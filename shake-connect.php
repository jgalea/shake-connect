<?php
/**
 * Plugin Name:       Shake Connect
 * Description:       Connect this WordPress site to your WPShake dashboard for backups, updates, monitoring, security, and reports across multiple sites.
 * Version:           0.5.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WPShake
 * Author URI:        https://wpshake.com
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       shake-connect
 * Network:           true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SHAKE_CONNECT_VERSION', '0.5.1' );
define( 'SHAKE_CONNECT_FILE', __FILE__ );
define( 'SHAKE_CONNECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHAKE_CONNECT_NAMESPACE', 'wpshake/v1' );
define( 'SHAKE_CONNECT_OPT_TOKEN', 'shake_connect_token_hash' );
define( 'SHAKE_CONNECT_OPT_LAST_SEEN', 'shake_connect_last_seen' );

require_once SHAKE_CONNECT_PATH . 'includes/class-auth.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-php-errors.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-backups.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-activity-log.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-malware-scanner.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-link-checker.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-rest-controller.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-settings.php';
require_once SHAKE_CONNECT_PATH . 'includes/class-cli.php';

register_activation_hook( __FILE__, array( 'ShakeConnect_Settings', 'on_activate' ) );

add_action(
	'rest_api_init',
	function () {
		( new ShakeConnect_REST() )->register_routes();
	}
);

add_action(
	'admin_menu',
	function () {
		( new ShakeConnect_Settings() )->register_menu();
	}
);

add_action(
	'admin_init',
	function () {
		( new ShakeConnect_Settings() )->register_settings();
	}
);

