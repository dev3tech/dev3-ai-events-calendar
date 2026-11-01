<?php
/**
 * Content building blocks: descriptions as blocks, social limits, saved assets, templates, TEC facts.
 *
 * @package Dev3\AiEventsCalendar
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Content\Blocks;
use Dev3\AiEventsCalendar\Content\Social;
use Dev3\AiEventsCalendar\Content\Templates;
use Dev3\AiEventsCalendar\Tec\Events;
use Dev3\AiEventsCalendar\Tec\Places;

class ContentTest extends Dev3ec_Base_Case {

	public function test_tec_is_loaded_and_events_are_created_through_its_api() {
		$this->assertTrue( Events::active() );
		$venue = $this->venue();
		$org   = $this->organizer();
		$id    = $this->event(
			array(
				'venue_id'      => $venue,
				'organizer_ids' => array( $org ),
			)
		);
		$e = Events::get( $id );
		$this->assertSame( 'tribe_events', get_post_type( $id ) );
		$this->assertSame( 'Blue Room', $e['venue']['name'] );
		$this->assertSame( 'Leeds', $e['venue']['city'] );
		$this->assertSame( 'City Jazz Club', $e['organizers'][0]['name'] );
		$this->assertSame( 'Europe/London', $e['timezone'] );
		$this->assertStringContainsString( '19:00', $e['start'] );
		$this->assertStringContainsString( '15', $e['cost'] );
		$this->assertFalse( $e['past'] );
		$this->assertSame( 'block', $e['editor'] );
	}

	public function test_facts_hold_event_data_only() {
		$id    = $this->event( array( 'venue_id' => $this->venue() ) );
		$facts = Events::facts( $id );
		$this->assertSame( 'Jazz Night', $facts['title'] );
		$this->assertSame( 'Blue Room', $facts['venue']['name'] );
		$this->assertStringContainsString( 'An evening of live jazz.', $facts['description'] );
		$this->assertArrayNotHasKey( 'attendees', $facts );
	}

	public function test_text_format_round_trip_to_native_blocks() {
		$text   = "## What to expect\n\nLive jazz from **three** bands.\n\n- Bar open\n- Free cloakroom\n\nBook now: https://example.org/tickets";
		$blocks = Blocks::to_blocks( $text );
		$this->assertStringContainsString( '<!-- wp:heading -->', $blocks );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">What to expect</h2>', $blocks );
		$this->assertStringContainsString( '<!-- wp:list -->', $blocks );
		$this->assertStringContainsString( '<li>Bar open</li>', $blocks );
		$this->assertStringContainsString( '<strong>three</strong>', $blocks );
		$this->assertStringContainsString( '<a href="https://example.org/tickets">', $blocks );
		$parsed = array_values( array_filter( parse_blocks( $blocks ), static fn( $b ) => ! empty( $b['blockName'] ) ) );
		$this->assertSame( array( 'core/heading', 'core/paragraph', 'core/list', 'core/paragraph' ), wp_list_pluck( $parsed, 'blockName' ) );
		$this->assertSame( "## What to expect\n\nLive jazz from three bands.\n\n- Bar open\n- Free cloakroom\n\nBook now: https://example.org/tickets", Blocks::description_text( $blocks ) );
		$this->assertStringContainsString( '<ul>', Blocks::to_html( $text ) );
		$this->assertStringNotContainsString( '<!--', Blocks::to_html( $text ) );
	}

	public function test_scripts_in_ai_text_are_escaped() {
		$this->assertStringNotContainsString( '<script', Blocks::to_blocks( "Hello <script>alert(1)</script>\n\n## <img src=x onerror=alert(1)>" ) );
	}

	public function test_replace_keeps_tec_and_media_blocks() {
		$content = "<!-- wp:tribe/event-datetime /-->\n\n<!-- wp:paragraph -->\n<p>Old.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"x.jpg\" alt=\"\"/></figure>\n<!-- /wp:image -->\n\n<!-- wp:tribe/event-venue /-->";
		$out     = Blocks::apply_description( $content, "## New\n\nFresh text." );
		$names   = wp_list_pluck( array_values( array_filter( parse_blocks( $out ), static fn( $b ) => ! empty( $b['blockName'] ) ) ), 'blockName' );
		$this->assertSame( array( 'tribe/event-datetime', 'core/heading', 'core/paragraph', 'core/image', 'tribe/event-venue' ), $names );
		$this->assertStringNotContainsString( 'Old.', $out );
		$append = Blocks::apply_description( $content, 'Extra.', 'append' );
		$this->assertStringContainsString( 'Old.', $append );
		$this->assertLessThan( strpos( $append, 'wp:image' ), strpos( $append, 'Extra.' ) );
		$this->assertGreaterThan( strpos( $append, 'Old.' ), strpos( $append, 'Extra.' ) );
	}

	public function test_empty_event_gets_text_after_the_date_block() {
		$out   = Blocks::apply_description( "<!-- wp:tribe/event-datetime /-->\n\n<!-- wp:tribe/event-venue /-->", 'Hi.' );
		$names = wp_list_pluck( array_values( array_filter( parse_blocks( $out ), static fn( $b ) => ! empty( $b['blockName'] ) ) ), 'blockName' );
		$this->assertSame( array( 'tribe/event-datetime', 'core/paragraph', 'tribe/event-venue' ), $names );
	}

	public function test_classic_content_gets_html() {
		$out = Blocks::apply_description( '<p>Old classic text.</p>', "## Title\n\nBody." );
		$this->assertSame( "<h2>Title</h2>\n<p>Body.</p>", $out );
		$this->assertStringContainsString( 'Old classic', Blocks::apply_description( '<p>Old classic text.</p>', 'More.', 'append' ) );
	}

	public function test_social_lengths_and_fitting() {
		$url = 'https://example.org/event/some-really-long-slug-for-a-jazz-night-in-leeds/';
		$this->assertSame( 4 + 23, Social::length( 'Hey ' . $url, 'x' ) );
		$this->assertSame( 4 + strlen( $url ), Social::length( 'Hey ' . $url, 'linkedin' ) );
		$long = str_repeat( 'word ', 80 ) . $url;
		$fit  = Social::fit( $long . ' #jazz #leeds', 'x' );
		$this->assertLessThanOrEqual( 280, Social::length( $fit, 'x' ) );
		$this->assertStringEndsWith( $url, $fit );
		$this->assertStringNotContainsString( '#jazz', $fit );
		$composed = Social::compose( 'Jazz tonight!', array( 'Jazz', '#Leeds', 'bad tag!' ), 'instagram' );
		$this->assertSame( "Jazz tonight!\n\n#Jazz #Leeds #badtag", $composed );
		$this->assertSame( array( 'x', 'bluesky' ), Social::networks( array( 'x', 'bluesky', 'myspace' ) ) );
	}

	public function test_faq_text_format() {
		$items = Assets::faq_items( "Q: When?\nA: Friday.\n\nQuestion: Parking?\nAnswer: Yes,\nbehind the hall.\n\nQ: No answer?" );
		$this->assertSame(
			array(
				array(
					'q' => 'When?',
					'a' => 'Friday.',
				),
				array(
					'q' => 'Parking?',
					'a' => 'Yes, behind the hall.',
				),
			),
			$items
		);
		$this->assertSame( "Q: When?\nA: Friday.\n\nQ: Parking?\nA: Yes, behind the hall.", Assets::faq_text( $items ) );
	}

	public function test_assets_save_and_current() {
		$id = $this->event();
		Assets::save(
			$id,
			'social',
			array(
				'x'        => 'Post X',
				'linkedin' => 'Post LI',
				'myspace'  => 'nope',
			)
		);
		Assets::save( $id, 'social', array( 'x' => 'Post X2' ) );
		$this->assertSame(
			array(
				'x'        => 'Post X2',
				'linkedin' => 'Post LI',
			),
			get_post_meta( $id, '_dev3ec_social', true )
		);
		Assets::save(
			$id,
			'email_recap',
			array(
				'subject' => 'Thanks <b>all</b>',
				'body'    => "Hi {attendee_name},\n\nThanks.",
			)
		);
		$this->assertSame( 'Thanks all', Assets::current( $id, 'email_recap' )['subject'] );
		$this->assertTrue( Assets::has( $id, 'email_recap' ) );
		$this->assertFalse( Assets::has( $id, 'email_promo' ) );
		Assets::save(
			$id,
			'description',
			array(
				'excerpt' => 'Short.',
				'body'    => "## About\n\nNew description.",
			)
		);
		$post = get_post( $id );
		$this->assertSame( 'Short.', $post->post_excerpt );
		$this->assertStringContainsString( '<!-- wp:tribe/event-datetime /-->', $post->post_content );
		$this->assertStringContainsString( 'New description.', $post->post_content );
		$this->assertStringNotContainsString( 'An evening of live jazz', $post->post_content );
		Assets::save( $id, 'faq', array( 'faq' => "Q: A?\nA: B." ) );
		$this->assertCount( 1, get_post_meta( $id, '_dev3ec_faq', true ) );
		$this->assertWPError( Assets::save( self::factory()->post->create(), 'faq', array() ) );
	}

	public function test_templates_work_without_ai() {
		$id = $this->event( array( 'venue_id' => $this->venue() ) );
		foreach ( Assets::KINDS as $kind ) {
			$d = Templates::draft( $id, $kind, array( 'networks' => array( 'x', 'instagram' ) ) );
			$this->assertIsArray( $d, $kind );
			$this->assertFalse( Assets::is_empty( $d ), $kind );
		}
		$social = Templates::draft( $id, 'social', array( 'networks' => array( 'x', 'instagram' ) ) );
		$this->assertStringContainsString( get_permalink( $id ), $social['x'] );
		$this->assertStringContainsString( 'Link in bio', $social['instagram'] );
		$seo = Templates::draft( $id, 'seo' );
		$this->assertLessThanOrEqual( 155, mb_strlen( $seo['description'] ) );
		$this->assertGreaterThanOrEqual( 3, count( Assets::faq_items( Templates::draft( $id, 'faq' )['faq'] ) ) );
		$vars = Templates::vars( $id, 'Ana' );
		$this->assertSame( 'Hi Ana, Jazz Night at Blue Room', Templates::fill( 'Hi {attendee_name}, {event_title} at {venue}', $vars ) );
	}

	public function test_venue_and_organizer_matching() {
		$v1 = $this->venue();
		$this->venue(
			array(
				'name' => 'Blue Room',
				'city' => 'York',
			)
		);
		$m = Places::match_venue(
			array(
				'name' => 'The Blue Room',
				'city' => 'Leeds',
			)
		);
		$this->assertSame( 'existing', $m['suggestion'] );
		$this->assertSame( $v1, $m['id'] );
		$this->assertSame( 'new', Places::match_venue( array( 'name' => 'Grand Hall' ) )['suggestion'] );
		$this->assertSame( 'none', Places::match_venue( array() )['suggestion'] );
		$o = $this->organizer();
		$this->assertSame( $o, Places::match_organizer( array( 'name' => 'Totally different', 'email' => 'HELLO@cityjazz.test' ) )['id'] );
		$this->assertSame( 'email', Places::match_organizer( array( 'email' => 'hello@cityjazz.test' ) )['candidates'][0]['reason'] );
	}

	public function test_query_filters_upcoming_past_and_missing() {
		$up   = $this->event();
		$past = $this->event(
			array(
				'title' => 'Old Gig',
				'start' => '2020-01-01 19:00:00',
				'end'   => '2020-01-01 21:00:00',
			)
		);
		Assets::save( $up, 'seo', array( 'title' => 'T' ) );
		$this->assertContains( $up, Events::query( array( 'scope' => 'upcoming' ) )['items'] );
		$this->assertNotContains( $past, Events::query( array( 'scope' => 'upcoming' ) )['items'] );
		$this->assertContains( $past, Events::query( array( 'scope' => 'past' ) )['items'] );
		$missing = Events::query(
			array(
				'scope'   => 'all',
				'missing' => 'seo',
			)
		)['items'];
		$this->assertContains( $past, $missing );
		$this->assertNotContains( $up, $missing );
		$this->assertContains( $up, Events::query( array( 'scope' => 'all', 'missing' => 'description' ) )['items'] );
	}
}
