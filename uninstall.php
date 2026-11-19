<?php
/**
 * Uninstall WP Settings Exporter.
 *
 * Removes any pending (unconfirmed) import payload.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_wse_pending_import_' ) . '%'
	)
);
