<?php
/**
 * Verification Domain Registry
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Domains;

/**
 * Class DomainRegistry
 *
 * Owns two questions: which domains carry their own verification codes, and
 * which of them is this request. Everything per-domain in the plugin resolves
 * through here.
 *
 * The list is derived, never typed: the site's own host is always present and
 * always the default, and other plugins push additional hosts through the
 * taseo_verification_domains filter. This plugin therefore knows nothing about
 * Brands, multi-site, or any particular domain-mapping scheme.
 *
 * @since 1.0.0
 */
class DomainRegistry {

	/**
	 * Memoized host list. The filter's subscribers are fixed for the life of
	 * a request, so re-applying it per caller buys nothing.
	 *
	 * @since 1.0.0
	 *
	 * @var array<int, string>|null
	 */
	private ?array $hosts = null;

	/**
	 * Normalize a hostname: lowercase, strip scheme/port/path, strip leading
	 * `www.`.
	 *
	 * Deliberately identical to the multi-brand plugin's own host
	 * normalization. It has to be: the host matched against an incoming
	 * request must equal the key that plugin pushed through the filter, and a
	 * one-character divergence would silently serve the default domain's codes
	 * on a brand domain. A host is normalized in full or rejected, never
	 * truncated — anything left holding a character outside `[a-z0-9.-]` is
	 * junk, so internationalized domains must arrive as punycode.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw hostname or URL.
	 * @return string Normalized host, '' when nothing usable survives.
	 */
	public static function normalize_host( string $raw ): string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return '';
		}

		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) || str_starts_with( $raw, '//' ) ) {
			$parsed = wp_parse_url( $raw );
			$raw    = is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		} else {
			// Bare hostname, optionally with a trailing port. Only the port is
			// dropped: a path here means the caller passed something that is not
			// a bare host, and the character-class check below rejects it rather
			// than silently keeping the leading segment. Identical to
			// UrlRuleRegistry::normalize_host() in the multi-brand plugin, whose
			// pushed keys must compare equal to what this returns.
			$raw = (string) preg_replace( '/:\d+$/', '', $raw );
		}

		if ( '' === $raw ) {
			return '';
		}

		$raw = strtolower( $raw );
		$raw = (string) preg_replace( '/:\d+$/', '', $raw );
		$raw = (string) preg_replace( '/^www\./', '', $raw );

		return 1 === preg_match( '/^[a-z0-9.-]+$/', $raw ) ? $raw : '';
	}

	/**
	 * The default domain: the host of the site's own home URL.
	 *
	 * Static because Settings needs it to decide whether a requested host
	 * means "the flat keys" without taking a constructor dependency it would
	 * otherwise need everywhere.
	 *
	 * @since 1.0.0
	 *
	 * @return string Normalized host, '' when home_url() has none.
	 */
	public static function default_host(): string {
		return self::normalize_host( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Every domain that carries its own codes, default first.
	 *
	 * Memoized per instance: the filter's subscribers don't change mid-request,
	 * so callers that ask repeatedly (every output class does) don't each pay
	 * for re-applying it.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, string> Normalized hosts.
	 */
	public function get_hosts(): array {
		if ( null !== $this->hosts ) {
			return $this->hosts;
		}

		$default = self::default_host();

		/**
		 * Filters the domains that carry their own verification codes.
		 *
		 * Push a host to give it its own webmaster verification codes,
		 * verification files, and tracking IDs. Values are normalized and
		 * de-duplicated by the registry, so a raw URL or a `www.` host is
		 * accepted. The site's own host is always present and always first;
		 * removing or reordering it here has no effect.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, string> $hosts Hosts, starting with the site's own.
		 */
		$hosts = apply_filters( 'taseo_verification_domains', array( $default ) );

		if ( ! is_array( $hosts ) ) {
			$hosts = array();
		}

		$clean = array();

		foreach ( $hosts as $host ) {
			$host = is_string( $host ) ? self::normalize_host( $host ) : '';

			if ( '' !== $host && $host !== $default && ! in_array( $host, $clean, true ) ) {
				$clean[] = $host;
			}
		}

		if ( '' !== $default ) {
			array_unshift( $clean, $default );
		}

		$this->hosts = $clean;

		return $this->hosts;
	}

	/**
	 * The domain this request arrived on.
	 *
	 * An unrecognised Host — a staging alias, a bare IP, a load balancer —
	 * resolves to the default, which is exactly what every host got before
	 * this feature existed. That is what keeps the change non-breaking.
	 *
	 * @since 1.0.0
	 *
	 * @return string Normalized host.
	 */
	public function get_current_host(): string {
		$raw = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: '';

		$host  = self::normalize_host( $raw );
		$hosts = $this->get_hosts();

		return in_array( $host, $hosts, true ) ? $host : ( $hosts[0] ?? '' );
	}
}
