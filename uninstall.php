<?php
/**
 * Uninstall. Data removal is OPT-IN: nothing is deleted unless the option
 * `qc_delete_data_on_uninstall` is set to 1 before the plugin is deleted, e.g.
 *   wp option update qc_delete_data_on_uninstall 1
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== (string) get_option( 'qc_delete_data_on_uninstall', '0' ) ) {
	return;
}

global $wpdb;

foreach ( array( 'qhta_submission', 'qhta_program' ) as $qc_type ) {
	$qc_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $qc_type ) );
	foreach ( $qc_ids as $qc_id ) {
		wp_delete_post( (int) $qc_id, true );
	}
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}qc_scores" );

foreach ( array( 'qc_db_version', 'qc_round_flags', 'qc_outcome_queue', 'qc_cron_last', 'qc_retention_last_run', 'qc_delete_data_on_uninstall' ) as $qc_option ) {
	delete_option( $qc_option );
}

remove_role( 'qhta_judge' );
foreach ( array( 'administrator' ) as $qc_role_name ) {
	$qc_role = get_role( $qc_role_name );
	if ( $qc_role ) {
		$qc_role->remove_cap( 'qc_manage' );
	}
}

// Protected uploads.
$qc_uploads = wp_upload_dir( null, false );
$qc_dir     = trailingslashit( $qc_uploads['basedir'] ) . 'qhta-competitions';
if ( is_dir( $qc_dir ) ) {
	$qc_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $qc_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $qc_it as $qc_file ) {
		$qc_file->isDir() ? rmdir( $qc_file->getPathname() ) : unlink( $qc_file->getPathname() );
	}
	rmdir( $qc_dir );
}
