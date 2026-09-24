<?php
/**
 * Background geocoding queue for dealer addresses.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues published dealers and geocodes them on a cron hook.
 */
class LOW_DL_Geo_Queue {

	/**
	 * Option storing post ID => attempt count.
	 */
	const QUEUE_OPTION = 'low_dl_geo_queue';

	/**
	 * Cron hook that drains the queue.
	 */
	const CRON_HOOK = 'low_dl_process_geo_queue';

	/**
	 * Hash of the address that was last geocoded.
	 */
	const META_HASH = '_low_dl_geo_hash';

	/**
	 * Last lookup result: ok, not_found, or error.
	 */
	const META_STATUS = '_low_dl_geo_status';

	/**
	 * Maximum queued dealers.
	 */
	const QUEUE_CAP = 5000;

	/**
	 * Transient that keeps two processors from running at once.
	 */
	const LOCK_KEY = 'low_dl_geo_queue_lock';

	/**
	 * How many failures remove a dealer from the queue.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Register save, delete, cron, and admin hooks.
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'maybe_enqueue' ), 30, 2 );
		add_action( 'deleted_post', array( $this, 'remove_deleted' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'process' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_low_dl_geocode_missing', array( $this, 'handle_backfill' ) );
	}

	/**
	 * Street, city, state, and zip from the mapped sources.
	 *
	 * @param int $post_id Post ID.
	 * @return array{street: string, city: string, state: string, zip: string}
	 */
	public static function get_address( $post_id ) {
		$post_id = (int) $post_id;

		return array(
			'street' => self::address_part( $post_id, 'street' ),
			'city'   => self::address_part( $post_id, 'city' ),
			'state'  => self::address_part( $post_id, 'state' ),
			'zip'    => self::address_part( $post_id, 'zip' ),
		);
	}

	/**
	 * Hash of a non-empty address, or an empty string.
	 *
	 * @param array $address Address parts.
	 * @return string
	 */
	public static function address_hash( $address ) {
		if ( ! is_array( $address ) ) {
			return '';
		}

		$parts = array();

		foreach ( array( 'street', 'city', 'state', 'zip' ) as $key ) {
			if ( ! isset( $address[ $key ] ) || ! is_scalar( $address[ $key ] ) ) {
				continue;
			}

			$part = strtolower( trim( (string) $address[ $key ] ) );

			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Whether this published dealer may be geocoded into plugin coordinates.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_geocode( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			return false;
		}

		if ( ! LOW_DL_Field_Mapper::is_own( $post->post_type, 'lat' ) || ! LOW_DL_Field_Mapper::is_own( $post->post_type, 'lng' ) ) {
			return false;
		}

		$manual = get_post_meta( $post->ID, LOW_DL_Field_Mapper::GEO_MANUAL_KEY, true );

		if ( is_scalar( $manual ) && '1' === (string) $manual ) {
			return false;
		}

		return true;
	}

	/**
	 * Queue a dealer whose address changed.
	 *
	 * No nonce or capability check: this only schedules a background lookup of already-saved data.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Saved post.
	 * @return void
	 */
	public function maybe_enqueue( $post_id, $post = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Quick Edit and bulk edit do not submit the address.
		if ( self::is_quick_or_bulk_edit() ) {
			return;
		}

		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
		}

		if ( ! ( $post instanceof WP_Post ) || 'revision' === $post->post_type ) {
			return;
		}

		if ( ! in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			return;
		}

		if ( ! self::can_geocode( $post_id ) ) {
			return;
		}

		$hash = self::address_hash( self::get_address( $post_id ) );

		if ( '' === $hash ) {
			return;
		}

		$stored = get_post_meta( $post_id, self::META_HASH, true );

		if ( ! is_string( $stored ) ) {
			$stored = '';
		}

		if ( $hash === $stored ) {
			return;
		}

		if ( '' === $stored && self::has_valid_coordinates( $post_id ) ) {
			update_post_meta( $post_id, self::META_HASH, $hash );
			update_post_meta( $post_id, self::META_STATUS, 'ok' );
			return;
		}

		self::enqueue( $post_id );
	}

	/**
	 * Add a dealer to the queue and schedule a run.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function enqueue( $post_id ) {
		$post_id = (int) $post_id;

		if ( $post_id <= 0 ) {
			return false;
		}

		$queue = self::get_queue();

		if ( ! isset( $queue[ $post_id ] ) && count( $queue ) >= self::QUEUE_CAP ) {
			self::schedule( 5 );
			return false;
		}

		$queue[ $post_id ] = 0;
		self::save_queue( $queue );
		self::schedule( 5 );

		return true;
	}

	/**
	 * Drop a deleted post from the queue.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function remove_deleted( $post_id ) {
		$post_id = (int) $post_id;
		$queue   = self::get_queue();

		if ( ! isset( $queue[ $post_id ] ) ) {
			return;
		}

		unset( $queue[ $post_id ] );
		self::save_queue( $queue );
	}

	/**
	 * Geocode queued dealers until the time budget or a rate limit.
	 *
	 * @return void
	 */
	public static function process() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_transient( self::LOCK_KEY, 1, 60 );

		try {
			self::run_queue();
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Missing-coordinate warning and the one-time queued notice.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_notice_screen() ) {
			return;
		}

		$this->render_queued_notice();
		$this->render_missing_notice();
	}

	/**
	 * Queue published dealers that still need coordinates.
	 *
	 * @return void
	 */
	public function handle_backfill() {
		$nonce = '';

		if ( isset( $_POST['low_dl_geo_nonce'] ) && is_scalar( $_POST['low_dl_geo_nonce'] ) ) {
			$nonce = (string) wp_unslash( $_POST['low_dl_geo_nonce'] );
		}

		if ( ! wp_verify_nonce( $nonce, 'low_dl_geocode_missing' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to geocode dealers.', 'low-dealer-locator' ), '', array( 'response' => 403 ) );
		}

		$queued = 0;

		foreach ( LOW_DL_REST::get_dealers() as $dealer ) {
			if ( ! is_array( $dealer ) || empty( $dealer['id'] ) ) {
				continue;
			}

			$post_id = (int) $dealer['id'];

			if ( ! self::can_geocode( $post_id ) || self::has_valid_coordinates( $post_id ) ) {
				continue;
			}

			$address = self::get_address( $post_id );
			$hash    = self::address_hash( $address );

			if ( '' === $hash ) {
				continue;
			}

			$stored = get_post_meta( $post_id, self::META_HASH, true );
			$status = get_post_meta( $post_id, self::META_STATUS, true );

			if ( is_string( $stored ) && $hash === $stored && 'not_found' === $status ) {
				continue;
			}

			if ( ! self::enqueue( $post_id ) ) {
				continue;
			}

			delete_post_meta( $post_id, self::META_STATUS );
			$queued++;
		}

		$redirect = wp_get_referer();

		if ( ! is_string( $redirect ) || '' === $redirect ) {
			$redirect = admin_url( 'options-general.php?page=' . LOW_DL_Settings::MENU_SLUG );
		}

		wp_safe_redirect( add_query_arg( 'low_dl_queued', $queued, $redirect ) );
		exit;
	}

	/**
	 * One mapped address part.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $data_point Data point slug.
	 * @return string
	 */
	private static function address_part( $post_id, $data_point ) {
		$value = LOW_DL_Field_Mapper::get_value( $post_id, $data_point );

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return trim( (string) $value );
	}

	/**
	 * Whether both plugin coordinates are in range.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function has_valid_coordinates( $post_id ) {
		$lat = get_post_meta( $post_id, LOW_DL_Field_Mapper::own_key( 'lat' ), true );
		$lng = get_post_meta( $post_id, LOW_DL_Field_Mapper::own_key( 'lng' ), true );

		return self::is_valid_coordinate( $lat, 90 ) && self::is_valid_coordinate( $lng, 180 );
	}

	/**
	 * Whether a meta value is a number inside the coordinate range.
	 *
	 * @param mixed $value Stored value.
	 * @param int   $max   Absolute maximum.
	 * @return bool
	 */
	private static function is_valid_coordinate( $value, $max ) {
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( ! is_numeric( $value ) ) {
			return false;
		}

		$number = (float) $value;

		return $number >= ( 0 - $max ) && $number <= $max;
	}

	/**
	 * Plain decimal string with six digits after the decimal point.
	 *
	 * @param float $number Coordinate.
	 * @return string
	 */
	private static function format_coordinate( $number ) {
		$formatted = sprintf( '%.6F', (float) $number );

		if ( '-0.000000' === $formatted ) {
			return '0.000000';
		}

		return $formatted;
	}

	/**
	 * Queue as post ID => attempts.
	 *
	 * @return array<int, int>
	 */
	private static function get_queue() {
		$stored = get_option( self::QUEUE_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$queue = array();

		foreach ( $stored as $post_id => $attempts ) {
			$post_id = (int) $post_id;

			if ( $post_id <= 0 ) {
				continue;
			}

			$queue[ $post_id ] = max( 0, (int) $attempts );
		}

		return $queue;
	}

	/**
	 * Persist the queue without autoloading it.
	 *
	 * @param array $queue Post ID => attempts.
	 * @return void
	 */
	private static function save_queue( array $queue ) {
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Schedule one processor run when none is waiting.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private static function schedule( $delay ) {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + (int) $delay, self::CRON_HOOK );
	}

	/**
	 * Drain the queue for up to 20 seconds.
	 *
	 * @return void
	 */
	private static function run_queue() {
		$queue        = self::get_queue();
		$started      = microtime( true );
		$rate_limited = false;

		foreach ( array_keys( $queue ) as $post_id ) {
			if ( ( microtime( true ) - $started ) >= 20 ) {
				break;
			}

			if ( ! isset( $queue[ $post_id ] ) ) {
				continue;
			}

			$post = get_post( $post_id );

			if ( ! ( $post instanceof WP_Post ) || ! self::can_geocode( $post_id ) ) {
				unset( $queue[ $post_id ] );
				continue;
			}

			$address = self::get_address( $post_id );
			$hash    = self::address_hash( $address );
			$stored  = get_post_meta( $post_id, self::META_HASH, true );

			if ( is_string( $stored ) && $hash === $stored && '' !== $hash && self::has_valid_coordinates( $post_id ) ) {
				unset( $queue[ $post_id ] );
				continue;
			}

			$result = LOW_DL_Geocoder::geocode_address( $address, 2.0 );

			if ( is_array( $result ) && isset( $result['lat'], $result['lng'] ) && is_numeric( $result['lat'] ) && is_numeric( $result['lng'] ) ) {
				if ( ! self::can_geocode( $post_id ) ) {
					unset( $queue[ $post_id ] );
					continue;
				}

				update_post_meta( $post_id, LOW_DL_Field_Mapper::own_key( 'lat' ), self::format_coordinate( $result['lat'] ) );
				update_post_meta( $post_id, LOW_DL_Field_Mapper::own_key( 'lng' ), self::format_coordinate( $result['lng'] ) );
				update_post_meta( $post_id, self::META_HASH, $hash );
				update_post_meta( $post_id, self::META_STATUS, 'ok' );
				LOW_DL_REST::clear_cache();
				unset( $queue[ $post_id ] );
				continue;
			}

			if ( null === $result ) {
				update_post_meta( $post_id, self::META_HASH, $hash );
				update_post_meta( $post_id, self::META_STATUS, 'not_found' );
				LOW_DL_REST::clear_cache();
				unset( $queue[ $post_id ] );
				continue;
			}

			if ( is_wp_error( $result ) && 'rate_limited' === $result->get_error_code() ) {
				$rate_limited = true;
				break;
			}

			$attempts = (int) $queue[ $post_id ] + 1;

			if ( $attempts >= self::MAX_ATTEMPTS ) {
				update_post_meta( $post_id, self::META_STATUS, 'error' );
				unset( $queue[ $post_id ] );
				continue;
			}

			$queue[ $post_id ] = $attempts;
		}

		self::save_queue( $queue );

		if ( empty( $queue ) ) {
			return;
		}

		$delay = 5;

		if ( $rate_limited || get_transient( 'low_dl_geo_backoff' ) ) {
			$delay = 60;
		}

		self::schedule( $delay );
	}

	/**
	 * Quick Edit and bulk edit requests.
	 *
	 * @return bool
	 */
	private static function is_quick_or_bulk_edit() {
		if ( isset( $_REQUEST['bulk_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( ! isset( $_REQUEST['action'] ) || ! is_scalar( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$action = sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) );

		return 'inline-save' === $action;
	}

	/**
	 * Settings screen or a dealer list table.
	 *
	 * @return bool
	 */
	private static function is_notice_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		if ( 'settings_page_' . LOW_DL_Settings::MENU_SLUG === $screen->id ) {
			return true;
		}

		return 'edit' === $screen->base && in_array( $screen->post_type, LOW_DL_Settings::get_dealer_post_types(), true );
	}

	/**
	 * Success notice after the backfill redirect.
	 *
	 * @return void
	 */
	private function render_queued_notice() {
		if ( ! isset( $_GET['low_dl_queued'] ) || ! is_scalar( $_GET['low_dl_queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$count = absint( wp_unslash( (string) $_GET['low_dl_queued'] ) );
		$text  = sprintf(
			/* translators: %d: number of dealers queued for geocoding. */
			_n(
				'%d dealer queued for geocoding.',
				'%d dealers queued for geocoding.',
				$count,
				'low-dealer-locator'
			),
			$count
		);
		?>
		<div class="notice notice-success">
			<p><?php echo esc_html( $text ); ?></p>
		</div>
		<?php
	}

	/**
	 * Warning listing published dealers with no coordinates.
	 *
	 * @return void
	 */
	private function render_missing_notice() {
		$missing  = array();
		$show_button = false;
		$queue    = self::get_queue();

		foreach ( LOW_DL_REST::get_dealers() as $dealer ) {
			if ( ! is_array( $dealer ) || empty( $dealer['id'] ) ) {
				continue;
			}

			$lat = array_key_exists( 'lat', $dealer ) ? $dealer['lat'] : null;
			$lng = array_key_exists( 'lng', $dealer ) ? $dealer['lng'] : null;

			if ( null !== $lat && null !== $lng ) {
				continue;
			}

			$post_id = (int) $dealer['id'];
			$post    = get_post( $post_id );

			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}

			$address = self::get_address( $post_id );

			if ( self::can_geocode( $post_id ) && '' !== self::address_hash( $address ) ) {
				$show_button = true;
			}

			$missing[] = array(
				'id'     => $post_id,
				'name'   => isset( $dealer['name'] ) && is_scalar( $dealer['name'] ) ? (string) $dealer['name'] : '',
				'reason' => $this->missing_reason( $post, $queue ),
			);
		}

		$count = count( $missing );

		if ( 0 === $count ) {
			return;
		}

		$summary = sprintf(
			/* translators: %d: number of dealers without coordinates. */
			_n(
				'%d dealer has no map coordinates and is left out of distance results.',
				'%d dealers have no map coordinates and are left out of distance results.',
				$count,
				'low-dealer-locator'
			),
			$count
		);
		?>
		<div class="notice notice-warning">
			<p><?php echo esc_html( $summary ); ?></p>
			<ul>
				<?php foreach ( array_slice( $missing, 0, 20 ) as $item ) : ?>
					<li>
						<?php
						$link = get_edit_post_link( $item['id'] );

						if ( is_string( $link ) && '' !== $link ) {
							echo '<a href="' . esc_url( $link ) . '">' . esc_html( $item['name'] ) . '</a>';
						} else {
							echo esc_html( $item['name'] );
						}
						?>
						<?php echo esc_html( ' — ' . $item['reason'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $count > 20 ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of additional dealers not listed. */
							__( 'and %d more', 'low-dealer-locator' ),
							$count - 20
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( $show_button ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="low_dl_geocode_missing" />
					<?php wp_nonce_field( 'low_dl_geocode_missing', 'low_dl_geo_nonce' ); ?>
					<?php submit_button( __( 'Geocode missing dealers', 'low-dealer-locator' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Short reason a dealer has no coordinates.
	 *
	 * @param WP_Post $post  Dealer.
	 * @param array   $queue Current queue.
	 * @return string
	 */
	private function missing_reason( $post, array $queue ) {
		$manual = get_post_meta( $post->ID, LOW_DL_Field_Mapper::GEO_MANUAL_KEY, true );

		if ( is_scalar( $manual ) && '1' === (string) $manual ) {
			return __( 'locked', 'low-dealer-locator' );
		}

		if ( ! LOW_DL_Field_Mapper::is_own( $post->post_type, 'lat' ) || ! LOW_DL_Field_Mapper::is_own( $post->post_type, 'lng' ) ) {
			return __( 'coordinates mapped to a field with no value', 'low-dealer-locator' );
		}

		if ( '' === self::address_hash( self::get_address( $post->ID ) ) ) {
			return __( 'no address', 'low-dealer-locator' );
		}

		if ( isset( $queue[ (int) $post->ID ] ) ) {
			return __( 'waiting in the queue', 'low-dealer-locator' );
		}

		$status = get_post_meta( $post->ID, self::META_STATUS, true );

		if ( 'not_found' === $status ) {
			return __( 'address not found', 'low-dealer-locator' );
		}

		if ( 'error' === $status ) {
			return __( 'lookup failed', 'low-dealer-locator' );
		}

		return __( 'not yet geocoded', 'low-dealer-locator' );
	}
}
