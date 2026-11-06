<?php
/**
 * Program round admin: edit-screen meta boxes, save, "New from preset", list columns.
 *
 * Simple settings are form inputs; structured config (categories, fields,
 * declarations, eligibility, rubric, emails) is edited as validated JSON.
 * Invalid JSON is never saved and never lost: the rejected text is put back
 * in the box with the error on the next screen load.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Program admin screens.
 */
final class Qhta_Competitions_Admin_Programs {

	const NONCE        = 'qc_program_nonce';
	const NONCE_ACTION = 'qc_program_save';

	/** User meta: notices to show on next load. */
	const NOTICE_META = 'qc_admin_notices';

	/** User meta prefix: rejected JSON per program. */
	const REJECTED_META = 'qc_rejected_';

	/** Config sections edited as JSON. */
	const JSON_SECTIONS = array( 'categories', 'fields', 'declarations', 'eligibility', 'rubric', 'emails' );

	/**
	 * Hooks.
	 */
	public static function init() {
		$pt = Qhta_Competitions_Program_CPT::POST_TYPE;

		add_action( 'add_meta_boxes_' . $pt, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . $pt, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_qc_create_from_preset', array( __CLASS__, 'handle_create_from_preset' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_filter( 'manage_' . $pt . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . $pt . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/**
	 * Whether the current screen is one of ours (page-scoped assets).
	 *
	 * @return bool
	 */
	private static function is_our_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ( Qhta_Competitions_Program_CPT::POST_TYPE === $screen->post_type || false !== strpos( (string) $screen->id, 'qc-presets' ) );
	}

	/**
	 * Admin CSS on our screens only.
	 */
	public static function assets() {
		if ( self::is_our_screen() ) {
			wp_enqueue_style( 'qc-admin', QC_URL . 'assets/css/admin.css', array(), QC_VERSION );
		}
	}

	/* ---------------------------------------------------------------------
	 * New from preset
	 * ------------------------------------------------------------------ */

	/**
	 * Submenu page.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Qhta_Competitions_Program_CPT::POST_TYPE,
			__( 'New round from preset', 'qhta-competitions' ),
			__( 'New from preset', 'qhta-competitions' ),
			Qhta_Competitions_Plugin::CAP_MANAGE,
			'qc-presets',
			array( __CLASS__, 'render_presets_page' )
		);
	}

	/**
	 * Render the preset picker.
	 */
	public static function render_presets_page() {
		if ( ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'qhta-competitions' ) );
		}
		echo '<div class="wrap qc-admin"><h1>' . esc_html__( 'New round from preset', 'qhta-competitions' ) . '</h1>';
		echo '<p>' . esc_html__( 'Creates a draft round pre-filled from the preset. Every setting, including the dates, can be changed before you publish.', 'qhta-competitions' ) . '</p>';
		echo '<table class="widefat striped qc-presets"><tbody>';
		foreach ( Qhta_Competitions_Presets::all() as $id => $preset ) {
			echo '<tr><td><strong>' . esc_html( $preset['title'] ) . '</strong><br><span class="description">' . esc_html( $preset['description'] ) . '</span></td><td class="qc-presets__action">';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="qc_create_from_preset">';
			echo '<input type="hidden" name="preset" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'qc_create_from_preset' );
			submit_button( __( 'Create round', 'qhta-competitions' ), 'primary', 'submit', false );
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Create a round from a preset, then go to its edit screen.
	 */
	public static function handle_create_from_preset() {
		if ( ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'qhta-competitions' ), 403 );
		}
		check_admin_referer( 'qc_create_from_preset' );
		$id = isset( $_POST['preset'] ) ? sanitize_file_name( wp_unslash( $_POST['preset'] ) ) : '';

		list( $post_id, $errors ) = Qhta_Competitions_Presets::create_round( $id );
		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ) );
		}
		$messages = array( array( 'success', __( 'Round created from preset as a draft. Check the dates and settings, then publish.', 'qhta-competitions' ) ) );
		foreach ( $errors as $e ) {
			$messages[] = array( 'error', $e );
		}
		self::queue_notices( $messages );
		wp_safe_redirect( get_edit_post_link( $post_id, 'raw' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Notices (user meta, not transients — Hostinger object-cache issue)
	 * ------------------------------------------------------------------ */

	/**
	 * Queue notices for the current user's next screen.
	 *
	 * @param array<int, array{0:string,1:string}> $messages [type, text] pairs.
	 */
	private static function queue_notices( array $messages ) {
		$existing = get_user_meta( get_current_user_id(), self::NOTICE_META, true );
		$existing = is_array( $existing ) ? $existing : array();
		update_user_meta( get_current_user_id(), self::NOTICE_META, array_merge( $existing, $messages ) );
	}

	/**
	 * Print and clear queued notices.
	 */
	public static function notices() {
		if ( ! self::is_our_screen() ) {
			return;
		}
		$messages = get_user_meta( get_current_user_id(), self::NOTICE_META, true );
		if ( ! is_array( $messages ) || ! $messages ) {
			return;
		}
		delete_user_meta( get_current_user_id(), self::NOTICE_META );
		foreach ( $messages as $m ) {
			$type = in_array( $m[0], array( 'success', 'error', 'warning', 'info' ), true ) ? $m[0] : 'info';
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $m[1] ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Meta boxes
	 * ------------------------------------------------------------------ */

	/**
	 * Register meta boxes.
	 */
	public static function meta_boxes() {
		$pt = Qhta_Competitions_Program_CPT::POST_TYPE;

		add_meta_box( 'qc_flag', __( 'Feature flag', 'qhta-competitions' ), array( __CLASS__, 'box_flag' ), $pt, 'side', 'high' );
		add_meta_box( 'qc_shortcode', __( 'Shortcode', 'qhta-competitions' ), array( __CLASS__, 'box_shortcode' ), $pt, 'side' );

		add_meta_box( 'qc_schedule', __( 'Round settings', 'qhta-competitions' ), array( __CLASS__, 'box_schedule' ), $pt, 'normal', 'high' );
		add_meta_box( 'qc_categories', __( 'Categories', 'qhta-competitions' ), array( __CLASS__, 'box_json' ), $pt, 'normal', 'default', array( 'section' => 'categories' ) );
		add_meta_box( 'qc_fields', __( 'Form fields', 'qhta-competitions' ), array( __CLASS__, 'box_json' ), $pt, 'normal', 'default', array( 'section' => 'fields' ) );
		add_meta_box( 'qc_declarations', __( 'Declarations', 'qhta-competitions' ), array( __CLASS__, 'box_json' ), $pt, 'normal', 'default', array( 'section' => 'declarations' ) );
		add_meta_box( 'qc_privacy', __( 'Privacy notice', 'qhta-competitions' ), array( __CLASS__, 'box_privacy' ), $pt, 'normal' );
		add_meta_box( 'qc_confirmer', __( 'Confirmer (referee)', 'qhta-competitions' ), array( __CLASS__, 'box_confirmer' ), $pt, 'normal' );
		add_meta_box( 'qc_eligibility', __( 'Eligibility rules', 'qhta-competitions' ), array( __CLASS__, 'box_json' ), $pt, 'normal', 'default', array( 'section' => 'eligibility' ) );
		add_meta_box( 'qc_limits', __( 'Entry limits', 'qhta-competitions' ), array( __CLASS__, 'box_limits' ), $pt, 'normal' );
		add_meta_box( 'qc_wordcount', __( 'Word count rules', 'qhta-competitions' ), array( __CLASS__, 'box_wordcount' ), $pt, 'normal' );
		add_meta_box( 'qc_rubric', __( 'Judging rubric', 'qhta-competitions' ), array( __CLASS__, 'box_json' ), $pt, 'normal', 'default', array( 'section' => 'rubric' ) );
		add_meta_box( 'qc_emails', __( 'Email templates', 'qhta-competitions' ), array( __CLASS__, 'box_json' ), $pt, 'normal', 'default', array( 'section' => 'emails' ) );
		add_meta_box( 'qc_award', __( 'Award (bursary)', 'qhta-competitions' ), array( __CLASS__, 'box_award' ), $pt, 'normal' );
	}

	/**
	 * Feature-flag status box (spec §5.11 admin integration).
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_flag( $post ) {
		$key = Qhta_Competitions_Flags::key_for( $post->ID );
		if ( ! Qhta_Competitions_Flags::active() ) {
			echo '<p class="qc-warn">' . esc_html__( 'wordpress-feature-flags is not active. Every round form is hidden until it is (fail closed).', 'qhta-competitions' ) . '</p>';
		}
		if ( '' === $key ) {
			echo '<p>' . esc_html__( 'A flag is created when the round is first saved.', 'qhta-competitions' ) . '</p>';
			return;
		}
		echo '<p><strong>' . esc_html__( 'Key', 'qhta-competitions' ) . ':</strong> <code>' . esc_html( $key ) . '</code></p>';
		$mode = Qhta_Competitions_Flags::mode( $key );
		if ( $mode ) {
			echo '<p><strong>' . esc_html__( 'Mode', 'qhta-competitions' ) . ':</strong> ' . self::badge( $mode[0] ) . ' <span class="description">(' . esc_html( $mode[1] ) . ')</span></p>'; // Badge markup is escaped inside badge().
			echo '<p><strong>' . esc_html__( 'Live for public?', 'qhta-competitions' ) . '</strong> ' . ( Qhta_Competitions_Flags::is_live( $key ) ? esc_html__( 'Yes', 'qhta-competitions' ) : esc_html__( 'No', 'qhta-competitions' ) ) . '</p>';
			if ( 'unregistered' === $mode[1] ) {
				echo '<p class="description">' . esc_html__( 'Not registered yet — reload this screen after saving.', 'qhta-competitions' ) . '</p>';
			}
		}
		echo '<p><a href="' . esc_url( Qhta_Competitions_Flags::admin_url() ) . '">' . esc_html__( 'Tools → Feature flags', 'qhta-competitions' ) . '</a></p>';
		$preview = Qhta_Competitions_Flags::preview_url( $key );
		if ( '' !== $preview ) {
			echo '<p><strong>' . esc_html__( 'Secret preview link', 'qhta-competitions' ) . ':</strong><br><input type="text" class="widefat code" readonly value="' . esc_attr( $preview ) . '" onfocus="this.select()"></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'No secret preview link: set WPFF_PREVIEW_TOKEN (or a per-flag token) in wp-config.php.', 'qhta-competitions' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'The key never changes, even if the round slug does.', 'qhta-competitions' ) . '</p>';
	}

	/**
	 * Mode badge HTML.
	 *
	 * @param string $mode off|preview|on.
	 * @return string
	 */
	private static function badge( $mode ) {
		$mode = in_array( $mode, array( 'off', 'preview', 'on' ), true ) ? $mode : 'off';
		return '<span class="qc-badge qc-badge--' . esc_attr( $mode ) . '">' . esc_html( $mode ) . '</span>';
	}

	/**
	 * Shortcode help.
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_shortcode( $post ) {
		$ref = '' !== $post->post_name ? $post->post_name : (string) $post->ID;
		$key = Qhta_Competitions_Flags::key_for( $post->ID );
		echo '<input type="text" class="widefat code" readonly onfocus="this.select()" value="' . esc_attr( '[qhta_program_form program="' . $ref . '"]' ) . '">';
		echo '<p class="description">' . esc_html__( 'Put this on the round\'s page.', 'qhta-competitions' ) . ' ';
		if ( '' !== $key ) {
			/* translators: %s: flag key */
			echo esc_html( sprintf( __( 'Also tag that page (and any menu item) with the flag "%s" in the Feature flag box on the page editor, so the page itself is hidden while the round is off or in preview.', 'qhta-competitions' ), $key ) );
		}
		echo '</p>';
	}

	/**
	 * Rejected JSON for this user/program, if any.
	 *
	 * @param int $post_id Program ID.
	 * @return array<string,array{raw:string,error:string}>
	 */
	private static function rejected( $post_id ) {
		static $cache = array();
		if ( ! isset( $cache[ $post_id ] ) ) {
			$meta              = get_user_meta( get_current_user_id(), self::REJECTED_META . $post_id, true );
			$cache[ $post_id ] = is_array( $meta ) ? $meta : array();
			if ( $cache[ $post_id ] ) {
				delete_user_meta( get_current_user_id(), self::REJECTED_META . $post_id );
			}
		}
		return $cache[ $post_id ];
	}

	/**
	 * Round settings box (also prints the nonce).
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_schedule( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE );
		echo '<input type="hidden" name="qc[_present]" value="1">';
		$c  = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		$tz = wp_timezone_string();
		?>
		<table class="form-table qc-form-table">
			<tr>
				<th><label for="qc_type"><?php esc_html_e( 'Type', 'qhta-competitions' ); ?></label></th>
				<td>
					<select id="qc_type" name="qc[type]">
						<?php foreach ( Qhta_Competitions_Program_CPT::TYPES as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $c['type'], $t ); ?>><?php echo esc_html( ucfirst( $t ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Drives defaults only; behaviour comes from the settings below.', 'qhta-competitions' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="qc_opens_at"><?php esc_html_e( 'Opens', 'qhta-competitions' ); ?></label></th>
				<td><input type="datetime-local" id="qc_opens_at" name="qc[opens_at]" value="<?php echo esc_attr( Qhta_Competitions_Util::utc_to_local_input( $c['opens_at'] ) ); ?>"> <span class="description"><?php echo esc_html( $tz ); ?></span></td>
			</tr>
			<tr>
				<th><label for="qc_closes_at"><?php esc_html_e( 'Closes', 'qhta-competitions' ); ?></label></th>
				<td><input type="datetime-local" id="qc_closes_at" name="qc[closes_at]" value="<?php echo esc_attr( Qhta_Competitions_Util::utc_to_local_input( $c['closes_at'] ) ); ?>"> <span class="description"><?php echo esc_html( $tz ); ?></span>
					<p class="description"><?php esc_html_e( 'Submissions are accepted until exactly this date and time (plus any grace below). "Before 29 January" = 29 January 00:00.', 'qhta-competitions' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="qc_grace"><?php esc_html_e( 'Late grace (minutes)', 'qhta-competitions' ); ?></label></th>
				<td><input type="number" min="0" step="1" id="qc_grace" name="qc[late_grace_minutes]" value="<?php echo esc_attr( (string) $c['late_grace_minutes'] ); ?>" class="small-text"></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Anonymised judging', 'qhta-competitions' ); ?></th>
				<td><label><input type="checkbox" name="qc[anonymise]" value="1" <?php checked( $c['anonymise'] ); ?>> <?php esc_html_e( 'Judges see reference codes only, never identifying (PII) fields', 'qhta-competitions' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="qc_spread"><?php esc_html_e( 'Judge spread warning', 'qhta-competitions' ); ?></label></th>
				<td><input type="number" min="0" max="100" step="1" id="qc_spread" name="qc[judge_spread_margin]" value="<?php echo esc_attr( (string) $c['judge_spread_margin'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'percentage points — results warn when judges\' scores for an entry differ by more than this', 'qhta-competitions' ); ?></span></td>
			</tr>
			<tr>
				<th><label for="qc_max_upload"><?php esc_html_e( 'Max upload size (MB)', 'qhta-competitions' ); ?></label></th>
				<td><input type="number" min="1" step="1" id="qc_max_upload" name="qc[max_upload_mb]" value="<?php echo esc_attr( (string) $c['max_upload_mb'] ); ?>" class="small-text"> <span class="description"><?php esc_html_e( '.docx and .pdf only.', 'qhta-competitions' ); ?></span></td>
			</tr>
			<tr>
				<th><label for="qc_retention"><?php esc_html_e( 'Retention (months after close)', 'qhta-competitions' ); ?></label></th>
				<td><input type="number" min="1" step="1" id="qc_retention" name="qc[retention_months]" value="<?php echo esc_attr( (string) $c['retention_months'] ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Personal details and files of entries that were not awarded or commended are purged this long after the round closes.', 'qhta-competitions' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Reference help for each JSON section.
	 *
	 * @param string $section Section.
	 * @return string
	 */
	private static function json_help( $section ) {
		switch ( $section ) {
			case 'categories':
				return __( 'Array of {slug, label, year_levels[], word_min, word_max, submission_kind: upload|inline|both}. year_levels limits which year levels may choose the category (empty = any).', 'qhta-competitions' );
			case 'fields':
				return sprintf(
					/* translators: %s: field types */
					__( 'Ordered array of {key, type, label, required, help, options[], show_if:{field,equals}, label_if:{field,equals,label}, judge_visible, pii}. Types: %s. pii fields are hidden from judges and purged by retention. A text field keyed word_count_declared is the declared word count.', 'qhta-competitions' ),
					implode( ', ', Qhta_Competitions_Program_CPT::FIELD_TYPES )
				);
			case 'declarations':
				return __( 'Array of {key, text, required}. The text is shown in full and stored verbatim on each submission. Mark the publication consent declaration with "kind": "publication_consent" so the consent register can report it.', 'qhta-competitions' );
			case 'eligibility':
				return sprintf(
					/* translators: %s: rules */
					__( 'Array of {rule, mode: block|flag, field?, message?}. Rules: %s.', 'qhta-competitions' ),
					implode( ', ', Qhta_Competitions_Program_CPT::ELIGIBILITY_RULES )
				);
			case 'rubric':
				return __( '{"criteria": [{key, label, max, weight}]}. Weighted mean per judge, averaged across judges.', 'qhta-competitions' );
			case 'emails':
				return sprintf(
					/* translators: %s: events */
					__( 'Object keyed by event, each {subject, body}. Events: %s. Tokens: {{ref}}, {{program}}, {{category}}, {{status}}, {{closes_at}}, {{confirm_url}}, {{confirm_expires}}, {{admin_url}}, {{flags}}, {{registration_instructions}}, and any field key, e.g. {{full_name}}.', 'qhta-competitions' ),
					implode( ', ', Qhta_Competitions_Program_CPT::EMAIL_EVENTS )
				);
		}
		return '';
	}

	/**
	 * Generic JSON editor box.
	 *
	 * @param WP_Post $post Program.
	 * @param array   $box  Meta box args.
	 */
	public static function box_json( $post, $box ) {
		$section  = $box['args']['section'];
		$config   = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		$rejected = self::rejected( $post->ID );
		$value    = Qhta_Competitions_Util::json_pretty( $config[ $section ] );
		$class    = 'large-text code qc-json';

		if ( isset( $rejected[ $section ] ) ) {
			$value  = $rejected[ $section ]['raw'];
			$class .= ' qc-json--error';
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'NOT SAVED — fix and update again:', 'qhta-competitions' ) . ' ' . esc_html( $rejected[ $section ]['error'] ) . '</p></div>';
		}
		$rows = min( 40, max( 6, substr_count( $value, "\n" ) + 2 ) );
		echo '<p class="description">' . esc_html( self::json_help( $section ) ) . '</p>';
		printf(
			'<textarea name="qc_json[%1$s]" class="%2$s" rows="%3$d" spellcheck="false">%4$s</textarea>',
			esc_attr( $section ),
			esc_attr( $class ),
			(int) $rows,
			esc_textarea( $value )
		);
	}

	/**
	 * Privacy notice box.
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_privacy( $post ) {
		$c = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		echo '<p class="description">' . esc_html__( 'Shown above the declarations: what is collected, who sees it, how long it is kept, publication terms. Basic HTML allowed.', 'qhta-competitions' ) . '</p>';
		echo '<textarea name="qc[privacy_notice]" class="large-text" rows="5">' . esc_textarea( $c['privacy_notice'] ) . '</textarea>';
	}

	/**
	 * Confirmer box.
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_confirmer( $post ) {
		$c    = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		$conf = $c['confirmer'];
		$get  = function ( $k ) use ( $conf ) {
			return isset( $conf[ $k ] ) ? (string) $conf[ $k ] : '';
		};
		?>
		<table class="form-table qc-form-table">
			<tr>
				<th><label for="qc_conf_role"><?php esc_html_e( 'Confirmation', 'qhta-competitions' ); ?></label></th>
				<td><select id="qc_conf_role" name="qc_confirmer[role]">
					<option value="none" <?php selected( $get( 'role' ), 'none' ); ?>><?php esc_html_e( 'None — submitter only', 'qhta-competitions' ); ?></option>
					<option value="referee" <?php selected( $get( 'role' ), 'referee' ); ?>><?php esc_html_e( 'Referee confirms by emailed link', 'qhta-competitions' ); ?></option>
				</select></td>
			</tr>
			<tr>
				<th><label for="qc_conf_label"><?php esc_html_e( 'Label', 'qhta-competitions' ); ?></label></th>
				<td><input type="text" id="qc_conf_label" name="qc_confirmer[label]" value="<?php echo esc_attr( $get( 'label' ) ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="qc_conf_email"><?php esc_html_e( 'Email field key', 'qhta-competitions' ); ?></label></th>
				<td><input type="text" id="qc_conf_email" name="qc_confirmer[email_field]" value="<?php echo esc_attr( $get( 'email_field' ) ); ?>" class="regular-text code"></td>
			</tr>
			<tr>
				<th><label for="qc_conf_name"><?php esc_html_e( 'Name field key', 'qhta-competitions' ); ?></label></th>
				<td><input type="text" id="qc_conf_name" name="qc_confirmer[name_field]" value="<?php echo esc_attr( $get( 'name_field' ) ); ?>" class="regular-text code"></td>
			</tr>
			<tr>
				<th><label for="qc_conf_decl"><?php esc_html_e( 'Confirmer declaration', 'qhta-competitions' ); ?></label></th>
				<td><textarea id="qc_conf_decl" name="qc_confirmer[declaration]" class="large-text" rows="3"><?php echo esc_textarea( $get( 'declaration' ) ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Entry limits box.
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_limits( $post ) {
		$c      = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		$limits = $c['entry_limits'];
		$rows   = array(
			'per_entrant_per_category' => __( 'Per student/entrant, per category', 'qhta-competitions' ),
			'per_school_per_category'  => __( 'Per school, per category', 'qhta-competitions' ),
			'per_entrant_total'        => __( 'Per student/entrant, in total', 'qhta-competitions' ),
		);
		echo '<p class="description">' . esc_html__( 'Blank = unlimited. Enforced at submit (withdrawn and test entries don\'t count).', 'qhta-competitions' ) . '</p><table class="form-table qc-form-table">';
		foreach ( $rows as $k => $label ) {
			$v = isset( $limits[ $k ] ) && null !== $limits[ $k ] ? (string) $limits[ $k ] : '';
			printf(
				'<tr><th><label for="qc_lim_%1$s">%2$s</label></th><td><input type="number" min="1" step="1" class="small-text" id="qc_lim_%1$s" name="qc_limits[%1$s]" value="%3$s"></td></tr>',
				esc_attr( $k ),
				esc_html( $label ),
				esc_attr( $v )
			);
		}
		echo '</table>';
	}

	/**
	 * Word count rules box.
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_wordcount( $post ) {
		$c     = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		$rules = $c['word_count_rules'];
		$heads = isset( $rules['exclude_after_headings'] ) ? (array) $rules['exclude_after_headings'] : array();
		echo '<input type="hidden" name="qc_wc[_present]" value="1">';
		?>
		<table class="form-table qc-form-table">
			<tr>
				<th><?php esc_html_e( 'Footnotes', 'qhta-competitions' ); ?></th>
				<td><label><input type="checkbox" name="qc_wc[include_footnotes]" value="1" <?php checked( ! empty( $rules['include_footnotes'] ) ); ?>> <?php esc_html_e( 'Footnotes and endnotes count towards the word count', 'qhta-competitions' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="qc_wc_heads"><?php esc_html_e( 'Stop counting at heading', 'qhta-competitions' ); ?></label></th>
				<td><textarea id="qc_wc_heads" name="qc_wc[exclude_after_headings]" rows="4" class="regular-text"><?php echo esc_textarea( implode( "\n", $heads ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One per line, case-insensitive. Body text after the first matching heading (e.g. the bibliography) is not counted in uploaded .docx files.', 'qhta-competitions' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Award (bursary coupon) box.
	 *
	 * @param WP_Post $post Program.
	 */
	public static function box_award( $post ) {
		$c = Qhta_Competitions_Program_CPT::get_config( $post->ID );
		$a = $c['award_config'];
		echo '<input type="hidden" name="qc_award[_present]" value="1">';
		?>
		<p class="description"><?php esc_html_e( 'Free ECC registration for bursary winners. How winners get it is still undecided (Q12): with the coupon off, the award email says QHTA will arrange registration and an admin creates a complimentary order by hand.', 'qhta-competitions' ); ?></p>
		<table class="form-table qc-form-table">
			<tr>
				<th><?php esc_html_e( 'Registration coupon', 'qhta-competitions' ); ?></th>
				<td><label><input type="checkbox" name="qc_award[coupon_enabled]" value="1" <?php checked( ! empty( $a['coupon_enabled'] ) ); ?>> <?php esc_html_e( 'On award, create a single-use 100% WooCommerce coupon for the winner', 'qhta-competitions' ); ?></label></td>
			</tr>
			<tr>
				<th><label for="qc_award_product"><?php esc_html_e( 'Product ID', 'qhta-competitions' ); ?></label></th>
				<td><input type="number" min="1" id="qc_award_product" name="qc_award[coupon_product_id]" value="<?php echo esc_attr( isset( $a['coupon_product_id'] ) ? (string) $a['coupon_product_id'] : '' ); ?>" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="qc_award_variation"><?php esc_html_e( 'Variation ID', 'qhta-competitions' ); ?></label></th>
				<td><input type="number" min="1" id="qc_award_variation" name="qc_award[coupon_variation_id]" value="<?php echo esc_attr( isset( $a['coupon_variation_id'] ) ? (string) $a['coupon_variation_id'] : '' ); ?>" class="small-text"> <span class="description"><?php esc_html_e( 'Optional: restrict to one ticket variation.', 'qhta-competitions' ); ?></span></td>
			</tr>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Save
	 * ------------------------------------------------------------------ */

	/**
	 * Save handler.
	 *
	 * @param int     $post_id Program ID.
	 * @param WP_Post $post    Program.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( Qhta_Competitions_Plugin::CAP_MANAGE ) ) {
			return;
		}

		$config   = self::config_from_request( $post_id, $date_errors );
		$rejected = array();
		$messages = array();

		// JSON sections: decode; undecodable text is kept for the user, not saved.
		$json = isset( $_POST['qc_json'] ) && is_array( $_POST['qc_json'] ) ? wp_unslash( $_POST['qc_json'] ) : array(); // Decoded and validated per section below.
		foreach ( self::JSON_SECTIONS as $section ) {
			if ( ! isset( $json[ $section ] ) ) {
				continue;
			}
			$raw     = (string) $json[ $section ];
			$decoded = json_decode( $raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$rejected[ $section ] = array(
					'raw'   => $raw,
					'error' => json_last_error_msg(),
				);
				/* translators: 1: section, 2: JSON error */
				$messages[] = array( 'error', sprintf( __( '%1$s not saved — invalid JSON: %2$s', 'qhta-competitions' ), Qhta_Competitions_Program_CPT::section_label( $section ), json_last_error_msg() ) );
				continue;
			}
			$config[ $section ] = $decoded;
		}

		// Confirmer validation looks up the email field: use stored fields if the posted ones were rejected.
		if ( ! isset( $config['fields'] ) ) {
			$stored           = Qhta_Competitions_Program_CPT::get_config( $post_id );
			$config['fields'] = $stored['fields'];
		}

		$errors = Qhta_Competitions_Program_CPT::save_config( $post_id, $config );

		// Validation errors in a JSON section: keep the user's text too.
		foreach ( self::JSON_SECTIONS as $section ) {
			$label = Qhta_Competitions_Program_CPT::section_label( $section ) . ':';
			foreach ( $errors as $e ) {
				if ( 0 === strpos( $e, $label ) && ! isset( $rejected[ $section ] ) && isset( $json[ $section ] ) ) {
					$rejected[ $section ] = array(
						'raw'   => (string) $json[ $section ],
						'error' => $e,
					);
				}
			}
		}

		foreach ( array_merge( $date_errors, $errors ) as $e ) {
			$messages[] = array( 'error', $e );
		}
		if ( $rejected ) {
			update_user_meta( get_current_user_id(), self::REJECTED_META . $post_id, $rejected );
		}
		if ( $messages ) {
			self::queue_notices( $messages );
		}
	}

	/**
	 * Build a config array from the non-JSON inputs.
	 *
	 * @param int      $post_id     Program ID.
	 * @param string[] $date_errors Date parse errors (by ref).
	 * @return array
	 */
	private static function config_from_request( $post_id, &$date_errors ) {
		$date_errors = array();
		// Nonce verified in save(). Each box posts a `_present` marker; only then
		// does an absent checkbox mean "unticked" (a box that wasn't posted is left alone).
		$in     = isset( $_POST['qc'] ) && is_array( $_POST['qc'] ) ? wp_unslash( $_POST['qc'] ) : array();
		$config = array();
		$stored = Qhta_Competitions_Program_CPT::get_config( $post_id );

		if ( isset( $in['type'] ) ) {
			$config['type'] = sanitize_key( $in['type'] );
		}
		foreach ( array( 'opens_at', 'closes_at' ) as $name ) {
			if ( ! isset( $in[ $name ] ) ) {
				continue;
			}
			$utc = Qhta_Competitions_Util::local_input_to_utc( sanitize_text_field( $in[ $name ] ) );
			if ( null === $utc ) {
				/* translators: %s: Opens/Closes */
				$date_errors[] = sprintf( __( '%s: invalid date/time — not saved.', 'qhta-competitions' ), Qhta_Competitions_Program_CPT::section_label( $name ) );
				continue;
			}
			$config[ $name ] = $utc;
		}
		foreach ( array( 'late_grace_minutes', 'retention_months', 'max_upload_mb', 'judge_spread_margin' ) as $name ) {
			if ( isset( $in[ $name ] ) ) {
				$config[ $name ] = trim( sanitize_text_field( $in[ $name ] ) );
			}
		}
		if ( ! empty( $in['_present'] ) ) {
			$config['anonymise'] = ! empty( $in['anonymise'] );
		}
		if ( isset( $in['privacy_notice'] ) ) {
			$config['privacy_notice'] = $in['privacy_notice'];
		}

		// Confirmer: merge onto stored so keys not on the form are kept.
		if ( isset( $_POST['qc_confirmer'] ) && is_array( $_POST['qc_confirmer'] ) ) {
			$posted              = wp_unslash( $_POST['qc_confirmer'] );
			$conf                = is_array( $stored['confirmer'] ) ? $stored['confirmer'] : array();
			$conf['role']        = isset( $posted['role'] ) ? sanitize_key( $posted['role'] ) : 'none';
			$conf['label']       = isset( $posted['label'] ) ? sanitize_text_field( $posted['label'] ) : '';
			$conf['email_field'] = isset( $posted['email_field'] ) ? sanitize_key( $posted['email_field'] ) : '';
			$conf['name_field']  = isset( $posted['name_field'] ) ? sanitize_key( $posted['name_field'] ) : '';
			$conf['declaration'] = isset( $posted['declaration'] ) ? sanitize_textarea_field( $posted['declaration'] ) : '';
			$config['confirmer'] = $conf;
		}

		if ( isset( $_POST['qc_limits'] ) && is_array( $_POST['qc_limits'] ) ) {
			$posted = wp_unslash( $_POST['qc_limits'] );
			$limits = is_array( $stored['entry_limits'] ) ? $stored['entry_limits'] : array();
			foreach ( array( 'per_entrant_per_category', 'per_school_per_category', 'per_entrant_total' ) as $k ) {
				$limits[ $k ] = isset( $posted[ $k ] ) ? trim( sanitize_text_field( $posted[ $k ] ) ) : null;
			}
			$config['entry_limits'] = $limits;
		}

		$posted = isset( $_POST['qc_wc'] ) && is_array( $_POST['qc_wc'] ) ? wp_unslash( $_POST['qc_wc'] ) : array();
		if ( ! empty( $posted['_present'] ) ) {
			$rules                           = is_array( $stored['word_count_rules'] ) ? $stored['word_count_rules'] : array();
			$rules['include_footnotes']      = ! empty( $posted['include_footnotes'] );
			$heads                           = isset( $posted['exclude_after_headings'] ) ? sanitize_textarea_field( $posted['exclude_after_headings'] ) : '';
			$rules['exclude_after_headings'] = array_values( array_filter( array_map( 'trim', explode( "\n", $heads ) ), 'strlen' ) );
			$config['word_count_rules']      = $rules;
		}

		$posted = isset( $_POST['qc_award'] ) && is_array( $_POST['qc_award'] ) ? wp_unslash( $_POST['qc_award'] ) : array();
		if ( ! empty( $posted['_present'] ) ) {
			$award                        = is_array( $stored['award_config'] ) ? $stored['award_config'] : array();
			$award['coupon_enabled']      = ! empty( $posted['coupon_enabled'] );
			$award['coupon_product_id']   = isset( $posted['coupon_product_id'] ) ? trim( sanitize_text_field( $posted['coupon_product_id'] ) ) : null;
			$award['coupon_variation_id'] = isset( $posted['coupon_variation_id'] ) ? trim( sanitize_text_field( $posted['coupon_variation_id'] ) ) : null;
			$config['award_config']       = $award;
		}

		return $config;
	}

	/* ---------------------------------------------------------------------
	 * List columns
	 * ------------------------------------------------------------------ */

	/**
	 * Columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['qc_type']   = __( 'Type', 'qhta-competitions' );
				$out['qc_flag']   = __( 'Flag', 'qhta-competitions' );
				$out['qc_opens']  = __( 'Opens', 'qhta-competitions' );
				$out['qc_closes'] = __( 'Closes', 'qhta-competitions' );
			}
		}
		return $out;
	}

	/**
	 * Column content.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Program ID.
	 */
	public static function column( $col, $post_id ) {
		switch ( $col ) {
			case 'qc_type':
				echo esc_html( ucfirst( (string) get_post_meta( $post_id, '_qc_type', true ) ) );
				break;
			case 'qc_flag':
				$key  = Qhta_Competitions_Flags::key_for( $post_id );
				$mode = Qhta_Competitions_Flags::mode( $key );
				if ( '' === $key ) {
					echo '—';
				} elseif ( ! $mode ) {
					echo '<span class="qc-warn">' . esc_html__( 'wpff inactive', 'qhta-competitions' ) . '</span>';
				} else {
					echo self::badge( $mode[0] ) . '<br><code class="qc-flag-key">' . esc_html( $key ) . '</code>'; // Badge escaped inside.
				}
				break;
			case 'qc_opens':
				echo esc_html( Qhta_Competitions_Util::display_datetime( (string) get_post_meta( $post_id, '_qc_opens_at', true ) ) );
				break;
			case 'qc_closes':
				echo esc_html( Qhta_Competitions_Util::display_datetime( (string) get_post_meta( $post_id, '_qc_closes_at', true ) ) );
				break;
		}
	}
}
