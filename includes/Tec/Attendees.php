<?php
/**
 * Event Tickets bridge (free plugin, optional): tickets, attendee counts and recipients.
 * Only counts ever go to the AI; names and emails are used for sending only.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Tec;

defined( 'ABSPATH' ) || exit;

/**
 * Attendees.
 */
class Attendees {

	/**
	 * Whether Event Tickets is active.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'tribe_tickets_get_attendees' ) && class_exists( 'Tribe__Tickets__Tickets' );
	}

	/**
	 * Raw attendee rows for an event (filterable for tests/other ticket plugins).
	 *
	 * @param int $event_id Event.
	 * @return array
	 */
	public static function raw( $event_id ) {
		$rows = array();
		if ( self::active() ) {
			try {
				// All active ticket providers (RSVP v1/v2, Tickets Commerce); the data API as fallback.
				$rows = method_exists( 'Tribe__Tickets__Tickets', 'get_event_attendees' ) ? (array) \Tribe__Tickets__Tickets::get_event_attendees( (int) $event_id ) : array();
				if ( ! $rows ) {
					$rows = (array) tribe_tickets_get_attendees( (int) $event_id );
				}
				// Legacy RSVP (v1) when the site still runs it next to Tickets Commerce.
				if ( ! $rows && class_exists( 'Tribe__Tickets__RSVP' ) && function_exists( 'tribe' ) ) {
					$rows = (array) tribe( 'tickets.rsvp' )->get_attendees_by_id( (int) $event_id );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$rows = array();
			}
		}
		/**
		 * Filters the attendee rows of an event (Event Tickets format: holder_email, holder_name,
		 * purchaser_email, purchaser_name, check_in, order_status, ticket).
		 *
		 * @param array $rows     Rows.
		 * @param int   $event_id Event.
		 */
		return (array) apply_filters( 'dev3ec_attendees', $rows, (int) $event_id );
	}

	/**
	 * Normalised attendees.
	 *
	 * @param int $event_id Event.
	 * @return array [ { name, email, checked_in, going, ticket } ]
	 */
	public static function all( $event_id ) {
		$out = array();
		foreach ( self::raw( $event_id ) as $a ) {
			$a      = (array) $a;
			$email  = sanitize_email( (string) ( ! empty( $a['holder_email'] ) ? $a['holder_email'] : ( $a['purchaser_email'] ?? '' ) ) );
			$name   = sanitize_text_field( (string) ( ! empty( $a['holder_name'] ) ? $a['holder_name'] : ( $a['purchaser_name'] ?? '' ) ) );
			$status = strtolower( (string) ( $a['order_status'] ?? '' ) );
			$out[]  = array(
				'name'       => $name,
				'email'      => is_email( $email ) ? $email : '',
				'checked_in' => ! empty( $a['check_in'] ),
				'going'      => ! in_array( $status, array( 'no', 'not-going', 'refunded', 'cancelled', 'canceled', 'failed', 'denied' ), true ),
				'ticket'     => sanitize_text_field( (string) ( $a['ticket'] ?? ( $a['ticket_name'] ?? '' ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Counts only (safe to send to the AI).
	 *
	 * @param int $event_id Event.
	 * @return array { total, going, not_going, checked_in, by_ticket{name:count}, source }
	 */
	public static function counts( $event_id ) {
		$all     = self::all( $event_id );
		$by      = array();
		$going   = 0;
		$checked = 0;
		foreach ( $all as $a ) {
			if ( $a['going'] ) {
				++$going;
				$key        = '' !== $a['ticket'] ? $a['ticket'] : __( 'General', 'dev3-ai-events-calendar' );
				$by[ $key ] = ( $by[ $key ] ?? 0 ) + 1;
			}
			if ( $a['checked_in'] ) {
				++$checked;
			}
		}
		return array(
			'total'      => count( $all ),
			'going'      => $going,
			'not_going'  => count( $all ) - $going,
			'checked_in' => $checked,
			'by_ticket'  => $by,
			'source'     => self::active() || $all ? 'event-tickets' : 'none',
		);
	}

	/**
	 * Unique recipients (going attendees with a valid email).
	 *
	 * @param int $event_id Event.
	 * @return array [ { name, email } ]
	 */
	public static function recipients( $event_id ) {
		$seen = array();
		foreach ( self::all( $event_id ) as $a ) {
			if ( ! $a['going'] || '' === $a['email'] ) {
				continue;
			}
			$k = strtolower( $a['email'] );
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = array(
					'name'  => $a['name'],
					'email' => $a['email'],
				);
			}
		}
		return array_values( $seen );
	}

	/**
	 * Ticket types of an event.
	 *
	 * @param int $event_id Event.
	 * @return array [ { name, price, description, capacity, sold } ]
	 */
	public static function tickets( $event_id ) {
		$out = array();
		if ( ! self::active() ) {
			return $out;
		}
		try {
			$tickets = (array) \Tribe__Tickets__Tickets::get_all_event_tickets( (int) $event_id );
		} catch ( \Throwable $e ) {
			unset( $e );
			return $out;
		}
		foreach ( $tickets as $t ) {
			if ( ! is_object( $t ) ) {
				continue;
			}
			$price = isset( $t->price ) ? (string) $t->price : '';
			$out[] = array(
				'name'        => sanitize_text_field( (string) ( $t->name ?? '' ) ),
				'price'       => '' === $price || '0' === $price || 0.0 === (float) $price ? __( 'Free', 'dev3-ai-events-calendar' ) : $price,
				'description' => mb_substr( wp_strip_all_tags( (string) ( $t->description ?? '' ) ), 0, 200 ),
				'capacity'    => method_exists( $t, 'capacity' ) ? (int) $t->capacity() : 0,
				'sold'        => method_exists( $t, 'qty_sold' ) ? (int) $t->qty_sold() : 0,
			);
		}
		return $out;
	}
}
