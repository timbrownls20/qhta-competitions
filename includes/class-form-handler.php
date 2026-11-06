<?php
/**
 * Form submission: validate → store files → create submission → snapshot
 * declarations → emails → redirect (spec §5.1, §5.2, §5.4, §5.6, §5.11).
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission handler.
 */
final class Qhta_Competitions_Form_Handler {

	/** Minimum seconds between render and submit (bots submit instantly). */
	const MIN_SECONDS = 4;

	/** Rendered forms older than this are refused (matches nonce life). */
	const MAX_AGE = DAY_IN_SECONDS;

	/** Upload word count may differ from the declared count by this fraction before it is flagged. */
	const WORD_MISMATCH = 0.10;

	/** @var array<int, array{values:array, errors:array}> Failed submission state per program. */
	private static $state = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 5 );
	}

	/**
	 * Nonce action for a program's form.
	 *
	 * @param int $program_id Program ID.
	 * @return string
	 */
	public static function nonce_action( $program_id ) {
		return 'qc_submit_' . (int) $program_id;
	}

	/**
	 * Signature over the render timestamp.
	 *
	 * @param int $ts         Timestamp.
	 * @param int $program_id Program ID.
	 * @return string
	 */
	public static function ts_sig( $ts, $program_id ) {
		return substr( hash_hmac( 'sha256', (int) $ts . '|' . (int) $program_id, wp_salt( 'nonce' ) ), 0, 16 );
	}

	/**
	 * State of a failed submission, for re-rendering.
	 *
	 * @param int $program_id Program ID.
	 * @return array|null
	 */
	public static function state( $program_id ) {
		return isset( self::$state[ $program_id ] ) ? self::$state[ $program_id ] : null;
	}

	/**
	 * Handle a POST from the form.
	 */
	public static function maybe_handle() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || empty( $_POST['qc_action'] ) || 'submit' !== $_POST['qc_action'] ) { // Nonce verified in handle().
			return;
		}
		$program_id = isset( $_POST['qc_program'] ) ? absint( $_POST['qc_program'] ) : 0;
		$program    = $program_id ? get_post( $program_id ) : null;
		if ( ! $program || Qhta_Competitions_Program_CPT::POST_TYPE !== $program->post_type ) {
			return;
		}
		Qhta_Competitions_Form_Renderer::send_no_cache();

		$result = self::handle( $program );
		if ( is_array( $result ) ) {
			self::$state[ $program_id ] = $result;
			return; // The page renders the form again with errors.
		}

		$return = isset( $_POST['qc_return'] ) ? esc_url_raw( wp_unslash( $_POST['qc_return'] ) ) : '';
		$return = wp_validate_redirect( $return, home_url( '/' ) );
		$url    = add_query_arg(
			array(
				'qc_done' => rawurlencode( $result ),
				'qc_sig'  => Qhta_Competitions_Form_Renderer::done_sig( $result, $program_id ),
				'qc_p'    => $program_id,
			),
			$return
		) . '#qc-form-' . $program_id;
		wp_safe_redirect( $url, 303 );
		exit;
	}

	/**
	 * Process a submission.
	 *
	 * @param WP_Post $program Program.
	 * @return string|array Reference code on success, or {values, errors}.
	 */
	public static function handle( $program ) {
		$config = Qhta_Competitions_Program_CPT::get_config( $program->ID );
		$values = self::read_values( $config );
		$errors = array();
		$fail   = function ( $message ) use ( &$values ) {
			return array(
				'values' => $values,
				'errors' => array( '_form' => array( $message ) ),
			);
		};

		// Request integrity.
		$nonce = isset( $_POST['_qc_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_qc_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $program->ID ) ) ) {
			return $fail( __( 'Your session expired before you submitted. Please check your details and submit again.', 'qhta-competitions' ) );
		}
		if ( ! empty( $_POST['qc_website'] ) ) {
			return $fail( __( 'Your submission could not be accepted. Please contact qhta@qhta.com.au.', 'qhta-competitions' ) );
		}
		$ts_raw = isset( $_POST['qc_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['qc_ts'] ) ) : '';
		$parts  = explode( '.', $ts_raw );
		$ts     = (int) $parts[0];
		if ( 2 !== count( $parts ) || ! hash_equals( self::ts_sig( $ts, $program->ID ), $parts[1] ) ) {
			return $fail( __( 'Your submission could not be accepted. Please reload the page and try again.', 'qhta-competitions' ) );
		}
		$age = time() - $ts;
		if ( $age < (int) apply_filters( 'qc_min_seconds_on_page', self::MIN_SECONDS ) ) {
			return $fail( __( 'That was very quick! Please check your entry and submit again.', 'qhta-competitions' ) );
		}
		if ( $age > self::MAX_AGE ) {
			return $fail( __( 'This form has been open too long. Please check your details and submit again.', 'qhta-competitions' ) );
		}

		// Visibility: flags, then dates (bypassed in preview).
		$gate = Qhta_Competitions_Flags::round_gate( $program->ID );
		if ( Qhta_Competitions_Flags::GATE_KILLED === $gate ) {
			return $fail( __( 'Submissions are temporarily unavailable. Please try again later.', 'qhta-competitions' ) );
		}
		if ( ! in_array( $gate, array( Qhta_Competitions_Flags::GATE_LIVE, Qhta_Competitions_Flags::GATE_PREVIEW ), true ) ) {
			return $fail( __( 'This form is not accepting submissions.', 'qhta-competitions' ) );
		}
		$is_test = Qhta_Competitions_Flags::GATE_PREVIEW === $gate;
		if ( ! $is_test ) {
			$window = Qhta_Competitions_Program_CPT::window( $config );
			if ( 'open' !== $window ) {
				return $fail( 'before' === $window ? __( 'Submissions have not opened yet.', 'qhta-competitions' ) : __( 'Sorry — submissions for this round have closed.', 'qhta-competitions' ) );
			}
		}

		// Content.
		$category = self::validate_category( $config, $values, $errors );
		$visible  = self::visible_fields( $config, $values, $category );
		$uploads  = array();
		$counts   = array();
		self::validate_fields( $config, $values, $visible, $category, $errors, $uploads, $counts );
		self::validate_declarations( $config, $values, $errors );

		/**
		 * Extra validation (eligibility rules arrive in M3). Add errors keyed by field key or '_form'.
		 *
		 * @param array $errors  Errors.
		 * @param array $values  Values.
		 * @param array $config  Config.
		 * @param bool  $is_test Test entry.
		 */
		$errors = apply_filters( 'qc_validate_submission', $errors, $values, $config, $program->ID, $is_test );

		if ( $errors ) {
			if ( $uploads ) {
				$errors['_files_lost'] = true;
			}
			return array(
				'values' => $values,
				'errors' => $errors,
			);
		}

		// Files first: no submission without its files.
		$files = array();
		foreach ( $uploads as $key => $up ) {
			$rel = Qhta_Competitions_Files::store( $up['file'], $up['ext'], $program->ID );
			if ( is_wp_error( $rel ) ) {
				foreach ( $files as $stored ) {
					wp_delete_file( Qhta_Competitions_Files::abs_path( $stored['path'] ) );
				}
				return $fail( $rel->get_error_message() );
			}
			$files[] = array(
				'field'         => $key,
				'path'          => $rel,
				'ext'           => $up['ext'],
				'mime'          => $up['mime'],
				'size'          => (int) $up['file']['size'],
				'original_name' => sanitize_file_name( (string) $up['file']['name'] ),
			);
		}

		$submission_id = self::create( $program, $config, $values, $visible, $category, $files, $counts, $is_test );
		if ( is_wp_error( $submission_id ) ) {
			foreach ( $files as $stored ) {
				wp_delete_file( Qhta_Competitions_Files::abs_path( $stored['path'] ) );
			}
			return $fail( __( 'Your submission could not be saved. Please try again, or contact qhta@qhta.com.au.', 'qhta-competitions' ) );
		}

		self::send_emails( $submission_id, $config );

		/**
		 * A submission was created (M3 hooks the referee email here).
		 *
		 * @param int   $submission_id Submission ID.
		 * @param int   $program_id    Program ID.
		 * @param array $config        Config.
		 */
		do_action( 'qc_submission_created', $submission_id, $program->ID, $config );

		return (string) get_post_meta( $submission_id, Qhta_Competitions_Submission_CPT::REF_META, true );
	}

	/* ---------------------------------------------------------------------
	 * Reading and validating
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitised values from the POST (sticky on error).
	 *
	 * @param array $config Config.
	 * @return array
	 */
	private static function read_values( array $config ) {
		// Nonce checked by handle() before anything is stored.
		$in     = isset( $_POST['qc_f'] ) && is_array( $_POST['qc_f'] ) ? wp_unslash( $_POST['qc_f'] ) : array();
		$values = array();
		foreach ( $config['fields'] as $f ) {
			$key = $f['key'];
			$raw = isset( $in[ $key ] ) ? $in[ $key ] : '';
			switch ( $f['type'] ) {
				case 'heading':
				case 'help':
				case 'file':
					break;
				case 'email':
					$values[ $key ] = is_string( $raw ) ? sanitize_email( trim( $raw ) ) : '';
					break;
				case 'textarea':
				case 'richtext_limited':
					$values[ $key ] = is_string( $raw ) ? sanitize_textarea_field( $raw ) : '';
					break;
				case 'checkbox':
					$values[ $key ] = '1' === $raw ? '1' : '';
					break;
				case 'address':
					$a              = is_array( $raw ) ? $raw : array();
					$values[ $key ] = array();
					foreach ( array( 'line1', 'line2', 'suburb', 'state', 'postcode' ) as $part ) {
						$values[ $key ][ $part ] = isset( $a[ $part ] ) && is_string( $a[ $part ] ) ? sanitize_text_field( $a[ $part ] ) : '';
					}
					if ( ! in_array( $values[ $key ]['state'], Qhta_Competitions_Form_Renderer::STATES, true ) ) {
						$values[ $key ]['state'] = '';
					}
					break;
				default:
					$values[ $key ] = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
			}
		}
		$values['_category'] = isset( $_POST['qc_category'] ) ? sanitize_key( wp_unslash( $_POST['qc_category'] ) ) : '';
		$decl                = isset( $_POST['qc_decl'] ) && is_array( $_POST['qc_decl'] ) ? wp_unslash( $_POST['qc_decl'] ) : array();
		$values['_declarations'] = array();
		foreach ( $config['declarations'] as $d ) {
			$values['_declarations'][ $d['key'] ] = isset( $decl[ $d['key'] ] ) && '1' === $decl[ $d['key'] ];
		}
		$values['_signature'] = isset( $_POST['qc_signature'] ) ? sanitize_text_field( wp_unslash( $_POST['qc_signature'] ) ) : '';
		return $values;
	}

	/**
	 * Category choice and year-level match.
	 *
	 * @param array $config Config.
	 * @param array $values Values.
	 * @param array $errors Errors (by ref).
	 * @return array|null Chosen category.
	 */
	private static function validate_category( array $config, array $values, array &$errors ) {
		if ( ! $config['categories'] ) {
			return null;
		}
		$cat = Qhta_Competitions_Program_CPT::category( $config, $values['_category'] );
		if ( ! $cat ) {
			$errors['_category'] = __( 'Please choose a category.', 'qhta-competitions' );
			return null;
		}
		if ( $cat['year_levels'] ) {
			foreach ( $config['fields'] as $f ) {
				if ( 'year_level' === $f['type'] && '' !== $values[ $f['key'] ] && ! in_array( (string) $values[ $f['key'] ], $cat['year_levels'], true ) ) {
					/* translators: 1: category, 2: year level */
					$errors['_category'] = sprintf( __( '%1$s is not open to Year %2$s. Please choose a category for this year level.', 'qhta-competitions' ), $cat['label'], $values[ $f['key'] ] );
				}
			}
		}
		return $cat;
	}

	/**
	 * Keys of fields that apply to this submission: show_if satisfied (recursively)
	 * and, for inline/upload fields, allowed by the category's submission kind.
	 *
	 * @param array      $config   Config.
	 * @param array      $values   Values.
	 * @param array|null $category Chosen category.
	 * @return array<string,true>
	 */
	public static function visible_fields( array $config, array $values, $category ) {
		$by_key = array();
		foreach ( $config['fields'] as $f ) {
			$by_key[ $f['key'] ] = $f;
		}
		$kind    = $category ? $category['submission_kind'] : 'both';
		$memo    = array();
		$visible = function ( $key, $depth = 0 ) use ( &$visible, &$memo, $by_key, $values, $kind ) {
			if ( isset( $memo[ $key ] ) ) {
				return $memo[ $key ];
			}
			if ( ! isset( $by_key[ $key ] ) || $depth > 10 ) {
				return false;
			}
			$f  = $by_key[ $key ];
			$ok = true;
			if ( 'richtext_limited' === $f['type'] && 'upload' === $kind ) {
				$ok = false;
			}
			if ( 'file' === $f['type'] && 'inline' === $kind ) {
				$ok = false;
			}
			if ( $ok && ! empty( $f['show_if'] ) ) {
				$dep = $f['show_if']['field'];
				$ok  = $visible( $dep, $depth + 1 ) && isset( $values[ $dep ] ) && is_string( $values[ $dep ] ) && $values[ $dep ] === (string) $f['show_if']['equals'];
			}
			$memo[ $key ] = $ok;
			return $ok;
		};
		$out = array();
		foreach ( $config['fields'] as $f ) {
			if ( ! in_array( $f['type'], Qhta_Competitions_Program_CPT::DISPLAY_ONLY_TYPES, true ) && $visible( $f['key'] ) ) {
				$out[ $f['key'] ] = true;
			}
		}
		return $out;
	}

	/**
	 * Per-field validation, uploads and word counts.
	 *
	 * @param array      $config   Config.
	 * @param array      $values   Values.
	 * @param array      $visible  Visible keys.
	 * @param array|null $category Category.
	 * @param array      $errors   Errors (by ref).
	 * @param array      $uploads  Validated uploads (by ref): key => {file, ext, mime}.
	 * @param array      $counts   Word counts (by ref): inline / docx results.
	 */
	private static function validate_fields( array $config, array $values, array $visible, $category, array &$errors, array &$uploads, array &$counts ) {
		$required = __( 'This field is required.', 'qhta-competitions' );
		$kind     = $category ? $category['submission_kind'] : 'both';
		$provided = array(
			'inline' => false,
			'upload' => false,
		);

		foreach ( $config['fields'] as $f ) {
			$key = $f['key'];
			if ( ! isset( $visible[ $key ] ) ) {
				continue;
			}
			$val = isset( $values[ $key ] ) ? $values[ $key ] : '';
			// With kind "both", either the inline piece or the file will do (checked below).
			$req = $f['required'] && ! ( 'both' === $kind && in_array( $f['type'], array( 'file', 'richtext_limited' ), true ) );

			switch ( $f['type'] ) {
				case 'address':
					if ( $req && ( '' === $val['line1'] || '' === $val['suburb'] || '' === $val['postcode'] ) ) {
						$errors[ $key ] = __( 'Please enter the street address, suburb and postcode.', 'qhta-competitions' );
					} elseif ( '' !== $val['postcode'] && ! preg_match( '/^\d{4}$/', $val['postcode'] ) ) {
						$errors[ $key ] = __( 'Postcode must be 4 digits.', 'qhta-competitions' );
					}
					break;

				case 'file':
					$file = isset( $_FILES[ 'qc_file_' . $key ] ) && is_array( $_FILES[ 'qc_file_' . $key ] ) ? $_FILES[ 'qc_file_' . $key ] : null; // Validated by Files::validate_upload().
					if ( ! $file || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
						if ( $req ) {
							$errors[ $key ] = __( 'Please attach your entry (.docx or .pdf).', 'qhta-competitions' );
						}
						break;
					}
					$ok = Qhta_Competitions_Files::validate_upload( $file, (int) $config['max_upload_mb'] );
					if ( is_wp_error( $ok ) ) {
						$errors[ $key ] = $ok->get_error_message();
						break;
					}
					$uploads[ $key ]    = array_merge( $ok, array( 'file' => $file ) );
					$provided['upload'] = true;
					if ( 'docx' === $ok['ext'] ) {
						$count = Qhta_Competitions_Wordcount::count_docx( $file['tmp_name'], $config['word_count_rules'] );
						if ( ! is_wp_error( $count ) ) {
							$counts['docx'] = $count;
						}
					}
					break;

				case 'richtext_limited':
					if ( '' === trim( $val ) ) {
						if ( $req ) {
							$errors[ $key ] = $required;
						}
						break;
					}
					$provided['inline'] = true;
					$n                  = Qhta_Competitions_Wordcount::count_text( $val );
					$counts['inline']   = $n;
					if ( $category && null !== $category['word_max'] && $n > $category['word_max'] ) {
						/* translators: 1: words, 2: limit */
						$errors[ $key ] = sprintf( __( 'This is %1$s words; the limit is %2$s. Please shorten it.', 'qhta-competitions' ), number_format_i18n( $n ), number_format_i18n( $category['word_max'] ) );
					} elseif ( $category && null !== $category['word_min'] && $n < $category['word_min'] ) {
						/* translators: 1: words, 2: minimum */
						$errors[ $key ] = sprintf( __( 'This is %1$s words; the minimum is %2$s.', 'qhta-competitions' ), number_format_i18n( $n ), number_format_i18n( $category['word_min'] ) );
					}
					break;

				default:
					if ( $req && '' === trim( (string) $val ) ) {
						$errors[ $key ] = $required;
						break;
					}
					if ( '' === (string) $val ) {
						break;
					}
					if ( 'email' === $f['type'] && ! is_email( $val ) ) {
						$errors[ $key ] = __( 'Please enter a valid email address.', 'qhta-competitions' );
					} elseif ( in_array( $f['type'], array( 'select', 'radio', 'year_level' ), true ) && $f['options'] && ! in_array( $val, wp_list_pluck( $f['options'], 'value' ), true ) ) {
						$errors[ $key ] = __( 'Please choose one of the options.', 'qhta-competitions' );
					} elseif ( 'word_count_declared' === $key && ! preg_match( '/^\d{1,6}$/', $val ) ) {
						$errors[ $key ] = __( 'Please enter the word count as a number.', 'qhta-competitions' );
					} elseif ( 'textarea' !== $f['type'] && mb_strlen( (string) $val ) > 200 ) {
						$errors[ $key ] = __( 'That is too long.', 'qhta-competitions' );
					}
			}
		}

		if ( 'both' === $kind && $category && ! $provided['inline'] && ! $provided['upload'] ) {
			$errors['_form'] = array( __( 'Please either write your piece in the box or attach a file.', 'qhta-competitions' ) );
		}
	}

	/**
	 * Declarations and typed-name signature.
	 *
	 * @param array $config Config.
	 * @param array $values Values.
	 * @param array $errors Errors (by ref).
	 */
	private static function validate_declarations( array $config, array $values, array &$errors ) {
		if ( ! $config['declarations'] ) {
			return;
		}
		foreach ( $config['declarations'] as $d ) {
			if ( $d['required'] && empty( $values['_declarations'][ $d['key'] ] ) ) {
				$errors[ '_decl_' . $d['key'] ] = __( 'Please tick to agree to this declaration.', 'qhta-competitions' );
			}
		}
		if ( '' === trim( $values['_signature'] ) ) {
			$errors['_signature'] = __( 'Please type your full name as your signature.', 'qhta-competitions' );
		} elseif ( mb_strlen( $values['_signature'] ) > 200 ) {
			$errors['_signature'] = __( 'That is too long.', 'qhta-competitions' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Creating
	 * ------------------------------------------------------------------ */

	/**
	 * Create the submission post and its meta.
	 *
	 * @param WP_Post    $program  Program.
	 * @param array      $config   Config.
	 * @param array      $values   Values.
	 * @param array      $visible  Visible keys.
	 * @param array|null $category Category.
	 * @param array      $files    Stored files.
	 * @param array      $counts   Word counts.
	 * @param bool       $is_test  Test entry.
	 * @return int|WP_Error
	 */
	private static function create( $program, array $config, array $values, array $visible, $category, array $files, array $counts, $is_test ) {
		$ref    = Qhta_Competitions_Submission_CPT::next_ref( $program->ID, $is_test );
		$status = Qhta_Competitions_Program_CPT::has_confirmer( $config ) ? 'qc_pending_confirm' : 'qc_submitted';

		Qhta_Competitions_Submission_CPT::set_pending_note( $is_test ? __( 'Test entry submitted via preview', 'qhta-competitions' ) : __( 'Submitted via form', 'qhta-competitions' ) );
		$id = wp_insert_post(
			array(
				'post_type'   => Qhta_Competitions_Submission_CPT::POST_TYPE,
				'post_status' => $status,
				'post_parent' => $program->ID,
				'post_title'  => $program->post_title . ' — ' . $ref,
				'post_author' => get_current_user_id(),
			),
			true
		);
		Qhta_Competitions_Submission_CPT::set_pending_note( null );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, $ref );
		if ( $is_test ) {
			update_post_meta( $id, Qhta_Competitions_Submission_CPT::TEST_META, '1' );
		}
		update_post_meta( $id, '_qc_category', $category ? $category['slug'] : '' );

		foreach ( $config['fields'] as $f ) {
			if ( ! isset( $visible[ $f['key'] ] ) || 'file' === $f['type'] ) {
				continue;
			}
			$v = $values[ $f['key'] ];
			if ( is_array( $v ) ) {
				Qhta_Competitions_Util::set_json_meta( $id, '_qc_f_' . $f['key'], $v );
			} else {
				update_post_meta( $id, '_qc_f_' . $f['key'], wp_slash( $v ) );
			}
		}

		// Normalised entrant/school for entry-limit matching.
		foreach ( array( 'entrant_name' => '_qc_norm_entrant', 'school' => '_qc_norm_school' ) as $role => $meta_key ) {
			$rf = Qhta_Competitions_Program_CPT::field_by_role( $config, $role );
			update_post_meta( $id, $meta_key, $rf && isset( $values[ $rf['key'] ] ) && is_string( $values[ $rf['key'] ] ) ? Qhta_Competitions_Eligibility::normalise( $values[ $rf['key'] ] ) : '' );
		}

		$email_key = Qhta_Competitions_Program_CPT::submitter_email_key( $config );
		update_post_meta( $id, '_qc_submitter_email', '' !== $email_key && isset( $values[ $email_key ] ) ? $values[ $email_key ] : '' );

		if ( $files ) {
			Qhta_Competitions_Util::set_json_meta( $id, Qhta_Competitions_Files::META, $files );
		}

		// Word counts.
		$declared = isset( $visible['word_count_declared'], $values['word_count_declared'] ) && '' !== $values['word_count_declared'] ? (int) $values['word_count_declared'] : null;
		update_post_meta( $id, '_qc_word_count_declared', null === $declared ? '' : (string) $declared );
		$auto      = null;
		$breakdown = null;
		if ( isset( $counts['inline'] ) ) {
			$auto      = $counts['inline'];
			$breakdown = array(
				'source'   => 'inline',
				'body'     => $auto,
				'notes'    => 0,
				'excluded' => 0,
			);
		} elseif ( isset( $counts['docx'] ) ) {
			$auto      = $counts['docx']['total'];
			$breakdown = array_merge( array( 'source' => 'docx' ), $counts['docx'] );
		}
		update_post_meta( $id, '_qc_word_count_auto', null === $auto ? '' : (string) $auto );
		if ( $breakdown ) {
			Qhta_Competitions_Util::set_json_meta( $id, '_qc_word_count_breakdown', $breakdown );
		}

		$flags = self::word_count_flags( $category, $declared, $auto, isset( $counts['inline'] ) );
		Qhta_Competitions_Util::set_json_meta( $id, '_qc_flags', apply_filters( 'qc_submission_flags', $flags, $id, $values, $config ) );

		// Declarations snapshot: the exact text shown, the typed name, when, where from.
		$now   = gmdate( Qhta_Competitions_Util::UTC_FORMAT );
		$items = array();
		$consent = false;
		foreach ( $config['declarations'] as $d ) {
			$accepted = ! empty( $values['_declarations'][ $d['key'] ] );
			$items[]  = array(
				'key'      => $d['key'],
				'text'     => $d['text'],
				'required' => $d['required'],
				'kind'     => isset( $d['kind'] ) ? $d['kind'] : '',
				'accepted' => $accepted,
			);
			if ( isset( $d['kind'] ) && 'publication_consent' === $d['kind'] && $accepted ) {
				$consent = true;
			}
		}
		Qhta_Competitions_Util::set_json_meta(
			$id,
			'_qc_declarations_accepted',
			array(
				'items'      => $items,
				'typed_name' => $values['_signature'],
				'ts'         => $now,
				'ip'         => Qhta_Competitions_Util::client_ip(),
				'user_agent' => Qhta_Competitions_Util::user_agent(),
				'user_id'    => get_current_user_id(),
			)
		);
		Qhta_Competitions_Util::set_json_meta(
			$id,
			'_qc_publication_consent',
			array(
				'given' => $consent,
				'ts'    => $consent ? $now : '',
			)
		);

		return $id;
	}

	/**
	 * Word-count flags (never blocks an upload; admins see both numbers).
	 *
	 * @param array|null $category Category.
	 * @param int|null   $declared Declared count.
	 * @param int|null   $auto     Auto count.
	 * @param bool       $inline   Inline piece (already blocked if out of range).
	 * @return array<int, array{code:string, message:string}>
	 */
	public static function word_count_flags( $category, $declared, $auto, $inline ) {
		$flags = array();
		if ( $inline ) {
			return $flags;
		}
		if ( null !== $auto && null !== $declared && abs( $auto - $declared ) > self::WORD_MISMATCH * max( $declared, 1 ) ) {
			$flags[] = array(
				'code'    => 'word_count_mismatch',
				/* translators: 1: declared, 2: auto */
				'message' => sprintf( __( 'Declared %1$s words; the document counts %2$s.', 'qhta-competitions' ), number_format_i18n( $declared ), number_format_i18n( $auto ) ),
			);
		}
		$measure = null !== $auto ? $auto : $declared;
		if ( $category && null !== $measure ) {
			if ( null !== $category['word_max'] && $measure > $category['word_max'] ) {
				$flags[] = array(
					'code'    => 'word_count_over',
					/* translators: 1: words, 2: limit */
					'message' => sprintf( __( '%1$s words is over the %2$s-word limit.', 'qhta-competitions' ), number_format_i18n( $measure ), number_format_i18n( $category['word_max'] ) ),
				);
			}
			if ( null !== $category['word_min'] && $measure < $category['word_min'] ) {
				$flags[] = array(
					'code'    => 'word_count_under',
					/* translators: 1: words, 2: minimum */
					'message' => sprintf( __( '%1$s words is under the %2$s-word minimum.', 'qhta-competitions' ), number_format_i18n( $measure ), number_format_i18n( $category['word_min'] ) ),
				);
			}
		}
		return $flags;
	}

	/**
	 * Confirmation to the submitter and notification to admins.
	 *
	 * @param int   $submission_id Submission ID.
	 * @param array $config        Config.
	 */
	private static function send_emails( $submission_id, array $config ) {
		$to = (string) get_post_meta( $submission_id, '_qc_submitter_email', true );
		if ( '' !== $to ) {
			Qhta_Competitions_Mailer::send_event( $submission_id, 'submitted', $to );
		}
		/**
		 * Who gets "new submission" notifications.
		 *
		 * @param string[] $recipients Emails.
		 * @param int      $submission_id Submission ID.
		 */
		$admins = (array) apply_filters( 'qc_admin_recipients', array( get_option( 'admin_email' ) ), $submission_id );
		foreach ( $admins as $admin ) {
			Qhta_Competitions_Mailer::send_event( $submission_id, 'admin_new', (string) $admin );
		}
	}
}
