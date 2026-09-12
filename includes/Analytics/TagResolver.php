<?php
/**
 * Tracking Tag Resolution
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class TagResolver
 *
 * Which tracking IDs apply to this request. Settings are read, each vendor's own
 * filter runs, the whole collection is offered to one override filter, everything
 * that filter returned is re-validated, and consent gates have the last word.
 *
 * The ordering is the design:
 *
 * - The global gate short-circuits first, so a request that emits nothing runs no
 *   third-party code at all — not the ID filters, and not the override, because
 *   nothing may add tags back through a gate that said no.
 * - Consent is applied last, after the override. An override can say "use this
 *   party's IDs on this page"; it cannot say "ignore the visitor's refusal".
 * - Filter output is validated exactly like stored values. A filter is
 *   third-party code, and trusting it would hand any plugin on the site a
 *   script-injection path into <head>.
 *
 * Resolved once per request. Today's two classes re-read their filters for the
 * head half and again for the <body> half, so a non-deterministic subscriber can
 * already make a container loader and its no-JS iframe disagree; memoizing makes
 * that impossible.
 *
 * @since 1.5.0
 */
class TagResolver {

	/**
	 * Resolved collection for this request, null until first resolved.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $resolved = null;

	/**
	 * Constructor.
	 *
	 * @param TagRegistry    $registry Declared vendors.
	 * @param Settings       $settings Settings.
	 * @param DomainRegistry $domains  Domain registry.
	 */
	public function __construct(
		private readonly TagRegistry $registry,
		private readonly Settings $settings,
		private readonly DomainRegistry $domains
	) {
	}

	/**
	 * Vendor keys and the IDs to emit for them on this request.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, array<int, string>> Vendor key => validated IDs, in
	 *                                           registry order. Empty when
	 *                                           nothing may be emitted.
	 */
	public function resolve(): array {
		if ( null === $this->resolved ) {
			$this->resolved = $this->build();
		}

		return $this->resolved;
	}

	/**
	 * Resolve from scratch.
	 *
	 * @return array<string, array<int, string>> Vendor key => validated IDs.
	 */
	private function build(): array {
		if ( ! $this->should_print() ) {
			return array();
		}

		$host = $this->domains->get_current_host();
		$tags = array();

		foreach ( $this->registry->all() as $type ) {
			$stored = $this->settings->get_tracking_id( $type->settings_key, $host );
			$ids    = '' === $stored ? array() : array( $stored );

			/**
			 * Filters one vendor's IDs. Documented per vendor in the registry:
			 * taseo_analytics_ga4_ids, taseo_analytics_gtm_ids,
			 * taseo_meta_pixel_ids, taseo_google_ads_ids, taseo_bing_uet_ids.
			 *
			 * The stored ID is resolved for the domain the request arrived on,
			 * not for the site as a whole: on a multi-domain install these run
			 * once per requesting domain and the incoming array differs between
			 * them. They carry no host argument, so a subscriber that needs to
			 * know which domain it is running for must resolve that itself —
			 * taseo_tracking_tag_ids below does carry one.
			 *
			 * @since 1.0.0
			 *
			 * @param array<int, string> $ids Vendor IDs.
			 */
			$ids = $type->clean( apply_filters( $type->ids_filter, $ids ) );

			if ( array() !== $ids ) {
				$tags[ $type->key ] = $ids;
			}
		}

		/**
		 * Filters the whole resolved tag collection for this request.
		 *
		 * Applied after settings and the per-vendor filters, before any output:
		 * add a vendor, replace the collection, or return an empty array to
		 * emit nothing at all. This is what lets a host site say "this page
		 * belongs to one party, use their IDs" or "this page spans several,
		 * emit nothing" — neither of which the plugin can decide for itself.
		 *
		 * Identifiers only. Every value is re-validated against its vendor's
		 * pattern afterwards and unknown keys are dropped, so markup cannot
		 * reach the page through this filter. Key order is ignored: rendering
		 * order is the registry's.
		 *
		 * Consent gates run after this filter, so the collection it returns can
		 * still be emptied by a refusal.
		 *
		 * @since 1.5.0
		 *
		 * @param array<string, array<int, string>> $tags Vendor key => IDs, carrying
		 *                                                only vendors with at least
		 *                                                one ID.
		 * @param string                            $host Normalized host the request
		 *                                                arrived on.
		 */
		$tags = apply_filters( 'taseo_tracking_tag_ids', $tags, $host );

		return $this->gate( $this->revalidate( $tags ) );
	}

	/**
	 * Re-validate a filtered collection against the registry.
	 *
	 * Iterates the registry rather than the incoming array, which drops unknown
	 * keys and restores registry order in one pass.
	 *
	 * @param mixed $tags Candidate collection.
	 * @return array<string, array<int, string>> Clean collection.
	 */
	private function revalidate( mixed $tags ): array {
		if ( ! is_array( $tags ) ) {
			return array();
		}

		$clean = array();

		foreach ( $this->registry->all() as $type ) {
			if ( ! isset( $tags[ $type->key ] ) ) {
				continue;
			}

			$ids = $type->clean( $tags[ $type->key ] );

			if ( array() !== $ids ) {
				$clean[ $type->key ] = $ids;
			}
		}

		return $clean;
	}

	/**
	 * Drop whatever consent refuses.
	 *
	 * One filter application per category per request, and only for a category
	 * that has something to gate.
	 *
	 * @param array<string, array<int, string>> $tags Clean collection.
	 * @return array<string, array<int, string>> Permitted collection.
	 */
	private function gate( array $tags ): array {
		$categories = array();
		$gated      = array();

		foreach ( $tags as $key => $ids ) {
			$type = $this->registry->get( $key );

			if ( null === $type ) {
				continue;
			}

			$gate = $type->consent->gate();

			if ( ! isset( $categories[ $gate ] ) ) {
				/**
				 * Filters whether one consent category is emitted on this request.
				 *
				 * Two categories exist: `taseo_analytics_should_print` covers
				 * GA4 and Tag Manager, and `taseo_marketing_should_print` covers
				 * Meta Pixel, Google Ads and Bing UET. Separate so a visitor who
				 * accepted analytics but not marketing can be honoured without
				 * losing both.
				 *
				 * @since 1.0.0 As taseo_analytics_should_print.
				 * @since 1.5.0 The marketing category gate.
				 *
				 * @param bool $enabled Whether to emit.
				 */
				$categories[ $gate ] = (bool) apply_filters( $gate, true );
			}

			if ( ! $categories[ $gate ] ) {
				continue;
			}

			/**
			 * Filters whether one vendor is emitted on this request.
			 *
			 * Only Meta Pixel declares one of these, and only because
			 * taseo_meta_pixel_should_print predates the category gates and is
			 * published API. New vendors get no per-vendor gate: dropping a
			 * single vendor for a request is what taseo_tracking_tag_ids is for.
			 *
			 * @since 1.0.0
			 *
			 * @param bool $enabled Whether to emit.
			 */
			if ( '' !== $type->legacy_gate && ! (bool) apply_filters( $type->legacy_gate, true ) ) {
				continue;
			}

			$gated[ $key ] = $ids;
		}

		return $gated;
	}

	/**
	 * Whether any tracking output is allowed on this request.
	 *
	 * @return bool Allowed.
	 */
	private function should_print(): bool {
		$default = ! is_admin() && ! is_customize_preview();

		/**
		 * Filters whether ANY tracking output is emitted on this request.
		 *
		 * A false answer is final: no vendor's ID filter runs, and neither does
		 * taseo_tracking_tag_ids, so nothing can add tags back.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $default Whether to emit.
		 */
		return (bool) apply_filters( 'taseo_tracking_should_print', $default );
	}
}
