<?php
/**
 * Versioned DEV3 AI prompts (overridable with dev3ec_prompt / dev3ec_prompt_<id>).
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar\Ai;

use Dev3\AiEventsCalendar\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt registrations.
 */
class Prompt_Library {

	const RULES = "Only use facts from the event details and the organiser's notes. Never invent prices, dates, times, speakers, performers, sponsors, addresses, refund rules or other facts that are not given; leave a detail out when it is unknown.\nWrite plain text (no HTML, no markdown except where asked) in {{language}}.";

	/**
	 * STRING schema with a max length.
	 *
	 * @param int $max Max.
	 * @return array
	 */
	private static function str( $max ) {
		return array(
			'type'      => 'STRING',
			'maxLength' => $max,
		);
	}

	/**
	 * Email schema.
	 *
	 * @return array
	 */
	private static function email_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'subject'   => self::str( 90 ),
				'preheader' => self::str( 120 ),
				'body'      => self::str( 2500 ),
			),
			'required'   => array( 'subject', 'preheader', 'body' ),
		);
	}

	/**
	 * Registers every prompt.
	 */
	public static function register() {
		$placeholders = 'You may use these placeholders exactly as written and they are filled in per recipient: {attendee_name}, {event_title}, {event_date}, {event_time}, {venue}, {event_url}, {site_name}. Start with "Hi {attendee_name}," and keep paragraphs short. The body is plain text with blank lines between paragraphs.';
		Prompts::register(
			'event_description',
			array(
				'version'  => '1',
				'task'     => 'event_description',
				'system'   => "You write the description of an event for its page in The Events Calendar on a WordPress site. Tone: {{tone}}. Length: {{length}}. Audience: {{audience}}.\n" . self::RULES . "\nThe page already shows the date, time, venue, organiser and price in separate blocks, so do not repeat them as a list; weave them in only where helpful. Structure: a one or two sentence excerpt for listings, then 2-5 sections with a short heading each (for example what to expect, programme, who it is for, good to know). A section body may use lines starting with \"- \" for a short list. End with a one-line call to action.",
				'template' => "Event details (JSON):\n{{details}}\n\nCurrent description:\n{{current}}\n\nNotes from the organiser:\n{{notes}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'excerpt'  => self::str( 300 ),
						'sections' => array(
							'type'     => 'ARRAY',
							'maxItems' => 5,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'heading' => self::str( 90 ),
									'body'    => self::str( 1200 ),
								),
								'required'   => array( 'heading', 'body' ),
							),
						),
						'cta'      => self::str( 140 ),
					),
					'required'   => array( 'excerpt', 'sections', 'cta' ),
				),
				'model'    => 'pro',
				'profile'  => 'heavy',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			'social_posts',
			array(
				'version'  => '1',
				'task'     => 'social_posts',
				'system'   => "You write social media posts that promote an event. Tone: {{tone}}.\n" . self::RULES . "\nWrite exactly one post for each requested network. Network rules (character limits include the link and hashtags):\n{{rules}}\nInclude the event link {{url}} in every post where links are clickable; on Instagram write \"link in bio\" instead. Put 0-5 hashtags (without #) in the hashtags list, not in the text. Use emoji sparingly.",
				'template' => "Networks: {{networks}}\n\nEvent details (JSON):\n{{details}}\n\nNotes from the organiser: {{notes}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'posts' => array(
							'type'     => 'ARRAY',
							'maxItems' => 7,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'network'  => self::str( 12 ),
									'text'     => self::str( 2200 ),
									'hashtags' => array(
										'type'     => 'ARRAY',
										'maxItems' => 5,
										'items'    => self::str( 40 ),
									),
								),
								'required'   => array( 'network', 'text', 'hashtags' ),
							),
						),
					),
					'required'   => array( 'posts' ),
				),
				'profile'  => 'fast',
			)
		);
		Prompts::register(
			'event_email',
			array(
				'version'  => '1',
				'task'     => 'event_email',
				'system'   => "You write a {{purpose}} email about an event. Tone: {{tone}}.\n" . self::RULES . "\n" . $placeholders . "\nSubject up to 70 characters, preheader up to 110 characters (it complements the subject).",
				'template' => "Event details (JSON):\n{{details}}\n\nNotes from the organiser:\n{{notes}}",
				'schema'   => self::email_schema(),
				'profile'  => 'fast',
			)
		);
		Prompts::register(
			'recap_email',
			array(
				'version'  => '1',
				'task'     => 'recap_email',
				'system'   => "You write the thank-you / recap email sent to attendees after an event. Tone: {{tone}}.\n" . self::RULES . "\n" . $placeholders . "\nYou may mention the attendance numbers given (counts only) when they make the email warmer, never invent numbers; when attendance is unknown do not mention it. Mention highlights only if the organiser gave them. End with an invitation to give feedback or to the next event when the organiser mentions one.",
				'template' => "Event details (JSON):\n{{details}}\n\nAttendance (counts only): {{stats}}\n\nHighlights and notes from the organiser:\n{{notes}}",
				'schema'   => self::email_schema(),
				'profile'  => 'fast',
			)
		);
		Prompts::register(
			'event_faq',
			array(
				'version'  => '1',
				'task'     => 'event_faq',
				'system'   => "You write a short FAQ for an event page. Tone: {{tone}}.\n" . self::RULES . "\nWrite {{count}} questions attendees really ask (when, where, getting there, tickets, what is included, accessibility, age limits, refunds) but only where the details answer them. When the details do not answer a common question, say politely to contact the organiser instead of guessing.",
				'template' => "Event details (JSON):\n{{details}}\n\nNotes from the organiser: {{notes}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'faq' => array(
							'type'     => 'ARRAY',
							'maxItems' => 10,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'q' => self::str( 160 ),
									'a' => self::str( 600 ),
								),
								'required'   => array( 'q', 'a' ),
							),
						),
					),
					'required'   => array( 'faq' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		Prompts::register(
			'event_seo',
			array(
				'version'  => '1',
				'task'     => 'event_seo',
				'system'   => "You write the SEO title and meta description of an event page for Google.\n" . self::RULES . "\nTitle: 45-60 characters, starts with the event name, adds the date or city when it fits. Meta description: 120-155 characters, says what, when and where, ends with a reason to click. Use the focus phrase naturally when given.",
				'template' => "Focus phrase: {{focus}}\n\nEvent details (JSON):\n{{details}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'title'       => self::str( 70 ),
						'description' => self::str( 170 ),
					),
					'required'   => array( 'title', 'description' ),
				),
				'profile'  => 'fast',
				'cache'    => HOUR_IN_SECONDS,
			)
		);
		$place = static fn( array $props ) => array(
			'type'       => 'OBJECT',
			'properties' => array_map( array( self::class, 'str' ), $props ),
		);
		Prompts::register(
			'flyer_import',
			array(
				'version'  => '1',
				'task'     => 'flyer_import',
				'system'   => "You read an event flyer or poster (image or PDF) and extract the event for a calendar. Copy facts exactly as printed; never guess. Dates as YYYY-MM-DD and times as 24-hour HH:MM. When the year is missing, use the next future date ({{today}} is today). When only a start time is printed, leave end_time empty. List the keys of fields that are not on the flyer in \"missing\" and of fields you are unsure about in \"uncertain\". The description is plain text in the flyer's language using \"## Heading\" lines for sections when the flyer has several parts (programme, line-up, notes); otherwise one or two paragraphs.",
				'template' => "Site time zone: {{timezone}}\nNotes from the admin: {{notes}}\n\nExtract the event from the attached file.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'title'       => self::str( 160 ),
						'start_date'  => self::str( 10 ),
						'start_time'  => self::str( 5 ),
						'end_date'    => self::str( 10 ),
						'end_time'    => self::str( 5 ),
						'all_day'     => array( 'type' => 'BOOLEAN' ),
						'timezone'    => self::str( 40 ),
						'cost'        => self::str( 60 ),
						'currency'    => self::str( 8 ),
						'website'     => self::str( 200 ),
						'venue'       => $place(
							array(
								'name'    => 120,
								'address' => 160,
								'city'    => 80,
								'region'  => 80,
								'zip'     => 20,
								'country' => 60,
								'phone'   => 40,
							)
						),
						'organizer'   => $place(
							array(
								'name'    => 120,
								'email'   => 120,
								'phone'   => 40,
								'website' => 200,
							)
						),
						'categories'  => array(
							'type'     => 'ARRAY',
							'maxItems' => 4,
							'items'    => self::str( 40 ),
						),
						'description' => self::str( 3000 ),
						'missing'     => array(
							'type'     => 'ARRAY',
							'maxItems' => 20,
							'items'    => self::str( 20 ),
						),
						'uncertain'   => array(
							'type'     => 'ARRAY',
							'maxItems' => 20,
							'items'    => self::str( 20 ),
						),
					),
					'required'   => array( 'title', 'start_date', 'description', 'missing', 'uncertain' ),
				),
				'model'    => 'pro',
				'profile'  => 'heavy',
			)
		);
	}
}
