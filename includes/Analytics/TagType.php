<?php
/**
 * Tracking Tag Type
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\Analytics\Transport\TagTransport;

/**
 * Class TagType
 *
 * One supported vendor, declared. Identifiers only: a pattern that says what an
 * ID looks like, never a script and never markup. A registry of typed IDs can
 * validate and render; a registry of arbitrary HTML could only concatenate, and
 * any site letting a third party populate it would be handing out script
 * injection.
 *
 * The key is identity. Two vendors may share an ID shape — Bing UET and Meta
 * Pixel are both bare numeric strings — and nothing ever infers a vendor from an
 * ID: a candidate is only matched against the pattern of the key it arrived
 * under.
 *
 * @since 1.5.0
 */
final readonly class TagType {

	/**
	 * Constructor.
	 *
	 * @param string       $key          Stable vendor key, e.g. 'ga4'.
	 * @param string       $settings_key Settings key holding the stored ID.
	 * @param string       $pattern      Validation pattern for one ID.
	 * @param bool         $uppercase    Uppercase a candidate before matching.
	 * @param string       $ids_filter   Per-vendor ID filter name.
	 * @param Consent      $consent      Consent category.
	 * @param Placement    $placement    Where the primary snippet goes.
	 * @param int          $priority     Hook priority for that snippet.
	 * @param bool         $has_noscript Whether a <body>-open half exists.
	 * @param TagTransport $transport    Transport emitting this vendor.
	 * @param string       $legacy_gate  Retained per-vendor gate filter, '' for none.
	 */
	public function __construct(
		public string $key,
		public string $settings_key,
		public string $pattern,
		public bool $uppercase,
		public string $ids_filter,
		public Consent $consent,
		public Placement $placement,
		public int $priority,
		public bool $has_noscript,
		public TagTransport $transport,
		public string $legacy_gate = ''
	) {
	}

	/**
	 * Validate, normalize, de-duplicate and re-index a list of candidate IDs.
	 *
	 * Stored values and filter return values both come through here, by design:
	 * a filter is third-party code, and trusting it would hand any plugin on the
	 * site a script-injection path into <head>.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $ids Candidate IDs, from anywhere.
	 * @return array<int, string> Clean IDs.
	 */
	public function clean( mixed $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) ) {
				continue;
			}

			$id = trim( $id );
			$id = $this->uppercase ? strtoupper( $id ) : $id;

			if ( 1 === preg_match( $this->pattern, $id ) && ! in_array( $id, $clean, true ) ) {
				$clean[] = $id;
			}
		}

		return $clean;
	}
}
