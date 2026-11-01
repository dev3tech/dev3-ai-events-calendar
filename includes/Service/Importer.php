<?php
/**
 * A7: event import from a flyer/poster (image or PDF, Gemini vision) into a reviewed draft event,
 * with venue and organizer matching. Manual mode (AI off) uses the same review form.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Service;

use Dev3\AiEventsCalendar\Content\Blocks;
use Dev3\AiEventsCalendar\Core3\Api\Ai;
use Dev3\AiEventsCalendar\Core3\Api\Prompts;
use Dev3\AiEventsCalendar\Core3\Core\Jobs;
use Dev3\AiEventsCalendar\Data\Imports;
use Dev3\AiEventsCalendar\Settings;
use Dev3\AiEventsCalendar\Tec\Events;
use Dev3\AiEventsCalendar\Tec\Places;

defined( 'ABSPATH' ) || exit;

/**
 * Importer.
 */
class Importer {

	const JOB = 'dev3ec_flyer_import';

	const MIMES = array( 'image/jpeg', 'image/png', 'image/webp', 'application/pdf' );

	const MAX_BYTES = 15728640;

	/**
	 * Registers the job handler.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_job' ) );
	}

	/**
	 * Validates an attachment.
	 *
	 * @param int $aid Attachment.
	 * @return true|\WP_Error
	 */
	public static function validate( $aid ) {
		$file = $aid ? get_attached_file( $aid ) : '';
		if ( ! $file || ! is_readable( $file ) ) {
			return new \WP_Error( 'dev3ec_import_file', __( 'Add a flyer: a JPG, PNG or WebP image, or a PDF.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( (string) get_post_mime_type( $aid ), self::MIMES, true ) ) {
			return new \WP_Error( 'dev3ec_import_type', __( 'Use a JPG, PNG or WebP image, or a PDF.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( filesize( $file ) > self::MAX_BYTES ) {
			return new \WP_Error( 'dev3ec_import_size', __( 'The file is too large (15 MB max).', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Creates an import from an uploaded attachment.
	 *
	 * @param int    $aid   Attachment.
	 * @param string $mode  ai|manual.
	 * @param string $notes Notes for the model.
	 * @return array|\WP_Error Import.
	 */
	public static function create( $aid, $mode = 'ai', $notes = '' ) {
		$ok = self::validate( (int) $aid );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$mode = 'manual' === $mode ? 'manual' : 'ai';
		if ( 'ai' === $mode && ! Ai::enabled( 'admin' ) ) {
			return new \WP_Error( 'dev3ec_ai_disabled', __( 'Connect DEV3 AI first (API Config), or fill the event in by hand.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$id = Imports::insert(
			array(
				'attachment_id' => (int) $aid,
				'status'        => 'manual' === $mode ? 'ready' : 'queued',
				'mode'          => $mode,
				'draft'         => 'manual' === $mode ? self::with_matches( self::normalize( array() ) ) : array( 'notes' => mb_substr( sanitize_textarea_field( (string) $notes ), 0, 500 ) ),
			)
		);
		if ( 'ai' === $mode ) {
			$job = Jobs::create( self::JOB, array( $id ), array(), 1 );
			if ( is_wp_error( $job ) ) {
				Imports::update(
					$id,
					array(
						'status' => 'failed',
						'error'  => $job->get_error_message(),
					)
				);
				return $job;
			}
			Imports::update( $id, array( 'job_id' => (int) $job ) );
		}
		return Imports::get( $id );
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Import ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_job( array $items, array $job ) {
		unset( $job );
		$out = array();
		foreach ( $items as $id ) {
			$r          = self::process( (int) $id );
			$out[ $id ] = is_wp_error( $r ) ? $r->get_error_message() : true;
		}
		return $out;
	}

	/**
	 * Runs the vision call and stores the normalised draft with matches.
	 *
	 * @param int $id Import.
	 * @return true|\WP_Error
	 */
	public static function process( $id ) {
		$import = Imports::get( $id );
		if ( ! $import || 'queued' !== $import['status'] ) {
			return true;
		}
		$path = get_attached_file( $import['attachment_id'] );
		if ( ! $path || ! is_readable( $path ) ) {
			$err = new \WP_Error( 'dev3ec_import_file', __( 'The uploaded flyer is missing.', 'dev3-ai-events-calendar' ) );
		} else {
			$result = Prompts::run(
				'flyer_import',
				array(
					'today'    => wp_date( 'Y-m-d' ),
					'timezone' => wp_timezone_string(),
					'notes'    => '' !== (string) ( $import['draft']['notes'] ?? '' ) ? (string) $import['draft']['notes'] : '-',
				),
				'admin',
				array(
					'images' => array(
						array(
							'mime' => $import['mime'],
							'data' => base64_encode( (string) file_get_contents( $path ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
						),
					),
				)
			);
			$err    = is_wp_error( $result ) ? $result : null;
		}
		if ( $err ) {
			Imports::update(
				$id,
				array(
					'status' => 'failed',
					'error'  => $err->get_error_message(),
				)
			);
			return $err;
		}
		$draft           = self::with_matches( self::normalize( (array) ( $result['data'] ?? array() ) ) );
		$draft['model']  = (string) ( $result['model'] ?? '' );
		$draft['tokens'] = (int) ( $result['usage']['total'] ?? 0 );
		Imports::update(
			$id,
			array(
				'status' => 'ready',
				'draft'  => $draft,
				'error'  => '',
			)
		);
		return true;
	}

	/**
	 * Valid Y-m-d or ''.
	 *
	 * @param string $d Date.
	 * @return string
	 */
	public static function date( $d ) {
		$d = trim( (string) $d );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $d;
	}

	/**
	 * Valid H:i or ''.
	 *
	 * @param string $t Time.
	 * @return string
	 */
	public static function time( $t ) {
		$t = trim( (string) $t );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $t, $m ) || (int) $m[1] > 23 || (int) $m[2] > 59 ) {
			return '';
		}
		return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
	}

	/**
	 * Normalises AI (or manual) data into the review form shape.
	 *
	 * @param array $d Data.
	 * @return array
	 */
	public static function normalize( array $d ) {
		$t     = static fn( $v, $max ) => mb_substr( sanitize_text_field( (string) $v ), 0, $max );
		$venue = (array) ( $d['venue'] ?? array() );
		$org   = (array) ( $d['organizer'] ?? array() );
		$tz    = $t( $d['timezone'] ?? '', 40 );
		if ( '' === $tz || ! in_array( $tz, timezone_identifiers_list(), true ) ) {
			$tz = wp_timezone_string();
		}
		$start = self::date( $d['start_date'] ?? '' );
		$end   = self::date( $d['end_date'] ?? '' );
		$keys  = array( 'title', 'start_date', 'start_time', 'end_date', 'end_time', 'cost', 'website', 'venue', 'organizer', 'categories', 'description', 'timezone' );
		$email = sanitize_email( (string) ( $org['email'] ?? '' ) );
		return array(
			'title'       => $t( $d['title'] ?? '', 160 ),
			'start_date'  => $start,
			'start_time'  => self::time( $d['start_time'] ?? '' ),
			'end_date'    => '' !== $end ? $end : $start,
			'end_time'    => self::time( $d['end_time'] ?? '' ),
			'all_day'     => ! empty( $d['all_day'] ),
			'timezone'    => $tz,
			'cost'        => $t( $d['cost'] ?? '', 60 ),
			'currency'    => $t( $d['currency'] ?? '', 8 ),
			'website'     => esc_url_raw( (string) ( $d['website'] ?? '' ) ),
			'venue'       => array(
				'name'    => $t( $venue['name'] ?? '', 120 ),
				'address' => $t( $venue['address'] ?? '', 160 ),
				'city'    => $t( $venue['city'] ?? '', 80 ),
				'region'  => $t( $venue['region'] ?? '', 80 ),
				'zip'     => $t( $venue['zip'] ?? '', 20 ),
				'country' => $t( $venue['country'] ?? '', 60 ),
				'phone'   => $t( $venue['phone'] ?? '', 40 ),
			),
			'organizer'   => array(
				'name'    => $t( $org['name'] ?? '', 120 ),
				'email'   => is_email( $email ) ? $email : '',
				'phone'   => $t( $org['phone'] ?? '', 40 ),
				'website' => esc_url_raw( (string) ( $org['website'] ?? '' ) ),
			),
			'categories'  => array_values( array_filter( array_map( static fn( $c ) => mb_substr( sanitize_text_field( (string) $c ), 0, 40 ), (array) ( $d['categories'] ?? array() ) ) ) ),
			'description' => mb_substr( trim( sanitize_textarea_field( (string) ( $d['description'] ?? '' ) ) ), 0, 3000 ),
			'missing'     => array_values( array_intersect( $keys, (array) ( $d['missing'] ?? array() ) ) ),
			'uncertain'   => array_values( array_intersect( $keys, (array) ( $d['uncertain'] ?? array() ) ) ),
		);
	}

	/**
	 * Adds venue/organizer matches and category matches.
	 *
	 * @param array $draft Draft.
	 * @return array
	 */
	public static function with_matches( array $draft ) {
		$draft['venue_match']     = Places::match_venue( $draft['venue'] );
		$draft['organizer_match'] = Places::match_organizer( $draft['organizer'] );
		$ids                      = array();
		foreach ( Events::categories() as $c ) {
			foreach ( $draft['categories'] as $name ) {
				if ( Places::similarity( $name, $c['label'] ) >= 85 ) {
					$ids[] = $c['value'];
				}
			}
		}
		$draft['category_ids'] = array_values( array_unique( $ids ) );
		return $draft;
	}

	/**
	 * Creates the reviewed draft event.
	 *
	 * @param int   $id     Import.
	 * @param array $fields Reviewed fields: title, start_date, start_time, end_date, end_time, all_day, timezone, cost, currency, website, description, category_ids,
	 *                      venue { mode: existing|new|none, id, ...fields }, organizer { mode, id, ...fields }, featured (bool).
	 * @return array|\WP_Error { event_id, edit_url, venue_id, organizer_id }
	 */
	public static function create_event( $id, array $fields ) {
		$import = Imports::get( $id );
		if ( ! $import ) {
			return new \WP_Error( 'dev3ec_no_import', __( 'Import not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		if ( 'created' === $import['status'] && $import['event_id'] ) {
			return new \WP_Error( 'dev3ec_import_done', __( 'This flyer was already imported.', 'dev3-ai-events-calendar' ), array( 'status' => 409 ) );
		}
		$f = self::normalize( $fields );
		if ( '' === $f['title'] ) {
			return new \WP_Error( 'dev3ec_import_title', __( 'Give the event a title.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( '' === $f['start_date'] ) {
			return new \WP_Error( 'dev3ec_import_date', __( 'Add a valid start date.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$all_day = $f['all_day'] || '' === $f['start_time'];
		$start   = $f['start_date'] . ' ' . ( $all_day ? '00:00' : $f['start_time'] ) . ':00';
		if ( $all_day ) {
			$end = $f['end_date'] . ' 23:59:59';
		} else {
			$end_time = '' !== $f['end_time'] ? $f['end_time'] : gmdate( 'H:i', strtotime( '2000-01-01 ' . $f['start_time'] . ' +2 hours' ) );
			$end      = $f['end_date'] . ' ' . $end_time . ':00';
			if ( strtotime( $end ) <= strtotime( $start ) ) {
				$end = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +2 hours' ) );
			}
		}

		$venue_id = 0;
		$v        = (array) ( $fields['venue'] ?? array() );
		$vmode    = (string) ( $v['mode'] ?? 'none' );
		if ( 'existing' === $vmode && ! empty( $v['id'] ) && Events::venue( (int) $v['id'] ) ) {
			$venue_id = (int) $v['id'];
		} elseif ( 'new' === $vmode && '' !== $f['venue']['name'] ) {
			$venue_id = Places::create_venue( $f['venue'] );
			if ( is_wp_error( $venue_id ) ) {
				return $venue_id;
			}
		}
		$org_id = 0;
		$o      = (array) ( $fields['organizer'] ?? array() );
		$omode  = (string) ( $o['mode'] ?? 'none' );
		if ( 'existing' === $omode && ! empty( $o['id'] ) && Events::organizer( (int) $o['id'] ) ) {
			$org_id = (int) $o['id'];
		} elseif ( 'new' === $omode && '' !== $f['organizer']['name'] ) {
			$org_id = Places::create_organizer( $f['organizer'] );
			if ( is_wp_error( $org_id ) ) {
				return $org_id;
			}
		}
		$cats = array_values( array_filter( array_map( 'absint', (array) ( $fields['category_ids'] ?? array() ) ), static fn( $c ) => (bool) get_term( $c, Events::CATEGORY ) ) );
		$text = $f['description'];
		$mode = Events::blocks_on() ? 'block' : 'classic';
		$body = '' === $text ? '' : ( 'block' === $mode ? Blocks::to_blocks( $text ) : Blocks::to_html( $text ) );
		if ( 'block' === $mode && '' !== $body ) {
			$body = "<!-- wp:tribe/event-datetime /-->\n\n" . $body . "\n\n<!-- wp:tribe/event-price /-->\n\n<!-- wp:tribe/event-organizer /-->\n\n<!-- wp:tribe/event-venue /-->\n\n<!-- wp:tribe/event-website /-->\n\n<!-- wp:tribe/event-links /-->";
		}
		$event = Events::create(
			array(
				'title'         => $f['title'],
				'content'       => $body,
				'status'        => 'pending' === Settings::get( 'import_status', 'draft' ) ? 'pending' : 'draft',
				'start'         => $start,
				'end'           => $end,
				'all_day'       => $all_day,
				'timezone'      => $f['timezone'],
				'cost'          => $f['cost'],
				'currency'      => $f['currency'],
				'website'       => $f['website'],
				'venue_id'      => $venue_id,
				'organizer_ids' => $org_id ? array( $org_id ) : array(),
				'category_ids'  => $cats,
			)
		);
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		$featured = array_key_exists( 'featured', $fields ) ? ! empty( $fields['featured'] ) : (bool) Settings::get( 'import_featured', true );
		if ( $featured && 0 === strpos( $import['mime'], 'image/' ) ) {
			set_post_thumbnail( $event, $import['attachment_id'] );
		}
		update_post_meta( $event, '_dev3ec_import_id', (int) $id );
		Imports::update(
			$id,
			array(
				'status'   => 'created',
				'event_id' => $event,
			)
		);
		return array(
			'event_id'     => $event,
			'edit_url'     => (string) get_edit_post_link( $event, 'raw' ),
			'venue_id'     => $venue_id,
			'organizer_id' => $org_id,
			'import'       => Imports::get( $id ),
		);
	}
}
