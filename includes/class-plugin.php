<?php
/**
 * Loader and activation: roles/caps, scores table, protected upload dir.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
final class Qhta_Competitions_Plugin {

	/** Scores table schema version. Bump when the CREATE TABLE below changes. */
	const DB_VERSION = '1';

	/** Option holding the installed scores-table schema version. */
	const DB_VERSION_OPTION = 'qc_db_version';

	/** Admin capability. */
	const CAP_MANAGE = 'qc_manage';

	/** Judge capability. */
	const CAP_JUDGE = 'qc_judge';

	/** Secondary role given to judges (never replaces their existing role). */
	const ROLE_JUDGE = 'qhta_judge';

	/** @var self|null */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load classes and hook everything up.
	 */
	private function __construct() {
		require_once QC_DIR . 'includes/class-util.php';
		require_once QC_DIR . 'includes/class-flags.php';
		require_once QC_DIR . 'includes/class-program-cpt.php';
		require_once QC_DIR . 'includes/class-submission-cpt.php';
		require_once QC_DIR . 'includes/class-presets.php';
		require_once QC_DIR . 'includes/class-wordcount.php';
		require_once QC_DIR . 'includes/class-files.php';
		require_once QC_DIR . 'includes/class-mailer.php';
		require_once QC_DIR . 'includes/class-form-renderer.php';
		require_once QC_DIR . 'includes/class-form-handler.php';
		require_once QC_DIR . 'includes/class-eligibility.php';
		require_once QC_DIR . 'includes/class-confirm.php';
		require_once QC_DIR . 'includes/class-judging.php';
		require_once QC_DIR . 'includes/class-outcomes.php';
		require_once QC_DIR . 'includes/class-export.php';
		require_once QC_DIR . 'includes/class-retention.php';

		Qhta_Competitions_Flags::init();
		Qhta_Competitions_Program_CPT::init();
		Qhta_Competitions_Submission_CPT::init();
		Qhta_Competitions_Files::init();
		Qhta_Competitions_Form_Renderer::init();
		Qhta_Competitions_Form_Handler::init();
		Qhta_Competitions_Eligibility::init();
		Qhta_Competitions_Confirm::init();
		Qhta_Competitions_Judging::init();
		Qhta_Competitions_Outcomes::init();
		Qhta_Competitions_Export::init();
		Qhta_Competitions_Retention::init();
		add_action( 'qc_cron', array( __CLASS__, 'cron_heartbeat' ), 1 );

		if ( is_admin() ) {
			require_once QC_DIR . 'admin/class-admin-programs.php';
			Qhta_Competitions_Admin_Programs::init();
			require_once QC_DIR . 'admin/class-admin-submissions.php';
			Qhta_Competitions_Admin_Submissions::init();
			require_once QC_DIR . 'admin/class-admin-judging.php';
			Qhta_Competitions_Admin_Judging::init();
			require_once QC_DIR . 'admin/class-admin-tools.php';
			Qhta_Competitions_Admin_Tools::init();
		}

		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Activation: caps, judge role, scores table, protected upload dir.
	 */
	public static function activate() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_MANAGE );
		}
		if ( ! get_role( self::ROLE_JUDGE ) ) {
			add_role(
				self::ROLE_JUDGE,
				__( 'QHTA Judge', 'qhta-competitions' ),
				array(
					'read'          => true,
					self::CAP_JUDGE => true,
				)
			);
		}

		self::install_tables();
		self::protect_upload_dir();

		// Registering CPTs before flushing so their rewrite rules (none public) don't linger.
		require_once QC_DIR . 'includes/class-util.php';
		require_once QC_DIR . 'includes/class-program-cpt.php';
		require_once QC_DIR . 'includes/class-submission-cpt.php';
		Qhta_Competitions_Program_CPT::register();
		Qhta_Competitions_Submission_CPT::register();
	}

	/**
	 * Deactivation. Data, roles and caps stay; uninstall.php handles opt-in removal.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'qc_cron' );
		wp_clear_scheduled_hook( 'qc_outcome_batch' );
	}

	/**
	 * Record that the hourly task ran (shown on the Health page).
	 */
	public static function cron_heartbeat() {
		Qhta_Competitions_Util::set_option( 'qc_cron_last', time() );
	}

	/**
	 * Re-run table install when the schema version moves (covers updates without reactivation).
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install_tables();
		}
	}

	/**
	 * Name of the scores table.
	 *
	 * @return string
	 */
	public static function scores_table() {
		global $wpdb;
		return $wpdb->prefix . 'qc_scores';
	}

	/**
	 * Create/upgrade the scores table via dbDelta.
	 */
	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::scores_table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta formatting rules: two spaces after PRIMARY KEY, one field per line.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  submission_id bigint(20) unsigned NOT NULL,
  judge_user_id bigint(20) unsigned NOT NULL,
  criterion_key varchar(64) NOT NULL,
  score decimal(6,2) DEFAULT NULL,
  comment text NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY submission_judge_criterion (submission_id,judge_user_id,criterion_key),
  KEY judge_user_id (judge_user_id)
) {$charset};";

		dbDelta( $sql );

		if ( false === get_option( self::DB_VERSION_OPTION, false ) ) {
			add_option( self::DB_VERSION_OPTION, self::DB_VERSION, '', 'no' );
		} else {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Base directory for protected files.
	 *
	 * @return string Absolute path, trailing slash.
	 */
	public static function upload_base_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'qhta-competitions/';
	}

	/**
	 * Create the protected upload directory with deny-all .htaccess and index.php.
	 * Re-run safely; files are only (re)written when missing.
	 *
	 * @param string|null $dir Directory to protect (defaults to the base dir).
	 * @return bool Whether the directory exists and is protected.
	 */
	public static function protect_upload_dir( $dir = null ) {
		$dir = $dir ? trailingslashit( $dir ) : self::upload_base_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Apache 2.4 / LiteSpeed, with a 2.2 fallback.
			$rules = "# qhta-competitions: protected uploads. Never serve directly.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules );
		}
		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return file_exists( $htaccess ) && file_exists( $index );
	}
}
