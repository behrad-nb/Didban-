<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$opts = (array) get_option( 'didban_settings', array() );

if ( ! empty( $opts['delete_on_uninstall'] ) ) {
	global $wpdb;
	foreach ( array( 'events', 'sessions', 'pageviews' ) as $t ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'didban_' . $t );
	}
	delete_option( 'didban_settings' );
	delete_option( 'didban_db_version' );
}

wp_clear_scheduled_hook( 'didban_cleanup' );
