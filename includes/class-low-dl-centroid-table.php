<?php
/**
 * Zip centroid table loaded from the bundled CSV.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, fills, and looks up {$wpdb->prefix}low_dl_zip_centroids.
 */
class LOW_DL_Centroid_Table {

	/**
	 * Transient that keeps two loads from writing at once.
	 */
	const LOCK_KEY = 'low_dl_centroids_lock';

	/**
	 * Option storing how many centroid rows were loaded.
	 */
	const LOADED_OPTION = 'low_dl_centroids_loaded';

	/**
	 * Rows per INSERT statement.
	 */
	const BATCH_SIZE = 1000;

	/**
	 * Table name including the WordPress prefix.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'low_dl_zip_centroids';
	}

	/**
	 * Load data/zcta-centroids.csv into the centroid table.
	 *
	 * @return bool
	 */
	public static function load() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}

		set_transient( self::LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );

		try {
			return self::load_csv();
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Coordinates for a 5-digit zip, or null.
	 *
	 * @param mixed $zip Zip code.
	 * @return array{lat: float, lng: float}|null
	 */
	public static function lookup( $zip ) {
		if ( ! is_scalar( $zip ) ) {
			return null;
		}

		$zip = trim( (string) $zip );

		if ( ! preg_match( '/^\d{5}$/', $zip ) ) {
			return null;
		}

		global $wpdb;

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT lat, lng FROM {$table} WHERE zip = %s",
				$zip
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! isset( $row['lat'], $row['lng'] ) ) {
			return null;
		}

		return array(
			'lat' => (float) $row['lat'],
			'lng' => (float) $row['lng'],
		);
	}

	/**
	 * Read the CSV and insert it in batches.
	 *
	 * @return bool
	 */
	private static function load_csv() {
		wp_raise_memory_limit( 'admin' );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$path = LOW_DL_PATH . 'data/zcta-centroids.csv';

		if ( ! is_readable( $path ) ) {
			return false;
		}

		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		// Header row: zip,lat,lng.
		fgetcsv( $handle );

		$batch     = array();
		$processed = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$clean = self::valid_row( $row );

			if ( null === $clean ) {
				continue;
			}

			$batch[] = $clean;
			$processed++;

			if ( count( $batch ) >= self::BATCH_SIZE ) {
				if ( ! self::insert_batch( $batch ) ) {
					fclose( $handle );
					return false;
				}

				$batch = array();
			}
		}

		fclose( $handle );

		if ( ! empty( $batch ) && ! self::insert_batch( $batch ) ) {
			return false;
		}

		update_option( self::LOADED_OPTION, $processed, false );

		return true;
	}

	/**
	 * A CSV row that is a 5-digit zip and in-range coordinates.
	 *
	 * @param mixed $row Parsed CSV row.
	 * @return array{0: string, 1: float, 2: float}|null
	 */
	private static function valid_row( $row ) {
		if ( ! is_array( $row ) || ! isset( $row[0], $row[1], $row[2] ) ) {
			return null;
		}

		$zip = trim( (string) $row[0] );
		$lat = trim( (string) $row[1] );
		$lng = trim( (string) $row[2] );

		if ( ! preg_match( '/^\d{5}$/', $zip ) || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}

		$lat = (float) $lat;
		$lng = (float) $lng;

		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		return array( $zip, $lat, $lng );
	}

	/**
	 * Insert or update one batch with a single prepared statement.
	 *
	 * @param array<int, array{0: string, 1: float, 2: float}> $rows Rows to write.
	 * @return bool
	 */
	private static function insert_batch( array $rows ) {
		global $wpdb;

		$count = count( $rows );

		if ( 0 === $count ) {
			return true;
		}

		$placeholders = implode( ',', array_fill( 0, $count, '(%s,%f,%f)' ) );
		$sql          = 'INSERT INTO ' . self::table_name() . " (zip, lat, lng) VALUES {$placeholders} ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng)";
		$values       = array();

		foreach ( $rows as $row ) {
			$values[] = $row[0];
			$values[] = $row[1];
			$values[] = $row[2];
		}

		// Placeholders are assembled to match $values. Unpack so prepare() does not receive an array.
		$prepared = $wpdb->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result   = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return false !== $result;
	}
}
