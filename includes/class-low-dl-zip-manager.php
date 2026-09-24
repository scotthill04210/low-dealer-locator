<?php
/**
 * Service-area zip meta box and lookup-table sync.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edits dealer zip codes and mirrors them into the lookup table.
 */
class LOW_DL_Zip_Manager {

	/**
	 * Source-of-truth post meta. Sorted, comma-separated 5-digit zips.
	 */
	const META_KEY = '_low_dl_zip_codes';

	/**
	 * Nonce action.
	 */
	const NONCE_ACTION = 'low_dl_save_zips';

	/**
	 * Nonce field name.
	 */
	const NONCE_NAME = 'low_dl_zips_nonce';

	/**
	 * Textarea field name.
	 */
	const INPUT_NAME = 'low_dl_zip_input';

	/**
	 * Rows per INSERT statement.
	 */
	const INSERT_CHUNK = 500;

	/**
	 * How many invalid tokens to list in the admin notice.
	 */
	const NOTICE_LIMIT = 20;

	/**
	 * Import form action and nonce.
	 */
	const IMPORT_ACTION = 'low_dl_import_zips';

	/**
	 * Import nonce field name.
	 */
	const IMPORT_NONCE = 'low_dl_import_nonce';

	/**
	 * Maximum CSV upload size in bytes.
	 */
	const IMPORT_MAX_BYTES = 2097152;

	/**
	 * Maximum data rows in one import.
	 */
	const IMPORT_MAX_ROWS = 5000;

	/**
	 * Detail lines stored in an import report.
	 */
	const IMPORT_DETAIL_LIMIT = 50;

	/**
	 * Register meta box, save, delete, and import hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save' ), 20, 2 );
		add_action( 'delete_post', array( $this, 'delete_rows' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'render_invalid_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
	}

	/**
	 * Meta box on each active dealer post type.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		foreach ( LOW_DL_Settings::get_dealer_post_types() as $post_type ) {
			add_meta_box(
				'low_dl_zip_codes',
				__( 'Service Area Zip Codes', 'low-dealer-locator' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Meta box markup.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$zips  = self::get_zips( $post->ID );
		$count = count( $zips );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="low-dl-zips">
			<label for="low-dl-zip-input" class="screen-reader-text">
				<?php echo esc_html__( 'Service area zip codes', 'low-dealer-locator' ); ?>
			</label>
			<textarea id="low-dl-zip-input" name="<?php echo esc_attr( self::INPUT_NAME ); ?>" rows="10" cols="40"><?php echo esc_textarea( implode( "\n", $zips ) ); ?></textarea>
			<p class="description">
				<?php echo esc_html__( 'Enter zip codes separated by commas, spaces, or new lines. Duplicates are removed.', 'low-dealer-locator' ); ?>
			</p>
			<p class="low-dl-zips-count">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of saved zip codes. */
						_n( '%d zip code saved', '%d zip codes saved', $count, 'low-dealer-locator' ),
						$count
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Split raw input into valid 5-digit zips and rejected tokens.
	 *
	 * @param string $raw Unparsed zip input.
	 * @return array{valid: string[], invalid: string[]}
	 */
	public static function parse( $raw ) {
		$valid   = array();
		$invalid = array();
		$tokens  = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) ) {
			$tokens = array();
		}

		foreach ( $tokens as $token ) {
			$token = self::strip_surrounding_quotes( $token );

			if ( '' === $token ) {
				continue;
			}

			if ( preg_match( '/^\d{5}-\d{4}$/', $token ) ) {
				$valid[] = substr( $token, 0, 5 );
			} elseif ( preg_match( '/^\d{3,4}$/', $token ) ) {
				$valid[] = str_pad( $token, 5, '0', STR_PAD_LEFT );
			} elseif ( preg_match( '/^\d{5}$/', $token ) ) {
				$valid[] = $token;
			} else {
				$invalid[] = $token;
			}
		}

		$valid = array_values( array_unique( $valid ) );
		sort( $valid, SORT_STRING );

		return array(
			'valid'   => $valid,
			'invalid' => array_values( array_unique( $invalid ) ),
		);
	}

	/**
	 * Saved zips for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function get_zips( $post_id ) {
		$stored = get_post_meta( (int) $post_id, self::META_KEY, true );

		if ( ! is_string( $stored ) || '' === $stored ) {
			return array();
		}

		$zips = array();

		foreach ( explode( ',', $stored ) as $zip ) {
			if ( preg_match( '/^\d{5}$/', $zip ) ) {
				$zips[] = $zip;
			}
		}

		return $zips;
	}

	/**
	 * Published post IDs that list this zip.
	 *
	 * @param string $zip Five-digit zip code.
	 * @return int[]
	 */
	public static function find_dealer_ids_by_zip( $zip ) {
		if ( ! is_string( $zip ) || ! preg_match( '/^\d{5}$/', $zip ) ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . 'low_dl_dealer_zips';
		$sql   = "SELECT z.post_id FROM {$table} AS z INNER JOIN {$wpdb->posts} AS p ON p.ID = z.post_id WHERE z.zip = %s AND p.post_status = 'publish' ORDER BY z.post_id ASC";
		$ids   = $wpdb->get_col( $wpdb->prepare( $sql, $zip ) );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Save textarea input to meta and the lookup table.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Saved post.
	 * @return void
	 */
	public function save( $post_id, $post = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! ( $post instanceof WP_Post ) || 'revision' === $post->post_type ) {
			return;
		}

		if ( ! in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = wp_unslash( $_POST[ self::NONCE_NAME ] );

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::INPUT_NAME ] ) || ! is_string( $_POST[ self::INPUT_NAME ] ) ) {
			return;
		}

		$raw    = sanitize_textarea_field( wp_unslash( $_POST[ self::INPUT_NAME ] ) );
		$parsed = self::parse( $raw );

		if ( ! empty( $parsed['invalid'] ) ) {
			set_transient( $this->invalid_notice_key( $post_id ), $parsed['invalid'], 60 );
		} else {
			delete_transient( $this->invalid_notice_key( $post_id ) );
		}

		self::set_zips( $post_id, $parsed['valid'] );
	}

	/**
	 * Store zip codes for a dealer and mirror them into the lookup table.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $zips    Five-digit zip codes. An empty list clears storage.
	 * @return void
	 */
	public static function set_zips( $post_id, array $zips ) {
		$post_id = (int) $post_id;
		$clean   = array();

		foreach ( $zips as $zip ) {
			if ( is_string( $zip ) && preg_match( '/^\d{5}$/', $zip ) ) {
				$clean[] = $zip;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		sort( $clean, SORT_STRING );

		if ( empty( $clean ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, implode( ',', $clean ) );
		}

		self::sync_table( $post_id, $clean );

		/**
		 * Fires after dealer zip codes are saved.
		 *
		 * @param int      $post_id Post ID.
		 * @param string[] $zips    Valid zip codes that were stored.
		 */
		do_action( 'low_dl_zips_saved', $post_id, $clean );
	}

	/**
	 * Replace lookup-table rows for one post.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $zips    Five-digit zip codes.
	 * @return void
	 */
	public static function sync_table( $post_id, array $zips ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$table   = $wpdb->prefix . 'low_dl_dealer_zips';

		$wpdb->delete(
			$table,
			array(
				'post_id' => $post_id,
			),
			array(
				'%d',
			)
		);

		$clean = array();

		foreach ( $zips as $zip ) {
			if ( is_string( $zip ) && preg_match( '/^\d{5}$/', $zip ) ) {
				$clean[] = $zip;
			}
		}

		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) ) {
			return;
		}

		foreach ( array_chunk( $clean, self::INSERT_CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array();

			foreach ( $chunk as $zip ) {
				$placeholders[] = '(%d, %s)';
				$values[]       = $post_id;
				$values[]       = $zip;
			}

			$sql = "INSERT INTO {$table} (post_id, zip) VALUES " . implode( ', ', $placeholders );
			// Placeholders are assembled to match $values. Unpack so prepare() does not receive an array.
			$wpdb->query( $wpdb->prepare( $sql, ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Remove lookup rows when a dealer is permanently deleted.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object, when WordPress passes it.
	 * @return void
	 */
	public function delete_rows( $post_id, $post = null ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
		}

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			return;
		}

		global $wpdb;

		$wpdb->delete(
			$wpdb->prefix . 'low_dl_dealer_zips',
			array(
				'post_id' => (int) $post_id,
			),
			array(
				'%d',
			)
		);
	}

	/**
	 * One-time warning for tokens skipped on the previous save.
	 *
	 * @return void
	 */
	public function render_invalid_notice() {
		if ( ! isset( $_GET['post'] ) || ! is_scalar( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen context for a per-user transient.
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post'] ) );

		if ( ! $post_id ) {
			return;
		}

		$invalid = get_transient( $this->invalid_notice_key( $post_id ) );

		if ( ! is_array( $invalid ) || empty( $invalid ) ) {
			return;
		}

		delete_transient( $this->invalid_notice_key( $post_id ) );

		$shown = array_slice( $invalid, 0, self::NOTICE_LIMIT );
		$extra = count( $invalid ) - count( $shown );
		$text  = sprintf(
			/* translators: %s: comma-separated list of rejected values. */
			__( 'These values were not valid zip codes and were skipped: %s', 'low-dealer-locator' ),
			implode( ', ', $shown )
		);

		if ( $extra > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number of additional rejected values not listed. */
				__( 'and %d more', 'low-dealer-locator' ),
				$extra
			);
		}

		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Meta box styles on dealer edit screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'low-dl-admin',
			LOW_DL_URL . 'assets/css/admin.css',
			array(),
			LOW_DL_VERSION
		);
	}

	/**
	 * Import tab markup. Not part of the Settings API.
	 *
	 * @return void
	 */
	public static function render_import_tab() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import zip codes.', 'low-dealer-locator' ) );
		}

		self::render_import_notice();

		$example = "dealer,zip\n42,78001\nAcme Propane,\"78002, 78003\"";
		?>
		<div class="low-dl-import">
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
				<?php wp_nonce_field( self::IMPORT_ACTION, self::IMPORT_NONCE ); ?>
				<p>
					<label for="low-dl-import-file"><?php echo esc_html__( 'CSV file', 'low-dealer-locator' ); ?></label><br />
					<input type="file" id="low-dl-import-file" name="low_dl_import_file" accept=".csv" />
				</p>
				<fieldset class="low-dl-import-modes">
					<legend><?php echo esc_html__( 'Import mode', 'low-dealer-locator' ); ?></legend>
					<label>
						<input type="radio" name="low_dl_import_mode" value="add" checked="checked" />
						<?php echo esc_html__( 'Add to existing zips', 'low-dealer-locator' ); ?>
					</label>
					<label>
						<input type="radio" name="low_dl_import_mode" value="replace" />
						<?php echo esc_html__( 'Replace existing zips for dealers in this file', 'low-dealer-locator' ); ?>
					</label>
				</fieldset>
				<p class="low-dl-import-confirm">
					<label>
						<input type="checkbox" name="low_dl_import_confirm" value="1" />
						<?php echo esc_html__( 'I understand this overwrites those dealers\' current zips', 'low-dealer-locator' ); ?>
					</label>
				</p>
				<div class="low-dl-import-help">
					<p>
						<?php echo esc_html__( 'Use a header row with columns dealer and zip. The dealer column is a post ID or an exact title. Put one zip per row, or several zips in one cell separated by commas, spaces, or semicolons.', 'low-dealer-locator' ); ?>
					</p>
					<pre><?php echo esc_html( $example ); ?></pre>
				</div>
				<?php submit_button( __( 'Import zip codes', 'low-dealer-locator' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Logged-in import handler. Guests are not given a nopriv action.
	 *
	 * @return void
	 */
	public function handle_import() {
		$nonce = isset( $_POST[ self::IMPORT_NONCE ] ) ? wp_unslash( $_POST[ self::IMPORT_NONCE ] ) : '';

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::IMPORT_ACTION ) ) {
			wp_die( esc_html__( 'The import request could not be verified.', 'low-dealer-locator' ), '', array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import zip codes.', 'low-dealer-locator' ), '', array( 'response' => 403 ) );
		}

		$mode = 'add';

		if ( isset( $_POST['low_dl_import_mode'] ) && is_string( $_POST['low_dl_import_mode'] ) ) {
			$requested = sanitize_key( wp_unslash( $_POST['low_dl_import_mode'] ) );

			if ( 'replace' === $requested ) {
				$mode = 'replace';
			}
		}

		if ( 'replace' === $mode ) {
			$confirm = isset( $_POST['low_dl_import_confirm'] ) ? wp_unslash( $_POST['low_dl_import_confirm'] ) : '';

			if ( ! is_string( $confirm ) || '1' !== $confirm ) {
				self::redirect_import_report(
					array(
						'type'    => 'error',
						'message' => __( 'Replace mode requires the confirmation checkbox. Nothing was changed.', 'low-dealer-locator' ),
					)
				);
			}
		}

		$upload_error = self::import_upload_error();

		if ( '' !== $upload_error ) {
			self::redirect_import_report(
				array(
					'type'    => 'error',
					'message' => $upload_error,
				)
			);
		}

		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$file = $_FILES['low_dl_import_file'];
		$csv  = self::read_csv( $file['tmp_name'] );

		if ( '' !== $csv['error'] ) {
			self::redirect_import_report(
				array(
					'type'    => 'error',
					'message' => self::csv_error_message( $csv['error'] ),
				)
			);
		}

		$report = self::apply_import( $csv['rows'], (int) $csv['dealer_index'], (int) $csv['zip_index'], $mode );

		self::redirect_import_report( $report );
	}

	/**
	 * One-time import report for the current user.
	 *
	 * @return void
	 */
	private static function render_import_notice() {
		$key    = self::import_report_key();
		$report = get_transient( $key );

		if ( ! is_array( $report ) ) {
			return;
		}

		delete_transient( $key );

		$type = ( isset( $report['type'] ) && 'error' === $report['type'] ) ? 'error' : 'success';

		if ( 'error' === $type ) {
			$message = isset( $report['message'] ) && is_string( $report['message'] ) ? $report['message'] : '';
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
			return;
		}

		$updated   = isset( $report['updated'] ) ? (int) $report['updated'] : 0;
		$unchanged = isset( $report['unchanged'] ) ? (int) $report['unchanged'] : 0;
		$skipped   = isset( $report['skipped'] ) ? (int) $report['skipped'] : 0;
		$net       = isset( $report['zips_added'] ) ? (int) $report['zips_added'] : 0;
		$extra     = isset( $report['details_extra'] ) ? (int) $report['details_extra'] : 0;
		$class     = ( $skipped > 0 || $extra > 0 || ! empty( $report['details'] ) ) ? 'notice-warning' : 'notice-success';

		echo '<div class="notice ' . esc_attr( $class ) . '"><p>';
		echo esc_html(
			sprintf(
				/* translators: 1: dealers updated, 2: dealers unchanged, 3: dealers skipped, 4: net zip count change. */
				__( 'Updated %1$d dealers. Unchanged %2$d. Skipped %3$d. Zips added: %4$d.', 'low-dealer-locator' ),
				$updated,
				$unchanged,
				$skipped,
				$net
			)
		);
		echo '</p>';

		if ( ! empty( $report['details'] ) && is_array( $report['details'] ) ) {
			echo '<ul class="low-dl-import-details">';

			foreach ( $report['details'] as $line ) {
				if ( ! is_scalar( $line ) ) {
					continue;
				}

				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}

			echo '</ul>';
		}

		if ( $extra > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of additional report lines not listed. */
					__( 'and %d more', 'low-dealer-locator' ),
					$extra
				)
			) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Why an upload cannot be imported. Empty string when the temp file is usable.
	 *
	 * @return string
	 */
	private static function import_upload_error() {
		if ( ! isset( $_FILES['low_dl_import_file'] ) || ! is_array( $_FILES['low_dl_import_file'] ) ) {
			return __( 'Choose a CSV file to import.', 'low-dealer-locator' );
		}

		$file  = $_FILES['low_dl_import_file'];
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return __( 'Choose a CSV file to import.', 'low-dealer-locator' );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return __( 'The file is larger than 2 MB.', 'low-dealer-locator' );
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return __( 'The file could not be uploaded.', 'low-dealer-locator' );
		}

		$name = isset( $file['name'] ) && is_string( $file['name'] ) ? wp_basename( $file['name'] ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'csv' !== $ext ) {
			return __( 'The file must be a .csv file.', 'low-dealer-locator' );
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$tmp  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return __( 'The file could not be uploaded.', 'low-dealer-locator' );
		}

		$bytes = filesize( $tmp );

		if ( false === $bytes ) {
			return __( 'The file could not be read.', 'low-dealer-locator' );
		}

		if ( $size > self::IMPORT_MAX_BYTES || $bytes > self::IMPORT_MAX_BYTES ) {
			return __( 'The file is larger than 2 MB.', 'low-dealer-locator' );
		}

		return '';
	}

	/**
	 * User-facing message for a CSV read failure.
	 *
	 * @param string $code columns, rows, or read.
	 * @return string
	 */
	private static function csv_error_message( $code ) {
		if ( 'rows' === $code ) {
			return __( 'The CSV has more than 5,000 data rows.', 'low-dealer-locator' );
		}

		if ( 'read' === $code ) {
			return __( 'The file could not be read.', 'low-dealer-locator' );
		}

		return __( 'The CSV must include dealer and zip columns.', 'low-dealer-locator' );
	}

	/**
	 * Read a CSV temp file without moving or storing it.
	 *
	 * @param string $path Uploaded temp path.
	 * @return array{error: string, rows: array, dealer_index: int, zip_index: int}
	 */
	private static function read_csv( $path ) {
		$empty = array(
			'error'         => 'read',
			'rows'          => array(),
			'dealer_index'  => 0,
			'zip_index'     => 0,
		);

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return $empty;
		}

		$bom = fread( $handle, 3 );

		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		$header_pos  = ftell( $handle );
		$header_line = fgets( $handle );

		if ( ! is_string( $header_line ) || '' === trim( $header_line ) ) {
			fclose( $handle );
			$empty['error'] = 'columns';
			return $empty;
		}

		$delimiter = self::detect_delimiter( $header_line );

		if ( false === $header_pos || 0 !== fseek( $handle, $header_pos ) ) {
			fclose( $handle );
			return $empty;
		}

		$header = fgetcsv( $handle, self::IMPORT_MAX_BYTES + 1, $delimiter );

		if ( ! is_array( $header ) ) {
			fclose( $handle );
			$empty['error'] = 'columns';
			return $empty;
		}

		$map = self::map_header( $header );

		if ( ! isset( $map['dealer'], $map['zip'] ) ) {
			fclose( $handle );
			$empty['error'] = 'columns';
			return $empty;
		}

		$rows        = array();
		$line_number = 1;

		while ( ( $row = fgetcsv( $handle, self::IMPORT_MAX_BYTES + 1, $delimiter ) ) !== false ) {
			++$line_number;

			if ( self::is_empty_row( $row ) ) {
				continue;
			}

			$rows[] = array(
				'line'  => $line_number,
				'cells' => $row,
			);

			if ( count( $rows ) > self::IMPORT_MAX_ROWS ) {
				fclose( $handle );
				$empty['error'] = 'rows';
				return $empty;
			}
		}

		fclose( $handle );

		return array(
			'error'        => '',
			'rows'         => $rows,
			'dealer_index' => $map['dealer'],
			'zip_index'    => $map['zip'],
		);
	}

	/**
	 * Apply grouped CSV rows to dealer posts.
	 *
	 * @param array  $rows         Data rows.
	 * @param int    $dealer_index Dealer column index.
	 * @param int    $zip_index    Zip column index.
	 * @param string $mode         add or replace.
	 * @return array
	 */
	private static function apply_import( array $rows, $dealer_index, $zip_index, $mode ) {
		$details = array();
		$skipped = 0;
		$groups  = array();
		$order   = array();

		foreach ( $rows as $row ) {
			$line  = isset( $row['line'] ) ? (int) $row['line'] : 0;
			$cells = ( isset( $row['cells'] ) && is_array( $row['cells'] ) ) ? $row['cells'] : array();
			$dealer = isset( $cells[ $dealer_index ] ) && is_scalar( $cells[ $dealer_index ] ) ? trim( (string) $cells[ $dealer_index ] ) : '';
			$cell   = isset( $cells[ $zip_index ] ) && is_scalar( $cells[ $zip_index ] ) ? (string) $cells[ $zip_index ] : '';
			$parsed = self::parse( $cell );

			if ( '' === $dealer ) {
				++$skipped;
				$details[] = sprintf(
					/* translators: %d: CSV row number, counting the header as row 1. */
					__( 'Row %d has an empty dealer value.', 'low-dealer-locator' ),
					$line
				);
				self::append_invalid_detail( $details, $line, $parsed['invalid'] );
				continue;
			}

			if ( ! isset( $groups[ $dealer ] ) ) {
				$groups[ $dealer ] = array(
					'zips'    => array(),
					'invalid' => array(),
				);
				$order[] = $dealer;
			}

			foreach ( $parsed['valid'] as $zip ) {
				$groups[ $dealer ]['zips'][] = $zip;
			}

			if ( ! empty( $parsed['invalid'] ) ) {
				$groups[ $dealer ]['invalid'][] = array(
					'row'    => $line,
					'tokens' => $parsed['invalid'],
				);
			}
		}

		$by_post = array();

		foreach ( $order as $identifier ) {
			$group = $groups[ $identifier ];

			foreach ( $group['invalid'] as $item ) {
				self::append_invalid_detail( $details, (int) $item['row'], $item['tokens'] );
			}

			$resolved = self::resolve_dealer( (string) $identifier );

			if ( 'ok' !== $resolved['code'] ) {
				++$skipped;
				$details[] = self::resolve_failure_message( (string) $identifier, $resolved['code'] );
				continue;
			}

			$post_id = (int) $resolved['post_id'];

			if ( ! isset( $by_post[ $post_id ] ) ) {
				$by_post[ $post_id ] = array(
					'denied' => ! current_user_can( 'edit_post', $post_id ),
					'zips'   => array(),
					'label'  => (string) $identifier,
				);
			}

			if ( ! empty( $by_post[ $post_id ]['denied'] ) ) {
				continue;
			}

			foreach ( $group['zips'] as $zip ) {
				$by_post[ $post_id ]['zips'][] = $zip;
			}
		}

		$updated    = 0;
		$unchanged  = 0;
		$zips_added = 0;

		foreach ( $by_post as $post_id => $data ) {
			if ( ! empty( $data['denied'] ) ) {
				++$skipped;
				$details[] = sprintf(
					/* translators: %s: dealer title or post ID from the CSV. */
					__( 'You cannot edit %s.', 'low-dealer-locator' ),
					$data['label']
				);
				continue;
			}

			$current = self::get_zips( (int) $post_id );
			$next    = self::combine_zips( $current, $data['zips'], $mode );

			if ( $next['changed'] ) {
				self::set_zips( (int) $post_id, $next['zips'] );
				++$updated;
				$zips_added += $next['net'];
			} else {
				++$unchanged;
			}
		}

		$extra   = max( 0, count( $details ) - self::IMPORT_DETAIL_LIMIT );
		$details = array_slice( $details, 0, self::IMPORT_DETAIL_LIMIT );

		return array(
			'type'           => 'success',
			'updated'        => $updated,
			'unchanged'      => $unchanged,
			'skipped'        => $skipped,
			'zips_added'     => $zips_added,
			'details'        => $details,
			'details_extra'  => $extra,
		);
	}

	/**
	 * Merge or replace, then compare with the stored list.
	 *
	 * @param string[] $current   Stored zips.
	 * @param string[] $file_zips Zips from the file.
	 * @param string   $mode      add or replace.
	 * @return array{zips: string[], changed: bool, net: int}
	 */
	private static function combine_zips( array $current, array $file_zips, $mode ) {
		$current   = array_values( array_unique( $current ) );
		$file_zips = array_values( array_unique( $file_zips ) );
		sort( $current, SORT_STRING );
		sort( $file_zips, SORT_STRING );

		if ( 'replace' === $mode ) {
			$next = $file_zips;
		} else {
			$next = array_values( array_unique( array_merge( $current, $file_zips ) ) );
			sort( $next, SORT_STRING );
		}

		return array(
			'zips'    => $next,
			'changed' => $next !== $current,
			'net'     => count( $next ) - count( $current ),
		);
	}

	/**
	 * Find one dealer post for a CSV identifier.
	 *
	 * @param string $value Post ID or exact title.
	 * @return array{code: string, post_id: int}
	 */
	private static function resolve_dealer( $value ) {
		$post_types = LOW_DL_Settings::get_dealer_post_types();
		$failed     = array(
			'code'    => 'not_found',
			'post_id' => 0,
		);

		if ( preg_match( '/^\d+$/', $value ) ) {
			$post = get_post( (int) $value );

			if ( ! ( $post instanceof WP_Post ) ) {
				return $failed;
			}

			if ( empty( $post_types ) || ! in_array( $post->post_type, $post_types, true ) ) {
				$failed['code'] = 'not_dealer';
				return $failed;
			}

			return array(
				'code'    => 'ok',
				'post_id' => (int) $post->ID,
			);
		}

		if ( '' === $value || empty( $post_types ) ) {
			return $failed;
		}

		$ids = self::query_ids_by_exact_title( $value, $post_types );

		if ( count( $ids ) > 1 ) {
			$failed['code'] = 'ambiguous';
			return $failed;
		}

		if ( 1 !== count( $ids ) ) {
			return $failed;
		}

		return array(
			'code'    => 'ok',
			'post_id' => (int) $ids[0],
		);
	}

	/**
	 * Up to two post IDs whose title matches exactly.
	 *
	 * @param string   $title      Exact post title.
	 * @param string[] $post_types Dealer post types.
	 * @return int[]
	 */
	private static function query_ids_by_exact_title( $title, array $post_types ) {
		$filter = static function ( $where, $query ) {
			$exact = $query->get( 'low_dl_exact_title' );

			if ( ! is_string( $exact ) || '' === $exact ) {
				return $where;
			}

			global $wpdb;

			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title = %s", $exact );

			return $where;
		};

		add_filter( 'posts_where', $filter, 10, 2 );

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 2,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'low_dl_exact_title'     => $title,
			)
		);

		remove_filter( 'posts_where', $filter, 10 );

		$ids = array();

		foreach ( $query->posts as $post_id ) {
			$ids[] = (int) $post_id;
		}

		return $ids;
	}

	/**
	 * Skip reason for a dealer identifier that did not resolve.
	 *
	 * @param string $identifier CSV dealer value.
	 * @param string $code       not_found, not_dealer, or ambiguous.
	 * @return string
	 */
	private static function resolve_failure_message( $identifier, $code ) {
		if ( 'ambiguous' === $code ) {
			return sprintf(
				/* translators: %s: dealer title from the CSV. */
				__( '"%s" is an ambiguous title, use the post ID.', 'low-dealer-locator' ),
				$identifier
			);
		}

		if ( 'not_dealer' === $code ) {
			return sprintf(
				/* translators: %s: post ID from the CSV. */
				__( 'Post ID %s is not a dealer.', 'low-dealer-locator' ),
				$identifier
			);
		}

		if ( preg_match( '/^\d+$/', $identifier ) ) {
			return sprintf(
				/* translators: %s: post ID from the CSV. */
				__( 'Post ID %s was not found.', 'low-dealer-locator' ),
				$identifier
			);
		}

		return sprintf(
			/* translators: %s: dealer title from the CSV. */
			__( '"%s" was not found.', 'low-dealer-locator' ),
			$identifier
		);
	}

	/**
	 * Append one invalid-token line.
	 *
	 * @param array    $details Detail lines, passed by reference.
	 * @param int      $line    CSV row number.
	 * @param string[] $tokens  Rejected tokens.
	 * @return void
	 */
	private static function append_invalid_detail( array &$details, $line, array $tokens ) {
		if ( empty( $tokens ) ) {
			return;
		}

		$details[] = sprintf(
			/* translators: 1: CSV row number, 2: comma-separated rejected values. */
			__( 'Row %1$d: invalid values skipped: %2$s', 'low-dealer-locator' ),
			(int) $line,
			implode( ', ', $tokens )
		);
	}

	/**
	 * Comma, semicolon, or tab, preferring comma when tied.
	 *
	 * @param string $line Header line.
	 * @return string
	 */
	private static function detect_delimiter( $line ) {
		$line  = (string) $line;
		$comma = substr_count( $line, ',' );
		$semi  = substr_count( $line, ';' );
		$tab   = substr_count( $line, "\t" );

		if ( $semi > $comma && $semi > $tab ) {
			return ';';
		}

		if ( $tab > $comma && $tab > $semi ) {
			return "\t";
		}

		return ',';
	}

	/**
	 * Column indexes for dealer and zip.
	 *
	 * @param array $header Header cells.
	 * @return array<string, int>
	 */
	private static function map_header( array $header ) {
		$map = array();

		foreach ( $header as $index => $column ) {
			if ( ! is_scalar( $column ) ) {
				continue;
			}

			$name = strtolower( trim( (string) $column ) );
			$name = preg_replace( '/^\xEF\xBB\xBF/', '', $name );

			if ( ( 'dealer' === $name || 'zip' === $name ) && ! isset( $map[ $name ] ) ) {
				$map[ $name ] = (int) $index;
			}
		}

		return $map;
	}

	/**
	 * True when every cell is empty.
	 *
	 * @param mixed $row CSV row.
	 * @return bool
	 */
	private static function is_empty_row( $row ) {
		if ( ! is_array( $row ) ) {
			return true;
		}

		foreach ( $row as $cell ) {
			if ( null !== $cell && '' !== trim( (string) $cell ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Save the report and return to the Import tab.
	 *
	 * @param array $report Report payload.
	 * @return void
	 */
	private static function redirect_import_report( array $report ) {
		set_transient( self::import_report_key(), $report, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => LOW_DL_Settings::MENU_SLUG,
					'tab'  => 'import',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Per-user transient for the import report.
	 *
	 * @return string
	 */
	private static function import_report_key() {
		return 'low_dl_import_report_' . get_current_user_id();
	}

	/**
	 * Transient key for the skipped-value notice.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function invalid_notice_key( $post_id ) {
		return 'low_dl_zip_invalid_' . get_current_user_id() . '_' . (int) $post_id;
	}

	/**
	 * Remove one matching pair of wrapping quotes.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	private static function strip_surrounding_quotes( $token ) {
		$token = trim( $token );

		if ( strlen( $token ) < 2 ) {
			return $token;
		}

		$first = $token[0];
		$last  = substr( $token, -1 );

		if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
			return trim( substr( $token, 1, -1 ) );
		}

		return $token;
	}
}
