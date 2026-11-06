<?php
/**
 * Bursary free-registration coupon (spec §5.9). Loaded only when WooCommerce
 * is active; does nothing unless the round's award config has
 * `coupon_enabled: true` (default off — Q12 still open). WooCommerce core APIs
 * only; nothing in qhta-commerce changes.
 *
 * On award: a single-use 100% coupon `ECCB-{ref}` limited to the configured
 * product (or variation) and the applicant's email. On forfeit: an unused
 * coupon is voided (trashed).
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bursary coupon.
 */
final class Qhta_Competitions_Bursary {

	/** Submission meta: coupon post ID. */
	const COUPON_META = '_qc_coupon_id';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	/**
	 * Create on award, void on forfeit.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_transition( $new, $old, $post ) {
		if ( Qhta_Competitions_Submission_CPT::POST_TYPE !== $post->post_type || $new === $old ) {
			return;
		}
		if ( 'qc_awarded' === $new ) {
			self::ensure_coupon( $post->ID );
		} elseif ( 'qc_forfeited' === $new ) {
			self::void_coupon( $post->ID );
		}
	}

	/**
	 * The award config if the coupon applies to this submission, else null.
	 *
	 * @param int $id Submission ID.
	 * @return array|null
	 */
	private static function applicable( $id ) {
		if ( ! class_exists( 'WC_Coupon' ) || 'qc_awarded' !== get_post_status( $id ) || Qhta_Competitions_Submission_CPT::is_test( $id ) ) {
			return null;
		}
		$config = Qhta_Competitions_Program_CPT::get_config( (int) wp_get_post_parent_id( $id ) );
		$award  = $config['award_config'];
		return ! empty( $award['coupon_enabled'] ) && ! empty( $award['coupon_product_id'] ) ? $award : null;
	}

	/**
	 * Coupon code for a submission, creating the coupon if needed.
	 *
	 * @param int $id Submission ID.
	 * @return string|null Code, or null when no coupon applies.
	 */
	public static function ensure_coupon( $id ) {
		$award = self::applicable( $id );
		if ( ! $award ) {
			return null;
		}
		$existing = (int) get_post_meta( $id, self::COUPON_META, true );
		if ( $existing && 'publish' === get_post_status( $existing ) ) {
			return ( new WC_Coupon( $existing ) )->get_code();
		}

		$ref   = (string) get_post_meta( $id, Qhta_Competitions_Submission_CPT::REF_META, true );
		$code  = 'ECCB-' . $ref;
		$email = (string) get_post_meta( $id, '_qc_submitter_email', true );
		if ( ! is_email( $email ) ) {
			Qhta_Competitions_Submission_CPT::append_log( $id, '', '', __( 'Coupon not created: no applicant email', 'qhta-competitions' ) );
			return null;
		}

		// Reference codes are per round, so another round can produce the same code:
		// only reuse a coupon this submission owns; otherwise make the code unique.
		$found = wc_get_coupon_id_by_code( $code );
		if ( $found && (int) get_post_meta( $found, '_qc_submission_id', true ) !== (int) $id ) {
			$code  = $code . '-' . (int) $id;
			$found = wc_get_coupon_id_by_code( $code );
			if ( $found && (int) get_post_meta( $found, '_qc_submission_id', true ) !== (int) $id ) {
				$found = 0;
				$code  = $code . '-' . strtoupper( wp_generate_password( 4, false ) );
			}
		}
		$coupon = new WC_Coupon( $found ? $found : 0 );
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 100 );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_product_ids( array( ! empty( $award['coupon_variation_id'] ) ? (int) $award['coupon_variation_id'] : (int) $award['coupon_product_id'] ) );
		$coupon->set_email_restrictions( array( $email ) );
		/* translators: %s: reference */
		$coupon->set_description( sprintf( __( 'QHTA ECC bursary free registration — %s (created by qhta-competitions)', 'qhta-competitions' ), $ref ) );
		$coupon->update_meta_data( '_qc_submission_id', (int) $id );
		$coupon_id = $coupon->save();
		if ( ! $coupon_id ) {
			Qhta_Competitions_Submission_CPT::append_log( $id, '', '', __( 'Coupon creation FAILED', 'qhta-competitions' ) );
			return null;
		}
		update_post_meta( $id, self::COUPON_META, $coupon_id );
		/* translators: %s: code */
		Qhta_Competitions_Submission_CPT::append_log( $id, '', '', sprintf( __( 'Registration coupon %s created', 'qhta-competitions' ), strtoupper( $code ) ) );
		return $coupon->get_code();
	}

	/**
	 * Void an unused coupon when the awardee forfeits.
	 *
	 * @param int $id Submission ID.
	 */
	public static function void_coupon( $id ) {
		$cid = (int) get_post_meta( $id, self::COUPON_META, true );
		if ( ! $cid || 'publish' !== get_post_status( $cid ) ) {
			return;
		}
		$coupon = new WC_Coupon( $cid );
		if ( $coupon->get_usage_count() > 0 ) {
			Qhta_Competitions_Submission_CPT::append_log( $id, '', '', __( 'Forfeited, but the registration coupon has already been used — not voided. Cancel the order by hand if needed.', 'qhta-competitions' ) );
			return;
		}
		wp_trash_post( $cid );
		/* translators: %s: code */
		Qhta_Competitions_Submission_CPT::append_log( $id, '', '', sprintf( __( 'Registration coupon %s voided (forfeited)', 'qhta-competitions' ), strtoupper( $coupon->get_code() ) ) );
	}
}
