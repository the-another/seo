<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\CLI;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheAnother\Plugin\SEO\CLI\CleanupCommand;
use TheAnother\Plugin\SEO\Maintenance\OrphanCleaner;

#[CoversClass( CleanupCommand::class )]
class CleanupCommandTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $cleaner;
	private CleanupCommand $command;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->cleaner = Mockery::mock( OrphanCleaner::class );
		$this->command = new CleanupCommand( $this->cleaner );

		\WP_CLI::$lines = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array{rows: int, duplicates: int, files: int, skipped: array<int, string>}
	 */
	private function report( int $rows = 0, int $duplicates = 0, int $files = 0, array $skipped = array() ): array {
		return array( 'rows' => $rows, 'duplicates' => $duplicates, 'files' => $files, 'skipped' => $skipped );
	}

	public function test_deletes_by_default(): void {
		$this->cleaner->shouldReceive( 'clean' )->once()->with( false, null )->andReturn( $this->report( 3, 2, 1 ) );

		$this->command->__invoke( array(), array() );

		$this->assertStringContainsString( 'success', implode( "\n", \WP_CLI::$lines ) );
	}

	public function test_dry_run_passes_the_flag_through_and_labels_the_output(): void {
		$this->cleaner->shouldReceive( 'clean' )->once()->with( true, null )->andReturn( $this->report( 3 ) );

		$this->command->__invoke( array(), array( 'dry-run' => true ) );

		$this->assertStringContainsStringIgnoringCase( 'dry run', implode( "\n", \WP_CLI::$lines ) );
	}

	public function test_only_scopes_the_run(): void {
		$this->cleaner->shouldReceive( 'clean' )->once()->with( false, OrphanCleaner::ONLY_FILES )->andReturn( $this->report( 0, 0, 4 ) );

		$this->command->__invoke( array(), array( 'only' => 'files' ) );
	}

	public function test_rejects_an_unknown_only_value(): void {
		$this->cleaner->shouldNotReceive( 'clean' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/rows, duplicates, files/' );

		$this->command->__invoke( array(), array( 'only' => 'everything' ) );
	}

	public function test_surfaces_the_inactive_provider_guard_as_an_error(): void {
		$this->cleaner->shouldReceive( 'clean' )->once()->andThrow( new RuntimeException( 'No sitemap families are registered, but 1200 pushed URL rows exist.' ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/1200/' );

		$this->command->__invoke( array(), array() );
	}

	public function test_prints_skip_reasons(): void {
		$this->cleaner->shouldReceive( 'clean' )->once()->andReturn(
			$this->report( 0, 0, 0, array( 'Filesystem scan skipped: uploads are stream-wrapped.' ) )
		);

		$this->command->__invoke( array(), array() );

		$this->assertStringContainsString( 'stream-wrapped', implode( "\n", \WP_CLI::$lines ) );
	}
}
