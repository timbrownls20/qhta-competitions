<?php
/**
 * `qhta_program` CPT: one post = one round. Config lives in native post meta as JSON (spec §3.1).
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Program rounds and their configuration.
 */
final class Qhta_Competitions_Program_CPT {

	const POST_TYPE = 'qhta_program';

	/** Program types. Drives defaults only; behaviour comes from config. */
	const TYPES = array( 'competition', 'bursary', 'generic' );

	/** Field types supported by the form schema (§3.4). */
	const FIELD_TYPES = array( 'text', 'email', 'tel', 'textarea', 'richtext_limited', 'select', 'radio', 'checkbox', 'year_level', 'address', 'file', 'heading', 'help' );

	/** Field types that carry no input. */
	const DISPLAY_ONLY_TYPES = array( 'heading', 'help' );

	/** Field types that need options[]. */
	const OPTION_TYPES = array( 'select', 'radio' );

	/** Category submission kinds. */
	const SUBMISSION_KINDS = array( 'upload', 'inline', 'both' );

	/** Email events (§5.8). */
	const EMAIL_EVENTS = array( 'submitted', 'confirm_request', 'confirmed', 'admin_new', 'reminder_confirm', 'outcome_awarded', 'outcome_commended', 'outcome_unsuccessful' );

	/** Eligibility rules understood by class-eligibility (§5.5). */
	const ELIGIBILITY_RULES = array( 'year_level_category', 'word_count', 'required_true', 'qhta_membership', 'entry_limits', 'late', 'confirmer_differs' );

	/** Eligibility modes. */
	const ELIGIBILITY_MODES = array( 'block', 'flag' );

	/** Confirmer roles. */
	const CONFIRMER_ROLES = array( 'none', 'referee' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the CPT. Non-public, admin-only, no REST.
	 */
	public static function register() {
		$cap = Qhta_Competitions_Plugin::CAP_MANAGE;
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Program rounds', 'qhta-competitions' ),
					'singular_name'      => __( 'Program round', 'qhta-competitions' ),
					'menu_name'          => __( 'Competitions', 'qhta-competitions' ),
					'all_items'          => __( 'Program rounds', 'qhta-competitions' ),
					'add_new'            => __( 'Add blank round', 'qhta-competitions' ),
					'add_new_item'       => __( 'Add program round', 'qhta-competitions' ),
					'edit_item'          => __( 'Edit program round', 'qhta-competitions' ),
					'search_items'       => __( 'Search rounds', 'qhta-competitions' ),
					'not_found'          => __( 'No rounds yet. Use "New from preset" to start one.', 'qhta-competitions' ),
					'not_found_in_trash' => __( 'No rounds in trash.', 'qhta-competitions' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'menu_icon'           => 'dashicons-awards',
				'menu_position'       => 58,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'map_meta_cap'        => false,
				'capabilities'        => self::caps( $cap ),
			)
		);
	}

	/**
	 * Map every post capability to one plugin capability.
	 *
	 * @param string $cap Capability.
	 * @return array<string,string>
	 */
	public static function caps( $cap ) {
		$keys = array( 'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts', 'read' );
		return array_fill_keys( $keys, $cap );
	}

	/* ---------------------------------------------------------------------
	 * Config schema
	 * ------------------------------------------------------------------ */

	/**
	 * Config keys → meta storage. `json` values are stored as JSON strings.
	 *
	 * @return array<string, array{meta:string, kind:string}>
	 */
	public static function schema() {
		return array(
			'type'              => array( 'meta' => '_qc_type', 'kind' => 'scalar' ),
			'opens_at'          => array( 'meta' => '_qc_opens_at', 'kind' => 'scalar' ),
			'closes_at'         => array( 'meta' => '_qc_closes_at', 'kind' => 'scalar' ),
			'late_grace_minutes' => array( 'meta' => '_qc_late_grace_minutes', 'kind' => 'scalar' ),
			'categories'        => array( 'meta' => '_qc_categories', 'kind' => 'json' ),
			'fields'            => array( 'meta' => '_qc_fields', 'kind' => 'json' ),
			'declarations'      => array( 'meta' => '_qc_declarations', 'kind' => 'json' ),
			'confirmer'         => array( 'meta' => '_qc_confirmer', 'kind' => 'json' ),
			'eligibility'       => array( 'meta' => '_qc_eligibility', 'kind' => 'json' ),
			'rubric'            => array( 'meta' => '_qc_rubric', 'kind' => 'json' ),
			'anonymise'         => array( 'meta' => '_qc_anonymise', 'kind' => 'scalar' ),
			'entry_limits'      => array( 'meta' => '_qc_entry_limits', 'kind' => 'json' ),
			'emails'            => array( 'meta' => '_qc_emails', 'kind' => 'json' ),
			'retention_months'  => array( 'meta' => '_qc_retention_months', 'kind' => 'scalar' ),
			'word_count_rules'  => array( 'meta' => '_qc_word_count_rules', 'kind' => 'json' ),
			'privacy_notice'    => array( 'meta' => '_qc_privacy_notice', 'kind' => 'scalar' ),
			'max_upload_mb'     => array( 'meta' => '_qc_max_upload_mb', 'kind' => 'scalar' ),
			'judge_spread_margin' => array( 'meta' => '_qc_judge_spread_margin', 'kind' => 'scalar' ),
		);
	}

	/**
	 * Defaults for a new round.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'type'               => 'generic',
			'opens_at'           => '',
			'closes_at'          => '',
			'late_grace_minutes' => 0,
			'categories'         => array(),
			'fields'             => array(),
			'declarations'       => array(),
			'confirmer'          => array(
				'role'        => 'none',
				'label'       => '',
				'email_field' => '',
				'name_field'  => '',
				'declaration' => '',
			),
			'eligibility'        => array(),
			'rubric'             => array( 'criteria' => array() ),
			'anonymise'          => true,
			'entry_limits'       => array(
				'per_entrant_per_category' => null,
				'per_school_per_category'  => null,
				'per_entrant_total'        => null,
			),
			'emails'             => array(),
			'retention_months'   => 12,
			'word_count_rules'   => array(
				'include_footnotes'      => true,
				'exclude_after_headings' => array( 'Bibliography', 'References', 'Reference list', 'Works cited' ),
			),
			'privacy_notice'     => '',
			'max_upload_mb'      => 10,
			'judge_spread_margin' => 15,
		);
	}

	/**
	 * Full config for a program, defaults filled in for anything unset.
	 *
	 * @param int $program_id Program ID.
	 * @return array
	 */
	public static function get_config( $program_id ) {
		$config = self::defaults();
		foreach ( self::schema() as $name => $def ) {
			if ( ! metadata_exists( 'post', $program_id, $def['meta'] ) ) {
				continue;
			}
			if ( 'json' === $def['kind'] ) {
				$value = Qhta_Competitions_Util::get_json_meta( $program_id, $def['meta'], null );
				if ( null !== $value ) {
					$config[ $name ] = $value;
				}
				continue;
			}
			$raw = get_post_meta( $program_id, $def['meta'], true );
			switch ( $name ) {
				case 'late_grace_minutes':
				case 'retention_months':
				case 'max_upload_mb':
				case 'judge_spread_margin':
					$config[ $name ] = (int) $raw;
					break;
				case 'anonymise':
					$config[ $name ] = '1' === (string) $raw;
					break;
				default:
					$config[ $name ] = (string) $raw;
			}
		}
		return $config;
	}

	/**
	 * Validate and store a (partial) config. Keys that fail validation are not written.
	 *
	 * @param int   $program_id Program ID.
	 * @param array $config     Config keyed by schema name.
	 * @return string[] Error messages (empty on success).
	 */
	public static function save_config( $program_id, array $config ) {
		list( $clean, $errors ) = self::validate( $config );
		$schema                 = self::schema();
		foreach ( $clean as $name => $value ) {
			$def = $schema[ $name ];
			if ( 'json' === $def['kind'] ) {
				Qhta_Competitions_Util::set_json_meta( $program_id, $def['meta'], $value );
			} elseif ( 'anonymise' === $name ) {
				update_post_meta( $program_id, $def['meta'], $value ? '1' : '0' );
			} else {
				update_post_meta( $program_id, $def['meta'], wp_slash( (string) $value ) );
			}
		}
		return $errors;
	}

	/* ---------------------------------------------------------------------
	 * Validation. Known keys are normalised; unknown keys inside objects are
	 * kept, so config round-trips save/edit without loss.
	 * ------------------------------------------------------------------ */

	/**
	 * Validate a (partial) config.
	 *
	 * @param array $config Config.
	 * @return array{0: array, 1: string[]} [clean values that passed, errors]
	 */
	public static function validate( array $config ) {
		$clean  = array();
		$errors = array();
		$schema = self::schema();

		foreach ( $config as $name => $value ) {
			if ( ! isset( $schema[ $name ] ) ) {
				continue;
			}
			$method = 'validate_' . $name;
			$err    = array();
			$result = method_exists( __CLASS__, $method ) ? self::$method( $value, $err, $config ) : $value;
			if ( $err ) {
				foreach ( $err as $e ) {
					/* translators: 1: config section, 2: error */
					$errors[] = sprintf( __( '%1$s: %2$s', 'qhta-competitions' ), self::section_label( $name ), $e );
				}
				continue;
			}
			$clean[ $name ] = $result;
		}

		// Cross-field: close after open.
		$opens  = isset( $clean['opens_at'] ) ? $clean['opens_at'] : null;
		$closes = isset( $clean['closes_at'] ) ? $clean['closes_at'] : null;
		if ( $opens && $closes && strcmp( $closes, $opens ) <= 0 ) {
			$errors[] = __( 'Schedule: the close date/time must be after the open date/time. Dates not saved.', 'qhta-competitions' );
			unset( $clean['opens_at'], $clean['closes_at'] );
		}

		return array( $clean, $errors );
	}

	/**
	 * Human label for a config section.
	 *
	 * @param string $name Config key.
	 * @return string
	 */
	public static function section_label( $name ) {
		$labels = array(
			'type'               => __( 'Type', 'qhta-competitions' ),
			'opens_at'           => __( 'Opens', 'qhta-competitions' ),
			'closes_at'          => __( 'Closes', 'qhta-competitions' ),
			'late_grace_minutes' => __( 'Late grace', 'qhta-competitions' ),
			'categories'         => __( 'Categories', 'qhta-competitions' ),
			'fields'             => __( 'Form fields', 'qhta-competitions' ),
			'declarations'       => __( 'Declarations', 'qhta-competitions' ),
			'confirmer'          => __( 'Confirmer', 'qhta-competitions' ),
			'eligibility'        => __( 'Eligibility', 'qhta-competitions' ),
			'rubric'             => __( 'Rubric', 'qhta-competitions' ),
			'anonymise'          => __( 'Anonymise', 'qhta-competitions' ),
			'entry_limits'       => __( 'Entry limits', 'qhta-competitions' ),
			'emails'             => __( 'Emails', 'qhta-competitions' ),
			'retention_months'   => __( 'Retention', 'qhta-competitions' ),
			'word_count_rules'   => __( 'Word count rules', 'qhta-competitions' ),
			'privacy_notice'     => __( 'Privacy notice', 'qhta-competitions' ),
			'max_upload_mb'      => __( 'Max upload size', 'qhta-competitions' ),
			'judge_spread_margin' => __( 'Judge spread warning', 'qhta-competitions' ),
		);
		return isset( $labels[ $name ] ) ? $labels[ $name ] : $name;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors (by ref).
	 * @return string
	 */
	private static function validate_type( $v, &$err ) {
		if ( ! in_array( $v, self::TYPES, true ) ) {
			$err[] = __( 'must be competition, bursary or generic.', 'qhta-competitions' );
		}
		return (string) $v;
	}

	/**
	 * UTC datetime string or ''.
	 *
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return string
	 */
	private static function validate_opens_at( $v, &$err ) {
		$v = (string) $v;
		if ( '' !== $v && null === Qhta_Competitions_Util::utc_to_timestamp( $v ) ) {
			$err[] = __( 'invalid date/time.', 'qhta-competitions' );
		}
		return $v;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return string
	 */
	private static function validate_closes_at( $v, &$err ) {
		return self::validate_opens_at( $v, $err );
	}

	/**
	 * Non-negative int.
	 *
	 * @param mixed    $v     Value.
	 * @param string[] $err   Errors.
	 * @param int      $min   Minimum.
	 * @return int
	 */
	private static function int_at_least( $v, &$err, $min ) {
		$int = self::to_int( $v );
		if ( null === $int || $int < $min ) {
			/* translators: %d: minimum */
			$err[] = sprintf( __( 'must be a whole number of at least %d.', 'qhta-competitions' ), $min );
			return 0;
		}
		return $int;
	}

	/**
	 * Strict whole-number parse: 5 and "5" pass; 5.5, "5a" and true don't.
	 *
	 * @param mixed $v Value.
	 * @return int|null
	 */
	private static function to_int( $v ) {
		if ( is_int( $v ) ) {
			return $v;
		}
		if ( is_string( $v ) && 1 === preg_match( '/^-?\d+$/', trim( $v ) ) ) {
			return (int) trim( $v );
		}
		return null;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return int
	 */
	private static function validate_late_grace_minutes( $v, &$err ) {
		return self::int_at_least( $v, $err, 0 );
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return int
	 */
	private static function validate_retention_months( $v, &$err ) {
		return self::int_at_least( $v, $err, 1 );
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return int
	 */
	private static function validate_max_upload_mb( $v, &$err ) {
		return self::int_at_least( $v, $err, 1 );
	}

	/**
	 * Percentage points (0–100).
	 *
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return int
	 */
	private static function validate_judge_spread_margin( $v, &$err ) {
		$n = self::int_at_least( $v, $err, 0 );
		if ( $n > 100 ) {
			$err[] = __( 'must be 100 or less.', 'qhta-competitions' );
		}
		return $n;
	}

	/**
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function validate_anonymise( $v ) {
		return (bool) $v;
	}

	/**
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function validate_privacy_notice( $v ) {
		return wp_kses_post( (string) $v );
	}

	/**
	 * Whether a value is a JSON-style list (sequential array).
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function is_list( $v ) {
		return is_array( $v ) && array_values( $v ) === $v;
	}

	/**
	 * Whether a value is a JSON-style object (associative array, or empty).
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	private static function is_object( $v ) {
		return is_array( $v ) && ( array() === $v || array_values( $v ) !== $v );
	}

	/**
	 * Int or null (null/'' = unset).
	 *
	 * @param mixed    $v     Value.
	 * @param string[] $err   Errors.
	 * @param string   $label Field label for messages.
	 * @param int      $min   Minimum.
	 * @return int|null
	 */
	private static function int_or_null( $v, &$err, $label, $min = 0 ) {
		if ( null === $v || '' === $v ) {
			return null;
		}
		$int = self::to_int( $v );
		if ( null === $int || $int < $min ) {
			/* translators: 1: field, 2: minimum */
			$err[] = sprintf( __( '%1$s must be empty or a whole number of at least %2$d.', 'qhta-competitions' ), $label, $min );
			return null;
		}
		return $int;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_categories( $v, &$err ) {
		if ( ! self::is_list( $v ) ) {
			$err[] = __( 'must be a JSON array of categories.', 'qhta-competitions' );
			return array();
		}
		$seen = array();
		$out  = array();
		foreach ( $v as $i => $cat ) {
			$n = $i + 1;
			if ( ! self::is_object( $cat ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d must be an object.', 'qhta-competitions' ), $n );
				continue;
			}
			$slug = isset( $cat['slug'] ) ? (string) $cat['slug'] : '';
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $slug ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d needs a slug (lowercase letters, digits, - or _).', 'qhta-competitions' ), $n );
			} elseif ( isset( $seen[ $slug ] ) ) {
				/* translators: %s: slug */
				$err[] = sprintf( __( 'duplicate slug "%s".', 'qhta-competitions' ), $slug );
			}
			$seen[ $slug ] = true;
			if ( empty( $cat['label'] ) || ! is_string( $cat['label'] ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d needs a label.', 'qhta-competitions' ), $n );
			}
			$years = isset( $cat['year_levels'] ) ? $cat['year_levels'] : array();
			if ( ! self::is_list( $years ) ) {
				/* translators: %s: slug */
				$err[] = sprintf( __( '"%s" year_levels must be an array.', 'qhta-competitions' ), $slug );
				$years = array();
			}
			$cat['year_levels'] = array_values( array_map( 'strval', $years ) );
			$cat['word_min']    = self::int_or_null( isset( $cat['word_min'] ) ? $cat['word_min'] : null, $err, $slug . ' word_min' );
			$cat['word_max']    = self::int_or_null( isset( $cat['word_max'] ) ? $cat['word_max'] : null, $err, $slug . ' word_max', 1 );
			if ( null !== $cat['word_min'] && null !== $cat['word_max'] && $cat['word_min'] > $cat['word_max'] ) {
				/* translators: %s: slug */
				$err[] = sprintf( __( '"%s" word_min is greater than word_max.', 'qhta-competitions' ), $slug );
			}
			$kind = isset( $cat['submission_kind'] ) ? $cat['submission_kind'] : 'upload';
			if ( ! in_array( $kind, self::SUBMISSION_KINDS, true ) ) {
				/* translators: %s: slug */
				$err[] = sprintf( __( '"%s" submission_kind must be upload, inline or both.', 'qhta-competitions' ), $slug );
			}
			$cat['submission_kind'] = $kind;
			$out[]                  = $cat;
		}
		return $out;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_fields( $v, &$err ) {
		if ( ! self::is_list( $v ) ) {
			$err[] = __( 'must be a JSON array of fields.', 'qhta-competitions' );
			return array();
		}
		$keys = array();
		foreach ( $v as $f ) {
			if ( is_array( $f ) && isset( $f['key'] ) ) {
				$keys[ (string) $f['key'] ] = true;
			}
		}
		$seen = array();
		$out  = array();
		foreach ( $v as $i => $f ) {
			$n = $i + 1;
			if ( ! self::is_object( $f ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d must be an object.', 'qhta-competitions' ), $n );
				continue;
			}
			$key = isset( $f['key'] ) ? (string) $f['key'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $key ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d needs a key (lowercase letters, digits, _).', 'qhta-competitions' ), $n );
			} elseif ( isset( $seen[ $key ] ) ) {
				/* translators: %s: key */
				$err[] = sprintf( __( 'duplicate key "%s".', 'qhta-competitions' ), $key );
			}
			$seen[ $key ] = true;

			$type = isset( $f['type'] ) ? $f['type'] : '';
			if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
				/* translators: 1: key, 2: allowed types */
				$err[] = sprintf( __( '"%1$s" type must be one of: %2$s.', 'qhta-competitions' ), $key, implode( ', ', self::FIELD_TYPES ) );
			}
			$display_only = in_array( $type, self::DISPLAY_ONLY_TYPES, true );
			if ( ! $display_only && ( ! isset( $f['label'] ) || '' === trim( (string) $f['label'] ) ) ) {
				/* translators: %s: key */
				$err[] = sprintf( __( '"%s" needs a label.', 'qhta-competitions' ), $key );
			}

			$f['label']    = isset( $f['label'] ) ? (string) $f['label'] : '';
			$f['required'] = ! $display_only && ! empty( $f['required'] );
			$f['help']     = isset( $f['help'] ) ? (string) $f['help'] : '';
			$f['pii']      = ! empty( $f['pii'] );
			// Default: judges see non-PII fields only.
			$f['judge_visible'] = isset( $f['judge_visible'] ) ? (bool) $f['judge_visible'] : ! $f['pii'];

			$options = isset( $f['options'] ) ? $f['options'] : array();
			if ( ! self::is_list( $options ) ) {
				/* translators: %s: key */
				$err[] = sprintf( __( '"%s" options must be an array.', 'qhta-competitions' ), $key );
				$options = array();
			}
			$norm = array();
			foreach ( $options as $opt ) {
				if ( is_array( $opt ) ) {
					$value  = isset( $opt['value'] ) ? (string) $opt['value'] : ( isset( $opt['label'] ) ? (string) $opt['label'] : '' );
					$label  = isset( $opt['label'] ) ? (string) $opt['label'] : $value;
					$norm[] = array_merge( $opt, array( 'value' => $value, 'label' => $label ) );
				} else {
					$norm[] = array(
						'value' => (string) $opt,
						'label' => (string) $opt,
					);
				}
			}
			$f['options'] = $norm;
			if ( in_array( $type, self::OPTION_TYPES, true ) && ! $norm ) {
				/* translators: %s: key */
				$err[] = sprintf( __( '"%s" needs options.', 'qhta-competitions' ), $key );
			}

			foreach ( array( 'show_if', 'label_if' ) as $cond ) {
				if ( ! isset( $f[ $cond ] ) || null === $f[ $cond ] ) {
					$f[ $cond ] = null;
					continue;
				}
				$c = $f[ $cond ];
				if ( ! self::is_object( $c ) || empty( $c['field'] ) || ! array_key_exists( 'equals', $c ) ) {
					/* translators: 1: key, 2: condition name */
					$err[] = sprintf( __( '"%1$s" %2$s needs {field, equals}.', 'qhta-competitions' ), $key, $cond );
				} elseif ( ! isset( $keys[ (string) $c['field'] ] ) || (string) $c['field'] === $key ) {
					/* translators: 1: key, 2: condition name, 3: referenced field */
					$err[] = sprintf( __( '"%1$s" %2$s refers to unknown field "%3$s".', 'qhta-competitions' ), $key, $cond, (string) $c['field'] );
				} elseif ( 'label_if' === $cond && empty( $c['label'] ) ) {
					/* translators: %s: key */
					$err[] = sprintf( __( '"%s" label_if needs a label.', 'qhta-competitions' ), $key );
				}
			}

			$out[] = $f;
		}
		return $out;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_declarations( $v, &$err ) {
		if ( ! self::is_list( $v ) ) {
			$err[] = __( 'must be a JSON array of declarations.', 'qhta-competitions' );
			return array();
		}
		$seen = array();
		$out  = array();
		foreach ( $v as $i => $d ) {
			$n = $i + 1;
			if ( ! self::is_object( $d ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d must be an object.', 'qhta-competitions' ), $n );
				continue;
			}
			$key = isset( $d['key'] ) ? (string) $d['key'] : '';
			if ( ! preg_match( '/^[a-z0-9_]+$/', $key ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d needs a key (lowercase letters, digits, _).', 'qhta-competitions' ), $n );
			} elseif ( isset( $seen[ $key ] ) ) {
				/* translators: %s: key */
				$err[] = sprintf( __( 'duplicate key "%s".', 'qhta-competitions' ), $key );
			}
			$seen[ $key ] = true;
			if ( empty( $d['text'] ) || ! is_string( $d['text'] ) ) {
				/* translators: %s: key */
				$err[] = sprintf( __( '"%s" needs text.', 'qhta-competitions' ), $key );
			}
			$d['required'] = ! isset( $d['required'] ) || (bool) $d['required'];
			$out[]         = $d;
		}
		return $out;
	}

	/**
	 * @param mixed    $v      Value.
	 * @param string[] $err    Errors.
	 * @param array    $config Whole posted config (for field lookups).
	 * @return array
	 */
	private static function validate_confirmer( $v, &$err, $config = array() ) {
		if ( ! self::is_object( $v ) ) {
			$err[] = __( 'must be an object.', 'qhta-competitions' );
			return array();
		}
		$role = isset( $v['role'] ) ? $v['role'] : 'none';
		if ( ! in_array( $role, self::CONFIRMER_ROLES, true ) ) {
			$err[] = __( 'role must be none or referee.', 'qhta-competitions' );
		}
		$v['role']        = $role;
		$v['label']       = isset( $v['label'] ) ? (string) $v['label'] : '';
		$v['email_field'] = isset( $v['email_field'] ) ? (string) $v['email_field'] : '';
		$v['name_field']  = isset( $v['name_field'] ) ? (string) $v['name_field'] : '';
		$v['declaration'] = isset( $v['declaration'] ) ? (string) $v['declaration'] : '';
		if ( 'none' !== $role ) {
			if ( '' === $v['email_field'] ) {
				$err[] = __( 'email_field is required when a confirmer is used.', 'qhta-competitions' );
			} elseif ( isset( $config['fields'] ) && is_array( $config['fields'] ) ) {
				$found = false;
				foreach ( $config['fields'] as $f ) {
					if ( is_array( $f ) && isset( $f['key'], $f['type'] ) && $f['key'] === $v['email_field'] && 'email' === $f['type'] ) {
						$found = true;
					}
				}
				if ( ! $found ) {
					/* translators: %s: field key */
					$err[] = sprintf( __( 'email_field "%s" must be an email field in the form.', 'qhta-competitions' ), $v['email_field'] );
				}
			}
		}
		return $v;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_eligibility( $v, &$err ) {
		if ( ! self::is_list( $v ) ) {
			$err[] = __( 'must be a JSON array of rules.', 'qhta-competitions' );
			return array();
		}
		foreach ( $v as $i => $r ) {
			$n = $i + 1;
			if ( ! self::is_object( $r ) || empty( $r['rule'] ) || ! in_array( $r['rule'], self::ELIGIBILITY_RULES, true ) ) {
				/* translators: 1: position, 2: allowed rules */
				$err[] = sprintf( __( 'item %1$d rule must be one of: %2$s.', 'qhta-competitions' ), $n, implode( ', ', self::ELIGIBILITY_RULES ) );
				continue;
			}
			if ( isset( $r['mode'] ) && ! in_array( $r['mode'], self::ELIGIBILITY_MODES, true ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d mode must be block or flag.', 'qhta-competitions' ), $n );
			}
			if ( 'required_true' === $r['rule'] && empty( $r['field'] ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'item %d (required_true) needs a field.', 'qhta-competitions' ), $n );
			}
		}
		return $v;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_rubric( $v, &$err ) {
		if ( ! self::is_object( $v ) ) {
			$err[] = __( 'must be an object {"criteria": [...]}.', 'qhta-competitions' );
			return array( 'criteria' => array() );
		}
		$criteria = isset( $v['criteria'] ) ? $v['criteria'] : array();
		if ( ! self::is_list( $criteria ) ) {
			$err[] = __( 'criteria must be an array.', 'qhta-competitions' );
			$criteria = array();
		}
		$seen = array();
		foreach ( $criteria as $i => $c ) {
			$n = $i + 1;
			if ( ! self::is_object( $c ) || empty( $c['key'] ) || ! preg_match( '/^[a-z0-9_]+$/', (string) $c['key'] ) ) {
				/* translators: %d: position */
				$err[] = sprintf( __( 'criterion %d needs a key (lowercase letters, digits, _).', 'qhta-competitions' ), $n );
				continue;
			}
			if ( isset( $seen[ $c['key'] ] ) ) {
				/* translators: %s: key */
				$err[] = sprintf( __( 'duplicate criterion "%s".', 'qhta-competitions' ), $c['key'] );
			}
			$seen[ $c['key'] ] = true;
			if ( ! isset( $c['max'] ) || ! is_numeric( $c['max'] ) || $c['max'] <= 0 ) {
				/* translators: %s: key */
				$err[] = sprintf( __( '"%s" max must be a positive number.', 'qhta-competitions' ), $c['key'] );
			}
			$weight = isset( $c['weight'] ) ? $c['weight'] : 1;
			if ( ! is_numeric( $weight ) || $weight < 0 ) {
				/* translators: %s: key */
				$err[] = sprintf( __( '"%s" weight must be zero or more.', 'qhta-competitions' ), $c['key'] );
			}
			$criteria[ $i ]['weight'] = is_numeric( $weight ) ? $weight + 0 : 1;
			$criteria[ $i ]['label']  = isset( $c['label'] ) ? (string) $c['label'] : $c['key'];
		}
		$v['criteria'] = $criteria;
		return $v;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_entry_limits( $v, &$err ) {
		if ( ! self::is_object( $v ) ) {
			$err[] = __( 'must be an object.', 'qhta-competitions' );
			return array();
		}
		foreach ( array( 'per_entrant_per_category', 'per_school_per_category', 'per_entrant_total' ) as $k ) {
			$v[ $k ] = self::int_or_null( isset( $v[ $k ] ) ? $v[ $k ] : null, $err, $k, 1 );
		}
		return $v;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_emails( $v, &$err ) {
		if ( ! self::is_object( $v ) ) {
			$err[] = __( 'must be an object keyed by event.', 'qhta-competitions' );
			return array();
		}
		foreach ( $v as $event => $tpl ) {
			if ( ! in_array( $event, self::EMAIL_EVENTS, true ) ) {
				/* translators: 1: event, 2: allowed events */
				$err[] = sprintf( __( 'unknown event "%1$s" (allowed: %2$s).', 'qhta-competitions' ), $event, implode( ', ', self::EMAIL_EVENTS ) );
				continue;
			}
			if ( ! self::is_object( $tpl ) || ! isset( $tpl['subject'], $tpl['body'] ) || ! is_string( $tpl['subject'] ) || ! is_string( $tpl['body'] ) ) {
				/* translators: %s: event */
				$err[] = sprintf( __( '"%s" needs string subject and body.', 'qhta-competitions' ), $event );
			}
		}
		return $v;
	}

	/**
	 * @param mixed    $v   Value.
	 * @param string[] $err Errors.
	 * @return array
	 */
	private static function validate_word_count_rules( $v, &$err ) {
		if ( ! self::is_object( $v ) ) {
			$err[] = __( 'must be an object.', 'qhta-competitions' );
			return array();
		}
		$v['include_footnotes'] = ! isset( $v['include_footnotes'] ) || (bool) $v['include_footnotes'];
		$headings               = isset( $v['exclude_after_headings'] ) ? $v['exclude_after_headings'] : array();
		if ( ! self::is_list( $headings ) ) {
			$err[] = __( 'exclude_after_headings must be an array.', 'qhta-competitions' );
			$headings = array();
		}
		$v['exclude_after_headings'] = array_values( array_filter( array_map( 'trim', array_map( 'strval', $headings ) ), 'strlen' ) );
		return $v;
	}

	/* ---------------------------------------------------------------------
	 * Lookups
	 * ------------------------------------------------------------------ */

	/**
	 * Find a program by slug or ID (shortcode attribute).
	 *
	 * @param string|int $ref Slug or ID.
	 * @return WP_Post|null
	 */
	public static function find( $ref ) {
		if ( is_numeric( $ref ) ) {
			$post = get_post( (int) $ref );
			return ( $post && self::POST_TYPE === $post->post_type ) ? $post : null;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => sanitize_title( (string) $ref ),
				'post_status'    => array( 'publish', 'private', 'draft', 'future', 'pending' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Where now falls relative to the round's submission window.
	 *
	 * @param array    $config Round config.
	 * @param int|null $now    Timestamp (default now).
	 * @return string before|open|closed
	 */
	public static function window( array $config, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$opens  = Qhta_Competitions_Util::utc_to_timestamp( $config['opens_at'] );
		$closes = Qhta_Competitions_Util::utc_to_timestamp( $config['closes_at'] );
		if ( $opens && $now < $opens ) {
			return 'before';
		}
		if ( $closes && $now >= $closes + 60 * max( 0, (int) $config['late_grace_minutes'] ) ) {
			return 'closed';
		}
		return 'open';
	}

	/**
	 * First field carrying a semantic role (entrant_name, school, submitter_email).
	 *
	 * @param array  $config Round config.
	 * @param string $role   Role.
	 * @return array|null
	 */
	public static function field_by_role( array $config, $role ) {
		foreach ( $config['fields'] as $f ) {
			if ( isset( $f['role'] ) && $role === $f['role'] ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Key of the submitter's email field: role submitter_email, else the first
	 * email field that isn't the confirmer's.
	 *
	 * @param array $config Round config.
	 * @return string '' when the form has none.
	 */
	public static function submitter_email_key( array $config ) {
		$f = self::field_by_role( $config, 'submitter_email' );
		if ( $f ) {
			return $f['key'];
		}
		$confirmer = isset( $config['confirmer']['email_field'] ) ? $config['confirmer']['email_field'] : '';
		foreach ( $config['fields'] as $f ) {
			if ( 'email' === $f['type'] && $f['key'] !== $confirmer ) {
				return $f['key'];
			}
		}
		return '';
	}

	/**
	 * Category by slug.
	 *
	 * @param array  $config Round config.
	 * @param string $slug   Slug.
	 * @return array|null
	 */
	public static function category( array $config, $slug ) {
		foreach ( $config['categories'] as $c ) {
			if ( $c['slug'] === $slug ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Whether the round needs a confirmer.
	 *
	 * @param array $config Round config.
	 * @return bool
	 */
	public static function has_confirmer( array $config ) {
		return isset( $config['confirmer']['role'] ) && 'none' !== $config['confirmer']['role'];
	}
}
