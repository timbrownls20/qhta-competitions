<?php
/**
 * `[qhta_program_form program="slug|id"]` — server-rendered form (spec §5.1),
 * gated by feature flags then dates (§5.11). Progressive enhancement in assets/js/form.js.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form renderer.
 */
final class Qhta_Competitions_Form_Renderer {

	const SHORTCODE = 'qhta_program_form';

	/** Australian states for address fields. */
	const STATES = array( 'QLD', 'NSW', 'ACT', 'VIC', 'TAS', 'SA', 'WA', 'NT' );

	/** @var bool Whether assets have been enqueued this request. */
	private static $enqueued = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Whether the queried post contains the shortcode.
	 *
	 * @return bool
	 */
	public static function page_has_form() {
		$post = get_queried_object();
		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE );
	}

	/**
	 * Form pages are never cached: nonces and the time-on-page check break under a page cache.
	 */
	public static function no_cache() {
		if ( ! self::page_has_form() && empty( $_POST['qc_action'] ) ) { // Only reads presence; handler verifies the nonce.
			return;
		}
		self::send_no_cache();
	}

	/**
	 * Emit no-cache signals (headers only if not yet sent).
	 */
	public static function send_no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/**
	 * Enqueue on pages that contain the shortcode.
	 */
	public static function maybe_enqueue() {
		if ( self::page_has_form() ) {
			self::enqueue();
		}
	}

	/**
	 * Enqueue CSS/JS (also called at render time for shortcodes outside post content).
	 */
	public static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		wp_enqueue_style( 'qc-form', QC_URL . 'assets/css/form.css', array(), QC_VERSION );
		wp_enqueue_script( 'qc-form', QC_URL . 'assets/js/form.js', array(), QC_VERSION, true );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'program' => '' ), $atts, self::SHORTCODE );
		$program = Qhta_Competitions_Program_CPT::find( $atts['program'] );
		$admin   = current_user_can( 'manage_options' );

		if ( ! $program ) {
			return $admin ? self::admin_note( sprintf( /* translators: %s: program ref */ __( 'QHTA Competitions: no program round "%s".', 'qhta-competitions' ), $atts['program'] ) ) : '';
		}

		self::send_no_cache();

		switch ( Qhta_Competitions_Flags::round_gate( $program->ID ) ) {
			case Qhta_Competitions_Flags::GATE_INACTIVE:
				return $admin ? self::admin_note( __( 'QHTA Competitions: wordpress-feature-flags is inactive, so this form is hidden from everyone.', 'qhta-competitions' ) ) : '';
			case Qhta_Competitions_Flags::GATE_KILLED:
				return self::message( __( 'Submissions are temporarily unavailable. Please try again later.', 'qhta-competitions' ) );
			case Qhta_Competitions_Flags::GATE_HIDDEN:
				return '';
			case Qhta_Competitions_Flags::GATE_PREVIEW:
				$preview = true;
				break;
			default:
				$preview = false;
		}

		self::enqueue();
		$config = Qhta_Competitions_Program_CPT::get_config( $program->ID );

		$done = self::done_ref( $program->ID );
		if ( null !== $done ) {
			return self::thank_you( $program, $config, $done );
		}

		if ( ! $preview ) {
			$window = Qhta_Competitions_Program_CPT::window( $config );
			if ( 'before' === $window ) {
				/* translators: %s: opening date */
				return self::message( sprintf( __( 'Submissions open %s.', 'qhta-competitions' ), Qhta_Competitions_Util::display_datetime( $config['opens_at'] ) ) );
			}
			if ( 'closed' === $window ) {
				return self::message( __( 'Submissions for this round have closed.', 'qhta-competitions' ) );
			}
		}

		return self::form( $program, $config, $preview );
	}

	/**
	 * Admin-only inline note.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function admin_note( $text ) {
		return '<div class="qc-form qc-form--admin-note"><p><strong>' . esc_html__( 'Admin only:', 'qhta-competitions' ) . '</strong> ' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Public message box.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function message( $text ) {
		return '<div class="qc-form qc-form--message"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Signature for the post-submit redirect, so the thank-you can't be spoofed.
	 *
	 * @param string $ref        Reference code.
	 * @param int    $program_id Program ID.
	 * @return string
	 */
	public static function done_sig( $ref, $program_id ) {
		return substr( hash_hmac( 'sha256', $ref . '|' . $program_id, wp_salt( 'nonce' ) ), 0, 20 );
	}

	/**
	 * Reference from a valid thank-you redirect for this program, else null.
	 *
	 * @param int $program_id Program ID.
	 * @return string|null
	 */
	private static function done_ref( $program_id ) {
		if ( empty( $_GET['qc_done'] ) || empty( $_GET['qc_sig'] ) || empty( $_GET['qc_p'] ) || (int) $_GET['qc_p'] !== (int) $program_id ) {
			return null;
		}
		$ref = sanitize_text_field( wp_unslash( $_GET['qc_done'] ) );
		$sig = sanitize_text_field( wp_unslash( $_GET['qc_sig'] ) );
		return hash_equals( self::done_sig( $ref, $program_id ), $sig ) ? $ref : null;
	}

	/**
	 * Thank-you message after a successful submission.
	 *
	 * @param WP_Post $program Program.
	 * @param array   $config  Config.
	 * @param string  $ref     Reference code.
	 * @return string
	 */
	private static function thank_you( $program, array $config, $ref ) {
		$out  = '<div class="qc-form qc-form--done" id="qc-form-' . (int) $program->ID . '" role="status">';
		$out .= '<h3>' . esc_html__( 'Thank you — received', 'qhta-competitions' ) . '</h3>';
		/* translators: %s: reference code */
		$out .= '<p>' . sprintf( esc_html__( 'Your reference number is %s. We have emailed a confirmation.', 'qhta-competitions' ), '<strong>' . esc_html( $ref ) . '</strong>' ) . '</p>';
		if ( Qhta_Competitions_Program_CPT::has_confirmer( $config ) ) {
			$label = '' !== $config['confirmer']['label'] ? $config['confirmer']['label'] : __( 'referee', 'qhta-competitions' );
			/* translators: %s: confirmer label */
			$out .= '<p>' . esc_html( sprintf( __( 'We have also emailed your %s. Your application is not complete until they confirm it, so please let them know to expect our email.', 'qhta-competitions' ), strtolower( $label ) ) ) . '</p>';
		}
		$out .= '</div>';
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Form
	 * ------------------------------------------------------------------ */

	/**
	 * Render the form.
	 *
	 * @param WP_Post $program Program.
	 * @param array   $config  Config.
	 * @param bool    $preview Preview (test) mode.
	 * @return string
	 */
	private static function form( $program, array $config, $preview ) {
		$state  = Qhta_Competitions_Form_Handler::state( $program->ID );
		$values = $state ? $state['values'] : self::prefill( $config );
		$errors = $state ? $state['errors'] : array();

		$view = array(
			'program'      => $program,
			'config'       => $config,
			'preview'      => $preview,
			'errors'       => $errors,
			'values'       => $values,
			'form_id'      => 'qc-form-' . (int) $program->ID,
			'action_url'   => self::current_url() . '#qc-form-' . (int) $program->ID,
			'hidden'       => self::hidden_inputs( $program->ID ),
			'fields_html'  => self::fields_html( $config, $values, $errors ),
			'declarations' => self::declarations_html( $config, $values, $errors ),
			'submit_label' => 'bursary' === $config['type'] ? __( 'Submit application', 'qhta-competitions' ) : __( 'Submit entry', 'qhta-competitions' ),
			'categories'   => self::categories_json( $config ),
		);

		ob_start();
		include Qhta_Competitions_Util::template( 'form.php' );
		return (string) ob_get_clean();
	}

	/**
	 * Current page URL without our query args.
	 *
	 * @return string
	 */
	private static function current_url() {
		$id = get_queried_object_id();
		if ( $id ) {
			return get_permalink( $id );
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return remove_query_arg( array( 'qc_done', 'qc_sig', 'qc_p' ), home_url( $uri ) );
	}

	/**
	 * Nonce, program, return URL, honeypot and signed render time.
	 *
	 * @param int $program_id Program ID.
	 * @return string
	 */
	private static function hidden_inputs( $program_id ) {
		$ts   = time();
		$html = wp_nonce_field( Qhta_Competitions_Form_Handler::nonce_action( $program_id ), '_qc_nonce', false, false );
		$html .= '<input type="hidden" name="qc_action" value="submit">';
		$html .= '<input type="hidden" name="qc_program" value="' . (int) $program_id . '">';
		$html .= '<input type="hidden" name="qc_return" value="' . esc_attr( self::current_url() ) . '">';
		$html .= '<input type="hidden" name="qc_ts" value="' . esc_attr( $ts . '.' . Qhta_Competitions_Form_Handler::ts_sig( $ts, $program_id ) ) . '">';
		// Honeypot: humans never see or fill it.
		$html .= '<div class="qc-hp" aria-hidden="true"><label>' . esc_html__( 'Leave this empty', 'qhta-competitions' ) . ' <input type="text" name="qc_website" value="" tabindex="-1" autocomplete="off"></label></div>';
		return $html;
	}

	/**
	 * Category data for the script.
	 *
	 * @param array $config Config.
	 * @return string JSON.
	 */
	private static function categories_json( array $config ) {
		$out = array();
		foreach ( $config['categories'] as $c ) {
			$out[] = array(
				'slug'  => $c['slug'],
				'years' => $c['year_levels'],
				'kind'  => $c['submission_kind'],
				'min'   => $c['word_min'],
				'max'   => $c['word_max'],
			);
		}
		return (string) wp_json_encode( $out );
	}

	/**
	 * Values for a logged-in user (fields with a `prefill` hint): WP user and PMPro billing fields.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	private static function prefill( array $config ) {
		if ( ! is_user_logged_in() ) {
			return array();
		}
		$user = wp_get_current_user();
		$meta = function ( $k ) use ( $user ) {
			return (string) get_user_meta( $user->ID, $k, true );
		};
		$out  = array();
		foreach ( $config['fields'] as $f ) {
			$hint = isset( $f['prefill'] ) ? $f['prefill'] : '';
			switch ( $hint ) {
				case 'name':
					$name             = trim( $user->first_name . ' ' . $user->last_name );
					$out[ $f['key'] ] = '' !== $name ? $name : $user->display_name;
					break;
				case 'email':
					$out[ $f['key'] ] = $user->user_email;
					break;
				case 'phone':
					$out[ $f['key'] ] = $meta( 'pmpro_bphone' );
					break;
				case 'address':
					$out[ $f['key'] ] = array(
						'line1'    => $meta( 'pmpro_baddress1' ),
						'line2'    => $meta( 'pmpro_baddress2' ),
						'suburb'   => $meta( 'pmpro_bcity' ),
						'state'    => strtoupper( $meta( 'pmpro_bstate' ) ),
						'postcode' => $meta( 'pmpro_bzipcode' ),
					);
					break;
			}
		}
		return $out;
	}

	/**
	 * All fields, with the category picker inserted after the year-level field
	 * (or first, when there is none).
	 *
	 * @param array $config Config.
	 * @param array $values Values.
	 * @param array $errors Errors.
	 * @return string
	 */
	private static function fields_html( array $config, array $values, array $errors ) {
		$category = self::category_html( $config, $values, $errors );
		$has_year = false;
		foreach ( $config['fields'] as $f ) {
			if ( 'year_level' === $f['type'] ) {
				$has_year = true;
			}
		}
		$html = $has_year ? '' : $category;
		foreach ( $config['fields'] as $f ) {
			$html .= self::field_html( $f, $config, $values, $errors );
			if ( 'year_level' === $f['type'] ) {
				$html .= $category;
			}
		}
		return $html;
	}

	/**
	 * Category picker (hidden input when there is only one category).
	 *
	 * @param array $config Config.
	 * @param array $values Values.
	 * @param array $errors Errors.
	 * @return string
	 */
	private static function category_html( array $config, array $values, array $errors ) {
		$cats = $config['categories'];
		if ( ! $cats ) {
			return '';
		}
		if ( 1 === count( $cats ) ) {
			return '<input type="hidden" name="qc_category" value="' . esc_attr( $cats[0]['slug'] ) . '" data-qc-category>';
		}
		$current = isset( $values['_category'] ) ? $values['_category'] : '';
		$html    = '<div class="qc-field qc-field--category' . ( isset( $errors['_category'] ) ? ' qc-field--error' : '' ) . '">';
		$html   .= '<label for="qc_category">' . esc_html__( 'Category', 'qhta-competitions' ) . ' <span class="qc-req" aria-hidden="true">*</span></label>';
		$html   .= '<select id="qc_category" name="qc_category" required data-qc-category' . self::describedby( '_category', $errors, false ) . '>';
		$html   .= '<option value="">' . esc_html__( '— Select a category —', 'qhta-competitions' ) . '</option>';
		foreach ( $cats as $c ) {
			$html .= '<option value="' . esc_attr( $c['slug'] ) . '" data-years="' . esc_attr( implode( ',', $c['year_levels'] ) ) . '"' . selected( $current, $c['slug'], false ) . '>' . esc_html( $c['label'] ) . self::limits_suffix( $c ) . '</option>';
		}
		$html .= '</select>';
		$html .= self::error_html( '_category', $errors );
		$html .= '</div>';
		return $html;
	}

	/**
	 * " (600–1,000 words)" suffix for a category option.
	 *
	 * @param array $c Category.
	 * @return string Escaped.
	 */
	private static function limits_suffix( array $c ) {
		if ( null !== $c['word_min'] && null !== $c['word_max'] ) {
			/* translators: 1: min, 2: max */
			return esc_html( sprintf( __( ' (%1$s–%2$s words)', 'qhta-competitions' ), number_format_i18n( $c['word_min'] ), number_format_i18n( $c['word_max'] ) ) );
		}
		if ( null !== $c['word_max'] ) {
			/* translators: %s: max */
			return esc_html( sprintf( __( ' (up to %s words)', 'qhta-competitions' ), number_format_i18n( $c['word_max'] ) ) );
		}
		return '';
	}

	/**
	 * aria-describedby attribute for a field's help/error.
	 *
	 * @param string $key      Field key.
	 * @param array  $errors   Errors.
	 * @param bool   $has_help Whether help text is printed.
	 * @return string
	 */
	private static function describedby( $key, array $errors, $has_help ) {
		$ids = array();
		if ( $has_help ) {
			$ids[] = 'qc_' . $key . '_help';
		}
		if ( isset( $errors[ $key ] ) ) {
			$ids[] = 'qc_' . $key . '_error';
		}
		$attr = $ids ? ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"' : '';
		return $attr . ( isset( $errors[ $key ] ) ? ' aria-invalid="true"' : '' );
	}

	/**
	 * Error line.
	 *
	 * @param string $key    Field key.
	 * @param array  $errors Errors.
	 * @return string
	 */
	private static function error_html( $key, array $errors ) {
		return isset( $errors[ $key ] ) ? '<p class="qc-error" id="qc_' . esc_attr( $key ) . '_error">' . esc_html( $errors[ $key ] ) . '</p>' : '';
	}

	/**
	 * Help text (newlines kept; "•" lines become a list).
	 *
	 * @param string $key  Field key.
	 * @param string $help Help.
	 * @return string
	 */
	private static function help_html( $key, $help ) {
		if ( '' === trim( $help ) ) {
			return '';
		}
		$lines   = preg_split( '/\r?\n/', $help );
		$html    = '<div class="qc-help" id="qc_' . esc_attr( $key ) . '_help">';
		$in_list = false;
		foreach ( $lines as $line ) {
			if ( preg_match( '/^\s*[•\-\*]\s+(.*)$/u', $line, $m ) ) {
				if ( ! $in_list ) {
					$html   .= '<ul>';
					$in_list = true;
				}
				$html .= '<li>' . esc_html( $m[1] ) . '</li>';
				continue;
			}
			if ( $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			if ( '' !== trim( $line ) ) {
				$html .= '<p>' . esc_html( $line ) . '</p>';
			}
		}
		return $html . ( $in_list ? '</ul>' : '' ) . '</div>';
	}

	/**
	 * One field.
	 *
	 * @param array $f      Field.
	 * @param array $config Config.
	 * @param array $values Values.
	 * @param array $errors Errors.
	 * @return string
	 */
	private static function field_html( array $f, array $config, array $values, array $errors ) {
		$key  = $f['key'];
		$type = $f['type'];
		$id   = 'qc_' . $key;
		$val  = isset( $values[ $key ] ) ? $values[ $key ] : '';

		if ( 'heading' === $type ) {
			return '<h3 class="qc-heading">' . esc_html( $f['label'] ) . '</h3>';
		}
		if ( 'help' === $type ) {
			return self::help_html( $key, $f['help'] );
		}

		// Conditional fields can't carry `required` (they'd block the no-JS form while hidden).
		$conditional = ! empty( $f['show_if'] ) || in_array( $type, array( 'file', 'richtext_limited' ), true );
		$required    = $f['required'] && ! $conditional ? ' required' : '';
		$has_help    = '' !== trim( $f['help'] );
		$aria        = self::describedby( $key, $errors, $has_help );
		$star        = $f['required'] ? ' <span class="qc-req" aria-hidden="true">*</span>' : '';
		$label       = esc_html( $f['label'] );

		$attrs = ' data-key="' . esc_attr( $key ) . '"';
		if ( ! empty( $f['show_if'] ) ) {
			$attrs .= ' data-show-if-field="' . esc_attr( $f['show_if']['field'] ) . '" data-show-if-equals="' . esc_attr( (string) $f['show_if']['equals'] ) . '"';
		}
		if ( 'file' === $type ) {
			$attrs .= ' data-kind="upload"';
		} elseif ( 'richtext_limited' === $type ) {
			$attrs .= ' data-kind="inline"';
		}
		$class = 'qc-field qc-field--' . $type . ( isset( $errors[ $key ] ) ? ' qc-field--error' : '' ) . ( $f['required'] ? ' qc-field--required' : '' );

		$label_attrs = '';
		if ( ! empty( $f['label_if'] ) ) {
			$label_attrs = ' data-label-if-field="' . esc_attr( $f['label_if']['field'] ) . '" data-label-if-equals="' . esc_attr( (string) $f['label_if']['equals'] ) . '" data-label-alt="' . esc_attr( $f['label_if']['label'] ) . '" data-label-default="' . esc_attr( $f['label'] ) . '"';
			// Server render honours the current value too.
			if ( isset( $values[ $f['label_if']['field'] ] ) && (string) $values[ $f['label_if']['field'] ] === (string) $f['label_if']['equals'] ) {
				$label = esc_html( $f['label_if']['label'] );
			}
		}
		$label_html = '<span class="qc-label-text"' . $label_attrs . '>' . $label . '</span>';

		$html = '<div class="' . esc_attr( $class ) . '"' . $attrs . '>';
		switch ( $type ) {
			case 'text':
			case 'email':
			case 'tel':
				$input_type   = 'text' === $type ? 'text' : $type;
				$autocomplete = isset( $f['prefill'] ) ? array( 'name' => 'name', 'email' => 'email', 'phone' => 'tel' ) : array();
				$ac           = isset( $f['prefill'], $autocomplete[ $f['prefill'] ] ) ? ' autocomplete="' . esc_attr( $autocomplete[ $f['prefill'] ] ) . '"' : '';
				$extra        = 'word_count_declared' === $key ? ' inputmode="numeric" pattern="[0-9]*"' : '';
				$html        .= '<label for="' . esc_attr( $id ) . '">' . $label_html . $star . '</label>';
				$html        .= '<input type="' . esc_attr( $input_type ) . '" id="' . esc_attr( $id ) . '" name="qc_f[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $val ) . '"' . $required . $ac . $extra . $aria . '>';
				break;

			case 'textarea':
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label_html . $star . '</label>';
				$html .= '<textarea id="' . esc_attr( $id ) . '" name="qc_f[' . esc_attr( $key ) . ']" rows="5"' . $required . $aria . '>' . esc_textarea( (string) $val ) . '</textarea>';
				break;

			case 'richtext_limited':
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label_html . $star . '</label>';
				$html .= self::help_html( $key, $f['help'] );
				$html .= '<textarea id="' . esc_attr( $id ) . '" name="qc_f[' . esc_attr( $key ) . ']" rows="12" data-wordcount' . $aria . '>' . esc_textarea( (string) $val ) . '</textarea>';
				$html .= '<p class="qc-wordcount" aria-live="polite" data-wordcount-for="' . esc_attr( $id ) . '"></p>';
				$has_help = false; // Already printed above the box.
				break;

			case 'select':
			case 'year_level':
				$options = $f['options'];
				if ( 'year_level' === $type && ! $options ) {
					foreach ( array( '7', '8', '9', '10', '11', '12' ) as $y ) {
						$options[] = array( 'value' => $y, 'label' => $y );
					}
				}
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label_html . $star . '</label>';
				$html .= '<select id="' . esc_attr( $id ) . '" name="qc_f[' . esc_attr( $key ) . ']"' . $required . $aria . ( 'year_level' === $type ? ' data-qc-year' : '' ) . '>';
				$html .= '<option value="">' . esc_html__( '— Select —', 'qhta-competitions' ) . '</option>';
				foreach ( $options as $o ) {
					$o_label = 'year_level' === $type && is_numeric( $o['label'] ) ? sprintf( /* translators: %s: year */ __( 'Year %s', 'qhta-competitions' ), $o['label'] ) : $o['label'];
					$html   .= '<option value="' . esc_attr( $o['value'] ) . '"' . selected( (string) $val, $o['value'], false ) . '>' . esc_html( $o_label ) . '</option>';
				}
				$html .= '</select>';
				break;

			case 'radio':
				$html .= '<fieldset' . $aria . '><legend>' . $label_html . $star . '</legend>';
				foreach ( $f['options'] as $i => $o ) {
					$oid   = $id . '_' . $i;
					$html .= '<label class="qc-choice" for="' . esc_attr( $oid ) . '"><input type="radio" id="' . esc_attr( $oid ) . '" name="qc_f[' . esc_attr( $key ) . ']" value="' . esc_attr( $o['value'] ) . '"' . checked( (string) $val, $o['value'], false ) . ( 0 === $i ? $required : '' ) . '> ' . esc_html( $o['label'] ) . '</label>';
				}
				$html .= '</fieldset>';
				break;

			case 'checkbox':
				$html .= '<label class="qc-choice" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="qc_f[' . esc_attr( $key ) . ']" value="1"' . checked( '1', (string) $val, false ) . $required . $aria . '> ' . $label_html . $star . '</label>';
				break;

			case 'address':
				$a     = is_array( $val ) ? $val : array();
				$get   = function ( $k ) use ( $a ) {
					return isset( $a[ $k ] ) ? (string) $a[ $k ] : '';
				};
				$name  = 'qc_f[' . esc_attr( $key ) . ']';
				$req   = $f['required'] && ! $conditional ? ' required' : '';
				$html .= '<fieldset' . $aria . '><legend>' . $label_html . $star . '</legend>';
				$html .= '<label class="qc-sub" for="' . esc_attr( $id ) . '_line1">' . esc_html__( 'Street address', 'qhta-competitions' ) . '</label><input type="text" id="' . esc_attr( $id ) . '_line1" name="' . $name . '[line1]" value="' . esc_attr( $get( 'line1' ) ) . '" autocomplete="address-line1"' . $req . '>';
				$html .= '<input type="text" aria-label="' . esc_attr__( 'Address line 2', 'qhta-competitions' ) . '" name="' . $name . '[line2]" value="' . esc_attr( $get( 'line2' ) ) . '" autocomplete="address-line2">';
				$html .= '<div class="qc-address-row">';
				$html .= '<span><label class="qc-sub" for="' . esc_attr( $id ) . '_suburb">' . esc_html__( 'Suburb / town', 'qhta-competitions' ) . '</label><input type="text" id="' . esc_attr( $id ) . '_suburb" name="' . $name . '[suburb]" value="' . esc_attr( $get( 'suburb' ) ) . '" autocomplete="address-level2"' . $req . '></span>';
				$html .= '<span><label class="qc-sub" for="' . esc_attr( $id ) . '_state">' . esc_html__( 'State', 'qhta-competitions' ) . '</label><select id="' . esc_attr( $id ) . '_state" name="' . $name . '[state]" autocomplete="address-level1">';
				$state = '' !== $get( 'state' ) ? $get( 'state' ) : 'QLD';
				foreach ( self::STATES as $s ) {
					$html .= '<option value="' . esc_attr( $s ) . '"' . selected( $state, $s, false ) . '>' . esc_html( $s ) . '</option>';
				}
				$html .= '</select></span>';
				$html .= '<span><label class="qc-sub" for="' . esc_attr( $id ) . '_postcode">' . esc_html__( 'Postcode', 'qhta-competitions' ) . '</label><input type="text" id="' . esc_attr( $id ) . '_postcode" name="' . $name . '[postcode]" value="' . esc_attr( $get( 'postcode' ) ) . '" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="postal-code"' . $req . '></span>';
				$html .= '</div></fieldset>';
				break;

			case 'file':
				$html .= '<label for="' . esc_attr( $id ) . '">' . $label_html . $star . '</label>';
				$html .= '<input type="file" id="' . esc_attr( $id ) . '" name="qc_file_' . esc_attr( $key ) . '" accept=".docx,.pdf,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"' . $aria . '>';
				if ( isset( $errors['_files_lost'] ) ) {
					$html .= '<p class="qc-note">' . esc_html__( 'For security, please choose the file again.', 'qhta-competitions' ) . '</p>';
				}
				break;
		}

		if ( $has_help ) {
			$html .= self::help_html( $key, $f['help'] );
		}
		$html .= self::error_html( $key, $errors );
		$html .= '</div>';
		return $html;
	}

	/**
	 * Privacy notice, declarations and typed-name signature.
	 *
	 * @param array $config Config.
	 * @param array $values Values.
	 * @param array $errors Errors.
	 * @return string
	 */
	private static function declarations_html( array $config, array $values, array $errors ) {
		$html = '';
		if ( '' !== trim( $config['privacy_notice'] ) ) {
			$html .= '<div class="qc-privacy"><h3 class="qc-heading">' . esc_html__( 'Privacy', 'qhta-competitions' ) . '</h3>' . wp_kses_post( $config['privacy_notice'] ) . '</div>';
		}
		if ( ! $config['declarations'] ) {
			return $html;
		}
		$ticked = isset( $values['_declarations'] ) && is_array( $values['_declarations'] ) ? $values['_declarations'] : array();

		$html .= '<fieldset class="qc-declarations"><legend class="qc-heading">' . esc_html__( 'Declarations', 'qhta-competitions' ) . '</legend>';
		foreach ( $config['declarations'] as $d ) {
			$id    = 'qc_decl_' . $d['key'];
			$ekey  = '_decl_' . $d['key'];
			$html .= '<div class="qc-field qc-field--declaration' . ( isset( $errors[ $ekey ] ) ? ' qc-field--error' : '' ) . '">';
			$html .= '<label class="qc-choice" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="qc_decl[' . esc_attr( $d['key'] ) . ']" value="1"' . checked( ! empty( $ticked[ $d['key'] ] ), true, false ) . ( $d['required'] ? ' required' : '' ) . self::describedby( $ekey, $errors, false ) . '> ' . esc_html( $d['text'] ) . ( $d['required'] ? ' <span class="qc-req" aria-hidden="true">*</span>' : '' ) . '</label>';
			$html .= self::error_html( $ekey, $errors );
			$html .= '</div>';
		}
		$sig   = isset( $values['_signature'] ) ? (string) $values['_signature'] : '';
		$html .= '<div class="qc-field qc-field--signature' . ( isset( $errors['_signature'] ) ? ' qc-field--error' : '' ) . '">';
		$html .= '<label for="qc_signature">' . esc_html__( 'Type your full name as your signature', 'qhta-competitions' ) . ' <span class="qc-req" aria-hidden="true">*</span></label>';
		$html .= '<input type="text" id="qc_signature" name="qc_signature" value="' . esc_attr( $sig ) . '" required autocomplete="name"' . self::describedby( '_signature', $errors, false ) . '>';
		$html .= '<p class="qc-help">' . esc_html__( 'Typing your name here has the same effect as signing the declarations above. We record the date, time and your IP address with it.', 'qhta-competitions' ) . '</p>';
		$html .= self::error_html( '_signature', $errors );
		$html .= '</div></fieldset>';
		return $html;
	}
}
