<?php
/**
 * Plugin Name: Motorcycle Rally Scoring Application
 * Plugin URI: https://github.com/oconnoradv/MotorcycleRallyScoringApp
 * Description: Site-scoped rally administration, motorcycle catalog and rider registration.
 * Version: 0.1.8
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Brian O'Connor
 * Author URI: https://github.com/oconnoradv
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: motorcycle-rally-scoring-application
 */

defined( 'ABSPATH' ) || exit;

define( 'MRSA_VERSION', '0.1.8' );
define( 'MRSA_DB_VERSION', '6' );
define( 'MRSA_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-mrsa.php';
require_once __DIR__ . '/includes/class-mrsa-importer.php';
require_once __DIR__ . '/includes/class-mrsa-file-scan.php';
require_once __DIR__ . '/includes/class-mrsa-bonus.php';
require_once __DIR__ . '/includes/class-mrsa-admin.php';
require_once __DIR__ . '/includes/class-mrsa-views.php';

register_activation_hook( __FILE__, array( 'MRSA', 'activate' ) );
add_action( 'plugins_loaded', array( 'MRSA', 'init' ) );
