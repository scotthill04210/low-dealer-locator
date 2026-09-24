<?php
/**
 * Front-end locator shortcode and shared markup.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [low_dealer_locator] and the markup the block and widget will reuse.
 */
class LOW_DL_Shortcode {

	/**
	 * How many locators this request has rendered.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Register the shortcode and the assets. Assets stay unqueued until render().
	 */
	public function __construct() {
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Shortcode tag.
	 *
	 * @return void
	 */
	public static function register_shortcode() {
		add_shortcode( 'low_dealer_locator', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Register Leaflet and the locator assets. Does not enqueue them.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'low-dl-leaflet',
			LOW_DL_URL . 'assets/vendor/leaflet/leaflet.css',
			array(),
			LOW_DL_VERSION
		);

		wp_register_script(
			'low-dl-leaflet',
			LOW_DL_URL . 'assets/vendor/leaflet/leaflet.js',
			array(),
			LOW_DL_VERSION,
			true
		);

		wp_register_style(
			'low-dl-locator',
			LOW_DL_URL . 'assets/css/locator.css',
			array( 'low-dl-leaflet' ),
			LOW_DL_VERSION
		);

		wp_register_script(
			'low-dl-locator',
			LOW_DL_URL . 'assets/js/locator.js',
			array( 'low-dl-leaflet' ),
			LOW_DL_VERSION,
			true
		);
	}

	/**
	 * Shortcode callback.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		return self::render( $atts );
	}

	/**
	 * Locator shell. Used by the shortcode now and by the block and widget later.
	 *
	 * @param array $atts height and zoom, both optional.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		if ( ! is_array( $atts ) ) {
			$atts = array();
		}

		$atts = shortcode_atts(
			array(
				'height' => '',
				'zoom'   => '',
			),
			$atts,
			'low_dealer_locator'
		);

		$settings = LOW_DL_Settings::get_all();
		$height   = self::map_height( $settings );
		$zoom     = self::map_zoom( $settings );
		$override_height = self::height_override( $atts['height'] );
		$override_zoom   = self::zoom_override( $atts['zoom'] );

		if ( null !== $override_height ) {
			$height = $override_height;
		}

		if ( null !== $override_zoom ) {
			$zoom = $override_zoom;
		}

		self::register_assets();
		wp_enqueue_style( 'low-dl-leaflet' );
		wp_enqueue_style( 'low-dl-locator' );
		wp_enqueue_script( 'low-dl-leaflet' );
		wp_enqueue_script( 'low-dl-locator' );

		++self::$count;

		$id    = 'low-dl-locator-' . self::$count;
		$input = 'low-dl-q-' . self::$count;
		$color = self::marker_color( $settings );
		$unit  = ( isset( $settings['distance_unit'] ) && 'km' === $settings['distance_unit'] ) ? 'km' : 'mi';
		$tile  = self::tile_url( $settings );
		$i18n  = self::i18n_strings();
		$json  = wp_json_encode( $i18n );

		if ( ! is_string( $json ) ) {
			$json = '{}';
		}

		$style = '--low-dl-map-height:' . (int) $height . 'px;--low-dl-marker:' . $color;

		$html  = '<div id="' . esc_attr( $id ) . '" class="low-dl-locator"';
		$html .= ' style="' . esc_attr( $style ) . '"';
		$html .= ' data-search-url="' . esc_url( rest_url( LOW_DL_REST::NAMESPACE . '/search' ) ) . '"';
		$html .= ' data-dealers-url="' . esc_url( rest_url( LOW_DL_REST::NAMESPACE . '/dealers' ) ) . '"';
		$html .= ' data-zoom="' . esc_attr( (string) (int) $zoom ) . '"';
		$html .= ' data-unit="' . esc_attr( $unit ) . '"';
		// esc_attr keeps {z} {x} {y}. esc_url would strip the braces.
		$html .= ' data-tile-url="' . esc_attr( $tile ) . '"';
		$html .= ' data-attribution="' . esc_attr( self::attribution( $settings ) ) . '"';
		$html .= ' data-i18n="' . esc_attr( $json ) . '">';

		$html .= '<form class="low-dl-form" role="search">';
		$html .= '<label class="low-dl-sr-only" for="' . esc_attr( $input ) . '">' . esc_html__( 'Zip code or address', 'low-dealer-locator' ) . '</label>';
		$html .= '<input type="text" id="' . esc_attr( $input ) . '" name="q" autocomplete="off" maxlength="200"';
		$html .= ' placeholder="' . esc_attr( $i18n['search_placeholder'] ) . '" />';
		$html .= '<button type="submit">' . esc_html( $i18n['search_button'] ) . '</button>';
		$html .= '<button type="button" class="low-dl-geo" hidden>' . esc_html( $i18n['use_location'] ) . '</button>';
		$html .= '</form>';

		$html .= '<div class="low-dl-message" role="status" aria-live="polite"></div>';

		$html .= '<div class="low-dl-body">';
		$html .= '<div class="low-dl-results">';
		$html .= '<h2 aria-live="off"></h2>';
		$html .= '<ul></ul>';
		$html .= '</div>';
		$html .= '<div class="low-dl-map" role="region" aria-label="' . esc_attr( $i18n['map_label'] ) . '"></div>';
		$html .= '</div>';

		$html .= '<noscript>' . esc_html__( 'The dealer locator needs JavaScript. Please enable it to search for dealers.', 'low-dealer-locator' ) . '</noscript>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Map height from settings, limited to the allowed range.
	 *
	 * @param array $settings Saved settings.
	 * @return int
	 */
	private static function map_height( $settings ) {
		$height = isset( $settings['map_height'] ) ? absint( $settings['map_height'] ) : 450;

		if ( $height < 200 || $height > 1200 ) {
			return 450;
		}

		return $height;
	}

	/**
	 * Default zoom from settings, limited to the allowed range.
	 *
	 * @param array $settings Saved settings.
	 * @return int
	 */
	private static function map_zoom( $settings ) {
		$zoom = isset( $settings['default_zoom'] ) ? absint( $settings['default_zoom'] ) : 4;

		if ( $zoom < 1 || $zoom > 18 ) {
			return 4;
		}

		return $zoom;
	}

	/**
	 * Shortcode height when it is all digits and inside 200 to 1200.
	 *
	 * @param mixed $value Attribute value.
	 * @return int|null
	 */
	private static function height_override( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( ! preg_match( '/^\d+$/', $value ) ) {
			return null;
		}

		$height = (int) $value;

		if ( $height < 200 || $height > 1200 ) {
			return null;
		}

		return $height;
	}

	/**
	 * Shortcode zoom when it is between 1 and 18.
	 *
	 * @param mixed $value Attribute value.
	 * @return int|null
	 */
	private static function zoom_override( $value ) {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$zoom = (int) $value;

		if ( (float) $zoom !== (float) $value || $zoom < 1 || $zoom > 18 ) {
			return null;
		}

		return $zoom;
	}

	/**
	 * Sanitized marker color.
	 *
	 * @param array $settings Saved settings.
	 * @return string
	 */
	private static function marker_color( $settings ) {
		$color = isset( $settings['marker_color'] ) ? $settings['marker_color'] : '';

		if ( ! is_string( $color ) || 1 !== preg_match( '/^#([a-fA-F0-9]{3}){1,2}$/', $color ) ) {
			return '#d9480f';
		}

		return strtolower( $color );
	}

	/**
	 * Tile template. Braces are left intact.
	 *
	 * @param array $settings Saved settings.
	 * @return string
	 */
	private static function tile_url( $settings ) {
		$default = (string) LOW_DL_Settings::defaults()['tile_url'];
		$url     = isset( $settings['tile_url'] ) ? $settings['tile_url'] : '';

		if ( ! is_string( $url ) || '' === $url ) {
			return $default;
		}

		if ( false === strpos( $url, '{z}' ) || false === strpos( $url, '{x}' ) || false === strpos( $url, '{y}' ) ) {
			return $default;
		}

		return $url;
	}

	/**
	 * OpenStreetMap credit, plus any extra attribution the site added.
	 *
	 * The OpenStreetMap credit is always present.
	 *
	 * @param array $settings Saved settings.
	 * @return string
	 */
	private static function attribution( $settings ) {
		$credit = '© OpenStreetMap contributors';
		$extra  = isset( $settings['tile_attribution'] ) ? $settings['tile_attribution'] : '';

		if ( ! is_string( $extra ) ) {
			return $credit;
		}

		$extra = trim( $extra );

		if ( '' === $extra ) {
			return $credit;
		}

		return $credit . ' | ' . $extra;
	}

	/**
	 * Strings the front-end script will read from data-i18n.
	 *
	 * @return array<string, string>
	 */
	private static function i18n_strings() {
		return array(
			'search_placeholder'  => __( 'Zip code or address', 'low-dealer-locator' ),
			'search_button'       => __( 'Search', 'low-dealer-locator' ),
			'use_location'        => __( 'Use my location', 'low-dealer-locator' ),
			'locating'            => __( 'Finding your location...', 'low-dealer-locator' ),
			'location_error'      => __( 'Your location could not be read. Please try again or search by zip code or address.', 'low-dealer-locator' ),
			'location_insecure'   => __( 'Location is only available on a secure page.', 'low-dealer-locator' ),
			'searching'           => __( 'Searching...', 'low-dealer-locator' ),
			'network_error'       => __( 'We could not complete that search. Please try again.', 'low-dealer-locator' ),
			'invalid_zip'         => __( 'Please enter a valid 5-digit zip code.', 'low-dealer-locator' ),
			'empty_input'         => __( 'Please enter a zip code or an address.', 'low-dealer-locator' ),
			'results_count_one'   => __( '1 dealer', 'low-dealer-locator' ),
			'results_count_many'  => __( '%d dealers', 'low-dealer-locator' ),
			'distance_away'       => __( '%s away', 'low-dealer-locator' ),
			'unit_mi'             => __( 'mi', 'low-dealer-locator' ),
			'unit_km'             => __( 'km', 'low-dealer-locator' ),
			'phone'               => __( 'Phone', 'low-dealer-locator' ),
			'website'             => __( 'Website', 'low-dealer-locator' ),
			'email'               => __( 'Email', 'low-dealer-locator' ),
			'view_on_map'         => __( 'View on map', 'low-dealer-locator' ),
			'map_label'           => __( 'Dealer map', 'low-dealer-locator' ),
		);
	}
}
