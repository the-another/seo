<?php
/**
 * Plugin Name: The Another SEO
 * Plugin URI: https://theanother.org/plugin/seo/
 * Description: Performance-first SEO for WordPress at catalog scale — template-driven titles and meta, Open Graph and Twitter Cards, Schema.org JSON-LD, and breadcrumbs.
 * Version: 0.2.0
 * Author: The Another
 * Author URI: https://theanother.org
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Text Domain: the-another-seo
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'THE_ANOTHER_SEO_VERSION', '0.2.0' );
define( 'THE_ANOTHER_SEO_PLUGIN_FILE', __FILE__ );
define( 'THE_ANOTHER_SEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'THE_ANOTHER_SEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'THE_ANOTHER_SEO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another SEO requires PHP 8.3 or higher. Please upgrade your PHP version.', 'the-another-seo' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another SEO requires WordPress 6.9 or higher. Please upgrade WordPress.', 'the-another-seo' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( file_exists( THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/autoload.php';
}

// Action Scheduler self-deduplicates across bundling plugins; it must be
// required directly from the plugin main file, at include time.
if ( file_exists( THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook(
	__FILE__,
	function () {
		Installer::activate();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		// Stop the recurring sweep; Action Scheduler would otherwise keep
		// firing it (via any other AS-bundling plugin) with no listener.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Sitemap\SitemapSweeper::HOOK, array(), Sitemap\SitemapSweeper::GROUP );
		}

		// Order matters: at this point our init-registered rewrite rules and
		// the mod_rewrite_rules filter (SitemapServer::prepend_apache_static_rules())
		// are still live on this request, so flushing now would re-bake
		// everything we're trying to remove. Deregistering our hooks first
		// stops both from firing again; dropping only our own top rules next
		// leaves every other plugin's rules/endpoints untouched; only then
		// does the flush write clean rules and .htaccess.
		Container::get_instance()->deregister_all_hooks();

		// Drop only this plugin's rules: extra_rules_top survives a
		// WP_Rewrite::init() reset, and calling init() here would wipe OTHER
		// plugins' non-top rules/endpoints and persist their absence on flush.
		if ( isset( $GLOBALS['wp_rewrite'] ) ) {
			unset(
				$GLOBALS['wp_rewrite']->extra_rules_top[ Sitemap\SitemapServer::PATTERN_INDEX ],
				$GLOBALS['wp_rewrite']->extra_rules_top[ Sitemap\SitemapServer::PATTERN_CHUNK ]
			);
		}

		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	function () {
		try {
			Plugin::get_instance()->start();
		} catch ( Exception $e ) {
			wp_die(
				esc_html( $e->getMessage() ),
				'The Another SEO Error',
				array( 'response' => 500 )
			);
		}
	}
);
