<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiEventsCalendar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3ec_settings = get_option( 'dev3ec_settings', array() );
if ( empty( $dev3ec_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3ec_usage', 'dev3ec_core_cache', 'dev3ec_core_jobs', 'dev3ec_core_embeddings', 'dev3ec_drafts', 'dev3ec_imports' ) as $dev3ec_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3ec_table}" );
}
foreach ( array( 'dev3ec_settings', 'dev3ec_license', 'dev3ec_db_version' ) as $dev3ec_option ) {
	delete_option( $dev3ec_option );
}
delete_transient( 'dev3ec_models' );
delete_site_transient( 'dev3ec_update_info' );
wp_unschedule_hook( 'dev3ec_run_job' );
wp_unschedule_hook( 'dev3ec_daily' );
wp_unschedule_hook( 'dev3ec_hourly' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_dev3ec\\_%'" );
