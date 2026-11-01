<?php
/**
 * Front end (FAQ, SEO meta) and emails (test send, attendees, automatic reminder).
 *
 * @package Dev3\AiEventsCalendar
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Core3\Core\Jobs;
use Dev3\AiEventsCalendar\Core3\Core\Settings_Store;
use Dev3\AiEventsCalendar\Frontend\Faq;
use Dev3\AiEventsCalendar\Seo\Seo;
use Dev3\AiEventsCalendar\Service\Mailer;

class OutputTest extends Dev3ec_Base_Case {

	private function with_faq() {
		$id = $this->event();
		Assets::save( $id, 'faq', array( 'faq' => "Q: Is there parking?\nA: Yes, <b>free</b> parking." ) );
		return $id;
	}

	public function test_faq_render_and_schema() {
		$id   = $this->with_faq();
		$html = Faq::render( $id );
		$this->assertStringContainsString( '<details class="dev3ec-faq__item">', $html );
		$this->assertStringContainsString( 'Is there parking?', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( 'DEV3', $html );
		$this->assertSame( '', Faq::render( $id ), 'Rendered once per request.' );
		ob_start();
		Faq::schema();
		$json = ob_get_clean();
		$this->assertStringContainsString( '"@type":"FAQPage"', $json );
		$this->assertStringContainsString( 'Is there parking?', $json );
		Faq::reset();
		$this->assertStringContainsString( 'Questions', do_shortcode( '[dev3ec_faq id="' . $id . '" heading="Questions"]' ) );
		$this->assertSame( '', Faq::render( $this->event() ) );
	}

	public function test_faq_appends_on_the_single_event_page() {
		$id = $this->with_faq();
		$this->go_to( get_permalink( $id ) );
		$this->assertTrue( is_singular( 'tribe_events' ) );
		global $wp_query;
		$wp_query->in_the_loop = true;
		$this->assertStringContainsString( 'dev3ec-faq', Faq::append( '<p>Body</p>' ) );
		Faq::reset();
		Settings_Store::update( array( 'faq_display' => 'shortcode' ) );
		Settings_Store::flush();
		$this->assertSame( '<p>Body</p>', Faq::append( '<p>Body</p>' ) );
	}

	public function test_native_seo_output_and_yoast_adapter() {
		$id = $this->event();
		Assets::save(
			$id,
			'seo',
			array(
				'title'       => 'Best Jazz Night',
				'description' => 'Live jazz "quoted".',
			)
		);
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( 'Best Jazz Night', Seo::document_title( 'Default' ) );
		ob_start();
		Seo::head();
		$head = ob_get_clean();
		$this->assertStringContainsString( '<meta name="description" content="Live jazz &quot;quoted&quot;." />', $head );
		$this->assertStringContainsString( 'og:title', $head );
		add_filter( 'dev3ec_seo_adapter', static fn() => 'yoast' );
		Seo::write(
			$id,
			array(
				'title'       => 'Y title',
				'description' => 'Y desc',
			)
		);
		$this->assertSame( 'Y title', get_post_meta( $id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Y desc', Seo::current( $id )['description'] );
		$this->assertSame( 'Default', Seo::document_title( 'Default' ), 'No native output when an SEO plugin is active.' );
		remove_all_filters( 'dev3ec_seo_adapter' );
	}

	public function test_test_send_fills_merge_tags() {
		$user = $this->login();
		$id   = $this->event( array( 'venue_id' => $this->venue() ) );
		$r    = Mailer::test(
			$id,
			'email_promo',
			array(
				'subject'   => 'Come to {event_title}',
				'preheader' => 'At {venue}',
				'body'      => "Hi {attendee_name},\n\nSee {event_url}",
			)
		);
		$this->assertSame( 1, $r['sent'] );
		$mail = $this->mails()[0];
		$this->assertSame( 'Come to Jazz Night', $mail['subject'] );
		$this->assertStringContainsString( 'Hi ' . get_userdata( $user )->display_name, $mail['body'] );
		$this->assertStringContainsString( 'href="' . get_permalink( $id ) . '"', $mail['body'] );
		$this->assertStringContainsString( 'At Blue Room', $mail['body'] );
		$this->assertWPError( Mailer::test( $id, 'email_recap' ) );
	}

	public function test_send_to_attendees_once_per_unique_address() {
		$this->login();
		$id                     = $this->event();
		$this->attendees[ $id ] = array(
			array(
				'holder_name'  => 'Ana',
				'holder_email' => 'ana@example.org',
				'order_status' => 'yes',
			),
			array(
				'holder_name'  => 'Ana again',
				'holder_email' => 'ANA@example.org',
				'order_status' => 'yes',
			),
			array(
				'purchaser_name'  => 'Bo',
				'purchaser_email' => 'bo@example.org',
				'order_status'    => 'completed',
			),
			array(
				'holder_name'  => 'Not going',
				'holder_email' => 'no@example.org',
				'order_status' => 'no',
			),
		);
		Assets::save(
			$id,
			'email_reminder',
			array(
				'subject' => 'Tomorrow: {event_title}',
				'body'    => 'Hi {attendee_name}!',
			)
		);
		$r = $this->rest(
			'POST',
			"events/{$id}/send",
			array(
				'kind'   => 'email_reminder',
				'target' => 'attendees',
			)
		);
		$this->assertSame( 201, $r->get_status() );
		$this->assertSame( 2, $r->get_data()['count'] );
		Jobs::run( $r->get_data()['job']['id'], 30 );
		$mails = $this->mails();
		$this->assertCount( 2, $mails );
		$this->assertStringContainsString( 'Hi Ana!', $mails[0]['body'] );
		$this->assertStringContainsString( 'Hi Bo!', $mails[1]['body'] );
		$this->assertSame( 'attendees', Mailer::history( $id )[0]['target'] );
		$this->login( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', "events/{$id}/send", array( 'kind' => 'email_reminder', 'target' => 'attendees' ) )->get_status() );
	}

	public function test_auto_reminder_sends_once() {
		$id                     = $this->event(
			array(
				'start' => gmdate( 'Y-m-d H:i:s', time() + 5 * HOUR_IN_SECONDS ),
				'end'   => gmdate( 'Y-m-d H:i:s', time() + 8 * HOUR_IN_SECONDS ),
				'timezone' => 'UTC',
			)
		);
		$this->attendees[ $id ] = array(
			array(
				'holder_name'  => 'Ana',
				'holder_email' => 'ana@example.org',
				'order_status' => 'yes',
			),
		);
		Assets::save(
			$id,
			'email_reminder',
			array(
				'subject' => 'Soon',
				'body'    => 'Hi',
			)
		);
		$this->assertSame( 0, Mailer::auto_reminders(), 'Off by default.' );
		Settings_Store::update(
			array(
				'auto_reminder'  => true,
				'reminder_hours' => 24,
			)
		);
		Settings_Store::flush();
		$this->assertSame( 1, Mailer::auto_reminders() );
		$this->assertSame( 0, Mailer::auto_reminders() );
		$this->assertCount( 1, $this->mails() );
	}
}
