<?php
/**
 * Dealer field mapping and value resolution.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps dealer data points to post fields and reads them back.
 */
class LOW_DL_Field_Mapper {

	/**
	 * Nonce action for the dealer details meta box.
	 */
	const DETAILS_NONCE_ACTION = 'low_dl_save_details';

	/**
	 * Nonce field name for the dealer details meta box.
	 */
	const DETAILS_NONCE_NAME = 'low_dl_details_nonce';

	/**
	 * Manual geocode flag. Stored only when latitude or longitude is a plugin field.
	 */
	const GEO_MANUAL_KEY = '_low_dl_geo_manual';

	/**
	 * Register the dealer details meta box and its save handler.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_details' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'render_invalid_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Allowed mapping sources.
	 *
	 * @return array<string, string> Source slug => label.
	 */
	public static function sources() {
		return array(
			'own'  => __( 'Plugin field', 'low-dealer-locator' ),
			'meta' => __( 'Custom field (post meta)', 'low-dealer-locator' ),
			'acf'  => __( 'ACF field', 'low-dealer-locator' ),
			'core' => __( 'Core field', 'low-dealer-locator' ),
		);
	}

	/**
	 * Mappable data points. Dealer name is the post title and is not included.
	 *
	 * @return array<string, array{label: string, type: string}>
	 */
	public static function data_points() {
		return array(
			'email'   => array(
				'label' => __( 'Email', 'low-dealer-locator' ),
				'type'  => 'email',
			),
			'website' => array(
				'label' => __( 'Website', 'low-dealer-locator' ),
				'type'  => 'url',
			),
			'phone'   => array(
				'label' => __( 'Phone', 'low-dealer-locator' ),
				'type'  => 'text',
			),
			'street'  => array(
				'label' => __( 'Street', 'low-dealer-locator' ),
				'type'  => 'text',
			),
			'city'    => array(
				'label' => __( 'City', 'low-dealer-locator' ),
				'type'  => 'text',
			),
			'state'   => array(
				'label' => __( 'State', 'low-dealer-locator' ),
				'type'  => 'text',
			),
			'zip'     => array(
				'label' => __( 'ZIP code', 'low-dealer-locator' ),
				'type'  => 'text',
			),
			'lat'     => array(
				'label' => __( 'Latitude', 'low-dealer-locator' ),
				'type'  => 'lat',
			),
			'lng'     => array(
				'label' => __( 'Longitude', 'low-dealer-locator' ),
				'type'  => 'lng',
			),
		);
	}

	/**
	 * Plugin meta key for a data point.
	 *
	 * @param string $data_point Data point slug.
	 * @return string
	 */
	public static function own_key( $data_point ) {
		$keys = array(
			'email'   => '_low_dl_email',
			'website' => '_low_dl_website',
			'phone'   => '_low_dl_phone',
			'street'  => '_low_dl_street',
			'city'    => '_low_dl_city',
			'state'   => '_low_dl_state',
			'zip'     => '_low_dl_postal_code',
			'lat'     => '_low_dl_lat',
			'lng'     => '_low_dl_lng',
		);

		return isset( $keys[ $data_point ] ) ? $keys[ $data_point ] : '';
	}

	/**
	 * Saved mapping, or source own when nothing is stored.
	 *
	 * @param string $post_type  Post type slug.
	 * @param string $data_point Data point slug.
	 * @return array{source: string, key: string}
	 */
	public static function get_mapping( $post_type, $data_point ) {
		$mapping = array(
			'source' => 'own',
			'key'    => '',
		);

		$settings = LOW_DL_Settings::get_all();
		$stored   = array();

		if ( isset( $settings['field_mappings'][ $post_type ][ $data_point ] ) && is_array( $settings['field_mappings'][ $post_type ][ $data_point ] ) ) {
			$stored = $settings['field_mappings'][ $post_type ][ $data_point ];
		}

		if ( isset( $stored['source'] ) && is_string( $stored['source'] ) && array_key_exists( $stored['source'], self::sources() ) ) {
			$mapping['source'] = $stored['source'];
		}

		if ( isset( $stored['key'] ) && is_string( $stored['key'] ) ) {
			$mapping['key'] = $stored['key'];
		}

		return $mapping;
	}

	/**
	 * Whether this data point uses the plugin's own meta field.
	 *
	 * @param string $post_type  Post type slug.
	 * @param string $data_point Data point slug.
	 * @return bool
	 */
	public static function is_own( $post_type, $data_point ) {
		$mapping = self::get_mapping( $post_type, $data_point );

		return 'own' === $mapping['source'];
	}

	/**
	 * Cleaned value for one data point on a post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $data_point Data point slug.
	 * @return string|float|null
	 */
	public static function get_value( $post_id, $data_point ) {
		$points = self::data_points();

		if ( ! isset( $points[ $data_point ] ) ) {
			return null;
		}

		$post = get_post( (int) $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return self::empty_value( $points[ $data_point ]['type'] );
		}

		$mapping = self::get_mapping( $post->post_type, $data_point );
		$raw     = self::resolve_raw( $post, $data_point, $mapping );

		if ( is_array( $raw ) || is_object( $raw ) ) {
			$raw = '';
		}

		return self::clean_value( $raw, $points[ $data_point ]['type'] );
	}

	/**
	 * One dealer in the locator JSON shape, without zip codes.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_dealer_data( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! ( $post instanceof WP_Post ) ) {
			return null;
		}

		return array(
			'id'      => (int) $post->ID,
			'name'    => wp_strip_all_tags( get_the_title( $post ) ),
			'email'   => self::get_value( $post->ID, 'email' ),
			'website' => self::get_value( $post->ID, 'website' ),
			'phone'   => self::get_value( $post->ID, 'phone' ),
			'address' => array(
				'street' => self::get_value( $post->ID, 'street' ),
				'city'   => self::get_value( $post->ID, 'city' ),
				'state'  => self::get_value( $post->ID, 'state' ),
				'zip'    => self::get_value( $post->ID, 'zip' ),
			),
			'lat'     => self::get_value( $post->ID, 'lat' ),
			'lng'     => self::get_value( $post->ID, 'lng' ),
		);
	}

	/**
	 * Keep only valid mappings for active dealer post types.
	 *
	 * An empty meta or ACF key falls back to the plugin field. A core key that
	 * is not a post field does the same.
	 *
	 * @param mixed $input Posted field_mappings.
	 * @return array
	 */
	public static function sanitize_mappings( $input ) {
		$clean = array();

		if ( ! is_array( $input ) ) {
			return $clean;
		}

		$sources   = array_keys( self::sources() );
		$core_keys = array_keys( self::core_fields() );

		foreach ( LOW_DL_Settings::get_dealer_post_types() as $post_type ) {
			$rows = array();

			if ( isset( $input[ $post_type ] ) && is_array( $input[ $post_type ] ) ) {
				$rows = $input[ $post_type ];
			}

			foreach ( self::data_points() as $data_point => $config ) {
				unset( $config );

				$row    = ( isset( $rows[ $data_point ] ) && is_array( $rows[ $data_point ] ) ) ? $rows[ $data_point ] : array();
				$source = 'own';

				if ( isset( $row['source'] ) && is_scalar( $row['source'] ) ) {
					$source = sanitize_key( wp_unslash( (string) $row['source'] ) );
				}

				if ( ! in_array( $source, $sources, true ) ) {
					$source = 'own';
				}

				$key = '';

				if ( isset( $row['key'] ) && is_scalar( $row['key'] ) ) {
					$key = self::limit_key( sanitize_text_field( wp_unslash( (string) $row['key'] ) ) );
				}

				if ( ( 'meta' === $source || 'acf' === $source ) && '' === $key ) {
					$source = 'own';
				}

				if ( 'core' === $source && ! in_array( $key, $core_keys, true ) ) {
					$source = 'own';
				}

				if ( 'own' === $source ) {
					$key = '';
				}

				$clean[ $post_type ][ $data_point ] = array(
					'source' => $source,
					'key'    => $key,
				);
			}
		}

		return $clean;
	}

	/**
	 * Field Mapping tab contents.
	 *
	 * The settings page owns the form and the manage_options check.
	 *
	 * @return void
	 */
	public static function render_fields() {
		$post_types = LOW_DL_Settings::get_dealer_post_types();

		if ( empty( $post_types ) ) {
			$url = add_query_arg(
				array(
					'page' => LOW_DL_Settings::MENU_SLUG,
					'tab'  => 'general',
				),
				admin_url( 'options-general.php' )
			);

			echo '<div class="notice notice-info"><p>';
			echo esc_html__( 'Select at least one dealer post type on the', 'low-dealer-locator' );
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'General', 'low-dealer-locator' ) . '</a> ';
			echo esc_html__( 'tab before mapping fields.', 'low-dealer-locator' );
			echo '</p></div>';
			return;
		}

		echo '<div class="low-dl-mapping">';

		foreach ( $post_types as $post_type ) {
			self::render_post_type_section( $post_type );
		}

		echo '</div>';
	}

	/**
	 * Mapping table for one post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return void
	 */
	private static function render_post_type_section( $post_type ) {
		$object = get_post_type_object( $post_type );
		$label  = ( $object && isset( $object->labels->name ) ) ? $object->labels->name : $post_type;
		$meta   = self::meta_key_suggestions( $post_type );
		$acf    = self::acf_key_suggestions( $post_type );

		echo '<h2>' . esc_html( $label ) . '</h2>';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Data point', 'low-dealer-locator' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Source', 'low-dealer-locator' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Field', 'low-dealer-locator' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( self::data_points() as $data_point => $config ) {
			self::render_mapping_row( $post_type, $data_point, $config['label'] );
		}

		echo '</tbody></table>';

		self::render_datalist( 'low-dl-meta-' . $post_type, $meta );
		self::render_datalist( 'low-dl-acf-' . $post_type, $acf );
	}

	/**
	 * One data-point row.
	 *
	 * @param string $post_type  Post type slug.
	 * @param string $data_point Data point slug.
	 * @param string $label      Data point label.
	 * @return void
	 */
	private static function render_mapping_row( $post_type, $data_point, $label ) {
		$mapping   = self::get_mapping( $post_type, $data_point );
		$source    = $mapping['source'];
		$key       = $mapping['key'];
		$select_id = 'low-dl-source-' . $post_type . '-' . $data_point;
		$name      = LOW_DL_Settings::OPTION_KEY . '[field_mappings][' . $post_type . '][' . $data_point . ']';
		$key_name  = $name . '[key]';

		echo '<tr class="low-dl-mapping-row">';
		echo '<th scope="row"><label for="' . esc_attr( $select_id ) . '">' . esc_html( $label ) . '</label></th>';
		echo '<td><select class="low-dl-mapping-source" id="' . esc_attr( $select_id ) . '" name="' . esc_attr( $name . '[source]' ) . '">';

		foreach ( self::sources() as $source_key => $source_label ) {
			echo '<option value="' . esc_attr( $source_key ) . '" ' . selected( $source, $source_key, false ) . '>' . esc_html( $source_label ) . '</option>';
		}

		echo '</select></td>';
		echo '<td class="low-dl-mapping-key">';

		self::render_own_control( 'own' === $source, $data_point );
		self::render_text_control( 'meta', 'meta' === $source, $key_name, ( 'meta' === $source ) ? $key : '', 'low-dl-meta-' . $post_type );
		self::render_text_control( 'acf', 'acf' === $source, $key_name, ( 'acf' === $source ) ? $key : '', 'low-dl-acf-' . $post_type );
		self::render_core_control( 'core' === $source, $key_name, $key );

		echo '</td></tr>';
	}

	/**
	 * Read-only plugin meta key.
	 *
	 * @param bool   $active     Whether this control is the saved source.
	 * @param string $data_point Data point slug.
	 * @return void
	 */
	private static function render_own_control( $active, $data_point ) {
		echo '<span class="low-dl-mapping-control" data-source="own"' . ( $active ? '' : ' hidden="hidden"' ) . '>';
		echo '<code>' . esc_html( self::own_key( $data_point ) ) . '</code>';
		echo '</span>';
	}

	/**
	 * Text key input with an optional suggestion list.
	 *
	 * @param string $source   meta or acf.
	 * @param bool   $active   Whether this control is submitted.
	 * @param string $name     Field name.
	 * @param string $value    Saved key.
	 * @param string $list_id  Datalist id.
	 * @return void
	 */
	private static function render_text_control( $source, $active, $name, $value, $list_id ) {
		echo '<span class="low-dl-mapping-control" data-source="' . esc_attr( $source ) . '"' . ( $active ? '' : ' hidden="hidden"' ) . '>';
		echo '<input type="text" class="regular-text" value="' . esc_attr( $value ) . '" list="' . esc_attr( $list_id ) . '" data-name="' . esc_attr( $name ) . '"';

		if ( $active ) {
			echo ' name="' . esc_attr( $name ) . '"';
		} else {
			echo ' disabled="disabled"';
		}

		echo ' />';
		echo '</span>';
	}

	/**
	 * Core post-field select.
	 *
	 * @param bool   $active Whether this control is submitted.
	 * @param string $name   Field name.
	 * @param string $value  Saved key.
	 * @return void
	 */
	private static function render_core_control( $active, $name, $value ) {
		echo '<span class="low-dl-mapping-control" data-source="core"' . ( $active ? '' : ' hidden="hidden"' ) . '>';
		echo '<select data-name="' . esc_attr( $name ) . '"';

		if ( $active ) {
			echo ' name="' . esc_attr( $name ) . '"';
		} else {
			echo ' disabled="disabled"';
		}

		echo '>';

		foreach ( self::core_fields() as $field_key => $field_label ) {
			echo '<option value="' . esc_attr( $field_key ) . '" ' . selected( $value, $field_key, false ) . '>' . esc_html( $field_label ) . '</option>';
		}

		echo '</select></span>';
	}

	/**
	 * Suggestion list for text key inputs.
	 *
	 * @param string   $id   Element id.
	 * @param string[] $keys Suggested keys.
	 * @return void
	 */
	private static function render_datalist( $id, array $keys ) {
		echo '<datalist id="' . esc_attr( $id ) . '">';

		foreach ( $keys as $key ) {
			echo '<option value="' . esc_attr( $key ) . '"></option>';
		}

		echo '</datalist>';
	}

	/**
	 * Core fields a mapping may read.
	 *
	 * @return array<string, string>
	 */
	private static function core_fields() {
		return array(
			'post_title'   => __( 'Title', 'low-dealer-locator' ),
			'post_excerpt' => __( 'Excerpt', 'low-dealer-locator' ),
			'post_content' => __( 'Content', 'low-dealer-locator' ),
		);
	}

	/**
	 * Raw stored value before type cleaning.
	 *
	 * @param WP_Post $post       Post object.
	 * @param string  $data_point Data point slug.
	 * @param array   $mapping    Source and key.
	 * @return mixed
	 */
	private static function resolve_raw( $post, $data_point, array $mapping ) {
		$source = isset( $mapping['source'] ) ? $mapping['source'] : 'own';
		$key    = isset( $mapping['key'] ) ? $mapping['key'] : '';

		if ( 'meta' === $source && '' !== $key ) {
			return get_post_meta( $post->ID, $key, true );
		}

		if ( 'acf' === $source && '' !== $key ) {
			if ( function_exists( 'get_field' ) ) {
				return get_field( $key, $post->ID, false );
			}

			return get_post_meta( $post->ID, $key, true );
		}

		if ( 'core' === $source && array_key_exists( $key, self::core_fields() ) ) {
			$value = isset( $post->$key ) ? $post->$key : '';

			return trim( wp_strip_all_tags( (string) $value ) );
		}

		return get_post_meta( $post->ID, self::own_key( $data_point ), true );
	}

	/**
	 * Apply the data-point type rules.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $type  email, url, text, lat, or lng.
	 * @return string|float|null
	 */
	public static function clean_value( $value, $type ) {
		$raw = $value;
		if ( is_array( $raw ) || is_object( $raw ) ) {
			$raw = '';
		}

		if ( 'lat' === $type || 'lng' === $type ) {
			if ( null === $raw || false === $raw ) {
				return null;
			}

			if ( is_string( $raw ) ) {
				$raw = trim( $raw );
			}

			if ( '' === $raw || ! is_numeric( $raw ) ) {
				return null;
			}

			$number = (float) $raw;
			$max    = ( 'lat' === $type ) ? 90 : 180;

			if ( $number < ( 0 - $max ) || $number > $max ) {
				return null;
			}

			return $number;
		}

		if ( is_bool( $raw ) || null === $raw ) {
			$raw = '';
		}

		$raw = is_scalar( $raw ) ? (string) $raw : '';

		if ( 'email' === $type ) {
			$email = sanitize_email( $raw );

			return is_email( $email ) ? $email : '';
		}

		if ( 'url' === $type ) {
			$url = esc_url_raw( $raw, array( 'http', 'https' ) );

			if ( '' === $url ) {
				return '';
			}

			$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return '';
			}

			return $url;
		}

		return sanitize_text_field( $raw );
	}

	/**
	 * Empty cleaned value for a type.
	 *
	 * @param string $type Value type.
	 * @return string|null
	 */
	private static function empty_value( $type ) {
		if ( 'lat' === $type || 'lng' === $type ) {
			return null;
		}

		return '';
	}

	/**
	 * Cap a field key at 191 characters.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private static function limit_key( $key ) {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $key ) > 191 ) {
			return mb_substr( $key, 0, 191 );
		}

		if ( strlen( $key ) > 191 ) {
			return substr( $key, 0, 191 );
		}

		return $key;
	}

	/**
	 * Distinct public meta keys used by a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[]
	 */
	private static function meta_key_suggestions( $post_type ) {
		static $cache = array();

		if ( isset( $cache[ $post_type ] ) ) {
			return $cache[ $post_type ];
		}

		global $wpdb;

		$sql  = "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} AS pm INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key <> %s ORDER BY pm.meta_key ASC LIMIT 300";
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				$sql,
				$post_type,
				$wpdb->esc_like( '_low_dl_' ) . '%',
				$wpdb->esc_like( '_edit_' ) . '%',
				$wpdb->esc_like( '_wp_' ) . '%',
				'_thumbnail_id'
			)
		);

		$keys = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $meta_key ) {
				if ( ! is_string( $meta_key ) || '' === $meta_key ) {
					continue;
				}

				if ( 0 === strpos( $meta_key, '_low_dl_' ) || 0 === strpos( $meta_key, '_edit_' ) || 0 === strpos( $meta_key, '_wp_' ) || '_thumbnail_id' === $meta_key ) {
					continue;
				}

				$keys[] = $meta_key;
			}
		}

		$cache[ $post_type ] = $keys;

		return $keys;
	}

	/**
	 * ACF field names assigned to a post type, when ACF is available.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[]
	 */
	private static function acf_key_suggestions( $post_type ) {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = acf_get_field_groups(
			array(
				'post_type' => $post_type,
			)
		);

		if ( ! is_array( $groups ) ) {
			return array();
		}

		$names = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$fields = acf_get_fields( $group );

			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( is_array( $field ) && isset( $field['name'] ) && is_string( $field['name'] ) && '' !== $field['name'] ) {
					$names[] = $field['name'];
				}
			}
		}

		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Dealer Details meta box on each active dealer post type.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		foreach ( LOW_DL_Settings::get_dealer_post_types() as $post_type ) {
			add_meta_box(
				'low_dl_dealer_details',
				__( 'Dealer Details', 'low-dealer-locator' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Dealer Details fields that use the plugin's own meta keys.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$groups  = array(
			array( 'email', 'website', 'phone' ),
			array( 'street', 'city', 'state', 'zip' ),
			array( 'lat', 'lng' ),
		);
		$points  = self::data_points();
		$visible = array();

		foreach ( $points as $data_point => $config ) {
			if ( self::is_own( $post->post_type, $data_point ) ) {
				$visible[ $data_point ] = $config;
			}
		}

		wp_nonce_field( self::DETAILS_NONCE_ACTION, self::DETAILS_NONCE_NAME );

		echo '<div class="low-dl-details">';

		if ( empty( $visible ) ) {
			$url = add_query_arg(
				array(
					'page' => LOW_DL_Settings::MENU_SLUG,
					'tab'  => 'mapping',
				),
				admin_url( 'options-general.php' )
			);

			echo '<p>';
			echo esc_html__( 'All dealer fields are mapped to other sources. Change this on the', 'low-dealer-locator' );
			echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Field Mapping', 'low-dealer-locator' ) . '</a> ';
			echo esc_html__( 'settings tab.', 'low-dealer-locator' );
			echo '</p></div>';
			return;
		}

		foreach ( $groups as $group ) {
			$fields = array();

			foreach ( $group as $data_point ) {
				if ( isset( $visible[ $data_point ] ) ) {
					$fields[ $data_point ] = $visible[ $data_point ];
				}
			}

			if ( empty( $fields ) ) {
				continue;
			}

			echo '<div class="low-dl-details-fields">';

			foreach ( $fields as $data_point => $config ) {
				self::render_detail_field( $post->ID, $data_point, $config['label'] );
			}

			echo '</div>';
		}

		if ( isset( $visible['lat'] ) || isset( $visible['lng'] ) ) {
			$manual    = get_post_meta( $post->ID, self::GEO_MANUAL_KEY, true );
			$is_manual = is_scalar( $manual ) && '1' === (string) $manual;

			echo '<input type="hidden" name="low_dl_details_geo_shown" value="1" />';
			echo '<p class="low-dl-details-geo">';
			echo '<label for="low-dl-geo-manual">';
			echo '<input type="checkbox" id="low-dl-geo-manual" name="low_dl_geo_manual" value="1" ' . checked( $is_manual, true, false ) . ' /> ';
			echo esc_html__( 'Don\'t auto-geocode this dealer (use the coordinates entered here)', 'low-dealer-locator' );
			echo '</label>';
			echo '</p>';
			echo '<p class="description">' . esc_html__( 'Coordinates are looked up automatically from the address when the dealer is saved, unless this is checked.', 'low-dealer-locator' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * One dealer detail input.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $data_point Data point slug.
	 * @param string $label      Field label.
	 * @return void
	 */
	private static function render_detail_field( $post_id, $data_point, $label ) {
		$stored = get_post_meta( $post_id, self::own_key( $data_point ), true );

		if ( ! is_scalar( $stored ) ) {
			$stored = '';
		}

		$input_id   = 'low-dl-detail-' . $data_point;
		$input_type = 'text';
		$extra      = '';

		if ( 'email' === $data_point ) {
			$input_type = 'email';
		} elseif ( 'website' === $data_point ) {
			$input_type = 'url';
		} elseif ( 'phone' === $data_point ) {
			$input_type = 'tel';
		} elseif ( 'lat' === $data_point || 'lng' === $data_point ) {
			$extra = ' inputmode="decimal"';
		}

		echo '<p class="low-dl-details-field">';
		echo '<label for="' . esc_attr( $input_id ) . '">' . esc_html( $label ) . '</label>';
		echo '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( 'low_dl_details[' . $data_point . ']' ) . '" value="' . esc_attr( (string) $stored ) . '"' . $extra . ' />';
		echo '</p>';
	}

	/**
	 * Save plugin-owned dealer details.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Saved post.
	 * @return void
	 */
	public function save_details( $post_id, $post = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || ! ( $post instanceof WP_Post ) || 'revision' === $post->post_type ) {
			return;
		}

		if ( ! in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::DETAILS_NONCE_NAME ] ) ) {
			return;
		}

		$nonce = wp_unslash( $_POST[ self::DETAILS_NONCE_NAME ] );

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::DETAILS_NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$details = array();

		if ( isset( $_POST['low_dl_details'] ) && is_array( $_POST['low_dl_details'] ) ) {
			$details = wp_unslash( $_POST['low_dl_details'] );
		}

		$invalid = array();

		foreach ( self::data_points() as $data_point => $config ) {
			if ( ! self::is_own( $post->post_type, $data_point ) || ! array_key_exists( $data_point, $details ) ) {
				continue;
			}

			$submitted = $details[ $data_point ];

			if ( ! is_scalar( $submitted ) ) {
				continue;
			}

			$submitted = (string) $submitted;
			$meta_key  = self::own_key( $data_point );
			$cleaned   = self::clean_value( $submitted, $config['type'] );

			if ( '' === trim( $submitted ) ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			if ( self::cleaned_value_rejected( $config['type'], $cleaned ) ) {
				$invalid[] = $config['label'];
				continue;
			}

			if ( 'lat' === $config['type'] || 'lng' === $config['type'] ) {
				update_post_meta( $post_id, $meta_key, self::format_coordinate( $cleaned ) );
				continue;
			}

			update_post_meta( $post_id, $meta_key, $cleaned );
		}

		$geo_shown = isset( $_POST['low_dl_details_geo_shown'] ) && is_scalar( $_POST['low_dl_details_geo_shown'] ) && '1' === (string) wp_unslash( $_POST['low_dl_details_geo_shown'] );

		if ( $geo_shown && ( self::is_own( $post->post_type, 'lat' ) || self::is_own( $post->post_type, 'lng' ) ) ) {
			$manual = isset( $_POST['low_dl_geo_manual'] ) ? wp_unslash( $_POST['low_dl_geo_manual'] ) : '';

			if ( is_scalar( $manual ) && '1' === (string) $manual ) {
				update_post_meta( $post_id, self::GEO_MANUAL_KEY, '1' );
			} else {
				delete_post_meta( $post_id, self::GEO_MANUAL_KEY );
			}
		}

		if ( ! empty( $invalid ) ) {
			set_transient( $this->details_notice_key( $post_id ), $invalid, 60 );
		} else {
			delete_transient( $this->details_notice_key( $post_id ) );
		}

		/**
		 * Fires after dealer detail fields are saved.
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'low_dl_details_saved', $post_id );
	}

	/**
	 * One-time warning for detail fields that were left unchanged.
	 *
	 * @return void
	 */
	public function render_invalid_notice() {
		if ( ! isset( $_GET['post'] ) || ! is_scalar( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen context for a per-user transient.
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post'] ) );

		if ( ! $post_id ) {
			return;
		}

		$invalid = get_transient( $this->details_notice_key( $post_id ) );

		if ( ! is_array( $invalid ) || empty( $invalid ) ) {
			return;
		}

		delete_transient( $this->details_notice_key( $post_id ) );

		$labels = array();

		foreach ( $invalid as $label ) {
			if ( is_scalar( $label ) ) {
				$labels[] = (string) $label;
			}
		}

		if ( empty( $labels ) ) {
			return;
		}

		$text = sprintf(
			/* translators: %s: comma-separated field labels. */
			__( 'These fields had invalid values and were not changed: %s', 'low-dealer-locator' ),
			implode( ', ', $labels )
		);

		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Detail styles on dealer edit screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->post_type, LOW_DL_Settings::get_dealer_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'low-dl-admin',
			LOW_DL_URL . 'assets/css/admin.css',
			array(),
			LOW_DL_VERSION
		);
	}

	/**
	 * True when a non-empty submission did not produce a storable value.
	 *
	 * @param string $type    Value type.
	 * @param mixed  $cleaned Result of clean_value().
	 * @return bool
	 */
	private static function cleaned_value_rejected( $type, $cleaned ) {
		if ( 'email' === $type || 'url' === $type ) {
			return '' === $cleaned;
		}

		if ( 'lat' === $type || 'lng' === $type ) {
			return null === $cleaned;
		}

		return false;
	}

	/**
	 * Plain decimal string for a latitude or longitude.
	 *
	 * @param float $number Coordinate.
	 * @return string
	 */
	private static function format_coordinate( $number ) {
		$formatted = rtrim( rtrim( sprintf( '%.8F', (float) $number ), '0' ), '.' );

		if ( '' === $formatted || '-' === $formatted || '-0' === $formatted ) {
			return '0';
		}

		return $formatted;
	}

	/**
	 * Transient key for the invalid-details notice.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function details_notice_key( $post_id ) {
		return 'low_dl_details_invalid_' . get_current_user_id() . '_' . (int) $post_id;
	}
}
