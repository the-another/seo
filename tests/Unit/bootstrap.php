<?php
/**
 * PHPUnit bootstrap file for The Another SEO plugin tests.
 *
 * @package TheAnother\Plugin\SEO\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once dirname( __DIR__, 2 ) . '/vendor/brain/monkey/inc/patchwork-loader.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// Pre-create and pre-load the wp-admin upgrade.php stub so production code's
// require_once is a no-op under Patchwork (which would otherwise emit stream-
// wrapper output mid-test and trip failOnRisky).
$taseo_upgrade_stub = ABSPATH . 'wp-admin/includes/upgrade.php';

if ( ! file_exists( $taseo_upgrade_stub ) ) {
	if ( ! is_dir( dirname( $taseo_upgrade_stub ) ) ) {
		mkdir( dirname( $taseo_upgrade_stub ), 0777, true );
	}
	file_put_contents( $taseo_upgrade_stub, "<?php\n" );
}

require_once $taseo_upgrade_stub;

if ( ! defined( 'THE_ANOTHER_SEO_VERSION' ) ) {
	define( 'THE_ANOTHER_SEO_VERSION', '0.1.0' );
}

if ( ! defined( 'THE_ANOTHER_SEO_PLUGIN_URL' ) ) {
	define( 'THE_ANOTHER_SEO_PLUGIN_URL', 'https://example.com/wp-content/plugins/the-another-seo/' );
}

// A disposable scratch directory, NOT the real project root. Code that reads
// a built asset file off disk (SettingsPage::enqueue_assets, ImageField::
// enqueue) needs a path that actually exists so its "not built yet"
// file_exists() guard is testable both ways — but it does not need to be the
// real dist/ a release zip or a running site reads from. This constant used
// to point at the real project root, and SettingsPageTest/MetaboxTest wrote a
// stub dist/*/index.asset.php there for the duration of a handful of tests,
// restoring it afterwards. That is exactly backwards when it goes wrong: a
// test interrupted before its restore ran (an assertion failure not wrapped
// in try/finally, a killed process) left a 'version' => 'testassetversion'
// stub sitting in real build output. A scratch directory starts with no
// dist/ at all — file_exists() naturally returns false there until a test
// deliberately writes one — so the worst an interrupted run can do is leave
// stray files in a directory nothing but this test run ever reads.
if ( ! defined( 'THE_ANOTHER_SEO_PLUGIN_DIR' ) ) {
	$taseo_scratch_plugin_dir = rtrim( sys_get_temp_dir(), '/' ) . '/taseo-phpunit-' . getmypid() . '/';

	define( 'THE_ANOTHER_SEO_PLUGIN_DIR', $taseo_scratch_plugin_dir );

	/**
	 * Best-effort cleanup. Not load-bearing for correctness (every test that
	 * writes here already restores what it changed), just hygiene so a local
	 * run doesn't accumulate one throwaway directory per PHPUnit invocation.
	 *
	 * @param string $dir Directory to remove recursively.
	 * @return void
	 */
	function taseo_rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );

		foreach ( false === $items ? array() : $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$path = $dir . '/' . $item;

			if ( is_dir( $path ) ) {
				taseo_rrmdir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}

	register_shutdown_function( 'taseo_rrmdir', $taseo_scratch_plugin_dir );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->errors[ $code ][]   = $message;
			$this->error_data[ $code ] = $data;
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$codes = array_keys( $this->errors );
				$code  = $codes[0] ?? '';
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ][0] : '';
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $data ) {
		return $data;
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {

		/** @var array<int, string> */
		public static array $lines = array();

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}

		public static function warning( string $message ): void {
			self::$lines[] = 'warning: ' . $message;
		}

		public static function success( string $message ): void {
			self::$lines[] = 'success: ' . $message;
		}

		public static function line( string $message = '' ): void {
			self::$lines[] = $message;
		}

		public static function log( string $message ): void {
			self::$lines[] = $message;
		}

		/**
		 * @param mixed                $value   Value.
		 * @param array<string, mixed> $options Options.
		 */
		public static function print_value( $value, array $options = array() ): void {
			self::$lines[] = (string) wp_json_encode( $value );
		}

		/**
		 * @param array<string, mixed> $options Options.
		 * @return mixed
		 */
		public static function runcommand( string $command, array $options = array() ) {
			self::$lines[] = 'runcommand: ' . $command;

			return null;
		}
	}
}

// \WP_CLI\Utils\get_flag_value(), which the commands use to tell --flag from
// --no-flag. Lives in its own file because a braced namespace cannot coexist
// with the un-namespaced code above it.
require_once __DIR__ . '/stubs/wp-cli-utils.php';
