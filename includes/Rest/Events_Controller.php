<?php
/**
 * REST: status, events (bulk list + workspace), generate/template drafts, manual saves, sending,
 * bulk runs and venue/organizer lookups.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Rest;

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Content\Social;
use Dev3\AiEventsCalendar\Content\Templates;
use Dev3\AiEventsCalendar\Core3\Api\Ai;
use Dev3\AiEventsCalendar\Core3\Core\Capabilities;
use Dev3\AiEventsCalendar\Core3\Rest\Base_Controller;
use Dev3\AiEventsCalendar\Data\Drafts;
use Dev3\AiEventsCalendar\Data\Imports;
use Dev3\AiEventsCalendar\Seo\Seo;
use Dev3\AiEventsCalendar\Service\Bulk;
use Dev3\AiEventsCalendar\Service\Drafter;
use Dev3\AiEventsCalendar\Service\Mailer;
use Dev3\AiEventsCalendar\Settings;
use Dev3\AiEventsCalendar\Tec\Attendees;
use Dev3\AiEventsCalendar\Tec\Events;
use Dev3\AiEventsCalendar\Tec\Places;

defined( 'ABSPATH' ) || exit;

/**
 * Events controller.
 */
class Events_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$gen  = array( $this, 'can_generate' );
		$edit = array( $this, 'can_edit_event' );
		$kind = array(
			'type'     => 'string',
			'enum'     => Assets::KINDS,
			'required' => true,
		);
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $gen,
			)
		);
		register_rest_route(
			self::NS,
			'/events',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => $gen,
				'args'                => array(
					'scope'    => array(
						'type'    => 'string',
						'enum'    => array( 'upcoming', 'past', 'all' ),
						'default' => 'upcoming',
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'category' => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'missing'  => array(
						'type'    => 'string',
						'default' => '',
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
			'/events/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_event' ),
				'permission_callback' => $edit,
			)
		);
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => $edit,
				'args'                => array(
					'kind'    => $kind,
					'options' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/template',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'template' ),
				'permission_callback' => $edit,
				'args'                => array(
					'kind'    => $kind,
					'options' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/assets',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_asset' ),
				'permission_callback' => $edit,
				'args'                => array(
					'kind'  => $kind,
					'value' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/send',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $edit,
				'args'                => array(
					'kind'   => array(
						'type'     => 'string',
						'enum'     => Assets::EMAILS,
						'required' => true,
					),
					'target' => array(
						'type'     => 'string',
						'enum'     => array( 'test', 'attendees' ),
						'required' => true,
					),
					'value'  => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $gen,
				'args'                => array(
					'ids'          => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
					'kinds'        => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
					'options'      => array(
						'type'    => 'object',
						'default' => array(),
					),
					'only_missing' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'source'       => array(
						'type'    => 'string',
						'enum'    => array( 'ai', 'template' ),
						'default' => 'ai',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/places',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'places' ),
				'permission_callback' => $gen,
				'args'                => array(
					'type'   => array(
						'type'    => 'string',
						'enum'    => array( 'venue', 'organizer' ),
						'default' => 'venue',
					),
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Permission: nonce + generate capability + edit_post on a TEC event.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_event( \WP_REST_Request $request ) {
		$ok = $this->can_generate( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$id = (int) $request['id'];
		if ( ! Events::is_event( $id ) ) {
			return new \WP_Error( 'dev3ec_bad_event', __( 'Event not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'dev3ec_forbidden', __( 'You do not have permission to edit this event.', 'dev3-ai-events-calendar' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * GET /status.
	 *
	 * @return \WP_REST_Response
	 */
	public function status() {
		$nets = array();
		foreach ( Social::NETWORKS as $id => $n ) {
			$nets[] = array_merge( array( 'id' => $id ), $n );
		}
		$upcoming = Events::query(
			array(
				'scope'    => 'upcoming',
				'per_page' => 1,
			)
		);
		$missing  = Events::query(
			array(
				'scope'    => 'upcoming',
				'missing'  => 'description',
				'per_page' => 5,
			)
		);
		return $this->respond(
			array(
				'ai'            => Ai::status(),
				'tec'           => Events::active(),
				'tickets'       => Attendees::active(),
				'blocks'        => Events::blocks_on(),
				'seo'           => array(
					'adapter' => Seo::adapter(),
					'label'   => Seo::label(),
				),
				'networks'      => $nets,
				'tones'         => Settings::TONES,
				'defaults'      => array(
					'tone'     => Settings::tone(),
					'language' => (string) Settings::get( 'default_language', '' ),
					'networks' => Social::networks(),
				),
				'categories'    => Events::categories(),
				'counts'        => array(
					'upcoming'            => $upcoming['total'],
					'missing_description' => $missing['total'],
					'drafts'              => Drafts::counts(),
				),
				'needs_content' => array_map( array( $this, 'row' ), $missing['items'] ),
				'imports'       => array_slice( Imports::recent( 5 ), 0, 5 ),
				'tags'          => Templates::TAGS,
				'canManage'     => Capabilities::can_manage(),
				'canUpload'     => current_user_can( 'upload_files' ),
			)
		);
	}

	/**
	 * Table row of an event.
	 *
	 * @param int $id Event.
	 * @return array
	 */
	public function row( $id ) {
		$e = Events::get( $id );
		if ( ! $e ) {
			return array( 'id' => (int) $id );
		}
		$has = array();
		foreach ( Assets::KINDS as $k ) {
			$has[ $k ] = Assets::has( $id, $k );
		}
		return array(
			'id'         => $e['id'],
			'title'      => $e['title'],
			'status'     => $e['status'],
			'date_label' => $e['date_label'],
			'time_label' => $e['time_label'],
			'start_ts'   => $e['start_ts'],
			'past'       => $e['past'],
			'venue'      => $e['venue'] ? $e['venue']['name'] : '',
			'categories' => $e['categories'],
			'edit_url'   => $e['edit_url'],
			'url'        => $e['url'],
			'thumbnail'  => $e['thumbnail'],
			'has'        => $has,
			'pending'    => count( Drafts::pending_for( $id ) ),
		);
	}

	/**
	 * GET /events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_events( \WP_REST_Request $request ) {
		$r = Events::query( $request->get_params() );
		return $this->respond(
			array(
				'items' => array_map( array( $this, 'row' ), $r['items'] ),
				'total' => $r['total'],
			)
		);
	}

	/**
	 * GET /events/<id>: workspace snapshot.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_event( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$e  = Events::get( $id );
		unset( $e['content'] );
		return $this->respond(
			array(
				'event'      => $e,
				'assets'     => Assets::all( $id ),
				'attendees'  => Attendees::counts( $id ),
				'recipients' => count( Attendees::recipients( $id ) ),
				'tickets'    => Attendees::tickets( $id ),
				'pending'    => Drafts::pending_for( $id ),
				'sends'      => Mailer::history( $id ),
				'seo'        => array(
					'adapter' => Seo::adapter(),
					'label'   => Seo::label(),
				),
			)
		);
	}

	/**
	 * POST /events/<id>/generate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function generate( \WP_REST_Request $request ) {
		$r = Drafter::create( (int) $request['id'], (string) $request['kind'], (array) $request['options'], 'ai' );
		return $this->respond( $r, 201 );
	}

	/**
	 * POST /events/<id>/template.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function template( \WP_REST_Request $request ) {
		$r = Drafter::create( (int) $request['id'], (string) $request['kind'], (array) $request['options'], 'template' );
		return $this->respond( $r, 201 );
	}

	/**
	 * POST /events/<id>/assets: manual save.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_asset( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$saved = Assets::save( $id, (string) $request['kind'], (array) $request['value'] );
		if ( is_wp_error( $saved ) ) {
			return $this->respond( $saved );
		}
		return $this->respond(
			array(
				'saved'  => $saved,
				'assets' => Assets::all( $id ),
			)
		);
	}

	/**
	 * POST /events/<id>/send.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function send( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$kind = (string) $request['kind'];
		if ( 'test' === $request['target'] ) {
			return $this->respond( Mailer::test( $id, $kind, (array) $request['value'] ) );
		}
		if ( ! Capabilities::can_manage() ) {
			return $this->respond( new \WP_Error( 'dev3ec_forbidden', __( 'Only administrators can email attendees.', 'dev3-ai-events-calendar' ), array( 'status' => 403 ) ) );
		}
		return $this->respond( Mailer::to_attendees( $id, $kind ), 201 );
	}

	/**
	 * POST /bulk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$r = Bulk::start( (array) $request['ids'], array_map( 'strval', (array) $request['kinds'] ), (array) $request['options'], (bool) $request['only_missing'], (string) $request['source'] );
		return $this->respond( $r, 201 );
	}

	/**
	 * GET /places.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function places( \WP_REST_Request $request ) {
		return $this->respond( Places::search( (string) $request['type'], (string) $request['search'], 100 ) );
	}
}
