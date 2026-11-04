<?php
/**
 * Front-end service area form.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [low_dealer_service_area] edits the dealer post that contains the shortcode.
 */
class LOW_DL_Service_Area {

	/**
	 * How many forms this request has rendered.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Register the shortcode, assets, and save handler.
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_post_low_dl_save_service_area', array( $this, 'handle_save' ) );
	}

	/**
	 * Shortcode tag.
	 *
	 * @return void
	 */
	public static function register_shortcode() {
		add_shortcode( 'low_dealer_service_area', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Register assets. Does not enqueue them.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'low-dl-service-area',
			LOW_DL_URL . 'assets/css/service-area.css',
			array(),
			LOW_DL_VERSION
		);

		wp_register_script(
			'low-dl-service-area',
			LOW_DL_URL . 'assets/js/service-area.js',
			array(),
			LOW_DL_VERSION,
			true
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param mixed $atts Shortcode attributes. None are used.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		unset( $atts );

		$post = get_post();

		if ( ! ( $post instanceof WP_Post ) ) {
			return '';
		}

		if ( ! in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			if ( is_user_logged_in() && current_user_can( 'edit_post', $post->ID ) ) {
				return '<p class="low-dl-area-note">' . esc_html__( 'Dealer Service Area belongs on a dealer.', 'low-dealer-locator' ) . '</p>';
			}

			return '';
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}

		self::register_assets();
		wp_enqueue_style( 'low-dl-service-area' );
		wp_enqueue_script( 'low-dl-service-area' );

		++self::$count;

		$index   = self::$count;
		$unit    = LOW_DL_Zip_Manager::distance_unit();
		$radius  = LOW_DL_Zip_Manager::radius_input_value( $post->ID, $unit );
		$states  = LOW_DL_Zip_Manager::get_states( $post->ID );
		$zips    = LOW_DL_Zip_Manager::get_zips( $post->ID );
		$maximum = ( 'km' === $unit ) ? (int) floor( LOW_DL_Zip_Manager::MAX_RADIUS_MILES * LOW_DL_Zip_Manager::KM_PER_MILE ) : LOW_DL_Zip_Manager::MAX_RADIUS_MILES;
		$dialog  = 'low-dl-area-dialog-' . $index;
		$radius_id = 'low-dl-area-radius-' . $index;
		$zip_id    = 'low-dl-area-zips-' . $index;
		$title     = self::plain_title( $post );
		$status    = self::status_for( $post->ID );

		ob_start();
		?>
		<div class="low-dl-area"<?php echo $status['open'] ? ' data-open="1"' : ''; ?>>
			<?php if ( '' !== $status['message'] ) : ?>
				<p class="low-dl-area-status" role="status"><?php echo esc_html( $status['message'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $status['invalid'] ) ) : ?>
				<p class="low-dl-area-warning" role="status">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated values that were skipped. */
							__( 'These values are not zip codes and were not saved: %s', 'low-dealer-locator' ),
							implode( ', ', $status['invalid'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<button type="button" class="low-dl-area-open" aria-haspopup="dialog" aria-controls="<?php echo esc_attr( $dialog ); ?>">
				<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
				<?php echo esc_html__( 'Dealer Service Area', 'low-dealer-locator' ); ?>
			</button>
			<dialog id="<?php echo esc_attr( $dialog ); ?>" class="low-dl-area-dialog" aria-labelledby="<?php echo esc_attr( $dialog ); ?>-title">
				<form class="low-dl-area-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="low_dl_save_service_area" />
					<input type="hidden" name="low_dl_service_post" value="<?php echo esc_attr( (string) $post->ID ); ?>" />
					<?php wp_nonce_field( 'low_dl_save_service_area_' . $post->ID, 'low_dl_service_area_nonce' ); ?>
					<div class="low-dl-area-fields">
						<h2 id="<?php echo esc_attr( $dialog ); ?>-title">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: dealer name. */
									__( 'Service area for %s', 'low-dealer-locator' ),
									$title
								)
							);
							?>
						</h2>
						<p class="low-dl-area-help">
							<?php echo esc_html__( 'A visitor matches this dealer when any of these apply: the zip is listed, the search point is inside the radius, or the zip is in a checked state.', 'low-dealer-locator' ); ?>
						</p>
						<div class="low-dl-area-radius">
							<label for="<?php echo esc_attr( $radius_id ); ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: distance unit name, miles or kilometers. */
										__( 'Radius (%s)', 'low-dealer-locator' ),
										'km' === $unit ? __( 'kilometers', 'low-dealer-locator' ) : __( 'miles', 'low-dealer-locator' )
									)
								);
								?>
							</label>
							<input
								type="number"
								id="<?php echo esc_attr( $radius_id ); ?>"
								name="low_dl_service_radius"
								value="<?php echo esc_attr( $radius ); ?>"
								min="0"
								max="<?php echo esc_attr( (string) $maximum ); ?>"
								step="0.1"
								inputmode="decimal"
							/>
							<p class="low-dl-area-help">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: maximum radius, including the unit. */
										__( 'Distance from this dealer\'s address. Leave blank for no radius. %s maximum. The dealer needs map coordinates for a radius to match.', 'low-dealer-locator' ),
										sprintf(
											'km' === $unit ? __( '%s kilometers', 'low-dealer-locator' ) : __( '%s miles', 'low-dealer-locator' ),
											(string) $maximum
										)
									)
								);
								?>
							</p>
						</div>
						<fieldset class="low-dl-area-states">
							<legend><?php echo esc_html__( 'States', 'low-dealer-locator' ); ?></legend>
							<p class="low-dl-area-help">
								<?php echo esc_html__( 'Check each US state this dealer serves. A visitor zip in a checked state matches. Canadian provinces are not available.', 'low-dealer-locator' ); ?>
							</p>
							<div class="low-dl-area-state-list">
								<?php foreach ( LOW_DL_Zip_Manager::state_choices() as $code => $label ) : ?>
									<label>
										<input
											type="checkbox"
											name="low_dl_service_states[]"
											value="<?php echo esc_attr( $code ); ?>"
											<?php checked( in_array( $code, $states, true ) ); ?>
										/>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: state name, 2: USPS abbreviation. */
												__( '%1$s (%2$s)', 'low-dealer-locator' ),
												$label,
												$code
											)
										);
										?>
									</label>
								<?php endforeach; ?>
							</div>
						</fieldset>
						<div class="low-dl-area-zips">
							<label for="<?php echo esc_attr( $zip_id ); ?>"><?php echo esc_html__( 'Zip codes', 'low-dealer-locator' ); ?></label>
							<textarea id="<?php echo esc_attr( $zip_id ); ?>" name="low_dl_zip_input" rows="8"><?php echo esc_textarea( implode( "\n", $zips ) ); ?></textarea>
							<p class="low-dl-area-help">
								<?php echo esc_html__( 'Enter zip codes separated by commas, spaces, or new lines. Duplicates are removed.', 'low-dealer-locator' ); ?>
							</p>
							<p class="low-dl-area-count">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: number of saved zip codes. */
										_n( '%d zip code saved', '%d zip codes saved', count( $zips ), 'low-dealer-locator' ),
										count( $zips )
									)
								);
								?>
							</p>
						</div>
					</div>
					<div class="low-dl-area-actions">
						<button type="submit"><?php echo esc_html__( 'Save service area', 'low-dealer-locator' ); ?></button>
						<button type="button" class="low-dl-area-close"><?php echo esc_html__( 'Close', 'low-dealer-locator' ); ?></button>
					</div>
				</form>
			</dialog>
		</div>
		<?php

		$html = ob_get_clean();

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Save the posted service area and return to the dealer.
	 *
	 * @return void
	 */
	public function handle_save() {
		$post_id = isset( $_POST['low_dl_service_post'] ) ? absint( wp_unslash( $_POST['low_dl_service_post'] ) ) : 0;
		$nonce   = isset( $_POST['low_dl_service_area_nonce'] ) ? wp_unslash( $_POST['low_dl_service_area_nonce'] ) : '';

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'low_dl_save_service_area_' . $post_id ) ) {
			wp_die( esc_html__( 'The service area form expired. Reload the dealer and try again.', 'low-dealer-locator' ), '', array( 'response' => 403 ) );
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof WP_Post ) || ! in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to edit this dealer.', 'low-dealer-locator' ), '', array( 'response' => 403 ) );
		}

		$radius = isset( $_POST['low_dl_service_radius'] ) ? wp_unslash( $_POST['low_dl_service_radius'] ) : '';
		$states = isset( $_POST['low_dl_service_states'] ) ? wp_unslash( $_POST['low_dl_service_states'] ) : array();
		$zips   = isset( $_POST['low_dl_zip_input'] ) ? wp_unslash( $_POST['low_dl_zip_input'] ) : '';

		if ( ! is_string( $zips ) ) {
			$zips = '';
		}

		$invalid = LOW_DL_Zip_Manager::save_service_area( $post_id, $radius, $states, sanitize_textarea_field( $zips ) );

		if ( ! empty( $invalid ) ) {
			set_transient( self::invalid_key( $post_id ), $invalid, 60 );
		} else {
			delete_transient( self::invalid_key( $post_id ) );
		}

		$url = get_permalink( $post_id );

		if ( ! is_string( $url ) || '' === $url ) {
			$url = home_url( '/' );
		}

		wp_safe_redirect( add_query_arg( 'low_dl_area', 'saved', $url ) );
		exit;
	}

	/**
	 * Dealer name without HTML tags or entities.
	 *
	 * @param WP_Post $post Dealer post.
	 * @return string
	 */
	private static function plain_title( WP_Post $post ) {
		$title = html_entity_decode( (string) $post->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( wp_strip_all_tags( $title ) );
	}

	/**
	 * Saved notice and skipped zip codes for this editor.
	 *
	 * @param int $post_id Post ID.
	 * @return array{open: bool, message: string, invalid: string[]}
	 */
	private static function status_for( $post_id ) {
		$status = array(
			'open'    => false,
			'message' => '',
			'invalid' => array(),
		);

		$flag = isset( $_GET['low_dl_area'] ) ? wp_unslash( $_GET['low_dl_area'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag after a verified save redirect.

		if ( is_scalar( $flag ) && 'saved' === (string) $flag ) {
			$status['open']    = true;
			$status['message'] = __( 'Service area saved.', 'low-dealer-locator' );
		}

		$invalid = get_transient( self::invalid_key( $post_id ) );

		if ( is_array( $invalid ) ) {
			delete_transient( self::invalid_key( $post_id ) );

			foreach ( $invalid as $token ) {
				if ( is_scalar( $token ) && '' !== trim( (string) $token ) ) {
					$status['invalid'][] = (string) $token;
				}
			}

			if ( ! empty( $status['invalid'] ) ) {
				$status['open'] = true;
			}
		}

		return $status;
	}

	/**
	 * Transient key for skipped zip codes.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function invalid_key( $post_id ) {
		return 'low_dl_front_zips_' . get_current_user_id() . '_' . (int) $post_id;
	}
}
