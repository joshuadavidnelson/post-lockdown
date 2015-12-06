<?php
/**
 * Plugin Name: Post Lockdown
 * Description: Allows admins to lock selected posts and pages so they cannot be edited or deleted by non-admin users.
 * Version: 1.1.1
 * Author: Andy Palmer
 * Author URI: http://www.andypalmer.me
 * License: GPL2
 * Text Domain: postlockdown
 */
if ( is_admin() ) {
	add_action( 'init', [ 'PostLockdown', 'get_instance' ], 99 );
}

class PostLockdown {

	/** Plugin key for options and the option page. */
	const KEY = 'postlockdown';

	/** Option page title. */
	const TITLE = 'Post Lockdown';

	/** Query arg used to determine if an admin notice is displayed */
	const QUERY_ARG = 'plstatuschange';

	/** @var array List of post IDs which cannot be edited, trashed or deleted. */
	private $locked_post_ids = [];

	/** @var array List of post IDs which cannot be trashed or deleted. */
	private $protected_post_ids = [];

	/** @var string Page hook returned by add_options_page(). */
	private $page_hook;

	/** @var object Reference to the unique instance of the class. */
	private static $instance;

	/**
	 * Returns a single instance of the PostLockdown class.
	 *
	 * @return PostLockdown object instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->load_options();

		add_action( 'admin_init', function() {
			register_setting( self::KEY, self::KEY );
		} );

		add_action( 'admin_menu', function() {
			$this->page_hook = add_options_page( self::TITLE, self::TITLE, $this->get_admin_cap(), self::KEY, function() {
				include_once( plugin_dir_path( __FILE__ ) . 'view/options-page.php' );
			} );
		} );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_ajax_pl_autocomplete', [ $this, 'ajax_autocomplete' ] );

		add_filter( 'option_page_capability_' . self::KEY, [ $this, 'get_admin_cap' ] );

		add_action( 'admin_notices', [ $this, 'output_admin_notices' ] );
		add_action( 'delete_post', [ $this, 'update_option' ] );

		add_filter( 'user_has_cap', [ $this, 'filter_cap' ], 10, 3 );
		add_filter( 'wp_insert_post_data', [ $this, 'prevent_status_change' ], 10, 2 );

		add_filter( 'removable_query_args', function( $args ) {
			$args[] = self::QUERY_ARG;

			return $args;
		} );

		register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall' ] );
	}

	/**
	 * Filter for the 'user_has_cap' hook.
	 *
	 * Sets the capability to false when current_user_can() has been called on
	 * one of the capabilities we're interested in on a locked or protected post.
	 */
	public function filter_cap( array $allcaps, array $cap, array $args ) {
		if ( ! $this->have_posts() ) {
			return $allcaps;
		}

		$the_caps = apply_filters( 'postlockdown_capabilities', [
			'delete_post' => true,
			'edit_post' => true,
		] );

		// If it's not a capability we're interested in get out of here.
		if ( ! isset( $the_caps[ $args[0] ] ) ) {
			return $allcaps;
		}

		/* If the user has the required capability to bypass
		 * restrictions get out of here.
		 */
		if ( ! empty( $allcaps[ $this->get_admin_cap() ] ) ) {
			return $allcaps;
		}

		$post_id = $args[2];

		if ( ! $post_id ) {
			return $allcaps;
		}

		$has_cap = ! $this->is_post_locked( $post_id );

		if ( $has_cap && 'edit_post' !== $args[0] ) {
			$has_cap = ! $this->is_post_protected( $post_id );
		}

		$allcaps[ $cap[0] ] = $has_cap;

		return $allcaps;
	}

	/**
	 * Filter for the 'wp_insert_post_data' hook.
	 *
	 * Reverts any changes made by a non-admin to a published protected post's status, privacy and password.
	 * Also reverts any date changes if they're set to a future date. If anything is changed a filter for
	 * the 'redirect_post_location' hook is added to display an admin notice letting the user know we reverted it.
	 */
	public function prevent_status_change( array $data, array $postarr ) {
		/* If the user has the required capability to bypass
		 * restrictions or there are no locked or protected posts get out of here.
		 */
		if ( current_user_can( $this->get_admin_cap() ) || ! $this->have_posts() ) {
			return $data;
		}

		$post_id = $postarr['ID'];

		/* If it's not a protected post get out of here. No need
		 * to check for locked posts because they can't be edited.
		 */
		if ( ! $this->is_post_protected( $post_id ) ) {
			return $data;
		}

		$post = get_post( $post_id );

		/* If the post is not published we don't need to revert
		 * anything so get out of here.
		 */
		if ( 'publish' !== $post->post_status ) {
			return $data;
		}

		$changed = false;

		if ( 'publish' !== $data['post_status'] ) {
			$changed = true;
			$data['post_status'] = $post->post_status;
		}

		if ( $data['post_password'] !== $post->post_password ) {
			$changed = true;
			$data['post_password'] = $post->post_password;
		}

		// Revert the post date if it's set to a future date.
		if ( $data['post_date'] !== $post->post_date && strtotime( $data['post_date'] ) > time() ) {
			$changed = true;
			$data['post_date'] = $post->post_date;
			$data['post_date_gmt'] = $post->post_date_gmt;
		}

		if ( $changed ) {
			add_filter( 'redirect_post_location', function( $location ) {
				return add_query_arg( self::QUERY_ARG, 1, $location );
			} );
		}

		return $data;
	}

	/**
	 * Callback for the 'admin_notices' hook.
	 *
	 * Outputs the plugin's admin notices if there are any.
	 */
	public function output_admin_notices() {
		$notices = [];

		if ( $this->filter_input( self::QUERY_ARG ) ) {
			$notices[] = [
				'class' => 'error',
				'message' => esc_html( 'This post is protected by Post Lockdown and must stay published.', 'postlockdown' ),
			];
		}

		if ( ! empty( $notices ) ) {
			include_once( plugin_dir_path( __FILE__ ) . 'view/admin-notices.php' );
		}
	}

	/**
	 * Callback for the 'pl_autocomplete' AJAX action.
	 *
	 * Responds with a json encoded array of posts matching the query.
	 */
	public function ajax_autocomplete() {
		$posts = $this->get_posts( [
			's' => $this->filter_input( 'term' ),
			'offset' => $this->filter_input( 'offset', 'int' ),
			'posts_per_page' => 10,
		] );

		wp_send_json_success( $posts );
	}

	/**
	 * Callback for the 'admin_enqueue_scripts' hook.
	 *
	 * Enqueues the required scripts and styles for the plugin options page.
	 */
	public function enqueue_scripts( $hook ) {
		// If it's not the plugin options page get out of here.
		if ( $hook !== $this->page_hook ) {
			return;
		}

		$assets_path = plugin_dir_url( __FILE__ ) . 'view/assets/';

		$ext = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_enqueue_style( self::KEY, $assets_path . 'css/postlockdown' . $ext . '.css', null, null );

		wp_enqueue_script( 'plmultiselect', $assets_path . 'js/jquery.plmultiselect' . $ext . '.js', [ 'jquery-ui-autocomplete' ], null, true );
		wp_enqueue_script( self::KEY, $assets_path . 'js/postlockdown' . $ext . '.js', [ 'plmultiselect' ], null, true );

		$data = [];

		if ( $this->have_posts() ) {
			$posts = $this->get_posts( [
				'nopaging' => true,
				'post__in' => array_merge( $this->locked_post_ids, $this->protected_post_ids ),
			] );

			foreach ( $posts as $post ) {
				if ( $this->is_post_locked( $post->ID ) ) {
					$data['locked'][] = $post;
				}

				if ( $this->is_post_protected( $post->ID ) ) {
					$data['protected'][] = $post;
				}
			}
		}

		wp_localize_script( self::KEY, self::KEY, $data );
	}

	/**
	 * Callback for the 'delete_post' hook.
	 *
	 * Removes the deleted post's ID from both locked and protected arrays.
	 */
	public function update_option( $post_id ) {
		unset( $this->locked_post_ids[ $post_id ], $this->protected_post_ids[ $post_id ] );

		update_option( self::KEY, [
			'locked_post_ids' => $this->locked_post_ids,
			'protected_post_ids' => $this->protected_post_ids,
		] );
	}

	/**
	 * Callback for register_uninstall_hook() function. Must be static.
	 *
	 * Removes the plugin option from the database when it is uninstalled.
	 */
	public static function uninstall() {
		delete_option( self::KEY );
	}

	public function get_locked_post_ids() {
		return apply_filters( 'postlockdown_locked_posts', $this->locked_post_ids );
	}

	public function get_protected_post_ids() {
		return apply_filters( 'postlockdown_protected_posts', $this->protected_post_ids );
	}

	/**
	 * Returns whether there are any locked or protected posts set.
	 *
	 * @return bool
	 */
	public function have_posts() {
		return ( $this->get_locked_post_ids() || $this->get_protected_post_ids() );
	}

	/**
	 * Returns whether a post is locked.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool
	 */
	public function is_post_locked( $post_id ) {
		return isset( $this->get_locked_post_ids()[ $post_id ] );
	}

	/**
	 * Returns whether a post is protected.
	 *
	 * @param int $post_id The ID of the post to check.
	 * @return bool
	 */
	public function is_post_protected( $post_id ) {
		return isset( $this->get_protected_post_ids()[ $post_id ] );
	}

	/**
	 * Returns the required capability a user must have to bypass all
	 * locked and protected post restrictions. Defaults to 'manage_options'.
	 *
	 * Also serves as a callback for the 'option_page_capability_{slug}' hook.
	 *
	 * @return string The required capability.
	 */
	public function get_admin_cap() {
		return apply_filters( 'postlockdown_admin_capability', 'manage_options' );
	}

	/**
	 * Sets the arrays of locked and protected post IDs.
	 *
	 */
	private function load_options() {
		$options = get_option( self::KEY, [] );

		if ( ! empty( $options['locked_post_ids'] ) && is_array( $options['locked_post_ids'] ) ) {
			$this->locked_post_ids = $options['locked_post_ids'];
		}

		if ( ! empty( $options['protected_post_ids'] ) && is_array( $options['protected_post_ids'] ) ) {
			$this->protected_post_ids = $options['protected_post_ids'];
		}
	}

	/**
	 * Convenience wrapper for get_posts().
	 *
	 * @param array $args Array of args to merge with defaults passed to get_posts().
	 * @return array Array of posts.
	 */
	private function get_posts( $args = [] ) {
		$excluded_post_types = [ 'nav_menu_item', 'revision' ];

		if ( class_exists( 'WooCommerce' ) ) {
			$excluded_post_types = array_merge( $excluded_post_types, [
				'product_variation',
				'shop_order',
				'shop_coupon',
			] );
		}

		$excluded_post_types = apply_filters( 'postlockdown_excluded_post_types', $excluded_post_types );

		$defaults = [
			'post_type' => array_diff( get_post_types(), $excluded_post_types ),
			'post_status' => [ 'publish', 'pending', 'draft', 'future' ],
		];

		$args = wp_parse_args( $args, $defaults );

		return get_posts( apply_filters( 'postlockdown_get_posts', $args ) );
	}

	/**
	 * Convenience wrapper for PHP's filter_input() function.
	 *
	 * @param string $key Input key.
	 * @param string $data_type Input data type.
	 * @param int $type Type of input. INPUT_POST or INPUT_GET (Default).
	 * @param int $flags Additional flags to pass to filter_input().
	 * @return mixed Filtered input.
	 */
	private function filter_input( $key, $data_type = 'string', $type = INPUT_GET, $flags = 0 ) {
		switch ( $data_type ) {
			case 'int':
				$filter = FILTER_SANITIZE_NUMBER_INT;
				break;
			case 'float':
				$filter = FILTER_SANITIZE_NUMBER_FLOAT;
				$flags |= FILTER_FLAG_ALLOW_FRACTION | FILTER_FLAG_ALLOW_THOUSAND;
				break;
			default:
				$filter = FILTER_SANITIZE_STRING;
				$flags |= FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_LOW;
				break;
		}

		return filter_input( $type, $key, $filter, $flags );
	}
}
