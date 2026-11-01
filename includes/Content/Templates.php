<?php
/**
 * Non-AI starters built from the event facts (the plugin stays useful without a key), and email
 * merge tags.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Content;

use Dev3\AiEventsCalendar\Settings;
use Dev3\AiEventsCalendar\Tec\Attendees;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Templates.
 */
class Templates {

	const TAGS = array( '{attendee_name}', '{event_title}', '{event_date}', '{event_time}', '{venue}', '{event_url}', '{site_name}' );

	/**
	 * Merge-tag values of an event.
	 *
	 * @param int    $id   Event.
	 * @param string $name Attendee name.
	 * @return array
	 */
	public static function vars( $id, $name = '' ) {
		$e = Events::get( $id );
		return array(
			'{attendee_name}' => '' !== $name ? $name : __( 'there', 'dev3-ai-events-calendar' ),
			'{event_title}'   => $e ? $e['title'] : '',
			'{event_date}'    => $e ? $e['date_label'] : '',
			'{event_time}'    => $e ? $e['time_label'] : '',
			'{venue}'         => $e && $e['venue'] ? $e['venue']['name'] : __( 'online', 'dev3-ai-events-calendar' ),
			'{event_url}'     => $e ? $e['url'] : home_url( '/' ),
			'{site_name}'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}

	/**
	 * Fills merge tags.
	 *
	 * @param string $text Text.
	 * @param array  $vars Vars.
	 * @return string
	 */
	public static function fill( $text, array $vars ) {
		return strtr( (string) $text, $vars );
	}

	/**
	 * Hashtags from settings + categories.
	 *
	 * @param array $e Event.
	 * @return string[]
	 */
	private static function hashtags( array $e ) {
		$tags = array_filter( array_map( 'trim', explode( ' ', str_replace( array( ',', '#' ), ' ', (string) Settings::get( 'hashtags', '' ) ) ) ) );
		foreach ( $e['categories'] as $c ) {
			$tags[] = preg_replace( '/[^\p{L}\p{N}]/u', '', ucwords( $c ) );
		}
		return array_slice( array_values( array_unique( array_filter( $tags ) ) ), 0, 3 );
	}

	/**
	 * Non-AI draft fields for a kind.
	 *
	 * @param int    $id   Event.
	 * @param string $kind Kind.
	 * @param array  $opts networks (social).
	 * @return array|\WP_Error
	 */
	public static function draft( $id, $kind, array $opts = array() ) {
		$e = Events::get( $id );
		if ( ! $e ) {
			return new \WP_Error( 'dev3ec_bad_event', __( 'Event not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		$when  = trim( $e['date_label'] . ( $e['all_day'] ? '' : ', ' . $e['time_label'] ) );
		$where = $e['venue'] ? $e['venue']['name'] . ( '' !== $e['venue']['city'] ? ', ' . $e['venue']['city'] : '' ) : '';
		$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		switch ( $kind ) {
			case 'description':
				$lines = array( '## ' . __( 'Event details', 'dev3-ai-events-calendar' ) );
				$facts = array( '- ' . sprintf( /* translators: %s: date and time */ __( 'When: %s', 'dev3-ai-events-calendar' ), $when ) );
				if ( '' !== $where ) {
					$facts[] = '- ' . sprintf( /* translators: %s: venue */ __( 'Where: %s', 'dev3-ai-events-calendar' ), $e['venue']['full'] ? $e['venue']['name'] . ', ' . $e['venue']['full'] : $where );
				}
				if ( '' !== $e['cost'] ) {
					$facts[] = '- ' . sprintf( /* translators: %s: cost */ __( 'Cost: %s', 'dev3-ai-events-calendar' ), $e['cost'] );
				}
				if ( $e['organizers'] ) {
					$facts[] = '- ' . sprintf( /* translators: %s: organizer names */ __( 'Organised by: %s', 'dev3-ai-events-calendar' ), implode( ', ', wp_list_pluck( $e['organizers'], 'name' ) ) );
				}
				$current = Blocks::description_text( $e['content'] );
				$body    = implode( "\n", $lines ) . "\n\n" . implode( "\n", $facts );
				if ( '' !== $current ) {
					$body = $current . "\n\n" . $body;
				}
				return array(
					'excerpt' => '' !== $e['excerpt'] ? $e['excerpt'] : sprintf( /* translators: 1: title, 2: date */ __( '%1$s on %2$s.', 'dev3-ai-events-calendar' ), $e['title'], $when ),
					'body'    => $body,
				);
			case 'social':
				$out  = array();
				$tags = self::hashtags( $e );
				foreach ( Social::networks( $opts['networks'] ?? null ) as $n ) {
					$text = $e['title'] . ' · ' . $when . ( '' !== $where ? ' · ' . $where : '' );
					if ( 'instagram' === $n ) {
						$text .= "\n\n" . __( 'Link in bio.', 'dev3-ai-events-calendar' );
					} else {
						$text .= ' ' . $e['url'];
					}
					$out[ $n ] = Social::compose( $text, $tags, $n );
				}
				return $out;
			case 'email_promo':
				return array(
					'subject'   => sprintf( /* translators: 1: event, 2: date */ __( 'Join us: %1$s on %2$s', 'dev3-ai-events-calendar' ), $e['title'], $e['date_label'] ),
					'preheader' => trim( $when . ( '' !== $where ? ' · ' . $where : '' ) ),
					'body'      => __( "Hi {attendee_name},\n\nWe would love to see you at {event_title}.\n\nWhen: {event_date}, {event_time}\nWhere: {venue}\n\nDetails and tickets: {event_url}\n\nSee you there,\n{site_name}", 'dev3-ai-events-calendar' ),
				);
			case 'email_reminder':
				return array(
					'subject'   => sprintf( /* translators: %s: event */ __( 'Reminder: %s is coming up', 'dev3-ai-events-calendar' ), $e['title'] ),
					'preheader' => $when,
					'body'      => __( "Hi {attendee_name},\n\nThis is a friendly reminder that {event_title} is coming up.\n\nWhen: {event_date}, {event_time}\nWhere: {venue}\n\nEvent page: {event_url}\n\nSee you soon,\n{site_name}", 'dev3-ai-events-calendar' ),
				);
			case 'email_recap':
				$c = Attendees::counts( $id );
				$n = $c['checked_in'] > 0 ? $c['checked_in'] : $c['going'];
				return array(
					'subject'   => sprintf( /* translators: %s: event */ __( 'Thank you for coming to %s', 'dev3-ai-events-calendar' ), $e['title'] ),
					'preheader' => __( 'A short recap and what comes next.', 'dev3-ai-events-calendar' ),
					'body'      => $n > 0
						/* translators: %d: number of attendees */
						? sprintf( __( "Hi {attendee_name},\n\nThank you for joining {event_title}! You were one of %d people who made it a great event.\n\nWe'd love your feedback, and we hope to see you at the next one: {event_url}\n\nThanks again,\n{site_name}", 'dev3-ai-events-calendar' ), $n )
						: __( "Hi {attendee_name},\n\nThank you for joining {event_title}!\n\nWe'd love your feedback, and we hope to see you at the next one: {event_url}\n\nThanks again,\n{site_name}", 'dev3-ai-events-calendar' ),
				);
			case 'faq':
				$items = array(
					array(
						'q' => __( 'When does the event take place?', 'dev3-ai-events-calendar' ),
						'a' => $when . '.',
					),
				);
				if ( '' !== $where ) {
					$items[] = array(
						'q' => __( 'Where is it?', 'dev3-ai-events-calendar' ),
						'a' => ( $e['venue']['full'] ? $e['venue']['name'] . ', ' . $e['venue']['full'] : $where ) . '.',
					);
				}
				if ( '' !== $e['cost'] ) {
					$items[] = array(
						'q' => __( 'How much does it cost?', 'dev3-ai-events-calendar' ),
						'a' => $e['cost'] . '.',
					);
				}
				$items[] = array(
					'q' => __( 'Who can I contact with questions?', 'dev3-ai-events-calendar' ),
					'a' => $e['organizers'] ? sprintf( /* translators: %s: organizer */ __( 'Please contact %s.', 'dev3-ai-events-calendar' ), $e['organizers'][0]['name'] . ( '' !== $e['organizers'][0]['email'] ? ' (' . $e['organizers'][0]['email'] . ')' : '' ) ) : sprintf( /* translators: %s: site name */ __( 'Please contact %s.', 'dev3-ai-events-calendar' ), $site ),
				);
				return array( 'faq' => Assets::faq_text( $items ) );
			case 'seo':
				$title = $e['title'] . ( '' !== $e['date_label'] ? ' – ' . $e['date_label'] : '' );
				if ( mb_strlen( $title ) > 60 ) {
					$title = mb_substr( $e['title'], 0, 60 );
				}
				$desc = sprintf( /* translators: 1: event, 2: when, 3: where */ __( '%1$s: %2$s%3$s. Details and tickets.', 'dev3-ai-events-calendar' ), $e['title'], $when, '' !== $where ? ' · ' . $where : '' );
				return array(
					'title'       => $title,
					'description' => mb_substr( $desc, 0, 155 ),
				);
		}
		return new \WP_Error( 'dev3ec_bad_kind', __( 'Unknown output type.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
	}
}
