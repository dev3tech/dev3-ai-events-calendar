<?php
/**
 * The Events Calendar adapter: reads event facts and creates events through TEC's own API.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Tec;

defined( 'ABSPATH' ) || exit;

/**
 * Events.
 */
class Events {

	const POST_TYPE = 'tribe_events';
	const VENUE     = 'tribe_venue';
	const ORGANIZER = 'tribe_organizer';
	const CATEGORY  = 'tribe_events_cat';

	/**
	 * Whether The Events Calendar is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'Tribe__Events__Main' ) || post_type_exists( self::POST_TYPE );
	}

	/**
	 * Whether an id is a TEC event.
	 *
	 * @param int $id Post id.
	 * @return bool
	 */
	public static function is_event( $id ) {
		$post = get_post( (int) $id );
		return $post && self::POST_TYPE === $post->post_type;
	}

	/**
	 * Whether TEC's block editor is switched on (Settings > General > Activate Block Editor for Events).
	 *
	 * @return bool
	 */
	public static function blocks_on() {
		if ( function_exists( 'tribe' ) && class_exists( 'Tribe__Events__Editor__Compatibility' ) ) {
			try {
				return (bool) tribe( 'events.editor.compatibility' )->is_blocks_editor_toggled_on();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return function_exists( 'tribe_get_option' ) ? (bool) tribe_get_option( 'toggle_blocks_editor', false ) : true;
	}

	/**
	 * Editor mode of an event: block when the post has blocks or TEC's block editor is on.
	 *
	 * @param int $id Event.
	 * @return string block|classic
	 */
	public static function editor_mode( $id ) {
		$post = get_post( (int) $id );
		if ( $post && '' !== trim( (string) $post->post_content ) ) {
			return has_blocks( $post->post_content ) ? 'block' : 'classic';
		}
		return self::blocks_on() ? 'block' : 'classic';
	}

	/**
	 * Organizer ids (TEC stores one meta row per organizer).
	 *
	 * @param int $id Event.
	 * @return int[]
	 */
	public static function organizer_ids( $id ) {
		$ids = function_exists( 'tribe_get_organizer_ids' ) ? (array) tribe_get_organizer_ids( $id ) : (array) get_post_meta( $id, '_EventOrganizerID', false );
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Time zone string of an event.
	 *
	 * @param int $id Event.
	 * @return string
	 */
	public static function timezone( $id ) {
		$tz = (string) get_post_meta( $id, '_EventTimezone', true );
		return '' !== $tz ? $tz : wp_timezone_string();
	}

	/**
	 * Start/end as DateTimeImmutable in the event time zone.
	 *
	 * @param int    $id    Event.
	 * @param string $which start|end.
	 * @return \DateTimeImmutable|null
	 */
	public static function date( $id, $which = 'start' ) {
		$raw = (string) get_post_meta( $id, 'start' === $which ? '_EventStartDate' : '_EventEndDate', true );
		if ( '' === $raw ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $raw, new \DateTimeZone( self::timezone( $id ) ) );
		} catch ( \Exception $e ) {
			unset( $e );
			return null;
		}
	}

	/**
	 * Start timestamp (UTC).
	 *
	 * @param int $id Event.
	 * @return int
	 */
	public static function start_ts( $id ) {
		$d = self::date( $id, 'start' );
		return $d ? $d->getTimestamp() : 0;
	}

	/**
	 * Human date label, e.g. "Saturday 14 November 2026".
	 *
	 * @param int $id Event.
	 * @return string
	 */
	public static function date_label( $id ) {
		$start = self::date( $id, 'start' );
		$end   = self::date( $id, 'end' );
		if ( ! $start ) {
			return '';
		}
		$fmt   = (string) get_option( 'date_format', 'F j, Y' );
		$label = wp_date( 'l ' . $fmt, $start->getTimestamp(), $start->getTimezone() );
		if ( $end && $end->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) ) {
			$label .= ' – ' . wp_date( 'l ' . $fmt, $end->getTimestamp(), $end->getTimezone() );
		}
		return $label;
	}

	/**
	 * Human time label, e.g. "7:00 pm – 10:00 pm" ("All day" for all-day events).
	 *
	 * @param int $id Event.
	 * @return string
	 */
	public static function time_label( $id ) {
		if ( self::all_day( $id ) ) {
			return __( 'All day', 'dev3-ai-events-calendar' );
		}
		$start = self::date( $id, 'start' );
		$end   = self::date( $id, 'end' );
		if ( ! $start ) {
			return '';
		}
		$fmt   = (string) get_option( 'time_format', 'g:i a' );
		$label = wp_date( $fmt, $start->getTimestamp(), $start->getTimezone() );
		if ( $end ) {
			$label .= ' – ' . wp_date( $fmt, $end->getTimestamp(), $end->getTimezone() );
		}
		return $label;
	}

	/**
	 * All-day flag.
	 *
	 * @param int $id Event.
	 * @return bool
	 */
	public static function all_day( $id ) {
		$v = get_post_meta( $id, '_EventAllDay', true );
		return in_array( strtolower( (string) $v ), array( 'yes', '1', 'true' ), true );
	}

	/**
	 * Cost label with currency.
	 *
	 * @param int $id Event.
	 * @return string
	 */
	public static function cost( $id ) {
		if ( function_exists( 'tribe_get_cost' ) ) {
			$c = (string) tribe_get_cost( $id, true );
			return html_entity_decode( wp_strip_all_tags( $c ), ENT_QUOTES, 'UTF-8' );
		}
		$cost = (string) get_post_meta( $id, '_EventCost', true );
		$sym  = (string) get_post_meta( $id, '_EventCurrencySymbol', true );
		return '' === $cost ? '' : ( is_numeric( $cost ) ? $sym . $cost : $cost );
	}

	/**
	 * Venue facts.
	 *
	 * @param int $venue_id Venue.
	 * @return array|null
	 */
	public static function venue( $venue_id ) {
		$p = $venue_id ? get_post( (int) $venue_id ) : null;
		if ( ! $p || self::VENUE !== $p->post_type ) {
			return null;
		}
		$m               = static fn( $k ) => trim( (string) get_post_meta( $p->ID, $k, true ) );
		$region          = $m( '_VenueStateProvince' );
		$region          = '' !== $region ? $region : ( '' !== $m( '_VenueState' ) ? $m( '_VenueState' ) : $m( '_VenueProvince' ) );
		$address         = array(
			'name'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'address' => $m( '_VenueAddress' ),
			'city'    => $m( '_VenueCity' ),
			'region'  => $region,
			'zip'     => $m( '_VenueZip' ),
			'country' => $m( '_VenueCountry' ),
			'phone'   => $m( '_VenuePhone' ),
			'website' => $m( '_VenueURL' ),
		);
		$address['id']   = (int) $p->ID;
		$address['full'] = implode( ', ', array_filter( array( $address['address'], $address['city'], trim( $address['region'] . ' ' . $address['zip'] ), $address['country'] ) ) );
		return $address;
	}

	/**
	 * Organizer facts.
	 *
	 * @param int $org_id Organizer.
	 * @return array|null
	 */
	public static function organizer( $org_id ) {
		$p = $org_id ? get_post( (int) $org_id ) : null;
		if ( ! $p || self::ORGANIZER !== $p->post_type ) {
			return null;
		}
		return array(
			'id'      => (int) $p->ID,
			'name'    => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			'email'   => (string) get_post_meta( $p->ID, '_OrganizerEmail', true ),
			'phone'   => (string) get_post_meta( $p->ID, '_OrganizerPhone', true ),
			'website' => (string) get_post_meta( $p->ID, '_OrganizerWebsite', true ),
		);
	}

	/**
	 * Everything the plugin knows about an event (for the UI and the templates).
	 *
	 * @param int $id Event.
	 * @return array|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$venue = self::venue( (int) get_post_meta( $post->ID, '_EventVenueID', true ) );
		$orgs  = array_values( array_filter( array_map( array( self::class, 'organizer' ), self::organizer_ids( $post->ID ) ) ) );
		$cats  = wp_get_object_terms( $post->ID, self::CATEGORY, array( 'fields' => 'names' ) );
		$tags  = wp_get_object_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		$start = self::date( $post->ID, 'start' );
		$end   = self::date( $post->ID, 'end' );
		$now   = time();
		return array(
			'id'          => (int) $post->ID,
			'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'status'      => $post->post_status,
			'url'         => (string) get_permalink( $post ),
			'edit_url'    => (string) get_edit_post_link( $post->ID, 'raw' ),
			'start'       => $start ? $start->format( 'Y-m-d H:i:s' ) : '',
			'end'         => $end ? $end->format( 'Y-m-d H:i:s' ) : '',
			'start_ts'    => $start ? $start->getTimestamp() : 0,
			'end_ts'      => $end ? $end->getTimestamp() : 0,
			'past'        => $end ? $end->getTimestamp() < $now : false,
			'timezone'    => self::timezone( $post->ID ),
			'all_day'     => self::all_day( $post->ID ),
			'date_label'  => self::date_label( $post->ID ),
			'time_label'  => self::time_label( $post->ID ),
			'cost'        => self::cost( $post->ID ),
			'website'     => (string) get_post_meta( $post->ID, '_EventURL', true ),
			'event_state' => (string) get_post_meta( $post->ID, '_tribe_events_status', true ),
			'venue'       => $venue,
			'organizers'  => $orgs,
			'categories'  => is_wp_error( $cats ) ? array() : array_map( 'html_entity_decode', $cats ),
			'tags'        => is_wp_error( $tags ) ? array() : $tags,
			'excerpt'     => (string) $post->post_excerpt,
			'content'     => (string) $post->post_content,
			'thumbnail'   => (string) get_the_post_thumbnail_url( $post, 'medium' ),
			'editor'      => self::editor_mode( $post->ID ),
			'modified'    => (string) $post->post_modified_gmt,
		);
	}

	/**
	 * Facts sent to the model: event data only, never attendee names or emails.
	 *
	 * @param int $id Event.
	 * @return array
	 */
	public static function facts( $id ) {
		$e = self::get( $id );
		if ( ! $e ) {
			return array();
		}
		$tickets = array();
		foreach ( Attendees::tickets( $id ) as $t ) {
			$tickets[] = array_filter(
				array(
					'name'        => $t['name'],
					'price'       => $t['price'],
					'description' => $t['description'],
				)
			);
		}
		$text = \Dev3\AiEventsCalendar\Content\Blocks::description_text( $e['content'] );
		return array_filter(
			array(
				'title'       => $e['title'],
				'date'        => $e['date_label'],
				'time'        => $e['time_label'],
				'time_zone'   => $e['timezone'],
				'status'      => in_array( $e['event_state'], array( 'canceled', 'postponed' ), true ) ? $e['event_state'] : '',
				'cost'        => $e['cost'],
				'website'     => $e['website'],
				'venue'       => $e['venue'] ? array_filter(
					array(
						'name'    => $e['venue']['name'],
						'address' => $e['venue']['full'],
					)
				) : null,
				'organizers'  => array_map( static fn( $o ) => $o['name'], $e['organizers'] ),
				'categories'  => $e['categories'],
				'tags'        => $e['tags'],
				'tickets'     => $tickets,
				'url'         => $e['url'],
				'description' => mb_substr( trim( $e['excerpt'] . "\n" . $text ), 0, 3000 ),
			)
		);
	}

	/**
	 * Hash of the facts a draft was made from (stale check on accept).
	 *
	 * @param int $id Event.
	 * @return string
	 */
	public static function hash( $id ) {
		$e = self::get( $id );
		if ( ! $e ) {
			return '';
		}
		return md5( wp_json_encode( array( $e['title'], $e['start'], $e['end'], $e['excerpt'], $e['content'], $e['venue'] ? $e['venue']['id'] : 0, $e['cost'] ) ) );
	}

	/**
	 * Event list for the bulk screen.
	 *
	 * @param array $q scope (upcoming|past|all), search, category, ids, page, per_page.
	 * @return array { items: int[], total }
	 */
	public static function query( array $q ) {
		$args  = array(
			'post_type'                    => self::POST_TYPE,
			'post_status'                  => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'               => max( 1, min( 100, (int) ( $q['per_page'] ?? 20 ) ) ),
			'paged'                        => max( 1, (int) ( $q['page'] ?? 1 ) ),
			'fields'                       => 'ids',
			'meta_key'                     => '_EventStartDate', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
			'orderby'                      => 'meta_value',
			'meta_type'                    => 'DATETIME',
			'order'                        => 'ASC',
			'tribe_suppress_query_filters' => true,
		);
		$now   = current_time( 'mysql' );
		$scope = (string) ( $q['scope'] ?? 'upcoming' );
		if ( 'upcoming' === $scope ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				array(
					'key'     => '_EventEndDate',
					'value'   => $now,
					'compare' => '>=',
					'type'    => 'DATETIME',
				),
			);
		} elseif ( 'past' === $scope ) {
			$args['order']      = 'DESC';
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				array(
					'key'     => '_EventEndDate',
					'value'   => $now,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			);
		} else {
			$args['order'] = 'DESC';
		}
		if ( ! empty( $q['search'] ) ) {
			$args['s'] = mb_substr( sanitize_text_field( (string) $q['search'] ), 0, 100 );
		}
		if ( ! empty( $q['category'] ) ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_tax_query
				array(
					'taxonomy' => self::CATEGORY,
					'field'    => 'term_id',
					'terms'    => array( absint( $q['category'] ) ),
				),
			);
		}
		if ( ! empty( $q['missing'] ) ) {
			$key = \Dev3\AiEventsCalendar\Content\Assets::meta_key( (string) $q['missing'] );
			if ( $key ) {
				$args['meta_query']   = isset( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
				$args['meta_query'][] = array(
					'key'     => $key,
					'compare' => 'NOT EXISTS',
				);
			} elseif ( 'description' === $q['missing'] ) {
				$args['dev3ec_short_content'] = 1;
			}
		}
		if ( ! empty( $q['ids'] ) ) {
			$args['post__in'] = array_map( 'absint', (array) $q['ids'] );
		}
		$filter = static function ( $where, $query ) {
			if ( $query->get( 'dev3ec_short_content' ) ) {
				global $wpdb;
				$where .= " AND CHAR_LENGTH( {$wpdb->posts}.post_content ) < 600";
			}
			return $where;
		};
		add_filter( 'posts_where', $filter, 10, 2 );
		$wpq = new \WP_Query( $args );
		remove_filter( 'posts_where', $filter, 10 );
		return array(
			'items' => array_map( 'intval', $wpq->posts ),
			'total' => (int) $wpq->found_posts,
		);
	}

	/**
	 * Event categories for filters/import.
	 *
	 * @return array [ { value, label } ]
	 */
	public static function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::CATEGORY,
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static fn( $t ) => array(
				'value' => (int) $t->term_id,
				'label' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
			),
			$terms
		);
	}

	/**
	 * Creates an event through TEC's ORM (falls back to tribe_create_event, then plain meta).
	 *
	 * @param array $f title, content, excerpt, status, start (Y-m-d H:i:s), end, all_day, timezone, cost, currency, website, venue_id, organizer_ids, category_ids.
	 * @return int|\WP_Error Event id.
	 */
	public static function create( array $f ) {
		$args = array(
			'title'      => $f['title'],
			'content'    => $f['content'] ?? '',
			'excerpt'    => $f['excerpt'] ?? '',
			'status'     => $f['status'] ?? 'draft',
			'start_date' => $f['start'],
			'end_date'   => $f['end'],
			'timezone'   => $f['timezone'] ?? wp_timezone_string(),
			'all_day'    => ! empty( $f['all_day'] ),
		);
		if ( '' !== (string) ( $f['cost'] ?? '' ) ) {
			$args['cost'] = $f['cost'];
		}
		if ( '' !== (string) ( $f['currency'] ?? '' ) ) {
			$args['currency_symbol'] = $f['currency'];
		}
		if ( '' !== (string) ( $f['website'] ?? '' ) ) {
			$args['url'] = $f['website'];
		}
		if ( ! empty( $f['venue_id'] ) ) {
			$args['venue'] = (int) $f['venue_id'];
		}
		if ( ! empty( $f['organizer_ids'] ) ) {
			$args['organizer'] = array_map( 'intval', (array) $f['organizer_ids'] );
		}
		if ( ! empty( $f['category_ids'] ) ) {
			$args['category'] = array_map( 'intval', (array) $f['category_ids'] );
		}
		$id = 0;
		if ( function_exists( 'tribe_events' ) ) {
			try {
				$post = tribe_events()->set_args( $args )->create();
				$id   = $post instanceof \WP_Post ? (int) $post->ID : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				$id = 0;
			}
		}
		if ( ! $id ) {
			$id = self::create_plain( $f );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! $id ) {
			return new \WP_Error( 'dev3ec_create_failed', __( 'The event could not be created.', 'dev3-ai-events-calendar' ), array( 'status' => 500 ) );
		}
		return (int) $id;
	}

	/**
	 * Fallback without TEC's ORM (older TEC or unit tests without TEC).
	 *
	 * @param array $f Fields.
	 * @return int|\WP_Error
	 */
	private static function create_plain( array $f ) {
		$id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_title'   => $f['title'],
				'post_content' => $f['content'] ?? '',
				'post_excerpt' => $f['excerpt'] ?? '',
				'post_status'  => $f['status'] ?? 'draft',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$tz = $f['timezone'] ?? wp_timezone_string();
		update_post_meta( $id, '_EventStartDate', $f['start'] );
		update_post_meta( $id, '_EventEndDate', $f['end'] );
		update_post_meta( $id, '_EventTimezone', $tz );
		try {
			$utc = new \DateTimeZone( 'UTC' );
			update_post_meta( $id, '_EventStartDateUTC', ( new \DateTimeImmutable( $f['start'], new \DateTimeZone( $tz ) ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
			update_post_meta( $id, '_EventEndDateUTC', ( new \DateTimeImmutable( $f['end'], new \DateTimeZone( $tz ) ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		} catch ( \Exception $e ) {
			unset( $e );
		}
		if ( ! empty( $f['all_day'] ) ) {
			update_post_meta( $id, '_EventAllDay', 'yes' );
		}
		foreach ( array(
			'cost'     => '_EventCost',
			'currency' => '_EventCurrencySymbol',
			'website'  => '_EventURL',
			'venue_id' => '_EventVenueID',
		) as $k => $meta ) {
			if ( ! empty( $f[ $k ] ) ) {
				update_post_meta( $id, $meta, $f[ $k ] );
			}
		}
		foreach ( (array) ( $f['organizer_ids'] ?? array() ) as $oid ) {
			add_post_meta( $id, '_EventOrganizerID', (int) $oid );
		}
		if ( ! empty( $f['category_ids'] ) ) {
			wp_set_object_terms( $id, array_map( 'intval', (array) $f['category_ids'] ), self::CATEGORY );
		}
		return (int) $id;
	}
}
