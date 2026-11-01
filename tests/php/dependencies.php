<?php
/**
 * Loads The Events Calendar and Event Tickets (both free, installed by wp-env) before DEV3.
 *
 * @package Dev3\AiEventsCalendar
 */

tests_add_filter(
	'dev3ec_test_dependencies',
	static function () {
		return array( 'the-events-calendar/the-events-calendar.php', 'event-tickets/event-tickets.php' );
	}
);

tests_add_filter( 'wp_mail_from', static fn() => 'wordpress@example.org' );
