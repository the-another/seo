<?php
/**
 * Plugin Name: The Another SEO
 * Plugin URI: https://theanother.org/plugin/seo/
 * Description: Performance-first SEO for WordPress at catalog scale — template-driven titles and meta, Open Graph and Twitter Cards, Schema.org JSON-LD, and breadcrumbs.
 * Version: 0.1.0
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

define( 'THE_ANOTHER_SEO_VERSION', '0.1.0' );
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
