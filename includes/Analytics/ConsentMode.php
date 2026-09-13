<?php
/**
 * Tracking Consent Mode
 *
 * @package TheAnotherSEO
 * @since 1.6.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class ConsentMode
 *
 * Whether this request emits tracking snippets live or inert, and the
 * attributes that make one inert.
 *
 * The server never decides that a visitor accepted. It cannot: the decision
 * lives in the visitor's browser and this response may be stored by a full-page
 * cache and replayed to thousands of other people. So while consent mode is
 * active every snippet is emitted blocked — present in the HTML, executing
 * nothing, fetching nothing — and the boot script activates the categories the
 * visitor actually accepted. The cached bytes are the same for everyone, which
 * is the only arrangement in which a shared cache cannot leak one visitor's
 * choice to another.
 *
 * The existing gates are untouched by this and keep their own meaning: a gate
 * returning false means not emitted at all, not even inert.
 *
 * @since 1.6.0
 */
class ConsentMode {

	/**
	 * Memoized answer for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $active = null;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Settings.
	 * @param TagResolver $resolver Per-request resolution.
	 * @param TagRegistry $registry Declared vendors.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly TagResolver $resolver,
		private readonly TagRegistry $registry
	) {
	}

	/**
	 * Whether snippets are emitted inert on this request.
	 *
	 * False when the setting is off, and false when nothing survived site
	 * policy — a site with no tracking configured has nothing to consent to,
	 * and asking would be a banner that gates nothing.
	 *
	 * @since 1.6.0
	 *
	 * @return bool Active.
	 */
	public function is_active(): bool {
		if ( null !== $this->active ) {
			return $this->active;
		}

		/**
		 * Filters whether visitors are asked for consent on this request.
		 *
		 * Answering false emits tracking live, exactly as a site with the
		 * setting off does — for a site that runs its own consent platform and
		 * gates these tags through taseo_tracking_should_print instead.
		 *
		 * @since 1.6.0
		 *
		 * @param bool $enabled Whether consent mode is on.
		 */
		$enabled = (bool) apply_filters( 'taseo_consent_enabled', $this->settings->is_consent_enabled() );

		$this->active = $enabled && array() !== $this->resolver->resolve();

		return $this->active;
	}

	/**
	 * Category slugs with at least one vendor emitting on this request.
	 *
	 * In registry order, without duplicates. This is what the banner offers, so
	 * a site running no marketing tag never asks about marketing — and adding
	 * one later re-asks every visitor, because their stored record will not
	 * mention the new category.
	 *
	 * @since 1.6.0
	 *
	 * @return array<int, string> Slugs.
	 */
	public function categories(): array {
		$resolved = $this->resolver->resolve();
		$slugs    = array();

		foreach ( $this->registry->all() as $type ) {
			if ( ! isset( $resolved[ $type->key ] ) ) {
				continue;
			}

			$slug = $type->consent->slug();

			if ( ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Script attributes that make one block inert until consent activates it.
	 *
	 * Empty while inactive, so a transport can pass the result straight to
	 * wp_print_inline_script_tag() and emit exactly what it emitted before this
	 * feature existed.
	 *
	 * @since 1.6.0
	 *
	 * @param array<int, Consent> $categories Categories that may activate this block. Any one of them suffices.
	 * @param string              $group      Group name, or '' for none. At most one member of a group ever runs.
	 * @return array<string, string> Attributes.
	 */
	public function attributes( array $categories, string $group = '' ): array {
		if ( ! $this->is_active() ) {
			return array();
		}

		$slugs = array();

		foreach ( $categories as $category ) {
			$slug = $category->slug();

			if ( ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		$attributes = array(
			'type'               => 'text/plain',
			'data-taseo-consent' => implode( ' ', $slugs ),
		);

		if ( '' !== $group ) {
			$attributes['data-taseo-consent-group'] = $group;
		}

		return $attributes;
	}
}
