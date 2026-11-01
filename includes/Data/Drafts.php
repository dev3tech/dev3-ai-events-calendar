<?php
/**
 * Draft store: every AI (or template) output waits here until it is accepted or rejected.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Drafts table.
 */
class Drafts {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3ec_drafts';
	}

	/**
	 * CREATE TABLE statements (dev3ec_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		global $wpdb;
		$sql[] = 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(32) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'pending',
			source varchar(16) NOT NULL DEFAULT 'ai',
			payload longtext NULL,
			original longtext NULL,
			options text NULL,
			source_hash varchar(32) NOT NULL DEFAULT '',
			model varchar(80) NOT NULL DEFAULT '',
			tokens int(10) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY event_kind (event_id, kind),
			KEY status (status)
		) {$charset};";
		$sql[] = 'CREATE TABLE ' . $wpdb->prefix . "dev3ec_imports (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(16) NOT NULL DEFAULT 'queued',
			mode varchar(8) NOT NULL DEFAULT 'ai',
			draft longtext NULL,
			error text NULL,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset};";
		return $sql;
	}

	/**
	 * Inserts a draft.
	 *
	 * @param array $d event_id, kind, payload, original, options, source, source_hash, model, tokens, job_id.
	 * @return int
	 */
	public static function insert( array $d ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table(),
			array(
				'event_id'    => (int) $d['event_id'],
				'kind'        => (string) $d['kind'],
				'status'      => 'pending',
				'source'      => (string) ( $d['source'] ?? 'ai' ),
				'payload'     => wp_json_encode( $d['payload'] ?? array() ),
				'original'    => wp_json_encode( $d['original'] ?? array() ),
				'options'     => wp_json_encode( $d['options'] ?? array() ),
				'source_hash' => (string) ( $d['source_hash'] ?? '' ),
				'model'       => mb_substr( (string) ( $d['model'] ?? '' ), 0, 80 ),
				'tokens'      => (int) ( $d['tokens'] ?? 0 ),
				'job_id'      => (int) ( $d['job_id'] ?? 0 ),
				'user_id'     => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads a draft.
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
	 * Updates a draft.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns (payload/original/options arrays are encoded).
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		foreach ( array( 'payload', 'original', 'options' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				$data[ $k ] = wp_json_encode( $data[ $k ] );
			}
		}
		$data['updated_at'] = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Marks older pending drafts of the same event + kind as rejected (a new draft replaces them).
	 *
	 * @param int    $event_id Event.
	 * @param string $kind     Kind.
	 * @param int    $keep     Draft to keep.
	 */
	public static function supersede( $event_id, $kind, $keep ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'rejected', updated_at = %s WHERE event_id = %d AND kind = %s AND status = 'pending' AND id <> %d", current_time( 'mysql', true ), (int) $event_id, (string) $kind, (int) $keep ) );
	}

	/**
	 * Lists drafts.
	 *
	 * @param array $q status, kind, event_id, page, per_page.
	 * @return array { items, total }
	 */
	public static function query( array $q ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $q['status'] ) && 'all' !== $q['status'] ) {
			$where[] = 'status = %s';
			$args[]  = (string) $q['status'];
		}
		if ( ! empty( $q['kind'] ) ) {
			$where[] = 'kind = %s';
			$args[]  = (string) $q['kind'];
		}
		if ( ! empty( $q['event_id'] ) ) {
			$where[] = 'event_id = %d';
			$args[]  = (int) $q['event_id'];
		}
		if ( ! empty( $q['job_id'] ) ) {
			$where[] = 'job_id = %d';
			$args[]  = (int) $q['job_id'];
		}
		$per   = max( 1, min( 100, (int) ( $q['per_page'] ?? 20 ) ) );
		$page  = max( 1, (int) ( $q['page'] ?? 1 ) );
		$sql_w = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$sql_w}", $args ) : 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$sql_w}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$sql_w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items' => array_map( array( self::class, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counts by status (dashboard).
	 *
	 * @return array status => count, plus accepted_month.
	 */
	public static function counts() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A );
		$month = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'accepted' AND updated_at >= %s", gmdate( 'Y-m-01 00:00:00' ) ) );
		// phpcs:enable
		$out = array(
			'pending'        => 0,
			'accepted'       => 0,
			'rejected'       => 0,
			'accepted_month' => $month,
		);
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Pending drafts of an event.
	 *
	 * @param int $event_id Event.
	 * @return array
	 */
	public static function pending_for( $event_id ) {
		return self::query(
			array(
				'event_id' => $event_id,
				'status'   => 'pending',
				'per_page' => 50,
			)
		)['items'];
	}

	/**
	 * Row shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$title = get_the_title( (int) $row['event_id'] );
		return array(
			'id'          => (int) $row['id'],
			'event_id'    => (int) $row['event_id'],
			'event_title' => html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ),
			'kind'        => (string) $row['kind'],
			'status'      => (string) $row['status'],
			'source'      => (string) $row['source'],
			'payload'     => (array) json_decode( (string) $row['payload'], true ),
			'original'    => (array) json_decode( (string) $row['original'], true ),
			'options'     => (array) json_decode( (string) $row['options'], true ),
			'source_hash' => (string) $row['source_hash'],
			'model'       => (string) $row['model'],
			'tokens'      => (int) $row['tokens'],
			'job_id'      => (int) $row['job_id'],
			'user_id'     => (int) $row['user_id'],
			'created_at'  => str_replace( ' ', 'T', (string) $row['created_at'] ) . 'Z',
			'updated_at'  => str_replace( ' ', 'T', (string) $row['updated_at'] ) . 'Z',
		);
	}

	/**
	 * Deletes drafts of a deleted event.
	 *
	 * @param int $post_id Post.
	 */
	public static function delete_for_event( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'event_id' => (int) $post_id ) );
	}
}
