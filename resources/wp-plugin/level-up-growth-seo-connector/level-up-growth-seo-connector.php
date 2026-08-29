<?php
/**
 * Plugin Name: LevelUp Growth SEO
 * Plugin URI:  https://levelupgrowth.io
 * Description: Connect your WordPress site to LevelUp Growth: AI-written articles published straight to your blog, SEO meta management, and the LevelUp chatbot. All intelligence lives in LevelUp Growth — this plugin is a thin REST client + receiver.
 * Version:     1.1.0
 * Author:      LevelUp Growth
 * Author URI:  https://levelupgrowth.io
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lgsc
 * Requires PHP: 7.4
 * Requires at least: 5.8
 *
 * @package LevelUp_Growth_SEO_Connector
 */

defined( 'ABSPATH' ) || exit;

define( 'LGSC_VERSION', '1.1.0' );
define( 'LGSC_PLUGIN_FILE', __FILE__ );
define( 'LGSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Default API base. User can override via settings page (lgsc_api_url option).
if ( ! defined( 'LGSC_API_BASE_DEFAULT' ) ) {
	define( 'LGSC_API_BASE_DEFAULT', 'https://staging.levelupgrowth.io/api' );
}

/**
 * Resolve the configured API base. Reads option, falls back to default.
 * Always returns a string with no trailing slash.
 */
function lgsc_api_base() {
	$url = get_option( 'lgsc_api_url', LGSC_API_BASE_DEFAULT );
	$url = is_string( $url ) ? trim( $url ) : LGSC_API_BASE_DEFAULT;
	return rtrim( $url, '/' );
}

// Autoload includes.
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-api-client.php';
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-meta-box.php';
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-settings-page.php';
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-rest-receiver.php';
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-frontend-render.php';
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-admin-shell.php';
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-chatbot.php';
require_once LGSC_PLUGIN_DIR . 'includes/class-lgsc-activator.php';

// Activation / deactivation / uninstall hooks.
register_activation_hook( __FILE__, array( 'LGSC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LGSC_Activator', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'LGSC_Activator', 'uninstall' ) );

/**
 * Bootstrap the plugin once WordPress is fully loaded.
 */
function lgsc_bootstrap() {
	// Settings page (admin only).
	if ( is_admin() ) {
		$settings = new LGSC_Settings_Page();
		$settings->register();

		$meta = new LGSC_Meta_Box();
		$meta->register();

		// v1.0.3: full admin shell — top-level "LevelUp SEO" menu with 11 sub-pages.
		// Pages with backend wiring (Dashboard, Page Analyzer, Quick Wins, Internal Links)
		// call /api/connector/* via the existing client; pages without WP-side backend
		// yet show honest "Open in LevelUp Growth" deep-links rather than fake UI.
		$shell = new LGSC_Admin_Shell();
		$shell->register();
	}

	// REST receiver (always — webhook target).
	$receiver = new LGSC_Rest_Receiver();
	$receiver->register();

	// Frontend render — outputs the LevelUp-controlled meta to the customer's
	// site HTML. Detects active SEO plugin (Yoast/RankMath/AIOSEO) and routes
	// through its filter chain; falls back to native pre_get_document_title +
	// wp_head when none is active. Without this, edits in the LevelUp panel
	// would never reach the visitor-facing page (was the v1.0.0 bug).
	$renderer = new LGSC_Frontend_Render();
	$renderer->register();

	// v1.0.5: Chatbot888 admin sub-page + frontend widget injector.
	// All chatbot intelligence lives in LevelUp Growth — this only renders
	// the settings UI and outputs the <script> tag on the public site.
	$chatbot = new LGSC_Chatbot();
	$chatbot->register();
}
add_action( 'plugins_loaded', 'lgsc_bootstrap' );

/**
 * Add a "Settings" link to the plugin row on the Plugins screen.
 */
function lgsc_plugin_action_links( $links ) {
	$url      = admin_url( 'options-general.php?page=lgsc-settings' );
	$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'lgsc' ) . '</a>';
	array_unshift( $links, $settings );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'lgsc_plugin_action_links' );
