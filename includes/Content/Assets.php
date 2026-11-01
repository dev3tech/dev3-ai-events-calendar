<?php
/**
 * Saved writer outputs per event ("assets"): kinds, cleaning, current values (for diffs) and saving.
 *
 * Every kind has a flat "fields" shape (strings only) used by drafts, diffs and editing:
 * - description: { excerpt, body } (body in the Blocks text format)
 * - social: { <network>: text }
 * - email_promo / email_reminder / email_recap: { subject, preheader, body }
 * - faq: { faq } ("Q: … / A: …" blocks)
 * - seo: { title, description }
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Content;

use Dev3\AiEventsCalendar\Seo\Seo;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Assets.
 */
class Assets {

	const KINDS = array( 'description', 'social', 'email_promo', 'email_reminder', 'email_recap', 'faq', 'seo' );

	const EMAILS = array( 'email_promo', 'email_reminder', 'email_recap' );

	/**
	 * Field limits.
	 */
	const LIMITS = array(
		'excerpt'     => 400,
		'body'        => 8000,
		'subject'     => 120,
		'preheader'   => 160,
		'faq'         => 8000,
		'title'       => 90,
		'description' => 300,
	);

	/**
	 * Valid kind.
	 *
	 * @param string $kind Kind.
	 * @return bool
	 */
	public static function valid( $kind ) {
		return in_array( $kind, self::KINDS, true );
	}

	/**
	 * Post meta key of a kind (description lives in the post itself).
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function meta_key( $kind ) {
		$map = array(
			'social'         => '_dev3ec_social',
			'email_promo'    => '_dev3ec_email_promo',
			'email_reminder' => '_dev3ec_email_reminder',
			'email_recap'    => '_dev3ec_email_recap',
			'faq'            => '_dev3ec_faq',
			'seo'            => '_dev3ec_seo',
		);
		return $map[ $kind ] ?? '';
	}

	/**
	 * FAQ items from the text format.
	 *
	 * @param string $text Text.
	 * @return array [ { q, a } ]
	 */
	public static function faq_items( $text ) {
		$items = array();
		$cur   = null;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$t = trim( $line );
			if ( preg_match( '/^(?:Q|Question)\s*[:.)]\s*(.+)$/iu', $t, $m ) ) {
				if ( $cur && '' !== $cur['q'] ) {
					$items[] = $cur;
				}
				$cur = array(
					'q' => $m[1],
					'a' => '',
				);
			} elseif ( $cur && preg_match( '/^(?:A|Answer)\s*[:.)]\s*(.*)$/iu', $t, $m ) ) {
				$cur['a'] = trim( $cur['a'] . ' ' . $m[1] );
			} elseif ( $cur && '' !== $t ) {
				$cur['a'] = trim( $cur['a'] . ' ' . $t );
			}
		}
		if ( $cur && '' !== $cur['q'] ) {
			$items[] = $cur;
		}
		return self::clean_faq( $items );
	}

	/**
	 * Cleans FAQ items.
	 *
	 * @param array $items Raw.
	 * @return array
	 */
	public static function clean_faq( array $items ) {
		$out = array();
		foreach ( $items as $it ) {
			$q = mb_substr( sanitize_text_field( (string) ( $it['q'] ?? '' ) ), 0, 200 );
			$a = mb_substr( sanitize_textarea_field( (string) ( $it['a'] ?? '' ) ), 0, 1000 );
			if ( '' !== $q && '' !== $a ) {
				$out[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}
		return array_slice( $out, 0, 12 );
	}

	/**
	 * FAQ text format.
	 *
	 * @param array $items Items.
	 * @return string
	 */
	public static function faq_text( array $items ) {
		return implode( "\n\n", array_map( static fn( $it ) => 'Q: ' . $it['q'] . "\nA: " . $it['a'], $items ) );
	}

	/**
	 * Cleans a fields object for a kind.
	 *
	 * @param string $kind   Kind.
	 * @param array  $fields Raw fields.
	 * @return array
	 */
	public static function clean( $kind, array $fields ) {
		$s = static function ( $key, $multiline = false ) use ( $fields ) {
			$v = (string) ( $fields[ $key ] ?? '' );
			$v = $multiline ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
			return mb_substr( trim( $v ), 0, self::LIMITS[ $key ] ?? 2000 );
		};
		switch ( $kind ) {
			case 'description':
				return array(
					'excerpt' => $s( 'excerpt', true ),
					'body'    => $s( 'body', true ),
				);
			case 'social':
				$out = array();
				foreach ( Social::NETWORKS as $id => $n ) {
					if ( isset( $fields[ $id ] ) ) {
						$out[ $id ] = mb_substr( trim( sanitize_textarea_field( (string) $fields[ $id ] ) ), 0, $n['limit'] + 200 );
					}
				}
				return $out;
			case 'faq':
				return array( 'faq' => self::faq_text( self::faq_items( (string) ( $fields['faq'] ?? '' ) ) ) );
			case 'seo':
				return array(
					'title'       => $s( 'title' ),
					'description' => $s( 'description', true ),
				);
			default:
				if ( in_array( $kind, self::EMAILS, true ) ) {
					return array(
						'subject'   => $s( 'subject' ),
						'preheader' => $s( 'preheader' ),
						'body'      => $s( 'body', true ),
					);
				}
		}
		return array();
	}

	/**
	 * Whether a cleaned value is empty.
	 *
	 * @param array $fields Fields.
	 * @return bool
	 */
	public static function is_empty( array $fields ) {
		return '' === trim( implode( '', array_map( 'strval', $fields ) ) );
	}

	/**
	 * Current value of a kind (the "original" side of a diff).
	 *
	 * @param int    $id   Event.
	 * @param string $kind Kind.
	 * @return array
	 */
	public static function current( $id, $kind ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return array();
		}
		if ( 'description' === $kind ) {
			return array(
				'excerpt' => (string) $post->post_excerpt,
				'body'    => Blocks::description_text( $post->post_content ),
			);
		}
		if ( 'seo' === $kind ) {
			return Seo::current( $id );
		}
		$v = get_post_meta( $id, self::meta_key( $kind ), true );
		$v = is_array( $v ) ? $v : array();
		if ( 'faq' === $kind ) {
			return array( 'faq' => self::faq_text( self::clean_faq( $v ) ) );
		}
		return self::clean( $kind, $v );
	}

	/**
	 * Whether an event already has content of a kind.
	 *
	 * @param int    $id   Event.
	 * @param string $kind Kind.
	 * @return bool
	 */
	public static function has( $id, $kind ) {
		if ( 'description' === $kind ) {
			return mb_strlen( Blocks::description_text( (string) get_post_field( 'post_content', $id ) ) ) >= 300;
		}
		return ! self::is_empty( self::current( $id, $kind ) );
	}

	/**
	 * Saves a kind (server side). Description writes the post content + excerpt.
	 *
	 * @param int    $id     Event.
	 * @param string $kind   Kind.
	 * @param array  $fields Fields.
	 * @param array  $opts   mode (replace|append) for descriptions.
	 * @return array|\WP_Error Saved (cleaned) value.
	 */
	public static function save( $id, $kind, array $fields, array $opts = array() ) {
		if ( ! Events::is_event( $id ) || ! self::valid( $kind ) ) {
			return new \WP_Error( 'dev3ec_bad_event', __( 'Event not found.', 'dev3-ai-events-calendar' ), array( 'status' => 404 ) );
		}
		$clean = self::clean( $kind, $fields );
		if ( 'description' === $kind ) {
			$post = get_post( $id );
			$r    = wp_update_post(
				array(
					'ID'           => $id,
					'post_excerpt' => $clean['excerpt'],
					'post_content' => wp_slash( Blocks::apply_description( $post->post_content, $clean['body'], (string) ( $opts['mode'] ?? 'replace' ) ) ),
				),
				true
			);
			return is_wp_error( $r ) ? $r : $clean;
		}
		if ( 'seo' === $kind ) {
			Seo::write( $id, $clean );
			return $clean;
		}
		if ( 'faq' === $kind ) {
			$items = self::faq_items( $clean['faq'] );
			if ( $items ) {
				update_post_meta( $id, self::meta_key( 'faq' ), $items );
			} else {
				delete_post_meta( $id, self::meta_key( 'faq' ) );
			}
			return $clean;
		}
		if ( self::is_empty( $clean ) ) {
			delete_post_meta( $id, self::meta_key( $kind ) );
		} else {
			if ( 'social' === $kind ) {
				$prev  = get_post_meta( $id, self::meta_key( 'social' ), true );
				$clean = array_filter( array_merge( is_array( $prev ) ? $prev : array(), $clean ), 'strlen' );
			}
			update_post_meta( $id, self::meta_key( $kind ), $clean );
		}
		return $clean;
	}

	/**
	 * Every saved asset of an event (for the UI).
	 *
	 * @param int $id Event.
	 * @return array kind => fields
	 */
	public static function all( $id ) {
		$out = array();
		foreach ( self::KINDS as $k ) {
			$out[ $k ] = self::current( $id, $k );
		}
		return $out;
	}
}
