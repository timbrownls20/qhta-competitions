<?php
/**
 * Submissions admin: list columns and filters, test-entry tools, read-only detail screen, status changes.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission admin screens.
 */
final class Qhta_Competitions_Admin_Submissions {

	/**
	 * Hooks.
	 */
	public static function init() {
		$pt = Qhta_Competitions_Submission_CPT::POST_TYPE;

		add_filter( 'manage_' . $pt . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . $pt . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filters' ) );
		add_filter( 'views_edit-' . $pt, array( __CLASS__, 'views' ) );
		add_filter( 'bulk_actions-edit-' . $pt, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . $pt, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );

		add_action( 'add_meta_boxes_' . $pt, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'admin_post_qc_set_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_qc_checks', array( __CLASS__, 'handle_checks' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Admin CSS on submission screens.
	 */
	public static function assets() {
		$screen = get_current_screen();
		if ( $screen && Qhta_Competitions_Submission_CPT::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'qc-admin', QC_URL . 'assets/css/admin.css', array(), QC_VERSION );
		}
	}

	/* ---------------------------------------------------------------------
	 * List
	 * ------------------------------------------------------------------ */

	/**
	 * Columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		return array(
			'cb'          => $cols['cb'],
			'title'       => __( 'Reference', 'qhta-competitions' ),
			'qc_entrant'  => __( 'Entrant', 'qhta-competitions' ),
			'qc_school'   => __( 'School', 'qhta-competitions' ),
			'qc_category' => __( 'Category', 'qhta-competitions' ),
			'qc_words'    => __( 'Words', 'qhta-competitions' ),
			'qc_flags'    => __( 'Flags', 'qhta-competitions' ),
			'qc_member'   => __( 'Member?', 'qhta-competitions' ),
			'qc_status'   => __( 'Status', 'qhta-competitions' ),
			'date'        => __( 'Submitted', 'qhta-competitions' ),
		);
	}

	/**
	 * Value of the field carrying a role.
	 *
	 * @param int    $id   Submission ID.
	 * @param string $role Role.
	 * @return string
	 */
	private static function role_value( $id, $role ) {
		static $configs = array();
		$program = (int) wp_get_post_parent_id( $id );
		if ( ! isset( $configs[ $program ] ) ) {
			$configs[ $program ] = Qhta_Competitions_Program_CPT::get_config( $program );
		}
		$f = Qhta_Competitions_Program_CPT::field_by_role( $configs[ $program ], $role );
		return $f ? Qhta_Competitions_Submission_CPT::to_text( Qhta_Competitions_Submission_CPT::field( $id, $f['key'] ) ) : '';
	}

	/**
	 * Column content.
	 *
	 * @param string $col Column.
	 * @param int    $id  Submission ID.
	 */
	public static function column( $col, $id ) {
		switch ( $col ) {
			case 'qc_entrant':
				echo esc_html( self::role_value( $id, 'entrant_name' ) );
				if ( Qhta_Competitions_Submission_CPT::is_test( $id ) ) {
					echo ' <span class="qc-badge qc-badge--preview">' . esc_html__( 'test', 'qhta-competitions' ) . '</span>';
				}
				break;
			case 'qc_school':
				echo esc_html( self::role_value( $id, 'school' ) );
				break;
			case 'qc_category':
				$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );
				$cat    = Qhta_Competitions_Program_CPT::category( $config, (string) get_post_meta( $id, '_qc_category', true ) );
				echo esc_html( $cat ? $cat['label'] : '' );
				break;
			case 'qc_words':
				$declared = (string) get_post_meta( $id, '_qc_word_count_declared', true );
				$auto     = (string) get_post_meta( $id, '_qc_word_count_auto', true );
				$parts    = array();
				if ( '' !== $auto ) {
					/* translators: %s: count */
					$parts[] = sprintf( __( 'auto %s', 'qhta-competitions' ), number_format_i18n( (int) $auto ) );
				}
				if ( '' !== $declared ) {
					/* translators: %s: count */
					$parts[] = sprintf( __( 'declared %s', 'qhta-competitions' ), number_format_i18n( (int) $declared ) );
				}
				echo esc_html( implode( ' / ', $parts ) );
				break;
			case 'qc_flags':
				$flags = Qhta_Competitions_Util::get_json_meta( $id, '_qc_flags', array() );
				foreach ( (array) $flags as $f ) {
					echo '<span class="qc-flag" title="' . esc_attr( $f['message'] ) . '">⚑ ' . esc_html( str_replace( '_', ' ', $f['code'] ) ) . '</span><br>';
				}
				break;
			case 'qc_member':
				$sym = Qhta_Competitions_Eligibility::member_symbol( $id );
				echo '' === $sym ? '—' : '<span class="qc-member">' . esc_html( $sym ) . '</span>';
				break;
			case 'qc_status':
				$statuses = Qhta_Competitions_Submission_CPT::statuses();
				$status   = get_post_status( $id );
				echo '<span class="qc-status qc-status--' . esc_attr( $status ) . '">' . esc_html( isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status ) . '</span>';
				break;
		}
	}

	/**
	 * Filter dropdowns: round, category, flagged.
	 *
	 * @param string $post_type Post type.
	 */
	public static function filters( $post_type ) {
		if ( Qhta_Competitions_Submission_CPT::POST_TYPE !== $post_type ) {
			return;
		}
		$programs = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'pending' ),
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$current = isset( $_GET['qc_program'] ) ? absint( $_GET['qc_program'] ) : 0;
		echo '<select name="qc_program"><option value="">' . esc_html__( 'All rounds', 'qhta-competitions' ) . '</option>';
		foreach ( $programs as $p ) {
			echo '<option value="' . (int) $p->ID . '"' . selected( $current, $p->ID, false ) . '>' . esc_html( $p->post_title ) . '</option>';
		}
		echo '</select>';

		if ( $current ) {
			$config  = Qhta_Competitions_Program_CPT::get_config( $current );
			$cat_cur = isset( $_GET['qc_category'] ) ? sanitize_key( wp_unslash( $_GET['qc_category'] ) ) : '';
			echo '<select name="qc_category"><option value="">' . esc_html__( 'All categories', 'qhta-competitions' ) . '</option>';
			foreach ( $config['categories'] as $c ) {
				echo '<option value="' . esc_attr( $c['slug'] ) . '"' . selected( $cat_cur, $c['slug'], false ) . '>' . esc_html( $c['label'] ) . '</option>';
			}
			echo '</select>';
		}
		$flagged = ! empty( $_GET['qc_flagged'] );
		echo '<label class="qc-inline-check"><input type="checkbox" name="qc_flagged" value="1"' . checked( $flagged, true, false ) . '> ' . esc_html__( 'Flagged only', 'qhta-competitions' ) . '</label>';
	}

	/**
	 * Apply list filters (round, category, flagged, tests).
	 *
	 * @param WP_Query $q Query.
	 */
	public static function apply_filters( $q ) {
		if ( ! is_admin() || ! $q->is_main_query() || Qhta_Competitions_Submission_CPT::POST_TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		if ( ! empty( $_GET['qc_program'] ) ) {
			$q->set( 'post_parent', absint( $_GET['qc_program'] ) );
		}
		$meta = array();
		if ( ! empty( $_GET['qc_category'] ) ) {
			$meta[] = array(
				'key'   => '_qc_category',
				'value' => sanitize_key( wp_unslash( $_GET['qc_category'] ) ),
			);
		}
		if ( ! empty( $_GET['qc_flagged'] ) ) {
			$meta[] = array(
				'key'     => '_qc_flags',
				'value'   => '[]',
				'compare' => '!=',
			);
		}
		$tests = isset( $_GET['qc_tests'] ) ? sanitize_key( wp_unslash( $_GET['qc_tests'] ) ) : '';
		if ( 'only' === $tests ) {
			$meta[] = array(
				'key'   => Qhta_Competitions_Submission_CPT::TEST_META,
				'value' => '1',
			);
		} elseif ( 'hide' === $tests ) {
			$meta[] = Qhta_Competitions_Submission_CPT::real_entries_clause();
		}
		if ( $meta ) {
			$q->set( 'meta_query', $meta );
		}
	}

	/**
	 * "Tests" and "Real entries" views.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public static function views( $views ) {
		global $wpdb;
		$count   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'trash'", Qhta_Competitions_Submission_CPT::TEST_META, Qhta_Competitions_Submission_CPT::POST_TYPE ) );
		$current = isset( $_GET['qc_tests'] ) ? sanitize_key( wp_unslash( $_GET['qc_tests'] ) ) : '';
		$base    = admin_url( 'edit.php?post_type=' . Qhta_Competitions_Submission_CPT::POST_TYPE );
		$views['qc_real']  = '<a href="' . esc_url( add_query_arg( 'qc_tests', 'hide', $base ) ) . '"' . ( 'hide' === $current ? ' class="current"' : '' ) . '>' . esc_html__( 'Real entries', 'qhta-competitions' ) . '</a>';
		$views['qc_tests'] = '<a href="' . esc_url( add_query_arg( 'qc_tests', 'only', $base ) ) . '"' . ( 'only' === $current ? ' class="current"' : '' ) . '>' . esc_html__( 'Tests', 'qhta-competitions' ) . ' <span class="count">(' . number_format_i18n( $count ) . ')</span></a>';
		return $views;
	}

	/**
	 * Bulk actions: only our own.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		return array(
			'qc_to_review'    => __( 'Send to judging (In review)', 'qhta-competitions' ),
			'qc_delete_tests' => __( 'Delete test entries', 'qhta-competitions' ),
		);
	}

	/**
	 * Bulk "Delete test entries": permanently deletes selected entries that are tests; real ones are skipped.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Selected IDs.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			return $redirect;
		}
		if ( 'qc_to_review' === $action ) {
			$moved   = 0;
			$skipped = 0;
			foreach ( $ids as $id ) {
				// Test entries never go to judges.
				if ( Qhta_Competitions_Submission_CPT::is_test( $id ) || is_wp_error( Qhta_Competitions_Submission_CPT::transition( (int) $id, 'qc_in_review', __( 'Sent to judging', 'qhta-competitions' ) ) ) ) {
					++$skipped;
				} else {
					++$moved;
				}
			}
			return add_query_arg(
				array(
					'qc_reviewed'  => $moved,
					'qc_rskipped'  => $skipped,
				),
				$redirect
			);
		}
		if ( 'qc_delete_tests' !== $action ) {
			return $redirect;
		}
		$deleted = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			if ( Qhta_Competitions_Submission_CPT::POST_TYPE === get_post_type( $id ) && Qhta_Competitions_Submission_CPT::is_test( $id ) ) {
				wp_delete_post( (int) $id, true );
				++$deleted;
			} else {
				++$skipped;
			}
		}
		return add_query_arg(
			array(
				'qc_deleted' => $deleted,
				'qc_skipped' => $skipped,
			),
			$redirect
		);
	}

	/**
	 * Result notice for the bulk delete.
	 */
	public static function bulk_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . Qhta_Competitions_Submission_CPT::POST_TYPE !== $screen->id ) {
			return;
		}
		if ( isset( $_GET['qc_reviewed'] ) ) {
			$moved   = absint( $_GET['qc_reviewed'] );
			$skipped = isset( $_GET['qc_rskipped'] ) ? absint( $_GET['qc_rskipped'] ) : 0;
			/* translators: 1: moved, 2: skipped */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( __( '%1$d sent to judging. %2$d skipped (test entries, or not in "Submitted" status — entries awaiting referee confirmation must be confirmed first).', 'qhta-competitions' ), $moved, $skipped ) ) . '</p></div>';
			return;
		}
		if ( ! isset( $_GET['qc_deleted'] ) ) {
			return;
		}
		$deleted = absint( $_GET['qc_deleted'] );
		$skipped = isset( $_GET['qc_skipped'] ) ? absint( $_GET['qc_skipped'] ) : 0;
		/* translators: %d: count */
		$msg = sprintf( _n( '%d test entry deleted.', '%d test entries deleted.', $deleted, 'qhta-competitions' ), $deleted );
		if ( $skipped ) {
			/* translators: %d: count */
			$msg .= ' ' . sprintf( _n( '%d real entry was skipped — only test entries can be deleted here.', '%d real entries were skipped — only test entries can be deleted here.', $skipped, 'qhta-competitions' ), $skipped );
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Row actions: View only (no quick edit, no trash for real entries).
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( Qhta_Competitions_Submission_CPT::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		return array(
			'view' => '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'View', 'qhta-competitions' ) . '</a>',
		);
	}

	/* ---------------------------------------------------------------------
	 * Detail screen
	 * ------------------------------------------------------------------ */

	/**
	 * Read-only boxes; the core publish box is replaced by a status box.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function meta_boxes( $post ) {
		$pt = Qhta_Competitions_Submission_CPT::POST_TYPE;
		remove_meta_box( 'submitdiv', $pt, 'side' );
		remove_meta_box( 'slugdiv', $pt, 'normal' );
		add_meta_box( 'qc_status', __( 'Status', 'qhta-competitions' ), array( __CLASS__, 'box_status' ), $pt, 'side', 'high' );
		add_meta_box( 'qc_entry', __( 'Entry', 'qhta-competitions' ), array( __CLASS__, 'box_entry' ), $pt, 'normal', 'high' );
		add_meta_box( 'qc_signature', __( 'Declarations & signature', 'qhta-competitions' ), array( __CLASS__, 'box_signature' ), $pt, 'normal' );
		$config = Qhta_Competitions_Program_CPT::get_config( $post->post_parent );
		if ( Qhta_Competitions_Program_CPT::has_confirmer( $config ) || Qhta_Competitions_Confirm::record( $post->ID ) ) {
			add_meta_box( 'qc_confirmation', __( 'Referee confirmation', 'qhta-competitions' ), array( __CLASS__, 'box_confirmation' ), $pt, 'normal' );
		}
		if ( self::verify_fields( $config ) || is_array( Qhta_Competitions_Util::get_json_meta( $post->ID, Qhta_Competitions_Eligibility::MEMBER_META, null ) ) ) {
			add_meta_box( 'qc_checks', __( 'Admin checks', 'qhta-competitions' ), array( __CLASS__, 'box_checks' ), $pt, 'side' );
		}
		add_meta_box( 'qc_log', __( 'History', 'qhta-competitions' ), array( __CLASS__, 'box_log' ), $pt, 'normal', 'low' );
	}

	/**
	 * Status + allowed transitions.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function box_status( $post ) {
		$statuses = Qhta_Competitions_Submission_CPT::statuses();
		$current  = $post->post_status;
		$ref      = (string) get_post_meta( $post->ID, Qhta_Competitions_Submission_CPT::REF_META, true );
		echo '<p class="qc-ref"><strong>' . esc_html( $ref ) . '</strong></p>';
		if ( Qhta_Competitions_Submission_CPT::is_test( $post->ID ) ) {
			echo '<p><span class="qc-badge qc-badge--preview">' . esc_html__( 'Test entry', 'qhta-competitions' ) . '</span> ' . esc_html__( 'Excluded from limits, judging, exports and outcome emails.', 'qhta-competitions' ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Round:', 'qhta-competitions' ) . ' <a href="' . esc_url( get_edit_post_link( $post->post_parent ) ) . '">' . esc_html( get_the_title( $post->post_parent ) ) . '</a></p>';
		echo '<p>' . esc_html__( 'Status:', 'qhta-competitions' ) . ' <strong>' . esc_html( isset( $statuses[ $current ] ) ? $statuses[ $current ] : $current ) . '</strong></p>';

		$allowed = Qhta_Competitions_Submission_CPT::transitions();
		$next    = isset( $allowed[ $current ] ) ? $allowed[ $current ] : array();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="qc-status-form">';
		echo '<input type="hidden" name="action" value="qc_set_status"><input type="hidden" name="id" value="' . (int) $post->ID . '">';
		wp_nonce_field( 'qc_set_status_' . $post->ID );
		echo '<p><label for="qc_to">' . esc_html__( 'Change to', 'qhta-competitions' ) . '</label><br><select id="qc_to" name="to">';
		foreach ( $next as $s ) {
			echo '<option value="' . esc_attr( $s ) . '">' . esc_html( $statuses[ $s ] ) . '</option>';
		}
		echo '<optgroup label="' . esc_attr__( 'Override (note required)', 'qhta-competitions' ) . '">';
		foreach ( $statuses as $s => $label ) {
			if ( $s !== $current && ! in_array( $s, $next, true ) ) {
				echo '<option value="' . esc_attr( 'force:' . $s ) . '">' . esc_html( $label ) . '</option>';
			}
		}
		echo '</optgroup></select></p>';
		echo '<p><label for="qc_note">' . esc_html__( 'Note', 'qhta-competitions' ) . '</label><textarea id="qc_note" name="note" rows="2" class="widefat"></textarea></p>';
		submit_button( __( 'Change status', 'qhta-competitions' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Changing status does not email anyone. Outcome emails are sent in bulk later.', 'qhta-competitions' ) . '</p>';
	}

	/**
	 * Handle a status change.
	 */
	public static function handle_status() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'qhta-competitions' ), 403 );
		}
		check_admin_referer( 'qc_set_status_' . $id );
		$to    = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$note  = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$force = 0 === strpos( $to, 'force:' );
		$to    = $force ? substr( $to, 6 ) : $to;
		$res   = Qhta_Competitions_Submission_CPT::transition( $id, $to, $note, $force );
		if ( is_wp_error( $res ) ) {
			wp_die( esc_html( $res->get_error_message() ), '', array( 'back_link' => true ) );
		}
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}

	/**
	 * Entry details: fields, category, word counts, flags, files.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function box_entry( $post ) {
		$config = Qhta_Competitions_Program_CPT::get_config( $post->post_parent );
		$cat    = Qhta_Competitions_Program_CPT::category( $config, (string) get_post_meta( $post->ID, '_qc_category', true ) );
		$flags  = Qhta_Competitions_Util::get_json_meta( $post->ID, '_qc_flags', array() );

		if ( $flags ) {
			echo '<div class="notice notice-warning inline"><ul>';
			foreach ( $flags as $f ) {
				echo '<li>⚑ ' . esc_html( $f['message'] ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<table class="widefat striped qc-entry"><tbody>';
		if ( $cat ) {
			echo '<tr><th>' . esc_html__( 'Category', 'qhta-competitions' ) . '</th><td>' . esc_html( $cat['label'] ) . '</td></tr>';
		}
		$files = array();
		foreach ( Qhta_Competitions_Files::get( $post->ID ) as $file ) {
			$files[ $file['field'] ] = $file;
		}
		foreach ( $config['fields'] as $f ) {
			if ( 'heading' === $f['type'] ) {
				echo '<tr class="qc-entry__heading"><th colspan="2">' . esc_html( $f['label'] ) . '</th></tr>';
				continue;
			}
			if ( 'help' === $f['type'] ) {
				continue;
			}
			if ( 'file' === $f['type'] ) {
				$cell = '—';
				if ( isset( $files[ $f['key'] ] ) ) {
					$file = $files[ $f['key'] ];
					$cell = '<a class="button" href="' . esc_url( Qhta_Competitions_Files::download_url( $post->ID, $f['key'] ) ) . '">' . esc_html__( 'Download', 'qhta-competitions' ) . ' .' . esc_html( $file['ext'] ) . '</a> <span class="description">' . esc_html( size_format( $file['size'] ) ) . ' · ' . esc_html( $file['original_name'] ) . '</span>';
				}
				echo '<tr><th>' . esc_html( $f['label'] ) . '</th><td>' . $cell . '</td></tr>'; // Cell built from escaped parts.
				continue;
			}
			if ( ! metadata_exists( 'post', $post->ID, '_qc_f_' . $f['key'] ) ) {
				continue; // Not shown to the submitter (condition not met).
			}
			$value = Qhta_Competitions_Submission_CPT::field( $post->ID, $f['key'] );
			if ( 'checkbox' === $f['type'] ) {
				$text = '1' === $value ? '✓ ' . __( 'Yes', 'qhta-competitions' ) : '✗ ' . __( 'No', 'qhta-competitions' );
			} elseif ( in_array( $f['type'], array( 'select', 'radio' ), true ) ) {
				$text = (string) $value;
				foreach ( $f['options'] as $o ) {
					if ( $o['value'] === $value ) {
						$text = $o['label'];
					}
				}
			} else {
				$text = Qhta_Competitions_Submission_CPT::to_text( $value );
			}
			$cell = 'richtext_limited' === $f['type'] || 'textarea' === $f['type'] ? '<div class="qc-entry__long">' . nl2br( esc_html( $text ) ) . '</div>' : esc_html( $text );
			echo '<tr><th>' . esc_html( $f['label'] ) . ( $f['pii'] ? ' <span class="qc-pii" title="' . esc_attr__( 'Personal information: hidden from judges, purged by retention', 'qhta-competitions' ) . '">PII</span>' : '' ) . '</th><td>' . $cell . '</td></tr>'; // Cell escaped above.
		}

		$declared  = (string) get_post_meta( $post->ID, '_qc_word_count_declared', true );
		$auto      = (string) get_post_meta( $post->ID, '_qc_word_count_auto', true );
		$breakdown = Qhta_Competitions_Util::get_json_meta( $post->ID, '_qc_word_count_breakdown', null );
		if ( '' !== $auto || '' !== $declared ) {
			$words = array();
			if ( '' !== $auto ) {
				/* translators: %s: count */
				$words[] = sprintf( __( 'Counted: %s', 'qhta-competitions' ), number_format_i18n( (int) $auto ) );
			}
			if ( is_array( $breakdown ) && 'docx' === $breakdown['source'] ) {
				/* translators: 1: body, 2: notes, 3: excluded, 4: heading */
				$words[] = sprintf( __( '(body %1$s + notes %2$s; %3$s not counted after "%4$s")', 'qhta-competitions' ), number_format_i18n( $breakdown['body'] ), number_format_i18n( $breakdown['notes'] ), number_format_i18n( $breakdown['excluded'] ), '' !== $breakdown['stopped_at'] ? $breakdown['stopped_at'] : '—' );
			}
			if ( '' !== $declared ) {
				/* translators: %s: count */
				$words[] = sprintf( __( 'Declared: %s', 'qhta-competitions' ), number_format_i18n( (int) $declared ) );
			}
			echo '<tr><th>' . esc_html__( 'Word count', 'qhta-competitions' ) . '</th><td>' . esc_html( implode( ' ', $words ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Declarations rendered as a signature block.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function box_signature( $post ) {
		$snap = Qhta_Competitions_Util::get_json_meta( $post->ID, '_qc_declarations_accepted', null );
		if ( ! is_array( $snap ) ) {
			echo '<p>' . esc_html__( 'No declarations recorded.', 'qhta-competitions' ) . '</p>';
			return;
		}
		echo '<ul class="qc-decl-list">';
		foreach ( $snap['items'] as $item ) {
			echo '<li>' . ( $item['accepted'] ? '☑' : '☐' ) . ' ' . esc_html( $item['text'] ) . ( 'publication_consent' === $item['kind'] ? ' <span class="qc-badge qc-badge--on">' . esc_html__( 'publication consent', 'qhta-competitions' ) . '</span>' : '' ) . '</li>';
		}
		echo '</ul>';
		echo '<div class="qc-sigblock">';
		echo '<p class="qc-sigblock__name">' . esc_html( $snap['typed_name'] ) . '</p>';
		echo '<p class="qc-sigblock__meta">' . esc_html__( 'Signed by typing their name', 'qhta-competitions' ) . ' · ' . esc_html( Qhta_Competitions_Util::display_datetime( $snap['ts'] ) ) . ' · IP ' . esc_html( $snap['ip'] ) . '</p>';
		echo '<p class="qc-sigblock__meta">' . esc_html( $snap['user_agent'] ) . '</p>';
		echo '</div>';
	}

	/**
	 * Transition/email log.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function box_log( $post ) {
		$log      = array_reverse( Qhta_Competitions_Submission_CPT::get_log( $post->ID ) );
		$statuses = Qhta_Competitions_Submission_CPT::statuses();
		$label    = function ( $s ) use ( $statuses ) {
			return isset( $statuses[ $s ] ) ? $statuses[ $s ] : $s;
		};
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Who', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Change', 'qhta-competitions' ) . '</th><th>' . esc_html__( 'Note', 'qhta-competitions' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $e ) {
			$user   = $e['user_id'] ? get_userdata( $e['user_id'] ) : null;
			$change = '' !== $e['to'] ? $label( $e['from'] ) . ' → ' . $label( $e['to'] ) : '';
			echo '<tr><td>' . esc_html( Qhta_Competitions_Util::display_datetime( $e['ts'] ) ) . '</td><td>' . esc_html( $user ? $user->display_name : __( 'Public form', 'qhta-competitions' ) ) . '</td><td>' . esc_html( $change ) . '</td><td>' . esc_html( $e['note'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Fields marked "admin_verify" (e.g. QCT number).
	 *
	 * @param array $config Config.
	 * @return array[]
	 */
	private static function verify_fields( array $config ) {
		return array_values(
			array_filter(
				$config['fields'],
				function ( $f ) {
					return ! empty( $f['admin_verify'] );
				}
			)
		);
	}

	/**
	 * Referee confirmation status, details and admin actions.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function box_confirmation( $post ) {
		$rec = Qhta_Competitions_Confirm::record( $post->ID );
		$row = function ( $label, $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		};
		echo '<table class="widefat striped qc-entry"><tbody>';
		$row( __( 'Referee', 'qhta-competitions' ), trim( ( $rec['name'] ?? '' ) . ' <' . ( $rec['email'] ?? '' ) . '>' ) );
		$row( __( 'Link last sent', 'qhta-competitions' ), ! empty( $rec['sent_at'] ) ? Qhta_Competitions_Util::display_datetime( $rec['sent_at'] ) : __( 'not sent', 'qhta-competitions' ) );
		$row( __( 'Link expires', 'qhta-competitions' ), wp_date( 'j F Y, g:i a T', Qhta_Competitions_Confirm::expires_at( $post->ID ) ) );
		$reminded = (string) get_post_meta( $post->ID, Qhta_Competitions_Confirm::REMINDED_META, true );
		if ( '' !== $reminded ) {
			$row( __( 'Reminder sent', 'qhta-competitions' ), Qhta_Competitions_Util::display_datetime( $reminded ) );
		}
		if ( ! empty( $rec['confirmed_at'] ) ) {
			$row( __( 'Confirmed', 'qhta-competitions' ), Qhta_Competitions_Util::display_datetime( $rec['confirmed_at'] ) . ( 'manual' === ( $rec['method'] ?? '' ) ? ' — ' . sprintf( /* translators: 1: user, 2: note */ __( 'manually by %1$s: %2$s', 'qhta-competitions' ), $rec['admin_user'] ?? '', $rec['admin_note'] ?? '' ) : '' ) );
			if ( ! empty( $rec['typed_name'] ) ) {
				$row( __( 'Signed', 'qhta-competitions' ), $rec['typed_name'] . ' · IP ' . ( $rec['ip'] ?? '' ) );
			}
			if ( ! empty( $rec['declaration'] ) ) {
				$row( __( 'Declaration', 'qhta-competitions' ), '☑ ' . $rec['declaration'] );
			}
			if ( ! empty( $rec['comment'] ) ) {
				echo '<tr><th>' . esc_html__( 'Referee comment', 'qhta-competitions' ) . '</th><td><div class="qc-entry__long">' . nl2br( esc_html( $rec['comment'] ) ) . '</div></td></tr>';
			}
		}
		echo '</tbody></table>';

		if ( 'qc_pending_confirm' !== $post->post_status ) {
			return;
		}
		echo '<div class="qc-confirm-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="qc_confirm_resend"><input type="hidden" name="id" value="' . (int) $post->ID . '">';
		wp_nonce_field( 'qc_confirm_resend_' . $post->ID );
		submit_button( __( 'Re-send confirmation link', 'qhta-competitions' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Sends a new link; the old one stops working.', 'qhta-competitions' ) . '</span></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="qc_confirm_manual"><input type="hidden" name="id" value="' . (int) $post->ID . '">';
		wp_nonce_field( 'qc_confirm_manual_' . $post->ID );
		echo '<p><label for="qc_manual_note">' . esc_html__( 'Mark confirmed manually — note (required, e.g. "referee confirmed by phone 3 Jan")', 'qhta-competitions' ) . '</label><input type="text" id="qc_manual_note" name="note" class="widefat" required></p>';
		submit_button( __( 'Mark confirmed', 'qhta-competitions' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Membership override and "verified" ticks.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function box_checks( $post ) {
		$config = Qhta_Competitions_Program_CPT::get_config( $post->post_parent );
		$check  = Qhta_Competitions_Util::get_json_meta( $post->ID, Qhta_Competitions_Eligibility::MEMBER_META, null );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="qc_checks"><input type="hidden" name="id" value="' . (int) $post->ID . '">';
		wp_nonce_field( 'qc_checks_' . $post->ID );
		if ( is_array( $check ) ) {
			echo '<p><strong>' . esc_html__( 'QHTA member?', 'qhta-competitions' ) . '</strong> ' . esc_html( Qhta_Competitions_Eligibility::member_symbol( $post->ID ) ) . '<br><span class="description">' . esc_html( $check['note'] ) . '</span></p>';
			echo '<p><select name="member" aria-label="' . esc_attr__( 'Membership', 'qhta-competitions' ) . '">';
			foreach ( array( 'yes' => __( '✓ Member (verified)', 'qhta-competitions' ), 'no' => __( '✗ Not a member', 'qhta-competitions' ), 'unknown' => __( '? Unchecked', 'qhta-competitions' ) ) as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $check['status'], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></p>';
		}
		foreach ( self::verify_fields( $config ) as $f ) {
			$on = '1' === (string) get_post_meta( $post->ID, '_qc_verified_' . $f['key'], true );
			echo '<p><label><input type="checkbox" name="verified[' . esc_attr( $f['key'] ) . ']" value="1"' . checked( $on, true, false ) . '> ' . esc_html( sprintf( /* translators: %s: field label */ __( '%s verified', 'qhta-competitions' ), $f['label'] ) ) . '</label></p>';
		}
		submit_button( __( 'Save checks', 'qhta-competitions' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Save admin checks (logged).
	 */
	public static function handle_checks() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'qhta-competitions' ), 403 );
		}
		check_admin_referer( 'qc_checks_' . $id );
		$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );
		$user   = wp_get_current_user()->user_login;

		$check = Qhta_Competitions_Util::get_json_meta( $id, Qhta_Competitions_Eligibility::MEMBER_META, null );
		$new   = isset( $_POST['member'] ) ? sanitize_key( wp_unslash( $_POST['member'] ) ) : '';
		if ( is_array( $check ) && in_array( $new, array( 'yes', 'no', 'unknown' ), true ) && $new !== $check['status'] ) {
			$notes = array(
				'yes'     => __( 'Membership verified by admin.', 'qhta-competitions' ),
				'no'      => __( 'Not a member (admin).', 'qhta-competitions' ),
				'unknown' => __( 'Membership unchecked.', 'qhta-competitions' ),
			);
			Qhta_Competitions_Util::set_json_meta( $id, Qhta_Competitions_Eligibility::MEMBER_META, array_merge( $check, array( 'status' => $new, 'source' => 'admin', 'note' => $notes[ $new ], 'by' => $user, 'ts' => gmdate( Qhta_Competitions_Util::UTC_FORMAT ) ) ) );
			// Clear the membership flag once resolved.
			$flags = Qhta_Competitions_Util::get_json_meta( $id, '_qc_flags', array() );
			$flags = array_values( array_filter( (array) $flags, function ( $f ) { return 0 !== strpos( $f['code'], 'membership_' ); } ) );
			if ( 'yes' !== $new ) {
				$flags[] = array( 'code' => 'membership_' . $new, 'message' => $notes[ $new ] );
			}
			Qhta_Competitions_Util::set_json_meta( $id, '_qc_flags', $flags );
			/* translators: %s: status */
			Qhta_Competitions_Submission_CPT::append_log( $id, '', '', sprintf( __( 'Membership set to %s', 'qhta-competitions' ), $new ) );
		}

		$posted = isset( $_POST['verified'] ) && is_array( $_POST['verified'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['verified'] ) ) ) : array();
		foreach ( self::verify_fields( $config ) as $f ) {
			$was = '1' === (string) get_post_meta( $id, '_qc_verified_' . $f['key'], true );
			$now = in_array( $f['key'], $posted, true );
			if ( $was !== $now ) {
				update_post_meta( $id, '_qc_verified_' . $f['key'], $now ? '1' : '0' );
				/* translators: 1: field, 2: ticked/unticked */
				Qhta_Competitions_Submission_CPT::append_log( $id, '', '', sprintf( __( '%1$s %2$s', 'qhta-competitions' ), $f['label'], $now ? __( 'verified', 'qhta-competitions' ) : __( 'un-verified', 'qhta-competitions' ) ) );
			}
		}
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}
}
