<?php
/**
 * Admin tools (spec §5.8, §5.10, §7): Outcome emails, Exports, Retention, Health.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools pages.
 */
final class Qhta_Competitions_Admin_Tools {

	/** User meta: submissions chosen for outcome emails. */
	const SELECTION_META = 'qc_notify_selection';

	/** User meta: fingerprint of the selection the admin has test-sent. */
	const TESTED_META = 'qc_notify_tested';

	/** Option: last time the hourly cron ran. */
	const CRON_LAST_OPTION = 'qc_cron_last';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'bulk_actions-edit-' . Qhta_Competitions_Submission_CPT::POST_TYPE, array( __CLASS__, 'bulk_action' ), 20 );
		add_filter( 'handle_bulk_actions-edit-' . Qhta_Competitions_Submission_CPT::POST_TYPE, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_qc_notify_test', array( __CLASS__, 'handle_notify_test' ) );
		add_action( 'admin_post_qc_notify_queue', array( __CLASS__, 'handle_notify_queue' ) );
		add_action( 'admin_post_qc_retention_run', array( __CLASS__, 'handle_retention_run' ) );
		add_action( 'admin_post_qc_mail_test', array( __CLASS__, 'handle_mail_test' ) );
	}

	/**
	 * Pages under Competitions.
	 */
	public static function menu() {
		$parent = 'edit.php?post_type=' . Qhta_Competitions_Program_CPT::POST_TYPE;
		$cap    = Qhta_Competitions_Plugin::CAP_MANAGE;
		add_submenu_page( $parent, __( 'Outcome emails', 'qhta-competitions' ), __( 'Outcome emails', 'qhta-competitions' ), $cap, 'qc-notify', array( __CLASS__, 'render_notify' ) );
		add_submenu_page( $parent, __( 'Exports', 'qhta-competitions' ), __( 'Exports', 'qhta-competitions' ), $cap, 'qc-exports', array( __CLASS__, 'render_exports' ) );
		add_submenu_page( $parent, __( 'Retention', 'qhta-competitions' ), __( 'Retention', 'qhta-competitions' ), $cap, 'qc-retention', array( __CLASS__, 'render_retention' ) );
		add_submenu_page( $parent, __( 'Health', 'qhta-competitions' ), __( 'Health', 'qhta-competitions' ), $cap, 'qc-health', array( __CLASS__, 'render_health' ) );
	}

	/**
	 * CSS.
	 *
	 * @param string $hook Hook.
	 */
	public static function assets( $hook ) {
		foreach ( array( 'qc-notify', 'qc-exports', 'qc-retention', 'qc-health' ) as $p ) {
			if ( false !== strpos( $hook, $p ) ) {
				wp_enqueue_style( 'qc-admin', QC_URL . 'assets/css/admin.css', array(), QC_VERSION );
			}
		}
	}

	/**
	 * Page URL.
	 *
	 * @param string $page Slug.
	 * @param array  $args Extra args.
	 * @return string
	 */
	private static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => Qhta_Competitions_Program_CPT::POST_TYPE, 'page' => $page ), $args ), admin_url( 'edit.php' ) );
	}

	/**
	 * Redirect with a message.
	 *
	 * @param string $url  URL.
	 * @param string $msg  Message.
	 * @param string $type success|error|warning.
	 */
	private static function back( $url, $msg, $type = 'success' ) {
		wp_safe_redirect( add_query_arg( array( 'qc_msg' => rawurlencode( $msg ), 'qc_type' => $type ), $url ) );
		exit;
	}

	/**
	 * Print a redirect message.
	 */
	private static function message() {
		if ( empty( $_GET['qc_msg'] ) ) {
			return;
		}
		$type = isset( $_GET['qc_type'] ) ? sanitize_key( wp_unslash( $_GET['qc_type'] ) ) : 'success';
		$type = in_array( $type, array( 'success', 'error', 'warning' ), true ) ? $type : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['qc_msg'] ) ) ) . '</p></div>';
	}

	/**
	 * Capability + nonce.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'qhta-competitions' ), 403 );
		}
		check_admin_referer( $action );
	}

	/* ---------------------------------------------------------------------
	 * Outcome emails
	 * ------------------------------------------------------------------ */

	/**
	 * Add "Notify outcome" to the Submissions bulk actions.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_action( $actions ) {
		$actions['qc_notify'] = __( 'Notify outcome (preview first)', 'qhta-competitions' );
		return $actions;
	}

	/**
	 * Store the selection and go to the preview page.
	 *
	 * @param string $redirect Redirect.
	 * @param string $action   Action.
	 * @param int[]  $ids      IDs.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'qc_notify' !== $action || ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			return $redirect;
		}
		update_user_meta( get_current_user_id(), self::SELECTION_META, array_map( 'intval', $ids ) );
		delete_user_meta( get_current_user_id(), self::TESTED_META );
		return self::url( 'qc-notify' );
	}

	/**
	 * Current selection, classified.
	 *
	 * @param bool $resend Re-send to already-notified entries.
	 * @return array{ids:int[], send:array, skipped:array, fingerprint:string}
	 */
	private static function selection( $resend ) {
		$ids = get_user_meta( get_current_user_id(), self::SELECTION_META, true );
		$ids = is_array( $ids ) ? $ids : array();
		$c   = Qhta_Competitions_Outcomes::classify( $ids, $resend );
		return array(
			'ids'         => $ids,
			'send'        => $c['send'],
			'skipped'     => $c['skipped'],
			'fingerprint' => md5( wp_json_encode( array( $c['send'], $resend ) ) ),
		);
	}

	/**
	 * Preview page.
	 */
	public static function render_notify() {
		$resend = ! empty( $_GET['resend'] );
		$sel    = self::selection( $resend );
		$labels = Qhta_Competitions_Submission_CPT::statuses();
		$queue  = Qhta_Competitions_Outcomes::queue();
		$next   = wp_next_scheduled( Qhta_Competitions_Outcomes::BATCH_HOOK );

		echo '<div class="wrap qc-admin"><h1>' . esc_html__( 'Outcome emails', 'qhta-competitions' ) . '</h1>';
		self::message();
		if ( $queue ) {
			/* translators: 1: count, 2: time */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( '%1$d emails waiting to send, in batches of 20. Next batch: %2$s.', 'qhta-competitions' ), count( $queue ), $next ? wp_date( 'g:i a', $next ) : __( 'when WP-Cron next runs', 'qhta-competitions' ) ) ) . '</p></div>';
		}
		if ( ! $sel['ids'] ) {
			echo '<p>' . esc_html__( 'Go to Submissions, select the entries (e.g. filter a round, then select all), and choose the bulk action "Notify outcome". You\'ll come back here to check each email and send yourself a test before anything goes out.', 'qhta-competitions' ) . '</p></div>';
			return;
		}

		$total = 0;
		foreach ( $sel['send'] as $ids ) {
			$total += count( $ids );
		}
		/* translators: 1: selected, 2: will send */
		echo '<p>' . esc_html( sprintf( __( '%1$d selected; %2$d will be emailed.', 'qhta-competitions' ), count( $sel['ids'] ), $total ) ) . ' ';
		echo $resend
			? '<a href="' . esc_url( self::url( 'qc-notify' ) ) . '">' . esc_html__( 'Skip entries already notified', 'qhta-competitions' ) . '</a>'
			: '<a href="' . esc_url( self::url( 'qc-notify', array( 'resend' => 1 ) ) ) . '">' . esc_html__( 'Include entries already notified (re-send)', 'qhta-competitions' ) . '</a>';
		echo '</p>';

		foreach ( $sel['send'] as $status => $ids ) {
			$p = Qhta_Competitions_Outcomes::preview( $ids[0] );
			/* translators: 1: status, 2: count, 3: ref */
			echo '<h2>' . esc_html( sprintf( __( '%1$s — %2$d emails (preview for %3$s)', 'qhta-competitions' ), $labels[ $status ], count( $ids ), get_post_meta( $ids[0], Qhta_Competitions_Submission_CPT::REF_META, true ) ) ) . '</h2>';
			echo '<div class="qc-email-preview"><p><strong>' . esc_html__( 'Subject:', 'qhta-competitions' ) . '</strong> ' . esc_html( $p['subject'] ) . '</p><pre>' . esc_html( $p['body'] ) . '</pre></div>';
		}
		if ( $sel['skipped'] ) {
			echo '<h2>' . esc_html__( 'Not emailed', 'qhta-competitions' ) . '</h2><ul class="qc-skipped">';
			foreach ( $sel['skipped'] as $id => $why ) {
				echo '<li>' . esc_html( get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true ) . ' — ' . $why ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! $total ) {
			echo '</div>';
			return;
		}

		$tested = get_user_meta( get_current_user_id(), self::TESTED_META, true ) === $sel['fingerprint'];
		$me     = wp_get_current_user()->user_email;
		echo '<div class="qc-notify-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="qc_notify_test"><input type="hidden" name="resend" value="' . ( $resend ? 1 : 0 ) . '">';
		wp_nonce_field( 'qc_notify_test' );
		/* translators: %s: email */
		submit_button( sprintf( __( '1. Send me a test of each (%s)', 'qhta-competitions' ), $me ), $tested ? 'secondary' : 'primary', 'submit', false );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="qc_notify_queue"><input type="hidden" name="resend" value="' . ( $resend ? 1 : 0 ) . '">';
		wp_nonce_field( 'qc_notify_queue' );
		/* translators: %d: count */
		submit_button( sprintf( __( '2. Send %d emails', 'qhta-competitions' ), $total ), $tested ? 'primary' : 'secondary', 'submit', false, $tested ? array() : array( 'disabled' => 'disabled' ) );
		echo $tested ? '' : ' <span class="description">' . esc_html__( 'Send yourself a test first.', 'qhta-competitions' ) . '</span>';
		echo '</form></div></div>';
	}

	/**
	 * Test-send the previews to the current admin.
	 */
	public static function handle_notify_test() {
		self::guard( 'qc_notify_test' );
		$resend = ! empty( $_POST['resend'] );
		$sel    = self::selection( $resend );
		$n      = Qhta_Competitions_Outcomes::send_test( $sel['send'], wp_get_current_user()->user_email );
		if ( $n ) {
			update_user_meta( get_current_user_id(), self::TESTED_META, $sel['fingerprint'] );
		}
		/* translators: %d: count */
		self::back( self::url( 'qc-notify', $resend ? array( 'resend' => 1 ) : array() ), $n ? sprintf( __( '%d test emails sent to you. Check them, then send.', 'qhta-competitions' ), $n ) : __( 'The test email could not be sent — check Health → mail test.', 'qhta-competitions' ), $n ? 'success' : 'error' );
	}

	/**
	 * Queue the real emails (only after a test of this exact selection).
	 */
	public static function handle_notify_queue() {
		self::guard( 'qc_notify_queue' );
		$resend = ! empty( $_POST['resend'] );
		$sel    = self::selection( $resend );
		$back   = self::url( 'qc-notify', $resend ? array( 'resend' => 1 ) : array() );
		if ( get_user_meta( get_current_user_id(), self::TESTED_META, true ) !== $sel['fingerprint'] ) {
			self::back( $back, __( 'Send yourself a test of this selection first.', 'qhta-competitions' ), 'error' );
		}
		$ids = array();
		foreach ( $sel['send'] as $group ) {
			$ids = array_merge( $ids, $group );
		}
		$len = Qhta_Competitions_Outcomes::enqueue( $ids );
		delete_user_meta( get_current_user_id(), self::SELECTION_META );
		delete_user_meta( get_current_user_id(), self::TESTED_META );
		/* translators: 1: count, 2: queue */
		self::back( self::url( 'qc-notify' ), sprintf( __( '%1$d emails queued (%2$d waiting in total). They go out in batches of 20 a minute apart.', 'qhta-competitions' ), count( $ids ), $len ) );
	}

	/* ---------------------------------------------------------------------
	 * Exports
	 * ------------------------------------------------------------------ */

	/**
	 * Export links per round.
	 */
	public static function render_exports() {
		echo '<div class="wrap qc-admin"><h1>' . esc_html__( 'Exports', 'qhta-competitions' ) . '</h1>';
		echo '<p>' . esc_html__( 'CSV files open directly in Excel. Test entries are never included. The standard export leaves out personal information (names, schools, contact details, signatures, referee details); only download the version with personal details when you need it, and delete it when you\'re done.', 'qhta-competitions' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Round', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Entries', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Publication consent register', 'qhta-competitions' ) . '</th></tr></thead><tbody>';
		$programs = get_posts( array( 'post_type' => Qhta_Competitions_Program_CPT::POST_TYPE, 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1 ) );
		foreach ( $programs as $p ) {
			echo '<tr><td><strong>' . esc_html( $p->post_title ) . '</strong></td><td>';
			echo '<a class="button" href="' . esc_url( Qhta_Competitions_Export::url( $p->ID, 'entries' ) ) . '">' . esc_html__( 'Entries (no personal details)', 'qhta-competitions' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( Qhta_Competitions_Export::url( $p->ID, 'entries', true ) ) . '">' . esc_html__( 'Entries with personal details', 'qhta-competitions' ) . '</a>';
			echo '</td><td><a class="button" href="' . esc_url( Qhta_Competitions_Export::url( $p->ID, 'consent' ) ) . '">' . esc_html__( 'Consent register', 'qhta-competitions' ) . '</a><br><span class="description">' . esc_html__( 'Awarded and commended entries with publication consent.', 'qhta-competitions' ) . '</span></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ---------------------------------------------------------------------
	 * Retention
	 * ------------------------------------------------------------------ */

	/**
	 * Dry run per round, with a manual purge for rounds that are due.
	 */
	public static function render_retention() {
		echo '<div class="wrap qc-admin"><h1>' . esc_html__( 'Retention', 'qhta-competitions' ) . '</h1>';
		self::message();
		echo '<p>' . esc_html__( 'After each round\'s retention period, entries that were not awarded or commended lose their personal information (names, schools, contact details, signatures, referee details) and uploaded files. References, outcomes and scores are kept. This runs automatically once a day and cannot be undone. Awarded and commended entries are kept for the publication record.', 'qhta-competitions' ) . '</p>';
		echo '<table class="widefat striped qc-retention"><thead><tr><th>' . esc_html__( 'Round', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Purge date', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Will be purged', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Kept (awarded/commended)', 'qhta-competitions' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( Qhta_Competitions_Retention::preview() as $r ) {
			$refs = array();
			foreach ( $r['ids'] as $id ) {
				$refs[] = (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true );
			}
			$due = null === $r['due'] ? __( 'No close date', 'qhta-competitions' ) : wp_date( 'j F Y', $r['due'] );
			echo '<tr><td><strong>' . esc_html( $r['title'] ) . '</strong></td><td>' . esc_html( $due ) . ( $r['is_due'] ? ' <span class="qc-badge qc-badge--preview">' . esc_html__( 'due', 'qhta-competitions' ) . '</span>' : '' ) . '</td>';
			/* translators: 1: count, 2: date */
			echo '<td>' . esc_html( null === $r['due'] ? sprintf( _n( '%d entry (once a close date is set)', '%d entries (once a close date is set)', count( $refs ), 'qhta-competitions' ), count( $refs ) ) : sprintf( _n( '%1$d entry will be purged on %2$s', '%1$d entries will be purged on %2$s', count( $refs ), 'qhta-competitions' ), count( $refs ), $due ) );
			if ( $refs ) {
				echo '<details><summary>' . esc_html__( 'List', 'qhta-competitions' ) . '</summary><p class="qc-refs">' . esc_html( implode( ', ', $refs ) ) . '</p></details>';
			}
			echo '</td><td>' . (int) $r['kept'] . '</td><td>';
			if ( $r['is_due'] && $refs ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="qc_retention_run"><input type="hidden" name="program" value="' . (int) $r['program'] . '">';
				wp_nonce_field( 'qc_retention_run_' . $r['program'] );
				echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I understand this cannot be undone', 'qhta-competitions' ) . '</label> ';
				submit_button( __( 'Purge now', 'qhta-competitions' ), 'delete small', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Purge a due round now.
	 */
	public static function handle_retention_run() {
		$pid = isset( $_POST['program'] ) ? absint( $_POST['program'] ) : 0;
		self::guard( 'qc_retention_run_' . $pid );
		if ( empty( $_POST['confirm'] ) ) {
			self::back( self::url( 'qc-retention' ), __( 'Tick the box to confirm.', 'qhta-competitions' ), 'error' );
		}
		$due = Qhta_Competitions_Retention::due_at( $pid );
		if ( null === $due || time() < $due ) {
			self::back( self::url( 'qc-retention' ), __( 'This round is not due for purging yet.', 'qhta-competitions' ), 'error' );
		}
		$n = 0;
		foreach ( Qhta_Competitions_Retention::candidates( $pid ) as $id ) {
			Qhta_Competitions_Retention::purge( $id, 'retention (manual)' );
			++$n;
		}
		/* translators: %d: count */
		self::back( self::url( 'qc-retention' ), sprintf( _n( '%d entry purged.', '%d entries purged.', $n, 'qhta-competitions' ), $n ) );
	}

	/* ---------------------------------------------------------------------
	 * Health
	 * ------------------------------------------------------------------ */

	/**
	 * Run the health checks.
	 *
	 * @return array[] {label, state: ok|warn|fail|info, detail}
	 */
	public static function checks() {
		$out = array();
		$add = function ( $label, $state, $detail ) use ( &$out ) {
			$out[] = array( 'label' => $label, 'state' => $state, 'detail' => $detail );
		};

		// Feature flags.
		if ( Qhta_Competitions_Flags::active() ) {
			$m = Qhta_Competitions_Flags::mode( Qhta_Competitions_Flags::MODULE_FLAG );
			/* translators: %s: mode */
			$add( __( 'Feature flags', 'qhta-competitions' ), 'on' === $m[0] ? 'ok' : 'warn', sprintf( __( 'wordpress-feature-flags active; "competitions" is %s.', 'qhta-competitions' ), $m[0] ) );
		} else {
			$add( __( 'Feature flags', 'qhta-competitions' ), 'fail', __( 'wordpress-feature-flags is not active — every form is hidden.', 'qhta-competitions' ) );
		}

		// Upload protection.
		$dir = Qhta_Competitions_Plugin::upload_base_dir();
		if ( ! file_exists( $dir . '.htaccess' ) || ! file_exists( $dir . 'index.php' ) ) {
			Qhta_Competitions_Plugin::protect_upload_dir();
		}
		$probe = self::probe_upload_dir();
		$add( __( 'Uploads protected', 'qhta-competitions' ), $probe[0], $probe[1] );

		// Cron.
		$last = (int) get_option( self::CRON_LAST_OPTION, 0 );
		$next = wp_next_scheduled( Qhta_Competitions_Confirm::CRON );
		if ( ! $next ) {
			$add( __( 'Scheduled tasks', 'qhta-competitions' ), 'fail', __( 'The hourly qhta-competitions task is not scheduled. Deactivate and reactivate the plugin.', 'qhta-competitions' ) );
		} elseif ( ! $last ) {
			$add( __( 'Scheduled tasks', 'qhta-competitions' ), 'warn', __( 'Scheduled, but it has not run yet. If this stays, WP-Cron is not running (check DISABLE_WP_CRON and the server cron job).', 'qhta-competitions' ) );
		} else {
			/* translators: 1: time ago, 2: next */
			$add( __( 'Scheduled tasks', 'qhta-competitions' ), time() - $last > 3 * HOUR_IN_SECONDS ? 'warn' : 'ok', sprintf( __( 'Last ran %1$s ago; next %2$s. Reminders, retention and outcome batches depend on this.', 'qhta-competitions' ), human_time_diff( $last ), wp_date( 'j M g:i a', $next ) ) . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' ' . __( 'DISABLE_WP_CRON is set, so a server cron job must call wp-cron.php.', 'qhta-competitions' ) : '' ) );
		}

		// Outcome queue.
		$q = count( Qhta_Competitions_Outcomes::queue() );
		/* translators: %d: count */
		$add( __( 'Outcome email queue', 'qhta-competitions' ), $q && ! wp_next_scheduled( Qhta_Competitions_Outcomes::BATCH_HOOK ) ? 'warn' : 'ok', $q ? sprintf( __( '%d waiting.', 'qhta-competitions' ), $q ) : __( 'Empty.', 'qhta-competitions' ) );

		$add( __( 'PMPro membership check', 'qhta-competitions' ), function_exists( 'pmpro_hasMembershipLevel' ) ? 'ok' : 'info', function_exists( 'pmpro_hasMembershipLevel' ) ? __( 'Available.', 'qhta-competitions' ) : __( 'PMPro not active — membership is checked by hand.', 'qhta-competitions' ) );
		/* translators: %s: version */
		$add( __( 'Database', 'qhta-competitions' ), get_option( Qhta_Competitions_Plugin::DB_VERSION_OPTION ) === Qhta_Competitions_Plugin::DB_VERSION ? 'ok' : 'warn', sprintf( __( 'Scores table schema version %s.', 'qhta-competitions' ), (string) get_option( Qhta_Competitions_Plugin::DB_VERSION_OPTION ) ) );
		return $out;
	}

	/**
	 * Self-test: write a probe file into the protected folder and request it over HTTP.
	 *
	 * @return array{0:string,1:string} [state, detail]
	 */
	private static function probe_upload_dir() {
		$dir  = Qhta_Competitions_Plugin::upload_base_dir();
		$file = 'qc-health-probe-' . wp_generate_password( 8, false ) . '.txt';
		if ( false === file_put_contents( $dir . $file, 'qc-probe' ) ) {
			return array( 'fail', __( 'Could not write to the protected uploads folder.', 'qhta-competitions' ) );
		}
		$uploads = wp_upload_dir( null, false );
		$url     = trailingslashit( $uploads['baseurl'] ) . 'qhta-competitions/' . $file;
		/**
		 * URL the server uses to reach its own uploads (e.g. when it can't reach its public hostname).
		 *
		 * @param string $url Probe URL.
		 */
		$fetch = (string) apply_filters( 'qc_health_probe_url', $url );
		$res   = wp_remote_get( $fetch, array( 'timeout' => 10, 'redirection' => 0 ) );
		wp_delete_file( $dir . $file );
		$manual = sprintf( 'curl -I %s', trailingslashit( $uploads['baseurl'] ) . 'qhta-competitions/index.php' );

		if ( is_wp_error( $res ) ) {
			/* translators: 1: error, 2: command */
			return array( 'warn', sprintf( __( 'Could not test from the server (%1$s). Check by hand: %2$s should return 403.', 'qhta-competitions' ), $res->get_error_message(), $manual ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $res ), 'qc-probe' ) ) {
			return array( 'fail', __( 'Files in the protected folder can be downloaded directly — the web server is ignoring its .htaccess. Fix before taking entries.', 'qhta-competitions' ) );
		}
		/* translators: %d: status */
		return array( in_array( $code, array( 401, 403, 404 ), true ) ? 'ok' : 'warn', sprintf( __( 'Direct request to a file in the protected folder returned %d.', 'qhta-competitions' ), $code ) );
	}

	/**
	 * Health page.
	 */
	public static function render_health() {
		echo '<div class="wrap qc-admin"><h1>' . esc_html__( 'Health', 'qhta-competitions' ) . '</h1>';
		self::message();
		$icons = array( 'ok' => '✓', 'warn' => '⚠', 'fail' => '✗', 'info' => 'ℹ' );
		echo '<table class="widefat striped qc-health"><tbody>';
		foreach ( self::checks() as $c ) {
			echo '<tr class="qc-health--' . esc_attr( $c['state'] ) . '"><td class="qc-health__icon">' . esc_html( $icons[ $c['state'] ] ) . '</td><th>' . esc_html( $c['label'] ) . '</th><td>' . esc_html( $c['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Mail test', 'qhta-competitions' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="qc_mail_test">';
		wp_nonce_field( 'qc_mail_test' );
		/* translators: %s: email */
		submit_button( sprintf( __( 'Send a test email to %s', 'qhta-competitions' ), wp_get_current_user()->user_email ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Send a test email using the plugin's template.
	 */
	public static function handle_mail_test() {
		self::guard( 'qc_mail_test' );
		$error = '';
		$catch = function ( $e ) use ( &$error ) {
			$error = $e instanceof WP_Error ? $e->get_error_message() : '';
		};
		add_action( 'wp_mail_failed', $catch );
		$ok = Qhta_Competitions_Mailer::send( wp_get_current_user()->user_email, __( 'QHTA Competitions mail test', 'qhta-competitions' ), __( "This is a test from QHTA Competitions → Health.\n\nIf it arrived, submission and outcome emails can be sent.", 'qhta-competitions' ) );
		remove_action( 'wp_mail_failed', $catch );
		/* translators: %s: error */
		self::back( self::url( 'qc-health' ), $ok ? __( 'Test email sent — check your inbox (and spam).', 'qhta-competitions' ) : sprintf( __( 'Sending failed: %s', 'qhta-competitions' ), '' !== $error ? $error : __( 'unknown error', 'qhta-competitions' ) ), $ok ? 'success' : 'error' );
	}
}
