<?php
/**
 * CSV exports (spec §5.10): all entries of a round, and the publication-consent
 * register. Admins only (judges never get exports). Test entries are never
 * exported. UTF-8 with BOM so Excel opens it cleanly; cells that Excel would
 * treat as formulas are neutralised.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports.
 */
final class Qhta_Competitions_Export {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_qc_export', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Download URL.
	 *
	 * @param int    $program_id Program ID.
	 * @param string $type       entries|consent.
	 * @param bool   $pii        Include personal information.
	 * @return string
	 */
	public static function url( $program_id, $type, $pii = false ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'qc_export',
					'program' => (int) $program_id,
					'type'    => $type,
					'pii'     => $pii ? 1 : 0,
				),
				admin_url( 'admin-post.php' )
			),
			'qc_export_' . (int) $program_id
		);
	}

	/**
	 * Stream a CSV.
	 */
	public static function handle() {
		$pid = isset( $_GET['program'] ) ? absint( $_GET['program'] ) : 0;
		if ( ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export.', 'qhta-competitions' ), 403 );
		}
		check_admin_referer( 'qc_export_' . $pid );
		if ( Qhta_Competitions_Program_CPT::POST_TYPE !== get_post_type( $pid ) ) {
			wp_die( esc_html__( 'Round not found.', 'qhta-competitions' ), 404 );
		}
		$type = isset( $_GET['type'] ) && 'consent' === $_GET['type'] ? 'consent' : 'entries';
		$pii  = ! empty( $_GET['pii'] );
		$rows = 'consent' === $type ? self::consent_rows( $pid ) : self::entry_rows( $pid, $pii );
		$name = sanitize_file_name( get_post_field( 'post_name', $pid ) . '-' . ( 'consent' === $type ? 'publication-consent' : ( $pii ? 'entries-with-personal-details' : 'entries' ) ) . '-' . wp_date( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		echo self::to_csv( $rows ); // CSV body, not HTML.
		exit;
	}

	/**
	 * Rows → CSV string with BOM and CRLF line endings.
	 *
	 * @param array[] $rows Rows (first row = headers).
	 * @return string
	 */
	public static function to_csv( array $rows ) {
		$fh = fopen( 'php://temp', 'w+' );
		foreach ( $rows as $row ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'cell' ), $row ), ',', '"', '' );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return "\xEF\xBB\xBF" . str_replace( "\n", "\r\n", str_replace( "\r\n", "\n", (string) $csv ) );
	}

	/**
	 * Neutralise spreadsheet formula injection (=, +, -, @, tab, CR at the start).
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function cell( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 'Yes' : 'No';
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		$v = (string) $v;
		return '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ? "'" . $v : $v;
	}

	/**
	 * Real submissions of a round.
	 *
	 * @param int      $pid      Program ID.
	 * @param string[] $statuses Statuses (default all).
	 * @return WP_Post[]
	 */
	private static function submissions( $pid, $statuses = null ) {
		return get_posts(
			array(
				'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
				'post_status'    => $statuses ? $statuses : array_keys( Qhta_Competitions_Submission_CPT::statuses() ),
				'post_parent'    => (int) $pid,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( Qhta_Competitions_Submission_CPT::real_entries_clause() ),
			)
		);
	}

	/**
	 * Display text for a field value.
	 *
	 * @param array $f  Field.
	 * @param int   $id Submission ID.
	 * @return string
	 */
	private static function field_text( array $f, $id ) {
		if ( ! metadata_exists( 'post', $id, '_qc_f_' . $f['key'] ) ) {
			return '';
		}
		$v = Qhta_Competitions_Submission_CPT::field( $id, $f['key'] );
		if ( 'checkbox' === $f['type'] ) {
			return '1' === $v ? 'Yes' : 'No';
		}
		foreach ( $f['options'] as $o ) {
			if ( $o['value'] === $v ) {
				return $o['label'];
			}
		}
		return Qhta_Competitions_Submission_CPT::to_text( $v );
	}

	/**
	 * Full export: fields, categories, word counts, flags, checks, declarations,
	 * referee, scores. PII columns only when asked for.
	 *
	 * @param int  $pid Program ID.
	 * @param bool $pii Include personal information.
	 * @return array[]
	 */
	public static function entry_rows( $pid, $pii ) {
		$config   = Qhta_Competitions_Program_CPT::get_config( $pid );
		$statuses = Qhta_Competitions_Submission_CPT::statuses();
		$fields   = array();
		foreach ( $config['fields'] as $f ) {
			if ( in_array( $f['type'], array( 'heading', 'help', 'file' ), true ) || ( $f['pii'] && ! $pii ) ) {
				continue;
			}
			$fields[] = $f;
		}
		$panel  = Qhta_Competitions_Judging::panel( $pid );
		$judges = array();
		foreach ( $panel as $ids ) {
			foreach ( $ids as $uid ) {
				$judges[ $uid ] = true;
			}
		}
		$judges = array_keys( $judges );

		// Results per category, computed once.
		$results = array();
		foreach ( $config['categories'] as $c ) {
			foreach ( Qhta_Competitions_Judging::results( $pid, $c['slug'] ) as $r ) {
				$results[ $r['id'] ] = $r;
			}
		}

		$head = array( 'Reference', 'Status', 'Category', 'Submitted' );
		foreach ( $fields as $f ) {
			$head[] = $f['label'];
		}
		$head = array_merge( $head, array( 'Files', 'Words (counted)', 'Words (declared)', 'Flags', 'Member?' ) );
		foreach ( $config['fields'] as $f ) {
			if ( ! empty( $f['admin_verify'] ) ) {
				$head[] = $f['label'] . ' verified';
			}
		}
		foreach ( $config['declarations'] as $d ) {
			$head[] = 'Declaration: ' . $d['key'];
		}
		$head[] = 'Publication consent';
		if ( $pii ) {
			$head = array_merge( $head, array( 'Signed (typed name)', 'Signed at', 'Signer IP' ) );
		}
		if ( Qhta_Competitions_Program_CPT::has_confirmer( $config ) ) {
			$head[] = 'Referee confirmed at';
			if ( $pii ) {
				$head = array_merge( $head, array( 'Referee confirmation name', 'Referee comment' ) );
			}
		}
		$head = array_merge( $head, array( 'Rank', 'Mean %', 'Judges scored', 'Spread warning' ) );
		foreach ( $judges as $uid ) {
			$u      = get_userdata( $uid );
			$head[] = 'Judge %: ' . ( $u ? $u->display_name : '#' . $uid );
		}
		$head[] = 'Outcome email sent';

		$rows = array( $head );
		foreach ( self::submissions( $pid ) as $p ) {
			$id   = $p->ID;
			$cat  = Qhta_Competitions_Program_CPT::category( $config, (string) get_post_meta( $id, '_qc_category', true ) );
			$row  = array(
				(string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true ),
				isset( $statuses[ $p->post_status ] ) ? $statuses[ $p->post_status ] : $p->post_status,
				$cat ? $cat['label'] : '',
				get_date_from_gmt( $p->post_date_gmt, 'Y-m-d H:i' ),
			);
			foreach ( $fields as $f ) {
				$row[] = self::field_text( $f, $id );
			}
			$files = Qhta_Competitions_Files::get( $id );
			$row[] = implode( ', ', wp_list_pluck( $files, 'ext' ) );
			$row[] = (string) get_post_meta( $id, '_qc_word_count_auto', true );
			$row[] = (string) get_post_meta( $id, '_qc_word_count_declared', true );
			$flags = Qhta_Competitions_Util::get_json_meta( $id, '_qc_flags', array() );
			$row[] = implode( '; ', wp_list_pluck( (array) $flags, 'message' ) );
			$row[] = Qhta_Competitions_Eligibility::member_symbol( $id );
			foreach ( $config['fields'] as $f ) {
				if ( ! empty( $f['admin_verify'] ) ) {
					$row[] = '1' === (string) get_post_meta( $id, '_qc_verified_' . $f['key'], true );
				}
			}
			$snap  = Qhta_Competitions_Util::get_json_meta( $id, '_qc_declarations_accepted', array() );
			$taken = array();
			foreach ( isset( $snap['items'] ) ? $snap['items'] : array() as $item ) {
				$taken[ $item['key'] ] = (bool) $item['accepted'];
			}
			foreach ( $config['declarations'] as $d ) {
				$row[] = ! empty( $taken[ $d['key'] ] );
			}
			$consent = Qhta_Competitions_Util::get_json_meta( $id, '_qc_publication_consent', array() );
			$row[]   = ! empty( $consent['given'] );
			if ( $pii ) {
				$row[] = isset( $snap['typed_name'] ) ? $snap['typed_name'] : '';
				$row[] = isset( $snap['ts'] ) && '' !== $snap['ts'] ? get_date_from_gmt( $snap['ts'], 'Y-m-d H:i' ) : '';
				$row[] = isset( $snap['ip'] ) ? $snap['ip'] : '';
			}
			if ( Qhta_Competitions_Program_CPT::has_confirmer( $config ) ) {
				$rec   = Qhta_Competitions_Confirm::record( $id );
				$row[] = ! empty( $rec['confirmed_at'] ) ? get_date_from_gmt( $rec['confirmed_at'], 'Y-m-d H:i' ) : '';
				if ( $pii ) {
					$row[] = isset( $rec['typed_name'] ) ? $rec['typed_name'] : '';
					$row[] = isset( $rec['comment'] ) ? $rec['comment'] : '';
				}
			}
			$r     = isset( $results[ $id ] ) ? $results[ $id ] : null;
			$row[] = $r && null !== $r['rank'] ? (int) $r['rank'] : '';
			$row[] = $r && null !== $r['mean'] ? round( $r['mean'], 2 ) : '';
			$row[] = $r ? $r['complete'] . '/' . $r['assigned'] : '';
			$row[] = $r ? (bool) $r['warn'] : '';
			foreach ( $judges as $uid ) {
				$j     = $r && isset( $r['judges'][ $uid ] ) ? $r['judges'][ $uid ] : null;
				$row[] = $j ? ( $j['conflict'] ? 'COI' : ( null === $j['pct'] ? '' : round( $j['pct'], 2 ) ) ) : '';
			}
			$sent  = Qhta_Competitions_Util::get_json_meta( $id, Qhta_Competitions_Outcomes::NOTIFIED_META, null );
			$row[] = is_array( $sent ) ? get_date_from_gmt( $sent['ts'], 'Y-m-d H:i' ) : '';
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Publication-consent register: awarded/commended entries with consent ✓.
	 *
	 * @param int $pid Program ID.
	 * @return array[]
	 */
	public static function consent_rows( $pid ) {
		$config   = Qhta_Competitions_Program_CPT::get_config( $pid );
		$statuses = Qhta_Competitions_Submission_CPT::statuses();
		$named    = array();
		foreach ( array( 'entrant_name', 'school' ) as $role ) {
			$f = Qhta_Competitions_Program_CPT::field_by_role( $config, $role );
			if ( $f ) {
				$named[] = $f;
			}
		}
		foreach ( $config['fields'] as $f ) {
			if ( 'year_level' === $f['type'] ) {
				$named[] = $f;
			}
		}
		$head = array( 'Reference', 'Outcome', 'Category' );
		foreach ( $named as $f ) {
			$head[] = $f['label'];
		}
		$head = array_merge( $head, array( 'Consent given', 'Consent text', 'Entry file', 'Submission (admin link)' ) );
		$rows = array( $head );
		foreach ( self::submissions( $pid, array( 'qc_awarded', 'qc_commended' ) ) as $p ) {
			$consent = Qhta_Competitions_Util::get_json_meta( $p->ID, '_qc_publication_consent', array() );
			if ( empty( $consent['given'] ) ) {
				continue;
			}
			$snap = Qhta_Competitions_Util::get_json_meta( $p->ID, '_qc_declarations_accepted', array() );
			$text = '';
			foreach ( isset( $snap['items'] ) ? $snap['items'] : array() as $item ) {
				if ( 'publication_consent' === $item['kind'] ) {
					$text = $item['text'];
				}
			}
			$cat   = Qhta_Competitions_Program_CPT::category( $config, (string) get_post_meta( $p->ID, '_qc_category', true ) );
			$ref   = (string) get_post_meta( $p->ID, Qhta_Competitions_Submission_CPT::REF_META, true );
			$files = Qhta_Competitions_Files::get( $p->ID );
			$row   = array( $ref, $statuses[ $p->post_status ], $cat ? $cat['label'] : '' );
			foreach ( $named as $f ) {
				$row[] = self::field_text( $f, $p->ID );
			}
			$row[]  = get_date_from_gmt( $consent['ts'], 'Y-m-d H:i' );
			$row[]  = $text;
			$row[]  = $files ? $ref . '.' . $files[0]['ext'] : '';
			$row[]  = admin_url( 'post.php?post=' . $p->ID . '&action=edit' );
			$rows[] = $row;
		}
		return $rows;
	}
}
