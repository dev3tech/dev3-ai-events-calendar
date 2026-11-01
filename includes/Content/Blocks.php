<?php
/**
 * Event descriptions as text, native block markup and classic HTML, and safe replacement of the
 * description inside an event that also holds TEC's own blocks.
 *
 * Text format (used for diffs and editing): "## Heading" lines, blank-line separated paragraphs,
 * "- item" lines for lists.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks.
 */
class Blocks {

	/**
	 * Core blocks that count as "description" (replaced on accept). TEC blocks, images, embeds
	 * and anything else stay.
	 */
	const TEXT_BLOCKS = array( 'core/paragraph', 'core/heading', 'core/list', 'core/quote' );

	/**
	 * Sections from the text format.
	 *
	 * @param string $text Text.
	 * @return array [ { heading, body } ]
	 */
	public static function text_to_sections( $text ) {
		$sections = array();
		$current  = array(
			'heading' => '',
			'body'    => '',
		);
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( preg_match( '/^#{1,3}\s+(.+)$/', trim( $line ), $m ) ) {
				if ( '' !== trim( $current['body'] ) || '' !== $current['heading'] ) {
					$sections[] = $current;
				}
				$current = array(
					'heading' => trim( $m[1] ),
					'body'    => '',
				);
				continue;
			}
			$current['body'] .= $line . "\n";
		}
		if ( '' !== trim( $current['body'] ) || '' !== $current['heading'] ) {
			$sections[] = $current;
		}
		foreach ( $sections as &$s ) {
			$s['heading'] = mb_substr( sanitize_text_field( $s['heading'] ), 0, 120 );
			$s['body']    = trim( sanitize_textarea_field( $s['body'] ) );
		}
		unset( $s );
		return array_values( array_filter( $sections, static fn( $s ) => '' !== $s['heading'] || '' !== $s['body'] ) );
	}

	/**
	 * Text format from sections.
	 *
	 * @param array  $sections Sections.
	 * @param string $cta      Call to action (appended as the last paragraph).
	 * @return string
	 */
	public static function sections_to_text( array $sections, $cta = '' ) {
		$out = array();
		foreach ( $sections as $s ) {
			$chunk = '';
			if ( '' !== (string) ( $s['heading'] ?? '' ) ) {
				$chunk .= '## ' . $s['heading'] . "\n\n";
			}
			$chunk .= trim( (string) ( $s['body'] ?? '' ) );
			$out[]  = trim( $chunk );
		}
		if ( '' !== trim( (string) $cta ) ) {
			$out[] = trim( (string) $cta );
		}
		return implode( "\n\n", array_filter( $out ) );
	}

	/**
	 * Paragraph chunks of a body: [ type => p|ul, lines|text ].
	 *
	 * @param string $body Body.
	 * @return array
	 */
	private static function chunks( $body ) {
		$out = array();
		foreach ( preg_split( '/\n\s*\n/', trim( (string) $body ) ) as $p ) {
			$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $p ) ), 'strlen' ) );
			if ( ! $lines ) {
				continue;
			}
			$bullets = array_filter( $lines, static fn( $l ) => (bool) preg_match( '/^[-•*]\s+/u', $l ) );
			if ( count( $bullets ) === count( $lines ) ) {
				$out[] = array(
					'type'  => 'ul',
					'lines' => array_map( static fn( $l ) => preg_replace( '/^[-•*]\s+/u', '', $l ), $lines ),
				);
			} else {
				$out[] = array(
					'type' => 'p',
					'text' => implode( "\n", $lines ),
				);
			}
		}
		return $out;
	}

	/**
	 * Inline markup for a line: escapes, keeps **bold** and bare links clickable.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function inline( $s ) {
		$h = esc_html( $s );
		$h = preg_replace( '/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $h );
		return preg_replace( '~(https?://[^\s<]+[^\s<.,;:!?)])~i', '<a href="$1">$1</a>', $h );
	}

	/**
	 * Native block markup (core/heading, core/paragraph, core/list).
	 *
	 * @param string $text Text format.
	 * @return string
	 */
	public static function to_blocks( $text ) {
		$out = '';
		foreach ( self::text_to_sections( $text ) as $s ) {
			if ( '' !== $s['heading'] ) {
				$out .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $s['heading'] ) . "</h2>\n<!-- /wp:heading -->\n\n";
			}
			foreach ( self::chunks( $s['body'] ) as $c ) {
				if ( 'ul' === $c['type'] ) {
					$out .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
					foreach ( $c['lines'] as $l ) {
						$out .= "<!-- wp:list-item -->\n<li>" . self::inline( $l ) . "</li>\n<!-- /wp:list-item -->";
					}
					$out .= "</ul>\n<!-- /wp:list -->\n\n";
				} else {
					$out .= "<!-- wp:paragraph -->\n<p>" . implode( '<br>', array_map( array( self::class, 'inline' ), explode( "\n", $c['text'] ) ) ) . "</p>\n<!-- /wp:paragraph -->\n\n";
				}
			}
		}
		return wp_kses_post( trim( $out ) );
	}

	/**
	 * Classic editor HTML.
	 *
	 * @param string $text Text format.
	 * @return string
	 */
	public static function to_html( $text ) {
		$out = '';
		foreach ( self::text_to_sections( $text ) as $s ) {
			if ( '' !== $s['heading'] ) {
				$out .= '<h2>' . esc_html( $s['heading'] ) . "</h2>\n";
			}
			foreach ( self::chunks( $s['body'] ) as $c ) {
				if ( 'ul' === $c['type'] ) {
					$out .= "<ul>\n" . implode( '', array_map( static fn( $l ) => '<li>' . self::inline( $l ) . "</li>\n", $c['lines'] ) ) . "</ul>\n";
				} else {
					$out .= '<p>' . implode( '<br>', array_map( array( self::class, 'inline' ), explode( "\n", $c['text'] ) ) ) . "</p>\n";
				}
			}
		}
		return wp_kses_post( trim( $out ) );
	}

	/**
	 * Plain text of an HTML fragment with headings as "## " and list items as "- ".
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_text( $html ) {
		$h = preg_replace( '~</li>\s*<li~i', '</li><li', (string) $html );
		$h = preg_replace( '~<h[1-6][^>]*>(.*?)</h[1-6]>~is', "\n\n## $1\n\n", $h );
		$h = preg_replace( '~<li[^>]*>(.*?)</li>~is', "\n- $1", $h );
		$h = preg_replace( '~<br\s*/?>~i', "\n", $h );
		$h = preg_replace( '~</(p|ul|ol|blockquote|div)>~i', "\n\n", $h );
		$h = html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' );
		$h = preg_replace( "/[ \t]+/", ' ', $h );
		$h = preg_replace( "/ *\n */", "\n", $h );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $h ) );
	}

	/**
	 * Description text of an event (TEC blocks are skipped).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function description_text( $content ) {
		$content = (string) $content;
		if ( ! has_blocks( $content ) ) {
			return self::html_text( $content );
		}
		$parts = array();
		foreach ( parse_blocks( $content ) as $b ) {
			if ( empty( $b['blockName'] ) || 0 === strpos( $b['blockName'], 'tribe/' ) ) {
				continue;
			}
			if ( in_array( $b['blockName'], self::TEXT_BLOCKS, true ) ) {
				$parts[] = self::html_text( render_block( $b ) );
			}
		}
		return trim( implode( "\n\n", array_filter( $parts ) ) );
	}

	/**
	 * Replaces the description of an event: TEC blocks and non-text blocks stay; the new blocks go
	 * where the first old text block was (or after TEC's date block, or at the top).
	 *
	 * @param string $content Current content.
	 * @param string $text    New description (text format).
	 * @param string $mode    replace|append.
	 * @return string
	 */
	public static function apply_description( $content, $text, $mode = 'replace' ) {
		$content = (string) $content;
		if ( '' !== trim( $content ) && ! has_blocks( $content ) ) {
			return 'append' === $mode ? trim( $content ) . "\n\n" . self::to_html( $text ) : self::to_html( $text );
		}
		$new    = parse_blocks( self::to_blocks( $text ) );
		$new    = array_values( array_filter( $new, static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$blocks = array_values( array_filter( parse_blocks( $content ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$at     = null;
		$keep   = array();
		foreach ( $blocks as $b ) {
			$is_text = in_array( $b['blockName'], self::TEXT_BLOCKS, true );
			if ( $is_text && 'replace' === $mode ) {
				if ( null === $at ) {
					$at = count( $keep );
				}
				continue;
			}
			$keep[] = $b;
		}
		if ( null === $at ) {
			$at = 0;
			foreach ( $keep as $i => $b ) {
				if ( 'tribe/event-datetime' === $b['blockName'] || 'tribe/featured-image' === $b['blockName'] ) {
					$at = $i + 1;
				}
			}
			if ( 'append' === $mode ) {
				$last_text = null;
				foreach ( $keep as $i => $b ) {
					if ( in_array( $b['blockName'], self::TEXT_BLOCKS, true ) ) {
						$last_text = $i + 1;
					}
				}
				$at = null !== $last_text ? $last_text : $at;
			}
		}
		array_splice( $keep, $at, 0, $new );
		$out = array();
		foreach ( $keep as $b ) {
			$out[] = serialize_block( $b );
		}
		return implode( "\n\n", $out );
	}

	/**
	 * FAQ as native core/details blocks (with a heading).
	 *
	 * @param array  $items   [ { q, a } ].
	 * @param string $heading Heading.
	 * @return string
	 */
	public static function faq_blocks( array $items, $heading ) {
		$out = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $heading ) . "</h2>\n<!-- /wp:heading -->\n\n";
		foreach ( $items as $it ) {
			$out .= "<!-- wp:details {\"className\":\"dev3ec-faq-item\"} -->\n<details class=\"wp-block-details dev3ec-faq-item\"><summary>" . esc_html( $it['q'] ) . "</summary><!-- wp:paragraph -->\n<p>" . esc_html( $it['a'] ) . "</p>\n<!-- /wp:paragraph --></details>\n<!-- /wp:details -->\n\n";
		}
		return trim( $out );
	}
}
