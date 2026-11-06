<?php
/**
 * Referee confirmation page (/?qc_confirm=…), inside the theme's header and footer.
 * Override by copying to wp-content/uploads/qhta-competitions/templates/confirm-page.php.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="site-main qc-confirm-page">
	<?php echo Qhta_Competitions_Confirm::page_html(); // Escaped inside. ?>
</main>
<?php
get_footer();
