<?php
/**
 * M1 smoke test. Run against a disposable site with wp-cli, in two steps
 * (the wpff registry is per request, so registration is checked in a fresh one):
 *
 *   wp eval-file wp-content/plugins/qhta-competitions/tests/m1-smoke.php setup
 *   wp eval-file wp-content/plugins/qhta-competitions/tests/m1-smoke.php verify
 *
 * DESTRUCTIVE: setup deletes every qhta_program and qhta_submission on the site first. Never run on staging/live.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$qc_step  = isset( $args[0] ) ? $args[0] : 'setup';
$qc_fails = 0;

$qc_check = function ( $ok, $label ) use ( &$qc_fails ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		++$qc_fails;
	}
};

$qc_find = function ( $slug ) {
	$p = get_posts( array( 'post_type' => 'qhta_program', 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1 ) );
	return $p ? $p[0] : null;
};

if ( ! class_exists( 'Qhta_Competitions_Admin_Programs' ) ) {
	require_once QC_DIR . 'admin/class-admin-programs.php';
}

if ( 'setup' === $qc_step ) {
	foreach ( get_posts( array( 'post_type' => 'qhta_submission', 'post_status' => array_merge( array_keys( Qhta_Competitions_Submission_CPT::statuses() ), array( 'trash' ) ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $qc_id ) {
		wp_delete_post( $qc_id, true ); // Also removes their files.
	}
	foreach ( get_posts( array( 'post_type' => 'qhta_program', 'post_status' => array( 'any', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $qc_id ) {
		wp_delete_post( $qc_id, true );
	}
	update_option( 'wpff_ui_flags', array( 'comp-clash' => array( 'label' => 'UI flag that clashes' ) ) );
	delete_option( 'wpff_modes' ); // Admin-set flag modes from earlier runs.

	// Activation artefacts.
	global $wpdb;
	$qc_check( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}qc_scores'" ) === $wpdb->prefix . 'qc_scores', 'scores table exists' );
	$qc_check( (bool) get_role( 'qhta_judge' ), 'qhta_judge role exists' );
	$qc_check( get_role( 'administrator' )->has_cap( 'qc_manage' ), 'administrator has qc_manage' );
	$qc_dir = Qhta_Competitions_Plugin::upload_base_dir();
	$qc_check( file_exists( $qc_dir . '.htaccess' ) && file_exists( $qc_dir . 'index.php' ), 'protected upload dir has .htaccess + index.php' );
	$qc_check( 'no' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'qc_db_version'" ) || 'off' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'qc_db_version'" ), 'qc_db_version not autoloaded' );

	// Presets load into new rounds with no validation errors.
	foreach ( array( 'ecc-bursary-2027', 'writing-competition-2026-demo' ) as $qc_preset ) {
		list( $qc_pid, $qc_errors ) = Qhta_Competitions_Presets::create_round( $qc_preset );
		$qc_check( ! is_wp_error( $qc_pid ), "preset $qc_preset creates a round" );
		$qc_check( array() === $qc_errors, "preset $qc_preset validates cleanly" . ( $qc_errors ? ': ' . implode( ' | ', $qc_errors ) : '' ) );
	}

	$qc_bursary = $qc_find( 'ecc-bursary-2027' );
	$qc_comp    = $qc_find( 'writing-competition-2026-demo' );
	$qc_bc      = Qhta_Competitions_Program_CPT::get_config( $qc_bursary->ID );
	$qc_cc      = Qhta_Competitions_Program_CPT::get_config( $qc_comp->ID );

	$qc_check( 15 === count( $qc_cc['categories'] ), 'writing comp has 15 categories' );
	$qc_check( 'referee' === $qc_bc['confirmer']['role'] && 'none' === $qc_cc['confirmer']['role'], 'confirmer roles' );
	$qc_check( '2027-01-28 14:00:00' === $qc_bc['closes_at'], 'bursary closes 29 Jan 2027 00:00 AEST stored as UTC (' . $qc_bc['closes_at'] . ')' );
	$qc_check( '2027-01-29T00:00' === Qhta_Competitions_Util::utc_to_local_input( $qc_bc['closes_at'] ), 'close date round-trips to local input' );
	$qc_check( false === $qc_bc['award_config']['coupon_enabled'] && 4311 === $qc_bc['award_config']['coupon_product_id'], 'bursary coupon off by default, product 4311' );
	$qc_check( true === $qc_bc['anonymise'], 'anonymise default true' );

	// Config round-trips through the admin save path without loss.
	foreach ( array( $qc_bursary, $qc_comp ) as $qc_p ) {
		$qc_before = Qhta_Competitions_Program_CPT::get_config( $qc_p->ID );
		$_POST     = array(
			Qhta_Competitions_Admin_Programs::NONCE => wp_create_nonce( Qhta_Competitions_Admin_Programs::NONCE_ACTION ),
			'qc'           => array(
				'type'               => $qc_before['type'],
				'opens_at'           => Qhta_Competitions_Util::utc_to_local_input( $qc_before['opens_at'] ),
				'closes_at'          => Qhta_Competitions_Util::utc_to_local_input( $qc_before['closes_at'] ),
				'late_grace_minutes' => (string) $qc_before['late_grace_minutes'],
				'retention_months'   => (string) $qc_before['retention_months'],
				'max_upload_mb'      => (string) $qc_before['max_upload_mb'],
				'judge_spread_margin' => (string) $qc_before['judge_spread_margin'],
				'privacy_notice'     => $qc_before['privacy_notice'],
				'_present'           => '1',
			) + ( $qc_before['anonymise'] ? array( 'anonymise' => '1' ) : array() ),
			'qc_json'      => array(),
			'qc_confirmer' => array(
				'role'        => $qc_before['confirmer']['role'],
				'label'       => $qc_before['confirmer']['label'],
				'email_field' => $qc_before['confirmer']['email_field'],
				'name_field'  => isset( $qc_before['confirmer']['name_field'] ) ? $qc_before['confirmer']['name_field'] : '',
				'declaration' => isset( $qc_before['confirmer']['declaration'] ) ? $qc_before['confirmer']['declaration'] : '',
			),
			'qc_limits'    => array_map( function ( $v ) { return null === $v ? '' : (string) $v; }, $qc_before['entry_limits'] ),
			'qc_wc'        => array(
				'_present'               => '1',
				'exclude_after_headings' => implode( "\n", $qc_before['word_count_rules']['exclude_after_headings'] ),
			) + ( $qc_before['word_count_rules']['include_footnotes'] ? array( 'include_footnotes' => '1' ) : array() ),
			'qc_award'     => array(
				'_present'            => '1',
				'coupon_product_id'   => null === $qc_before['award_config']['coupon_product_id'] ? '' : (string) $qc_before['award_config']['coupon_product_id'],
				'coupon_variation_id' => null === $qc_before['award_config']['coupon_variation_id'] ? '' : (string) $qc_before['award_config']['coupon_variation_id'],
			) + ( $qc_before['award_config']['coupon_enabled'] ? array( 'coupon_enabled' => '1' ) : array() ),
		);
		foreach ( Qhta_Competitions_Admin_Programs::JSON_SECTIONS as $qc_s ) {
			$_POST['qc_json'][ $qc_s ] = Qhta_Competitions_Util::json_pretty( $qc_before[ $qc_s ] );
		}
		$_POST = wp_slash( $_POST );
		delete_user_meta( get_current_user_id(), Qhta_Competitions_Admin_Programs::NOTICE_META );
		Qhta_Competitions_Admin_Programs::save( $qc_p->ID, $qc_p );
		$qc_after   = Qhta_Competitions_Program_CPT::get_config( $qc_p->ID );
		$qc_notices = get_user_meta( get_current_user_id(), Qhta_Competitions_Admin_Programs::NOTICE_META, true );
		$qc_check( empty( $qc_notices ), "{$qc_p->post_name}: admin save raises no errors" . ( $qc_notices ? ': ' . wp_json_encode( $qc_notices ) : '' ) );
		$qc_diff = array();
		foreach ( $qc_before as $k => $v ) {
			if ( $v !== $qc_after[ $k ] ) {
				$qc_diff[] = $k;
			}
		}
		$qc_check( ! $qc_diff, "{$qc_p->post_name}: config round-trips save/edit without loss" . ( $qc_diff ? ' (changed: ' . implode( ', ', $qc_diff ) . ')' : '' ) );
	}

	// Invalid JSON is rejected, previous value kept, text preserved for the user.
	$qc_before = Qhta_Competitions_Program_CPT::get_config( $qc_comp->ID );
	$_POST     = wp_slash(
		array(
			Qhta_Competitions_Admin_Programs::NONCE => wp_create_nonce( Qhta_Competitions_Admin_Programs::NONCE_ACTION ),
			'qc'      => array( 'type' => 'competition' ),
			'qc_json' => array( 'categories' => '[{"slug": "broken",' ),
		)
	);
	Qhta_Competitions_Admin_Programs::save( $qc_comp->ID, $qc_comp );
	$qc_after    = Qhta_Competitions_Program_CPT::get_config( $qc_comp->ID );
	$qc_rejected = get_user_meta( get_current_user_id(), Qhta_Competitions_Admin_Programs::REJECTED_META . $qc_comp->ID, true );
	$qc_check( $qc_before['categories'] === $qc_after['categories'], 'invalid JSON does not overwrite categories' );
	$qc_check( isset( $qc_rejected['categories']['raw'] ), 'invalid JSON text kept for the user' );
	$qc_check( $qc_before['word_count_rules'] === $qc_after['word_count_rules'] && $qc_before['award_config'] === $qc_after['award_config'] && $qc_before['anonymise'] === $qc_after['anonymise'], 'partial POST leaves boxes that were not posted untouched' );
	// Semantic validation: duplicate category slug.
	$qc_errs = Qhta_Competitions_Program_CPT::save_config( $qc_comp->ID, array( 'categories' => array( array( 'slug' => 'a', 'label' => 'A' ), array( 'slug' => 'a', 'label' => 'B' ) ) ) );
	$qc_check( (bool) $qc_errs && $qc_before['categories'] === Qhta_Competitions_Program_CPT::get_config( $qc_comp->ID )['categories'], 'duplicate slug rejected' );
	$qc_errs = Qhta_Competitions_Program_CPT::save_config( $qc_comp->ID, array( 'opens_at' => '2026-12-01 00:00:00', 'closes_at' => '2026-11-01 00:00:00' ) );
	$qc_check( (bool) $qc_errs, 'close before open rejected' );
	delete_user_meta( get_current_user_id(), Qhta_Competitions_Admin_Programs::NOTICE_META );
	delete_user_meta( get_current_user_id(), Qhta_Competitions_Admin_Programs::REJECTED_META . $qc_comp->ID );
	$_POST = array();

	// Flag keys.
	$qc_check( 'comp-ecc-bursary-2027' === Qhta_Competitions_Flags::key_for( $qc_bursary->ID ), 'bursary flag key' );
	$qc_check( 'comp-writing-competition-2026-demo' === Qhta_Competitions_Flags::key_for( $qc_comp->ID ), 'writing comp flag key' );

	// Rename slug: key unchanged.
	wp_update_post( array( 'ID' => $qc_comp->ID, 'post_name' => 'renamed-writing-comp', 'post_status' => 'publish' ) );
	$qc_check( 'comp-writing-competition-2026-demo' === Qhta_Competitions_Flags::key_for( $qc_comp->ID ), 'renaming slug keeps flag key' );

	// Clash with a wpff UI flag → suffixed.
	$qc_clash = wp_insert_post( array( 'post_type' => 'qhta_program', 'post_status' => 'draft', 'post_title' => 'Clash', 'post_name' => 'clash' ) );
	$qc_check( 'comp-clash-2' === Qhta_Competitions_Flags::key_for( $qc_clash ), 'UI-flag clash suffixed (' . Qhta_Competitions_Flags::key_for( $qc_clash ) . ')' );
	// Clash with another round's key.
	$qc_clash2 = wp_insert_post( array( 'post_type' => 'qhta_program', 'post_status' => 'draft', 'post_title' => 'Clash', 'post_name' => 'clash' ) );
	$qc_check( 'comp-clash-3' === Qhta_Competitions_Flags::key_for( $qc_clash2 ), 'round clash suffixed (' . Qhta_Competitions_Flags::key_for( $qc_clash2 ) . ')' );

	// Long slug truncated to 40 and valid.
	$qc_long = wp_insert_post( array( 'post_type' => 'qhta_program', 'post_status' => 'draft', 'post_title' => 'Long', 'post_name' => 'a-really-long-program-round-name-that-goes-on-and-on-2027' ) );
	$qc_lk   = Qhta_Competitions_Flags::key_for( $qc_long );
	$qc_check( strlen( $qc_lk ) <= 40 && Qhta_Competitions_Flags::valid_key( $qc_lk ), "long slug key valid ($qc_lk)" );

	// Trash keeps the flag in the cache.
	wp_trash_post( $qc_clash2 );
	$qc_keys = wp_list_pluck( Qhta_Competitions_Flags::cached_round_flags(), 'key' );
	$qc_check( in_array( 'comp-clash-3', $qc_keys, true ), 'trashed round flag stays cached' );
	// Permanent delete removes it.
	wp_delete_post( $qc_long, true );
	$qc_keys = wp_list_pluck( Qhta_Competitions_Flags::cached_round_flags(), 'key' );
	$qc_check( ! in_array( $qc_lk, $qc_keys, true ), 'deleted round flag removed from cache' );
	$qc_check( 'no' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'qc_round_flags'" ) || 'off' === $wpdb->get_var( "SELECT autoload FROM {$wpdb->options} WHERE option_name = 'qc_round_flags'" ), 'qc_round_flags not autoloaded' );

	// Submission status log.
	$qc_sub = wp_insert_post( array( 'post_type' => 'qhta_submission', 'post_status' => 'qc_submitted', 'post_title' => 'T', 'post_parent' => $qc_bursary->ID ) );
	$qc_r1  = Qhta_Competitions_Submission_CPT::transition( $qc_sub, 'qc_in_review', 'start judging' );
	$qc_r2  = Qhta_Competitions_Submission_CPT::transition( $qc_sub, 'qc_awarded' );
	$qc_log = Qhta_Competitions_Submission_CPT::get_log( $qc_sub );
	$qc_check( true === $qc_r1 && is_wp_error( $qc_r2 ), 'state diagram enforced (in_review → awarded blocked)' );
	$qc_last = end( $qc_log );
	$qc_check( $qc_last && 'qc_submitted' === $qc_last['from'] && 'qc_in_review' === $qc_last['to'] && 'start judging' === $qc_last['note'], 'transition logged with note' );
	wp_delete_post( $qc_sub, true );
}

if ( 'verify' === $qc_step ) {
	$qc_flags = wpff_flags();
	$qc_check( isset( $qc_flags['competitions'] ) && 'on' === $qc_flags['competitions']['default'] && $qc_flags['competitions']['admin_toggle'], 'competitions flag registered (default on, admin toggle)' );
	foreach ( array( 'comp-ecc-bursary-2027', 'comp-writing-competition-2026-demo', 'comp-clash-3' ) as $qc_k ) {
		$qc_check( isset( $qc_flags[ $qc_k ] ) && 'preview' === $qc_flags[ $qc_k ]['default'] && 'qhta-competitions' === $qc_flags[ $qc_k ]['owner'], "$qc_k registered (default preview, owner qhta-competitions)" );
	}
	$qc_check( isset( $qc_flags['comp-clash'] ) && 'Admin UI' === $qc_flags['comp-clash']['owner'], 'UI flag comp-clash not shadowed' );
	$qc_comp = $qc_find( 'renamed-writing-comp' );
	$qc_check( $qc_comp && 'comp-writing-competition-2026-demo' === Qhta_Competitions_Flags::key_for( $qc_comp->ID ) && isset( $qc_flags['comp-writing-competition-2026-demo'] ), 'renamed round keeps registered flag' );
	list( $qc_mode, $qc_src ) = Qhta_Competitions_Flags::mode( 'comp-ecc-bursary-2027' );
	$qc_check( 'preview' === $qc_mode && 'default' === $qc_src, 'round mode preview/default' );
	$qc_check( ! Qhta_Competitions_Flags::is_live( 'comp-ecc-bursary-2027' ), 'round not live' );
	$qc_check( '' !== Qhta_Competitions_Flags::preview_url( 'comp-ecc-bursary-2027' ), 'preview URL available with WPFF_PREVIEW_TOKEN' );
}

echo $qc_fails ? "\n$qc_fails FAILED\n" : "\nALL PASSED\n";
