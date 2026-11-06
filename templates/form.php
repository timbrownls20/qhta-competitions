<?php
/**
 * Submission form layout. Override by copying to wp-content/uploads/qhta-competitions/templates/form.php.
 *
 * $view keys: program (WP_Post), config (array), preview (bool), errors (array),
 * form_id, action_url, hidden (HTML), fields_html (HTML), declarations (HTML),
 * submit_label, categories (JSON). All *_html / hidden / declarations are pre-escaped.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$qc_general = isset( $view['errors']['_form'] ) ? (array) $view['errors']['_form'] : array();
$qc_count   = count( array_diff_key( $view['errors'], array( '_form' => 1, '_files_lost' => 1 ) ) );
?>
<div class="qc-form<?php echo $view['preview'] ? ' qc-form--preview' : ''; ?>" id="<?php echo esc_attr( $view['form_id'] ); ?>">
	<?php if ( $view['preview'] ) : ?>
		<div class="qc-preview-banner" role="note">
			<strong><?php esc_html_e( 'PREVIEW — test entry', 'qhta-competitions' ); ?></strong>
			<?php esc_html_e( 'This form is not public yet. Anything you submit is saved as a TEST- entry, ignores the round dates, and is left out of entry limits, judging, exports and outcome emails. Emails are still sent, marked [TEST].', 'qhta-competitions' ); ?>
		</div>
	<?php endif; ?>

	<?php if ( $qc_general || $qc_count ) : ?>
		<div class="qc-errors" role="alert" tabindex="-1">
			<p><strong><?php esc_html_e( 'Please fix the following and submit again:', 'qhta-competitions' ); ?></strong></p>
			<ul>
				<?php foreach ( $qc_general as $qc_msg ) : ?>
					<li><?php echo esc_html( $qc_msg ); ?></li>
				<?php endforeach; ?>
				<?php if ( $qc_count ) : ?>
					<?php /* translators: %d: number of fields */ ?>
					<li><?php echo esc_html( sprintf( _n( '%d field needs attention — see the messages below.', '%d fields need attention — see the messages below.', $qc_count, 'qhta-competitions' ), $qc_count ) ); ?></li>
				<?php endif; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( $view['action_url'] ); ?>" enctype="multipart/form-data" data-qc-categories="<?php echo esc_attr( $view['categories'] ); ?>">
		<?php echo $view['hidden']; // Pre-escaped. ?>
		<p class="qc-required-note"><?php esc_html_e( 'Fields marked * are required.', 'qhta-competitions' ); ?></p>
		<?php echo $view['fields_html']; // Pre-escaped. ?>
		<?php echo $view['declarations']; // Pre-escaped. ?>
		<p class="qc-submit"><button type="submit" class="qc-button"><?php echo esc_html( $view['submit_label'] ); ?></button></p>
	</form>
</div>
