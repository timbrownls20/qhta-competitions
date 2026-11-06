<?php
/**
 * wordpress-feature-flags integration (spec §5.11). The ONLY class that calls wpff_* functions.
 *
 * Visibility = flags × dates. Flags decide who can see a surface; the round's
 * opens/closes decide when submissions are accepted. wpff's own starts/ends are
 * never used.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flag registration, round-flag cache and visibility checks.
 */
final class Qhta_Competitions_Flags {

	/** Module kill switch. */
	const MODULE_FLAG = 'competitions';

	/** Owner string shown in Tools → Feature flags. */
	const OWNER = 'qhta-competitions';

	/** Round flag key prefix. */
	const ROUND_PREFIX = 'comp-';

	/** Max key length (wpff rule). */
	const KEY_MAX = 40;

	/** Cached list of round flags, read during wpff_register (no WP_Query there). */
	const CACHE_OPTION = 'qc_round_flags';

	/** Program meta holding the flag key, set once at first save and never changed. */
	const META_KEY = '_qc_flag_key';

	/** wpff's option for flags created in its UI. */
	const WPFF_UI_OPTION = 'wpff_ui_flags';

	/** Gate results for a round surface. */
	const GATE_INACTIVE = 'inactive'; // wpff not active: render nothing (fail closed).
	const GATE_KILLED   = 'killed';   // module flag off: "temporarily unavailable".
	const GATE_HIDDEN   = 'hidden';   // round flag off for this visitor: render nothing.
	const GATE_PREVIEW  = 'preview';  // visible only via preview: test entries, dates bypassed.
	const GATE_LIVE     = 'live';     // public: date logic applies.

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wpff_register', array( __CLASS__, 'register_flags' ) );

		add_action( 'save_post_' . Qhta_Competitions_Program_CPT::POST_TYPE, array( __CLASS__, 'on_program_save' ), 20, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'on_program_status_change' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'on_program_status_change' ) );
		add_action( 'deleted_post', array( __CLASS__, 'on_program_deleted' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * wpff availability
	 * ------------------------------------------------------------------ */

	/**
	 * Whether wordpress-feature-flags is loaded.
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'wpff_flag' ) && function_exists( 'wpff_register' );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register the module flag and one flag per round, from the cached list.
	 * Always passes `default` explicitly — wpff defaults omitted ones to `off`.
	 */
	public static function register_flags() {
		if ( ! function_exists( 'wpff_register' ) ) {
			return;
		}

		wpff_register(
			self::MODULE_FLAG,
			array(
				'label'        => __( 'Competitions (module)', 'qhta-competitions' ),
				'description'  => __( 'Kill switch for every QHTA Competitions form. Off = all forms show "temporarily unavailable" and referee confirmations pause.', 'qhta-competitions' ),
				'owner'        => self::OWNER,
				'default'      => 'on',
				'admin_toggle' => true,
			)
		);

		foreach ( self::cached_round_flags() as $row ) {
			if ( empty( $row['key'] ) || ! self::valid_key( $row['key'] ) ) {
				continue;
			}
			wpff_register(
				$row['key'],
				array(
					'label'        => isset( $row['label'] ) ? (string) $row['label'] : $row['key'],
					'description'  => isset( $row['description'] ) ? (string) $row['description'] : '',
					'owner'        => self::OWNER,
					'default'      => 'preview',
					'admin_toggle' => true,
				)
			);
		}
	}

	/**
	 * Cached round flags.
	 *
	 * @return array<int, array{key:string,label:string,description:string}>
	 */
	public static function cached_round_flags() {
		$rows = get_option( self::CACHE_OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Rebuild the cached round-flag list from programs (incl. trashed; a trashed
	 * round's flag stays registered until the program is permanently deleted).
	 */
	public static function rebuild_cache() {
		$ids = get_posts(
			array(
				'post_type'        => Qhta_Competitions_Program_CPT::POST_TYPE,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$rows = array();
		foreach ( $ids as $id ) {
			$key = (string) get_post_meta( $id, self::META_KEY, true );
			if ( '' === $key ) {
				continue;
			}
			$title       = get_the_title( $id );
			$description = sprintf(
				/* translators: %s: program title */
				__( 'Round form for "%s". Default preview: admins and secret-link holders can test (test entries only). Set on to open it to the public, subject to the round dates.', 'qhta-competitions' ),
				$title
			);
			if ( 'trash' === get_post_status( $id ) ) {
				$description = __( 'TRASHED ROUND — remove this flag from pages and menus, then delete the round permanently to unregister it.', 'qhta-competitions' );
			}
			$rows[] = array(
				'key'         => $key,
				'label'       => '' !== $title ? $title : $key,
				'description' => $description,
			);
		}

		Qhta_Competitions_Util::set_option( self::CACHE_OPTION, $rows );
	}

	/**
	 * Assign the flag key on first real save, then refresh the cache.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_program_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		self::ensure_key( $post );
		self::rebuild_cache();
	}

	/**
	 * Trash/untrash of a program.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_program_status_change( $post_id ) {
		if ( Qhta_Competitions_Program_CPT::POST_TYPE === get_post_type( $post_id ) ) {
			self::rebuild_cache();
		}
	}

	/**
	 * Permanent deletion of a program: drop its flag from the cache.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Deleted post.
	 */
	public static function on_program_deleted( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && Qhta_Competitions_Program_CPT::POST_TYPE === $post->post_type ) {
			self::rebuild_cache();
		}
	}

	/* ---------------------------------------------------------------------
	 * Keys
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a key satisfies wpff's rule.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function valid_key( $key ) {
		return is_string( $key )
			&& 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,39}$/', $key )
			&& 0 !== strpos( $key, 'not-' );
	}

	/**
	 * A program's flag key ('' before its first save).
	 *
	 * @param int $program_id Program ID.
	 * @return string
	 */
	public static function key_for( $program_id ) {
		return (string) get_post_meta( $program_id, self::META_KEY, true );
	}

	/**
	 * Give a program its flag key if it has none. Never changes an existing key,
	 * so renaming the slug doesn't orphan page/menu tags.
	 *
	 * @param WP_Post $post Program.
	 * @return string The key ('' when the post has neither slug nor title yet).
	 */
	public static function ensure_key( $post ) {
		$existing = self::key_for( $post->ID );
		if ( '' !== $existing ) {
			return $existing;
		}
		$source = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		$base   = self::base_key( $source );
		if ( '' === $base ) {
			return '';
		}
		$key = self::unique_key( $base, $post->ID );
		update_post_meta( $post->ID, self::META_KEY, $key );
		return $key;
	}

	/**
	 * `comp-` + slug, a-z0-9- only, ≤40 chars.
	 *
	 * @param string $slug Program slug or title.
	 * @return string '' when nothing usable remains.
	 */
	public static function base_key( $slug ) {
		$slug = strtolower( remove_accents( rawurldecode( (string) $slug ) ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '-', $slug );
		$slug = trim( preg_replace( '/-+/', '-', $slug ), '-' );
		if ( '' === $slug ) {
			return '';
		}
		return rtrim( substr( self::ROUND_PREFIX . $slug, 0, self::KEY_MAX ), '-' );
	}

	/**
	 * Suffix `-2`, `-3`… until the key is free.
	 *
	 * @param string $base       Base key.
	 * @param int    $program_id Program being keyed (its own key doesn't count).
	 * @return string
	 */
	public static function unique_key( $base, $program_id ) {
		$taken = self::taken_keys( $program_id );
		$key   = $base;
		$n     = 2;
		while ( isset( $taken[ $key ] ) ) {
			$suffix = '-' . $n;
			$key    = rtrim( substr( $base, 0, self::KEY_MAX - strlen( $suffix ) ), '-' ) . $suffix;
			++$n;
		}
		return $key;
	}

	/**
	 * Keys already in use: registered flags, wpff UI flags and other programs' keys.
	 *
	 * @param int $exclude_program Program whose own key is ignored.
	 * @return array<string, true>
	 */
	private static function taken_keys( $exclude_program ) {
		$taken = array( self::MODULE_FLAG => true );

		if ( function_exists( 'wpff_flags' ) && did_action( 'plugins_loaded' ) ) {
			foreach ( array_keys( wpff_flags() ) as $key ) {
				$taken[ $key ] = true;
			}
		}

		$ui = get_option( self::WPFF_UI_OPTION, array() );
		if ( is_array( $ui ) ) {
			foreach ( array_keys( $ui ) as $key ) {
				$taken[ (string) $key ] = true;
			}
		}

		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s AND pm.post_id <> %d",
				self::META_KEY,
				$exclude_program
			)
		);
		foreach ( $rows as $key ) {
			$taken[ (string) $key ] = true;
		}

		// The program's own (registered) key must not count against it.
		$own = self::key_for( $exclude_program );
		if ( '' !== $own ) {
			unset( $taken[ $own ] );
		}
		return $taken;
	}

	/* ---------------------------------------------------------------------
	 * Status (admin display)
	 * ------------------------------------------------------------------ */

	/**
	 * Configured mode and its source.
	 *
	 * @param string $key Flag key.
	 * @return array{0:string,1:string}|null Null when wpff is inactive.
	 */
	public static function mode( $key ) {
		if ( ! function_exists( 'wpff_mode' ) || '' === $key ) {
			return null;
		}
		return wpff_mode( $key );
	}

	/**
	 * Whether the flag is on for everyone right now.
	 *
	 * @param string $key Flag key.
	 * @return bool
	 */
	public static function is_live( $key ) {
		return '' !== $key && function_exists( 'wpff_is_live' ) && wpff_is_live( $key );
	}

	/**
	 * Secret preview link, '' when no token is configured.
	 *
	 * @param string $key Flag key.
	 * @return string
	 */
	public static function preview_url( $key ) {
		return ( '' !== $key && function_exists( 'wpff_preview_url' ) ) ? (string) wpff_preview_url( $key ) : '';
	}

	/**
	 * Tools → Feature flags URL.
	 *
	 * @return string
	 */
	public static function admin_url() {
		return function_exists( 'wpff_admin_url' ) ? wpff_admin_url() : admin_url( 'tools.php?page=wpff-flags' );
	}

	/* ---------------------------------------------------------------------
	 * Visibility (front end) — check order per spec §5.11
	 * ------------------------------------------------------------------ */

	/**
	 * Gate for a round's public surface (the form shortcode).
	 *
	 * @param int $program_id Program ID.
	 * @return string One of the GATE_* constants.
	 */
	public static function round_gate( $program_id ) {
		if ( ! self::active() ) {
			return self::GATE_INACTIVE;
		}
		if ( ! wpff_flag( self::MODULE_FLAG ) ) {
			return self::GATE_KILLED;
		}
		$key = self::key_for( $program_id );
		if ( '' === $key || ! wpff_flag( $key ) ) {
			return self::GATE_HIDDEN;
		}
		return wpff_is_live( $key ) ? self::GATE_LIVE : self::GATE_PREVIEW;
	}

	/**
	 * Gate for referee confirmations: only the module kill switch applies.
	 *
	 * @return bool Whether confirmations may proceed.
	 */
	public static function module_enabled() {
		return self::active() && wpff_flag( self::MODULE_FLAG );
	}
}
