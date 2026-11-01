<?php
/**
 * Plugin Name:       DEV3 AI Event Writer for The Events Calendar
 * Plugin URI:        https://dev3.tech/item/dev3-ai-events-calendar
 * Description:       DEV3 AI writes event descriptions, social posts, emails, recaps, FAQs and SEO meta for The Events Calendar, and imports events from flyers.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-events-calendar
 * Domain Path:       /languages
 * Requires Plugins:  the-events-calendar
 *
 * @package Dev3\AiEventsCalendar
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3EC_VERSION', '1.0.0' );
define( 'DEV3EC_FILE', __FILE__ );
define( 'DEV3EC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3EC_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3EC_SLUG', 'dev3-ai-events-calendar' );
define( 'DEV3EC_NAME', 'DEV3 AI Event Writer for The Events Calendar' );
define( 'DEV3EC_MIN_PHP', '7.4' );
define( 'DEV3EC_MIN_WP', '6.0' );
define( 'DEV3EC_DB_VERSION', '1.0.1' );

if ( version_compare( PHP_VERSION, DEV3EC_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiEventsCalendar\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3EC_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiEventsCalendar\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiEventsCalendar\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiEventsCalendar\Plugin', 'instance' ) );
