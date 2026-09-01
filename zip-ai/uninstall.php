<?php
/**
 * Uninstall handler.
 *
 * Removes all plugin-owned options, transients, capabilities, and
 * scheduled cron events when the plugin is deleted from the
 * WordPress admin.
 *
 * @package zip-ai
 */

// Only run on uninstall, not activation or deactivation.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete a single plugin option (site-wide on multisite).
 *
 * @param string $option Option name.
 * @return void
 */
function zip_ai_delete_option( $option ) {
	if ( is_multisite() ) {
		delete_site_option( $option );
	}
	delete_option( $option );
}

// Options.
zip_ai_delete_option( 'zip_ai_settings' );
zip_ai_delete_option( 'zip_ai_audit_log' );
zip_ai_delete_option( 'zip_ai_encryption_key' );

// Transients.
delete_transient( 'zip_ai_last_scan_time' );

// Per-user OAuth state transients (prefix match). A direct query is the
// only way to bulk-delete prefix-matched transients; caching and the
// standard API are not applicable during uninstall.
global $wpdb;
/**
 * Narrowed type for `$wpdb`.
 *
 * @var \wpdb $wpdb
 */
$zip_ai_delete_transients_query = $wpdb->prepare(
	'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
	$wpdb->options,
	$wpdb->esc_like( '_transient_zip_ai_oauth_state_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_zip_ai_oauth_state_' ) . '%'
);
if ( is_string( $zip_ai_delete_transients_query ) ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare() with a %i table placeholder above; guarded is_string.
	$wpdb->query( $zip_ai_delete_transients_query );
}

// Capability removal from every role.
// `manage_zip_mcp_assistant` is the capability the plugin adds today;
// `manage_zip_ai_assistant` may linger from older releases.
$zip_ai_roles = wp_roles();
foreach ( $zip_ai_roles->role_objects as $zip_ai_role ) {
	foreach ( array( 'manage_zip_mcp_assistant', 'manage_zip_ai_assistant' ) as $zip_ai_cap ) {
		if ( $zip_ai_role->has_cap( $zip_ai_cap ) ) {
			$zip_ai_role->remove_cap( $zip_ai_cap );
		}
	}
}

// Scheduled cron events.
wp_clear_scheduled_hook( 'zip_ai_site_scan' );
