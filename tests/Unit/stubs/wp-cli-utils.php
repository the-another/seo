<?php
/**
 * WP-CLI \WP_CLI\Utils function stubs.
 *
 * A separate file only because PHP forbids a braced namespace alongside the
 * un-namespaced top-level code in bootstrap.php; the guarded style matches
 * the WP_CLI class stub there.
 *
 * @package TheAnother\Plugin\SEO\Tests
 */

declare(strict_types=1);

namespace WP_CLI\Utils;

if ( ! function_exists( __NAMESPACE__ . '\get_flag_value' ) ) {
	/**
	 * Mirrors WP-CLI's own implementation.
	 *
	 * --flag sets the key to true, --no-flag sets it to false, and an absent
	 * flag leaves the key unset — which is precisely the distinction isset()
	 * cannot make and this function exists for.
	 *
	 * @param array<string, mixed> $assoc_args Associative args.
	 * @param string               $flag       Flag name.
	 * @param mixed                $default    Value when the flag was not passed.
	 * @return mixed Flag value.
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return isset( $assoc_args[ $flag ] ) ? $assoc_args[ $flag ] : $default;
	}
}
