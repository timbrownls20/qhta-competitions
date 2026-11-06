<?php
/**
 * Healthcheck canaries for qhta-competitions.
 *
 * Registered on qhta-healthcheck's `qhta_healthcheck_checks` filter, so the
 * canaries ship with the code whose dependencies they watch (see
 * docs/HEALTHCHECK.md). If qhta-healthcheck is not installed nothing here
 * runs: the filter is never applied, and add_filter() on an unused hook costs
 * nothing.
 *
 * Every test is read-only. The assertion helpers (`qhta_healthcheck_*`) belong
 * to qhta-healthcheck and are only called from inside its runner, which wraps
 * each test in a try/catch.
 *
 * Competitions → Health (admin/class-admin-tools.php) is the operator's
 * fuller self-test, and also repairs the upload folder and sends a test email.
 * These canaries are the subset worth watching all the time from the dashboard.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * QHTA Health canaries.
 */
final class Qhta_Competitions_Healthcheck {

	/** Slug the canaries are registered under (the plugin folder). */
	const SLUG = 'qhta-competitions';

	/** How long without an hourly qc_cron run before it counts as stalled. */
	const HEARTBEAT_MAX_AGE = 3 * HOUR_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'qhta_healthcheck_checks', array( __CLASS__, 'checks' ) );
	}

	/**
	 * Register this plugin's canaries.
	 *
	 * @param array $checks Slug => list of check specs.
	 * @return array
	 */
	public static function checks( $checks ) {
		$checks[ self::SLUG ] = array_merge(
			isset( $checks[ self::SLUG ] ) ? (array) $checks[ self::SLUG ] : array(),
			array(
				array(
					'id'       => 'feature-flags-api',
					'label'    => __( 'wordpress-feature-flags functions', 'qhta-competitions' ),
					'why'      => __( 'Every round form is gated by a feature flag. Without these functions the plugin fails closed: every form, on every page, is hidden.', 'qhta-competitions' ),
					'severity' => 'critical',
					'test'     => function () {
						return qhta_healthcheck_assert_functions( array( 'wpff_register', 'wpff_flag', 'wpff_mode', 'wpff_is_live', 'wpff_flags', 'wpff_preview_url' ) );
					},
				),
				array(
					'id'       => 'module-flag-on',
					'label'    => __( '"competitions" kill switch is on', 'qhta-competitions' ),
					'why'      => __( 'Off means every competition form shows "temporarily unavailable" and referee confirmations pause. Right during an incident, wrong if left that way.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_module_flag' ),
				),
				array(
					'id'       => 'round-flags-registered',
					'label'    => __( 'Each round has a registered flag', 'qhta-competitions' ),
					'why'      => __( 'A round whose comp-{slug} flag is not registered with wpff stays hidden whatever its dates say, and does not appear under Tools → Feature flags to be switched on.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_round_flags' ),
				),
				array(
					'id'       => 'form-shortcode',
					'label'    => __( 'Form shortcode registered', 'qhta-competitions' ),
					'why'      => __( 'Round forms are placed on pages with [qhta_program_form]. Unregistered, the page shows the raw shortcode text instead of the form.', 'qhta-competitions' ),
					'severity' => 'critical',
					'test'     => function () {
						return qhta_healthcheck_assert_shortcode( Qhta_Competitions_Form_Renderer::SHORTCODE );
					},
				),
				array(
					'id'       => 'upload-protection-files',
					'label'    => __( 'Protected uploads folder', 'qhta-competitions' ),
					'why'      => __( 'Entries (students\' work, applicants\' details) are stored in uploads/qhta-competitions/, which relies on its deny-all .htaccess and index.php. Missing, the files are one guessable URL from public.', 'qhta-competitions' ),
					'severity' => 'critical',
					'test'     => array( __CLASS__, 'test_upload_files' ),
				),
				array(
					'id'       => 'upload-protection-http',
					'label'    => __( 'Protected uploads refused over HTTP', 'qhta-competitions' ),
					'why'      => __( 'The .htaccess only works if the web server honours it. A LiteSpeed/hPanel change that stops reading it makes every stored entry downloadable while the files all look present.', 'qhta-competitions' ),
					'severity' => 'critical',
					'remote'   => true,
					'test'     => array( __CLASS__, 'test_upload_http' ),
				),
				array(
					'id'       => 'cron-scheduled',
					'label'    => __( 'Hourly task scheduled', 'qhta-competitions' ),
					'why'      => __( 'qc_cron sends referee reminders, runs the retention purge and restarts stuck outcome emails. Unscheduled, none of that happens and nothing says so.', 'qhta-competitions' ),
					'severity' => 'critical',
					'test'     => array( __CLASS__, 'test_cron_scheduled' ),
				),
				array(
					'id'       => 'cron-heartbeat',
					'label'    => __( 'Hourly task has run recently', 'qhta-competitions' ),
					'why'      => __( 'Scheduled is not the same as running: with DISABLE_WP_CRON and no server cron job, the event sits overdue for ever. The task stamps qc_cron_last each time it runs.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_heartbeat' ),
				),
				array(
					'id'       => 'scores-table',
					'label'    => __( 'Judging scores table', 'qhta-competitions' ),
					'why'      => __( 'Judges\' scores are saved to {prefix}qc_scores. Missing or at the wrong schema version, scoring fails and Results cannot rank entries.', 'qhta-competitions' ),
					'severity' => 'critical',
					'test'     => array( __CLASS__, 'test_scores_table' ),
				),
				array(
					'id'       => 'outcome-queue',
					'label'    => __( 'Outcome emails not stuck', 'qhta-competitions' ),
					'why'      => __( 'Outcome emails go out in batches of 20 via WP-Cron. Entries waiting with no batch scheduled means applicants are not being told their result.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_outcome_queue' ),
				),
				array(
					'id'       => 'judge-admin-access',
					'label'    => __( 'Judges let into wp-admin', 'qhta-competitions' ),
					'why'      => __( 'Judges are usually subscribers or customers, whom WooCommerce and PMPro keep out of wp-admin. These filters let judges through to the Judging screen; if one detaches, judges are bounced to My Account and cannot score.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_judge_filters' ),
				),
				array(
					'id'       => 'preview-token',
					'label'    => __( 'Secret preview link configured', 'qhta-competitions' ),
					'why'      => __( 'WPFF_PREVIEW_TOKEN in wp-config.php makes the committee\'s secret preview links work. Without it a round in preview can only be seen by logged-in admins.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_preview_token' ),
				),
				array(
					'id'       => 'site-timezone',
					'label'    => __( 'Site time zone is Brisbane', 'qhta-competitions' ),
					'why'      => __( 'Opening and closing times are entered and shown in the site time zone. Any other zone moves every deadline, and "closes before 29 January" closes at the wrong hour.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_timezone' ),
				),
				array(
					'id'       => 'pmpro-membership-api',
					'label'    => __( 'PMPro membership check', 'qhta-competitions' ),
					'why'      => __( 'Logged-in applicants are checked with pmpro_hasMembershipLevel(). If PMPro renames it, every applicant shows membership "?" and has to be checked by hand.', 'qhta-competitions' ),
					'severity' => 'warning',
					'test'     => array( __CLASS__, 'test_pmpro' ),
				),
			)
		);
		return $checks;
	}

	/* ---------------------------------------------------------------------
	 * Tests
	 * ------------------------------------------------------------------ */

	/**
	 * The `competitions` module flag is on.
	 *
	 * @return array
	 */
	public static function test_module_flag() {
		if ( ! Qhta_Competitions_Flags::active() ) {
			return qhta_healthcheck_skip( __( 'wordpress-feature-flags is not active (see the functions check).', 'qhta-competitions' ) );
		}
		$mode = Qhta_Competitions_Flags::mode( Qhta_Competitions_Flags::MODULE_FLAG );
		if ( ! $mode || 'on' !== $mode[0] ) {
			/* translators: %s: flag mode */
			return qhta_healthcheck_fail( sprintf( __( '"competitions" is %s — every form shows "temporarily unavailable"', 'qhta-competitions' ), $mode ? $mode[0] : 'unregistered' ) );
		}
		/* translators: %s: where the mode came from */
		return qhta_healthcheck_pass( sprintf( __( 'on (%s)', 'qhta-competitions' ), $mode[1] ) );
	}

	/**
	 * Every round with a flag key is in the cache and registered with wpff.
	 *
	 * @return array
	 */
	public static function test_round_flags() {
		if ( ! Qhta_Competitions_Flags::active() ) {
			return qhta_healthcheck_skip( __( 'wordpress-feature-flags is not active (see the functions check).', 'qhta-competitions' ) );
		}
		$ids = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( ! $ids ) {
			return qhta_healthcheck_skip( __( 'No published rounds yet.', 'qhta-competitions' ) );
		}
		$missing = array();
		foreach ( $ids as $id ) {
			$key  = Qhta_Competitions_Flags::key_for( $id );
			$mode = '' === $key ? null : Qhta_Competitions_Flags::mode( $key );
			if ( ! $mode || 'unregistered' === $mode[1] ) {
				$missing[] = '' === $key ? get_the_title( $id ) : $key;
			}
		}
		if ( $missing ) {
			/* translators: %s: comma-separated round flags or titles */
			return qhta_healthcheck_fail( sprintf( __( 'Not registered: %s. Re-save the round to rebuild its flag.', 'qhta-competitions' ), implode( ', ', $missing ) ) );
		}
		/* translators: %d: number of rounds */
		return qhta_healthcheck_pass( sprintf( _n( '%d published round, flag registered', '%d published rounds, all flags registered', count( $ids ), 'qhta-competitions' ), count( $ids ) ) );
	}

	/**
	 * The upload folder's .htaccess and index.php are in place (no repair here — read-only).
	 *
	 * @return array
	 */
	public static function test_upload_files() {
		$dir = Qhta_Competitions_Plugin::upload_base_dir();
		if ( ! is_dir( $dir ) ) {
			return qhta_healthcheck_fail( __( 'uploads/qhta-competitions/ does not exist. Open Competitions → Health to recreate it.', 'qhta-competitions' ) );
		}
		$htaccess = $dir . '.htaccess';
		if ( ! is_readable( $htaccess ) || false === strpos( (string) file_get_contents( $htaccess ), 'Require all denied' ) ) {
			return qhta_healthcheck_fail( __( 'uploads/qhta-competitions/.htaccess is missing or no longer denies access. Open Competitions → Health to rewrite it.', 'qhta-competitions' ) );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			return qhta_healthcheck_fail( __( 'uploads/qhta-competitions/index.php is missing. Open Competitions → Health to recreate it.', 'qhta-competitions' ) );
		}
		return qhta_healthcheck_pass( __( '.htaccess (deny all) and index.php present', 'qhta-competitions' ) );
	}

	/**
	 * A request for the folder's index.php is refused. Read-only: nothing is written.
	 *
	 * @return array
	 */
	public static function test_upload_http() {
		$uploads = wp_upload_dir( null, false );
		$url     = trailingslashit( $uploads['baseurl'] ) . 'qhta-competitions/index.php';
		/** This filter is documented in admin/class-admin-tools.php */
		$fetch = (string) apply_filters( 'qc_health_probe_url', $url );
		$res   = wp_remote_get(
			$fetch,
			array(
				'timeout'     => 10,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error message */
			return qhta_healthcheck_fail( sprintf( __( 'Could not request the protected folder: %s', 'qhta-competitions' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( in_array( $code, array( 401, 403, 404 ), true ) ) {
			/* translators: %d: HTTP status */
			return qhta_healthcheck_pass( sprintf( __( 'Direct request refused with HTTP %d', 'qhta-competitions' ), $code ) );
		}
		/* translators: %d: HTTP status */
		return qhta_healthcheck_fail( sprintf( __( 'Direct request returned HTTP %d — the web server is ignoring the folder\'s .htaccess, so stored entries can be downloaded', 'qhta-competitions' ), $code ) );
	}

	/**
	 * qc_cron is scheduled. Lateness is the heartbeat canary's job (a warning),
	 * matching Competitions → Health.
	 *
	 * @return array
	 */
	public static function test_cron_scheduled() {
		$next = wp_next_scheduled( Qhta_Competitions_Confirm::CRON );
		if ( ! $next ) {
			return qhta_healthcheck_fail( __( 'qc_cron is not scheduled. Deactivate and reactivate QHTA Competitions to reschedule it.', 'qhta-competitions' ) );
		}
		if ( $next < time() ) {
			/* translators: %s: human time */
			return qhta_healthcheck_pass( sprintf( __( 'Scheduled; due %s ago (see "Hourly task has run recently")', 'qhta-competitions' ), human_time_diff( $next ) ) );
		}
		/* translators: %s: human time */
		return qhta_healthcheck_pass( sprintf( __( 'Next run in %s', 'qhta-competitions' ), human_time_diff( time(), $next ) ) );
	}

	/**
	 * qc_cron has stamped its heartbeat recently.
	 *
	 * @return array
	 */
	public static function test_heartbeat() {
		$last = (int) get_option( 'qc_cron_last', 0 );
		$cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' ' . __( '(DISABLE_WP_CRON is set, so a server cron job must call wp-cron.php.)', 'qhta-competitions' ) : '';
		if ( ! $last ) {
			return qhta_healthcheck_fail( __( 'qc_cron has never run.', 'qhta-competitions' ) . $cron );
		}
		$age = time() - $last;
		if ( $age > self::HEARTBEAT_MAX_AGE ) {
			/* translators: %s: human time */
			return qhta_healthcheck_fail( sprintf( __( 'qc_cron last ran %s ago.', 'qhta-competitions' ), human_time_diff( $last ) ) . $cron );
		}
		/* translators: %s: human time */
		return qhta_healthcheck_pass( sprintf( __( 'Last ran %s ago', 'qhta-competitions' ), human_time_diff( $last ) ) );
	}

	/**
	 * Scores table present with the columns the code writes, at the expected schema version.
	 *
	 * @return array
	 */
	public static function test_scores_table() {
		$table = qhta_healthcheck_assert_table( 'qc_scores', array( 'submission_id', 'judge_user_id', 'criterion_key', 'score', 'comment', 'updated_at' ) );
		if ( ! $table['ok'] ) {
			return $table;
		}
		$version = (string) get_option( Qhta_Competitions_Plugin::DB_VERSION_OPTION, '' );
		if ( Qhta_Competitions_Plugin::DB_VERSION !== $version ) {
			/* translators: 1: installed version, 2: expected version */
			return qhta_healthcheck_fail( sprintf( __( 'Schema version is "%1$s", expected "%2$s" — the upgrade has not run', 'qhta-competitions' ), $version, Qhta_Competitions_Plugin::DB_VERSION ) );
		}
		/* translators: 1: table detail, 2: schema version */
		return qhta_healthcheck_pass( sprintf( __( '%1$s, schema v%2$s', 'qhta-competitions' ), $table['detail'], $version ) );
	}

	/**
	 * Queued outcome emails have a batch scheduled.
	 *
	 * @return array
	 */
	public static function test_outcome_queue() {
		$waiting = count( Qhta_Competitions_Outcomes::queue() );
		if ( ! $waiting ) {
			return qhta_healthcheck_pass( __( 'Queue empty', 'qhta-competitions' ) );
		}
		if ( ! wp_next_scheduled( Qhta_Competitions_Outcomes::BATCH_HOOK ) ) {
			/* translators: %d: count */
			return qhta_healthcheck_fail( sprintf( _n( '%d outcome email waiting with no batch scheduled', '%d outcome emails waiting with no batch scheduled', $waiting, 'qhta-competitions' ), $waiting ) );
		}
		/* translators: %d: count */
		return qhta_healthcheck_pass( sprintf( _n( '%d waiting, next batch scheduled', '%d waiting, next batch scheduled', $waiting, 'qhta-competitions' ), $waiting ) );
	}

	/**
	 * The judge-only wp-admin filters are attached.
	 *
	 * @return array
	 */
	public static function test_judge_filters() {
		$callback = array( 'Qhta_Competitions_Judging', 'allow_judge_admin' );
		$missing  = array();
		foreach ( array( 'woocommerce_prevent_admin_access', 'pmpro_block_dashboard', 'woocommerce_disable_admin_bar' ) as $hook ) {
			if ( false === has_filter( $hook, $callback ) ) {
				$missing[] = $hook;
			}
		}
		if ( $missing ) {
			/* translators: %s: comma-separated hook names */
			return qhta_healthcheck_fail( sprintf( __( 'Not attached: %s', 'qhta-competitions' ), implode( ', ', $missing ) ) );
		}
		return qhta_healthcheck_pass( __( 'All 3 filters attached', 'qhta-competitions' ) );
	}

	/**
	 * WPFF_PREVIEW_TOKEN is set and long enough. The value itself is never shown.
	 *
	 * @return array
	 */
	public static function test_preview_token() {
		$defined = qhta_healthcheck_assert_constant( 'WPFF_PREVIEW_TOKEN' );
		if ( ! $defined['ok'] ) {
			return $defined;
		}
		if ( strlen( (string) constant( 'WPFF_PREVIEW_TOKEN' ) ) < 12 ) {
			return qhta_healthcheck_fail( __( 'WPFF_PREVIEW_TOKEN is shorter than 12 characters; wpff ignores it', 'qhta-competitions' ) );
		}
		return $defined;
	}

	/**
	 * Site time zone is Australia/Brisbane.
	 *
	 * @return array
	 */
	public static function test_timezone() {
		$tz = wp_timezone_string();
		if ( 'Australia/Brisbane' !== $tz ) {
			/* translators: %s: time zone */
			return qhta_healthcheck_fail( sprintf( __( 'Site time zone is %s — set Settings → General → Timezone to Brisbane', 'qhta-competitions' ), $tz ) );
		}
		return qhta_healthcheck_pass( $tz );
	}

	/**
	 * PMPro's membership function, when PMPro is active. Optional: skipped without PMPro.
	 *
	 * @return array
	 */
	public static function test_pmpro() {
		if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
			return qhta_healthcheck_pass( 'pmpro_hasMembershipLevel()' );
		}
		if ( defined( 'PMPRO_VERSION' ) ) {
			/* translators: %s: PMPro version */
			return qhta_healthcheck_fail( sprintf( __( 'PMPro %s is active but pmpro_hasMembershipLevel() is missing — every applicant shows "?"', 'qhta-competitions' ), PMPRO_VERSION ) );
		}
		return qhta_healthcheck_skip( __( 'PMPro not active — membership is checked by hand.', 'qhta-competitions' ) );
	}
}
