<?php
/**
 * Eligibility rules (spec §5.5): `block` rules stop the form; `flag` rules
 * accept the submission and mark it for an admin.
 *
 * Rules (round config `_qc_eligibility`, each {rule, mode, field?, message?}):
 *   required_true     a checkbox must be ticked (e.g. "I live and work outside SEQ"). Default block.
 *   qhta_membership   PMPro check for logged-in users; institutional/unknown → manual. Default flag.
 *   entry_limits      the round's `_qc_entry_limits` (always enforced when set). Block.
 *   confirmer_differs referee email must differ from the submitter's. Default flag.
 *   year_level_category, word_count, late  — enforced by the form handler itself.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eligibility.
 */
final class Qhta_Competitions_Eligibility {

	/** Submission meta: membership check {status: yes|no|unknown, source, ts, by?}. */
	const MEMBER_META = '_qc_member_check';

	/** Default mode per rule. */
	const DEFAULT_MODES = array(
		'required_true'     => 'block',
		'qhta_membership'   => 'flag',
		'entry_limits'      => 'block',
		'confirmer_differs' => 'flag',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'qc_validate_submission', array( __CLASS__, 'validate' ), 10, 5 );
		add_filter( 'qc_submission_flags', array( __CLASS__, 'flags' ), 10, 4 );
	}

	/**
	 * Configured rules with modes resolved.
	 *
	 * @param array  $config Config.
	 * @param string $mode   Only rules in this mode.
	 * @return array[]
	 */
	private static function rules( array $config, $mode ) {
		$out = array();
		foreach ( (array) $config['eligibility'] as $r ) {
			if ( ! is_array( $r ) || empty( $r['rule'] ) || ! isset( self::DEFAULT_MODES[ $r['rule'] ] ) ) {
				continue;
			}
			$r['mode'] = isset( $r['mode'] ) ? $r['mode'] : self::DEFAULT_MODES[ $r['rule'] ];
			if ( $mode === $r['mode'] ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Normalise a name/school for matching: accents, case, punctuation and spacing ignored.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function normalise( $s ) {
		$s = strtolower( remove_accents( (string) $s ) );
		$s = preg_replace( "/[.'\x{2019}`]/u", '', $s ); // "S.S." = "SS", "St Mary's" = "St Marys".
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( preg_replace( '/\s+/', ' ', $s ) );
	}

	/* ---------------------------------------------------------------------
	 * Block rules
	 * ------------------------------------------------------------------ */

	/**
	 * qc_validate_submission filter.
	 *
	 * @param array $errors     Errors.
	 * @param array $values     Values.
	 * @param array $config     Config.
	 * @param int   $program_id Program ID.
	 * @param bool  $is_test    Test entry.
	 * @return array
	 */
	public static function validate( $errors, $values, $config, $program_id = 0, $is_test = false ) {
		foreach ( self::rules( $config, 'block' ) as $r ) {
			switch ( $r['rule'] ) {
				case 'required_true':
					$key = isset( $r['field'] ) ? $r['field'] : '';
					if ( '' !== $key && array_key_exists( $key, $values ) && '1' !== $values[ $key ] ) {
						$errors[ $key ] = ! empty( $r['message'] ) ? $r['message'] : __( 'You must tick this to be eligible.', 'qhta-competitions' );
					}
					break;
				case 'confirmer_differs':
					if ( self::same_confirmer_email( $values, $config ) ) {
						$errors[ $config['confirmer']['email_field'] ] = __( 'Your referee must be someone other than you — please give their own email address.', 'qhta-competitions' );
					}
					break;
			}
		}

		// Entry limits: always enforced when set (spec §3.1), whatever the rule list says.
		$limit_error = self::entry_limit_error( $values, $config, $program_id, $is_test );
		if ( '' !== $limit_error ) {
			$errors['_form']   = isset( $errors['_form'] ) ? (array) $errors['_form'] : array();
			$errors['_form'][] = $limit_error;
		}
		return $errors;
	}

	/**
	 * Whether the confirmer email equals the submitter's.
	 *
	 * @param array $values Values.
	 * @param array $config Config.
	 * @return bool
	 */
	private static function same_confirmer_email( array $values, array $config ) {
		if ( ! Qhta_Competitions_Program_CPT::has_confirmer( $config ) ) {
			return false;
		}
		$sub  = Qhta_Competitions_Program_CPT::submitter_email_key( $config );
		$conf = $config['confirmer']['email_field'];
		return '' !== $sub && ! empty( $values[ $sub ] ) && ! empty( $values[ $conf ] ) && 0 === strcasecmp( trim( $values[ $sub ] ), trim( $values[ $conf ] ) );
	}

	/**
	 * Entry-limit message, or '' when within limits. Real entries count real
	 * entries; test entries count test entries (so limits can be tried in preview).
	 * Withdrawn entries never count.
	 *
	 * @param array $values     Values.
	 * @param array $config     Config.
	 * @param int   $program_id Program ID.
	 * @param bool  $is_test    Test entry.
	 * @return string
	 */
	public static function entry_limit_error( array $values, array $config, $program_id, $is_test ) {
		$limits = $config['entry_limits'];
		$name_f = Qhta_Competitions_Program_CPT::field_by_role( $config, 'entrant_name' );
		$sch_f  = Qhta_Competitions_Program_CPT::field_by_role( $config, 'school' );
		$name   = $name_f && isset( $values[ $name_f['key'] ] ) ? self::normalise( $values[ $name_f['key'] ] ) : '';
		$school = $sch_f && isset( $values[ $sch_f['key'] ] ) ? self::normalise( $values[ $sch_f['key'] ] ) : '';
		$cat    = isset( $values['_category'] ) ? (string) $values['_category'] : '';
		$cat_l  = '';
		$c      = Qhta_Competitions_Program_CPT::category( $config, $cat );
		if ( $c ) {
			$cat_l = $c['label'];
		}
		$single = count( $config['categories'] ) <= 1;

		$checks = array(
			'per_entrant_per_category' => array( 'entrant' => $name, 'school' => $school, 'category' => $cat ),
			'per_school_per_category'  => array( 'school' => $school, 'category' => $cat ),
			'per_entrant_total'        => array( 'entrant' => $name, 'school' => $school ),
		);
		foreach ( $checks as $limit_key => $match ) {
			$limit = isset( $limits[ $limit_key ] ) ? $limits[ $limit_key ] : null;
			if ( null === $limit || in_array( '', $match, true ) ) {
				continue; // Unlimited, or the form lacks the field to match on.
			}
			$count = self::count_existing( $program_id, $match, $is_test );
			if ( $count < (int) $limit ) {
				continue;
			}
			switch ( $limit_key ) {
				case 'per_entrant_per_category':
					return $single
						/* translators: %d: limit */
						? sprintf( _n( 'We already have an entry from this person for this round. The limit is %d.', 'We already have the maximum of %d entries from this person for this round.', (int) $limit, 'qhta-competitions' ), (int) $limit )
						/* translators: 1: category, 2: limit */
						: sprintf( _n( 'This student already has an entry in %1$s. The limit is %2$d per student per category.', 'This student already has %2$d entries in %1$s, the most allowed per category.', (int) $limit, 'qhta-competitions' ), $cat_l, (int) $limit );
				case 'per_school_per_category':
					/* translators: 1: category, 2: limit */
					return sprintf( _n( 'Your school already has an entry in %1$s. The limit is %2$d per school per category.', 'Your school already has %2$d entries in %1$s, the most allowed per school per category.', (int) $limit, 'qhta-competitions' ), $cat_l, (int) $limit );
				default:
					return 'bursary' === $config['type']
						? __( 'We have already received an application from you for this round.', 'qhta-competitions' )
						/* translators: %d: limit */
						: sprintf( _n( 'This student already has an entry in this competition. The limit is %d per student.', 'This student already has %d entries in this competition, the most allowed.', (int) $limit, 'qhta-competitions' ), (int) $limit );
			}
		}
		return '';
	}

	/**
	 * Count matching, non-withdrawn submissions in a round.
	 *
	 * @param int   $program_id Program ID.
	 * @param array $match      entrant/school/category normalised values.
	 * @param bool  $is_test    Count test entries (true) or real ones (false).
	 * @return int
	 */
	private static function count_existing( $program_id, array $match, $is_test ) {
		$meta = array(
			$is_test
				? array(
					'key'   => Qhta_Competitions_Submission_CPT::TEST_META,
					'value' => '1',
				)
				: Qhta_Competitions_Submission_CPT::real_entries_clause(),
		);
		$keys = array(
			'entrant'  => '_qc_norm_entrant',
			'school'   => '_qc_norm_school',
			'category' => '_qc_category',
		);
		foreach ( $match as $k => $v ) {
			$meta[] = array(
				'key'   => $keys[ $k ],
				'value' => $v,
			);
		}
		$statuses = array_diff( array_keys( Qhta_Competitions_Submission_CPT::statuses() ), array( 'qc_withdrawn' ) );
		$q        = new WP_Query(
			array(
				'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
				'post_status'    => array_values( $statuses ),
				'post_parent'    => (int) $program_id,
				'meta_query'     => $meta,
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		return (int) $q->found_posts;
	}

	/* ---------------------------------------------------------------------
	 * Flag rules
	 * ------------------------------------------------------------------ */

	/**
	 * qc_submission_flags filter (runs once, at creation).
	 *
	 * @param array $flags  Flags.
	 * @param int   $id     Submission ID.
	 * @param array $values Values.
	 * @param array $config Config.
	 * @return array
	 */
	public static function flags( $flags, $id, $values, $config ) {
		foreach ( self::rules( $config, 'flag' ) as $r ) {
			switch ( $r['rule'] ) {
				case 'required_true':
					$key = isset( $r['field'] ) ? $r['field'] : '';
					if ( '' !== $key && array_key_exists( $key, $values ) && '1' !== $values[ $key ] ) {
						$flags[] = array(
							'code'    => 'not_ticked_' . $key,
							'message' => ! empty( $r['message'] ) ? $r['message'] : sprintf( /* translators: %s: field */ __( '"%s" was not ticked.', 'qhta-competitions' ), $key ),
						);
					}
					break;

				case 'qhta_membership':
					$check = self::membership_check( $values, isset( $r['field'] ) ? $r['field'] : '' );
					Qhta_Competitions_Util::set_json_meta( $id, self::MEMBER_META, $check );
					if ( 'yes' !== $check['status'] ) {
						$flags[] = array(
							'code'    => 'membership_' . $check['status'],
							'message' => $check['note'],
						);
					}
					break;

				case 'confirmer_differs':
					if ( self::same_confirmer_email( $values, $config ) ) {
						$flags[] = array(
							'code'    => 'confirmer_same_email',
							'message' => __( 'Referee email is the same as the applicant\'s.', 'qhta-competitions' ),
						);
					}
					break;
			}
		}
		return $flags;
	}

	/**
	 * Membership check at submit time.
	 *
	 * @param array  $values Values.
	 * @param string $field  Membership-type field key (individual|institutional).
	 * @return array{status:string, source:string, note:string, ts:string}
	 */
	public static function membership_check( array $values, $field ) {
		$ts   = gmdate( Qhta_Competitions_Util::UTC_FORMAT );
		$type = '' !== $field && isset( $values[ $field ] ) ? (string) $values[ $field ] : '';

		if ( 'institutional' === $type ) {
			return array( 'status' => 'unknown', 'source' => 'institutional', 'note' => __( 'Institutional membership claimed — check manually.', 'qhta-competitions' ), 'ts' => $ts );
		}
		if ( ! is_user_logged_in() ) {
			return array( 'status' => 'unknown', 'source' => 'logged_out', 'note' => __( 'Applied without logging in — check membership manually.', 'qhta-competitions' ), 'ts' => $ts );
		}
		if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
			return array( 'status' => 'unknown', 'source' => 'no_pmpro', 'note' => __( 'PMPro not active — check membership manually.', 'qhta-competitions' ), 'ts' => $ts );
		}
		$user = get_current_user_id();
		if ( pmpro_hasMembershipLevel( null, $user ) ) {
			return array( 'status' => 'yes', 'source' => 'pmpro', 'note' => __( 'Active PMPro membership.', 'qhta-competitions' ), 'ts' => $ts, 'user_id' => $user );
		}
		return array( 'status' => 'no', 'source' => 'pmpro', 'note' => __( 'Logged in, but no active PMPro membership.', 'qhta-competitions' ), 'ts' => $ts, 'user_id' => $user );
	}

	/**
	 * Member ✓/✗/? for a submission ('' when not checked for this round).
	 *
	 * @param int $id Submission ID.
	 * @return string
	 */
	public static function member_symbol( $id ) {
		$check = Qhta_Competitions_Util::get_json_meta( $id, self::MEMBER_META, null );
		if ( ! is_array( $check ) ) {
			return '';
		}
		$map = array(
			'yes'     => '✓',
			'no'      => '✗',
			'unknown' => '?',
		);
		return isset( $map[ $check['status'] ] ) ? $map[ $check['status'] ] : '?';
	}
}
