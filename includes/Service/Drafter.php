<?php
/**
 * Draft lifecycle: generate (AI or template) → pending draft → accept (server or editor) / reject.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Service;

use Dev3\AiEventsCalendar\Ai\Writer;
use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Content\Blocks;
use Dev3\AiEventsCalendar\Content\Templates;
use Dev3\AiEventsCalendar\Data\Drafts;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Drafter.
 */
class Drafter {

	/**
	 * Creates a pending draft.
	 *
	 * @param int    $event_id Event.
	 * @param string $kind     Kind.
	 * @param array  $opts     Writer options.
	 * @param string $source   ai|template.
	 * @param int    $job_id   Bulk job.
	 * @return array|\WP_Error Draft.
	 */
	public static function create( $event_id, $kind, array $opts = array(), $source = 'ai', $job_id = 0 ) {
		if ( ! Events::is_event( $event_id ) ) {
			return new \WP_Error( 'dev3ec_bad_event', __( 'Event not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		if ( ! Assets::valid( $kind ) ) {
			return new \WP_Error( 'dev3ec_bad_kind', __( 'Unknown output type.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$meta = array(
			'model'  => '',
			'tokens' => 0,
			'cached' => false,
		);
		if ( 'template' === $source ) {
			$fields = Templates::draft( $event_id, $kind, $opts );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
		} else {
			$r = Writer::generate( $event_id, $kind, $opts );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$fields = $r['fields'];
			$meta   = array(
				'model'  => $r['model'],
				'tokens' => $r['tokens'],
				'cached' => $r['cached'],
			);
		}
		$id = Drafts::insert(
			array(
				'event_id'    => $event_id,
				'kind'        => $kind,
				'source'      => 'template' === $source ? 'template' : 'ai',
				'payload'     => $fields,
				'original'    => Assets::current( $event_id, $kind ),
				'options'     => Writer::options( $opts ),
				'source_hash' => Events::hash( $event_id ),
				'model'       => $meta['model'],
				'tokens'      => $meta['tokens'],
				'job_id'      => $job_id,
			)
		);
		Drafts::supersede( $event_id, $kind, $id );
		$draft           = Drafts::get( $id );
		$draft['cached'] = $meta['cached'];
		return $draft;
	}

	/**
	 * Accepts a draft.
	 *
	 * @param int   $id   Draft.
	 * @param array $args value (edited fields), mode (server|client), force, apply (replace|append).
	 * @return array|\WP_Error { draft, saved, blocks?, html? }
	 */
	public static function accept( $id, array $args = array() ) {
		$draft = Drafts::get( $id );
		if ( ! $draft ) {
			return new \WP_Error( 'dev3ec_no_draft', __( 'Draft not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $draft['status'] ) {
			return new \WP_Error( 'dev3ec_draft_done', __( 'This draft was already handled.', 'dev3-ai-events-calendar' ), array( 'status' => 409 ) );
		}
		if ( ! Events::is_event( $draft['event_id'] ) ) {
			return new \WP_Error( 'dev3ec_bad_event', __( 'Event not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		$mode = 'client' === ( $args['mode'] ?? 'server' ) ? 'client' : 'server';
		if ( 'server' === $mode && empty( $args['force'] ) && '' !== $draft['source_hash'] && Events::hash( $draft['event_id'] ) !== $draft['source_hash'] ) {
			return new \WP_Error( 'dev3ec_stale', __( 'The event changed after this draft was written. Accept anyway?', 'dev3-ai-events-calendar' ), array( 'status' => 409 ) );
		}
		$value = isset( $args['value'] ) && is_array( $args['value'] ) ? $args['value'] : $draft['payload'];
		$clean = Assets::clean( $draft['kind'], $value );
		if ( 'social' === $draft['kind'] ) {
			$clean = array_intersect_key( $clean, $draft['payload'] ) + $clean;
		}
		$out = array();
		if ( 'description' === $draft['kind'] && 'client' === $mode ) {
			// The editor inserts the blocks itself; the post is saved with the editor's Update button.
			$out['blocks']  = Blocks::to_blocks( $clean['body'] );
			$out['html']    = Blocks::to_html( $clean['body'] );
			$out['excerpt'] = $clean['excerpt'];
			$saved          = $clean;
		} else {
			$saved = Assets::save( $draft['event_id'], $draft['kind'], $clean, array( 'mode' => (string) ( $args['apply'] ?? 'replace' ) ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		Drafts::update(
			$id,
			array(
				'status'  => 'accepted',
				'payload' => $clean,
			)
		);
		/**
		 * Fires after a DEV3 draft was accepted.
		 *
		 * @param array  $draft Draft (before the update).
		 * @param array  $saved Saved value.
		 * @param string $mode  server|client.
		 */
		do_action( 'dev3ec_draft_accepted', $draft, $saved, $mode );
		$out['draft'] = Drafts::get( $id );
		$out['saved'] = $saved;
		return $out;
	}

	/**
	 * Rejects a draft.
	 *
	 * @param int $id Draft.
	 * @return array|\WP_Error
	 */
	public static function reject( $id ) {
		$draft = Drafts::get( $id );
		if ( ! $draft ) {
			return new \WP_Error( 'dev3ec_no_draft', __( 'Draft not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		if ( 'pending' === $draft['status'] ) {
			Drafts::update( $id, array( 'status' => 'rejected' ) );
		}
		return Drafts::get( $id );
	}
}
