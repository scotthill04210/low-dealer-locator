<?php
/**
 * Plugin bootstrap.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the plugin and wires hooks.
 */
class LOW_DL_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var LOW_DL_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return LOW_DL_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks and load classes that exist in this stage.
	 */
	private function __construct() {
		$this->includes();

		add_action( 'init', array( $this, 'load_textdomain' ) );

		new LOW_DL_Settings();
		new LOW_DL_Post_Type();
		new LOW_DL_Zip_Manager();
		new LOW_DL_Field_Mapper();
		new LOW_DL_REST();
		new LOW_DL_Centroid_Table();
		new LOW_DL_Geo_Queue();
		new LOW_DL_Shortcode();
		new LOW_DL_Block();
		new LOW_DL_Widget();
		new LOW_DL_Updater();

		add_action( 'low_dl_load_centroids', array( 'LOW_DL_Centroid_Table', 'load' ) );

		LOW_DL_Install::maybe_upgrade();
	}

	/**
	 * Load class files.
	 *
	 * @return void
	 */
	private function includes() {
		require_once LOW_DL_PATH . 'includes/class-low-dl-settings.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-post-type.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-zip-manager.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-field-mapper.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-rest.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-centroid-table.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-geocoder.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-geo-queue.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-shortcode.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-block.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-widget.php';
		require_once LOW_DL_PATH . 'includes/class-low-dl-updater.php';
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'low-dealer-locator',
			false,
			dirname( plugin_basename( LOW_DL_FILE ) ) . '/languages'
		);
	}
}
