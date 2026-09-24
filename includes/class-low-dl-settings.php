<?php
/**
 * Settings page and option access.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > Dealer Locator.
 */
class LOW_DL_Settings {

	/**
	 * Option group and option name.
	 */
	const OPTION_KEY = 'low_dl_settings';

	/**
	 * Settings screen slug.
	 */
	const MENU_SLUG = 'low-dealer-locator';

	/**
	 * Keys written when the Locator tab is saved.
	 */
	const LOCATOR_KEYS = array(
		'nearest_count',
		'max_distance',
		'distance_unit',
		'heading_covered',
		'heading_fallback',
		'heading_nearest',
		'empty_text',
		'map_height',
		'marker_color',
		'searched_marker_color',
		'default_zoom',
		'tile_url',
		'tile_attribution',
	);

	/**
	 * Hook callbacks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LOW_DL_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types'               => array(),
			'create_dealer_cpt'        => 0,
			'delete_data_on_uninstall' => 0,
			'field_mappings'           => array(),
			'geocoder_url'             => 'https://nominatim.openstreetmap.org/search',
			'geocoder_key'             => '',
			'geocoder_key_param'       => 'key',
			'geocoder_email'           => '',
			'geocoder_countries'       => 'us',
			'geocoder_interval'        => 1,
			'nearest_count'            => 5,
			'max_distance'             => 0,
			'distance_unit'            => 'mi',
			'heading_covered'          => '',
			'heading_fallback'         => '',
			'heading_nearest'          => '',
			'empty_text'               => '',
			'map_height'               => 450,
			'marker_color'             => '#d9480f',
			'searched_marker_color'    => '#1c7ed6',
			'default_zoom'             => 4,
			'tile_url'                 => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
			'tile_attribution'         => '',
		);
	}

	/**
	 * Locator copy: the saved string, or the translated default when it is blank.
	 *
	 * Defaults are translated here, not stored in the option.
	 *
	 * @param string $key heading_covered, heading_fallback, heading_nearest, or empty_text.
	 * @return string
	 */
	public static function get_text( $key ) {
		$defaults = self::default_texts();

		if ( ! isset( $defaults[ $key ] ) ) {
			return '';
		}

		$saved = self::get( $key );

		if ( is_string( $saved ) && '' !== $saved ) {
			return $saved;
		}

		return $defaults[ $key ];
	}

	/**
	 * Translated fallbacks for blank locator messages.
	 *
	 * @return array<string, string>
	 */
	private static function default_texts() {
		return array(
			'heading_covered'  => __( 'Dealers serving your area', 'low-dealer-locator' ),
			'heading_fallback' => __( 'No dealers list your zip code. Here are the nearest.', 'low-dealer-locator' ),
			'heading_nearest'  => __( 'Nearest dealers', 'low-dealer-locator' ),
			'empty_text'       => __( 'No dealers found nearby. Please contact us for help.', 'low-dealer-locator' ),
		);
	}

	/**
	 * Saved settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, self::defaults() );

		if ( ! is_array( $settings['post_types'] ) ) {
			$settings['post_types'] = array();
		}

		$settings['create_dealer_cpt']        = empty( $settings['create_dealer_cpt'] ) ? 0 : 1;
		$settings['delete_data_on_uninstall'] = empty( $settings['delete_data_on_uninstall'] ) ? 0 : 1;

		if ( ! is_array( $settings['field_mappings'] ) ) {
			$settings['field_mappings'] = array();
		}

		return $settings;
	}

	/**
	 * One saved setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	public static function get( $key ) {
		$settings = self::get_all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : null;
	}

	/**
	 * A saved hex color expanded for a color input, or the fallback.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Six-digit hex color.
	 * @return string
	 */
	private static function color_for_input( $key, $fallback ) {
		$color = self::get( $key );

		if ( ! is_string( $color ) || 1 !== preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ) {
			return $fallback;
		}

		$color = strtolower( $color );

		if ( 4 === strlen( $color ) ) {
			$color = sprintf( '#%1$s%1$s%2$s%2$s%3$s%3$s', $color[1], $color[2], $color[3] );
		}

		return $color;
	}

	/**
	 * Post types that hold dealers.
	 *
	 * Saved slugs, plus dealer_locator when that post type is enabled.
	 * Drops anything that is not registered.
	 *
	 * @return string[]
	 */
	public static function get_dealer_post_types() {
		$settings = self::get_all();
		$slugs    = array();

		if ( is_array( $settings['post_types'] ) ) {
			foreach ( $settings['post_types'] as $slug ) {
				if ( is_scalar( $slug ) ) {
					$slugs[] = (string) $slug;
				}
			}
		}

		if ( ! empty( $settings['create_dealer_cpt'] ) ) {
			$slugs[] = 'dealer_locator';
		}

		$active = array();

		foreach ( $slugs as $slug ) {
			if ( post_type_exists( $slug ) ) {
				$active[] = $slug;
			}
		}

		return array_values( array_unique( $active ) );
	}

	/**
	 * Tabs that can be requested with ?tab=.
	 *
	 * @return array<string, string> Slug => label.
	 */
	private function get_tabs() {
		return array(
			'general'       => __( 'General', 'low-dealer-locator' ),
			'import'        => __( 'Import', 'low-dealer-locator' ),
			'mapping'       => __( 'Field Mapping', 'low-dealer-locator' ),
			'geocoding'     => __( 'Geocoding', 'low-dealer-locator' ),
			'locator'       => __( 'Locator', 'low-dealer-locator' ),
			'instructions'  => __( 'Instructions', 'low-dealer-locator' ),
		);
	}

	/**
	 * Settings API page id for a tab. Later tabs register their own page id.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function get_tab_page( $tab ) {
		return self::MENU_SLUG . '-' . $tab;
	}

	/**
	 * Whitelisted current tab.
	 *
	 * @return string
	 */
	private function get_current_tab() {
		$tabs = $this->get_tabs();
		$tab  = 'general';

		// Read-only navigation. The Settings API nonce covers the form POST.
		if ( isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = sanitize_key( wp_unslash( (string) $_GET['tab'] ) );

			if ( isset( $tabs[ $requested ] ) ) {
				$tab = $requested;
			}
		}

		return $tab;
	}

	/**
	 * Admin URL for a settings tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function get_tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Add Settings > Dealer Locator.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'Dealer Locator', 'low-dealer-locator' ),
			__( 'Dealer Locator', 'low-dealer-locator' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option, then each tab's Settings API sections.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_KEY,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		foreach ( array_keys( $this->get_tabs() ) as $tab_slug ) {
			$method = 'register_' . $tab_slug . '_tab';

			if ( method_exists( $this, $method ) ) {
				$this->$method();
			}
		}
	}

	/**
	 * General tab fields.
	 *
	 * @return void
	 */
	private function register_general_tab() {
		$page = $this->get_tab_page( 'general' );

		add_settings_section(
			'low_dl_general_section',
			'',
			'__return_null',
			$page
		);

		add_settings_field(
			'low_dl_post_types',
			__( 'Dealer post types', 'low-dealer-locator' ),
			array( $this, 'render_post_types_field' ),
			$page,
			'low_dl_general_section'
		);

		add_settings_field(
			'low_dl_create_dealer_cpt',
			__( 'Create a Dealer Locator post type', 'low-dealer-locator' ),
			array( $this, 'render_create_cpt_field' ),
			$page,
			'low_dl_general_section',
			array(
				'label_for' => 'low-dl-create-dealer-cpt',
			)
		);

		add_settings_field(
			'low_dl_delete_data_on_uninstall',
			__( 'Delete all plugin data when the plugin is deleted', 'low-dealer-locator' ),
			array( $this, 'render_delete_data_field' ),
			$page,
			'low_dl_general_section',
			array(
				'label_for' => 'low-dl-delete-data-on-uninstall',
			)
		);
	}

	/**
	 * Merge one tab's posted values over the saved option.
	 *
	 * An unknown tab changes nothing. The _tab flag is never stored.
	 *
	 * @param mixed $input Posted settings.
	 * @return array
	 */
	public function sanitize( $input ) {
		$clean = self::get_all();
		unset( $clean['_tab'] );

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$tab = '';

		if ( isset( $input['_tab'] ) && is_scalar( $input['_tab'] ) ) {
			$tab = sanitize_key( wp_unslash( (string) $input['_tab'] ) );
		}

		if ( 'general' === $tab ) {
			$clean['post_types']               = $this->sanitize_post_types( isset( $input['post_types'] ) ? $input['post_types'] : array() );
			$clean['create_dealer_cpt']        = ( isset( $input['create_dealer_cpt'] ) && is_scalar( $input['create_dealer_cpt'] ) && absint( $input['create_dealer_cpt'] ) > 0 ) ? 1 : 0;
			$clean['delete_data_on_uninstall'] = ( isset( $input['delete_data_on_uninstall'] ) && is_scalar( $input['delete_data_on_uninstall'] ) && absint( $input['delete_data_on_uninstall'] ) > 0 ) ? 1 : 0;
		} elseif ( 'mapping' === $tab ) {
			$posted                  = ( isset( $input['field_mappings'] ) && is_array( $input['field_mappings'] ) ) ? $input['field_mappings'] : array();
			$clean['field_mappings'] = LOW_DL_Field_Mapper::sanitize_mappings( $posted );
		} elseif ( 'geocoding' === $tab ) {
			$updated = LOW_DL_Geocoder::sanitize_settings( $input, $clean );

			foreach ( array( 'geocoder_url', 'geocoder_key', 'geocoder_key_param', 'geocoder_email', 'geocoder_countries', 'geocoder_interval' ) as $key ) {
				if ( is_array( $updated ) && array_key_exists( $key, $updated ) ) {
					$clean[ $key ] = $updated[ $key ];
				}
			}
		} elseif ( 'locator' === $tab ) {
			$updated = $this->sanitize_locator( $input );

			foreach ( self::LOCATOR_KEYS as $key ) {
				$clean[ $key ] = $updated[ $key ];
			}
		}

		unset( $clean['_tab'] );

		return $clean;
	}

	/**
	 * Locator tab values. Other setting keys are left to the caller.
	 *
	 * @param array $input Posted settings.
	 * @return array
	 */
	private function sanitize_locator( $input ) {
		$count = 5;

		if ( isset( $input['nearest_count'] ) && is_scalar( $input['nearest_count'] ) && is_numeric( wp_unslash( (string) $input['nearest_count'] ) ) ) {
			$count = absint( $input['nearest_count'] );

			if ( $count < 1 ) {
				$count = 1;
			} elseif ( $count > 50 ) {
				$count = 50;
			}
		}

		$distance = 0.0;

		if ( isset( $input['max_distance'] ) && is_scalar( $input['max_distance'] ) && is_numeric( wp_unslash( (string) $input['max_distance'] ) ) ) {
			$distance = (float) wp_unslash( (string) $input['max_distance'] );

			if ( $distance < 0 ) {
				$distance = 0.0;
			} elseif ( $distance > 20000 ) {
				$distance = 20000.0;
			}
		}

		$unit = 'mi';

		if ( isset( $input['distance_unit'] ) && is_scalar( $input['distance_unit'] ) ) {
			$posted = sanitize_key( wp_unslash( (string) $input['distance_unit'] ) );

			if ( 'km' === $posted ) {
				$unit = 'km';
			}
		}

		$clean = array(
			'nearest_count' => $count,
			'max_distance'  => $distance,
			'distance_unit' => $unit,
		);

		foreach ( array( 'heading_covered', 'heading_fallback', 'heading_nearest', 'empty_text' ) as $key ) {
			$clean[ $key ] = $this->limit_locator_text( isset( $input[ $key ] ) ? $input[ $key ] : '' );
		}

		$defaults = self::defaults();
		$height   = (int) $defaults['map_height'];

		if ( isset( $input['map_height'] ) && is_scalar( $input['map_height'] ) && is_numeric( wp_unslash( (string) $input['map_height'] ) ) ) {
			$height = absint( $input['map_height'] );

			if ( $height < 200 ) {
				$height = 200;
			} elseif ( $height > 1200 ) {
				$height = 1200;
			}
		}

		$zoom = (int) $defaults['default_zoom'];

		if ( isset( $input['default_zoom'] ) && is_scalar( $input['default_zoom'] ) && is_numeric( wp_unslash( (string) $input['default_zoom'] ) ) ) {
			$zoom = absint( $input['default_zoom'] );

			if ( $zoom < 1 ) {
				$zoom = 1;
			} elseif ( $zoom > 18 ) {
				$zoom = 18;
			}
		}

		$clean['map_height']            = $height;
		$clean['marker_color']          = $this->sanitize_hex_color( isset( $input['marker_color'] ) ? $input['marker_color'] : '', '#d9480f' );
		$clean['searched_marker_color'] = $this->sanitize_hex_color( isset( $input['searched_marker_color'] ) ? $input['searched_marker_color'] : '', '#1c7ed6' );
		$clean['default_zoom']          = $zoom;
		$clean['tile_url']         = $this->sanitize_tile_url( isset( $input['tile_url'] ) ? $input['tile_url'] : '' );
		$clean['tile_attribution'] = $this->limit_locator_text( isset( $input['tile_attribution'] ) ? $input['tile_attribution'] : '' );

		return $clean;
	}

	/**
	 * Hex color, or the given default.
	 *
	 * @param mixed  $value   Posted color.
	 * @param string $default Fallback hex color.
	 * @return string
	 */
	private function sanitize_hex_color( $value, $default ) {

		if ( ! is_scalar( $value ) ) {
			return $default;
		}

		$color = sanitize_hex_color( trim( wp_unslash( (string) $value ) ) );

		if ( ! is_string( $color ) || '' === $color ) {
			return $default;
		}

		$color = strtolower( $color );

		if ( 4 === strlen( $color ) ) {
			$color = sprintf(
				'#%1$s%1$s%2$s%2$s%3$s%3$s',
				$color[1],
				$color[2],
				$color[3]
			);
		}

		return $color;
	}

	/**
	 * Https tile template with {z}, {x}, and {y}. Anything else restores the default.
	 *
	 * esc_url_raw strips braces, so the placeholders are protected first and put back after.
	 *
	 * @param mixed $value Posted URL.
	 * @return string
	 */
	private function sanitize_tile_url( $value ) {
		$default = (string) self::defaults()['tile_url'];
		$raw     = '';

		if ( is_scalar( $value ) ) {
			$raw = trim( (string) wp_unslash( $value ) );
		}

		if ( ! $this->is_tile_template( $raw ) ) {
			$this->tile_url_error();
			return $default;
		}

		$protected = str_replace(
			array( '{z}', '{x}', '{y}' ),
			array( '__lowdlz__', '__lowdlx__', '__lowdly__' ),
			$raw
		);
		$clean     = esc_url_raw( $protected );

		if ( ! is_string( $clean ) || '' === $clean ) {
			$this->tile_url_error();
			return $default;
		}

		$clean = str_replace(
			array( '__lowdlz__', '__lowdlx__', '__lowdly__' ),
			array( '{z}', '{x}', '{y}' ),
			$clean
		);

		if ( ! $this->is_tile_template( $clean ) ) {
			$this->tile_url_error();
			return $default;
		}

		return $clean;
	}

	/**
	 * Whether a tile URL is https and still has its placeholders.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_tile_template( $url ) {
		if ( '' === $url ) {
			return false;
		}

		if ( false === strpos( $url, '{z}' ) || false === strpos( $url, '{x}' ) || false === strpos( $url, '{y}' ) ) {
			return false;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $scheme ) || 'https' !== strtolower( $scheme ) ) {
			return false;
		}

		return is_string( $host ) && '' !== $host;
	}

	/**
	 * Restore-the-default notice for a rejected tile URL.
	 *
	 * The rejected address is not included.
	 *
	 * @return void
	 */
	private function tile_url_error() {
		add_settings_error(
			self::OPTION_KEY,
			'low_dl_tile_url',
			__( 'The map tile URL must be an https address that includes {z}, {x}, and {y}. The default was restored.', 'low-dealer-locator' ),
			'error'
		);
	}

	/**
	 * Plain text, at most 200 characters.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	private function limit_locator_text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = sanitize_text_field( wp_unslash( (string) $value ) );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $text ) > 200 ) {
			return mb_substr( $text, 0, 200 );
		}

		if ( strlen( $text ) > 200 ) {
			return substr( $text, 0, 200 );
		}

		return $text;
	}

	/**
	 * Keep only real, UI-visible post type slugs.
	 *
	 * @param mixed $posted Posted post type list.
	 * @return string[]
	 */
	private function sanitize_post_types( $posted ) {
		$post_types = array();

		if ( ! is_array( $posted ) ) {
			return $post_types;
		}

		foreach ( $posted as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}

			$slug = sanitize_key( wp_unslash( (string) $slug ) );

			if ( '' === $slug || 'attachment' === $slug || ! post_type_exists( $slug ) ) {
				continue;
			}

			$post_type_object = get_post_type_object( $slug );

			if ( ! $post_type_object || empty( $post_type_object->show_ui ) ) {
				continue;
			}

			$post_types[] = $slug;
		}

		return array_values( array_unique( $post_types ) );
	}

	/**
	 * Post types an admin is allowed to select.
	 *
	 * @return WP_Post_Type[]
	 */
	private function get_selectable_post_types() {
		$objects = get_post_types(
			array(
				'show_ui' => true,
			),
			'objects'
		);

		unset( $objects['attachment'] );

		uasort(
			$objects,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a->labels->name, (string) $b->labels->name );
			}
		);

		return $objects;
	}

	/**
	 * Checklist of dealer post types plus a live filter.
	 *
	 * @return void
	 */
	public function render_post_types_field() {
		$selected   = self::get( 'post_types' );
		$post_types = $this->get_selectable_post_types();

		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		?>
		<fieldset>
			<legend class="screen-reader-text"><?php echo esc_html__( 'Dealer post types', 'low-dealer-locator' ); ?></legend>
			<p>
				<label for="low-dl-post-type-filter">
					<?php echo esc_html__( 'Filter', 'low-dealer-locator' ); ?>
				</label>
				<input
					type="search"
					id="low-dl-post-type-filter"
					class="regular-text low-dl-post-type-filter"
					placeholder="<?php echo esc_attr__( 'Filter post types', 'low-dealer-locator' ); ?>"
					aria-controls="low-dl-post-type-list"
				/>
			</p>
			<?php if ( empty( $post_types ) ) : ?>
				<p><?php echo esc_html__( 'No post types are available.', 'low-dealer-locator' ); ?></p>
			<?php else : ?>
				<ul id="low-dl-post-type-list" class="low-dl-post-type-list">
					<?php foreach ( $post_types as $slug => $post_type ) : ?>
						<?php
						$label = isset( $post_type->labels->name ) ? $post_type->labels->name : $slug;
						?>
						<li class="low-dl-post-type" data-search="<?php echo esc_attr( $label . ' ' . $slug ); ?>">
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[post_types][]"
									value="<?php echo esc_attr( $slug ); ?>"
									<?php checked( in_array( $slug, $selected, true ) ); ?>
								/>
								<?php echo esc_html( $label ); ?>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Flag stored for a later stage that registers the dealer post type.
	 *
	 * @return void
	 */
	public function render_create_cpt_field() {
		$value = (int) self::get( 'create_dealer_cpt' );
		?>
		<input
			type="checkbox"
			id="low-dl-create-dealer-cpt"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[create_dealer_cpt]"
			value="1"
			<?php checked( 1, $value ); ?>
		/>
		<p class="description">
			<?php echo esc_html__( 'Use this if none of your existing post types hold dealers. The post type is registered in a later stage.', 'low-dealer-locator' ); ?>
		</p>
		<?php
	}

	/**
	 * Opt in to removing plugin data when the plugin is deleted.
	 *
	 * @return void
	 */
	public function render_delete_data_field() {
		$value = (int) self::get( 'delete_data_on_uninstall' );
		?>
		<input
			type="checkbox"
			id="low-dl-delete-data-on-uninstall"
			name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_data_on_uninstall]"
			value="1"
			<?php checked( 1, $value ); ?>
		/>
		<p class="description">
			<?php echo esc_html__( 'Removes the zip code and coordinate lookup tables, saved settings, and dealer zip, contact, and coordinate fields. Dealer posts themselves are never deleted. This cannot be undone.', 'low-dealer-locator' ); ?>
		</p>
		<?php
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'low-dealer-locator' ) );
		}

		$tabs = $this->get_tabs();
		$tab  = $this->get_current_tab();
		?>
		<div class="wrap low-dl-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Dealer Locator settings sections', 'low-dealer-locator' ); ?>">
				<?php foreach ( $tabs as $tab_slug => $tab_label ) : ?>
					<a
						href="<?php echo esc_url( $this->get_tab_url( $tab_slug ) ); ?>"
						class="nav-tab<?php echo $tab === $tab_slug ? ' nav-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php if ( 'import' === $tab ) : ?>
				<?php LOW_DL_Zip_Manager::render_import_tab(); ?>
			<?php elseif ( 'instructions' === $tab ) : ?>
				<?php $this->render_instructions(); ?>
			<?php elseif ( 'mapping' === $tab && empty( self::get_dealer_post_types() ) ) : ?>
				<?php LOW_DL_Field_Mapper::render_fields(); ?>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php settings_fields( self::OPTION_KEY ); ?>
					<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>" />
					<?php if ( 'mapping' === $tab ) : ?>
						<?php LOW_DL_Field_Mapper::render_fields(); ?>
					<?php elseif ( 'geocoding' === $tab ) : ?>
						<?php LOW_DL_Geocoder::render_fields(); ?>
					<?php elseif ( 'locator' === $tab ) : ?>
						<?php $this->render_locator_fields(); ?>
					<?php else : ?>
						<?php do_settings_sections( $this->get_tab_page( $tab ) ); ?>
					<?php endif; ?>
					<?php submit_button(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Locator tab fields.
	 *
	 * @return void
	 */
	public function render_locator_fields() {
		$name     = self::OPTION_KEY;
		$count    = self::get( 'nearest_count' );
		$distance = self::get( 'max_distance' );
		$unit     = self::get( 'distance_unit' );
		$texts    = self::default_texts();

		if ( ! is_numeric( $count ) ) {
			$count = 5;
		}

		if ( ! is_numeric( $distance ) ) {
			$distance = 0;
		}

		if ( 'km' !== $unit ) {
			$unit = 'mi';
		}

		$map_height = self::get( 'map_height' );
		$zoom       = self::get( 'default_zoom' );
		$color      = self::color_for_input( 'marker_color', '#d9480f' );
		$searched   = self::color_for_input( 'searched_marker_color', '#1c7ed6' );
		$tile_url   = self::get( 'tile_url' );
		$credit     = self::get( 'tile_attribution' );
		$tile_default = (string) self::defaults()['tile_url'];

		if ( ! is_numeric( $map_height ) ) {
			$map_height = 450;
		}

		if ( ! is_numeric( $zoom ) ) {
			$zoom = 4;
		}

		if ( ! is_string( $tile_url ) || '' === $tile_url ) {
			$tile_url = $tile_default;
		}

		if ( ! is_string( $credit ) ) {
			$credit = '';
		}

		$labels = array(
			'heading_covered'  => __( 'Heading when dealers serve the zip', 'low-dealer-locator' ),
			'heading_fallback' => __( 'Heading when no dealer lists the zip', 'low-dealer-locator' ),
			'heading_nearest'  => __( 'Heading for nearest dealers', 'low-dealer-locator' ),
			'empty_text'       => __( 'Empty-state message', 'low-dealer-locator' ),
		);
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="low-dl-nearest-count"><?php echo esc_html__( 'Number of nearest dealers', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						class="small-text"
						id="low-dl-nearest-count"
						name="<?php echo esc_attr( $name ); ?>[nearest_count]"
						value="<?php echo esc_attr( (string) (int) $count ); ?>"
						min="1"
						max="50"
						step="1"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="low-dl-max-distance"><?php echo esc_html__( 'Maximum distance', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						class="small-text"
						id="low-dl-max-distance"
						name="<?php echo esc_attr( $name ); ?>[max_distance]"
						value="<?php echo esc_attr( (string) (float) $distance ); ?>"
						min="0"
						max="20000"
						step="any"
					/>
					<p class="description"><?php echo esc_html__( 'Leave 0 for no limit', 'low-dealer-locator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="low-dl-distance-unit"><?php echo esc_html__( 'Distance unit', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<select id="low-dl-distance-unit" name="<?php echo esc_attr( $name ); ?>[distance_unit]">
						<option value="mi" <?php selected( $unit, 'mi' ); ?>><?php echo esc_html__( 'Miles', 'low-dealer-locator' ); ?></option>
						<option value="km" <?php selected( $unit, 'km' ); ?>><?php echo esc_html__( 'Kilometers', 'low-dealer-locator' ); ?></option>
					</select>
				</td>
			</tr>
			<?php foreach ( $labels as $key => $label ) : ?>
				<?php
				$saved = self::get( $key );
				if ( ! is_string( $saved ) ) {
					$saved = '';
				}
				?>
				<tr>
					<th scope="row">
						<label for="low-dl-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>"><?php echo esc_html( $label ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="low-dl-<?php echo esc_attr( str_replace( '_', '-', $key ) ); ?>"
							name="<?php echo esc_attr( $name ); ?>[<?php echo esc_attr( $key ); ?>]"
							value="<?php echo esc_attr( $saved ); ?>"
							maxlength="200"
							placeholder="<?php echo esc_attr( $texts[ $key ] ); ?>"
						/>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<h2><?php echo esc_html__( 'Map appearance', 'low-dealer-locator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="low-dl-map-height"><?php echo esc_html__( 'Map height in pixels', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						class="small-text"
						id="low-dl-map-height"
						name="<?php echo esc_attr( $name ); ?>[map_height]"
						value="<?php echo esc_attr( (string) (int) $map_height ); ?>"
						min="200"
						max="1200"
						step="1"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="low-dl-marker-color"><?php echo esc_html__( 'Marker color', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="color"
						id="low-dl-marker-color"
						name="<?php echo esc_attr( $name ); ?>[marker_color]"
						value="<?php echo esc_attr( $color ); ?>"
					/>
					<p class="description"><?php echo esc_html__( 'Dealer pins.', 'low-dealer-locator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="low-dl-searched-marker-color"><?php echo esc_html__( 'Searched marker color', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="color"
						id="low-dl-searched-marker-color"
						name="<?php echo esc_attr( $name ); ?>[searched_marker_color]"
						value="<?php echo esc_attr( $searched ); ?>"
					/>
					<p class="description"><?php echo esc_html__( 'The zip, address, or location that was searched. Hover or click that marker to see Searched location.', 'low-dealer-locator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="low-dl-default-zoom"><?php echo esc_html__( 'Default zoom', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						class="small-text"
						id="low-dl-default-zoom"
						name="<?php echo esc_attr( $name ); ?>[default_zoom]"
						value="<?php echo esc_attr( (string) (int) $zoom ); ?>"
						min="1"
						max="18"
						step="1"
					/>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="low-dl-tile-url"><?php echo esc_html__( 'Map tile URL', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="url"
						class="large-text code"
						id="low-dl-tile-url"
						name="<?php echo esc_attr( $name ); ?>[tile_url]"
						value="<?php echo esc_attr( $tile_url ); ?>"
					/>
					<p class="description">
						<?php echo esc_html__( 'Uses OpenStreetMap by default. Must include {z}, {x} and {y}. The public OpenStreetMap tile servers are for light use; use a tile provider that allows your traffic for busy sites.', 'low-dealer-locator' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="low-dl-tile-attribution"><?php echo esc_html__( 'Extra map attribution', 'low-dealer-locator' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						class="large-text"
						id="low-dl-tile-attribution"
						name="<?php echo esc_attr( $name ); ?>[tile_attribution]"
						value="<?php echo esc_attr( $credit ); ?>"
						maxlength="200"
					/>
					<p class="description">
						<?php echo esc_html__( 'Optional. Added after \'© OpenStreetMap contributors\', for example to credit another tile provider.', 'low-dealer-locator' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Read-only instructions. This tab does not save settings.
	 *
	 * @return void
	 */
	private function render_instructions() {
		$tabs = array(
			'general'   => $this->get_tab_url( 'general' ),
			'import'    => $this->get_tab_url( 'import' ),
			'mapping'   => $this->get_tab_url( 'mapping' ),
			'geocoding' => $this->get_tab_url( 'geocoding' ),
			'locator'   => $this->get_tab_url( 'locator' ),
		);
		?>
		<div class="low-dl-docs">
			<p><?php echo esc_html__( 'LOW Dealer Locator attaches a service area to dealer records and shows those dealers on a map. A service area can be a radius, US states, zip codes, or any combination. Visitors can search by zip code, street address, or their current location.', 'low-dealer-locator' ); ?></p>
			<ul class="low-dl-docs-contents">
				<li><a href="#low-dl-docs-start"><?php echo esc_html__( 'Start here', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-dealer"><?php echo esc_html__( 'Add a dealer', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-import"><?php echo esc_html__( 'Import zip codes', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-mapping"><?php echo esc_html__( 'Read fields you already store', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-geo"><?php echo esc_html__( 'Look up coordinates', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-locator"><?php echo esc_html__( 'Set how the locator behaves', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-place"><?php echo esc_html__( 'Place the locator on a page', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-visitor"><?php echo esc_html__( 'What a visitor sees', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-json"><?php echo esc_html__( 'Dealer list address', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-update"><?php echo esc_html__( 'Install an update', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-remove"><?php echo esc_html__( 'Remove the plugin', 'low-dealer-locator' ); ?></a></li>
				<li><a href="#low-dl-docs-privacy"><?php echo esc_html__( 'Map and address privacy', 'low-dealer-locator' ); ?></a></li>
			</ul>

			<h2 id="low-dl-docs-start"><?php echo esc_html__( 'Start here', 'low-dealer-locator' ); ?></h2>
			<ol>
				<li>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL of the General settings tab. */
							__( 'Open the <a href="%s">General</a> tab.', 'low-dealer-locator' ),
							esc_url( $tabs['general'] )
						),
						array(
							'a' => array(
								'href' => array(),
							),
						)
					);
					?>
				</li>
				<li><?php echo esc_html__( 'Check the post types that already hold dealers, or check “Create a Dealer Locator post type”. That adds a Dealers menu for editing. Those screens do not create public dealer pages.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Save. Only published dealers from the selected post types appear in the locator. Password-protected dealers are left out.', 'low-dealer-locator' ); ?></li>
			</ol>

			<h2 id="low-dl-docs-dealer"><?php echo esc_html__( 'Add a dealer', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'Open the dealer and set the title. The title is the name visitors see.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'In Service area, use any combination of a radius, checked states, and zip codes. A visitor matches when their search point is inside the radius, their zip is in a checked state, or their zip is listed. The radius uses the distance unit from the Locator tab and needs map coordinates on the dealer. Leave the radius blank for none. Zip codes can be separated by commas, spaces, or new lines. A ZIP+4 such as 30301-1234 is stored as 30301. A 3-digit or 4-digit number is padded with leading zeros. Duplicates are removed. A value that is not a zip is skipped, and a notice lists the skipped values after you save.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'In Dealer Details, fill in email, website, phone, street, city, state, ZIP code, latitude, and longitude. A field appears there only when Field Mapping leaves it set to Plugin field. The plugin fills latitude and longitude from the address when you save, unless you check “Don\'t auto-geocode this dealer (use the coordinates entered here)”.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-import"><?php echo esc_html__( 'Import zip codes', 'low-dealer-locator' ); ?></h2>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: URL of the Import settings tab. */
						__( 'On the <a href="%s">Import</a> tab, upload a .csv file of 2 MB or less, with at most 5,000 data rows.', 'low-dealer-locator' ),
						esc_url( $tabs['import'] )
					),
					array(
						'a' => array(
							'href' => array(),
						),
					)
				);
				?>
			</p>
			<p><?php echo esc_html__( 'The first row must name the columns dealer and zip. The dealer column is a post ID or an exact title. Put one zip in each row, or several zips in one cell separated by commas, spaces, or semicolons.', 'low-dealer-locator' ); ?></p>
			<pre><?php echo esc_html( "dealer,zip\n42,30301\nAcme Propane,\"30302, 30303\"" ); ?></pre>
			<p><?php echo esc_html__( 'Add to existing zips keeps the zips already stored and adds the ones in the file.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'Replace existing zips for dealers in this file overwrites the stored zips of dealers who appear in the file. Check “I understand this overwrites those dealers\' current zips” before importing. Dealers who are not in the file stay as they are.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-mapping"><?php echo esc_html__( 'Read fields you already store', 'low-dealer-locator' ); ?></h2>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: URL of the Field Mapping settings tab. */
						__( 'Use the <a href="%s">Field Mapping</a> tab when contact details, the address, or coordinates already live in custom fields, ACF fields, or the post title, excerpt, or content.', 'low-dealer-locator' ),
						esc_url( $tabs['mapping'] )
					),
					array(
						'a' => array(
							'href' => array(),
						),
					)
				);
				?>
			</p>
			<p><?php echo esc_html__( 'For each dealer post type, set Email, Website, Phone, Street, City, State, ZIP code, Latitude, and Longitude. The dealer name is always the post title.', 'low-dealer-locator' ); ?></p>
			<ul>
				<li><?php echo esc_html__( 'Plugin field stores the value in Dealer Details.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Custom field (post meta) reads a meta key you name.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'ACF field reads an ACF field name. ACF must be active.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Core field reads the title, excerpt, or content.', 'low-dealer-locator' ); ?></li>
			</ul>
			<p><?php echo esc_html__( 'Mapping reads those other sources. Dealer Details and the geocoder write the plugin\'s own fields. A custom field or ACF field left blank is read from the plugin field instead. Select at least one dealer post type on the General tab before this tab has anything to map.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-geo"><?php echo esc_html__( 'Look up coordinates', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'Distance search needs a numeric latitude and longitude. Dealers without coordinates can still match a zip search. They are left out of distance results.', 'low-dealer-locator' ); ?></p>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: URL of the Geocoding settings tab. */
						__( 'On the <a href="%s">Geocoding</a> tab, the default service is the public Nominatim service. It is for light use. Enter a contact email so requests identify this site. Leave the email blank to use the site admin email. Country codes default to us. Leave them blank to search worldwide.', 'low-dealer-locator' ),
						esc_url( $tabs['geocoding'] )
					),
					array(
						'a' => array(
							'href' => array(),
						),
					)
				);
				?>
			</p>
			<p><?php echo esc_html__( 'A paid or self-hosted service that accepts the same search URL can be entered in Geocoder URL. The public Nominatim service does not need an API key. Leave API key blank. A later blank key keeps a key that is already saved. Check “Remove the saved key” to clear it.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'Saving a dealer queues a lookup from the street, city, state, and ZIP code. The queue runs on WP-Cron. If WP-Cron is turned off, a server cron job must request wp-cron.php. On the Dealer Locator settings screen and on dealer list screens, administrators see a notice naming published dealers that still have no coordinates. The notice has a close button. It stays closed until that list of dealers changes.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-locator"><?php echo esc_html__( 'Set how the locator behaves', 'low-dealer-locator' ); ?></h2>
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: URL of the Locator settings tab. */
						__( 'On the <a href="%s">Locator</a> tab:', 'low-dealer-locator' ),
						esc_url( $tabs['locator'] )
					),
					array(
						'a' => array(
							'href' => array(),
						),
					)
				);
				?>
			</p>
			<ul>
				<li><?php echo esc_html__( 'Number of nearest dealers, from 1 to 50. The default is 5.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Maximum distance. 0 means no limit.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Distance unit: miles or kilometers.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Headings and the empty-state message. A blank field uses the placeholder text.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Map height in pixels, from 200 to 1200. The default is 450.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Marker color for dealer pins.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Searched marker color. The default is blue. Hover or click that marker to see Searched location.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Default zoom, from 1 to 18. The default is 4.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Map tile URL. The default is OpenStreetMap and must include {z}, {x}, and {y}. Public OpenStreetMap tiles are for light use.', 'low-dealer-locator' ); ?></li>
				<li><?php echo esc_html__( 'Extra map attribution is added after © OpenStreetMap contributors.', 'low-dealer-locator' ); ?></li>
			</ul>

			<h2 id="low-dl-docs-place"><?php echo esc_html__( 'Place the locator on a page', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'Any of these shows the same locator.', 'low-dealer-locator' ); ?></p>
			<ul>
				<li><code>[low_dealer_locator]</code></li>
				<li><code>[low_dealer_locator height="600" zoom="10"]</code></li>
			</ul>
			<p><?php echo esc_html__( 'Height is 200 to 1200 pixels. Zoom is 1 to 18. A missing or out-of-range value uses the Locator tab. The Dealer Locator block and the Dealer Locator widget accept the same height and zoom. Leave them blank to use the Locator tab.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-visitor"><?php echo esc_html__( 'What a visitor sees', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'The visitor can search by a 5-digit zip, a street address, or Use my location. Use my location is the button on the left of the search field. A search marks that place and shows a pin for each matching dealer. Hover or click the searched marker to see Searched location.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'A dealer who lists the zip, checks that state, or covers the search point with a radius is shown first, under the heading for dealers who serve the area. If none do, the locator shows the nearest dealers that have coordinates. A state matches a zip search, and an address search when the lookup includes a state. Use my location matches a radius, and it does not match a state.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'An address search and Use my location go straight to the nearest dealers. Use my location is available on an https page when the browser allows location. Those coordinates are sent only to this site and are not stored.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'Clicking a dealer pin opens a popup with the dealer\'s name, address, phone, email, and website. After a search, the popup also shows the distance. A phone, email, or website line appears only when that dealer has one.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'An empty search or a value that is not a 5-digit zip shows a message on the page and does not look anything up. If nothing is within the maximum distance, the empty-state message is shown. The map does not zoom with the mouse wheel until the visitor clicks or focuses the map.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-json"><?php echo esc_html__( 'Dealer list address', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'This address lists published dealers that have a service location: coordinates, an address ZIP, zip codes, a radius, or at least one state. A dealer with only a name is left out. Drafts and password-protected dealers are left out too.', 'low-dealer-locator' ); ?></p>
			<p>
				<a href="<?php echo esc_url( 'https://alliance360.southeastpropane.org/wp-json/low-dealer-locator/v1/dealers' ); ?>">
					<?php echo esc_html( 'https://alliance360.southeastpropane.org/wp-json/low-dealer-locator/v1/dealers' ); ?>
				</a>
			</p>

			<h2 id="low-dl-docs-update"><?php echo esc_html__( 'Install an update', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'On the Plugins screen, choose Check for updates. WordPress installs a GitHub release when its version is newer than the copy on this site. Install from that screen. Uploading the GitHub zip through Plugins, Add New creates a second plugin folder instead of replacing this one.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-remove"><?php echo esc_html__( 'Remove the plugin', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'On the General tab, leave “Delete all plugin data when the plugin is deleted” unchecked. Deleting the plugin then leaves the zip tables, settings, and dealer fields in place. Checking it removes that plugin data when the plugin is deleted. Dealer posts are kept either way.', 'low-dealer-locator' ); ?></p>

			<h2 id="low-dl-docs-privacy"><?php echo esc_html__( 'Map and address privacy', 'low-dealer-locator' ); ?></h2>
			<p><?php echo esc_html__( 'Each visitor\'s browser requests map tiles from the tile server on the Locator tab. The default server is tile.openstreetmap.org. The visitor\'s IP address and the map area are sent there.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'Dealer addresses are sent to the geocoder when a dealer is saved. A visitor\'s typed address, and a zip that is not in the bundled US zip table, are sent from this server during a search. The default geocoder is nominatim.openstreetmap.org. Each request includes the site name, the site URL, and the contact email from the Geocoding tab.', 'low-dealer-locator' ); ?></p>
			<p><?php echo esc_html__( 'The plugin does not set cookies. The public OpenStreetMap tile and Nominatim services are for light use. If you change the tile URL or geocoder URL, follow that provider\'s terms.', 'low-dealer-locator' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$url = admin_url( 'options-general.php?page=' . self::MENU_SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'low-dealer-locator' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Assets for this settings screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'low-dl-admin',
			LOW_DL_URL . 'assets/css/admin.css',
			array(),
			LOW_DL_VERSION
		);

		wp_enqueue_script(
			'low-dl-admin',
			LOW_DL_URL . 'assets/js/admin.js',
			array(),
			LOW_DL_VERSION,
			true
		);
	}
}
