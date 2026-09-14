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

/**
 * Registers a category this plugin does not emit, behind its own query var, so
 * the suite can prove the published extension path end to end: a third party
 * registers a slug, marks its own script inert with the documented attribute,
 * and the activator runs it once that category alone is accepted.
 */
add_filter(
	'taseo_consent_categories',
	static function ( array $slugs ): array {
		if ( taseo_e2e_functional_registered() ) {
			$slugs[] = 'functional';
		}

		return $slugs;
	}
);

add_action(
	'wp_head',
	static function (): void {
		if ( ! taseo_e2e_functional_registered() ) {
			return;
		}

		echo '<script type="text/plain" data-taseo-consent="functional">window.__taseoFunctionalRan = true;</script>' . "\n";
	},
	5
);

/**
 * Whether this request asked for the third-party category fixture.
 *
 * @return bool Registered.
 */
function taseo_e2e_functional_registered(): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only test switch.
	return isset( $_GET['taseo_functional'] ) && 'on' === $_GET['taseo_functional'];
}
