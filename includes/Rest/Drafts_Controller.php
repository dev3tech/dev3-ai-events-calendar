<?php
/**
 * REST: review queue (list, accept, reject, bulk accept/reject).
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Rest;

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Core3\Rest\Base_Controller;
use Dev3\AiEventsCalendar\Data\Drafts;
use Dev3\AiEventsCalendar\Service\Drafter;

defined( 'ABSPATH' ) || exit;

/**
 * Drafts controller.
 */
class Drafts_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/drafts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_drafts' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => array( 'pending', 'accepted', 'rejected', 'all' ),
						'default' => 'pending',
					),
					'kind'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'event_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'job_id'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/drafts/(?P<id>\d+)/accept',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept' ),
				'permission_callback' => array( $this, 'can_edit_draft' ),
				'args'                => array(
					'value' => array(
						'type'    => 'object',
						'default' => null,
					),
					'mode'  => array(
						'type'    => 'string',
						'enum'    => array( 'server', 'client' ),
						'default' => 'server',
					),
					'apply' => array(
						'type'    => 'string',
						'enum'    => array( 'replace', 'append' ),
						'default' => 'replace',
					),
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/drafts/(?P<id>\d+)/reject',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => array( $this, 'can_edit_draft' ),
			)
		);
		register_rest_route(
			self::NS,
			'/drafts/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => array( $this, 'can_generate' ),
				'args'                => array(
					'ids'    => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
					'action' => array(
						'type'     => 'string',
						'enum'     => array( 'accept', 'reject' ),
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission: nonce + generate + edit_post on the draft's event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_draft( \WP_REST_Request $request ) {
		$ok = $this->can_generate( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$draft = Drafts::get( (int) $request['id'] );
		if ( ! $draft ) {
			return new \WP_Error( 'dev3ec_no_draft', __( 'Draft not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $draft['event_id'] ) ) {
			return new \WP_Error( 'dev3ec_forbidden', __( 'You do not have permission to edit this event.', 'dev3-ai-events-calendar' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * GET /drafts (only events the user can edit are shown).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_drafts( \WP_REST_Request $request ) {
		$q = $request->get_params();
		if ( ! empty( $q['kind'] ) && ! Assets::valid( (string) $q['kind'] ) ) {
			$q['kind'] = '';
		}
		$r          = Drafts::query( $q );
		$r['items'] = array_values( array_filter( $r['items'], static fn( $d ) => current_user_can( 'edit_post', $d['event_id'] ) ) );
		return $this->respond( $r );
	}

	/**
	 * POST /drafts/<id>/accept.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function accept( \WP_REST_Request $request ) {
		return $this->respond(
			Drafter::accept(
				(int) $request['id'],
				array(
					'value' => is_array( $request['value'] ) ? $request['value'] : null,
					'mode'  => (string) $request['mode'],
					'apply' => (string) $request['apply'],
					'force' => (bool) $request['force'],
				)
			)
		);
	}

	/**
	 * POST /drafts/<id>/reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reject( \WP_REST_Request $request ) {
		return $this->respond( Drafter::reject( (int) $request['id'] ) );
	}

	/**
	 * POST /drafts/bulk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$done   = 0;
		$errors = array();
		foreach ( array_slice( array_map( 'absint', (array) $request['ids'] ), 0, 100 ) as $id ) {
			$draft = Drafts::get( $id );
			if ( ! $draft || ! current_user_can( 'edit_post', $draft['event_id'] ) ) {
				$errors[] = array(
					'id'      => $id,
					'message' => __( 'Not allowed.', 'dev3-ai-events-calendar' ),
				);
				continue;
			}
			$r = 'accept' === $request['action'] ? Drafter::accept( $id, array( 'force' => true ) ) : Drafter::reject( $id );
			if ( is_wp_error( $r ) ) {
				$errors[] = array(
					'id'      => $id,
					'message' => $r->get_error_message(),
				);
			} else {
				++$done;
			}
		}
		return $this->respond(
			array(
				'done'   => $done,
				'errors' => $errors,
			)
		);
	}
}
