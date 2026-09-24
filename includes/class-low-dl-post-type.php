<?php
/**
 * Optional dealer_locator post type.
 *
 * @package LOW_Dealer_Locator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the dealer post type when the setting is enabled.
 */
class LOW_DL_Post_Type {

	/**
	 * Post type slug.
	 */
	const POST_TYPE = 'dealer_locator';

	/**
	 * Hook registration only when the setting is on.
	 */
	public function __construct() {
		if ( LOW_DL_Settings::get( 'create_dealer_cpt' ) ) {
			add_action( 'init', array( $this, 'register' ), 5 );
		}
	}

	/**
	 * Register the dealer post type.
	 *
	 * Rewrite is disabled and the type is not public, so rules are not flushed.
	 *
	 * @return void
	 */
	public function register() {
		$labels = array(
			'name'                  => __( 'Dealers', 'low-dealer-locator' ),
			'singular_name'         => __( 'Dealer', 'low-dealer-locator' ),
			'menu_name'             => __( 'Dealers', 'low-dealer-locator' ),
			'name_admin_bar'        => __( 'Dealer', 'low-dealer-locator' ),
			'add_new'               => __( 'Add New', 'low-dealer-locator' ),
			'add_new_item'          => __( 'Add New Dealer', 'low-dealer-locator' ),
			'new_item'              => __( 'New Dealer', 'low-dealer-locator' ),
			'edit_item'             => __( 'Edit Dealer', 'low-dealer-locator' ),
			'view_item'             => __( 'View Dealer', 'low-dealer-locator' ),
			'all_items'             => __( 'All Dealers', 'low-dealer-locator' ),
			'search_items'          => __( 'Search Dealers', 'low-dealer-locator' ),
			'not_found'             => __( 'No dealers found.', 'low-dealer-locator' ),
			'not_found_in_trash'    => __( 'No dealers found in Trash.', 'low-dealer-locator' ),
			'featured_image'        => __( 'Featured image', 'low-dealer-locator' ),
			'set_featured_image'    => __( 'Set featured image', 'low-dealer-locator' ),
			'remove_featured_image' => __( 'Remove featured image', 'low-dealer-locator' ),
			'use_featured_image'    => __( 'Use as featured image', 'low-dealer-locator' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'menu_icon'           => 'dashicons-location',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'revisions' ),
		);

		/**
		 * Filters the dealer_locator post type arguments before registration.
		 *
		 * @param array $args register_post_type() arguments.
		 */
		$args = apply_filters( 'low_dl_dealer_cpt_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}
}
