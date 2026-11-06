<?php
/**
 * Judging admin (spec §5.7): Judges page, panel box, judge dashboard + scoring, results view.
 *
 * Judges see only: reference codes (and, when the round isn't anonymised, the
 * entrant), the fields marked judge-visible (never PII while anonymised), the
 * inline piece or file, and their own scores. No lists, no exports.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Judging screens.
 */
final class Qhta_Competitions_Admin_Judging {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes_' . Qhta_Competitions_Program_CPT::POST_TYPE, array( __CLASS__, 'panel_box' ) );
		add_action( 'save_post_' . Qhta_Competitions_Program_CPT::POST_TYPE, array( __CLASS__, 'save_panel' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Qhta_Competitions_Submission_CPT::POST_TYPE, array( __CLASS__, 'scores_box' ) );

		add_action( 'admin_post_qc_judge_add', array( __CLASS__, 'handle_judge_add' ) );
		add_action( 'admin_post_qc_judge_remove', array( __CLASS__, 'handle_judge_remove' ) );
		add_action( 'admin_post_qc_judge_school', array( __CLASS__, 'handle_judge_school' ) );
		add_action( 'admin_post_qc_save_scores', array( __CLASS__, 'handle_save_scores' ) );
		add_action( 'admin_post_qc_results_status', array( __CLASS__, 'handle_results_status' ) );
	}

	/**
	 * Menus: Judges + Results for admins; Judging dashboard for judges.
	 */
	public static function menu() {
		$parent = 'edit.php?post_type=' . Qhta_Competitions_Program_CPT::POST_TYPE;
		add_submenu_page( $parent, __( 'Judges', 'qhta-competitions' ), __( 'Judges', 'qhta-competitions' ), Qhta_Competitions_Plugin::CAP_MANAGE, 'qc-judges', array( __CLASS__, 'render_judges' ) );
		add_submenu_page( $parent, __( 'Results', 'qhta-competitions' ), __( 'Results', 'qhta-competitions' ), Qhta_Competitions_Plugin::CAP_MANAGE, 'qc-results', array( __CLASS__, 'render_results' ) );
		$hook = add_menu_page( __( 'Judging', 'qhta-competitions' ), __( 'Judging', 'qhta-competitions' ), Qhta_Competitions_Plugin::CAP_JUDGE, 'qc-judging', array( __CLASS__, 'render_dashboard' ), 'dashicons-clipboard', 3 );
		add_action( 'load-' . $hook, array( __CLASS__, 'check_submission_access' ) );
	}

	/**
	 * Refuse a scoring screen before any output, so the response is a real 403.
	 */
	public static function check_submission_access() {
		$sid = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
		if ( $sid && ! Qhta_Competitions_Judging::judge_can_access( $sid, get_current_user_id() ) ) {
			wp_die( esc_html__( 'Sorry, this entry is not in your judging list.', 'qhta-competitions' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
	}

	/**
	 * CSS on our pages.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( $hook, 'qc-judg' ) || false !== strpos( $hook, 'qc-results' ) ) {
			wp_enqueue_style( 'qc-admin', QC_URL . 'assets/css/admin.css', array(), QC_VERSION );
		}
	}

	/**
	 * Redirect back with a message.
	 *
	 * @param string $url Base URL.
	 * @param string $msg Message.
	 * @param string $type success|error.
	 */
	private static function back( $url, $msg, $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'qc_msg'  => rawurlencode( $msg ),
					'qc_type' => $type,
				),
				$url
			)
		);
		exit;
	}

	/**
	 * Print a redirect message.
	 */
	private static function message() {
		if ( empty( $_GET['qc_msg'] ) ) {
			return;
		}
		$type = isset( $_GET['qc_type'] ) && 'error' === $_GET['qc_type'] ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['qc_msg'] ) ) ) . '</p></div>';
	}

	/**
	 * Guard: capability + nonce.
	 *
	 * @param string $cap    Capability.
	 * @param string $action Nonce action.
	 */
	private static function guard( $cap, $action ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'qhta-competitions' ), 403 );
		}
		check_admin_referer( $action );
	}

	/* ---------------------------------------------------------------------
	 * Judges page
	 * ------------------------------------------------------------------ */

	/**
	 * Judges list, add, remove, school.
	 */
	public static function render_judges() {
		echo '<div class="wrap qc-admin"><h1>' . esc_html__( 'Judges', 'qhta-competitions' ) . '</h1>';
		self::message();
		echo '<p>' . esc_html__( 'Judges use their existing website accounts. Adding someone here gives them the extra "QHTA Judge" role; their own role and membership are not changed. Removing them takes away only the judge role and their panel places.', 'qhta-competitions' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="qc-inline-form"><input type="hidden" name="action" value="qc_judge_add">';
		wp_nonce_field( 'qc_judge_add' );
		echo '<label for="qc_judge_user">' . esc_html__( 'Add judge (username or email)', 'qhta-competitions' ) . '</label> <input type="text" id="qc_judge_user" name="user" class="regular-text" required> ';
		submit_button( __( 'Add judge', 'qhta-competitions' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped qc-judges"><thead><tr><th>' . esc_html__( 'Judge', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Other roles', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'School (conflict of interest)', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Panels', 'qhta-competitions' ) . '</th><th></th></tr></thead><tbody>';
		$judges = Qhta_Competitions_Judging::judges();
		if ( ! $judges ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No judges yet.', 'qhta-competitions' ) . '</td></tr>';
		}
		foreach ( $judges as $u ) {
			$roles = array_diff( (array) $u->roles, array( Qhta_Competitions_Plugin::ROLE_JUDGE ) );
			$names = array();
			foreach ( $roles as $r ) {
				$names[] = wp_roles()->is_role( $r ) ? translate_user_role( wp_roles()->roles[ $r ]['name'] ) : $r;
			}
			$panels = array();
			foreach ( Qhta_Competitions_Judging::assignments( $u->ID ) as $pid => $slugs ) {
				$config = Qhta_Competitions_Program_CPT::get_config( $pid );
				$labels = array();
				foreach ( $slugs as $slug ) {
					$c        = Qhta_Competitions_Program_CPT::category( $config, $slug );
					$labels[] = $c ? $c['label'] : $slug;
				}
				$panels[] = get_the_title( $pid ) . ': ' . implode( ', ', $labels );
			}
			echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong><br><span class="description">' . esc_html( $u->user_login . ' · ' . $u->user_email ) . '</span></td>';
			echo '<td>' . esc_html( $names ? implode( ', ', $names ) : '—' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="qc_judge_school"><input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
			wp_nonce_field( 'qc_judge_school_' . $u->ID );
			echo '<input type="text" name="school" value="' . esc_attr( (string) get_user_meta( $u->ID, Qhta_Competitions_Judging::SCHOOL_META, true ) ) . '" aria-label="' . esc_attr__( 'School', 'qhta-competitions' ) . '"> ';
			submit_button( __( 'Save', 'qhta-competitions' ), 'small', 'submit', false );
			echo '</form></td>';
			echo '<td>' . ( $panels ? implode( '<br>', array_map( 'esc_html', $panels ) ) : '—' ) . '</td>';
			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="qc_judge_remove"><input type="hidden" name="user_id" value="' . (int) $u->ID . '">';
			wp_nonce_field( 'qc_judge_remove_' . $u->ID );
			submit_button( __( 'Remove judge role', 'qhta-competitions' ), 'delete small', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Entries from a judge\'s school are hidden from that judge and left out of their scoring. Then assign judges to categories in each round\'s "Judging panel" box.', 'qhta-competitions' ) . '</p></div>';
	}

	/**
	 * Judges page URL.
	 *
	 * @return string
	 */
	private static function judges_url() {
		return admin_url( 'edit.php?post_type=' . Qhta_Competitions_Program_CPT::POST_TYPE . '&page=qc-judges' );
	}

	/**
	 * Add a judge.
	 */
	public static function handle_judge_add() {
		self::guard( Qhta_Competitions_Plugin::CAP_MANAGE, 'qc_judge_add' );
		$who  = isset( $_POST['user'] ) ? sanitize_text_field( wp_unslash( $_POST['user'] ) ) : '';
		$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		if ( ! $user ) {
			/* translators: %s: input */
			self::back( self::judges_url(), sprintf( __( 'No account found for "%s". The judge needs a website account first.', 'qhta-competitions' ), $who ), 'error' );
		}
		Qhta_Competitions_Judging::add_judge( $user->ID );
		/* translators: %s: name */
		self::back( self::judges_url(), sprintf( __( '%s is now a judge.', 'qhta-competitions' ), $user->display_name ) );
	}

	/**
	 * Remove a judge.
	 */
	public static function handle_judge_remove() {
		$id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		self::guard( Qhta_Competitions_Plugin::CAP_MANAGE, 'qc_judge_remove_' . $id );
		Qhta_Competitions_Judging::remove_judge( $id );
		$u = get_userdata( $id );
		/* translators: %s: name */
		self::back( self::judges_url(), sprintf( __( '%s is no longer a judge. Their scores are kept.', 'qhta-competitions' ), $u ? $u->display_name : '#' . $id ) );
	}

	/**
	 * Save a judge's school.
	 */
	public static function handle_judge_school() {
		$id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		self::guard( Qhta_Competitions_Plugin::CAP_MANAGE, 'qc_judge_school_' . $id );
		update_user_meta( $id, Qhta_Competitions_Judging::SCHOOL_META, isset( $_POST['school'] ) ? sanitize_text_field( wp_unslash( $_POST['school'] ) ) : '' );
		self::back( self::judges_url(), __( 'School saved.', 'qhta-competitions' ) );
	}

	/* ---------------------------------------------------------------------
	 * Panel box on the program edit screen
	 * ------------------------------------------------------------------ */

	/**
	 * Register the panel box.
	 */
	public static function panel_box() {
		add_meta_box( 'qc_panel', __( 'Judging panel', 'qhta-competitions' ), array( __CLASS__, 'render_panel_box' ), Qhta_Competitions_Program_CPT::POST_TYPE, 'normal' );
	}

	/**
	 * Category × judge checkboxes.
	 *
	 * @param WP_Post $post Program.
	 */
	public static function render_panel_box( $post ) {
		$config = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		$panel  = Qhta_Competitions_Judging::panel( $post->ID );
		$judges = Qhta_Competitions_Judging::judges();
		echo '<input type="hidden" name="qc_panel_present" value="1">';
		if ( ! $judges ) {
			echo '<p>' . sprintf( esc_html__( 'No judges yet — add them on the %s page.', 'qhta-competitions' ), '<a href="' . esc_url( self::judges_url() ) . '">' . esc_html__( 'Judges', 'qhta-competitions' ) . '</a>' ) . '</p>';
			return;
		}
		if ( ! $config['categories'] ) {
			echo '<p>' . esc_html__( 'Add categories first.', 'qhta-competitions' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped qc-panel"><thead><tr><th>' . esc_html__( 'Category', 'qhta-competitions' ) . '</th>';
		foreach ( $judges as $u ) {
			echo '<th>' . esc_html( $u->display_name ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $config['categories'] as $c ) {
			echo '<tr><td>' . esc_html( $c['label'] ) . '</td>';
			$on = isset( $panel[ $c['slug'] ] ) ? $panel[ $c['slug'] ] : array();
			foreach ( $judges as $u ) {
				printf(
					'<td><input type="checkbox" name="qc_panel[%1$s][]" value="%2$d" aria-label="%3$s"%4$s></td>',
					esc_attr( $c['slug'] ),
					(int) $u->ID,
					esc_attr( $u->display_name . ' — ' . $c['label'] ),
					checked( in_array( (int) $u->ID, $on, true ), true, false )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Judges see entries in their categories once the entries are moved to "In review" (Submissions → bulk action "Send to judging").', 'qhta-competitions' ) . '</p>';
	}

	/**
	 * Save the panel with the program (same nonce as the program form).
	 *
	 * @param int     $post_id Program ID.
	 * @param WP_Post $post    Program.
	 */
	public static function save_panel( $post_id, $post ) {
		if ( empty( $_POST['qc_panel_present'] ) || ! isset( $_POST[ Qhta_Competitions_Admin_Programs::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ Qhta_Competitions_Admin_Programs::NONCE ] ) ), Qhta_Competitions_Admin_Programs::NONCE_ACTION ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			return;
		}
		$posted = isset( $_POST['qc_panel'] ) && is_array( $_POST['qc_panel'] ) ? wp_unslash( $_POST['qc_panel'] ) : array();
		$config = Qhta_Competitions_Program_CPT::get_config( $post_id );
		$panel  = array();
		foreach ( $config['categories'] as $c ) {
			$panel[ $c['slug'] ] = isset( $posted[ $c['slug'] ] ) ? array_map( 'absint', (array) $posted[ $c['slug'] ] ) : array();
		}
		Qhta_Competitions_Judging::save_panel( $post_id, $panel );
	}

	/* ---------------------------------------------------------------------
	 * Judge dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Dashboard or one submission's scoring screen.
	 */
	public static function render_dashboard() {
		$uid = get_current_user_id();
		echo '<div class="wrap qc-admin qc-judging">';
		self::message();
		$sid = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
		if ( $sid ) {
			if ( ! Qhta_Competitions_Judging::judge_can_access( $sid, $uid ) ) {
				wp_die( esc_html__( 'Sorry, this entry is not in your judging list.', 'qhta-competitions' ), 403 );
			}
			self::render_scoring( $sid, $uid );
			echo '</div>';
			return;
		}

		echo '<h1>' . esc_html__( 'Judging', 'qhta-competitions' ) . '</h1>';
		$assignments = Qhta_Competitions_Judging::assignments( $uid );
		if ( ! $assignments ) {
			echo '<p>' . esc_html__( 'You have not been assigned any categories yet.', 'qhta-competitions' ) . '</p></div>';
			return;
		}
		foreach ( $assignments as $pid => $slugs ) {
			$config = Qhta_Competitions_Program_CPT::get_config( $pid );
			echo '<h2>' . esc_html( get_the_title( $pid ) ) . '</h2>';
			foreach ( $slugs as $slug ) {
				$cat = Qhta_Competitions_Program_CPT::category( $config, $slug );
				if ( ! $cat ) {
					continue;
				}
				list( $done, $total ) = Qhta_Competitions_Judging::progress( $pid, $slug, $uid );
				$pct                  = $total ? round( $done / $total * 100 ) : 0;
				echo '<div class="qc-cat"><h3>' . esc_html( $cat['label'] ) . '</h3>';
				/* translators: 1: done, 2: total */
				echo '<div class="qc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="' . (int) $total . '" aria-valuenow="' . (int) $done . '"><span style="width:' . (int) $pct . '%"></span></div><p class="description">' . esc_html( sprintf( __( '%1$d of %2$d scored', 'qhta-competitions' ), $done, $total ) ) . '</p>';
				$queue = Qhta_Competitions_Judging::queue( $pid, $slug, $uid );
				if ( ! $queue ) {
					echo '<p>' . esc_html__( 'No entries to judge yet.', 'qhta-competitions' ) . '</p></div>';
					continue;
				}
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Entry', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Your scoring', 'qhta-competitions' ) . '</th><th></th></tr></thead><tbody>';
				foreach ( $queue as $id ) {
					$scores = Qhta_Competitions_Judging::scores( $id, $uid );
					$pctj   = Qhta_Competitions_Judging::judge_percent( $config['rubric']['criteria'], $scores );
					$any    = array_filter(
						$scores,
						function ( $s ) {
							return null !== $s['score'];
						}
					);
					$state  = null !== $pctj ? __( 'Scored', 'qhta-competitions' ) : ( $any ? __( 'Part scored', 'qhta-competitions' ) : __( 'Not started', 'qhta-competitions' ) );
					echo '<tr><td><strong>' . esc_html( self::entry_label( $id, $config ) ) . '</strong></td><td>' . esc_html( $state ) . '</td>';
					echo '<td><a class="button" href="' . esc_url( admin_url( 'admin.php?page=qc-judging&submission=' . (int) $id ) ) . '">' . esc_html( null !== $pctj ? __( 'Review', 'qhta-competitions' ) : __( 'Score', 'qhta-competitions' ) ) . '</a></td></tr>';
				}
				echo '</tbody></table></div>';
			}
		}
		echo '</div>';
	}

	/**
	 * Reference code, plus the entrant's name when the round isn't anonymised.
	 *
	 * @param int   $id     Submission ID.
	 * @param array $config Config.
	 * @return string
	 */
	private static function entry_label( $id, array $config ) {
		$ref = (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true );
		if ( $config['anonymise'] ) {
			return $ref;
		}
		$f = Qhta_Competitions_Program_CPT::field_by_role( $config, 'entrant_name' );
		return $f ? $ref . ' — ' . Qhta_Competitions_Submission_CPT::to_text( Qhta_Competitions_Submission_CPT::field( $id, $f['key'] ) ) : $ref;
	}

	/**
	 * Fields a judge may see: judge-visible ones; PII only when the round isn't anonymised.
	 *
	 * @param array $config Config.
	 * @return array[]
	 */
	public static function judge_fields( array $config ) {
		$out = array();
		foreach ( $config['fields'] as $f ) {
			if ( in_array( $f['type'], array( 'heading', 'help' ), true ) ) {
				continue;
			}
			$show = $config['anonymise'] ? ( $f['judge_visible'] && ! $f['pii'] ) : ( $f['judge_visible'] || $f['pii'] );
			if ( $show ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/**
	 * Scoring screen.
	 *
	 * @param int $sid Submission ID.
	 * @param int $uid Judge.
	 */
	private static function render_scoring( $sid, $uid ) {
		$pid    = (int) wp_get_post_parent_id( $sid );
		$config = Qhta_Competitions_Program_CPT::get_config( $pid );
		$cat    = Qhta_Competitions_Program_CPT::category( $config, (string) get_post_meta( $sid, '_qc_category', true ) );
		$scores = Qhta_Competitions_Judging::scores( $sid, $uid );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=qc-judging' ) ) . '">← ' . esc_html__( 'All entries', 'qhta-competitions' ) . '</a></p>';
		echo '<h1>' . esc_html( self::entry_label( $sid, $config ) ) . '</h1>';
		echo '<p>' . esc_html( get_the_title( $pid ) . ( $cat ? ' · ' . $cat['label'] : '' ) ) . '</p>';

		echo '<div class="qc-judge-entry">';
		$files = array();
		foreach ( Qhta_Competitions_Files::get( $sid ) as $f ) {
			$files[ $f['field'] ] = $f;
		}
		foreach ( self::judge_fields( $config ) as $f ) {
			if ( 'file' === $f['type'] ) {
				if ( isset( $files[ $f['key'] ] ) ) {
					echo '<p><strong>' . esc_html( $f['label'] ) . ':</strong> <a class="button button-primary" href="' . esc_url( Qhta_Competitions_Files::download_url( $sid, $f['key'] ) ) . '">' . esc_html__( 'Download', 'qhta-competitions' ) . ' .' . esc_html( $files[ $f['key'] ]['ext'] ) . '</a></p>';
				}
				continue;
			}
			if ( ! metadata_exists( 'post', $sid, '_qc_f_' . $f['key'] ) ) {
				continue;
			}
			$text = Qhta_Competitions_Submission_CPT::to_text( Qhta_Competitions_Submission_CPT::field( $sid, $f['key'] ) );
			if ( in_array( $f['type'], array( 'richtext_limited', 'textarea' ), true ) ) {
				echo '<h3>' . esc_html( $f['label'] ) . '</h3><div class="qc-entry__long qc-judge-piece">' . nl2br( esc_html( $text ) ) . '</div>';
			} else {
				foreach ( $f['options'] as $o ) {
					if ( $o['value'] === $text ) {
						$text = $o['label'];
					}
				}
				echo '<p><strong>' . esc_html( $f['label'] ) . ':</strong> ' . esc_html( $text ) . '</p>';
			}
		}
		if ( $config['anonymise'] ) {
			echo '<p class="description">' . esc_html__( 'This round is judged anonymously. If an uploaded document names the entrant or school, please tell the organisers.', 'qhta-competitions' ) . '</p>';
		}
		echo '</div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="qc-score-form">';
		echo '<input type="hidden" name="action" value="qc_save_scores"><input type="hidden" name="submission" value="' . (int) $sid . '">';
		wp_nonce_field( 'qc_save_scores_' . $sid );
		echo '<h2>' . esc_html__( 'Your scores', 'qhta-competitions' ) . '</h2>';
		echo '<table class="widefat qc-rubric"><thead><tr><th>' . esc_html__( 'Criterion', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Score', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Comment (optional)', 'qhta-competitions' ) . '</th></tr></thead><tbody>';
		foreach ( $config['rubric']['criteria'] as $c ) {
			$s   = isset( $scores[ $c['key'] ] ) ? $scores[ $c['key'] ] : array( 'score' => null, 'comment' => '' );
			$val = null === $s['score'] ? '' : rtrim( rtrim( number_format( $s['score'], 2, '.', '' ), '0' ), '.' );
			echo '<tr><td><label for="qc_s_' . esc_attr( $c['key'] ) . '">' . esc_html( $c['label'] ) . '</label></td>';
			/* translators: %s: max */
			echo '<td><input type="number" id="qc_s_' . esc_attr( $c['key'] ) . '" name="score[' . esc_attr( $c['key'] ) . ']" min="0" max="' . esc_attr( (string) $c['max'] ) . '" step="0.5" value="' . esc_attr( $val ) . '" class="small-text"> <span class="description">' . esc_html( sprintf( __( 'out of %s', 'qhta-competitions' ), $c['max'] ) ) . '</span></td>';
			echo '<td><textarea name="comment[' . esc_attr( $c['key'] ) . ']" rows="2" class="widefat">' . esc_textarea( $s['comment'] ) . '</textarea></td></tr>';
		}
		echo '</tbody></table>';
		$overall = isset( $scores[ Qhta_Competitions_Judging::OVERALL ] ) ? $scores[ Qhta_Competitions_Judging::OVERALL ]['comment'] : '';
		echo '<p><label for="qc_overall"><strong>' . esc_html__( 'Overall comment (optional)', 'qhta-competitions' ) . '</strong></label><textarea id="qc_overall" name="overall" rows="4" class="widefat">' . esc_textarea( $overall ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'You can save part-way; an entry counts as scored once every criterion has a score.', 'qhta-competitions' ) . '</p>';
		submit_button( __( 'Save scores', 'qhta-competitions' ), 'primary', 'submit', false );
		echo ' ';
		submit_button( __( 'Save and go to next', 'qhta-competitions' ), 'secondary', 'next', false );
		echo '</form>';
	}

	/**
	 * Save a judge's scores.
	 */
	public static function handle_save_scores() {
		$sid = isset( $_POST['submission'] ) ? absint( $_POST['submission'] ) : 0;
		self::guard( Qhta_Competitions_Plugin::CAP_JUDGE, 'qc_save_scores_' . $sid );
		$uid = get_current_user_id();
		if ( ! Qhta_Competitions_Judging::judge_can_access( $sid, $uid ) ) {
			wp_die( esc_html__( 'Sorry, this entry is not in your judging list.', 'qhta-competitions' ), 403 );
		}
		$scores   = isset( $_POST['score'] ) && is_array( $_POST['score'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['score'] ) ) : array();
		$comments = isset( $_POST['comment'] ) && is_array( $_POST['comment'] ) ? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['comment'] ) ) : array();
		$overall  = isset( $_POST['overall'] ) ? sanitize_textarea_field( wp_unslash( $_POST['overall'] ) ) : '';
		$self     = admin_url( 'admin.php?page=qc-judging&submission=' . $sid );

		$res = Qhta_Competitions_Judging::save_scores( $sid, $uid, $scores, $comments, $overall );
		if ( is_wp_error( $res ) ) {
			self::back( $self, $res->get_error_message(), 'error' );
		}
		if ( ! empty( $_POST['next'] ) ) {
			$pid    = (int) wp_get_post_parent_id( $sid );
			$config = Qhta_Competitions_Program_CPT::get_config( $pid );
			$queue  = Qhta_Competitions_Judging::queue( $pid, (string) get_post_meta( $sid, '_qc_category', true ), $uid );
			foreach ( $queue as $id ) {
				if ( $id !== $sid && null === Qhta_Competitions_Judging::judge_percent( $config['rubric']['criteria'], Qhta_Competitions_Judging::scores( $id, $uid ) ) ) {
					self::back( admin_url( 'admin.php?page=qc-judging&submission=' . $id ), __( 'Saved. Here is the next entry to score.', 'qhta-competitions' ) );
				}
			}
			self::back( admin_url( 'admin.php?page=qc-judging' ), __( 'Saved. You have scored every entry in this category.', 'qhta-competitions' ) );
		}
		self::back( $self, __( 'Scores saved.', 'qhta-competitions' ) );
	}

	/* ---------------------------------------------------------------------
	 * Submission screen: judges' scores (admins)
	 * ------------------------------------------------------------------ */

	/**
	 * Register the scores box on the submission screen.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function scores_box( $post ) {
		if ( in_array( $post->post_status, Qhta_Competitions_Judging::RESULT_STATUSES, true ) ) {
			add_meta_box( 'qc_scores', __( 'Judges\' scores', 'qhta-competitions' ), array( __CLASS__, 'render_scores_box' ), Qhta_Competitions_Submission_CPT::POST_TYPE, 'normal' );
		}
	}

	/**
	 * Each judge's scores and comments.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function render_scores_box( $post ) {
		$config = Qhta_Competitions_Program_CPT::get_config( $post->post_parent );
		$panel  = Qhta_Competitions_Judging::panel( $post->post_parent );
		$cat    = (string) get_post_meta( $post->ID, '_qc_category', true );
		$judges = isset( $panel[ $cat ] ) ? $panel[ $cat ] : array();
		if ( ! $judges ) {
			echo '<p>' . esc_html__( 'No judges assigned to this category.', 'qhta-competitions' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Judge', 'qhta-competitions' ) . '</th>';
		foreach ( $config['rubric']['criteria'] as $c ) {
			echo '<th>' . esc_html( $c['label'] ) . ' <span class="description">/' . esc_html( (string) $c['max'] ) . '</span></th>';
		}
		echo '<th>%</th><th>' . esc_html__( 'Comments', 'qhta-competitions' ) . '</th></tr></thead><tbody>';
		foreach ( $judges as $uid ) {
			$u = get_userdata( $uid );
			echo '<tr><td>' . esc_html( $u ? $u->display_name : '#' . $uid ) . '</td>';
			if ( Qhta_Competitions_Judging::conflict( $post->ID, $uid ) ) {
				echo '<td colspan="' . ( count( $config['rubric']['criteria'] ) + 2 ) . '"><em>' . esc_html__( 'Conflict of interest (same school) — excluded', 'qhta-competitions' ) . '</em></td></tr>';
				continue;
			}
			$scores   = Qhta_Competitions_Judging::scores( $post->ID, $uid );
			$comments = array();
			foreach ( $config['rubric']['criteria'] as $c ) {
				$s = isset( $scores[ $c['key'] ] ) ? $scores[ $c['key'] ] : null;
				echo '<td>' . esc_html( $s && null !== $s['score'] ? (string) ( $s['score'] + 0 ) : '—' ) . '</td>';
				if ( $s && '' !== $s['comment'] ) {
					$comments[] = $c['label'] . ': ' . $s['comment'];
				}
			}
			if ( isset( $scores[ Qhta_Competitions_Judging::OVERALL ] ) && '' !== $scores[ Qhta_Competitions_Judging::OVERALL ]['comment'] ) {
				$comments[] = __( 'Overall', 'qhta-competitions' ) . ': ' . $scores[ Qhta_Competitions_Judging::OVERALL ]['comment'];
			}
			$pct = Qhta_Competitions_Judging::judge_percent( $config['rubric']['criteria'], $scores );
			echo '<td>' . esc_html( null === $pct ? '—' : number_format_i18n( $pct, 1 ) ) . '</td><td>' . nl2br( esc_html( implode( "\n", $comments ) ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ---------------------------------------------------------------------
	 * Results
	 * ------------------------------------------------------------------ */

	/**
	 * Results view per category, with bulk outcome status.
	 */
	public static function render_results() {
		$programs = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
			)
		);
		$pid      = isset( $_GET['program'] ) ? absint( $_GET['program'] ) : ( $programs ? $programs[0]->ID : 0 );
		$config   = $pid ? Qhta_Competitions_Program_CPT::get_config( $pid ) : null;
		$cat_slug = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : ( $config && $config['categories'] ? $config['categories'][0]['slug'] : '' );

		echo '<div class="wrap qc-admin"><h1>' . esc_html__( 'Results', 'qhta-competitions' ) . '</h1>';
		self::message();
		echo '<form method="get" class="qc-inline-form"><input type="hidden" name="post_type" value="' . esc_attr( Qhta_Competitions_Program_CPT::POST_TYPE ) . '"><input type="hidden" name="page" value="qc-results">';
		echo '<select name="program" onchange="this.form.submit()">';
		foreach ( $programs as $p ) {
			echo '<option value="' . (int) $p->ID . '"' . selected( $pid, $p->ID, false ) . '>' . esc_html( $p->post_title ) . '</option>';
		}
		echo '</select> <select name="category">';
		if ( $config ) {
			foreach ( $config['categories'] as $c ) {
				echo '<option value="' . esc_attr( $c['slug'] ) . '"' . selected( $cat_slug, $c['slug'], false ) . '>' . esc_html( $c['label'] ) . '</option>';
			}
		}
		echo '</select> ';
		submit_button( __( 'Show', 'qhta-competitions' ), 'secondary', '', false );
		echo '</form>';

		if ( ! $config || '' === $cat_slug ) {
			echo '</div>';
			return;
		}

		$rows   = Qhta_Competitions_Judging::results( $pid, $cat_slug );
		$panel  = Qhta_Competitions_Judging::panel( $pid );
		$judges = isset( $panel[ $cat_slug ] ) ? $panel[ $cat_slug ] : array();
		$labels = Qhta_Competitions_Submission_CPT::statuses();
		$ent_f  = Qhta_Competitions_Program_CPT::field_by_role( $config, 'entrant_name' );

		/* translators: %d: margin */
		echo '<p class="description">' . esc_html( sprintf( __( 'Judge %% = Σ(score ÷ max × weight) ÷ Σ weight × 100, counted once a judge has scored every criterion. Mean = average of those. ⚠ = judges differ by more than %d points. Equal means share a rank.', 'qhta-competitions' ), (int) $config['judge_spread_margin'] ) ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="qc_results_status"><input type="hidden" name="program" value="' . (int) $pid . '"><input type="hidden" name="category" value="' . esc_attr( $cat_slug ) . '">';
		wp_nonce_field( 'qc_results_status' );
		echo '<table class="widefat striped qc-results"><thead><tr><td class="check-column"><input type="checkbox" onclick="var c=this.checked;this.closest(\'table\').querySelectorAll(\'tbody input[type=checkbox]\').forEach(function(x){x.checked=c;});" aria-label="' . esc_attr__( 'Select all', 'qhta-competitions' ) . '"></td><th>' . esc_html__( 'Rank', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Entry', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Status', 'qhta-competitions' ) . '</th>';
		foreach ( $judges as $uid ) {
			$u = get_userdata( $uid );
			echo '<th>' . esc_html( $u ? $u->display_name : '#' . $uid ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Mean %', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Spread', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Scored', 'qhta-competitions' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . ( 7 + count( $judges ) ) . '">' . esc_html__( 'No entries in judging for this category yet.', 'qhta-competitions' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$entrant = $ent_f ? Qhta_Competitions_Submission_CPT::to_text( Qhta_Competitions_Submission_CPT::field( $r['id'], $ent_f['key'] ) ) : '';
			echo '<tr' . ( $r['warn'] ? ' class="qc-warn-row"' : '' ) . '><th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '" aria-label="' . esc_attr( $r['ref'] ) . '"></th>';
			echo '<td class="qc-rank">' . esc_html( null === $r['rank'] ? '—' : (string) $r['rank'] ) . '</td>';
			echo '<td><a href="' . esc_url( get_edit_post_link( $r['id'] ) ) . '">' . esc_html( $r['ref'] ) . '</a><br><span class="description">' . esc_html( $entrant ) . '</span></td>';
			echo '<td>' . esc_html( isset( $labels[ $r['status'] ] ) ? $labels[ $r['status'] ] : $r['status'] ) . '</td>';
			foreach ( $judges as $uid ) {
				$j = isset( $r['judges'][ $uid ] ) ? $r['judges'][ $uid ] : null;
				if ( ! $j ) {
					echo '<td>—</td>';
				} elseif ( $j['conflict'] ) {
					echo '<td title="' . esc_attr__( 'Conflict of interest — excluded', 'qhta-competitions' ) . '">COI</td>';
				} else {
					echo '<td>' . esc_html( null === $j['pct'] ? '…' : number_format_i18n( $j['pct'], 2 ) ) . '</td>';
				}
			}
			echo '<td><strong>' . esc_html( null === $r['mean'] ? '—' : number_format_i18n( $r['mean'], 2 ) ) . '</strong></td>';
			echo '<td>' . ( null === $r['spread'] ? '—' : esc_html( number_format_i18n( $r['spread'], 2 ) ) . ( $r['warn'] ? ' <span class="qc-warn" title="' . esc_attr__( 'Judges disagree by more than the round\'s margin', 'qhta-competitions' ) . '">⚠</span>' : '' ) ) . '</td>';
			echo '<td>' . esc_html( $r['complete'] . ' / ' . $r['assigned'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><label for="qc_bulk_to">' . esc_html__( 'Set selected to', 'qhta-competitions' ) . '</label> <select id="qc_bulk_to" name="to">';
		foreach ( array( 'qc_shortlisted', 'qc_awarded', 'qc_commended', 'qc_unsuccessful' ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '">' . esc_html( $labels[ $s ] ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Apply', 'qhta-competitions' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Follows the usual order (In review → Shortlisted → Awarded / Commended / Unsuccessful). No emails are sent — outcome emails are a separate step.', 'qhta-competitions' ) . '</span></p>';
		echo '</form></div>';
	}

	/**
	 * Bulk status from the results view.
	 */
	public static function handle_results_status() {
		self::guard( Qhta_Competitions_Plugin::CAP_MANAGE, 'qc_results_status' );
		$pid  = isset( $_POST['program'] ) ? absint( $_POST['program'] ) : 0;
		$cat  = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		$ids  = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
		$url  = admin_url( 'edit.php?post_type=' . Qhta_Competitions_Program_CPT::POST_TYPE . '&page=qc-results&program=' . $pid . '&category=' . $cat );
		$ok   = 0;
		$fail = array();
		foreach ( $ids as $id ) {
			if ( (int) wp_get_post_parent_id( $id ) !== $pid ) {
				continue;
			}
			$res = Qhta_Competitions_Submission_CPT::transition( $id, $to, __( 'Set from results view', 'qhta-competitions' ) );
			if ( is_wp_error( $res ) ) {
				$fail[] = (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true );
			} else {
				++$ok;
			}
		}
		/* translators: %d: count */
		$msg = sprintf( _n( '%d entry updated.', '%d entries updated.', $ok, 'qhta-competitions' ), $ok );
		if ( $fail ) {
			/* translators: %s: refs */
			$msg .= ' ' . sprintf( __( 'Not allowed from their current status: %s.', 'qhta-competitions' ), implode( ', ', $fail ) );
		}
		self::back( $url, $msg, $fail ? 'error' : 'success' );
	}
}
