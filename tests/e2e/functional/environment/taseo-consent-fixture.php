<?php
/**
 * Plugin Name: TASEO consent fixture
 *
 * Turns consent mode on for one request, so the e2e suite can assert both
 * states against the same install. The existing webmaster spec asserts the
 * ungated output and must keep passing untouched.
 */

add_filter(
	'taseo_consent_enabled',
	static function ( bool $enabled ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test switch.
		return isset( $_GET['taseo_consent'] ) && 'on' === $_GET['taseo_consent'] ? true : $enabled;
	}
);
