<?php
/**
 * Shared fixtures: TEC events, venues, organizers, a fake Gemini (pre_http_request), REST helpers.
 *
 * @package Dev3\AiEventsCalendar
 */

use Dev3\AiEventsCalendar\Ai\Prompt_Library;
use Dev3\AiEventsCalendar\Core3\Api\Ai;
use Dev3\AiEventsCalendar\Core3\Api\Prompts;
use Dev3\AiEventsCalendar\Core3\Core\Cache;
use Dev3\AiEventsCalendar\Core3\Core\Schema;
use Dev3\AiEventsCalendar\Core3\Core\Settings_Store;
use Dev3\AiEventsCalendar\Core3\Data\Usage_Repository;
use Dev3\AiEventsCalendar\Frontend\Faq;
use Dev3\AiEventsCalendar\Tec\Events;
use Dev3\AiEventsCalendar\Tec\Places;

abstract class Dev3ec_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured Gemini requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake responses (each [ status, body ] or callable); the last one repeats.
	 *
	 * @var array
	 */
	protected $responses = array();

	/**
	 * Fake attendees per event (dev3ec_attendees filter).
	 *
	 * @var array
	 */
	protected $attendees = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3EC_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		Prompts::reset();
		Prompt_Library::register();
		Faq::reset();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3ec_drafts" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3ec_imports" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3ec_core_jobs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3ec_core_cache" );
		// phpcs:enable
		$this->requests  = array();
		$this->responses = array();
		$this->attendees = array();
		reset_phpmailer_instance();
		add_filter( 'dev3ec_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3ec_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3ec_attendees', array( $this, 'fake_attendees' ), 10, 2 );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3ec_retry_delay_ms', '__return_zero' );
		remove_filter( 'dev3ec_jobs_use_action_scheduler', '__return_false' );
		remove_filter( 'dev3ec_attendees', array( $this, 'fake_attendees' ), 10 );
		parent::tear_down();
	}

	/**
	 * Attendees filter: adds fake rows for events listed in $this->attendees.
	 *
	 * @param array $rows Rows.
	 * @param int   $id   Event.
	 * @return array
	 */
	public function fake_attendees( $rows, $id ) {
		return isset( $this->attendees[ $id ] ) ? array_merge( (array) $rows, $this->attendees[ $id ] ) : $rows;
	}

	/**
	 * Fake Gemini.
	 *
	 * @param mixed  $pre  Short-circuit.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array|mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false === strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			return $pre;
		}
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		$next = count( $this->responses ) > 1 ? array_shift( $this->responses ) : ( $this->responses ? $this->responses[0] : $this->json_response( array() ) );
		if ( is_callable( $next ) ) {
			$next = $next( end( $this->requests ) );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Structured-output response.
	 *
	 * @param array $data Decoded output.
	 * @return array
	 */
	protected function json_response( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 400,
					'candidatesTokenCount' => 200,
					'totalTokenCount'      => 600,
				),
			),
		);
	}

	/**
	 * Full text of the last request (system + user parts).
	 *
	 * @return string
	 */
	protected function last_prompt() {
		$r = end( $this->requests );
		if ( ! $r ) {
			return '';
		}
		$out = array();
		foreach ( (array) ( $r['body']['systemInstruction']['parts'] ?? array() ) as $p ) {
			$out[] = (string) ( $p['text'] ?? '' );
		}
		foreach ( (array) ( $r['body']['contents'] ?? array() ) as $turn ) {
			foreach ( (array) ( $turn['parts'] ?? array() ) as $p ) {
				$out[] = (string) ( $p['text'] ?? '' );
			}
		}
		return implode( "\n", $out );
	}

	/**
	 * Creates a venue.
	 *
	 * @param array $v Fields.
	 * @return int
	 */
	protected function venue( array $v = array() ) {
		return (int) Places::create_venue(
			array_merge(
				array(
					'name'    => 'Blue Room',
					'address' => '1 High Street',
					'city'    => 'Leeds',
					'country' => 'United Kingdom',
				),
				$v
			)
		);
	}

	/**
	 * Creates an organizer.
	 *
	 * @param array $o Fields.
	 * @return int
	 */
	protected function organizer( array $o = array() ) {
		return (int) Places::create_organizer(
			array_merge(
				array(
					'name'  => 'City Jazz Club',
					'email' => 'hello@cityjazz.test',
				),
				$o
			)
		);
	}

	/**
	 * Creates a TEC event.
	 *
	 * @param array $f Fields for Events::create.
	 * @return int
	 */
	protected function event( array $f = array() ) {
		$start = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );
		$id    = Events::create(
			array_merge(
				array(
					'title'    => 'Jazz Night',
					'content'  => "<!-- wp:tribe/event-datetime /-->\n\n<!-- wp:paragraph -->\n<p>An evening of live jazz.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:tribe/event-venue /-->",
					'status'   => 'publish',
					'start'    => $start . ' 19:00:00',
					'end'      => $start . ' 22:00:00',
					'timezone' => 'Europe/London',
					'cost'     => '15',
					'currency' => '£',
				),
				$f
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Logs in a user of a role.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected function login( $role = 'administrator' ) {
		$id = self::factory()->user->create(
			array(
				'role'       => $role,
				'user_email' => $role . wp_rand() . '@example.org',
			)
		);
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * REST call with a valid nonce.
	 *
	 * @param string $method Method.
	 * @param string $route  Route below dev3ec/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send a nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3ec/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Captured emails.
	 *
	 * @return array
	 */
	protected function mails() {
		$out = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $m ) {
			$m['body'] = quoted_printable_decode( (string) $m['body'] );
			$out[]     = $m;
		}
		return $out;
	}
}
