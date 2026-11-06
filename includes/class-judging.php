<?php
/**
 * Judging (spec §5.7): judge role, panel assignment, access rules, scores and aggregation.
 *
 * Scoring formula (used by the results view and the hand-check spreadsheet):
 *   judge %  = Σ(score_c / max_c × weight_c) / Σ(weight_c) × 100   — only when the judge scored every criterion
 *   mean     = average of complete judge %s
 *   spread   = highest judge % − lowest judge %   (warning when > the round's margin)
 *   rank     = by mean, highest first; equal means (to 2 dp) share a rank (1, 2, 2, 4)
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Judging core.
 */
final class Qhta_Competitions_Judging {

	/** Program meta: {category slug: [judge user IDs]}. */
	const PANEL_META = '_qc_panel';

	/** User meta: the judge's school, for conflict-of-interest exclusion. */
	const SCHOOL_META = 'qc_judge_school';

	/** Statuses a judge can see and score. */
	const JUDGEABLE = array( 'qc_in_review', 'qc_shortlisted' );

	/** Statuses shown in results (judging done or underway). */
	const RESULT_STATUSES = array( 'qc_in_review', 'qc_shortlisted', 'qc_awarded', 'qc_commended', 'qc_unsuccessful', 'qc_forfeited' );

	/** Pseudo-criterion holding the judge's overall comment. */
	const OVERALL = '_overall';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'qc_judge_can_access_submission', array( __CLASS__, 'filter_can_access' ), 10, 3 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		// Judges are usually subscribers/customers, whom WooCommerce (and PMPro's
		// "block dashboard" option) keep out of wp-admin. Let judges through only.
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_judge_admin' ) );
		add_filter( 'pmpro_block_dashboard', array( __CLASS__, 'allow_judge_admin' ) );
		add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'allow_judge_admin' ) );
	}

	/**
	 * Filter: false (don't block) for judges, unchanged for everyone else.
	 *
	 * @param bool $block Whether to block.
	 * @return bool
	 */
	public static function allow_judge_admin( $block ) {
		return current_user_can( Qhta_Competitions_Plugin::CAP_JUDGE ) ? false : $block;
	}

	/* ---------------------------------------------------------------------
	 * Judges (secondary role — never replaces the user's own role)
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a user is a judge.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_judge( $user_id ) {
		$u = get_userdata( $user_id );
		return $u && in_array( Qhta_Competitions_Plugin::ROLE_JUDGE, (array) $u->roles, true );
	}

	/**
	 * Make a user a judge: add_role() keeps their existing role(s) and PMPro membership.
	 *
	 * @param int $user_id User ID.
	 */
	public static function add_judge( $user_id ) {
		$u = get_userdata( $user_id );
		if ( $u && ! self::is_judge( $user_id ) ) {
			$u->add_role( Qhta_Competitions_Plugin::ROLE_JUDGE );
		}
	}

	/**
	 * Remove the judge role (and the user from every panel). Other roles untouched.
	 *
	 * @param int $user_id User ID.
	 */
	public static function remove_judge( $user_id ) {
		$u = get_userdata( $user_id );
		if ( $u ) {
			$u->remove_role( Qhta_Competitions_Plugin::ROLE_JUDGE );
		}
		$programs = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::PANEL_META,
			)
		);
		foreach ( $programs as $pid ) {
			$panel = self::panel( $pid );
			foreach ( $panel as $slug => $ids ) {
				$panel[ $slug ] = array_values( array_diff( $ids, array( (int) $user_id ) ) );
			}
			self::save_panel( $pid, $panel );
		}
	}

	/**
	 * All judges.
	 *
	 * @return WP_User[]
	 */
	public static function judges() {
		return get_users(
			array(
				'role'    => Qhta_Competitions_Plugin::ROLE_JUDGE,
				'orderby' => 'display_name',
			)
		);
	}

	/**
	 * Judge's normalised school ('' when unset).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function judge_school( $user_id ) {
		return Qhta_Competitions_Eligibility::normalise( (string) get_user_meta( $user_id, self::SCHOOL_META, true ) );
	}

	/**
	 * Judges land on their dashboard after logging in (unless they were heading somewhere specific).
	 *
	 * @param string           $to        Redirect.
	 * @param string           $requested Requested redirect.
	 * @param WP_User|WP_Error $user      User.
	 * @return string
	 */
	public static function login_redirect( $to, $requested, $user ) {
		if ( ! $user instanceof WP_User || ! $user->has_cap( Qhta_Competitions_Plugin::CAP_JUDGE ) || $user->has_cap( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			return $to;
		}
		$generic = array( '', admin_url(), admin_url( '/' ), admin_url( 'index.php' ), admin_url( 'profile.php' ), home_url(), home_url( '/' ) );
		return in_array( $requested, $generic, true ) || in_array( $to, $generic, true ) ? admin_url( 'admin.php?page=qc-judging' ) : $to;
	}

	/* ---------------------------------------------------------------------
	 * Panel
	 * ------------------------------------------------------------------ */

	/**
	 * Panel for a program.
	 *
	 * @param int $program_id Program ID.
	 * @return array<string,int[]>
	 */
	public static function panel( $program_id ) {
		$panel = Qhta_Competitions_Util::get_json_meta( $program_id, self::PANEL_META, array() );
		$out   = array();
		foreach ( (array) $panel as $slug => $ids ) {
			$out[ (string) $slug ] = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		}
		return $out;
	}

	/**
	 * Store a panel (only real judges kept).
	 *
	 * @param int   $program_id Program ID.
	 * @param array $panel      {slug: [ids]}.
	 */
	public static function save_panel( $program_id, array $panel ) {
		$clean = array();
		foreach ( $panel as $slug => $ids ) {
			$clean[ sanitize_key( $slug ) ] = array_values( array_filter( array_unique( array_map( 'intval', (array) $ids ) ), array( __CLASS__, 'is_judge' ) ) );
		}
		Qhta_Competitions_Util::set_json_meta( $program_id, self::PANEL_META, $clean );
	}

	/**
	 * Categories a judge sits on, per program.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,string[]> {program ID: [slugs]}
	 */
	public static function assignments( $user_id ) {
		$out      = array();
		$programs = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::PANEL_META,
			)
		);
		foreach ( $programs as $pid ) {
			foreach ( self::panel( $pid ) as $slug => $ids ) {
				if ( in_array( (int) $user_id, $ids, true ) ) {
					$out[ $pid ][] = $slug;
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Access
	 * ------------------------------------------------------------------ */

	/**
	 * Conflict of interest: the judge's school is the submission's school.
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $user_id       Judge.
	 * @return bool
	 */
	public static function conflict( $submission_id, $user_id ) {
		$school = self::judge_school( $user_id );
		return '' !== $school && $school === (string) get_post_meta( $submission_id, '_qc_norm_school', true );
	}

	/**
	 * Whether a judge may see and score a submission: a real entry in a
	 * judgeable status, in a category they're assigned to, not from their school.
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $user_id       Judge.
	 * @return bool
	 */
	public static function judge_can_access( $submission_id, $user_id ) {
		$post = get_post( $submission_id );
		if ( ! $post || Qhta_Competitions_Submission_CPT::POST_TYPE !== $post->post_type || ! in_array( $post->post_status, self::JUDGEABLE, true ) ) {
			return false;
		}
		if ( Qhta_Competitions_Submission_CPT::is_test( $submission_id ) || ! user_can( $user_id, Qhta_Competitions_Plugin::CAP_JUDGE ) ) {
			return false;
		}
		$panel = self::panel( $post->post_parent );
		$cat   = (string) get_post_meta( $submission_id, '_qc_category', true );
		if ( empty( $panel[ $cat ] ) || ! in_array( (int) $user_id, $panel[ $cat ], true ) ) {
			return false;
		}
		return ! self::conflict( $submission_id, $user_id );
	}

	/**
	 * Files filter: judges may download what they may judge.
	 *
	 * @param bool $can           Current.
	 * @param int  $submission_id Submission ID.
	 * @param int  $user_id       User.
	 * @return bool
	 */
	public static function filter_can_access( $can, $submission_id, $user_id ) {
		return $can || self::judge_can_access( $submission_id, $user_id );
	}

	/**
	 * Submissions a judge can score in one program category.
	 *
	 * @param int    $program_id Program ID.
	 * @param string $category   Category slug.
	 * @param int    $user_id    Judge.
	 * @return int[]
	 */
	public static function queue( $program_id, $category, $user_id ) {
		$ids = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
				'post_status'    => self::JUDGEABLE,
				'post_parent'    => (int) $program_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					Qhta_Competitions_Submission_CPT::real_entries_clause(),
					array(
						'key'   => '_qc_category',
						'value' => $category,
					),
				),
			)
		);
		return array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $user_id ) {
					return ! Qhta_Competitions_Judging::conflict( $id, $user_id );
				}
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Scores
	 * ------------------------------------------------------------------ */

	/**
	 * A judge's scores for a submission: {criterion: {score, comment}}.
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $user_id       Judge.
	 * @return array
	 */
	public static function scores( $submission_id, $user_id ) {
		global $wpdb;
		$table = Qhta_Competitions_Plugin::scores_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT criterion_key, score, comment FROM {$table} WHERE submission_id = %d AND judge_user_id = %d", $submission_id, $user_id ), ARRAY_A );
		$out   = array();
		foreach ( $rows as $r ) {
			$out[ $r['criterion_key'] ] = array(
				'score'   => null === $r['score'] ? null : (float) $r['score'],
				'comment' => (string) $r['comment'],
			);
		}
		return $out;
	}

	/**
	 * Validate and save a judge's scores. Blank scores are allowed (saved as
	 * "not yet scored") so a judge can save part-way.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param int    $user_id       Judge.
	 * @param array  $scores        {criterion: raw score string}.
	 * @param array  $comments      {criterion: comment}.
	 * @param string $overall       Overall comment.
	 * @return true|WP_Error
	 */
	public static function save_scores( $submission_id, $user_id, array $scores, array $comments, $overall ) {
		global $wpdb;
		$config   = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $submission_id ) );
		$criteria = $config['rubric']['criteria'];
		$rows     = array();
		foreach ( $criteria as $c ) {
			$raw = isset( $scores[ $c['key'] ] ) ? trim( (string) $scores[ $c['key'] ] ) : '';
			if ( '' === $raw ) {
				$value = null;
			} elseif ( ! is_numeric( $raw ) || (float) $raw < 0 || (float) $raw > (float) $c['max'] ) {
				/* translators: 1: criterion, 2: max */
				return new WP_Error( 'qc_bad_score', sprintf( __( '%1$s must be a number from 0 to %2$s.', 'qhta-competitions' ), $c['label'], $c['max'] ) );
			} else {
				$value = round( (float) $raw, 2 );
			}
			$rows[ $c['key'] ] = array( $value, isset( $comments[ $c['key'] ] ) ? (string) $comments[ $c['key'] ] : '' );
		}
		$rows[ self::OVERALL ] = array( null, (string) $overall );

		$table = Qhta_Competitions_Plugin::scores_table();
		$now   = current_time( 'mysql', true );
		foreach ( $rows as $key => $r ) {
			// REPLACE on the unique (submission, judge, criterion) key.
			$wpdb->replace(
				$table,
				array(
					'submission_id' => (int) $submission_id,
					'judge_user_id' => (int) $user_id,
					'criterion_key' => $key,
					'score'         => $r[0],
					'comment'       => $r[1],
					'updated_at'    => $now,
				),
				array( '%d', '%d', '%s', '%f', '%s', '%s' ) // wpdb writes a null value as NULL.
			);
		}
		return true;
	}

	/**
	 * Judge % for one set of scores, or null when incomplete.
	 *
	 * @param array $criteria Rubric criteria.
	 * @param array $scores   {criterion: {score}}.
	 * @return float|null
	 */
	public static function judge_percent( array $criteria, array $scores ) {
		$num = 0.0;
		$den = 0.0;
		foreach ( $criteria as $c ) {
			if ( ! isset( $scores[ $c['key'] ] ) || null === $scores[ $c['key'] ]['score'] ) {
				return null;
			}
			$w    = (float) $c['weight'];
			$num += (float) $scores[ $c['key'] ]['score'] / (float) $c['max'] * $w;
			$den += $w;
		}
		return $den > 0 ? $num / $den * 100 : null;
	}

	/**
	 * Results for a category, ranked.
	 *
	 * @param int    $program_id Program ID.
	 * @param string $category   Category slug.
	 * @return array[] Rows: id, ref, status, judges {uid: {pct, complete, conflict}}, complete, assigned, mean, spread, warn, rank.
	 */
	public static function results( $program_id, $category ) {
		$config   = Qhta_Competitions_Program_CPT::get_config( $program_id );
		$criteria = $config['rubric']['criteria'];
		$margin   = (float) $config['judge_spread_margin'];
		$panel    = self::panel( $program_id );
		$judges   = isset( $panel[ $category ] ) ? $panel[ $category ] : array();

		$ids = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
				'post_status'    => self::RESULT_STATUSES,
				'post_parent'    => (int) $program_id,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					Qhta_Competitions_Submission_CPT::real_entries_clause(),
					array(
						'key'   => '_qc_category',
						'value' => $category,
					),
				),
			)
		);

		$rows = array();
		foreach ( $ids as $id ) {
			$per  = array();
			$pcts = array();
			foreach ( $judges as $uid ) {
				if ( self::conflict( $id, $uid ) ) {
					$per[ $uid ] = array( 'pct' => null, 'complete' => false, 'conflict' => true );
					continue;
				}
				$pct         = self::judge_percent( $criteria, self::scores( $id, $uid ) );
				$per[ $uid ] = array( 'pct' => $pct, 'complete' => null !== $pct, 'conflict' => false );
				if ( null !== $pct ) {
					$pcts[] = $pct;
				}
			}
			$mean   = $pcts ? array_sum( $pcts ) / count( $pcts ) : null;
			$spread = count( $pcts ) > 1 ? max( $pcts ) - min( $pcts ) : null;
			$rows[] = array(
				'id'       => $id,
				'ref'      => (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true ),
				'status'   => get_post_status( $id ),
				'judges'   => $per,
				'complete' => count( $pcts ),
				'assigned' => count( array_filter( $per, function ( $j ) { return ! $j['conflict']; } ) ),
				'mean'     => $mean,
				'spread'   => $spread,
				'warn'     => null !== $spread && $spread > $margin,
				'rank'     => null,
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				if ( null === $a['mean'] || null === $b['mean'] ) {
					return null === $a['mean'] ? ( null === $b['mean'] ? strcmp( $a['ref'], $b['ref'] ) : 1 ) : -1;
				}
				$cmp = round( $b['mean'], 2 ) <=> round( $a['mean'], 2 );
				return 0 !== $cmp ? $cmp : strcmp( $a['ref'], $b['ref'] );
			}
		);
		$rank = 0;
		$prev = null;
		foreach ( $rows as $i => $r ) {
			if ( null === $r['mean'] ) {
				continue;
			}
			$m = round( $r['mean'], 2 );
			if ( $m !== $prev ) {
				$rank = $i + 1;
				$prev = $m;
			}
			$rows[ $i ]['rank'] = $rank;
		}
		return $rows;
	}

	/**
	 * Judge progress in a category: [scored completely, total].
	 *
	 * @param int    $program_id Program ID.
	 * @param string $category   Category.
	 * @param int    $user_id    Judge.
	 * @return array{0:int,1:int}
	 */
	public static function progress( $program_id, $category, $user_id ) {
		$config   = Qhta_Competitions_Program_CPT::get_config( $program_id );
		$criteria = $config['rubric']['criteria'];
		$queue    = self::queue( $program_id, $category, $user_id );
		$done     = 0;
		foreach ( $queue as $id ) {
			if ( null !== self::judge_percent( $criteria, self::scores( $id, $user_id ) ) ) {
				++$done;
			}
		}
		return array( $done, count( $queue ) );
	}
}
