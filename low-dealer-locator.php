<?php
/**
 * Plugin Name:       LOW Dealer Locator
 * Description:       Attach service-area zip codes to dealer records and publish them for a front-end locator.
 * Version:           0.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/scotthill04210/low-dealer-locator
 * Text Domain:       low-dealer-locator
 * Domain Path:       /languages
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LOW_DL_VERSION', '0.3.1' );
define( 'LOW_DL_FILE', __FILE__ );
define( 'LOW_DL_PATH', plugin_dir_path( __FILE__ ) );
define( 'LOW_DL_URL', plugin_dir_url( __FILE__ ) );

require_once LOW_DL_PATH . 'includes/class-low-dl-plugin.php';
require_once LOW_DL_PATH . 'includes/class-low-dl-install.php';

register_activation_hook( LOW_DL_FILE, array( 'LOW_DL_Install', 'activate' ) );

add_action( 'plugins_loaded', array( 'LOW_DL_Plugin', 'instance' ) );
