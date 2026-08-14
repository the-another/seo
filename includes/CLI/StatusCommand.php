<?php
/**
 * Status Command
 *
 * @package TheAnotherSEO
 * @since 1.1.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\CLI;

use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use WP_CLI;

/**
 * Class StatusCommand
 *
 * Reports index and sitemap state. Computes nothing: every number here is
 * the same one the settings screen renders, read through the same calls.
 *
 * @since 1.1.0
 */
class StatusCommand {

	/**
	 * Constructor.
	 *
	 * @param IndexableBackfill     $backfill Backfill (progress).
	 * @param SitemapFileRepository $files    Chunk registry repository.
	 * @param SitemapStorage        $storage  Storage seam.
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private readonly IndexableBackfill $backfill,
		private readonly SitemapFileRepository $files,
		private readonly SitemapStorage $storage,
		private readonly Settings $settings
	) {
	}

	/**
	 * Report index and sitemap status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taseo status
	 *     wp taseo status --format=json
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI invokable signature.
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$report = $this->build_report();

		if ( 'json' === $format || 'yaml' === $format ) {
			// One document: the per-subtype rows plus the scalars, which have
			// no place in a flat row list.
			WP_CLI::print_value( $report, array( 'format' => $format ) );

			return;
		}

		\WP_CLI\Utils\format_items( $format, $report['subtypes'], array( 'subtype', 'chunks', 'links' ) );

		if ( 'csv' === $format ) {
			// Deliberate: mixing the scalars in would give the file two row
			// shapes. Documented in OPTIONS above.
			return;
		}

		WP_CLI::line( '' );

		foreach ( $report['summary'] as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}

			WP_CLI::line( sprintf( '%-18s %s', $key . ':', null === $value ? 'never' : $value ) );
		}
	}

	/**
	 * Assemble the report.
	 *
	 * Public so it can be asserted on directly; the formatting above is the
	 * only part that needs WP-CLI.
	 *
	 * @return array{subtypes: array<int, array<string, mixed>>, summary: array<string, mixed>} Report.
	 */
	public function build_report(): array {
		$progress = $this->backfill->get_progress();

		// The disabled list has to be passed through exactly as the Sitemap
		// tab does: a disabled family's chunks are permanently dirty, so a
		// dirty count that includes them never reaches zero.
		$status = $this->files->get_status_summary( $this->settings->get_disabled_sitemap_families() );

		$subtypes = array();

		foreach ( $status['subtypes'] as $subtype => $counts ) {
			$subtypes[] = array(
				'subtype' => (string) $subtype,
				'chunks'  => (int) $counts['chunks'],
				'links'   => (int) $counts['links'],
			);
		}

		return array(
			'subtypes' => $subtypes,
			'summary'  => array(
				'index_phase'      => (string) $progress['phase'],
				'index_percentage' => (float) $progress['percentage'],
				'dirty_files'      => (int) $status['dirty'],
				'last_generated'   => $status['last_generated'],
				'sitemap_enabled'  => $this->settings->is_sitemap_enabled(),
				'storage_writable' => $this->storage->is_writable(),
			),
		);
	}
}
