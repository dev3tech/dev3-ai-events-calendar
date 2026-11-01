<?php
/**
 * TEC event editor integration: the "DEV3 AI Event Writer" sidebar in the block editor and a
 * metabox with the same panels in the classic editor.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Admin;

use Dev3\AiEventsCalendar\Core3\Core\Assets;
use Dev3\AiEventsCalendar\Core3\Core\Capabilities;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Editor.
 */
class Editor {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor' ) );
		add_action( 'add_meta_boxes_' . Events::POST_TYPE, array( $this, 'metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'classic_assets' ) );
	}

	/**
	 * Whether the current screen edits an event the user may write for.
	 *
	 * @return bool
	 */
	private function on_event_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'post' === $screen->base && Events::POST_TYPE === $screen->post_type && Capabilities::can_generate();
	}

	/**
	 * Block editor sidebar bundle.
	 */
	public function block_editor() {
		if ( $this->on_event_screen() ) {
			Assets::enqueue( 'editor' );
		}
	}

	/**
	 * Classic editor metabox (not added in the block editor, which uses the sidebar).
	 *
	 * @param \WP_Post $post Post.
	 */
	public function metabox( $post ) {
		if ( ! Capabilities::can_generate() || ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) ) {
			return;
		}
		add_meta_box( 'dev3ec-writer', __( 'DEV3 AI Event Writer', 'dev3-ai-events-calendar' ), array( $this, 'render_metabox' ), Events::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Metabox mount.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_metabox( $post ) {
		echo '<div id="dev3ec-metabox-root" class="dev3ec-root dev3ec-metabox" data-event="' . esc_attr( (string) $post->ID ) . '"></div>';
	}

	/**
	 * Classic editor bundle.
	 *
	 * @param string $hook Hook.
	 */
	public function classic_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $this->on_event_screen() ) {
			return;
		}
		$post = get_post();
		if ( $post && function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}
		Assets::enqueue( 'metabox' );
	}
}
