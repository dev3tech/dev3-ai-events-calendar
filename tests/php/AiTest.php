<?php
/**
 * DEV3 AI writers and the flyer import with a mocked Gemini.
 *
 * @package Dev3\AiEventsCalendar
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiEventsCalendar\Ai\Writer;
use Dev3\AiEventsCalendar\Core3\Core\Settings_Store;
use Dev3\AiEventsCalendar\Data\Imports;
use Dev3\AiEventsCalendar\Service\Importer;
use Dev3\AiEventsCalendar\Tec\Events;

class AiTest extends Dev3ec_Base_Case {

	public function test_description_draft_from_sections() {
		$id                = $this->event( array( 'venue_id' => $this->venue() ) );
		$this->responses[] = $this->json_response(
			array(
				'excerpt'  => 'A night of jazz in Leeds.',
				'sections' => array(
					array(
						'heading' => 'What to expect',
						'body'    => "Three bands.\n\n- Bar\n- Dancing",
					),
					array(
						'heading' => 'Empty',
						'body'    => '',
					),
				),
				'cta'      => 'Grab your ticket below.',
			)
		);
		$r = Writer::generate(
			$id,
			'description',
			array(
				'tone'  => 'exciting',
				'notes' => 'Headliner: the Leeds Trio',
			)
		);
		$this->assertIsArray( $r );
		$this->assertSame( 'A night of jazz in Leeds.', $r['fields']['excerpt'] );
		$this->assertSame( "## What to expect\n\nThree bands.\n\n- Bar\n- Dancing\n\nGrab your ticket below.", $r['fields']['body'] );
		$prompt = $this->last_prompt();
		$this->assertStringContainsString( 'Blue Room', $prompt );
		$this->assertStringContainsString( 'Headliner: the Leeds Trio', $prompt );
		$this->assertStringContainsString( 'Tone: exciting', $prompt );
		$this->assertStringContainsString( 'gemini-2.5-pro', $this->requests[0]['url'] );
		$this->assertSame( 2048, $this->requests[0]['body']['generationConfig']['thinkingConfig']['thinkingBudget'] );
		$this->assertSame( 300, $this->requests[0]['body']['generationConfig']['responseSchema']['properties']['excerpt']['maxLength'] );
	}

	public function test_social_posts_fit_each_network() {
		$id                = $this->event();
		$this->responses[] = $this->json_response(
			array(
				'posts' => array(
					array(
						'network'  => 'Twitter',
						'text'     => str_repeat( 'Jazz is great. ', 30 ) . get_permalink( $id ),
						'hashtags' => array( 'jazz', 'leeds' ),
					),
					array(
						'network'  => 'linkedin',
						'text'     => 'Join us for jazz.',
						'hashtags' => array( 'music' ),
					),
					array(
						'network'  => 'myspace',
						'text'     => 'Nope',
						'hashtags' => array(),
					),
				),
			)
		);
		$r = Writer::generate( $id, 'social', array( 'networks' => array( 'x', 'linkedin' ) ) );
		$this->assertSame( array( 'x', 'linkedin' ), array_keys( $r['fields'] ) );
		$this->assertLessThanOrEqual( 280, \Dev3\AiEventsCalendar\Content\Social::length( $r['fields']['x'], 'x' ) );
		$this->assertSame( 'Join us for jazz. #music', $r['fields']['linkedin'] );
		$this->assertStringContainsString( 'max 280 characters', $this->last_prompt() );
		$this->assertStringContainsString( 'every link counts as 23 characters', $this->last_prompt() );
	}

	public function test_recap_sends_counts_but_never_attendee_details() {
		$id                     = $this->event();
		$this->attendees[ $id ] = array(
			array(
				'holder_name'  => 'Ana Secret',
				'holder_email' => 'ana.secret@example.org',
				'check_in'     => 1,
				'order_status' => 'yes',
				'ticket'       => 'General',
			),
			array(
				'holder_name'  => 'Bo Hidden',
				'holder_email' => 'bo.hidden@example.org',
				'check_in'     => 0,
				'order_status' => 'yes',
				'ticket'       => 'VIP',
			),
			array(
				'holder_name'  => 'No Show',
				'holder_email' => 'no@example.org',
				'order_status' => 'no',
			),
		);
		$this->responses[]      = $this->json_response(
			array(
				'subject'   => 'Thank you!',
				'preheader' => 'Recap',
				'body'      => "Hi {attendee_name},\n\nThanks for coming.",
			)
		);
		$r = Writer::generate( $id, 'email_recap', array( 'notes' => 'Great crowd' ) );
		$this->assertSame( 'Thank you!', $r['fields']['subject'] );
		$prompt = $this->last_prompt();
		$this->assertStringContainsString( '"registered":2', $prompt );
		$this->assertStringContainsString( '"checked_in":1', $prompt );
		foreach ( array( 'Ana Secret', 'ana.secret', 'Bo Hidden', 'bo.hidden', 'no@example' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $prompt );
		}
	}

	public function test_promo_reminder_faq_and_seo() {
		$id                = $this->event();
		$this->responses[] = $this->json_response(
			array(
				'subject'   => 'Jazz Night is coming',
				'preheader' => 'Saturday',
				'body'      => 'Hi {attendee_name}, see you.',
			)
		);
		$this->assertSame( 'Jazz Night is coming', Writer::generate( $id, 'email_reminder' )['fields']['subject'] );
		$this->assertStringContainsString( 'reminder', $this->last_prompt() );
		$this->responses = array(
			$this->json_response(
				array(
					'faq' => array(
						array(
							'q' => 'When?',
							'a' => 'Saturday.',
						),
						array(
							'q' => '',
							'a' => 'Dropped.',
						),
					),
				)
			),
		);
		$this->assertSame( "Q: When?\nA: Saturday.", Writer::generate( $id, 'faq', array( 'count' => 50 ) )['fields']['faq'] );
		$this->assertStringContainsString( 'Write 10 questions', $this->last_prompt() );
		$this->responses = array(
			$this->json_response(
				array(
					'title'       => 'Jazz Night Leeds',
					'description' => 'Live jazz on Saturday.',
				)
			),
		);
		$seo             = Writer::generate( $id, 'seo', array( 'focus' => 'jazz leeds' ) );
		$this->assertSame( 'Jazz Night Leeds', $seo['fields']['title'] );
		$this->assertStringContainsString( 'jazz leeds', $this->last_prompt() );
	}

	public function test_empty_and_error_responses() {
		$id                = $this->event();
		$this->responses[] = $this->json_response( array( 'sections' => array() ) );
		$this->assertSame( 'dev3ec_ai_empty', Writer::generate( $id, 'description' )->get_error_code() );
		$this->responses = array( array( 400, array( 'error' => array( 'message' => 'API key not valid' ) ) ) );
		$this->assertWPError( Writer::generate( $id, 'seo' ) );
		$this->assertWPError( Writer::generate( $id, 'nonsense' ) );
		$this->assertWPError( Writer::generate( self::factory()->post->create(), 'seo' ) );
	}

	public function test_ai_off_blocks_calls() {
		Settings_Store::update( array( 'ai_enabled' => false ) );
		Settings_Store::flush();
		$r = Writer::generate( $this->event(), 'seo' );
		$this->assertWPError( $r );
		$this->assertCount( 0, $this->requests );
	}

	public function test_flyer_import_reads_and_matches() {
		$venue = $this->venue();
		wp_insert_term( 'Music', 'tribe_events_cat' );
		$aid               = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->responses[] = $this->json_response(
			array(
				'title'       => 'Spring Jazz Festival',
				'start_date'  => '2027-04-03',
				'start_time'  => '7:30',
				'end_date'    => '',
				'end_time'    => '23:00',
				'timezone'    => 'Mars/Olympus',
				'cost'        => '£20',
				'venue'       => array(
					'name' => 'The Blue Room',
					'city' => 'Leeds',
				),
				'organizer'   => array(
					'name'  => 'New Org',
					'email' => 'bad-email',
				),
				'categories'  => array( 'music' ),
				'description' => "## Line-up\n\nThree bands.",
				'missing'     => array( 'website', 'nonsense' ),
				'uncertain'   => array( 'cost' ),
			)
		);
		$import = Importer::create( $aid, 'ai', 'Year is 2027' );
		$this->assertSame( 'queued', $import['status'] );
		$this->assertGreaterThan( 0, $import['job_id'] );
		\Dev3\AiEventsCalendar\Core3\Core\Jobs::run( $import['job_id'] );
		$import = Imports::get( $import['id'] );
		$this->assertSame( 'ready', $import['status'] );
		$d = $import['draft'];
		$this->assertSame( 'Spring Jazz Festival', $d['title'] );
		$this->assertSame( '07:30', $d['start_time'] );
		$this->assertSame( '2027-04-03', $d['end_date'] );
		$this->assertSame( wp_timezone_string(), $d['timezone'] );
		$this->assertSame( '', $d['organizer']['email'] );
		$this->assertSame( array( 'website' ), $d['missing'] );
		$this->assertSame( 'existing', $d['venue_match']['suggestion'] );
		$this->assertSame( $venue, $d['venue_match']['id'] );
		$this->assertSame( 'new', $d['organizer_match']['suggestion'] );
		$this->assertCount( 1, $d['category_ids'] );
		$parts = $this->requests[0]['body']['contents'][0]['parts'];
		$this->assertSame( 'image/jpeg', $parts[0]['inline_data']['mime_type'] );
		$this->assertStringContainsString( 'Year is 2027', $this->last_prompt() );
	}

	public function test_flyer_import_failure_is_stored() {
		$aid               = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->responses[] = array( 500, array( 'error' => array( 'message' => 'boom' ) ) );
		$import            = Importer::create( $aid, 'ai' );
		\Dev3\AiEventsCalendar\Core3\Core\Jobs::run( $import['job_id'] );
		$this->assertSame( 'failed', Imports::get( $import['id'] )['status'] );
	}

	public function test_create_event_from_reviewed_import() {
		$aid    = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$import = Importer::create( $aid, 'manual' );
		$this->assertSame( 'ready', $import['status'] );
		$this->assertWPError( Importer::create_event( $import['id'], array( 'title' => 'No date' ) ) );
		$r = Importer::create_event(
			$import['id'],
			array(
				'title'       => 'Poster Party',
				'start_date'  => '2027-05-01',
				'start_time'  => '20:00',
				'end_date'    => '2027-05-01',
				'end_time'    => '',
				'cost'        => '10',
				'currency'    => '€',
				'description' => "## About\n\nDancing.",
				'venue'       => array(
					'mode' => 'new',
					'name' => 'Warehouse 9',
					'city' => 'Berlin',
				),
				'organizer'   => array(
					'mode'  => 'new',
					'name'  => 'Night Crew',
					'email' => 'crew@example.org',
				),
			)
		);
		$this->assertIsArray( $r );
		$e = Events::get( $r['event_id'] );
		$this->assertSame( 'draft', $e['status'] );
		$this->assertSame( 'Poster Party', $e['title'] );
		$this->assertStringStartsWith( '2027-05-01 20:00', $e['start'] );
		$this->assertStringStartsWith( '2027-05-01 22:00', $e['end'] );
		$this->assertSame( 'Warehouse 9', $e['venue']['name'] );
		$this->assertSame( 'crew@example.org', $e['organizers'][0]['email'] );
		$this->assertSame( $aid, (int) get_post_thumbnail_id( $r['event_id'] ) );
		$this->assertStringContainsString( 'Dancing.', $e['content'] );
		$this->assertSame( 'created', Imports::get( $import['id'] )['status'] );
		$this->assertSame( 'dev3ec_import_done', Importer::create_event( $import['id'], array( 'title' => 'x' ) )->get_error_code() );
	}

	public function test_import_rejects_wrong_files_and_ai_off() {
		$txt = self::factory()->attachment->create(
			array(
				'post_mime_type' => 'text/plain',
				'file'           => 'notes.txt',
			)
		);
		$this->assertWPError( Importer::create( $txt, 'manual' ) );
		Settings_Store::update( array( 'api_key' => '' ) );
		Settings_Store::flush();
		$aid = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertSame( 'dev3ec_ai_disabled', Importer::create( $aid, 'ai' )->get_error_code() );
		$this->assertSame( 'ready', Importer::create( $aid, 'manual' )['status'] );
	}
}
