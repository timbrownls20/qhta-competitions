<?php
/**
 * Outcome notifications (spec §5.8): preview + test-send to self, then a queue
 * sent by WP-Cron in batches of 20 (SureMail/MailChannels limits).
 *
 * Only real entries in a final status (awarded, commended, unsuccessful) are
 * notified, each at most once per status unless an admin chooses to re-send.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outcome emails.
 */
final class Qhta_Competitions_Outcomes {

	/** Statuses with an outcome email → event. */
	const EVENTS = array(
		'qc_awarded'      => 'outcome_awarded',
		'qc_commended'    => 'outcome_commended',
		'qc_unsuccessful' => 'outcome_unsuccessful',
	);

	/** Emails per cron batch. */
	const BATCH = 20;

	/** Seconds between batches. */
	const BATCH_GAP = 60;

	/** Option: queued submission IDs (autoload no). */
	const QUEUE_OPTION = 'qc_outcome_queue';

	/** Single cron event that sends a batch. */
	const BATCH_HOOK = 'qc_outcome_batch';

	/** Submission meta: {status, ts} of the last outcome email sent. */
	const NOTIFIED_META = '_qc_outcome_notified';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( Qhta_Competitions_Confirm::CRON, array( __CLASS__, 'kick' ) ); // Hourly safety net.
		add_filter( 'qc_email_tokens', array( __CLASS__, 'registration_token' ), 10, 3 );
	}

	/**
	 * Sort a selection into sendable and skipped (with reasons).
	 *
	 * @param int[] $ids    Submission IDs.
	 * @param bool  $resend Include entries already notified of their current status.
	 * @return array{send: array<string,int[]>, skipped: array<int,string>}
	 */
	public static function classify( array $ids, $resend = false ) {
		$send    = array();
		$skipped = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$status = get_post_status( $id );
			if ( Qhta_Competitions_Submission_CPT::POST_TYPE !== get_post_type( $id ) ) {
				continue;
			}
			if ( Qhta_Competitions_Submission_CPT::is_test( $id ) ) {
				$skipped[ $id ] = __( 'test entry', 'qhta-competitions' );
				continue;
			}
			if ( ! isset( self::EVENTS[ $status ] ) ) {
				$skipped[ $id ] = __( 'no final outcome yet', 'qhta-competitions' );
				continue;
			}
			$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );
			if ( empty( $config['emails'][ self::EVENTS[ $status ] ] ) ) {
				$skipped[ $id ] = __( 'the round has no template for this outcome', 'qhta-competitions' );
				continue;
			}
			if ( ! is_email( (string) get_post_meta( $id, '_qc_submitter_email', true ) ) ) {
				$skipped[ $id ] = __( 'no email address', 'qhta-competitions' );
				continue;
			}
			$done = Qhta_Competitions_Util::get_json_meta( $id, self::NOTIFIED_META, null );
			if ( ! $resend && is_array( $done ) && isset( $done['status'] ) && $done['status'] === $status ) {
				$skipped[ $id ] = __( 'already notified of this outcome', 'qhta-competitions' );
				continue;
			}
			$send[ $status ][] = $id;
		}
		return array(
			'send'    => $send,
			'skipped' => $skipped,
		);
	}

	/**
	 * Rendered {subject, body} for a submission's outcome email.
	 *
	 * @param int $id Submission ID.
	 * @return array|null
	 */
	public static function preview( $id ) {
		$status = get_post_status( $id );
		if ( ! isset( self::EVENTS[ $status ] ) ) {
			return null;
		}
		$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );
		$tpl    = isset( $config['emails'][ self::EVENTS[ $status ] ] ) ? $config['emails'][ self::EVENTS[ $status ] ] : null;
		if ( ! $tpl ) {
			return null;
		}
		$tokens = Qhta_Competitions_Mailer::submission_tokens( $id, $config );
		return array(
			'subject' => Qhta_Competitions_Mailer::render( $tpl['subject'], $tokens ),
			'body'    => Qhta_Competitions_Mailer::render( $tpl['body'], $tokens ),
		);
	}

	/**
	 * Send each outcome's preview (for the first entry with that outcome) to one address.
	 *
	 * @param array<string,int[]> $send From classify().
	 * @param string              $to   Recipient (the admin).
	 * @return int Emails sent.
	 */
	public static function send_test( array $send, $to ) {
		$n = 0;
		foreach ( $send as $ids ) {
			$p = self::preview( $ids[0] );
			if ( $p && Qhta_Competitions_Mailer::send( $to, '[PREVIEW] ' . $p['subject'], $p['body'] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Add IDs to the queue and start sending.
	 *
	 * @param int[] $ids Submission IDs.
	 * @return int Queue length.
	 */
	public static function enqueue( array $ids ) {
		$queue = self::queue();
		$queue = array_values( array_unique( array_merge( $queue, array_map( 'intval', $ids ) ) ) );
		Qhta_Competitions_Util::set_option( self::QUEUE_OPTION, $queue );
		self::kick();
		return count( $queue );
	}

	/**
	 * Queued IDs.
	 *
	 * @return int[]
	 */
	public static function queue() {
		$q = get_option( self::QUEUE_OPTION, array() );
		return is_array( $q ) ? array_map( 'intval', $q ) : array();
	}

	/**
	 * Schedule the next batch if work is waiting and none is scheduled.
	 */
	public static function kick() {
		if ( self::queue() && ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time(), self::BATCH_HOOK );
		}
	}

	/**
	 * Send one batch; reschedule while anything remains.
	 *
	 * @return int Emails sent in this batch.
	 */
	public static function run_batch() {
		$queue = self::queue();
		$batch = array_splice( $queue, 0, self::BATCH );
		Qhta_Competitions_Util::set_option( self::QUEUE_OPTION, $queue ); // Save first: a crash mid-batch never double-sends.
		$sent = 0;
		foreach ( $batch as $id ) {
			$status = get_post_status( $id );
			// Re-check at send time: status may have changed since it was queued.
			if ( ! isset( self::EVENTS[ $status ] ) || Qhta_Competitions_Submission_CPT::is_test( $id ) ) {
				continue;
			}
			$to = (string) get_post_meta( $id, '_qc_submitter_email', true );
			if ( Qhta_Competitions_Mailer::send_event( $id, self::EVENTS[ $status ], $to ) ) {
				Qhta_Competitions_Util::set_json_meta(
					$id,
					self::NOTIFIED_META,
					array(
						'status' => $status,
						'ts'     => gmdate( Qhta_Competitions_Util::UTC_FORMAT ),
					)
				);
				++$sent;
			}
		}
		if ( $queue ) {
			wp_schedule_single_event( time() + self::BATCH_GAP, self::BATCH_HOOK );
		}
		return $sent;
	}

	/**
	 * {{registration_instructions}} for bursary award emails.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $id     Submission ID.
	 * @param array $config Config.
	 * @return array
	 */
	public static function registration_token( $tokens, $id, $config ) {
		if ( 'bursary' === $config['type'] ) {
			$tokens['registration_instructions'] = __( 'QHTA will arrange your free conference registration and email you the details — you do not need to register or pay.', 'qhta-competitions' );
		}
		return $tokens;
	}
}
