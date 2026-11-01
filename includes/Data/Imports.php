<?php
/**
 * Flyer imports table (created by Drafts::schema).
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Imports.
 */
class Imports {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3ec_imports';
	}

	/**
	 * Inserts.
	 *
	 * @param array $d attachment_id, status, mode, draft.
	 * @return int
	 */
	public static function insert( array $d ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'attachment_id' => (int) $d['attachment_id'],
				'status'        => (string) ( $d['status'] ?? 'queued' ),
				'mode'          => (string) ( $d['mode'] ?? 'ai' ),
				'draft'         => wp_json_encode( $d['draft'] ?? array() ),
				'error'         => '',
				'user_id'       => get_current_user_id(),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Updates.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		if ( isset( $data['draft'] ) && is_array( $data['draft'] ) ) {
			$data['draft'] = wp_json_encode( $data['draft'] );
		}
		$data['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Deletes.
	 *
	 * @param int $id Id.
	 */
	public static function delete( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Recent imports.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function recent( $limit = 20 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ), ARRAY_A );
		return array_map( array( self::class, 'shape' ), (array) $rows );
	}

	/**
	 * Shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$aid  = (int) $row['attachment_id'];
		$mime = (string) get_post_mime_type( $aid );
		return array(
			'id'            => (int) $row['id'],
			'attachment_id' => $aid,
			'file_name'     => $aid ? wp_basename( (string) get_attached_file( $aid ) ) : '',
			'file_url'      => $aid ? (string) wp_get_attachment_url( $aid ) : '',
			'preview_url'   => $aid && 0 === strpos( $mime, 'image/' ) ? (string) wp_get_attachment_image_url( $aid, 'large' ) : '',
			'mime'          => $mime,
			'status'        => (string) $row['status'],
			'mode'          => (string) $row['mode'],
			'draft'         => (array) json_decode( (string) $row['draft'], true ),
			'error'         => (string) $row['error'],
			'job_id'        => (int) $row['job_id'],
			'event_id'      => (int) $row['event_id'],
			'event_edit'    => $row['event_id'] ? (string) get_edit_post_link( (int) $row['event_id'], 'raw' ) : '',
			'user_id'       => (int) $row['user_id'],
			'created_at'    => str_replace( ' ', 'T', (string) $row['created_at'] ) . 'Z',
		);
	}
}
