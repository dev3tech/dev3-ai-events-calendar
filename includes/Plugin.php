<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiEventsCalendar
 */

namespace Dev3\AiEventsCalendar;

use Dev3\AiEventsCalendar\Core3\Core\Assets;
use Dev3\AiEventsCalendar\Core3\Core\Privacy;
use Dev3\AiEventsCalendar\Core3\Core\Schema;
use Dev3\AiEventsCalendar\Data\Drafts;
use Dev3\AiEventsCalendar\Tec\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK   = 'dev3ec_daily';
	const HOURLY_HOOK = 'dev3ec_hourly';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Assets registered (Assets::register adds the boot script on every call).
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3EC_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
	}

	/**
	 * Settings defaults and plugin tables, registered so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3ec_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3ec_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3ec_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3ec_schema_sql', array( Drafts::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Registers the script bundles once.
	 */
	public static function assets() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		Assets::register(
			array( 'admin', 'editor', 'metabox', 'blocks' ),
			array(
				'appUrl' => admin_url( 'admin.php?page=' . DEV3EC_SLUG ),
			)
		);
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( self::class, 'textdomain' ), 1 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3EC_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		Privacy::init();
		// Background job types must be registered on every request (the runner looks them up).
		Service\Bulk::register();
		Service\Importer::register();
		Service\Mailer::register();
		Ai\Prompt_Library::register();
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( self::class, 'assets' ), 5 );
		add_action( 'deleted_post', array( Drafts::class, 'delete_for_event' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
					wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
				}
			},
			20
		);
		if ( ! Events::active() ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( Admin\Admin_Page::class, 'missing_tec_notice' ) );
				( new Admin\Admin_Page() )->register();
			}
			return;
		}
		Frontend\Faq::register();
		Seo\Seo::register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
			( new Admin\Editor() )->register();
		}
	}

	/**
	 * Translations (on init: WP 6.7+ warns when loaded earlier).
	 */
	public static function textdomain() {
		load_plugin_textdomain( 'dev3-ai-events-calendar', false, dirname( plugin_basename( DEV3EC_FILE ) ) . '/languages' );
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Events_Controller() )->register_routes();
		( new Rest\Drafts_Controller() )->register_routes();
		( new Rest\Imports_Controller() )->register_routes();
	}
}
