<?php
/**
 * Uninstall The Another SEO.
 *
 * Included by WordPress when the plugin is deleted through wp-admin or
 * WP-CLI, with the plugin itself NOT loaded — which is exactly why this is a
 * file rather than a register_uninstall_hook() callback: nothing here may
 * assume the plugin's runtime ever booted.
 *
 * Deleting the plugin removes everything it wrote: both tables, every option,
 * and the sitemap chunk directory under uploads/. Deactivation is the
 * reversible half of the lifecycle and keeps all of it. See Uninstaller for
 * the reasoning, and readme.txt's FAQ for the user-facing statement.
 *
 * @package TheAnotherSEO
 * @since 1.4.0
 */

namespace TheAnother\Plugin\SEO;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	// No autoloader, no teardown. Bailing beats a fatal inside core's
	// delete_plugins() loop, which would strand every other plugin queued
	// for deletion in the same request.
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

Uninstaller::uninstall();
