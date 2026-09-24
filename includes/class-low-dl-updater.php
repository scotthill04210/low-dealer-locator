<?php
/**
 * Plugin updates from GitHub releases.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the public GitHub repository for a newer release.
 */
class LOW_DL_Updater {

	/**
	 * GitHub owner and repository.
	 */
	const REPO = 'scotthill04210/low-dealer-locator';

	/**
	 * Cached release payload.
	 */
	const CACHE_KEY = 'low_dl_github_release';

	/**
	 * Hook update checks and the Plugins screen link.
	 */
	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( LOW_DL_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'admin_post_low_dl_check_updates', array( $this, 'force_check' ) );
		add_action( 'admin_notices', array( $this, 'checked_notice' ) );
	}

	/**
	 * "Check for updates" beside the other plugin row links.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=low_dl_check_updates' ),
			'low_dl_check_updates'
		);
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'low-dealer-locator' ) . '</a>';

		$position = current_user_can( 'manage_options' ) ? 1 : 0;
		array_splice( $links, $position, 0, array( $link ) );

		return $links;
	}

	/**
	 * Clear the cached release and ask WordPress to check again.
	 *
	 * @return void
	 */
	public function force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to update plugins.', 'low-dealer-locator' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'low_dl_check_updates' );

		delete_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect(
			add_query_arg(
				'low_dl_update_checked',
				'1',
				admin_url( 'plugins.php' )
			)
		);
		exit;
	}

	/**
	 * Result of a manual check, shown once on the Plugins screen.
	 *
	 * @return void
	 */
	public function checked_notice() {
		if ( ! isset( $_GET['low_dl_update_checked'] ) || '1' !== $_GET['low_dl_update_checked'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag after a nonce-checked redirect.
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		$release = $this->remote_release();
		$status  = isset( $release['status'] ) ? $release['status'] : 'error';

		if ( 'missing' === $status ) {
			$this->notice(
				'warning',
				__( 'No GitHub release was found for LOW Dealer Locator.', 'low-dealer-locator' )
			);
			return;
		}

		if ( 'ready' !== $status ) {
			$this->notice(
				'error',
				__( 'LOW Dealer Locator could not reach GitHub to check for updates.', 'low-dealer-locator' )
			);
			return;
		}

		if ( version_compare( $release['version'], LOW_DL_VERSION, '>' ) ) {
			$this->notice(
				'warning',
				sprintf(
					/* translators: %s: available version number. */
					__( 'LOW Dealer Locator %s is available.', 'low-dealer-locator' ),
					$release['version']
				)
			);
			return;
		}

		$this->notice(
			'success',
			__( 'LOW Dealer Locator is up to date.', 'low-dealer-locator' )
		);
	}

	/**
	 * Add a GitHub release to the plugin update list when it is newer.
	 *
	 * @param object|false $transient Update data WordPress is about to store.
	 * @return object|false
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$plugin  = plugin_basename( LOW_DL_FILE );
		$release = $this->remote_release();
		$info    = $this->update_info( $release );

		if ( null === $info ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		unset( $transient->response[ $plugin ], $transient->no_update[ $plugin ] );

		if ( version_compare( $info->new_version, LOW_DL_VERSION, '>' ) ) {
			$transient->response[ $plugin ] = $info;
		} else {
			$transient->no_update[ $plugin ] = $info;
		}

		return $transient;
	}

	/**
	 * Details for the update popup.
	 *
	 * @param false|object|array $result Existing plugin info.
	 * @param string             $action Requested API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) ) {
			return $result;
		}

		if ( dirname( plugin_basename( LOW_DL_FILE ) ) !== $args->slug ) {
			return $result;
		}

		$release = $this->remote_release();

		if ( empty( $release['ok'] ) ) {
			return $result;
		}

		$notes = '' !== $release['notes'] ? $release['notes'] : __( 'Update from the GitHub release.', 'low-dealer-locator' );

		return (object) array(
			'name'          => 'LOW Dealer Locator',
			'slug'          => dirname( plugin_basename( LOW_DL_FILE ) ),
			'version'       => $release['version'],
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'download_link' => $release['package'],
			'trunk'         => $release['package'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => wpautop( esc_html( $notes ) ),
				'changelog'   => wpautop( esc_html( $notes ) ),
			),
		);
	}

	/**
	 * GitHub archive folders are not named low-dealer-locator. Rename this one.
	 *
	 * @param string      $source        Extracted directory.
	 * @param string      $remote_source Temporary parent directory.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Install context.
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $upgrader );

		$plugin = plugin_basename( LOW_DL_FILE );

		if ( ! is_array( $hook_extra ) || ! isset( $hook_extra['plugin'] ) || $plugin !== $hook_extra['plugin'] ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . dirname( $plugin );

		if ( trailingslashit( $source ) === trailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;

		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Latest GitHub release, from the cache unless it is missing.
	 *
	 * @return array{ok: bool, tag?: string, version?: string, package?: string, url?: string, notes?: string, published?: string}
	 */
	private function remote_release() {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && array_key_exists( 'ok', $cached ) ) {
			return $cached;
		}

		$release = $this->fetch_release();
		$ttl     = ! empty( $release['ok'] ) ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
		set_transient( self::CACHE_KEY, $release, $ttl );

		return $release;
	}

	/**
	 * Request the latest release from the GitHub API.
	 *
	 * @return array{ok: bool, tag?: string, version?: string, package?: string, url?: string, notes?: string, published?: string}
	 */
	private function fetch_release() {
		$failed = array(
			'ok'     => false,
			'status' => 'error',
		);
		$url    = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'LOW-Dealer-Locator/' . LOW_DL_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $failed;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return array(
				'ok'     => false,
				'status' => 'missing',
			);
		}

		if ( 200 !== $code ) {
			return $failed;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['tag_name'] ) || ! is_string( $body['tag_name'] ) ) {
			return $failed;
		}

		$tag = $body['tag_name'];

		if ( ! preg_match( '/^v?\d+\.\d+\.\d+(?:[-.][0-9A-Za-z.]+)?$/', $tag ) ) {
			return $failed;
		}

		$version = preg_replace( '/^v/', '', $tag );
		$notes   = ( isset( $body['body'] ) && is_string( $body['body'] ) ) ? trim( $body['body'] ) : '';
		$date    = ( isset( $body['published_at'] ) && is_string( $body['published_at'] ) ) ? $body['published_at'] : '';

		return array(
			'ok'        => true,
			'status'    => 'ready',
			'tag'       => $tag,
			'version'   => $version,
			'package'   => 'https://github.com/' . self::REPO . '/archive/refs/tags/' . rawurlencode( $tag ) . '.zip',
			'url'       => 'https://github.com/' . self::REPO . '/releases/tag/' . rawurlencode( $tag ),
			'notes'     => $notes,
			'published' => $date,
		);
	}

	/**
	 * Update object WordPress stores for this plugin.
	 *
	 * @param array $release Release payload.
	 * @return object|null
	 */
	private function update_info( $release ) {
		if ( empty( $release['ok'] ) ) {
			return null;
		}

		return (object) array(
			'id'           => plugin_basename( LOW_DL_FILE ),
			'slug'         => dirname( plugin_basename( LOW_DL_FILE ) ),
			'plugin'       => plugin_basename( LOW_DL_FILE ),
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);
	}

	/**
	 * Print one admin notice.
	 *
	 * @param string $class   notice-success, notice-warning, or notice-error.
	 * @param string $message Notice text.
	 * @return void
	 */
	private function notice( $class, $message ) {
		echo '<div class="notice notice-' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
