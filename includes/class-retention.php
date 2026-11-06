<?php
/**
 * Data retention and WordPress privacy tools (spec §7).
 *
 * `retention_months` after a round closes, every entry that wasn't awarded or
 * commended has its personal information and files removed: PII form fields,
 * uploaded files, the signer's typed name/IP/browser, the referee's details,
 * contact email and matching keys. Reference, status, category, non-PII
 * answers and scores stay, so the round's history still adds up. Awarded and
 * commended entries are kept for the publication record. Purges are logged,
 * irreversible and previewable.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retention.
 */
final class Qhta_Competitions_Retention {

	/** Submission meta: purge record {ts, reason}. */
	const PURGED_META = '_qc_purged';

	/** Statuses kept for the publication/history record. */
	const KEEP = array( 'qc_awarded', 'qc_commended' );

	/** Option: last daily run (Y-m-d, site time). */
	const LAST_RUN_OPTION = 'qc_retention_last_run';

	/** Max purges per cron run. */
	const PER_RUN = 200;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( Qhta_Competitions_Confirm::CRON, array( __CLASS__, 'maybe_run' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * When a round's purge is due.
	 *
	 * @param int $pid Program ID.
	 * @return int|null Timestamp, or null when the round has no close date.
	 */
	public static function due_at( $pid ) {
		$closes = Qhta_Competitions_Util::utc_to_timestamp( (string) get_post_meta( $pid, '_qc_closes_at', true ) );
		if ( ! $closes ) {
			return null;
		}
		$config = Qhta_Competitions_Program_CPT::get_config( $pid );
		$date   = ( new DateTimeImmutable( '@' . $closes ) )->setTimezone( wp_timezone() )->modify( '+' . max( 1, (int) $config['retention_months'] ) . ' months' );
		return $date->getTimestamp();
	}

	/**
	 * Entries of a round that the purge will touch (incl. test entries).
	 *
	 * @param int $pid Program ID.
	 * @return int[]
	 */
	public static function candidates( $pid ) {
		return get_posts(
			array(
				'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
				'post_status'    => array_values( array_diff( array_keys( Qhta_Competitions_Submission_CPT::statuses() ), self::KEEP ) ),
				'post_parent'    => (int) $pid,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => self::PURGED_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
	}

	/**
	 * Dry run for every round: due date and what would be purged.
	 *
	 * @return array[] {program, title, due, is_due, ids, kept}
	 */
	public static function preview() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => Qhta_Competitions_Program_CPT::POST_TYPE, 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => -1 ) ) as $p ) {
			$due   = self::due_at( $p->ID );
			$kept  = get_posts(
				array(
					'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
					'post_status'    => self::KEEP,
					'post_parent'    => $p->ID,
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			$out[] = array(
				'program' => $p->ID,
				'title'   => $p->post_title,
				'due'     => $due,
				'is_due'  => null !== $due && time() >= $due,
				'ids'     => self::candidates( $p->ID ),
				'kept'    => count( $kept ),
			);
		}
		return $out;
	}

	/**
	 * Run once a day from the hourly cron.
	 */
	public static function maybe_run() {
		$today = wp_date( 'Y-m-d' );
		if ( get_option( self::LAST_RUN_OPTION ) === $today ) {
			return;
		}
		Qhta_Competitions_Util::set_option( self::LAST_RUN_OPTION, $today );
		self::run();
	}

	/**
	 * Purge every due round.
	 *
	 * @return int Entries purged.
	 */
	public static function run() {
		$n = 0;
		foreach ( self::preview() as $round ) {
			if ( ! $round['is_due'] ) {
				continue;
			}
			foreach ( $round['ids'] as $id ) {
				if ( $n >= self::PER_RUN ) {
					return $n;
				}
				self::purge( $id, 'retention' );
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Remove a submission's personal information and files. Irreversible.
	 *
	 * @param int    $id     Submission ID.
	 * @param string $reason retention|privacy request|….
	 */
	public static function purge( $id, $reason ) {
		$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );

		foreach ( $config['fields'] as $f ) {
			if ( $f['pii'] ) {
				delete_post_meta( $id, '_qc_f_' . $f['key'] );
			}
		}
		Qhta_Competitions_Files::delete_all( $id );
		delete_post_meta( $id, Qhta_Competitions_Files::META );

		$snap = Qhta_Competitions_Util::get_json_meta( $id, '_qc_declarations_accepted', null );
		if ( is_array( $snap ) ) {
			$snap['typed_name'] = '';
			$snap['ip']         = '';
			$snap['user_agent'] = '';
			$snap['user_id']    = 0;
			Qhta_Competitions_Util::set_json_meta( $id, '_qc_declarations_accepted', $snap );
		}
		$rec = Qhta_Competitions_Confirm::record( $id );
		if ( $rec ) {
			foreach ( array( 'email', 'name', 'ip', 'user_agent', 'typed_name', 'comment' ) as $k ) {
				if ( isset( $rec[ $k ] ) ) {
					$rec[ $k ] = '';
				}
			}
			Qhta_Competitions_Util::set_json_meta( $id, Qhta_Competitions_Confirm::META, $rec );
		}
		$check = Qhta_Competitions_Util::get_json_meta( $id, Qhta_Competitions_Eligibility::MEMBER_META, null );
		if ( is_array( $check ) ) {
			unset( $check['user_id'] );
			Qhta_Competitions_Util::set_json_meta( $id, Qhta_Competitions_Eligibility::MEMBER_META, $check );
		}
		foreach ( array( '_qc_submitter_email', '_qc_norm_entrant', '_qc_norm_school' ) as $k ) {
			delete_post_meta( $id, $k );
		}
		wp_update_post(
			array(
				'ID'          => $id,
				'post_author' => 0,
			)
		);

		Qhta_Competitions_Util::set_json_meta(
			$id,
			self::PURGED_META,
			array(
				'ts'     => gmdate( Qhta_Competitions_Util::UTC_FORMAT ),
				'reason' => $reason,
			)
		);
		/* translators: %s: reason */
		Qhta_Competitions_Submission_CPT::append_log( $id, '', '', sprintf( __( 'Personal information and files purged (%s)', 'qhta-competitions' ), $reason ) );
	}

	/* ---------------------------------------------------------------------
	 * WordPress privacy exporter / eraser (keyed on email)
	 * ------------------------------------------------------------------ */

	/**
	 * Register exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['qhta-competitions'] = array(
			'exporter_friendly_name' => __( 'QHTA Competitions entries', 'qhta-competitions' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['qhta-competitions'] = array(
			'eraser_friendly_name' => __( 'QHTA Competitions entries', 'qhta-competitions' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Submissions linked to an email (case-insensitive): as submitter, or as referee only.
	 *
	 * @param string $email Email.
	 * @return array{0:int[],1:int[]} [as submitter, as referee only]
	 */
	public static function find_by_email( $email ) {
		global $wpdb;
		$email    = strtolower( trim( (string) $email ) );
		$statuses = array_keys( Qhta_Competitions_Submission_CPT::statuses() );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$base     = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = %s AND p.post_status IN ($in) AND m.meta_key = %s";

		$mine    = $wpdb->get_col( $wpdb->prepare( $base . ' AND LOWER(m.meta_value) = %s', array_merge( array( Qhta_Competitions_Submission_CPT::POST_TYPE ), $statuses, array( '_qc_submitter_email', $email ) ) ) );
		$referee = $wpdb->get_col( $wpdb->prepare( $base . ' AND LOWER(m.meta_value) LIKE %s', array_merge( array( Qhta_Competitions_Submission_CPT::POST_TYPE ), $statuses, array( Qhta_Competitions_Confirm::META, '%' . $wpdb->esc_like( '"email":"' . $email . '"' ) . '%' ) ) ) );

		$mine = array_map( 'intval', $mine );
		return array( $mine, array_values( array_diff( array_map( 'intval', $referee ), $mine ) ) );
	}

	/**
	 * Personal data export.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		list( $mine, $referee ) = self::find_by_email( $email );
		$items                  = array();
		foreach ( $mine as $id ) {
			$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );
			$data   = array(
				array( 'name' => __( 'Program', 'qhta-competitions' ), 'value' => get_the_title( wp_get_post_parent_id( $id ) ) ),
				array( 'name' => __( 'Reference', 'qhta-competitions' ), 'value' => (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true ) ),
				array( 'name' => __( 'Status', 'qhta-competitions' ), 'value' => (string) get_post_status( $id ) ),
			);
			foreach ( $config['fields'] as $f ) {
				if ( metadata_exists( 'post', $id, '_qc_f_' . $f['key'] ) ) {
					$data[] = array( 'name' => $f['label'], 'value' => Qhta_Competitions_Submission_CPT::to_text( Qhta_Competitions_Submission_CPT::field( $id, $f['key'] ) ) );
				}
			}
			$snap = Qhta_Competitions_Util::get_json_meta( $id, '_qc_declarations_accepted', array() );
			if ( ! empty( $snap['typed_name'] ) ) {
				$data[] = array( 'name' => __( 'Signed (typed name)', 'qhta-competitions' ), 'value' => $snap['typed_name'] . ' — ' . $snap['ts'] . ' UTC, IP ' . $snap['ip'] );
			}
			$items[] = array(
				'group_id'    => 'qhta-competitions',
				'group_label' => __( 'Competition entries and applications', 'qhta-competitions' ),
				'item_id'     => 'qc-submission-' . $id,
				'data'        => $data,
			);
		}
		foreach ( $referee as $id ) {
			$rec     = Qhta_Competitions_Confirm::record( $id );
			$items[] = array(
				'group_id'    => 'qhta-competitions-referee',
				'group_label' => __( 'Applications you confirmed as a referee', 'qhta-competitions' ),
				'item_id'     => 'qc-referee-' . $id,
				'data'        => array(
					array( 'name' => __( 'Reference', 'qhta-competitions' ), 'value' => (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true ) ),
					array( 'name' => __( 'Name', 'qhta-competitions' ), 'value' => isset( $rec['name'] ) ? $rec['name'] : '' ),
					array( 'name' => __( 'Comment', 'qhta-competitions' ), 'value' => isset( $rec['comment'] ) ? $rec['comment'] : '' ),
					array( 'name' => __( 'Confirmed at (UTC)', 'qhta-competitions' ), 'value' => isset( $rec['confirmed_at'] ) ? $rec['confirmed_at'] : '' ),
				),
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Personal data erasure. Awarded/commended entries are retained (publication
	 * record) and reported as such; everything else is purged.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		list( $mine, $referee ) = self::find_by_email( $email );
		$removed                = false;
		$retained               = false;
		$messages               = array();
		foreach ( $mine as $id ) {
			if ( in_array( get_post_status( $id ), self::KEEP, true ) ) {
				$retained   = true;
				/* translators: %s: reference */
				$messages[] = sprintf( __( '%s was awarded or commended and is kept for the publication record. Remove it by hand if needed.', 'qhta-competitions' ), get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true ) );
				continue;
			}
			self::purge( $id, 'privacy request' );
			$removed = true;
		}
		foreach ( $referee as $id ) {
			$rec = Qhta_Competitions_Confirm::record( $id );
			foreach ( array( 'email', 'name', 'ip', 'user_agent', 'typed_name', 'comment' ) as $k ) {
				$rec[ $k ] = '';
			}
			Qhta_Competitions_Util::set_json_meta( $id, Qhta_Competitions_Confirm::META, $rec );
			Qhta_Competitions_Submission_CPT::append_log( $id, '', '', __( 'Referee details erased (privacy request)', 'qhta-competitions' ) );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
