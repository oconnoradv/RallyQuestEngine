<?php
/**
 * Plugin Name: Rally Quest Engine
 * Plugin URI: https://github.com/oconnoradv/RallyQuestEngine
 * Description: Plan, administer and score scavenger-hunt style rallies for motorcycle, bicycle, horseback and geocache participants with near-real-time results.
 * Version: 0.4.2
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: action-scheduler
 * Author: Brian O'Connor
 * Author URI: https://github.com/oconnoradv
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: rally-quest-engine
 */

defined( 'ABSPATH' ) || exit;

define( 'RQE_VERSION', '0.4.2' );
define( 'RQE_DB_VERSION', '16' );
define( 'RQE_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-rqe.php';
require_once __DIR__ . '/includes/class-rqe-event-state.php';
require_once __DIR__ . '/includes/class-rqe-certificates.php';
require_once __DIR__ . '/includes/class-rqe-styles.php';
require_once __DIR__ . '/includes/class-rqe-horses.php';
require_once __DIR__ . '/includes/class-rqe-importer.php';
require_once __DIR__ . '/includes/class-rqe-file-scan.php';
require_once __DIR__ . '/includes/class-rqe-spreadsheet-reader.php';
require_once __DIR__ . '/includes/class-rqe-import-audit.php';
require_once __DIR__ . '/includes/class-rqe-import-adapters.php';
require_once __DIR__ . '/includes/class-rqe-bonus.php';
require_once __DIR__ . '/includes/class-rqe-diagnostics.php';
require_once __DIR__ . '/includes/class-rqe-bonus-application-service.php';
require_once __DIR__ . '/includes/class-rqe-email-submissions.php';
require_once __DIR__ . '/includes/class-rqe-setup.php';
require_once __DIR__ . '/includes/class-rqe-roster-import-service.php';
require_once __DIR__ . '/includes/class-rqe-diagnostics-deletion-service.php';
require_once __DIR__ . '/includes/class-rqe-admin.php';
require_once __DIR__ . '/includes/class-rqe-statuses.php';
require_once __DIR__ . '/includes/class-rqe-results.php';
require_once __DIR__ . '/includes/class-rqe-queries.php';
require_once __DIR__ . '/includes/class-rqe-public-results.php';
require_once __DIR__ . '/includes/class-rqe-legacy-import.php';
require_once __DIR__ . '/includes/class-rqe-views.php';

register_activation_hook( __FILE__, array( 'RQE', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RQE', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'RQE', 'init' ) );
