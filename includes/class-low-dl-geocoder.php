<?php
/**
 * Nominatim-compatible geocoder.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an address or zip into coordinates.
 */
class LOW_DL_Geocoder {

	/**
	 * Transient prefix for cached geocoder results.
	 */
	const CACHE_PREFIX = 'low_dl_geo_';

	/**
	 * Transient set while the service is refusing requests.
	 */
	const BACKOFF_KEY = 'low_dl_geo_backoff';

	/**
	 * Transient holding the microtime of the last outbound request.
	 */
	const LAST_KEY = 'low_dl_geo_last';

	/**
	 * How long a successful lookup is cached.
	 */
	const HIT_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * How long a "nothing found" lookup is cached.
	 */
	const MISS_TTL = DAY_IN_SECONDS;

	/**
	 * Geocoding settings form.
	 *
	 * @return void
	 */
	public static function render_fields() {
		$url        = self::setting_string( 'geocoder_url' );
		$key_param  = self::setting_string( 'geocoder_key_param' );
		$email      = self::setting_string( 'geocoder_email' );
		$countries  = self::setting_string( 'geocoder_countries' );
		$interval   = LOW_DL_Settings::get( 'geocoder_interval' );
		$admin_mail = get_option( 'admin_email' );
		$has_key    = '' !== self::setting_string( 'geocoder_key' );
		$name       = LOW_DL_Settings::OPTION_KEY;

		if ( ! is_string( $admin_mail ) ) {
			$admin_mail = '';
		}

		if ( ! is_numeric( $interval ) ) {
			$interval = 1;
		}
		?>
		<div class="low-dl-geocoding">
			<p class="description">
				<?php echo esc_html__( 'The default is the free public OpenStreetMap Nominatim service, which allows at most 1 request per second, requires an identifying User-Agent, and is meant for light use; a paid or self-hosted Nominatim-compatible service can be entered here.', 'low-dealer-locator' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="low-dl-geocoder-url"><?php echo esc_html__( 'Geocoder URL', 'low-dealer-locator' ); ?></label>
					</th>
					<td>
						<input
							type="url"
							class="regular-text code"
							id="low-dl-geocoder-url"
							name="<?php echo esc_attr( $name ); ?>[geocoder_url]"
							value="<?php echo esc_attr( $url ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="low-dl-geocoder-key"><?php echo esc_html__( 'API key', 'low-dealer-locator' ); ?></label>
					</th>
					<td>
						<input
							type="password"
							class="regular-text"
							id="low-dl-geocoder-key"
							name="<?php echo esc_attr( $name ); ?>[geocoder_key]"
							value=""
							autocomplete="new-password"
						/>
						<?php if ( $has_key ) : ?>
							<p class="description"><?php echo esc_html__( 'A key is saved', 'low-dealer-locator' ); ?></p>
						<?php endif; ?>
						<p>
							<label for="low-dl-geocoder-remove-key">
								<input
									type="checkbox"
									id="low-dl-geocoder-remove-key"
									name="<?php echo esc_attr( $name ); ?>[geocoder_remove_key]"
									value="1"
								/>
								<?php echo esc_html__( 'Remove the saved key', 'low-dealer-locator' ); ?>
							</label>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="low-dl-geocoder-key-param"><?php echo esc_html__( 'Key parameter name', 'low-dealer-locator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="low-dl-geocoder-key-param"
							name="<?php echo esc_attr( $name ); ?>[geocoder_key_param]"
							value="<?php echo esc_attr( $key_param ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="low-dl-geocoder-email"><?php echo esc_html__( 'Contact email for the User-Agent', 'low-dealer-locator' ); ?></label>
					</th>
					<td>
						<input
							type="email"
							class="regular-text"
							id="low-dl-geocoder-email"
							name="<?php echo esc_attr( $name ); ?>[geocoder_email]"
							value="<?php echo esc_attr( $email ); ?>"
							placeholder="<?php echo esc_attr( $admin_mail ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="low-dl-geocoder-countries"><?php echo esc_html__( 'Country codes', 'low-dealer-locator' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="low-dl-geocoder-countries"
							name="<?php echo esc_attr( $name ); ?>[geocoder_countries]"
							value="<?php echo esc_attr( $countries ); ?>"
						/>
						<p class="description"><?php echo esc_html__( 'Comma-separated ISO codes. Leave blank for worldwide.', 'low-dealer-locator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="low-dl-geocoder-interval"><?php echo esc_html__( 'Minimum seconds between requests', 'low-dealer-locator' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							class="small-text"
							id="low-dl-geocoder-interval"
							name="<?php echo esc_attr( $name ); ?>[geocoder_interval]"
							value="<?php echo esc_attr( (string) $interval ); ?>"
							min="0"
							max="10"
							step="0.1"
						/>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Replace only the geocoder_* values.
	 *
	 * A blank API key keeps the saved key. The remove checkbox clears it.
	 *
	 * @param mixed $input    Posted settings.
	 * @param mixed $existing Saved settings.
	 * @return array
	 */
	public static function sanitize_settings( $input, $existing ) {
		$defaults = LOW_DL_Settings::defaults();
		$clean    = is_array( $existing ) ? $existing : $defaults;

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$url = self::posted_string( $input, 'geocoder_url' );
		$url = esc_url_raw( $url );

		if ( ! self::is_https_url( $url ) ) {
			add_settings_error(
				LOW_DL_Settings::OPTION_KEY,
				'low_dl_geocoder_url',
				__( 'The geocoder URL must be a valid https address. The default was restored.', 'low-dealer-locator' ),
				'error'
			);
			$url = $defaults['geocoder_url'];
		}

		$posted_key = self::limit_text( sanitize_text_field( self::posted_string( $input, 'geocoder_key' ) ), 200 );
		$existing_key = '';

		if ( isset( $clean['geocoder_key'] ) && is_scalar( $clean['geocoder_key'] ) ) {
			$existing_key = (string) $clean['geocoder_key'];
		}

		if ( ! empty( $input['geocoder_remove_key'] ) ) {
			$key = '';
		} elseif ( '' !== $posted_key ) {
			$key = $posted_key;
		} else {
			$key = $existing_key;
		}

		$param = sanitize_key( self::posted_string( $input, 'geocoder_key_param' ) );

		if ( '' === $param ) {
			$param = $defaults['geocoder_key_param'];
		}

		$email = sanitize_email( self::posted_string( $input, 'geocoder_email' ) );

		if ( ! is_email( $email ) ) {
			$email = '';
		}

		$interval = self::posted_string( $input, 'geocoder_interval' );

		if ( ! is_numeric( $interval ) ) {
			$interval = (float) $defaults['geocoder_interval'];
		} else {
			$interval = (float) $interval;
		}

		$interval = (float) min( 10, max( 0, $interval ) );

		if ( self::is_public_nominatim( $url ) && $interval < 1 ) {
			$interval = 1.0;
		}

		$clean['geocoder_url']       = $url;
		$clean['geocoder_key']       = $key;
		$clean['geocoder_key_param'] = $param;
		$clean['geocoder_email']     = $email;
		$clean['geocoder_countries'] = self::sanitize_countries( self::posted_string( $input, 'geocoder_countries' ) );
		$clean['geocoder_interval']  = $interval;

		unset( $clean['geocoder_remove_key'] );

		return $clean;
	}

	/**
	 * Coordinates for a free-text query.
	 *
	 * @param string $query    Address or place.
	 * @param float  $max_wait Longest pause this call may take, in seconds.
	 * @return array{lat: float, lng: float}|null|WP_Error
	 */
	public static function geocode( string $query, float $max_wait = 3.0 ) {
		$query = self::normalize_query( $query );

		if ( '' === $query || self::query_length( $query ) > 300 ) {
			return new WP_Error(
				'empty_query',
				__( 'Enter an address to geocode.', 'low-dealer-locator' )
			);
		}

		$cache_key = self::cache_key( $query );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && isset( $cached['lat'], $cached['lng'] ) && is_numeric( $cached['lat'] ) && is_numeric( $cached['lng'] ) ) {
			return array(
				'lat' => (float) $cached['lat'],
				'lng' => (float) $cached['lng'],
			);
		}

		if ( is_array( $cached ) && ! empty( $cached['none'] ) ) {
			return null;
		}

		if ( get_transient( self::BACKOFF_KEY ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'The geocoder is busy. Try again shortly.', 'low-dealer-locator' )
			);
		}

		$throttle = self::throttle( (float) $max_wait );

		if ( is_wp_error( $throttle ) ) {
			return $throttle;
		}

		$response = self::request( $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code || 403 === $code ) {
			set_transient( self::BACKOFF_KEY, 1, 5 * MINUTE_IN_SECONDS );

			return new WP_Error(
				'rate_limited',
				__( 'The geocoder is busy. Try again shortly.', 'low-dealer-locator' )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'http_error',
				__( 'The geocoder did not respond.', 'low-dealer-locator' )
			);
		}

		$parsed = self::parse_body( wp_remote_retrieve_body( $response ) );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( null === $parsed ) {
			set_transient( $cache_key, array( 'none' => 1 ), self::MISS_TTL );
			return null;
		}

		set_transient( $cache_key, $parsed, self::HIT_TTL );

		return $parsed;
	}

	/**
	 * Coordinates for a street address.
	 *
	 * @param array $address  Keys street, city, state, and zip.
	 * @param float $max_wait Longest pause this call may take, in seconds.
	 * @return array{lat: float, lng: float}|null|WP_Error
	 */
	public static function geocode_address( array $address, float $max_wait = 3.0 ) {
		$parts = array();

		foreach ( array( 'street', 'city', 'state', 'zip' ) as $part ) {
			if ( ! isset( $address[ $part ] ) || ! is_scalar( $address[ $part ] ) ) {
				continue;
			}

			$text = trim( (string) $address[ $part ] );

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		if ( empty( $parts ) ) {
			return new WP_Error(
				'empty_query',
				__( 'Enter an address to geocode.', 'low-dealer-locator' )
			);
		}

		return self::geocode( implode( ', ', $parts ), (float) $max_wait );
	}

	/**
	 * Coordinates for a zip, from the centroid table or the geocoder.
	 *
	 * @param string $zip          Five-digit zip code.
	 * @param bool   $allow_remote Use the geocoder when the centroid table has no row.
	 * @param float  $max_wait     Longest pause this call may take, in seconds.
	 * @return array{lat: float, lng: float}|null|WP_Error
	 */
	public static function zip_to_coords( string $zip, bool $allow_remote = true, float $max_wait = 3.0 ) {
		$zip = trim( $zip );

		if ( ! preg_match( '/^\d{5}$/', $zip ) ) {
			return null;
		}

		$local = LOW_DL_Centroid_Table::lookup( $zip );

		if ( is_array( $local ) && isset( $local['lat'], $local['lng'] ) ) {
			return array(
				'lat' => (float) $local['lat'],
				'lng' => (float) $local['lng'],
			);
		}

		if ( ! $allow_remote ) {
			return null;
		}

		return self::geocode( $zip . ', USA', (float) $max_wait );
	}

	/**
	 * Saved string setting, or an empty string.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function setting_string( $key ) {
		$value = LOW_DL_Settings::get( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * One posted scalar, unslashed.
	 *
	 * @param array  $input Posted settings.
	 * @param string $key   Field key.
	 * @return string
	 */
	private static function posted_string( array $input, $key ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return '';
		}

		return trim( (string) wp_unslash( $input[ $key ] ) );
	}

	/**
	 * Whether a URL is https and accepted by WordPress.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_https_url( $url ) {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return false;
		}

		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME );
	}

	/**
	 * Whether this host is the public OpenStreetMap Nominatim service.
	 *
	 * @param string $url Geocoder URL.
	 * @return bool
	 */
	private static function is_public_nominatim( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && 'nominatim.openstreetmap.org' === strtolower( $host );
	}

	/**
	 * Two-letter country codes joined with commas.
	 *
	 * @param string $raw Posted country list.
	 * @return string
	 */
	private static function sanitize_countries( $raw ) {
		$codes = array();

		foreach ( preg_split( '/\s*,\s*/', strtolower( $raw ) ) as $code ) {
			$code = trim( $code );

			if ( preg_match( '/^[a-z]{2}$/', $code ) ) {
				$codes[] = $code;
			}
		}

		return implode( ',', array_values( array_unique( $codes ) ) );
	}

	/**
	 * Cap a string at a character length.
	 *
	 * @param string $text  Text.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private static function limit_text( $text, $limit ) {
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $text ) > $limit ) {
			return mb_substr( $text, 0, $limit );
		}

		if ( strlen( $text ) > $limit ) {
			return substr( $text, 0, $limit );
		}

		return $text;
	}

	/**
	 * Trim and collapse whitespace.
	 *
	 * @param string $query Raw query.
	 * @return string
	 */
	private static function normalize_query( $query ) {
		$query = trim( $query );
		$query = preg_replace( '/\s+/', ' ', $query );

		return is_string( $query ) ? $query : '';
	}

	/**
	 * Character length of a query.
	 *
	 * @param string $query Normalized query.
	 * @return int
	 */
	private static function query_length( $query ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $query );
		}

		return strlen( $query );
	}

	/**
	 * Transient key for one query on the configured service.
	 *
	 * @param string $query Normalized query.
	 * @return string
	 */
	private static function cache_key( $query ) {
		return self::CACHE_PREFIX . md5( self::setting_string( 'geocoder_url' ) . '|' . strtolower( $query ) );
	}

	/**
	 * Configured gap between requests, in seconds.
	 *
	 * Public Nominatim is never called faster than once per second.
	 *
	 * @return float
	 */
	private static function interval() {
		$interval = LOW_DL_Settings::get( 'geocoder_interval' );
		$interval = is_numeric( $interval ) ? (float) $interval : 1.0;
		$interval = min( 10, max( 0, $interval ) );

		if ( self::is_public_nominatim( self::setting_string( 'geocoder_url' ) ) && $interval < 1 ) {
			$interval = 1.0;
		}

		return $interval;
	}

	/**
	 * Wait out the remaining gap, or refuse when the wait is too long.
	 *
	 * @param float $max_wait Longest pause this call may take, in seconds.
	 * @return true|WP_Error
	 */
	private static function throttle( $max_wait ) {
		if ( $max_wait < 0 ) {
			$max_wait = 0.0;
		}

		$last = get_transient( self::LAST_KEY );
		$wait = 0.0;

		if ( is_numeric( $last ) ) {
			$wait = self::interval() - ( microtime( true ) - (float) $last );
		}

		if ( $wait < 0 ) {
			$wait = 0.0;
		}

		if ( $wait > $max_wait ) {
			return new WP_Error(
				'rate_limited',
				__( 'The geocoder is busy. Try again shortly.', 'low-dealer-locator' )
			);
		}

		if ( $wait > 0 ) {
			usleep( (int) round( $wait * 1000000 ) );
		}

		set_transient( self::LAST_KEY, microtime( true ), 60 );

		return true;
	}

	/**
	 * GET the geocoder.
	 *
	 * @param string $query Normalized query.
	 * @return array|WP_Error
	 */
	private static function request( $query ) {
		$args = array(
			'q'              => $query,
			'format'         => 'jsonv2',
			'limit'          => 1,
			'addressdetails' => 0,
		);

		$countries = self::setting_string( 'geocoder_countries' );

		if ( '' !== $countries ) {
			$args['countrycodes'] = $countries;
		}

		$key   = self::setting_string( 'geocoder_key' );
		$param = self::setting_string( 'geocoder_key_param' );

		if ( '' !== $key && '' !== $param ) {
			$args[ $param ] = $key;
		}

		$url = add_query_arg( $args, self::setting_string( 'geocoder_url' ) );
		$ua  = self::user_agent();

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 2,
				'headers'     => array(
					'User-Agent' => $ua,
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'http_error',
				__( 'The geocoder did not respond.', 'low-dealer-locator' )
			);
		}

		return $response;
	}

	/**
	 * Identifying User-Agent. The API key is never included.
	 *
	 * @return string
	 */
	private static function user_agent() {
		$name = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$name = trim( str_replace( array( "\r", "\n" ), '', $name ) );
		$home = trim( str_replace( array( "\r", "\n" ), '', (string) home_url() ) );
		$email = self::setting_string( 'geocoder_email' );

		if ( '' === $email ) {
			$admin = get_option( 'admin_email' );
			$email = is_string( $admin ) ? $admin : '';
		}

		$email = trim( str_replace( array( "\r", "\n" ), '', $email ) );
		$agent = $name . ' (' . $home . '; ' . $email . ') LOW-Dealer-Locator/' . LOW_DL_VERSION;
		$agent = apply_filters( 'low_dl_geocoder_user_agent', $agent );

		if ( ! is_string( $agent ) || '' === $agent ) {
			$agent = 'LOW-Dealer-Locator/' . LOW_DL_VERSION;
		}

		return str_replace( array( "\r", "\n" ), '', $agent );
	}

	/**
	 * First result from a JSON body.
	 *
	 * @param string $body Response body.
	 * @return array{lat: float, lng: float}|null|WP_Error
	 */
	private static function parse_body( $body ) {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'bad_response',
				__( 'The geocoder returned an unexpected response.', 'low-dealer-locator' )
			);
		}

		if ( array() === $data ) {
			return null;
		}

		$first = reset( $data );

		if ( ! is_array( $first ) || ! isset( $first['lat'], $first['lon'] ) ) {
			return new WP_Error(
				'bad_response',
				__( 'The geocoder returned an unexpected response.', 'low-dealer-locator' )
			);
		}

		if ( ! is_numeric( $first['lat'] ) || ! is_numeric( $first['lon'] ) ) {
			return new WP_Error(
				'bad_response',
				__( 'The geocoder returned an unexpected response.', 'low-dealer-locator' )
			);
		}

		$lat = (float) $first['lat'];
		$lng = (float) $first['lon'];

		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return new WP_Error(
				'bad_response',
				__( 'The geocoder returned an unexpected response.', 'low-dealer-locator' )
			);
		}

		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}
}
