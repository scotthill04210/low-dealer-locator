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
			'general'   => __( 'General', 'low-dealer-locator' ),
			'import'    => __( 'Import', 'low-dealer-locator' ),
			'mapping'   => __( 'Field Mapping', 'low-dealer-locator' ),
			'geocoding' => __( 'Geocoding', 'low-dealer-locator' ),
			'locator'   => __( 'Locator', 'low-dealer-locator' ),
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

		$clean['map_height']       = $height;
		$clean['marker_color']     = $this->sanitize_marker_color( isset( $input['marker_color'] ) ? $input['marker_color'] : '' );
		$clean['default_zoom']     = $zoom;
		$clean['tile_url']         = $this->sanitize_tile_url( isset( $input['tile_url'] ) ? $input['tile_url'] : '' );
		$clean['tile_attribution'] = $this->limit_locator_text( isset( $input['tile_attribution'] ) ? $input['tile_attribution'] : '' );

		return $clean;
	}

	/**
	 * Hex color, or the default marker color.
	 *
	 * @param mixed $value Posted color.
	 * @return string
	 */
	private function sanitize_marker_color( $value ) {
		$default = (string) self::defaults()['marker_color'];

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
		$color      = self::get( 'marker_color' );
		$tile_url   = self::get( 'tile_url' );
		$credit     = self::get( 'tile_attribution' );
		$tile_default = (string) self::defaults()['tile_url'];

		if ( ! is_numeric( $map_height ) ) {
			$map_height = 450;
		}

		if ( ! is_numeric( $zoom ) ) {
			$zoom = 4;
		}

		if ( ! is_string( $color ) || 1 !== preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ) {
			$color = '#d9480f';
		}

		$color = strtolower( $color );

		if ( 4 === strlen( $color ) ) {
			$color = sprintf( '#%1$s%1$s%2$s%2$s%3$s%3$s', $color[1], $color[2], $color[3] );
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
