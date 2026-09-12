<?php
/**
 * Tracking Tag Output
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics;

use TheAnother\Plugin\SEO\Analytics\Transport\TagTransport;
use TheAnother\Plugin\SEO\HookManager;

/**
 * Class TagOutput
 *
 * Registers one callback per transport, placement and priority, and hands each
 * transport the slice of the resolved collection it owns.
 *
 * Grouping is what keeps GA4 and Google Ads to one gtag bootstrap: they share a
 * transport, a placement and a priority, so they share a callback. Vendors that
 * declare a <noscript> half get a second callback on wp_body_open, registered in
 * registry order — which is how the container's iframe keeps printing before the
 * pixel's image.
 *
 * @since 1.5.0
 */
class TagOutput {

	/**
	 * Constructor.
	 *
	 * @param TagRegistry $registry Declared vendors.
	 * @param TagResolver $resolver Per-request resolution.
	 */
	public function __construct(
		private readonly TagRegistry $registry,
		private readonly TagResolver $resolver
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.5.0
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		foreach ( $this->groups() as $group ) {
			$transport = $group['transport'];
			$keys      = $group['keys'];
			$noscript  = $group['noscript'];

			$hook_manager->register_action(
				$group['hook'],
				function () use ( $transport, $keys, $noscript ): void {
					$tags = $this->tags_for( $keys );

					if ( $noscript ) {
						$transport->emit_noscript( $tags );

						return;
					}

					$transport->emit_primary( $tags );
				},
				$group['priority']
			);
		}
	}

	/**
	 * One group per transport, placement and priority, primaries first.
	 *
	 * @return array<int, array{hook: string, priority: int, transport: TagTransport, keys: array<int, string>, noscript: bool}> Groups.
	 */
	private function groups(): array {
		$primary  = array();
		$noscript = array();

		foreach ( $this->registry->all() as $type ) {
			$this->group(
				$primary,
				$type->transport::class . '|' . $type->placement->hook() . '|' . $type->priority,
				$type->placement->hook(),
				$type->priority,
				$type,
				false
			);

			if ( ! $type->has_noscript ) {
				continue;
			}

			$this->group(
				$noscript,
				$type->transport::class,
				Placement::BodyOpen->hook(),
				10,
				$type,
				true
			);
		}

		return array_merge( array_values( $primary ), array_values( $noscript ) );
	}

	/**
	 * Add one vendor to a group, creating the group on first sight.
	 *
	 * @param array<string, array{hook: string, priority: int, transport: TagTransport, keys: array<int, string>, noscript: bool}> $groups   Groups, by reference.
	 * @param string                                                                                                               $id       Group identity.
	 * @param string                                                                                                               $hook     Hook name.
	 * @param int                                                                                                                  $priority Hook priority.
	 * @param TagType                                                                                                              $type     Vendor.
	 * @param bool                                                                                                                 $noscript Whether this group emits the no-JS half.
	 * @return void
	 */
	private function group( array &$groups, string $id, string $hook, int $priority, TagType $type, bool $noscript ): void {
		if ( ! isset( $groups[ $id ] ) ) {
			$groups[ $id ] = array(
				'hook'      => $hook,
				'priority'  => $priority,
				'transport' => $type->transport,
				'keys'      => array(),
				'noscript'  => $noscript,
			);
		}

		$groups[ $id ]['keys'][] = $type->key;
	}

	/**
	 * The resolved IDs for one group's vendors.
	 *
	 * @param array<int, string> $keys Vendor keys.
	 * @return array<string, array<int, string>> Vendor key => IDs, in registry order.
	 */
	private function tags_for( array $keys ): array {
		$resolved = $this->resolver->resolve();
		$tags     = array();

		foreach ( $keys as $key ) {
			if ( isset( $resolved[ $key ] ) ) {
				$tags[ $key ] = $resolved[ $key ];
			}
		}

		return $tags;
	}
}
