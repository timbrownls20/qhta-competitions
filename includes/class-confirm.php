<?php
/**
 * Confirmer (referee) token flow and confirmation reminders (spec §5.3, §5.8).
 *
 * The referee gets a single-use link /?qc_confirm={token}. The token is 32
 * random bytes; only its SHA-256 is stored. It expires 7 days after the round
 * closes (computed at check time, so moving the close date moves the expiry).
 * Re-sending or reminding issues a new token and retires the old one.
 *
 * Not gated by the round flag — confirmations keep working for real
 * submissions even if a round is later set off. Only the `competitions` kill
 * switch pauses them (the token stays valid).
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Referee confirmation.
 */
final class Qhta_Competitions_Confirm {

	/** Query var carrying the token. */
	const QUERY_ARG = 'qc_confirm';

	/** Submission meta: current token hash (lookup key). */
	const HASH_META = '_qc_confirm_hash';

	/** Submission meta (multiple): retired token hashes, for a helpful message. */
	const OLD_HASH_META = '_qc_confirm_old_hash';

	/** Submission meta: confirmation record. */
	const META = '_qc_confirmation';

	/** Submission meta: reminder sent (UTC). */
	const REMINDED_META = '_qc_reminder_sent';

	/** Days after close that links stay valid. */
	const EXPIRY_DAYS = 7;

	/** Days a link lasts when the round has no close date. */
	const FALLBACK_DAYS = 30;

	/** Reminders go out this long before close. */
	const REMINDER_WINDOW = 2 * DAY_IN_SECONDS;

	/** Cron hook. */
	const CRON = 'qc_cron';

	/** @var array|null Page state for the confirm template. */
	private static $page = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'qc_submission_created', array( __CLASS__, 'on_created' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 2 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'send_reminders' ) );
		add_action( 'admin_post_qc_confirm_resend', array( __CLASS__, 'handle_resend' ) );
		add_action( 'admin_post_qc_confirm_manual', array( __CLASS__, 'handle_manual' ) );
	}

	/**
	 * Hourly cron for reminders.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	/* ---------------------------------------------------------------------
	 * Tokens
	 * ------------------------------------------------------------------ */

	/**
	 * Hash of a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	/**
	 * Issue a fresh token for a submission, retiring any previous one.
	 *
	 * @param int $submission_id Submission ID.
	 * @return string The raw token (only ever in the email).
	 */
	public static function issue_token( $submission_id ) {
		$old = (string) get_post_meta( $submission_id, self::HASH_META, true );
		if ( '' !== $old ) {
			add_post_meta( $submission_id, self::OLD_HASH_META, $old );
		}
		$token = bin2hex( random_bytes( 32 ) );
		update_post_meta( $submission_id, self::HASH_META, self::hash( $token ) );
		return $token;
	}

	/**
	 * Confirmation link.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function url( $token ) {
		return add_query_arg( self::QUERY_ARG, $token, home_url( '/' ) );
	}

	/**
	 * Expiry timestamp for a submission's links.
	 *
	 * @param int $submission_id Submission ID.
	 * @return int
	 */
	public static function expires_at( $submission_id ) {
		$program = (int) wp_get_post_parent_id( $submission_id );
		$closes  = Qhta_Competitions_Util::utc_to_timestamp( (string) get_post_meta( $program, '_qc_closes_at', true ) );
		if ( $closes ) {
			return $closes + self::EXPIRY_DAYS * DAY_IN_SECONDS;
		}
		$rec  = self::record( $submission_id );
		$sent = isset( $rec['sent_at'] ) ? Qhta_Competitions_Util::utc_to_timestamp( $rec['sent_at'] ) : null;
		return ( $sent ? $sent : time() ) + self::FALLBACK_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Confirmation record.
	 *
	 * @param int $submission_id Submission ID.
	 * @return array
	 */
	public static function record( $submission_id ) {
		$rec = Qhta_Competitions_Util::get_json_meta( $submission_id, self::META, array() );
		return is_array( $rec ) ? $rec : array();
	}

	/**
	 * Merge into the confirmation record.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $changes       Keys to set.
	 */
	private static function update_record( $submission_id, array $changes ) {
		Qhta_Competitions_Util::set_json_meta( $submission_id, self::META, array_merge( self::record( $submission_id ), $changes ) );
	}

	/**
	 * Find a submission by token.
	 *
	 * @param string $token Token.
	 * @return array{0:int,1:string} [submission ID or 0, 'current'|'retired'|'none']
	 */
	public static function lookup( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return array( 0, 'none' );
		}
		$hash = self::hash( $token );
		foreach ( array( self::HASH_META => 'current', self::OLD_HASH_META => 'retired' ) as $key => $kind ) {
			$ids = get_posts(
				array(
					'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
					'post_status'    => array_keys( Qhta_Competitions_Submission_CPT::statuses() ),
					'meta_key'       => $key,
					'meta_value'     => $hash,
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( $ids ) {
				return array( (int) $ids[0], $kind );
			}
		}
		return array( 0, 'none' );
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * After a submission is created: record the confirmer and email them.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param int   $program_id    Program ID.
	 * @param array $config        Config.
	 */
	public static function on_created( $submission_id, $program_id, array $config ) {
		if ( ! Qhta_Competitions_Program_CPT::has_confirmer( $config ) ) {
			return;
		}
		$c     = $config['confirmer'];
		$email = (string) Qhta_Competitions_Submission_CPT::field( $submission_id, $c['email_field'] );
		$name  = '' !== $c['name_field'] ? (string) Qhta_Competitions_Submission_CPT::field( $submission_id, $c['name_field'] ) : '';
		self::update_record(
			$submission_id,
			array(
				'email'        => $email,
				'name'         => $name,
				'sent_at'      => '',
				'confirmed_at' => '',
				'ip'           => '',
			)
		);
		self::send( $submission_id, 'confirm_request' );
	}

	/**
	 * Issue a token and send a confirmer email (confirm_request or reminder_confirm).
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $event         Event.
	 * @return bool
	 */
	public static function send( $submission_id, $event ) {
		$rec = self::record( $submission_id );
		if ( empty( $rec['email'] ) || ! is_email( $rec['email'] ) ) {
			Qhta_Competitions_Submission_CPT::append_log( $submission_id, '', '', __( 'Confirmer email missing or invalid — link not sent', 'qhta-competitions' ) );
			return false;
		}
		$token = self::issue_token( $submission_id );
		self::update_record(
			$submission_id,
			array(
				'token_hash' => self::hash( $token ),
				'sent_at'    => gmdate( Qhta_Competitions_Util::UTC_FORMAT ),
			)
		);
		return Qhta_Competitions_Mailer::send_event(
			$submission_id,
			$event,
			$rec['email'],
			array(
				'confirm_url'     => self::url( $token ),
				'confirm_expires' => wp_date( 'j F Y, g:i a T', self::expires_at( $submission_id ) ),
			)
		);
	}

	/**
	 * Cron: remind confirmers of pending submissions in rounds closing within 48 hours. Once each.
	 *
	 * @return int Reminders sent.
	 */
	public static function send_reminders() {
		$now      = time();
		$programs = get_posts(
			array(
				'post_type'      => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_qc_closes_at',
						'value'   => array( gmdate( Qhta_Competitions_Util::UTC_FORMAT, $now ), gmdate( Qhta_Competitions_Util::UTC_FORMAT, $now + self::REMINDER_WINDOW ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATETIME',
					),
				),
			)
		);
		$sent = 0;
		foreach ( $programs as $program_id ) {
			$config = Qhta_Competitions_Program_CPT::get_config( $program_id );
			if ( ! Qhta_Competitions_Program_CPT::has_confirmer( $config ) || empty( $config['emails']['reminder_confirm'] ) ) {
				continue;
			}
			$subs = get_posts(
				array(
					'post_type'      => Qhta_Competitions_Submission_CPT::POST_TYPE,
					'post_status'    => 'qc_pending_confirm',
					'post_parent'    => $program_id,
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => self::REMINDED_META,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			);
			foreach ( $subs as $sid ) {
				update_post_meta( $sid, self::REMINDED_META, gmdate( Qhta_Competitions_Util::UTC_FORMAT ) );
				if ( self::send( $sid, 'reminder_confirm' ) ) {
					++$sent;
				}
			}
		}
		return $sent;
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * Handle GET/POST on /?qc_confirm=…
	 */
	public static function maybe_handle() {
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) { // Secret-token link; POST is nonce-checked below.
			return;
		}
		Qhta_Competitions_Form_Renderer::send_no_cache();
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) );

		if ( ! Qhta_Competitions_Flags::module_enabled() ) {
			self::$page = array( 'state' => 'unavailable' );
			return;
		}

		list( $id, $kind ) = self::lookup( $token );
		$state             = self::state( $id, $kind );
		self::$page        = array(
			'state'  => $state,
			'id'     => $id,
			'token'  => $token,
			'errors' => array(),
			'values' => array(),
		);

		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) && 'open' === $state ) {
			self::handle_post( $id, $token );
		}
	}

	/**
	 * What the link can do.
	 *
	 * @param int    $id   Submission ID.
	 * @param string $kind current|retired|none.
	 * @return string invalid|replaced|confirmed|closed|expired|open
	 */
	public static function state( $id, $kind ) {
		if ( ! $id ) {
			return 'invalid';
		}
		if ( 'retired' === $kind ) {
			return 'replaced';
		}
		$rec = self::record( $id );
		if ( ! empty( $rec['confirmed_at'] ) ) {
			return 'confirmed';
		}
		if ( 'qc_pending_confirm' !== get_post_status( $id ) ) {
			return 'closed';
		}
		if ( time() > self::expires_at( $id ) ) {
			return 'expired';
		}
		return 'open';
	}

	/**
	 * Process the referee's confirmation.
	 *
	 * @param int    $id    Submission ID.
	 * @param string $token Token.
	 */
	private static function handle_post( $id, $token ) {
		$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );
		$nonce  = isset( $_POST['_qc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_qc_nonce'] ) ) : '';
		$agree  = ! empty( $_POST['qc_agree'] );
		$name   = isset( $_POST['qc_signature'] ) ? sanitize_text_field( wp_unslash( $_POST['qc_signature'] ) ) : '';
		$note   = isset( $_POST['qc_comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qc_comment'] ) ) : '';

		self::$page['values'] = array(
			'signature' => $name,
			'comment'   => $note,
			'agree'     => $agree,
		);
		$errors = array();
		if ( ! wp_verify_nonce( $nonce, 'qc_confirm_' . self::hash( $token ) ) ) {
			$errors['_form'] = __( 'Your session expired. Please submit again.', 'qhta-competitions' );
		}
		if ( '' !== $config['confirmer']['declaration'] && ! $agree ) {
			$errors['agree'] = __( 'Please tick to confirm the declaration.', 'qhta-competitions' );
		}
		if ( '' === trim( $name ) ) {
			$errors['signature'] = __( 'Please type your full name as your signature.', 'qhta-competitions' );
		}
		if ( mb_strlen( $note ) > 5000 ) {
			$errors['comment'] = __( 'Please keep your comment under 5,000 characters.', 'qhta-competitions' );
		}
		if ( $errors ) {
			self::$page['errors'] = $errors;
			return;
		}

		self::complete(
			$id,
			array(
				'typed_name'  => $name,
				'comment'     => $note,
				'declaration' => $config['confirmer']['declaration'],
				'ip'          => Qhta_Competitions_Util::client_ip(),
				'user_agent'  => Qhta_Competitions_Util::user_agent(),
				'method'      => 'link',
			),
			__( 'Confirmed by referee', 'qhta-competitions' )
		);

		wp_safe_redirect( add_query_arg( 'qc_confirmed', '1', self::url( $token ) ), 303 );
		exit;
	}

	/**
	 * Mark confirmed: record, move to submitted, notify the submitter.
	 *
	 * @param int    $id      Submission ID.
	 * @param array  $details Record fields.
	 * @param string $note    Log note.
	 * @param bool   $force   Admin override.
	 * @return true|WP_Error
	 */
	public static function complete( $id, array $details, $note, $force = false ) {
		$res = Qhta_Competitions_Submission_CPT::transition( $id, 'qc_submitted', $note, $force );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::update_record( $id, array_merge( $details, array( 'confirmed_at' => gmdate( Qhta_Competitions_Util::UTC_FORMAT ) ) ) );
		$to = (string) get_post_meta( $id, '_qc_submitter_email', true );
		if ( '' !== $to ) {
			Qhta_Competitions_Mailer::send_event( $id, 'confirmed', $to );
		}
		return true;
	}

	/**
	 * Swap in our template for the confirmation page.
	 *
	 * @param string $template Template.
	 * @return string
	 */
	public static function template( $template ) {
		if ( null === self::$page ) {
			return $template;
		}
		status_header( 200 );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter(
			'pre_get_document_title',
			function () {
				return __( 'Confirm application', 'qhta-competitions' ) . ' — ' . get_bloginfo( 'name' );
			}
		);
		wp_enqueue_style( 'qc-form', QC_URL . 'assets/css/form.css', array(), QC_VERSION );
		return Qhta_Competitions_Util::template( 'confirm-page.php' );
	}

	/**
	 * Body HTML for the confirmation page (used by templates/confirm-page.php).
	 *
	 * @return string
	 */
	public static function page_html() {
		$p = self::$page;
		if ( ! $p ) {
			return '';
		}
		$msg = function ( $title, $text, $class = 'qc-form--message' ) {
			return '<div class="qc-form ' . esc_attr( $class ) . '"><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $text ) . '</p></div>';
		};
		switch ( $p['state'] ) {
			case 'unavailable':
				return $msg( __( 'Temporarily unavailable', 'qhta-competitions' ), __( 'Confirmations are temporarily unavailable. Your link is still valid — please try again later.', 'qhta-competitions' ) );
			case 'invalid':
				return $msg( __( 'Link not recognised', 'qhta-competitions' ), __( 'This confirmation link is not valid. Please use the link exactly as it appears in your email, or contact qhta@qhta.com.au.', 'qhta-competitions' ) );
			case 'replaced':
				return $msg( __( 'Link replaced', 'qhta-competitions' ), __( 'A newer confirmation link has been sent for this application. Please use the link in the most recent email.', 'qhta-competitions' ) );
			case 'confirmed':
				return ! empty( $_GET['qc_confirmed'] )
					? $msg( __( 'Thank you', 'qhta-competitions' ), __( 'You have confirmed this application. We have let the applicant know. You can close this page.', 'qhta-competitions' ), 'qc-form--done' )
					: $msg( __( 'Already confirmed', 'qhta-competitions' ), __( 'This application has already been confirmed, so this link can no longer be used. Thank you.', 'qhta-competitions' ) );
			case 'closed':
				return $msg( __( 'No longer needed', 'qhta-competitions' ), __( 'This application no longer needs your confirmation. Thank you.', 'qhta-competitions' ) );
			case 'expired':
				return $msg( __( 'Link expired', 'qhta-competitions' ), __( 'This confirmation link has expired. Please contact qhta@qhta.com.au if you still wish to confirm the application.', 'qhta-competitions' ) );
		}
		return self::form_html( $p );
	}

	/**
	 * The confirmation form with the summary of what the referee is vouching for.
	 *
	 * @param array $p Page state.
	 * @return string
	 */
	private static function form_html( array $p ) {
		$id      = $p['id'];
		$program = (int) wp_get_post_parent_id( $id );
		$config  = Qhta_Competitions_Program_CPT::get_config( $program );
		$c       = $config['confirmer'];
		$rec     = self::record( $id );
		$errors  = $p['errors'];
		$values  = $p['values'];
		$label   = '' !== $c['label'] ? $c['label'] : __( 'Referee', 'qhta-competitions' );
		$skip    = array( $c['email_field'], $c['name_field'] );
		$entrant = Qhta_Competitions_Program_CPT::field_by_role( $config, 'entrant_name' );
		$who     = $entrant ? Qhta_Competitions_Submission_CPT::to_text( Qhta_Competitions_Submission_CPT::field( $id, $entrant['key'] ) ) : '';

		$html  = '<div class="qc-form qc-confirm">';
		/* translators: %s: program */
		$html .= '<h2>' . esc_html( sprintf( __( 'Confirm an application: %s', 'qhta-competitions' ), get_the_title( $program ) ) ) . '</h2>';
		/* translators: 1: applicant, 2: confirmer label */
		$html .= '<p>' . esc_html( sprintf( __( '%1$s has named you as their %2$s. Please check the summary below and confirm.', 'qhta-competitions' ), '' !== $who ? $who : __( 'An applicant', 'qhta-competitions' ), strtolower( $label ) ) ) . '</p>';
		if ( Qhta_Competitions_Submission_CPT::is_test( $id ) ) {
			$html .= '<div class="qc-preview-banner" role="note"><strong>' . esc_html__( 'PREVIEW — test entry', 'qhta-competitions' ) . '</strong> ' . esc_html__( 'This confirmation is for a test application.', 'qhta-competitions' ) . '</div>';
		}
		if ( isset( $errors['_form'] ) ) {
			$html .= '<div class="qc-errors" role="alert"><p>' . esc_html( $errors['_form'] ) . '</p></div>';
		}

		$html .= '<dl class="qc-summary">';
		$html .= '<dt>' . esc_html__( 'Reference', 'qhta-competitions' ) . '</dt><dd>' . esc_html( (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true ) ) . '</dd>';
		foreach ( $config['fields'] as $f ) {
			// Summary only: no contact details, no files, not the referee's own fields.
			if ( in_array( $f['type'], array( 'heading', 'help', 'file', 'address', 'tel', 'email' ), true ) || in_array( $f['key'], $skip, true ) || ! metadata_exists( 'post', $id, '_qc_f_' . $f['key'] ) ) {
				continue;
			}
			$value = Qhta_Competitions_Submission_CPT::field( $id, $f['key'] );
			if ( 'checkbox' === $f['type'] ) {
				$text = '1' === $value ? __( 'Yes', 'qhta-competitions' ) : __( 'No', 'qhta-competitions' );
			} else {
				$text = Qhta_Competitions_Submission_CPT::to_text( $value );
				foreach ( $f['options'] as $o ) {
					if ( $o['value'] === $value ) {
						$text = $o['label'];
					}
				}
			}
			if ( '' === trim( $text ) ) {
				continue;
			}
			$label_text = ! empty( $f['label_if'] ) && (string) Qhta_Competitions_Submission_CPT::field( $id, $f['label_if']['field'] ) === (string) $f['label_if']['equals'] ? $f['label_if']['label'] : $f['label'];
			$html      .= '<dt>' . esc_html( $label_text ) . '</dt><dd>' . ( 'richtext_limited' === $f['type'] || 'textarea' === $f['type'] ? nl2br( esc_html( $text ) ) : esc_html( $text ) ) . '</dd>';
		}
		$html .= '</dl>';

		$html .= '<form method="post" action="' . esc_url( self::url( $p['token'] ) ) . '">';
		$html .= wp_nonce_field( 'qc_confirm_' . self::hash( $p['token'] ), '_qc_nonce', false, false );
		if ( '' !== $c['declaration'] ) {
			$html .= '<div class="qc-field' . ( isset( $errors['agree'] ) ? ' qc-field--error' : '' ) . '"><label class="qc-choice" for="qc_agree"><input type="checkbox" id="qc_agree" name="qc_agree" value="1" required' . checked( ! empty( $values['agree'] ), true, false ) . '> ' . esc_html( $c['declaration'] ) . ' <span class="qc-req" aria-hidden="true">*</span></label>';
			$html .= isset( $errors['agree'] ) ? '<p class="qc-error">' . esc_html( $errors['agree'] ) . '</p>' : '';
			$html .= '</div>';
		}
		$sig   = isset( $values['signature'] ) ? $values['signature'] : ( isset( $rec['name'] ) ? $rec['name'] : '' );
		$html .= '<div class="qc-field' . ( isset( $errors['signature'] ) ? ' qc-field--error' : '' ) . '"><label for="qc_signature">' . esc_html__( 'Type your full name as your signature', 'qhta-competitions' ) . ' <span class="qc-req" aria-hidden="true">*</span></label>';
		$html .= '<input type="text" id="qc_signature" name="qc_signature" value="' . esc_attr( $sig ) . '" required autocomplete="name">';
		$html .= isset( $errors['signature'] ) ? '<p class="qc-error">' . esc_html( $errors['signature'] ) . '</p>' : '';
		$html .= '</div>';
		$html .= '<div class="qc-field"><label for="qc_comment">' . esc_html__( 'Comment (optional)', 'qhta-competitions' ) . '</label>';
		$html .= '<textarea id="qc_comment" name="qc_comment" rows="5">' . esc_textarea( isset( $values['comment'] ) ? $values['comment'] : '' ) . '</textarea>';
		$html .= '<p class="qc-help">' . esc_html__( 'Anything you would like the selection committee to know about the applicant.', 'qhta-competitions' ) . '</p>';
		$html .= isset( $errors['comment'] ) ? '<p class="qc-error">' . esc_html( $errors['comment'] ) . '</p>' : '';
		$html .= '</div>';
		$html .= '<p class="qc-submit"><button type="submit" class="qc-button">' . esc_html__( 'Confirm application', 'qhta-competitions' ) . '</button></p>';
		$html .= '</form></div>';
		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Admin actions
	 * ------------------------------------------------------------------ */

	/**
	 * Resend the confirmation link (new token).
	 */
	public static function handle_resend() {
		$id = self::admin_guard( 'qc_confirm_resend' );
		$ok = self::send( $id, 'confirm_request' );
		Qhta_Competitions_Submission_CPT::append_log( $id, '', '', $ok ? __( 'Confirmation link re-sent by admin (previous link retired)', 'qhta-competitions' ) : __( 'Re-send of confirmation link FAILED', 'qhta-competitions' ) );
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}

	/**
	 * Mark confirmed manually (note required).
	 */
	public static function handle_manual() {
		$id   = self::admin_guard( 'qc_confirm_manual' );
		$note = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( '' === trim( $note ) ) {
			wp_die( esc_html__( 'A note is required to mark a confirmation manually.', 'qhta-competitions' ), '', array( 'back_link' => true ) );
		}
		$user = wp_get_current_user();
		$res  = self::complete(
			$id,
			array(
				'typed_name' => '',
				'comment'    => '',
				'method'     => 'manual',
				'admin_note' => $note,
				'admin_user' => $user->user_login,
				'ip'         => '',
			),
			/* translators: %s: note */
			sprintf( __( 'Marked confirmed manually: %s', 'qhta-competitions' ), $note )
		);
		if ( is_wp_error( $res ) ) {
			wp_die( esc_html( $res->get_error_message() ), '', array( 'back_link' => true ) );
		}
		wp_safe_redirect( get_edit_post_link( $id, 'raw' ) );
		exit;
	}

	/**
	 * Capability + nonce + state checks for admin actions.
	 *
	 * @param string $action Action.
	 * @return int Submission ID.
	 */
	private static function admin_guard( $action ) {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id || ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'qhta-competitions' ), 403 );
		}
		check_admin_referer( $action . '_' . $id );
		if ( 'qc_pending_confirm' !== get_post_status( $id ) ) {
			wp_die( esc_html__( 'This submission is not awaiting confirmation.', 'qhta-competitions' ), '', array( 'back_link' => true ) );
		}
		return $id;
	}
}
