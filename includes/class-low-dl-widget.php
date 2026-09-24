<?php
/**
 * Classic widget wrapper for the dealer locator.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dealer Locator widget. Markup comes from LOW_DL_Shortcode::render().
 */
class LOW_DL_Widget extends WP_Widget {

	/**
	 * Register this instance when widgets initialize.
	 */
	public function __construct() {
		add_action( 'widgets_init', array( $this, 'register' ) );
	}

	/**
	 * Set the widget identity and add it to the widget factory.
	 *
	 * @return void
	 */
	public function register() {
		parent::__construct(
			'low_dl_locator_widget',
			__( 'Dealer Locator', 'low-dealer-locator' ),
			array(
				'description' => __( 'A dealer locator map with search by zip code, address, or location.', 'low-dealer-locator' ),
			)
		);

		register_widget( $this );
	}

	/**
	 * Front-end widget output.
	 *
	 * @param array $args     Display arguments from the theme.
	 * @param array $instance Saved widget values.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		if ( ! is_array( $instance ) ) {
			$instance = array();
		}

		echo $args['before_widget'];

		$title = '';

		if ( isset( $instance['title'] ) && is_scalar( $instance['title'] ) ) {
			$title = (string) $instance['title'];
		}

		$title = apply_filters( 'widget_title', $title, $instance, $this->id_base );

		if ( is_scalar( $title ) && '' !== (string) $title ) {
			echo $args['before_title'] . esc_html( (string) $title ) . $args['after_title'];
		}

		$height = '';
		$zoom   = '';

		if ( isset( $instance['height'] ) && is_scalar( $instance['height'] ) ) {
			$height = (string) $instance['height'];
		}

		if ( isset( $instance['zoom'] ) && is_scalar( $instance['zoom'] ) ) {
			$zoom = (string) $instance['zoom'];
		}

		echo LOW_DL_Shortcode::render(
			array(
				'height' => $height,
				'zoom'   => $zoom,
			)
		);

		echo $args['after_widget'];
	}

	/**
	 * Widget settings form.
	 *
	 * @param array $instance Saved widget values.
	 * @return void
	 */
	public function form( $instance ) {
		if ( ! is_array( $instance ) ) {
			$instance = array();
		}

		$title  = isset( $instance['title'] ) && is_scalar( $instance['title'] ) ? (string) $instance['title'] : '';
		$height = isset( $instance['height'] ) && is_scalar( $instance['height'] ) ? (string) $instance['height'] : '';
		$zoom   = isset( $instance['zoom'] ) && is_scalar( $instance['zoom'] ) ? (string) $instance['zoom'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'low-dealer-locator' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'height' ) ); ?>"><?php esc_html_e( 'Map height (px):', 'low-dealer-locator' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'height' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'height' ) ); ?>" type="number" min="200" max="1200" step="1" value="<?php echo esc_attr( $height ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'zoom' ) ); ?>"><?php esc_html_e( 'Default zoom:', 'low-dealer-locator' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'zoom' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'zoom' ) ); ?>" type="number" min="1" max="18" step="1" value="<?php echo esc_attr( $zoom ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'Leave blank to use the Locator settings.', 'low-dealer-locator' ); ?></p>
		<?php
	}

	/**
	 * Sanitize widget values.
	 *
	 * @param array $new_instance Values from the form.
	 * @param array $old_instance Previous values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		unset( $old_instance );

		if ( ! is_array( $new_instance ) ) {
			$new_instance = array();
		}

		return array(
			'title'  => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'height' => self::digits_in_range( isset( $new_instance['height'] ) ? $new_instance['height'] : '', 200, 1200 ),
			'zoom'   => self::digits_in_range( isset( $new_instance['zoom'] ) ? $new_instance['zoom'] : '', 1, 18 ),
		);
	}

	/**
	 * Keep an all-digit value inside the range, otherwise blank.
	 *
	 * @param mixed $value Submitted value.
	 * @param int   $min   Minimum inclusive.
	 * @param int   $max   Maximum inclusive.
	 * @return string
	 */
	private static function digits_in_range( $value, $min, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );

		if ( ! preg_match( '/^\d+$/', $value ) ) {
			return '';
		}

		$number = (int) $value;

		if ( $number < $min || $number > $max ) {
			return '';
		}

		return (string) $number;
	}
}
