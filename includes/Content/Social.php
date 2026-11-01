<?php
/**
 * Social networks, their character limits and counting rules (mirrored in src/shared/social.js).
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Social.
 */
class Social {

	/**
	 * Networks: hard limit, recommended length, whether links count as 23 characters, whether
	 * links are clickable in the post body.
	 */
	const NETWORKS = array(
		'x'         => array(
			'label' => 'X (Twitter)',
			'limit' => 280,
			'ideal' => 240,
			'url23' => true,
			'links' => true,
		),
		'facebook'  => array(
			'label' => 'Facebook',
			'limit' => 2000,
			'ideal' => 500,
			'url23' => false,
			'links' => true,
		),
		'instagram' => array(
			'label' => 'Instagram',
			'limit' => 2200,
			'ideal' => 1000,
			'url23' => false,
			'links' => false,
		),
		'linkedin'  => array(
			'label' => 'LinkedIn',
			'limit' => 3000,
			'ideal' => 1300,
			'url23' => false,
			'links' => true,
		),
		'threads'   => array(
			'label' => 'Threads',
			'limit' => 500,
			'ideal' => 400,
			'url23' => false,
			'links' => true,
		),
		'bluesky'   => array(
			'label' => 'Bluesky',
			'limit' => 300,
			'ideal' => 280,
			'url23' => false,
			'links' => true,
		),
		'mastodon'  => array(
			'label' => 'Mastodon',
			'limit' => 500,
			'ideal' => 400,
			'url23' => true,
			'links' => true,
		),
	);

	const URL_RE = '~https?://[^\s<>"\']+~i';

	/**
	 * Valid network ids from input (falls back to the settings, then all).
	 *
	 * @param mixed $requested Requested.
	 * @return string[]
	 */
	public static function networks( $requested = null ) {
		$ids = array_values( array_intersect( array_keys( self::NETWORKS ), array_map( 'strval', (array) $requested ) ) );
		if ( ! $ids ) {
			$ids = array_values( array_intersect( array_keys( self::NETWORKS ), (array) \Dev3\AiEventsCalendar\Settings::get( 'networks', array() ) ) );
		}
		return $ids ? $ids : array( 'x', 'facebook', 'instagram', 'linkedin' );
	}

	/**
	 * Length of a post as the network counts it.
	 *
	 * @param string $text    Text.
	 * @param string $network Network.
	 * @return int
	 */
	public static function length( $text, $network ) {
		$text = (string) $text;
		if ( ! empty( self::NETWORKS[ $network ]['url23'] ) ) {
			$text = preg_replace( self::URL_RE, str_repeat( 'x', 23 ), $text );
		}
		return mb_strlen( $text );
	}

	/**
	 * Fits a post into the limit: drops trailing hashtags first, then shortens the text on a word
	 * boundary (keeping a trailing link intact).
	 *
	 * @param string $text    Text.
	 * @param string $network Network.
	 * @return string
	 */
	public static function fit( $text, $network ) {
		$text  = trim( preg_replace( "/[ \t]+/", ' ', (string) $text ) );
		$limit = (int) ( self::NETWORKS[ $network ]['limit'] ?? 280 );
		while ( self::length( $text, $network ) > $limit && preg_match( '/\s#[\p{L}\p{N}_]+\s*$/u', $text ) ) {
			$text = rtrim( preg_replace( '/\s#[\p{L}\p{N}_]+\s*$/u', '', $text ) );
		}
		if ( self::length( $text, $network ) <= $limit ) {
			return $text;
		}
		$link = '';
		if ( preg_match( '~\s(https?://\S+)\s*$~', $text, $m ) ) {
			$link = $m[1];
			$text = rtrim( substr( $text, 0, -strlen( $m[0] ) ) );
		}
		$room = $limit - ( '' !== $link ? self::length( ' ' . $link, $network ) : 0 ) - 1;
		while ( self::length( $text, $network ) > $room && false !== strpos( $text, ' ' ) ) {
			$text = substr( $text, 0, (int) strrpos( $text, ' ' ) );
		}
		$text = rtrim( $text, ' ,.;:-' ) . '…';
		return '' !== $link ? $text . ' ' . $link : $text;
	}

	/**
	 * Builds the post text from AI output: text + hashtags, fitted to the network.
	 *
	 * @param string   $text     Text.
	 * @param string[] $hashtags Hashtags (without #).
	 * @param string   $network  Network.
	 * @return string
	 */
	public static function compose( $text, array $hashtags, $network ) {
		$tags = array();
		foreach ( $hashtags as $h ) {
			$h = preg_replace( '/[^\p{L}\p{N}_]/u', '', (string) $h );
			if ( '' !== $h && false === stripos( $text, '#' . $h ) ) {
				$tags[] = '#' . $h;
			}
		}
		$tags = array_slice( array_unique( $tags ), 0, 6 );
		$out  = trim( sanitize_textarea_field( (string) $text ) );
		if ( $tags ) {
			$out .= ( 'instagram' === $network ? "\n\n" : ' ' ) . implode( ' ', $tags );
		}
		return self::fit( $out, $network );
	}
}
