<?php
/**
 * Verification Method Migration
 *
 * @package TheAnotherSEO
 * @since 1.0.0
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
 * @since 1.0.0
 */
class MethodMigration {

	/**
	 * Option flag: the conversion has already run.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'taseo_verification_method_migrated';

	/**
	 * Option holding what the conversion had to drop, for the admin notice.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public const NOTICE_OPTION = 'taseo_verification_migration_notice';

	/**
	 * Engine slug => legacy file key.
	 *
	 * Frozen, like LEGACY_FILE_SHAPES below: these are the keys pre-0.5.0
	 * settings were written under, and nothing writes them any more.
	 *
	 * @since 1.0.0
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
	 * FROZEN. These patterns describe values already written to the database
	 * by pre-0.5.0 installs — whole filenames, not the bare tokens this plugin
	 * now stores — and this constant is the only surviving record of that
	 * shape: the one it was derived from is gone. It must NOT be "aligned"
	 * with SettingsPage::TOKEN_SHAPES or VerificationFileServer::TOKEN_PATTERNS,
	 * however similar they look; doing so makes token_from_legacy() reject
	 * every stored value and the conversion silently drops the settings of
	 * exactly the sites that still need it.
	 *
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * Run the conversion once, if it has not run already.
	 *
	 * Guarded by an option rather than a plugin-version comparison: the flag
	 * says what happened to the data, which is the thing that matters, and it
	 * stays true across downgrades.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_run(): void {
		if ( '1' === get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		$stored = get_option( Settings::OPTION_NAME, array() );

		// array() covers both a stored empty array and the default returned on
		// a fresh install where the option has never been written. Converting
		// it produces an empty array back, so the only effect of writing would
		// be to create an option row the site does not have yet — and to make
		// "taseo_settings does not exist until the settings page is saved"
		// false for every reader downstream of it.
		if ( is_array( $stored ) && array() !== $stored ) {
			$result = self::migrate( $stored );

			update_option( Settings::OPTION_NAME, $result['settings'] );

			if ( array() !== $result['dropped'] ) {
				update_option( self::NOTICE_OPTION, $result['dropped'] );
			}
		}

		update_option( self::VERSION_OPTION, '1' );
	}

	/**
	 * Recover the bare token from a legacy file value.
	 *
	 * @since 1.0.0
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
