<?php
/**
 * SEO meta for events: writes into Yoast SEO or Rank Math when active, otherwise prints its own
 * meta description / Open Graph tags and document title.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Seo;

use Dev3\AiEventsCalendar\Settings;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Seo.
 */
class Seo {

	const META = '_dev3ec_seo';

	/**
	 * Active SEO plugin.
	 *
	 * @return string yoast|rankmath|native
	 */
	public static function adapter() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			$id = 'yoast';
		} elseif ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
			$id = 'rankmath';
		} else {
			$id = 'native';
		}
		/**
		 * Filters the SEO adapter (yoast|rankmath|native).
		 *
		 * @param string $id Adapter.
		 */
		return (string) apply_filters( 'dev3ec_seo_adapter', $id );
	}

	/**
	 * Label of the adapter.
	 *
	 * @return string
	 */
	public static function label() {
		$map = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
		);
		return $map[ self::adapter() ] ?? __( 'DEV3 (built-in meta tags)', 'dev3-ai-events-calendar' );
	}

	/**
	 * Meta keys of the adapter.
	 *
	 * @return array { title, description }
	 */
	private static function keys() {
		switch ( self::adapter() ) {
			case 'yoast':
				return array(
					'title'       => '_yoast_wpseo_title',
					'description' => '_yoast_wpseo_metadesc',
				);
			case 'rankmath':
				return array(
					'title'       => 'rank_math_title',
					'description' => 'rank_math_description',
				);
		}
		return array();
	}

	/**
	 * Current SEO title + description.
	 *
	 * @param int $id Event.
	 * @return array
	 */
	public static function current( $id ) {
		$own  = get_post_meta( $id, self::META, true );
		$own  = is_array( $own ) ? $own : array();
		$keys = self::keys();
		if ( $keys ) {
			$title = (string) get_post_meta( $id, $keys['title'], true );
			$desc  = (string) get_post_meta( $id, $keys['description'], true );
			return array(
				'title'       => '' !== $title ? $title : (string) ( $own['title'] ?? '' ),
				'description' => '' !== $desc ? $desc : (string) ( $own['description'] ?? '' ),
			);
		}
		return array(
			'title'       => (string) ( $own['title'] ?? '' ),
			'description' => (string) ( $own['description'] ?? '' ),
		);
	}

	/**
	 * Writes SEO values (own meta always; the SEO plugin's fields when active).
	 *
	 * @param int   $id     Event.
	 * @param array $fields { title, description }.
	 */
	public static function write( $id, array $fields ) {
		$vals = array(
			'title'       => (string) ( $fields['title'] ?? '' ),
			'description' => (string) ( $fields['description'] ?? '' ),
		);
		if ( '' === $vals['title'] && '' === $vals['description'] ) {
			delete_post_meta( $id, self::META );
		} else {
			update_post_meta( $id, self::META, $vals );
		}
		foreach ( self::keys() as $field => $key ) {
			if ( '' === $vals[ $field ] ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $vals[ $field ] );
			}
		}
		/**
		 * Fires after DEV3 wrote SEO meta for an event.
		 *
		 * @param int    $id      Event.
		 * @param array  $vals    Values.
		 * @param string $adapter Adapter.
		 */
		do_action( 'dev3ec_seo_written', $id, $vals, self::adapter() );
	}

	/**
	 * Front-end hooks (native adapter only).
	 */
	public static function register() {
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		add_action( 'wp_head', array( self::class, 'head' ), 2 );
	}

	/**
	 * Native output enabled for the current request.
	 *
	 * @return array|null Values.
	 */
	private static function native_values() {
		if ( 'native' !== self::adapter() || 'off' === Settings::get( 'seo_output', 'auto' ) || ! is_singular( Events::POST_TYPE ) ) {
			return null;
		}
		$v = get_post_meta( get_queried_object_id(), self::META, true );
		return is_array( $v ) ? $v : null;
	}

	/**
	 * Document title.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function document_title( $title ) {
		$v = self::native_values();
		return $v && ! empty( $v['title'] ) ? (string) $v['title'] : $title;
	}

	/**
	 * Meta tags.
	 */
	public static function head() {
		$v = self::native_values();
		if ( ! $v ) {
			return;
		}
		if ( ! empty( $v['description'] ) ) {
			echo '<meta name="description" content="' . esc_attr( $v['description'] ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $v['description'] ) . '" />' . "\n";
		}
		if ( ! empty( $v['title'] ) ) {
			echo '<meta property="og:title" content="' . esc_attr( $v['title'] ) . '" />' . "\n";
		}
		echo '<meta property="og:type" content="website" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( get_permalink( get_queried_object_id() ) ) . '" />' . "\n";
		$img = get_the_post_thumbnail_url( get_queried_object_id(), 'large' );
		if ( $img ) {
			echo '<meta property="og:image" content="' . esc_url( $img ) . '" />' . "\n";
		}
	}
}
