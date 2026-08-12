<?php
/**
 * Verification Method Migration
 *
 * @package TheAnotherSEO
 * @since 0.5.0
 */

namespace TheAnother\Plugin\SEO\Verification;

use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class MethodMigration
 *
 * Converts the two-key verification shape — a meta code in `verify_X` and a
 * separate file value in `verify_X_file` — into one bare token plus a
 * `verify_X_method` of `meta` or `file`.
 *
 * Bing and Yandex are lossless: both keys held the same token, so whichever
 * survives is the same string. Only Google can lose a value, because its file
 * method issues a credential unrelated to its meta tag. Those losses are
 * reported rather than swallowed — see the `dropped` half of migrate()'s return.
 *
 * @since 0.5.0
 */
class MethodMigration {

	/**
	 * Option flag: the conversion has already run.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'taseo_verification_method_migrated';

	/**
	 * Option holding what the conversion had to drop, for the admin notice.
	 *
	 * @var string
	 */
	public const NOTICE_OPTION = 'taseo_verification_migration_notice';

	/**
	 * Engine slug => legacy file key.
	 *
	 * @var array<string, string>
	 */
	private const LEGACY_FILE_KEYS = array(
		'google' => 'verify_google_file',
		'bing'   => 'verify_bing_file',
		'yandex' => 'verify_yandex_file',
	);

	/**
	 * Engine slug => pattern the legacy file value had to match, and the
	 * prefix/suffix stripped to recover the bare token.
	 *
	 * @var array<string, array{pattern: string, prefix: string, suffix: string}>
	 */
	private const LEGACY_FILE_SHAPES = array(
		'google' => array(
			'pattern' => '/^google[a-z0-9]+\.html$/',
			'prefix'  => 'google',
			'suffix'  => '.html',
		),
		'bing'   => array(
			'pattern' => '/^[A-Za-z0-9]+$/',
			'prefix'  => '',
			'suffix'  => '',
		),
		'yandex' => array(
			'pattern' => '/^yandex_[a-z0-9]+\.html$/',
			'prefix'  => 'yandex_',
			'suffix'  => '.html',
		),
	);

	/**
	 * Convert one settings array.
	 *
	 * Pure: no options, no filters, no side effects. maybe_run() owns the I/O.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, mixed> $settings Raw taseo_settings array.
	 * @return array{settings: array<string, mixed>, dropped: array<int, array{engine: string, domain: string}>}
	 */
	public static function migrate( array $settings ): array {
		$dropped = array();

		$settings = self::migrate_record( $settings, '', $dropped );

		$domains = $settings[ Settings::DOMAINS_KEY ] ?? null;

		if ( is_array( $domains ) ) {
			foreach ( $domains as $host => $record ) {
				if ( is_array( $record ) ) {
					$domains[ $host ] = self::migrate_record( $record, (string) $host, $dropped );
				}
			}

			$settings[ Settings::DOMAINS_KEY ] = $domains;
		}

		return array(
			'settings' => $settings,
			'dropped'  => $dropped,
		);
	}

	/**
	 * Convert one flat record — either the top-level settings or one domain's.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, mixed>                              $record  Record.
	 * @param string                                            $domain  Host, '' for the default domain.
	 * @param array<int, array{engine: string, domain: string}> $dropped Collected losses, by reference.
	 * @return array<string, mixed> Converted record.
	 */
	private static function migrate_record( array $record, string $domain, array &$dropped ): array {
		foreach ( self::LEGACY_FILE_KEYS as $engine => $file_key ) {
			$code_key   = 'verify_' . $engine;
			$method_key = $code_key . '_method';

			if ( array_key_exists( $method_key, $record ) ) {
				// Already migrated: the file key, if any, is long gone, so
				// re-deriving from it here would corrupt a settled record.
				continue;
			}

			$has_file_key = array_key_exists( $file_key, $record );

			if ( ! $has_file_key && ! array_key_exists( $code_key, $record ) ) {
				continue;
			}

			$legacy = ( $has_file_key && is_string( $record[ $file_key ] ) ) ? trim( $record[ $file_key ] ) : '';
			$code   = isset( $record[ $code_key ] ) && is_string( $record[ $code_key ] ) ? $record[ $code_key ] : '';
			$token  = self::token_from_legacy( $engine, $legacy );

			unset( $record[ $file_key ] );

			if ( '' === $token ) {
				// Nothing usable in the file key — the meta code, if any, stands.
				$record[ $method_key ] = Settings::METHOD_META;

				continue;
			}

			// A code that differs from the recovered token is a second,
			// unrelated credential and cannot survive the collapse. In
			// practice only Google reaches this: Bing and Yandex store the
			// same token in both keys, so the comparison is equal there.
			if ( '' !== $code && $code !== $token ) {
				$dropped[] = array(
					'engine' => $engine,
					'domain' => $domain,
				);
			}

			$record[ $code_key ]   = $token;
			$record[ $method_key ] = Settings::METHOD_FILE;
		}

		return $record;
	}

	/**
	 * Recover the bare token from a legacy file value.
	 *
	 * @since 0.5.0
	 *
	 * @param string $engine Engine slug.
	 * @param string $legacy Legacy stored value.
	 * @return string Token, '' when the value never matched the shape that engine stores.
	 */
	private static function token_from_legacy( string $engine, string $legacy ): string {
		if ( '' === $legacy || ! isset( self::LEGACY_FILE_SHAPES[ $engine ] ) ) {
			return '';
		}

		$shape = self::LEGACY_FILE_SHAPES[ $engine ];

		if ( 1 !== preg_match( $shape['pattern'], $legacy ) ) {
			return '';
		}

		$token = substr( $legacy, strlen( $shape['prefix'] ) );

		if ( '' !== $shape['suffix'] ) {
			$token = substr( $token, 0, -strlen( $shape['suffix'] ) );
		}

		return (string) $token;
	}
}
