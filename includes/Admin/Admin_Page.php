<?php
/**
 * Admin menu + React app mount, TEC events list integration and the missing-TEC notice.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Admin;

use Dev3\AiEventsCalendar\Core3\Core\Assets;
use Dev3\AiEventsCalendar\Core3\Core\Capabilities;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3EC_FILE ), array( $this, 'links' ) );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . Events::POST_TYPE, array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . Events::POST_TYPE, array( $this, 'handle_bulk' ), 10, 3 );
	}

	/**
	 * App URL.
	 *
	 * @param string $route Hash route.
	 * @param array  $query Extra query args.
	 * @return string
	 */
	public static function url( $route = '', array $query = array() ) {
		return add_query_arg( array_merge( array( 'page' => DEV3EC_SLUG ), $query ), admin_url( 'admin.php' ) ) . ( '' !== $route ? '#/' . ltrim( $route, '/' ) : '' );
	}

	/**
	 * Menu: own page + a shortcut under TEC's Events menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3EC_NAME, __( 'DEV3 AI Events', 'dev3-ai-events-calendar' ), Capabilities::generate(), DEV3EC_SLUG, array( $this, 'render' ), 'dashicons-superhero', 27 );
		add_submenu_page( DEV3EC_SLUG, DEV3EC_NAME, __( 'Dashboard', 'dev3-ai-events-calendar' ), Capabilities::generate(), DEV3EC_SLUG, array( $this, 'render' ) );
		if ( Events::active() ) {
			add_submenu_page( 'edit.php?post_type=' . Events::POST_TYPE, DEV3EC_NAME, __( 'DEV3 AI Writer', 'dev3-ai-events-calendar' ), Capabilities::generate(), self::url( 'events' ) );
		}
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3ec-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3EC_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3ec-admin-root" class="dev3ec-root dev3ec-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Plugin list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-events-calendar' ) . '</a>' );
		return $links;
	}

	/**
	 * "Write with DEV3 AI" row action on events.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( Events::POST_TYPE === $post->post_type && current_user_can( 'edit_post', $post->ID ) && Capabilities::can_generate() ) {
			$actions['dev3ec'] = '<a href="' . esc_url( self::url( 'event/' . $post->ID ) ) . '">' . esc_html__( 'Write with DEV3 AI', 'dev3-ai-events-calendar' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Bulk action on the events list.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		if ( Capabilities::can_generate() ) {
			$actions['dev3ec_generate'] = __( 'Generate with DEV3 AI', 'dev3-ai-events-calendar' );
		}
		return $actions;
	}

	/**
	 * Redirects the bulk action into the DEV3 bulk screen with the selection.
	 *
	 * @param string $redirect Redirect.
	 * @param string $action   Action.
	 * @param int[]  $ids      Posts.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'dev3ec_generate' !== $action ) {
			return $redirect;
		}
		return self::url( 'events', array( 'dev3ec_ids' => implode( ',', array_map( 'absint', (array) $ids ) ) ) );
	}

	/**
	 * Notice when The Events Calendar is missing.
	 */
	public static function missing_tec_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'DEV3 AI Event Writer needs The Events Calendar (free). Install and activate it to start writing.', 'dev3-ai-events-calendar' ) . '</p></div>';
	}
}
