<?php
/**
 * REST: flyer imports (upload → AI or manual draft → review → create draft event).
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Rest;

use Dev3\AiEventsCalendar\Core3\Rest\Base_Controller;
use Dev3\AiEventsCalendar\Data\Imports;
use Dev3\AiEventsCalendar\Service\Importer;

defined( 'ABSPATH' ) || exit;

/**
 * Imports controller.
 */
class Imports_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/imports',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_imports' ),
					'permission_callback' => array( $this, 'can_import' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_import' ),
					'args'                => array(
						'attachment_id' => array(
							'type'    => 'integer',
							'default' => 0,
						),
						'mode'          => array(
							'type'    => 'string',
							'enum'    => array( 'ai', 'manual' ),
							'default' => 'ai',
						),
						'notes'         => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/imports/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_import' ),
					'permission_callback' => array( $this, 'can_import' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_import' ),
					'permission_callback' => array( $this, 'can_import' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/imports/(?P<id>\d+)/create',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_event' ),
				'permission_callback' => array( $this, 'can_import' ),
				'args'                => array(
					'fields' => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Permission: nonce + generate + create events + upload files.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_import( \WP_REST_Request $request ) {
		$ok = $this->can_generate( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$type = get_post_type_object( 'tribe_events' );
		$cap  = $type ? $type->cap->edit_posts : 'edit_posts';
		if ( ! current_user_can( $cap ) || ! current_user_can( 'upload_files' ) ) {
			return new \WP_Error( 'dev3ec_forbidden', __( 'You need permission to create events and upload files.', 'dev3-ai-events-calendar' ), array( 'status' => rest_authorization_required_code() ) );
		}
		$id = (int) $request['id'];
		if ( $id ) {
			$import = Imports::get( $id );
			if ( ! $import ) {
				return new \WP_Error( 'dev3ec_no_import', __( 'Import not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
			}
			if ( get_current_user_id() !== $import['user_id'] && ! current_user_can( 'edit_others_posts' ) ) {
				return new \WP_Error( 'dev3ec_forbidden', __( 'This import belongs to another user.', 'dev3-ai-events-calendar' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * GET /imports.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_imports() {
		$rows = array_values( array_filter( Imports::recent( 30 ), static fn( $r ) => get_current_user_id() === $r['user_id'] || current_user_can( 'edit_others_posts' ) ) );
		return $this->respond( $rows );
	}

	/**
	 * POST /imports: multipart `file` (or an existing `attachment_id`).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create( \WP_REST_Request $request ) {
		$aid   = (int) $request['attachment_id'];
		$files = $request->get_file_params();
		if ( ! empty( $files['file'] ) && is_array( $files['file'] ) ) {
			$aid = $this->upload( $files['file'] );
			if ( is_wp_error( $aid ) ) {
				return $this->respond( $aid );
			}
		} elseif ( $aid && ! current_user_can( 'edit_post', $aid ) ) {
			return $this->respond( new \WP_Error( 'dev3ec_forbidden', __( 'You cannot use that file.', 'dev3-ai-events-calendar' ), array( 'status' => 403 ) ) );
		}
		return $this->respond( Importer::create( $aid, (string) $request['mode'], (string) $request['notes'] ), 201 );
	}

	/**
	 * Stores an uploaded flyer in the media library.
	 *
	 * @param array $file $_FILES entry.
	 * @return int|\WP_Error Attachment.
	 */
	private function upload( array $file ) {
		if ( ! empty( $file['error'] ) ) {
			return new \WP_Error( 'dev3ec_upload', __( 'The upload failed. Try a smaller file.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > Importer::MAX_BYTES ) {
			return new \WP_Error( 'dev3ec_import_size', __( 'The file is too large (15 MB max).', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], sanitize_file_name( (string) $file['name'] ) );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], Importer::MIMES, true ) ) {
			return new \WP_Error( 'dev3ec_import_type', __( 'Use a JPG, PNG or WebP image, or a PDF.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$aid = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( (string) $file['name'] ),
				'tmp_name' => (string) $file['tmp_name'],
				'size'     => (int) ( $file['size'] ?? 0 ),
				'error'    => 0,
			),
			0,
			null,
			array( 'post_title' => sanitize_text_field( pathinfo( (string) $file['name'], PATHINFO_FILENAME ) ) )
		);
		return is_wp_error( $aid ) ? new \WP_Error( 'dev3ec_upload', $aid->get_error_message(), array( 'status' => 400 ) ) : (int) $aid;
	}

	/**
	 * GET /imports/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_import( \WP_REST_Request $request ) {
		return $this->respond( Imports::get( (int) $request['id'] ) );
	}

	/**
	 * DELETE /imports/<id> (the flyer stays in the media library).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_import( \WP_REST_Request $request ) {
		Imports::delete( (int) $request['id'] );
		return $this->respond( array( 'deleted' => true ) );
	}

	/**
	 * POST /imports/<id>/create.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_event( \WP_REST_Request $request ) {
		return $this->respond( Importer::create_event( (int) $request['id'], (array) $request['fields'] ), 201 );
	}
}
