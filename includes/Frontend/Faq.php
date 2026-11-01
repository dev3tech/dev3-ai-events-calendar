<?php
/**
 * Front-end FAQ for events: appended to the single event page, `[dev3ec_faq]` shortcode and the
 * "Event FAQ" block, with FAQPage JSON-LD. Neutral markup (no DEV3 branding).
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Frontend;

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Settings;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Faq.
 */
class Faq {

	/**
	 * Items rendered on this request (for JSON-LD).
	 *
	 * @var array
	 */
	private static $rendered = array();

	/**
	 * Hooks.
	 */
	public static function register() {
		add_shortcode( 'dev3ec_faq', array( self::class, 'shortcode' ) );
		add_filter( 'the_content', array( self::class, 'append' ), 20 );
		add_action( 'wp_footer', array( self::class, 'schema' ) );
		add_action( 'init', array( self::class, 'block' ) );
		add_action( 'init', array( self::class, 'register_style' ), 4 );
	}

	/**
	 * Registers the stylesheet.
	 */
	public static function register_style() {
		wp_register_style( 'dev3ec-faq', DEV3EC_URL . 'assets/css/faq.css', array(), DEV3EC_VERSION );
	}

	/**
	 * Registers the dynamic block (editor script from build/blocks.js).
	 */
	public static function block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'dev3ec/event-faq',
			array(
				'api_version'     => 2,
				'editor_script'   => is_readable( DEV3EC_PATH . 'build/blocks.js' ) ? \Dev3\AiEventsCalendar\Core3\Core\Assets::handle( 'blocks' ) : null,
				'render_callback' => array( self::class, 'render_block' ),
				'style'           => 'dev3ec-faq',
				'attributes'      => array(
					'eventId' => array(
						'type'    => 'number',
						'default' => 0,
					),
					'heading' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'supports'        => array(
					'align'   => array( 'wide', 'full' ),
					'spacing' => array( 'margin' => true ),
				),
			)
		);
	}

	/**
	 * Block render.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public static function render_block( $attrs ) {
		return self::render( (int) ( $attrs['eventId'] ?? 0 ), (string) ( $attrs['heading'] ?? '' ) );
	}

	/**
	 * Shortcode.
	 *
	 * @param array|string $atts Attributes (id, heading).
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'      => 0,
				'heading' => '',
			),
			(array) $atts,
			'dev3ec_faq'
		);
		return self::render( (int) $atts['id'], (string) $atts['heading'] );
	}

	/**
	 * Appends the FAQ on single event pages (setting "append").
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function append( $content ) {
		if ( 'append' !== Settings::get( 'faq_display', 'append' ) || ! is_singular( Events::POST_TYPE ) || is_admin() || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		$id = (int) get_the_ID();
		if ( has_shortcode( $content, 'dev3ec_faq' ) || has_block( 'dev3ec/event-faq', $content ) || false !== strpos( $content, 'dev3ec-faq-item' ) ) {
			return $content;
		}
		return $content . self::render( $id );
	}

	/**
	 * FAQ HTML.
	 *
	 * @param int    $id      Event (0 = current post).
	 * @param string $heading Heading.
	 * @return string
	 */
	public static function render( $id = 0, $heading = '' ) {
		$id = $id ? $id : (int) get_the_ID();
		if ( ! Events::is_event( $id ) || isset( self::$rendered[ $id ] ) ) {
			return '';
		}
		$items = get_post_meta( $id, Assets::meta_key( 'faq' ), true );
		$items = is_array( $items ) ? Assets::clean_faq( $items ) : array();
		if ( ! $items ) {
			return '';
		}
		self::$rendered[ $id ] = $items;
		wp_enqueue_style( 'dev3ec-faq' );
		$heading = '' !== $heading ? $heading : __( 'Frequently asked questions', 'dev3-ai-events-calendar' );
		$html    = '<section class="dev3ec-faq" aria-labelledby="dev3ec-faq-h-' . esc_attr( (string) $id ) . '">';
		$html   .= '<h2 class="dev3ec-faq__title" id="dev3ec-faq-h-' . esc_attr( (string) $id ) . '">' . esc_html( $heading ) . '</h2>';
		foreach ( $items as $it ) {
			$html .= '<details class="dev3ec-faq__item"><summary class="dev3ec-faq__q">' . esc_html( $it['q'] ) . '</summary><div class="dev3ec-faq__a">' . wpautop( esc_html( $it['a'] ) ) . '</div></details>';
		}
		return $html . '</section>';
	}

	/**
	 * FAQPage JSON-LD for rendered FAQs.
	 */
	public static function schema() {
		if ( ! self::$rendered || ! Settings::get( 'faq_schema', true ) ) {
			return;
		}
		$entities = array();
		foreach ( self::$rendered as $items ) {
			foreach ( $items as $it ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $it['q'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $it['a'],
					),
				);
			}
		}
		$data = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . '</script>' . "\n";
	}

	/**
	 * Test helper.
	 */
	public static function reset() {
		self::$rendered = array();
	}
}
