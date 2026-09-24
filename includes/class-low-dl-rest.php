<?php
/**
 * Public dealers JSON, search, and the transient cache.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves published dealers, the public search, and drops the cache when dealer data changes.
 */
class LOW_DL_REST {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'low-dealer-locator/v1';

	/**
	 * Transient key for the dealer list.
	 */
	const CACHE_KEY = 'low_dl_dealers_json';

	/**
	 * Register the route and the cache-clearing hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'no_store_search_response' ), 10, 3 );
		add_action( 'save_post', array( $this, 'clear_cache_on_save' ), 99, 2 );
		add_action( 'deleted_post', array( $this, 'clear_cache_on_delete' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'clear_cache_on_status' ), 10, 3 );
		add_action( 'low_dl_zips_saved', array( $this, 'clear_cache_on_data_change' ) );
		add_action( 'low_dl_details_saved', array( $this, 'clear_cache_on_data_change' ) );
		add_action( 'update_option_low_dl_settings', array( $this, 'clear_cache_on_data_change' ) );
		add_action( 'add_option_low_dl_settings', array( $this, 'clear_cache_on_data_change' ) );
	}

	/**
	 * Register GET /dealers and GET /search.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/dealers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_dealers_response' ),
				// Public by design: the front-end locator reads dealer data without login. Only published dealers are ever included.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_search_response' ),
				// Public by design: the front-end locator searches without login. Input is validated, remote geocoding is rate limited per IP, and only published dealers are returned.
				'permission_callback' => '__return_true',
				'args'                => array(
					'type' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'zip', 'address', 'coords' ),
					),
					'q'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
					'lat'  => array(
						'type' => 'number',
					),
					'lng'  => array(
						'type' => 'number',
					),
				),
			)
		);
	}

	/**
	 * Search by zip, address, or coordinates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_search_response( $request ) {
		$validated = $this->validate_search_request( $request );

		if ( is_wp_error( $validated ) ) {
			return $this->search_http_response( $validated );
		}

		return $this->search_http_response( self::search( $validated['type'], $validated['value'] ) );
	}

	/**
	 * Cache-Control: no-store on every search response, including argument errors.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public function no_store_search_response( $response, $server, $request ) {
		if ( ! ( $response instanceof WP_REST_Response ) || ! ( $request instanceof WP_REST_Request ) ) {
			return $response;
		}

		if ( '/' . self::NAMESPACE . '/search' !== $request->get_route() ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * JSON response for the dealers collection.
	 *
	 * @return WP_REST_Response
	 */
	public function get_dealers_response() {
		$dealers  = self::get_dealers();
		$response = rest_ensure_response(
			array(
				'dealers' => $dealers,
			)
		);

		$max_age = apply_filters( 'low_dl_response_max_age', 300 );
		if ( ! is_numeric( $max_age ) ) {
			$max_age = 300;
		}

		$response->header( 'Cache-Control', 'public, max-age=' . (int) $max_age );

		return $response;
	}

	/**
	 * Published dealers, from the transient unless a rebuild is forced.
	 *
	 * @param bool $force Skip the transient and rebuild.
	 * @return array
	 */
	public static function get_dealers( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$post_types = LOW_DL_Settings::get_dealer_post_types();

		if ( empty( $post_types ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'has_password'        => false,
				'posts_per_page'      => -1,
				'fields'              => 'ids',
				'orderby'             => array(
					'title' => 'ASC',
					'ID'    => 'ASC',
				),
				'order'               => 'ASC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'suppress_filters'    => false,
			)
		);

		$ids = is_array( $query->posts ) ? $query->posts : array();

		update_meta_cache( 'post', $ids );
		_prime_post_caches( $ids, false, false );

		$dealers = array();

		foreach ( $ids as $id ) {
			$id  = (int) $id;
			$row = LOW_DL_Field_Mapper::get_dealer_data( $id );

			if ( null === $row ) {
				continue;
			}

			$dealers[] = array(
				'id'        => (int) $row['id'],
				'name'      => $row['name'],
				'email'     => $row['email'],
				'website'   => $row['website'],
				'phone'     => $row['phone'],
				'address'   => $row['address'],
				'lat'       => $row['lat'],
				'lng'       => $row['lng'],
				'zip_codes'       => array_values( LOW_DL_Zip_Manager::get_zips( $id ) ),
				'service_radius' => LOW_DL_Zip_Manager::get_radius_miles( $id ),
				'service_states' => LOW_DL_Zip_Manager::get_states( $id ),
			);
		}

		$dealers = apply_filters( 'low_dl_dealers_data', $dealers );

		if ( ! is_array( $dealers ) ) {
			$dealers = array();
		}

		$dealers = array_values( $dealers );

		set_transient(
			self::CACHE_KEY,
			$dealers,
			apply_filters( 'low_dl_cache_ttl', 12 * HOUR_IN_SECONDS )
		);

		return $dealers;
	}

	/**
	 * Delete the cached dealer list.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Clear the cache after a dealer is saved. Revisions and autosaves are ignored.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Saved post.
	 * @return void
	 */
	public function clear_cache_on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $this->is_dealer_post( $post ) ) {
			return;
		}

		self::clear_cache();
	}

	/**
	 * Clear the cache after a dealer is permanently deleted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Deleted post.
	 * @return void
	 */
	public function clear_cache_on_delete( $post_id, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
		}

		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) ) {
			return;
		}

		if ( ! $this->is_dealer_post( $post ) ) {
			return;
		}

		self::clear_cache();
	}

	/**
	 * Clear the cache when a dealer is published, unpublished, trashed, or restored.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public function clear_cache_on_status( $new_status, $old_status, $post ) {
		if ( ! $this->is_dealer_post( $post ) ) {
			return;
		}

		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		self::clear_cache();
	}

	/**
	 * Clear the cache after zips, details, or settings change.
	 *
	 * @return void
	 */
	public function clear_cache_on_data_change() {
		self::clear_cache();
	}

	/**
	 * Whether the post is an active dealer type.
	 *
	 * @param mixed $post Post object.
	 * @return bool
	 */
	private function is_dealer_post( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}

		return in_array( $post->post_type, LOW_DL_Settings::get_dealer_post_types(), true );
	}

	/**
	 * Dealers for a validated zip, address, or "lat,lng" pair.
	 *
	 * @param string $type  zip, address, or coords.
	 * @param string $value Validated query value.
	 * @return array|WP_Error
	 */
	public static function search( $type, $value ) {
		$dealers = self::get_dealers();
		$type    = (string) $type;
		$value   = (string) $value;
		$zip     = '';
		$state   = '';
		$origin  = null;

		if ( 'zip' === $type ) {
			$zip    = $value;
			$state  = LOW_DL_Zip_Manager::state_for_zip( $value );
			$origin = self::centroid_origin( $value );
		} elseif ( 'address' === $type ) {
			$origin = self::origin_for_address( $value );

			if ( is_wp_error( $origin ) ) {
				return $origin;
			}

			if ( is_array( $origin ) && isset( $origin['state'] ) && is_string( $origin['state'] ) ) {
				$state = $origin['state'];
			}
		} else {
			$origin = self::origin_for_coords( $value );
		}

		$covered = self::covered_dealers( $dealers, $zip, $state, is_array( $origin ) ? $origin : null );

		if ( ! empty( $covered ) ) {
			return self::search_payload(
				'zip_match',
				$type,
				$value,
				is_array( $origin ) ? $origin : null,
				LOW_DL_Settings::get_text( 'heading_covered' ),
				'',
				$covered
			);
		}

		if ( 'zip' === $type && ! is_array( $origin ) ) {
			$origin = self::origin_for_unmatched_zip( $value );
		}

		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		if ( null === $origin ) {
			return self::search_payload(
				'not_found',
				$type,
				$value,
				null,
				'',
				__( 'We couldn\'t find that location. Check the spelling or try a zip code.', 'low-dealer-locator' ),
				array()
			);
		}

		$nearest = self::nearest_dealers( $dealers, $origin );

		if ( empty( $nearest ) ) {
			$heading = '';
			$message = LOW_DL_Settings::get_text( 'empty_text' );
		} elseif ( 'zip' === $type ) {
			$heading = LOW_DL_Settings::get_text( 'heading_fallback' );
			$message = '';
		} else {
			$heading = LOW_DL_Settings::get_text( 'heading_nearest' );
			$message = '';
		}

		return self::search_payload( 'nearest', $type, $value, $origin, $heading, $message, $nearest );
	}

	/**
	 * Reject a search before any lookup or rate-limit check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error Type and value, or an error.
	 */
	private function validate_search_request( $request ) {
		$type = $request->get_param( 'type' );
		$type = is_string( $type ) ? $type : '';

		if ( 'zip' === $type ) {
			$zip = $this->search_query_text( $request );

			if ( ! preg_match( '/^\d{5}$/', $zip ) ) {
				return new WP_Error(
					'invalid_zip',
					__( 'Please enter a valid 5-digit zip code.', 'low-dealer-locator' ),
					array( 'status' => 400 )
				);
			}

			return array(
				'type'  => 'zip',
				'value' => $zip,
			);
		}

		if ( 'address' === $type ) {
			$query  = $this->search_query_text( $request );
			$length = function_exists( 'mb_strlen' ) ? (int) mb_strlen( $query ) : strlen( $query );

			if ( '' === $query || $length > 200 ) {
				return new WP_Error(
					'empty_address',
					__( 'Please enter an address, city, or zip code.', 'low-dealer-locator' ),
					array( 'status' => 400 )
				);
			}

			return array(
				'type'  => 'address',
				'value' => $query,
			);
		}

		if ( 'coords' === $type ) {
			$lat = $request->get_param( 'lat' );
			$lng = $request->get_param( 'lng' );

			if ( is_bool( $lat ) || is_bool( $lng ) || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				return $this->invalid_coords_error();
			}

			$lat = (float) $lat;
			$lng = (float) $lng;

			if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
				return $this->invalid_coords_error();
			}

			return array(
				'type'  => 'coords',
				'value' => self::format_coord( $lat ) . ',' . self::format_coord( $lng ),
			);
		}

		return new WP_Error(
			'rest_invalid_param',
			__( 'That search could not be understood.', 'low-dealer-locator' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Trimmed q parameter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function search_query_text( $request ) {
		$query = $request->get_param( 'q' );

		if ( ! is_scalar( $query ) ) {
			return '';
		}

		return trim( (string) $query );
	}

	/**
	 * Shared invalid-coordinates error.
	 *
	 * @return WP_Error
	 */
	private function invalid_coords_error() {
		return new WP_Error(
			'invalid_coords',
			__( 'Your location could not be read. Please try again or search by zip code or address.', 'low-dealer-locator' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Wrap a search result and mark it uncacheable.
	 *
	 * @param array|WP_Error $result Search payload or error.
	 * @return WP_REST_Response
	 */
	private function search_http_response( $result ) {
		if ( is_wp_error( $result ) ) {
			$response = rest_convert_error_to_response( $result );
			$data     = $result->get_error_data();

			if ( is_array( $data ) && isset( $data['retry_after'] ) && is_numeric( $data['retry_after'] ) ) {
				$response->header( 'Retry-After', (string) (int) $data['retry_after'] );
			}
		} else {
			$response = rest_ensure_response( $result );
		}

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Dealers who cover this search by zip list, state, or radius.
	 *
	 * @param array      $dealers Cached dealers.
	 * @param string     $zip     Visitor zip, or an empty string.
	 * @param string     $state   Visitor state abbreviation, or an empty string.
	 * @param array|null $origin  Origin lat and lng when known.
	 * @return array
	 */
	private static function covered_dealers( $dealers, $zip, $state, $origin ) {
		$matches = array();
		$state   = LOW_DL_Zip_Manager::is_state_code( $state ) ? $state : '';

		foreach ( $dealers as $dealer ) {
			if ( ! is_array( $dealer ) || ! self::dealer_covers( $dealer, $zip, $state, $origin ) ) {
				continue;
			}

			$matches[] = self::public_dealer( $dealer, null );
		}

		return $matches;
	}

	/**
	 * Whether one dealer serves this zip, state, or point.
	 *
	 * @param array      $dealer Cached dealer.
	 * @param string     $zip    Visitor zip, or an empty string.
	 * @param string     $state  Visitor state abbreviation, or an empty string.
	 * @param array|null $origin Origin lat and lng when known.
	 * @return bool
	 */
	private static function dealer_covers( $dealer, $zip, $state, $origin ) {
		$codes = ( isset( $dealer['zip_codes'] ) && is_array( $dealer['zip_codes'] ) ) ? $dealer['zip_codes'] : array();

		if ( '' !== $zip && in_array( $zip, $codes, true ) ) {
			return true;
		}

		$states = ( isset( $dealer['service_states'] ) && is_array( $dealer['service_states'] ) ) ? $dealer['service_states'] : array();

		if ( '' !== $state && in_array( $state, $states, true ) ) {
			return true;
		}

		$radius = isset( $dealer['service_radius'] ) ? $dealer['service_radius'] : null;

		if ( ! is_numeric( $radius ) || (float) $radius <= 0 || ! is_array( $origin ) ) {
			return false;
		}

		$olat = isset( $origin['lat'] ) ? $origin['lat'] : null;
		$olng = isset( $origin['lng'] ) ? $origin['lng'] : null;
		$dlat = isset( $dealer['lat'] ) ? $dealer['lat'] : null;
		$dlng = isset( $dealer['lng'] ) ? $dealer['lng'] : null;

		if ( ! is_numeric( $olat ) || ! is_numeric( $olng ) || ! is_numeric( $dlat ) || ! is_numeric( $dlng ) ) {
			return false;
		}

		$distance = self::haversine( (float) $olat, (float) $olng, (float) $dlat, (float) $dlng, 'mi' );

		return $distance <= (float) $radius;
	}

	/**
	 * Local centroid only. A miss stays null and does not call the geocoder.
	 *
	 * @param string $zip Five-digit zip.
	 * @return array|null
	 */
	private static function centroid_origin( $zip ) {
		$found = LOW_DL_Centroid_Table::lookup( $zip );

		if ( ! is_array( $found ) || ! isset( $found['lat'], $found['lng'] ) || ! is_numeric( $found['lat'] ) || ! is_numeric( $found['lng'] ) ) {
			return null;
		}

		return array(
			'lat' => (float) $found['lat'],
			'lng' => (float) $found['lng'],
		);
	}

	/**
	 * Origin for a zip no dealer lists. Remote lookup only after the local table misses.
	 *
	 * @param string $zip Five-digit zip.
	 * @return array|WP_Error|null
	 */
	private static function origin_for_unmatched_zip( $zip ) {
		$local = self::centroid_origin( $zip );

		if ( null !== $local ) {
			return $local;
		}

		$limited = self::enforce_rate_limit();

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		return self::interpret_geocode( LOW_DL_Geocoder::zip_to_coords( $zip, true, 3.0 ) );
	}

	/**
	 * Origin for an address query.
	 *
	 * @param string $query Address text.
	 * @return array|WP_Error|null
	 */
	private static function origin_for_address( $query ) {
		$limited = self::enforce_rate_limit();

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		return self::interpret_geocode( LOW_DL_Geocoder::geocode( $query, 3.0 ) );
	}

	/**
	 * Origin from a validated "lat,lng" value.
	 *
	 * @param string $value Coordinate pair.
	 * @return array|null
	 */
	private static function origin_for_coords( $value ) {
		$parts = explode( ',', $value, 2 );

		if ( 2 !== count( $parts ) || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			return null;
		}

		return array(
			'lat' => (float) $parts[0],
			'lng' => (float) $parts[1],
		);
	}

	/**
	 * Turn a geocoder result into an origin, a not-found null, or a public error.
	 *
	 * @param mixed $result Geocoder return value.
	 * @return array|WP_Error|null
	 */
	private static function interpret_geocode( $result ) {
		if ( is_wp_error( $result ) ) {
			if ( 'rate_limited' === $result->get_error_code() ) {
				return new WP_Error(
					'geocoder_busy',
					__( 'The address lookup service is busy. Please try again in a moment.', 'low-dealer-locator' ),
					array( 'status' => 429 )
				);
			}

			return new WP_Error(
				'geocode_failed',
				__( 'We couldn\'t complete that lookup. Please try again later.', 'low-dealer-locator' ),
				array( 'status' => 502 )
			);
		}

		if ( ! is_array( $result ) || ! isset( $result['lat'], $result['lng'] ) || ! is_numeric( $result['lat'] ) || ! is_numeric( $result['lng'] ) ) {
			return null;
		}

		$origin = array(
			'lat' => (float) $result['lat'],
			'lng' => (float) $result['lng'],
		);

		if ( isset( $result['state'] ) && is_string( $result['state'] ) && LOW_DL_Zip_Manager::is_state_code( $result['state'] ) ) {
			$origin['state'] = $result['state'];
		}

		return $origin;
	}

	/**
	 * Nearest dealers that have coordinates.
	 *
	 * @param array $dealers Cached dealers.
	 * @param array $origin  Origin lat and lng.
	 * @return array
	 */
	private static function nearest_dealers( $dealers, $origin ) {
		$settings = LOW_DL_Settings::get_all();
		$unit     = ( isset( $settings['distance_unit'] ) && 'km' === $settings['distance_unit'] ) ? 'km' : 'mi';
		$count    = isset( $settings['nearest_count'] ) ? absint( $settings['nearest_count'] ) : 5;

		if ( $count < 1 || $count > 50 ) {
			$count = 5;
		}

		$max = ( isset( $settings['max_distance'] ) && is_numeric( $settings['max_distance'] ) ) ? (float) $settings['max_distance'] : 0.0;

		if ( $max < 0 ) {
			$max = 0.0;
		}

		$rows = array();

		foreach ( $dealers as $dealer ) {
			if ( ! is_array( $dealer ) ) {
				continue;
			}

			$lat = isset( $dealer['lat'] ) ? $dealer['lat'] : null;
			$lng = isset( $dealer['lng'] ) ? $dealer['lng'] : null;

			if ( null === $lat || null === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
				continue;
			}

			$distance = self::haversine( (float) $origin['lat'], (float) $origin['lng'], (float) $lat, (float) $lng, $unit );

			if ( $max > 0 && $distance > $max ) {
				continue;
			}

			$rows[] = array(
				'dealer'   => $dealer,
				'distance' => $distance,
				'id'       => isset( $dealer['id'] ) ? (int) $dealer['id'] : 0,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				$by_distance = $a['distance'] <=> $b['distance'];

				if ( 0 !== $by_distance ) {
					return $by_distance;
				}

				return $a['id'] <=> $b['id'];
			}
		);

		$rows = array_slice( $rows, 0, $count );
		$out  = array();

		foreach ( $rows as $row ) {
			$out[] = self::public_dealer( $row['dealer'], round( $row['distance'], 1 ) );
		}

		return $out;
	}

	/**
	 * Great-circle distance. Radius is miles or kilometers from the unit setting.
	 *
	 * @param float  $lat1 Origin latitude.
	 * @param float  $lng1 Origin longitude.
	 * @param float  $lat2 Dealer latitude.
	 * @param float  $lng2 Dealer longitude.
	 * @param string $unit mi or km.
	 * @return float
	 */
	private static function haversine( $lat1, $lng1, $lat2, $lng2, $unit ) {
		$radius = ( 'km' === $unit ) ? 6371.0088 : 3958.8;
		$lat1   = deg2rad( $lat1 );
		$lat2   = deg2rad( $lat2 );
		$dlat   = $lat2 - $lat1;
		$dlng   = deg2rad( $lng2 - $lng1 );
		$h      = sin( $dlat / 2 ) * sin( $dlat / 2 ) + cos( $lat1 ) * cos( $lat2 ) * sin( $dlng / 2 ) * sin( $dlng / 2 );

		if ( $h > 1 ) {
			$h = 1;
		} elseif ( $h < 0 ) {
			$h = 0;
		}

		return 2 * $radius * asin( sqrt( $h ) );
	}

	/**
	 * Dealer row for search results: no zip list, plus distance.
	 *
	 * @param array      $dealer   Cached dealer.
	 * @param float|null $distance Miles or kilometers, or null for a zip match.
	 * @return array
	 */
	private static function public_dealer( $dealer, $distance ) {
		unset( $dealer['zip_codes'], $dealer['service_radius'], $dealer['service_states'] );

		$dealer['id']       = isset( $dealer['id'] ) ? (int) $dealer['id'] : 0;
		$dealer['lat']      = self::coord_or_null( isset( $dealer['lat'] ) ? $dealer['lat'] : null );
		$dealer['lng']      = self::coord_or_null( isset( $dealer['lng'] ) ? $dealer['lng'] : null );
		$dealer['distance'] = ( null === $distance ) ? null : (float) $distance;

		return $dealer;
	}

	/**
	 * Latitude or longitude as a float, or null when it is missing.
	 *
	 * @param mixed $value Stored coordinate.
	 * @return float|null
	 */
	private static function coord_or_null( $value ) {
		if ( null === $value || false === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Search JSON body. An empty dealer list stays a JSON array.
	 *
	 * @param string     $mode     zip_match, nearest, or not_found.
	 * @param string     $type     Query type.
	 * @param string     $value    Query value.
	 * @param array|null $origin   Origin coordinates.
	 * @param string     $heading  Heading text.
	 * @param string     $message  Message text.
	 * @param array      $dealers  Public dealer rows.
	 * @return array
	 */
	private static function search_payload( $mode, $type, $value, $origin, $heading, $message, $dealers ) {
		if ( is_array( $origin ) && isset( $origin['lat'], $origin['lng'] ) ) {
			$origin = array(
				'lat' => (float) $origin['lat'],
				'lng' => (float) $origin['lng'],
			);
		} else {
			$origin = null;
		}

		$unit = LOW_DL_Settings::get( 'distance_unit' );

		if ( 'km' !== $unit ) {
			$unit = 'mi';
		}

		return array(
			'mode'    => $mode,
			'query'   => array(
				'type'  => $type,
				'value' => $value,
			),
			'origin'  => $origin,
			'unit'    => $unit,
			'heading' => (string) $heading,
			'message' => (string) $message,
			'dealers' => array_values( $dealers ),
		);
	}

	/**
	 * Ten remote lookups per minute per IP, unless a filter changes the limit.
	 *
	 * The window is set on the first hit and is not extended when the counter increments.
	 *
	 * @return true|WP_Error
	 */
	private static function enforce_rate_limit() {
		$limits = apply_filters(
			'low_dl_search_rate_limit',
			array(
				'max'    => 10,
				'window' => 60,
			)
		);
		$max    = 10;
		$window = 60;

		if ( is_array( $limits ) ) {
			if ( isset( $limits['max'] ) && is_numeric( $limits['max'] ) ) {
				$max = (int) $limits['max'];

				if ( $max < 1 ) {
					$max = 1;
				}
			}

			if ( isset( $limits['window'] ) && is_numeric( $limits['window'] ) ) {
				$window = (int) $limits['window'];

				if ( $window < 1 ) {
					$window = 1;
				}
			}
		}

		$key   = 'low_dl_rl_' . md5( self::client_ip() );
		$count = get_transient( $key );

		if ( false === $count || ! is_numeric( $count ) ) {
			set_transient( $key, 1, $window );
			return true;
		}

		$count     = (int) $count;
		$remaining = self::rate_limit_remaining( $key, $window );

		if ( $count >= $max ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many searches right now. Please wait a minute and try again.', 'low-dealer-locator' ),
				array(
					'status'      => 429,
					'retry_after' => $remaining,
				)
			);
		}

		set_transient( $key, $count + 1, $remaining );

		return true;
	}

	/**
	 * Seconds left on a rate-limit transient.
	 *
	 * @param string $key    Transient key.
	 * @param int    $window Fallback window.
	 * @return int
	 */
	private static function rate_limit_remaining( $key, $window ) {
		$timeout = get_option( '_transient_timeout_' . $key );

		if ( ! is_numeric( $timeout ) ) {
			return (int) $window;
		}

		$remaining = (int) $timeout - time();

		if ( $remaining < 1 ) {
			return 1;
		}

		return $remaining;
	}

	/**
	 * Client IP from REMOTE_ADDR, then the low_dl_client_ip filter.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = 'unknown';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] ) ) {
			$raw   = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
			$valid = rest_is_ip_address( $raw );

			if ( is_string( $valid ) && '' !== $valid ) {
				$ip = $valid;
			}
		}

		$filtered = apply_filters( 'low_dl_client_ip', $ip );

		if ( is_string( $filtered ) && '' !== $filtered ) {
			return $filtered;
		}

		return $ip;
	}

	/**
	 * Compact coordinate text for the query value.
	 *
	 * @param float $number Latitude or longitude.
	 * @return string
	 */
	private static function format_coord( $number ) {
		$formatted = rtrim( rtrim( sprintf( '%.8F', (float) $number ), '0' ), '.' );

		if ( '' === $formatted || '-' === $formatted || '-0' === $formatted ) {
			return '0';
		}

		return $formatted;
	}
}
