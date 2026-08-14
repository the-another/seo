<?php
/**
 * Regenerate Command
 *
 * @package TheAnotherSEO
 * @since 1.1.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\CLI;

use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;
use WP_CLI;

/**
 * Class RegenerateCommand
 *
 * Marks every chunk dirty and drains the sweeper — the "Regenerate all
 * sitemap files now" button. Rewrites XML from current database state; it
 * does not re-index, so it cannot move a row between families.
 *
 * @since 1.1.0
 */
class RegenerateCommand {

	/**
	 * Constructor.
	 *
	 * @param SitemapSweeper        $sweeper  Sweeper.
	 * @param SitemapFileRepository $files    Chunk registry repository.
	 * @param SitemapStorage        $storage  Storage seam.
	 * @param Settings              $settings Settings.
	 * @param QueueWaiter           $waiter   Shared queue wait loop.
	 */
	public function __construct(
		private readonly SitemapSweeper $sweeper,
		private readonly SitemapFileRepository $files,
		private readonly SitemapStorage $storage,
		private readonly Settings $settings,
		private readonly QueueWaiter $waiter
	) {
	}

	/**
	 * Rewrite every sitemap file.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Drive the queue and block until the backlog drains.
	 *
	 * ## EXAMPLES
	 *
	 *     wp taseo regenerate
	 *     wp taseo regenerate --wait
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI invokable signature.
		if ( ! $this->settings->is_sitemap_enabled() ) {
			WP_CLI::error( 'The XML sitemap feature is disabled, so the sweep would bail without writing anything.' );
		}

		if ( ! $this->storage->is_writable() ) {
			WP_CLI::error( 'The uploads directory is not writable, so no sitemap file can be written.' );
		}

		// dispatch_full_regeneration() is function_exists()-guarded, so
		// without Action Scheduler it would mark every chunk dirty, enqueue
		// nothing, and look like it succeeded.
		QueueWaiter::require_action_scheduler( function_exists( 'as_enqueue_async_action' ) );

		$disabled = $this->settings->get_disabled_sitemap_families();

		$this->sweeper->dispatch_full_regeneration();

		WP_CLI::success( 'Sitemap regeneration dispatched.' );

		if ( ! isset( $assoc_args['wait'] ) ) {
			return;
		}

		$backlog = $this->files->count_dirty( $disabled );

		$this->waiter->wait(
			SitemapSweeper::GROUP,
			function () use ( $backlog, $disabled ): int {
				if ( $backlog < 1 ) {
					return 100;
				}

				$remaining = $this->files->count_dirty( $disabled );

				return (int) floor( ( ( $backlog - $remaining ) / $backlog ) * 100 );
			}
		);

		WP_CLI::success( 'Sitemap regeneration complete.' );
	}
}
