<?php
/**
 * Bulk generation: events × outputs as a background job that fills the review queue.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Service;

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Core3\Api\Ai;
use Dev3\AiEventsCalendar\Core3\Core\Jobs;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk.
 */
class Bulk {

	const JOB = 'dev3ec_bulk';

	const MAX_ITEMS = 500;

	/**
	 * Registers the handler (every request).
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_chunk' ) );
	}

	/**
	 * Queues a run.
	 *
	 * @param int[]    $ids          Events.
	 * @param string[] $kinds        Kinds.
	 * @param array    $opts         Writer options.
	 * @param bool     $only_missing Skip events that already have the output.
	 * @param string   $source       ai|template.
	 * @return array|\WP_Error Job.
	 */
	public static function start( array $ids, array $kinds, array $opts, $only_missing = false, $source = 'ai' ) {
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ), array( Events::class, 'is_event' ) ) ) );
		$ids   = array_values( array_filter( $ids, static fn( $id ) => current_user_can( 'edit_post', $id ) ) );
		$kinds = array_values( array_intersect( Assets::KINDS, $kinds ) );
		if ( ! $ids || ! $kinds ) {
			return new \WP_Error( 'dev3ec_bulk_empty', __( 'Pick at least one event and one output.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( 'ai' === $source && ! Ai::enabled( 'admin' ) ) {
			return new \WP_Error( 'dev3ec_ai_disabled', __( 'Connect DEV3 AI first (API Config), or use templates instead.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$items = array();
		foreach ( $ids as $id ) {
			foreach ( $kinds as $k ) {
				$items[] = $id . ':' . $k;
			}
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return new \WP_Error(
				'dev3ec_bulk_big',
				/* translators: %d: max items */
				sprintf( __( 'Too many outputs in one run (max %d). Select fewer events.', 'dev3-ai-events-calendar' ), self::MAX_ITEMS ),
				array( 'status' => 400 )
			);
		}
		$job = Jobs::create(
			self::JOB,
			$items,
			array(
				'options'      => $opts,
				'only_missing' => (bool) $only_missing,
				'source'       => 'template' === $source ? 'template' : 'ai',
			),
			1
		);
		return is_wp_error( $job ) ? $job : Jobs::get( $job );
	}

	/**
	 * Job handler.
	 *
	 * @param array $items "id:kind" items.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$args = (array) ( $job['args'] ?? array() );
		$out  = array();
		if ( ! empty( $job['user_id'] ) && ! get_current_user_id() ) {
			wp_set_current_user( (int) $job['user_id'] );
		}
		foreach ( $items as $item ) {
			list( $id, $kind ) = array_pad( explode( ':', (string) $item, 2 ), 2, '' );
			$id                = (int) $id;
			if ( ! empty( $args['only_missing'] ) && Assets::has( $id, $kind ) ) {
				$out[ $item ] = true;
				continue;
			}
			$r            = Drafter::create( $id, $kind, (array) ( $args['options'] ?? array() ), (string) ( $args['source'] ?? 'ai' ), (int) $job['id'] );
			$out[ $item ] = is_wp_error( $r ) ? get_the_title( $id ) . ': ' . $r->get_error_message() : true;
		}
		return $out;
	}
}
