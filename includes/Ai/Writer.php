<?php
/**
 * DEV3 AI writers: description (A1), social (A2), promo/reminder emails (A3), recap (A4), FAQ (A5)
 * and SEO meta (A6). Every method returns draft fields only; nothing is saved here.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Ai;

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Content\Blocks;
use Dev3\AiEventsCalendar\Content\Social;
use Dev3\AiEventsCalendar\Core3\Api\Prompts;
use Dev3\AiEventsCalendar\Settings;
use Dev3\AiEventsCalendar\Tec\Attendees;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Writer.
 */
class Writer {

	/**
	 * Cleans the shared options.
	 *
	 * @param array $opts Raw options.
	 * @return array
	 */
	public static function options( array $opts ) {
		$length = in_array( $opts['length'] ?? '', array( 'short', 'medium', 'long' ), true ) ? $opts['length'] : 'medium';
		return array(
			'tone'     => Settings::tone( (string) ( $opts['tone'] ?? '' ) ),
			'language' => Settings::language( (string) ( $opts['language'] ?? '' ) ),
			'notes'    => mb_substr( sanitize_textarea_field( (string) ( $opts['notes'] ?? '' ) ), 0, 1500 ),
			'length'   => $length,
			'audience' => mb_substr( sanitize_text_field( (string) ( $opts['audience'] ?? '' ) ), 0, 120 ),
			'networks' => Social::networks( $opts['networks'] ?? null ),
			'count'    => max( 3, min( 10, (int) ( $opts['count'] ?? 6 ) ) ),
			'focus'    => mb_substr( sanitize_text_field( (string) ( $opts['focus'] ?? '' ) ), 0, 80 ),
		);
	}

	/**
	 * Facts JSON.
	 *
	 * @param int $id Event.
	 * @return string
	 */
	private static function details( $id ) {
		return (string) wp_json_encode( Events::facts( $id ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Empty-result error.
	 *
	 * @return \WP_Error
	 */
	private static function empty_error() {
		return new \WP_Error( 'dev3ec_ai_empty', __( 'DEV3 AI returned an empty draft. Try again.', 'dev3-ai-events-calendar' ), array( 'status' => 502 ) );
	}

	/**
	 * Generates draft fields for a kind.
	 *
	 * @param int    $id   Event.
	 * @param string $kind Kind.
	 * @param array  $opts Options.
	 * @return array|\WP_Error { fields, model, tokens, cached }
	 */
	public static function generate( $id, $kind, array $opts = array() ) {
		if ( ! Events::is_event( $id ) ) {
			return new \WP_Error( 'dev3ec_bad_event', __( 'Event not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		$o    = self::options( $opts );
		$base = array(
			'tone'     => $o['tone'],
			'language' => $o['language'],
			'notes'    => '' !== $o['notes'] ? $o['notes'] : '-',
			'details'  => self::details( $id ),
		);
		switch ( $kind ) {
			case 'description':
				$r      = Prompts::run(
					'event_description',
					$base + array(
						'length'   => $o['length'],
						'audience' => '' !== $o['audience'] ? $o['audience'] : 'general public',
						'current'  => mb_substr( Blocks::description_text( (string) get_post_field( 'post_content', $id ) ), 0, 2000 ),
					)
				);
				$fields = is_wp_error( $r ) ? null : self::description( (array) ( $r['data'] ?? array() ) );
				break;
			case 'social':
				$rules = array();
				foreach ( $o['networks'] as $n ) {
					$net     = Social::NETWORKS[ $n ];
					$rules[] = sprintf( '- %s (%s): max %d characters, ideally under %d%s%s', $n, $net['label'], $net['limit'], $net['ideal'], $net['url23'] ? '; every link counts as 23 characters' : '', $net['links'] ? '' : '; links are not clickable' );
				}
				$r      = Prompts::run(
					'social_posts',
					$base + array(
						'networks' => implode( ', ', $o['networks'] ),
						'rules'    => implode( "\n", $rules ),
						'url'      => (string) get_permalink( $id ),
					)
				);
				$fields = is_wp_error( $r ) ? null : self::social( (array) ( $r['data']['posts'] ?? array() ), $o['networks'] );
				break;
			case 'email_promo':
			case 'email_reminder':
				$purpose = 'email_promo' === $kind
					? 'promotional (announce the event and invite people to get tickets or RSVP)'
					: 'reminder (sent to people who already have a ticket, shortly before the event: what to bring, when to arrive, how to get there, only from the details)';
				$r       = Prompts::run( 'event_email', $base + array( 'purpose' => $purpose ) );
				$fields  = is_wp_error( $r ) ? null : self::email( (array) ( $r['data'] ?? array() ) );
				break;
			case 'email_recap':
				$c      = Attendees::counts( $id );
				$stats  = 'none' === $c['source'] && 0 === $c['total'] ? 'unknown' : wp_json_encode(
					array(
						'registered' => $c['going'],
						'checked_in' => $c['checked_in'],
						'by_ticket'  => $c['by_ticket'],
					)
				);
				$r      = Prompts::run( 'recap_email', $base + array( 'stats' => $stats ) );
				$fields = is_wp_error( $r ) ? null : self::email( (array) ( $r['data'] ?? array() ) );
				break;
			case 'faq':
				$r      = Prompts::run( 'event_faq', $base + array( 'count' => $o['count'] ) );
				$items  = is_wp_error( $r ) ? array() : Assets::clean_faq( (array) ( $r['data']['faq'] ?? array() ) );
				$fields = $items ? array( 'faq' => Assets::faq_text( $items ) ) : array();
				break;
			case 'seo':
				$r      = Prompts::run( 'event_seo', $base + array( 'focus' => '' !== $o['focus'] ? $o['focus'] : '-' ) );
				$fields = is_wp_error( $r ) ? null : array(
					'title'       => mb_substr( sanitize_text_field( (string) ( $r['data']['title'] ?? '' ) ), 0, 70 ),
					'description' => mb_substr( sanitize_text_field( (string) ( $r['data']['description'] ?? '' ) ), 0, 170 ),
				);
				break;
			default:
				return new \WP_Error( 'dev3ec_bad_kind', __( 'Unknown output type.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $fields || Assets::is_empty( $fields ) ) {
			return self::empty_error();
		}
		return array(
			'fields' => $fields,
			'model'  => (string) ( $r['model'] ?? '' ),
			'tokens' => (int) ( $r['usage']['total'] ?? 0 ),
			'cached' => ! empty( $r['cached'] ),
		);
	}

	/**
	 * Description fields from AI data.
	 *
	 * @param array $d Data.
	 * @return array|null
	 */
	public static function description( array $d ) {
		$sections = array();
		foreach ( (array) ( $d['sections'] ?? array() ) as $s ) {
			$body = trim( sanitize_textarea_field( (string) ( $s['body'] ?? '' ) ) );
			if ( '' !== $body ) {
				$sections[] = array(
					'heading' => mb_substr( sanitize_text_field( (string) ( $s['heading'] ?? '' ) ), 0, 90 ),
					'body'    => mb_substr( $body, 0, 1200 ),
				);
			}
		}
		$excerpt = mb_substr( sanitize_textarea_field( (string) ( $d['excerpt'] ?? '' ) ), 0, 300 );
		if ( ! $sections ) {
			return null;
		}
		return array(
			'excerpt' => $excerpt,
			'body'    => Blocks::sections_to_text( $sections, mb_substr( sanitize_text_field( (string) ( $d['cta'] ?? '' ) ), 0, 140 ) ),
		);
	}

	/**
	 * Social fields from AI posts, one per requested network, fitted to each limit.
	 *
	 * @param array    $posts    Posts.
	 * @param string[] $networks Networks.
	 * @return array
	 */
	public static function social( array $posts, array $networks ) {
		$out = array();
		foreach ( $posts as $p ) {
			$n = strtolower( preg_replace( '/[^a-z]/i', '', (string) ( $p['network'] ?? '' ) ) );
			$n = 'twitter' === $n ? 'x' : $n;
			if ( ! in_array( $n, $networks, true ) || isset( $out[ $n ] ) ) {
				continue;
			}
			$text = (string) ( $p['text'] ?? '' );
			if ( '' === trim( $text ) ) {
				continue;
			}
			$out[ $n ] = Social::compose( $text, array_map( 'strval', (array) ( $p['hashtags'] ?? array() ) ), $n );
		}
		return $out;
	}

	/**
	 * Email fields from AI data.
	 *
	 * @param array $d Data.
	 * @return array|null
	 */
	public static function email( array $d ) {
		$subject = mb_substr( sanitize_text_field( (string) ( $d['subject'] ?? '' ) ), 0, 120 );
		$body    = mb_substr( trim( sanitize_textarea_field( (string) ( $d['body'] ?? '' ) ) ), 0, 5000 );
		if ( '' === $subject || '' === $body ) {
			return null;
		}
		return array(
			'subject'   => $subject,
			'preheader' => mb_substr( sanitize_text_field( (string) ( $d['preheader'] ?? '' ) ), 0, 160 ),
			'body'      => $body,
		);
	}
}
