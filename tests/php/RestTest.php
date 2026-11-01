<?php
/**
 * REST routes: permissions, the draft lifecycle, bulk runs, imports.
 *
 * @package Dev3\AiEventsCalendar
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiEventsCalendar\Core3\Core\Jobs;
use Dev3\AiEventsCalendar\Data\Drafts;

class RestTest extends Dev3ec_Base_Case {

	private function seo_response() {
		return $this->json_response(
			array(
				'title'       => 'Jazz Night in Leeds',
				'description' => 'Live jazz on Saturday at the Blue Room.',
			)
		);
	}

	public function test_permissions() {
		$id = $this->event();
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', 'status' )->get_status() );
		$this->login( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', "events/{$id}" )->get_status() );
		$this->login( 'contributor' );
		// A contributor cannot edit someone else's published event.
		$this->assertSame( 403, $this->rest( 'POST', "events/{$id}/generate", array( 'kind' => 'seo' ) )->get_status() );
		$this->login( 'editor' );
		$this->assertSame( 403, $this->rest( 'GET', "events/{$id}", array(), false )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', "events/{$id}" )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', 'events/' . self::factory()->post->create() )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', 'settings', array( 'default_tone' => 'formal' ) )->get_status() );
	}

	public function test_status_and_list() {
		$this->login();
		$id = $this->event( array( 'venue_id' => $this->venue() ) );
		$s  = $this->rest( 'GET', 'status' )->get_data();
		$this->assertTrue( $s['tec'] );
		$this->assertTrue( $s['ai']['key_set'] );
		$this->assertSame( 'native', $s['seo']['adapter'] );
		$this->assertGreaterThanOrEqual( 1, $s['counts']['upcoming'] );
		$this->assertCount( 7, $s['networks'] );
		$list = $this->rest( 'GET', 'events', array( 'scope' => 'upcoming' ) )->get_data();
		$row  = current( array_filter( $list['items'], static fn( $r ) => $r['id'] === $id ) );
		$this->assertSame( 'Blue Room', $row['venue'] );
		$this->assertFalse( $row['has']['seo'] );
		$snap = $this->rest( 'GET', "events/{$id}" )->get_data();
		$this->assertSame( 'Jazz Night', $snap['event']['title'] );
		$this->assertArrayNotHasKey( 'content', $snap['event'] );
		$this->assertArrayHasKey( 'description', $snap['assets'] );
	}

	public function test_generate_accept_saves_seo() {
		$this->login();
		$id                = $this->event();
		$this->responses[] = $this->seo_response();
		$r                 = $this->rest( 'POST', "events/{$id}/generate", array( 'kind' => 'seo' ) );
		$this->assertSame( 201, $r->get_status() );
		$draft = $r->get_data();
		$this->assertSame( 'pending', $draft['status'] );
		$this->assertSame( '', $draft['original']['title'] );
		$this->assertSame( '', (string) get_post_meta( $id, '_dev3ec_seo', true ) );
		$ok = $this->rest(
			'POST',
			"drafts/{$draft['id']}/accept",
			array(
				'value' => array(
					'title'       => 'Edited title',
					'description' => 'Edited description.',
				),
			)
		);
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 'Edited title', get_post_meta( $id, '_dev3ec_seo', true )['title'] );
		$this->assertSame( 'accepted', Drafts::get( $draft['id'] )['status'] );
		$this->assertSame( 409, $this->rest( 'POST', "drafts/{$draft['id']}/accept" )->get_status() );
	}

	public function test_new_draft_supersedes_older_pending() {
		$this->login();
		$id    = $this->event();
		$first = $this->rest( 'POST', "events/{$id}/template", array( 'kind' => 'faq' ) )->get_data();
		$next  = $this->rest( 'POST', "events/{$id}/template", array( 'kind' => 'faq' ) )->get_data();
		$this->assertSame( 'template', $next['source'] );
		$this->assertSame( 'rejected', Drafts::get( $first['id'] )['status'] );
		$this->assertCount( 1, Drafts::pending_for( $id ) );
		$this->assertCount( 0, $this->requests );
	}

	public function test_stale_draft_needs_force() {
		$this->login();
		$id    = $this->event();
		$draft = $this->rest( 'POST', "events/{$id}/template", array( 'kind' => 'description' ) )->get_data();
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Jazz Night (moved)',
			)
		);
		$r = $this->rest( 'POST', "drafts/{$draft['id']}/accept" );
		$this->assertSame( 409, $r->get_status() );
		$this->assertSame( 'dev3ec_stale', $r->get_data()['code'] );
		$this->assertSame( 200, $this->rest( 'POST', "drafts/{$draft['id']}/accept", array( 'force' => true ) )->get_status() );
		$this->assertStringContainsString( 'Event details', get_post_field( 'post_content', $id ) );
	}

	public function test_client_mode_returns_blocks_without_touching_the_post() {
		$this->login();
		$id     = $this->event();
		$before = get_post_field( 'post_content', $id );
		$draft  = $this->rest( 'POST', "events/{$id}/template", array( 'kind' => 'description' ) )->get_data();
		$r      = $this->rest(
			'POST',
			"drafts/{$draft['id']}/accept",
			array(
				'mode'  => 'client',
				'value' => array(
					'excerpt' => 'Ex',
					'body'    => "## Hello\n\nWorld",
				),
			)
		)->get_data();
		$this->assertStringContainsString( '<!-- wp:heading -->', $r['blocks'] );
		$this->assertStringContainsString( '<h2>Hello</h2>', $r['html'] );
		$this->assertSame( 'Ex', $r['excerpt'] );
		$this->assertSame( $before, get_post_field( 'post_content', $id ) );
		$this->assertSame( 'accepted', Drafts::get( $draft['id'] )['status'] );
	}

	public function test_reject_and_review_queue() {
		$this->login();
		$id = $this->event();
		$a  = $this->rest( 'POST', "events/{$id}/template", array( 'kind' => 'seo' ) )->get_data();
		$b  = $this->rest( 'POST', "events/{$id}/template", array( 'kind' => 'faq' ) )->get_data();
		$this->assertSame( 'rejected', $this->rest( 'POST', "drafts/{$a['id']}/reject" )->get_data()['status'] );
		$q = $this->rest( 'GET', 'drafts', array( 'status' => 'pending' ) )->get_data();
		$this->assertSame( array( $b['id'] ), wp_list_pluck( $q['items'], 'id' ) );
		$bulk = $this->rest(
			'POST',
			'drafts/bulk',
			array(
				'ids'    => array( $b['id'] ),
				'action' => 'accept',
			)
		)->get_data();
		$this->assertSame( 1, $bulk['done'] );
		$this->assertNotEmpty( get_post_meta( $id, '_dev3ec_faq', true ) );
	}

	public function test_bulk_run_creates_drafts_in_the_background() {
		$this->login();
		$a                 = $this->event();
		$b                 = $this->event( array( 'title' => 'Blues Night' ) );
		$this->responses[] = $this->seo_response();
		\Dev3\AiEventsCalendar\Content\Assets::save(
			$b,
			'seo',
			array(
				'title'       => 'Has one',
				'description' => 'x',
			)
		);
		$r = $this->rest(
			'POST',
			'bulk',
			array(
				'ids'          => array( $a, $b ),
				'kinds'        => array( 'seo', 'faq' ),
				'only_missing' => true,
				'options'      => array( 'tone' => 'formal' ),
			)
		);
		$this->assertSame( 201, $r->get_status() );
		$job = $r->get_data();
		$this->assertSame( 4, $job['total'] );
		$faq             = $this->json_response(
			array(
				'faq' => array(
					array(
						'q' => 'Q?',
						'a' => 'A.',
					),
				),
			)
		);
		$seo             = $this->seo_response();
		$this->responses = array(
			static function ( $req ) use ( $faq, $seo ) {
				return isset( $req['body']['generationConfig']['responseSchema']['properties']['faq'] ) ? $faq : $seo;
			},
		);
		$done = Jobs::run( $job['id'], 60 );
		$this->assertSame( 'done', $done['status'] );
		$this->assertSame( 4, $done['done'] );
		$drafts = Drafts::query( array( 'job_id' => $job['id'] ) );
		$this->assertSame( 3, $drafts['total'] );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( 'formal', Drafts::query( array( 'job_id' => $job['id'] ) )['items'][0]['options']['tone'] );
		$this->assertSame( 400, $this->rest( 'POST', 'bulk', array( 'ids' => array(), 'kinds' => array( 'seo' ) ) )->get_status() );
	}

	public function test_bulk_templates_work_without_a_key() {
		$this->login();
		\Dev3\AiEventsCalendar\Core3\Core\Settings_Store::update( array( 'api_key' => '' ) );
		\Dev3\AiEventsCalendar\Core3\Core\Settings_Store::flush();
		$id = $this->event();
		$this->assertSame( 400, $this->rest( 'POST', 'bulk', array( 'ids' => array( $id ), 'kinds' => array( 'seo' ) ) )->get_status() );
		$job = $this->rest(
			'POST',
			'bulk',
			array(
				'ids'    => array( $id ),
				'kinds'  => array( 'seo', 'social' ),
				'source' => 'template',
			)
		)->get_data();
		Jobs::run( $job['id'], 30 );
		$this->assertSame( 2, Drafts::query( array( 'event_id' => $id ) )['total'] );
	}

	public function test_manual_asset_save_and_places() {
		$this->login();
		$id = $this->event();
		$r  = $this->rest(
			'POST',
			"events/{$id}/assets",
			array(
				'kind'  => 'social',
				'value' => array( 'x' => 'Hand written' ),
			)
		)->get_data();
		$this->assertSame( 'Hand written', $r['assets']['social']['x'] );
		$this->venue();
		$places = $this->rest( 'GET', 'places', array( 'type' => 'venue' ) )->get_data();
		$this->assertSame( 'Blue Room', $places[0]['name'] );
	}

	public function test_import_rest_flow() {
		$this->login();
		$aid = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$imp = $this->rest(
			'POST',
			'imports',
			array(
				'attachment_id' => $aid,
				'mode'          => 'manual',
			)
		);
		$this->assertSame( 201, $imp->get_status() );
		$id  = $imp->get_data()['id'];
		$res = $this->rest(
			'POST',
			"imports/{$id}/create",
			array(
				'fields' => array(
					'title'      => 'From REST',
					'start_date' => '2027-06-01',
					'all_day'    => true,
					'venue'      => array( 'mode' => 'none' ),
					'organizer'  => array( 'mode' => 'none' ),
				),
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 'tribe_events', get_post_type( $res->get_data()['event_id'] ) );
		$this->assertTrue( \Dev3\AiEventsCalendar\Tec\Events::all_day( $res->get_data()['event_id'] ) );
		$this->assertCount( 1, $this->rest( 'GET', 'imports' )->get_data() );
		$this->login( 'author' );
		$this->assertSame( 403, $this->rest( 'GET', "imports/{$id}" )->get_status() );
	}
}
