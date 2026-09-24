<?php
/**
 * Activation and stored defaults.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation and database upgrades.
 */
class LOW_DL_Install {

	/**
	 * Add default settings, create tables, and record the schema version.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once LOW_DL_PATH . 'includes/class-low-dl-settings.php';

		if ( false === get_option( 'low_dl_settings' ) ) {
			add_option( 'low_dl_settings', LOW_DL_Settings::defaults() );
		}

		self::create_tables();

		require_once LOW_DL_PATH . 'includes/class-low-dl-centroid-table.php';
		LOW_DL_Centroid_Table::load();

		update_option( 'low_dl_db_version', LOW_DL_VERSION );
	}

	/**
	 * Create or update the dealer zip and centroid tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'low_dl_dealer_zips';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
post_id BIGINT UNSIGNED NOT NULL,
zip CHAR(5) NOT NULL,
PRIMARY KEY  (post_id, zip),
KEY zip (zip)
) {$charset_collate};";

		dbDelta( $sql );

		$centroids     = $wpdb->prefix . 'low_dl_zip_centroids';
		$centroids_sql = "CREATE TABLE {$centroids} (
zip CHAR(5) NOT NULL,
lat DECIMAL(9,6) NOT NULL,
lng DECIMAL(9,6) NOT NULL,
PRIMARY KEY  (zip)
) {$charset_collate};";

		dbDelta( $centroids_sql );
	}

	/**
	 * Bring an existing install up to the current schema.
	 *
	 * One option read when the version already matches.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'low_dl_db_version' ) !== LOW_DL_VERSION ) {
			self::create_tables();

			if ( empty( get_option( 'low_dl_centroids_loaded' ) ) && ! wp_next_scheduled( 'low_dl_load_centroids' ) ) {
				wp_schedule_single_event( time() + 10, 'low_dl_load_centroids' );
			}

			update_option( 'low_dl_db_version', LOW_DL_VERSION );
		}
	}
}
