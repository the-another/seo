<?php
/**
 * PHPUnit bootstrap file for The Another SEO plugin tests.
 *
 * @package TheAnother\Plugin\SEO\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/patchwork-loader.php';

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
