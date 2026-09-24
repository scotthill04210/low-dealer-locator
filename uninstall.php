<?php
/**
 * Uninstall LOW Dealer Locator.
 *
 * Removes plugin data only when delete_data_on_uninstall is enabled.
 * Dealer posts are never deleted.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'low_dl_uninstall_site' ) ) {
	/**
	 * Remove this site's plugin tables, options, meta, transients, and cron events.
	 *
	 * Does nothing unless the saved setting allows it.
	 *
	 * @return void
	 */
	function low_dl_uninstall_site() {
		global $wpdb;

		$settings = get_option( 'low_dl_settings' );

		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		$previous_suppress = $wpdb->suppress_errors( true );

		$run = function ( $callback ) {
			try {
				$callback();
			} catch ( Throwable $throwable ) {
				unset( $throwable );
			}
		};

		/*
		 * Table names cannot be prepare() placeholders. Each name is only
		 * $wpdb->prefix plus a fixed suffix, with no user input, so this is safe.
		 */
		$tables = array(
			$wpdb->prefix . 'low_dl_dealer_zips',
			$wpdb->prefix . 'low_dl_zip_centroids',
		);

		foreach ( $tables as $table ) {
			$run(
				function () use ( $wpdb, $table ) {
					$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is prefix plus a fixed suffix.
				}
			);
		}

		foreach ( array( 'low_dl_settings', 'low_dl_db_version', 'low_dl_centroids_loaded', 'low_dl_geo_queue' ) as $option ) {
			$run(
				function () use ( $option ) {
					delete_option( $option );
				}
			);
		}

		$meta_keys = array(
			'_low_dl_zip_codes',
			'_low_dl_service_radius',
			'_low_dl_service_states',
			'_low_dl_email',
			'_low_dl_website',
			'_low_dl_phone',
			'_low_dl_street',
			'_low_dl_city',
			'_low_dl_state',
			'_low_dl_postal_code',
			'_low_dl_lat',
			'_low_dl_lng',
			'_low_dl_geo_manual',
			'_low_dl_geo_hash',
			'_low_dl_geo_status',
		);

		foreach ( $meta_keys as $meta_key ) {
			$run(
				function () use ( $meta_key ) {
					delete_post_meta_by_key( $meta_key );
				}
			);
		}

		$run(
			function () use ( $wpdb ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
						$wpdb->esc_like( '_transient_low_dl_' ) . '%',
						$wpdb->esc_like( '_transient_timeout_low_dl_' ) . '%',
						$wpdb->esc_like( '_site_transient_low_dl_' ) . '%',
						$wpdb->esc_like( '_site_transient_timeout_low_dl_' ) . '%'
					)
				);
			}
		);

		foreach ( array( 'low_dl_load_centroids', 'low_dl_process_geo_queue' ) as $hook ) {
			$run(
				function () use ( $hook ) {
					wp_clear_scheduled_hook( $hook );
				}
			);
		}

		$wpdb->suppress_errors( $previous_suppress );
	}
}

if ( is_multisite() ) {
	$low_dl_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	if ( is_array( $low_dl_site_ids ) ) {
		foreach ( $low_dl_site_ids as $low_dl_site_id ) {
			switch_to_blog( (int) $low_dl_site_id );

			try {
				low_dl_uninstall_site();
			} catch ( Throwable $low_dl_uninstall_error ) {
				unset( $low_dl_uninstall_error );
			}

			restore_current_blog();
		}
	}
} else {
	low_dl_uninstall_site();
}
