<?php
/**
 * Venues and organizers: search, matching (for flyer imports) and creation through TEC's API.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Tec;

defined( 'ABSPATH' ) || exit;

/**
 * Places.
 */
class Places {

	/**
	 * Lists venues or organizers.
	 *
	 * @param string $type   venue|organizer.
	 * @param string $search Search.
	 * @param int    $limit  Limit.
	 * @return array
	 */
	public static function search( $type, $search = '', $limit = 50 ) {
		$args = array(
			'post_type'      => 'organizer' === $type ? Events::ORGANIZER : Events::VENUE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => max( 1, min( 300, (int) $limit ) ),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
		);
		if ( '' !== $search ) {
			$args['s'] = mb_substr( sanitize_text_field( $search ), 0, 100 );
		}
		$ids = get_posts( $args );
		$out = array();
		foreach ( $ids as $id ) {
			$row = 'organizer' === $type ? Events::organizer( $id ) : Events::venue( $id );
			if ( $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Lower-case, accent-free, punctuation-free text without leading articles.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function norm( $s ) {
		$s = strtolower( remove_accents( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) );
		$s = preg_replace( '/[^a-z0-9 ]+/', ' ', $s );
		$s = preg_replace( '/^(the|la|le|el|der|die|das)\s+/', '', trim( preg_replace( '/\s+/', ' ', $s ) ) );
		return trim( (string) $s );
	}

	/**
	 * Similarity 0-100 of two names.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return int
	 */
	public static function similarity( $a, $b ) {
		$a = self::norm( $a );
		$b = self::norm( $b );
		if ( '' === $a || '' === $b ) {
			return 0;
		}
		if ( $a === $b ) {
			return 100;
		}
		if ( strlen( $a ) >= 4 && strlen( $b ) >= 4 && ( false !== strpos( $a, $b ) || false !== strpos( $b, $a ) ) ) {
			return 85;
		}
		similar_text( $a, $b, $pct );
		return (int) round( $pct );
	}

	/**
	 * Host of a URL without www.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host( $url ) {
		$h = (string) wp_parse_url( ( false === strpos( (string) $url, '://' ) ? 'https://' : '' ) . $url, PHP_URL_HOST );
		return preg_replace( '/^www\./', '', strtolower( $h ) );
	}

	/**
	 * Venue matches for a draft venue.
	 *
	 * @param array $v name, city, address.
	 * @return array { suggestion: existing|new|none, id, candidates: [ {id, name, full, score, reason} ] }
	 */
	public static function match_venue( array $v ) {
		$name = trim( (string) ( $v['name'] ?? '' ) );
		if ( '' === $name ) {
			return array(
				'suggestion' => 'none',
				'id'         => 0,
				'candidates' => array(),
			);
		}
		$cands = array();
		foreach ( self::search( 'venue', '', 300 ) as $row ) {
			$score  = self::similarity( $name, $row['name'] );
			$reason = 100 === $score ? 'name' : 'similar name';
			$city   = self::norm( $v['city'] ?? '' );
			if ( '' !== $city && self::norm( $row['city'] ) === $city ) {
				$score  = min( 100, $score + 10 );
				$reason = $reason . ' + city';
			} elseif ( '' !== $city && '' !== self::norm( $row['city'] ) && $score < 100 ) {
				$score -= 15;
			}
			$addr = self::norm( $v['address'] ?? '' );
			if ( '' !== $addr && '' !== self::norm( $row['address'] ) && self::norm( $row['address'] ) === $addr ) {
				$score  = max( $score, 95 );
				$reason = 'address';
			}
			if ( $score >= 50 ) {
				$cands[] = array(
					'id'     => $row['id'],
					'name'   => $row['name'],
					'full'   => $row['full'],
					'score'  => max( 0, min( 100, $score ) ),
					'reason' => $reason,
				);
			}
		}
		return self::result( $cands );
	}

	/**
	 * Organizer matches for a draft organizer.
	 *
	 * @param array $o name, email, website.
	 * @return array Same shape as match_venue().
	 */
	public static function match_organizer( array $o ) {
		$name  = trim( (string) ( $o['name'] ?? '' ) );
		$email = strtolower( trim( (string) ( $o['email'] ?? '' ) ) );
		$host  = self::host( $o['website'] ?? '' );
		if ( '' === $name && '' === $email ) {
			return array(
				'suggestion' => 'none',
				'id'         => 0,
				'candidates' => array(),
			);
		}
		$cands = array();
		foreach ( self::search( 'organizer', '', 300 ) as $row ) {
			$score  = '' !== $name ? self::similarity( $name, $row['name'] ) : 0;
			$reason = 100 === $score ? 'name' : 'similar name';
			if ( '' !== $email && strtolower( $row['email'] ) === $email ) {
				$score  = 100;
				$reason = 'email';
			} elseif ( '' !== $host && '' !== $row['website'] && self::host( $row['website'] ) === $host ) {
				$score  = max( $score, 95 );
				$reason = 'website';
			}
			if ( $score >= 50 ) {
				$cands[] = array(
					'id'     => $row['id'],
					'name'   => $row['name'],
					'full'   => implode( ' · ', array_filter( array( $row['email'], $row['website'] ) ) ),
					'score'  => $score,
					'reason' => $reason,
				);
			}
		}
		return self::result( $cands );
	}

	/**
	 * Sorted result with a suggestion.
	 *
	 * @param array $cands Candidates.
	 * @return array
	 */
	private static function result( array $cands ) {
		usort( $cands, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$cands = array_slice( $cands, 0, 3 );
		$top   = $cands ? $cands[0] : null;
		return array(
			'suggestion' => $top && $top['score'] >= 80 ? 'existing' : 'new',
			'id'         => $top && $top['score'] >= 80 ? (int) $top['id'] : 0,
			'candidates' => $cands,
		);
	}

	/**
	 * Creates a venue (TEC ORM, then legacy API, then plain post).
	 *
	 * @param array $v name, address, city, region, zip, country, phone, website.
	 * @return int|\WP_Error
	 */
	public static function create_venue( array $v ) {
		$name = mb_substr( sanitize_text_field( (string) ( $v['name'] ?? '' ) ), 0, 200 );
		if ( '' === $name ) {
			return new \WP_Error( 'dev3ec_venue_name', __( 'The venue needs a name.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$meta = array(
			'address' => '_VenueAddress',
			'city'    => '_VenueCity',
			'region'  => '_VenueStateProvince',
			'zip'     => '_VenueZip',
			'country' => '_VenueCountry',
			'phone'   => '_VenuePhone',
			'website' => '_VenueURL',
		);
		$args = array(
			'venue'  => $name,
			'status' => 'publish',
		);
		foreach ( array_keys( $meta ) as $k ) {
			if ( ! empty( $v[ $k ] ) ) {
				$args[ 'region' === $k ? 'stateprovince' : $k ] = 'website' === $k ? esc_url_raw( (string) $v[ $k ] ) : sanitize_text_field( (string) $v[ $k ] );
			}
		}
		$id = 0;
		if ( function_exists( 'tribe_venues' ) ) {
			try {
				$post = tribe_venues()->set_args( $args )->create();
				$id   = $post instanceof \WP_Post ? (int) $post->ID : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'   => Events::VENUE,
					'post_title'  => $name,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			foreach ( $meta as $k => $key ) {
				if ( ! empty( $v[ $k ] ) ) {
					update_post_meta( $id, $key, 'website' === $k ? esc_url_raw( (string) $v[ $k ] ) : sanitize_text_field( (string) $v[ $k ] ) );
				}
			}
		}
		return (int) $id;
	}

	/**
	 * Creates an organizer.
	 *
	 * @param array $o name, email, phone, website.
	 * @return int|\WP_Error
	 */
	public static function create_organizer( array $o ) {
		$name = mb_substr( sanitize_text_field( (string) ( $o['name'] ?? '' ) ), 0, 200 );
		if ( '' === $name ) {
			return new \WP_Error( 'dev3ec_organizer_name', __( 'The organizer needs a name.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$email = sanitize_email( (string) ( $o['email'] ?? '' ) );
		$vals  = array_filter(
			array(
				'email'   => is_email( $email ) ? $email : '',
				'phone'   => sanitize_text_field( (string) ( $o['phone'] ?? '' ) ),
				'website' => esc_url_raw( (string) ( $o['website'] ?? '' ) ),
			)
		);
		$id    = 0;
		if ( function_exists( 'tribe_organizers' ) ) {
			try {
				$post = tribe_organizers()->set_args(
					array_merge(
						array(
							'organizer' => $name,
							'status'    => 'publish',
						),
						$vals
					)
				)->create();
				$id   = $post instanceof \WP_Post ? (int) $post->ID : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		if ( ! $id ) {
			$id = wp_insert_post(
				array(
					'post_type'   => Events::ORGANIZER,
					'post_title'  => $name,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$keys = array(
				'email'   => '_OrganizerEmail',
				'phone'   => '_OrganizerPhone',
				'website' => '_OrganizerWebsite',
			);
			foreach ( $vals as $k => $val ) {
				update_post_meta( $id, $keys[ $k ], $val );
			}
		}
		return (int) $id;
	}
}
