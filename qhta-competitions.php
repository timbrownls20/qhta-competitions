<?php
/**
 * Plugin Name:       QHTA Competitions
 * Description:       Program rounds for QHTA's submission-based programs (Historical Writing Competition, ECC Bursary): call for submissions, review, outcomes. Replaces the emailed PDF forms. Visibility is controlled by wordpress-feature-flags.
 * Version:           1.2.0
 * Author:            QHTA
 * License:           GPL-2.0-or-later
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  wordpress-feature-flags
 * Text Domain:       qhta-competitions
 *
 * Release rule: bump the Version header above AND QC_VERSION below together.
 * scripts/check-version.sh fails if they differ.
 *
 * @package Qhta_Competitions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QC_VERSION', '1.2.0' );
define( 'QC_FILE', __FILE__ );
define( 'QC_DIR', plugin_dir_path( __FILE__ ) );
define( 'QC_URL', plugin_dir_url( __FILE__ ) );

require_once QC_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Qhta_Competitions_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Qhta_Competitions_Plugin', 'deactivate' ) );

Qhta_Competitions_Plugin::instance();
