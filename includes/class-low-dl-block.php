<?php
/**
 * Gutenberg block wrapper for the dealer locator.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Dealer Locator block and renders it through the shortcode.
 */
class LOW_DL_Block {

	/**
	 * Hook block registration.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the editor script and the block type.
	 *
	 * @return void
	 */
	public function register() {
		wp_register_script(
			'low-dl-block-editor',
			LOW_DL_URL . 'blocks/locator/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			LOW_DL_VERSION,
			true
		);

		wp_set_script_translations( 'low-dl-block-editor', 'low-dealer-locator' );

		register_block_type(
			LOW_DL_PATH . 'blocks/locator',
			array(
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Server render. Output is exactly the shared locator markup.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$height = '';
		$zoom   = '';

		if ( is_array( $attributes ) ) {
			if ( isset( $attributes['height'] ) && is_scalar( $attributes['height'] ) ) {
				$height = (string) $attributes['height'];
			}

			if ( isset( $attributes['zoom'] ) && is_scalar( $attributes['zoom'] ) ) {
				$zoom = (string) $attributes['zoom'];
			}
		}

		return LOW_DL_Shortcode::render(
			array(
				'height' => $height,
				'zoom'   => $zoom,
			)
		);
	}
}
