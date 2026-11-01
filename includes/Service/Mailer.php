<?php
/**
 * Sends saved promo / reminder / recap emails: test send, send to Event Tickets attendees (as a
 * background job) and the automatic reminder before an event starts.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Service;

use Dev3\AiEventsCalendar\Content\Assets;
use Dev3\AiEventsCalendar\Content\Templates;
use Dev3\AiEventsCalendar\Core3\Core\Jobs;
use Dev3\AiEventsCalendar\Settings;
use Dev3\AiEventsCalendar\Tec\Attendees;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Mailer.
 */
class Mailer {

	const JOB = 'dev3ec_send';

	const LOG = '_dev3ec_send_log';

	/**
	 * Registers the job and the hourly reminder check.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_chunk' ) );
		add_action( 'dev3ec_hourly', array( self::class, 'auto_reminders' ) );
	}

	/**
	 * HTML email from a plain-text body (neutral template).
	 *
	 * @param string $subject   Subject.
	 * @param string $preheader Preheader.
	 * @param string $body      Body (merge tags already filled).
	 * @return string
	 */
	public static function html( $subject, $preheader, $body ) {
		$paras = array();
		foreach ( preg_split( '/\n\s*\n/', trim( (string) $body ) ) as $p ) {
			$h       = nl2br( esc_html( trim( $p ) ), false );
			$h       = preg_replace( '~(https?://[^\s<]+[^\s<.,;:!?)])~i', '<a href="$1" style="color:#2563eb">$1</a>', $h );
			$paras[] = '<p style="margin:0 0 16px;line-height:1.6">' . $h . '</p>';
		}
		$html  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . esc_html( $subject ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#18181b">';
		$html .= '<span style="display:none!important;opacity:0;color:transparent;height:0;width:0;overflow:hidden">' . esc_html( $preheader ) . '</span>';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px"><tr><td style="padding:32px 28px;font-size:16px">';
		$html .= implode( '', $paras );
		$html .= '</td></tr></table></td></tr></table></body></html>';
		/**
		 * Filters the HTML of an event email.
		 *
		 * @param string $html    HTML.
		 * @param string $subject Subject.
		 * @param string $body    Plain body.
		 */
		return (string) apply_filters( 'dev3ec_email_html', $html, $subject, $body );
	}

	/**
	 * Sends one email.
	 *
	 * @param int    $event_id Event.
	 * @param string $kind     Email kind.
	 * @param string $to       Address.
	 * @param string $name     Recipient name.
	 * @param array  $email    Fields (defaults to the saved email).
	 * @return bool
	 */
	public static function send_one( $event_id, $kind, $to, $name = '', array $email = array() ) {
		$email = $email ? $email : Assets::current( $event_id, $kind );
		if ( empty( $email['subject'] ) || empty( $email['body'] ) ) {
			return false;
		}
		$vars    = Templates::vars( $event_id, $name );
		$subject = Templates::fill( $email['subject'], $vars );
		$body    = Templates::fill( $email['body'], $vars );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$from    = (string) Settings::get( 'email_from_name', '' );
		$reply   = (string) Settings::get( 'email_reply_to', '' );
		if ( '' !== $reply ) {
			$headers[] = 'Reply-To: ' . ( '' !== $from ? $from . ' <' . $reply . '>' : $reply );
		}
		$name_filter = static fn() => $from;
		if ( '' !== $from ) {
			add_filter( 'wp_mail_from_name', $name_filter );
		}
		$ok = wp_mail( $to, $subject, self::html( $subject, Templates::fill( (string) ( $email['preheader'] ?? '' ), $vars ), $body ), $headers );
		if ( '' !== $from ) {
			remove_filter( 'wp_mail_from_name', $name_filter );
		}
		return (bool) $ok;
	}

	/**
	 * Test send to the current user.
	 *
	 * @param int    $event_id Event.
	 * @param string $kind     Kind.
	 * @param array  $email    Unsaved fields to test (optional).
	 * @return array|\WP_Error
	 */
	public static function test( $event_id, $kind, array $email = array() ) {
		$user = wp_get_current_user();
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return new \WP_Error( 'dev3ec_no_email', __( 'Your account has no email address.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$email = $email ? Assets::clean( $kind, $email ) : array();
		if ( ! self::send_one( $event_id, $kind, $user->user_email, $user->display_name, $email ) ) {
			return new \WP_Error( 'dev3ec_send_failed', __( 'The email could not be sent. Save a subject and body first, and check your site can send email.', 'dev3-ai-events-calendar' ), array( 'status' => 500 ) );
		}
		self::log( $event_id, $kind, 'test', 1 );
		return array(
			'sent' => 1,
			'to'   => $user->user_email,
		);
	}

	/**
	 * Queues a send to all attendees.
	 *
	 * @param int    $event_id Event.
	 * @param string $kind     Kind.
	 * @return array|\WP_Error Job + count.
	 */
	public static function to_attendees( $event_id, $kind ) {
		$email = Assets::current( $event_id, $kind );
		if ( empty( $email['subject'] ) || empty( $email['body'] ) ) {
			return new \WP_Error( 'dev3ec_no_email', __( 'Save this email first.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$list = Attendees::recipients( $event_id );
		if ( ! $list ) {
			return new \WP_Error( 'dev3ec_no_attendees', __( 'This event has no attendees with an email address yet.', 'dev3-ai-events-calendar' ), array( 'status' => 400 ) );
		}
		$items = array_map( static fn( $r ) => $r['email'], $list );
		$job   = Jobs::create(
			self::JOB,
			$items,
			array(
				'event_id' => (int) $event_id,
				'kind'     => $kind,
			),
			25
		);
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		self::log( $event_id, $kind, 'attendees', count( $items ) );
		return array(
			'job'   => Jobs::get( $job ),
			'count' => count( $items ),
		);
	}

	/**
	 * Job handler: one email per address.
	 *
	 * @param array $items Emails.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$event_id = (int) ( $job['args']['event_id'] ?? 0 );
		$kind     = (string) ( $job['args']['kind'] ?? '' );
		$names    = array();
		foreach ( Attendees::recipients( $event_id ) as $r ) {
			$names[ strtolower( $r['email'] ) ] = $r['name'];
		}
		$email = Assets::current( $event_id, $kind );
		$out   = array();
		foreach ( $items as $to ) {
			$out[ $to ] = self::send_one( $event_id, $kind, $to, $names[ strtolower( $to ) ] ?? '', $email ) ? true : __( 'Not sent', 'dev3-ai-events-calendar' );
		}
		return $out;
	}

	/**
	 * Hourly: sends the saved reminder once to attendees of events starting within the window.
	 *
	 * @return int Events reminded.
	 */
	public static function auto_reminders() {
		if ( ! Settings::get( 'auto_reminder', false ) ) {
			return 0;
		}
		$hours = (int) Settings::get( 'reminder_hours', 24 );
		$ids   = get_posts(
			array(
				'post_type'                    => Events::POST_TYPE,
				'post_status'                  => 'publish',
				'posts_per_page'               => 50,
				'fields'                       => 'ids',
				'tribe_suppress_query_filters' => true,
				'meta_query'                   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_EventStartDateUTC',
						'value'   => array( gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', time() + $hours * HOUR_IN_SECONDS ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
					array(
						'key'     => Assets::meta_key( 'email_reminder' ),
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_dev3ec_reminder_sent',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$n     = 0;
		foreach ( $ids as $id ) {
			update_post_meta( $id, '_dev3ec_reminder_sent', time() );
			$sent = 0;
			foreach ( Attendees::recipients( $id ) as $r ) {
				$sent += self::send_one( $id, 'email_reminder', $r['email'], $r['name'] ) ? 1 : 0;
			}
			self::log( $id, 'email_reminder', 'auto', $sent );
			++$n;
		}
		return $n;
	}

	/**
	 * Appends to the send log (last 20).
	 *
	 * @param int    $event_id Event.
	 * @param string $kind     Kind.
	 * @param string $target   test|attendees|auto.
	 * @param int    $count    Recipients.
	 */
	public static function log( $event_id, $kind, $target, $count ) {
		$log   = get_post_meta( $event_id, self::LOG, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'kind'   => $kind,
			'target' => $target,
			'count'  => (int) $count,
			'user'   => get_current_user_id(),
			'at'     => gmdate( 'c' ),
		);
		update_post_meta( $event_id, self::LOG, array_slice( $log, -20 ) );
	}

	/**
	 * Send log.
	 *
	 * @param int $event_id Event.
	 * @return array
	 */
	public static function history( $event_id ) {
		$log = get_post_meta( $event_id, self::LOG, true );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}
}
