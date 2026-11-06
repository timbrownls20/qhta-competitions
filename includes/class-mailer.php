<?php
/**
 * Mustache-lite templating and HTML + plain-text mail (spec §5.8).
 *
 * Templates (subject/body) come from the round's `_qc_emails` config:
 *   {{key}}            value (body is plain text; HTML is escaped afterwards)
 *   {{#key}}…{{/key}}  section shown when key is truthy
 *   {{^key}}…{{/key}}  section shown when key is empty
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mailer.
 */
final class Qhta_Competitions_Mailer {

	/** @var string|null Plain-text alternative for the mail being sent. */
	private static $alt_body = null;

	/**
	 * Render a template string.
	 *
	 * @param string $tpl  Template.
	 * @param array  $data Token values.
	 * @return string
	 */
	public static function render( $tpl, array $data ) {
		$tpl = (string) $tpl;
		// Sections (non-nested is enough for our templates; run twice for one level of nesting).
		for ( $i = 0; $i < 2; $i++ ) {
			$tpl = preg_replace_callback(
				'/\{\{([#^])\s*([a-z0-9_]+)\s*\}\}(.*?)\{\{\/\s*\2\s*\}\}/s',
				function ( $m ) use ( $data ) {
					$truthy = ! empty( $data[ $m[2] ] );
					return ( '#' === $m[1] ) === $truthy ? $m[3] : '';
				},
				$tpl
			);
		}
		return preg_replace_callback(
			'/\{\{\{?\s*([a-z0-9_]+)\s*\}?\}\}/',
			function ( $m ) use ( $data ) {
				if ( ! array_key_exists( $m[1], $data ) ) {
					return '';
				}
				$v = $data[ $m[1] ];
				return is_scalar( $v ) ? (string) $v : '';
			},
			$tpl
		);
	}

	/**
	 * Send a templated event email.
	 *
	 * @param string $to      Recipient.
	 * @param array  $tpl     {subject, body}.
	 * @param array  $data    Tokens.
	 * @param bool   $is_test Prefix the subject with [TEST].
	 * @return bool
	 */
	public static function send_template( $to, array $tpl, array $data, $is_test = false ) {
		if ( ! is_email( $to ) || empty( $tpl['subject'] ) || ! isset( $tpl['body'] ) ) {
			return false;
		}
		$subject = trim( preg_replace( '/[\r\n]+/', ' ', self::render( $tpl['subject'], $data ) ) );
		if ( $is_test ) {
			$subject = '[TEST] ' . $subject;
		}
		return self::send( $to, $subject, self::render( $tpl['body'], $data ) );
	}

	/**
	 * Send an HTML email with a plain-text alternative.
	 *
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $text    Plain-text body.
	 * @return bool
	 */
	public static function send( $to, $subject, $text ) {
		$html = self::html( $subject, $text );

		self::$alt_body = $text;
		add_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ) );
		$sent = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', array( __CLASS__, 'set_alt_body' ) );
		self::$alt_body = null;

		return (bool) $sent;
	}

	/**
	 * phpmailer_init: attach the plain-text alternative.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
	 */
	public static function set_alt_body( $mailer ) {
		if ( null !== self::$alt_body ) {
			$mailer->AltBody = self::$alt_body;
		}
	}

	/**
	 * Wrap a plain-text body in the QHTA HTML email template.
	 *
	 * @param string $subject Subject.
	 * @param string $text    Plain text.
	 * @return string
	 */
	public static function html( $subject, $text ) {
		$body_html = make_clickable( nl2br( esc_html( $text ) ) );
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		ob_start();
		include Qhta_Competitions_Util::template( 'email.php' );
		return (string) ob_get_clean();
	}

	/**
	 * Send a round's event template for a submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $event         Event key (§5.8).
	 * @param string $to            Recipient.
	 * @param array  $extra         Extra tokens (e.g. confirm_url).
	 * @return bool False when the round has no template for the event.
	 */
	public static function send_event( $submission_id, $event, $to, array $extra = array() ) {
		$program_id = (int) wp_get_post_parent_id( $submission_id );
		$config     = Qhta_Competitions_Program_CPT::get_config( $program_id );
		if ( empty( $config['emails'][ $event ] ) ) {
			return false;
		}
		$tokens = array_merge( self::submission_tokens( $submission_id, $config ), $extra );
		$sent   = self::send_template( $to, $config['emails'][ $event ], $tokens, Qhta_Competitions_Submission_CPT::is_test( $submission_id ) );
		Qhta_Competitions_Submission_CPT::append_log(
			$submission_id,
			'',
			'',
			/* translators: 1: event, 2: sent/failed */
			sprintf( __( 'Email %1$s %2$s', 'qhta-competitions' ), $event, $sent ? __( 'sent', 'qhta-competitions' ) : __( 'FAILED', 'qhta-competitions' ) )
		);
		return $sent;
	}

	/**
	 * Tokens for a submission: every stored field by key, plus ref, program,
	 * category, status, closes_at, admin_url, flags, site_name.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $config        Round config.
	 * @return array<string,string>
	 */
	public static function submission_tokens( $submission_id, array $config ) {
		$program_id = (int) wp_get_post_parent_id( $submission_id );
		$tokens     = array();
		foreach ( $config['fields'] as $f ) {
			if ( in_array( $f['type'], array( 'heading', 'help', 'file' ), true ) ) {
				continue;
			}
			$tokens[ $f['key'] ] = Qhta_Competitions_Submission_CPT::to_text( Qhta_Competitions_Submission_CPT::field( $submission_id, $f['key'] ) );
		}
		$cat      = Qhta_Competitions_Program_CPT::category( $config, (string) get_post_meta( $submission_id, '_qc_category', true ) );
		$statuses = Qhta_Competitions_Submission_CPT::statuses();
		$status   = get_post_status( $submission_id );
		$flags    = Qhta_Competitions_Util::get_json_meta( $submission_id, '_qc_flags', array() );

		return array_merge(
			$tokens,
			array(
				'ref'                       => (string) get_post_meta( $submission_id, Qhta_Competitions_Submission_CPT::REF_META, true ),
				'program'                   => get_the_title( $program_id ),
				'category'                  => $cat ? $cat['label'] : '',
				'status'                    => isset( $statuses[ $status ] ) ? $statuses[ $status ] : (string) $status,
				'closes_at'                 => Qhta_Competitions_Util::display_datetime( $config['closes_at'] ),
				'admin_url'                 => admin_url( 'post.php?post=' . (int) $submission_id . '&action=edit' ),
				'flags'                     => $flags ? implode( '; ', wp_list_pluck( $flags, 'message' ) ) : __( 'none', 'qhta-competitions' ),
				'site_name'                 => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'confirm_url'               => '',
				'confirm_expires'           => '',
				'registration_instructions' => '',
			)
		);
	}
}
