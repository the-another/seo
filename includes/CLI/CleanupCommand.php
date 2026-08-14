<?php
/**
 * Cleanup Command
 *
 * @package TheAnotherSEO
 * @since 1.1.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\CLI;

use RuntimeException;
use TheAnother\Plugin\SEO\Maintenance\OrphanCleaner;
use WP_CLI;

/**
 * Class CleanupCommand
 *
 * Removes indexable rows and sitemap files that no longer correspond to
 * anything. Deletes by default — pass --dry-run to see the same counts
 * without touching anything.
 *
 * @since 1.1.0
 */
class CleanupCommand {

	/**
	 * Accepted --only values.
	 *
	 * @var array<int, string>
	 */
	private const CATEGORIES = array(
		OrphanCleaner::ONLY_ROWS,
		OrphanCleaner::ONLY_DUPLICATES,
		OrphanCleaner::ONLY_FILES,
	);

	/**
	 * Constructor.
	 *
	 * @param OrphanCleaner $cleaner Orphan cleaner.
	 */
	public function __construct( private readonly OrphanCleaner $cleaner ) {
	}

	/**
	 * Remove orphaned indexable rows and sitemap files.
	 *
	 * Three categories run in order: rows whose object is gone (deleted
	 * posts and terms, disabled post types and taxonomies, unregistered
	 * families), objects holding rows under two subtypes at once, and XML
	 * files with no live chunk behind them.
	 *
	 * Deletes by default.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be removed, without removing it.
	 *
	 * [--only=<category>]
	 * : Run one category instead of all three.
	 * ---
	 * options:
	 *   - rows
	 *   - duplicates
	 *   - files
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp taseo cleanup --dry-run
	 *     wp taseo cleanup
	 *     wp taseo cleanup --only=files
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI invokable signature.
		// get_flag_value(), not isset(): --no-dry-run sets the key to false,
		// which isset() reads as "flag present" and would turn a preview into
		// a delete. --only carries a value, so its isset() is correct.
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$only    = isset( $assoc_args['only'] ) ? (string) $assoc_args['only'] : null;

		if ( null !== $only && ! in_array( $only, self::CATEGORIES, true ) ) {
			WP_CLI::error( sprintf( 'Unknown --only "%s". Use one of: %s.', $only, implode( ', ', self::CATEGORIES ) ) );
		}

		try {
			$report = $this->cleaner->clean( $dry_run, $only );
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );

			return;
		}

		$prefix = $dry_run ? '[dry run] would remove' : 'removed';

		// Use log() instead of line() so these detail lines share the logger channel with
		// the success() call below. line() uses PHP's output buffer, which flushes last,
		// causing the summary to overtake the details regardless of call order.
		WP_CLI::log( sprintf( '%s %d indexable rows whose object is gone', $prefix, $report['rows'] ) );
		WP_CLI::log( sprintf( '%s duplicate subtype rows for %d objects', $prefix, $report['duplicates'] ) );
		WP_CLI::log( sprintf( '%s %d sitemap files with no live chunk', $prefix, $report['files'] ) );

		foreach ( $report['skipped'] as $reason ) {
			WP_CLI::warning( (string) $reason );
		}

		$total = $report['rows'] + $report['duplicates'] + $report['files'];

		WP_CLI::success(
			$dry_run
				? sprintf( 'Dry run complete: %d items would be removed.', $total )
				: sprintf( 'Cleanup complete: %d items removed.', $total )
		);
	}
}
