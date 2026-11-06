<?php
/**
 * `qhta_submission` CPT, its custom statuses and the transition log (spec §3.2).
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submissions.
 */
final class Qhta_Competitions_Submission_CPT {

	const POST_TYPE = 'qhta_submission';

	/** Transition log meta. */
	const LOG_META = '_qc_log';

	/** Reference code meta, e.g. QC-2027-0042 or TEST-QC-2027-0003. */
	const REF_META = '_qc_ref';

	/** Test-entry marker (preview submissions, §5.11). */
	const TEST_META = '_qc_is_test';

	/** Program meta: real and test reference sequences. */
	const SEQ_META      = '_qc_ref_seq';
	const TEST_SEQ_META = '_qc_ref_seq_test';

	/** @var string|null Note to attach to the next logged transition. */
	private static $pending_note = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'log_transition' ), 10, 3 );
	}

	/**
	 * Statuses in lifecycle order => label.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'qc_pending_confirm' => __( 'Awaiting confirmation', 'qhta-competitions' ),
			'qc_submitted'       => __( 'Submitted', 'qhta-competitions' ),
			'qc_in_review'       => __( 'In review', 'qhta-competitions' ),
			'qc_shortlisted'     => __( 'Shortlisted', 'qhta-competitions' ),
			'qc_awarded'         => __( 'Awarded', 'qhta-competitions' ),
			'qc_commended'       => __( 'Commended', 'qhta-competitions' ),
			'qc_unsuccessful'    => __( 'Unsuccessful', 'qhta-competitions' ),
			'qc_ineligible'      => __( 'Ineligible', 'qhta-competitions' ),
			'qc_withdrawn'       => __( 'Withdrawn', 'qhta-competitions' ),
			'qc_forfeited'       => __( 'Forfeited', 'qhta-competitions' ),
		);
	}

	/**
	 * Allowed transitions (spec §6 state diagram). Admins may still override
	 * via "manual" transitions, which are logged with a note.
	 *
	 * @return array<string,string[]>
	 */
	public static function transitions() {
		return array(
			'qc_pending_confirm' => array( 'qc_submitted', 'qc_withdrawn', 'qc_ineligible' ),
			'qc_submitted'       => array( 'qc_ineligible', 'qc_in_review', 'qc_withdrawn' ),
			'qc_in_review'       => array( 'qc_shortlisted', 'qc_unsuccessful', 'qc_withdrawn' ),
			'qc_shortlisted'     => array( 'qc_awarded', 'qc_commended', 'qc_unsuccessful' ),
			'qc_awarded'         => array( 'qc_forfeited' ),
			'qc_commended'       => array(),
			'qc_unsuccessful'    => array(),
			'qc_ineligible'      => array(),
			'qc_withdrawn'       => array(),
			'qc_forfeited'       => array(),
		);
	}

	/**
	 * Register CPT and statuses. Non-public, excluded from search, no REST.
	 */
	public static function register() {
		$cap = Qhta_Competitions_Plugin::CAP_MANAGE;
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Submissions', 'qhta-competitions' ),
					'singular_name' => __( 'Submission', 'qhta-competitions' ),
					'all_items'     => __( 'Submissions', 'qhta-competitions' ),
					'edit_item'     => __( 'Submission', 'qhta-competitions' ),
					'search_items'  => __( 'Search submissions', 'qhta-competitions' ),
					'not_found'     => __( 'No submissions.', 'qhta-competitions' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Qhta_Competitions_Program_CPT::POST_TYPE,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => false,
				'capabilities'        => array_merge(
					Qhta_Competitions_Program_CPT::caps( $cap ),
					array( 'create_posts' => 'do_not_allow' ) // Submissions only come from the form.
				),
			)
		);

		foreach ( self::statuses() as $status => $label ) {
			register_post_status(
				$status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => false,
					'protected'                 => true,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => array(
						'singular' => $label . ' <span class="count">(%s)</span>',
						'plural'   => $label . ' <span class="count">(%s)</span>',
						'context'  => null,
						'domain'   => null,
					),
				)
			);
		}
	}

	/**
	 * Whether a status belongs to this plugin.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_status( $status ) {
		return array_key_exists( $status, self::statuses() );
	}

	/**
	 * Change a submission's status. Logged by log_transition().
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $to            Target status.
	 * @param string $note          Log note.
	 * @param bool   $force         Allow a transition outside the state diagram (admin override).
	 * @return true|WP_Error
	 */
	public static function transition( $submission_id, $to, $note = '', $force = false ) {
		$post = get_post( $submission_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'qc_not_submission', __( 'Not a submission.', 'qhta-competitions' ) );
		}
		if ( ! self::is_status( $to ) ) {
			return new WP_Error( 'qc_bad_status', __( 'Unknown status.', 'qhta-competitions' ) );
		}
		$from    = $post->post_status;
		$allowed = self::transitions();
		if ( ! $force && ( ! isset( $allowed[ $from ] ) || ! in_array( $to, $allowed[ $from ], true ) ) ) {
			/* translators: 1: from status, 2: to status */
			return new WP_Error( 'qc_bad_transition', sprintf( __( 'Cannot move a submission from %1$s to %2$s.', 'qhta-competitions' ), $from, $to ) );
		}
		if ( $force && '' === trim( $note ) ) {
			return new WP_Error( 'qc_note_required', __( 'A note is required for a manual status override.', 'qhta-competitions' ) );
		}

		self::$pending_note = $force ? '[override] ' . $note : $note;
		$result             = wp_update_post(
			array(
				'ID'          => $submission_id,
				'post_status' => $to,
			),
			true
		);
		self::$pending_note = null;
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Note for the next logged transition (e.g. the initial insert).
	 *
	 * @param string|null $note Note, or null to clear.
	 */
	public static function set_pending_note( $note ) {
		self::$pending_note = $note;
	}

	/**
	 * Log every status change of a submission, however it happened.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function log_transition( $new, $old, $post ) {
		if ( self::POST_TYPE !== $post->post_type || $new === $old ) {
			return;
		}
		self::append_log( $post->ID, $old, $new, null === self::$pending_note ? '' : self::$pending_note );
	}

	/**
	 * Append an entry to the submission log.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $from          From status ('' for non-status events).
	 * @param string $to            To status ('' for non-status events).
	 * @param string $note          Note.
	 */
	public static function append_log( $submission_id, $from, $to, $note = '' ) {
		$log   = self::get_log( $submission_id );
		$log[] = array(
			'ts'      => gmdate( Qhta_Competitions_Util::UTC_FORMAT ),
			'user_id' => get_current_user_id(),
			'from'    => (string) $from,
			'to'      => (string) $to,
			'note'    => (string) $note,
		);
		Qhta_Competitions_Util::set_json_meta( $submission_id, self::LOG_META, $log );
	}

	/**
	 * Next reference code for a program: QC-{year}-{0001}, per-program sequence.
	 * Tests use their own sequence so real refs have no gaps.
	 *
	 * @param int  $program_id Program ID.
	 * @param bool $is_test    Test entry.
	 * @return string
	 */
	public static function next_ref( $program_id, $is_test ) {
		global $wpdb;
		$key = $is_test ? self::TEST_SEQ_META : self::SEQ_META;
		if ( ! metadata_exists( 'post', $program_id, $key ) ) {
			add_post_meta( $program_id, $key, '0', true );
		}
		// Atomic increment: LAST_INSERT_ID(expr) hands the new value back on this connection.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = LAST_INSERT_ID(CAST(meta_value AS UNSIGNED) + 1) WHERE post_id = %d AND meta_key = %s", $program_id, $key ) );
		$seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		wp_cache_delete( $program_id, 'post_meta' );

		$closes = Qhta_Competitions_Util::utc_to_timestamp( (string) get_post_meta( $program_id, '_qc_closes_at', true ) );
		$year   = $closes ? wp_date( 'Y', $closes ) : wp_date( 'Y' );
		return ( $is_test ? 'TEST-' : '' ) . sprintf( 'QC-%s-%04d', $year, $seq );
	}

	/**
	 * Whether a submission is a preview/test entry.
	 *
	 * @param int $submission_id Submission ID.
	 * @return bool
	 */
	public static function is_test( $submission_id ) {
		return '1' === (string) get_post_meta( $submission_id, self::TEST_META, true );
	}

	/**
	 * meta_query clause excluding test entries. Every real-entry query (entry
	 * limits, judging, exports, outcome emails) must include it.
	 *
	 * @return array
	 */
	public static function real_entries_clause() {
		return array(
			'key'     => self::TEST_META,
			'compare' => 'NOT EXISTS',
		);
	}

	/**
	 * A submitted field value.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $key           Field key.
	 * @return mixed String, or array for address fields.
	 */
	public static function field( $submission_id, $key ) {
		$raw = get_post_meta( $submission_id, '_qc_f_' . $key, true );
		if ( is_string( $raw ) && '' !== $raw && '{' === $raw[0] ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && array_key_exists( 'line1', $decoded ) ) {
				return $decoded;
			}
		}
		return $raw;
	}

	/**
	 * Address array → one line.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function to_text( $value ) {
		if ( is_array( $value ) ) {
			$parts = array_filter( array_map( 'trim', array_map( 'strval', array( $value['line1'] ?? '', $value['line2'] ?? '', trim( ( $value['suburb'] ?? '' ) . ' ' . ( $value['state'] ?? '' ) . ' ' . ( $value['postcode'] ?? '' ) ) ) ) ), 'strlen' );
			return implode( ', ', $parts );
		}
		return (string) $value;
	}

	/**
	 * The submission log.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array
	 */
	public static function get_log( $submission_id ) {
		$log = Qhta_Competitions_Util::get_json_meta( $submission_id, self::LOG_META, array() );
		return is_array( $log ) ? $log : array();
	}
}
