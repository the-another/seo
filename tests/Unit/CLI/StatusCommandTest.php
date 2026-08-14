<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\CLI;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\CLI\StatusCommand;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;

#[CoversClass( StatusCommand::class )]
class StatusCommandTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_report_passes_disabled_families_through_and_assembles_both_halves(): void {
		$backfill = Mockery::mock( IndexableBackfill::class );
		$files    = Mockery::mock( SitemapFileRepository::class );
		$storage  = Mockery::mock( SitemapStorage::class );
		$settings = Mockery::mock( Settings::class );

		$backfill->shouldReceive( 'get_progress' )->once()->andReturn(
			array( 'phase' => 'posts', 'total' => 1000, 'processed' => 250, 'percentage' => 25.0 )
		);

		// The disabled list must reach get_status_summary(), or the dirty
		// count includes suspended chunks that never drain.
		$settings->shouldReceive( 'get_disabled_sitemap_families' )->once()->andReturn( array( 'product' ) );
		$files->shouldReceive( 'get_status_summary' )->once()->with( array( 'product' ) )->andReturn(
			array(
				'subtypes'       => array( 'aucteeno_item' => array( 'chunks' => 45, 'links' => 44_500 ) ),
				'dirty'          => 3,
				'last_generated' => '2026-08-14 11:53:11',
			)
		);

		$settings->shouldReceive( 'is_sitemap_enabled' )->once()->andReturn( true );
		$storage->shouldReceive( 'is_writable' )->once()->andReturn( true );

		$report = ( new StatusCommand( $backfill, $files, $storage, $settings ) )->build_report();

		$this->assertSame(
			array( array( 'subtype' => 'aucteeno_item', 'chunks' => 45, 'links' => 44_500 ) ),
			$report['subtypes']
		);
		$this->assertSame( 'posts', $report['summary']['index_phase'] );
		$this->assertSame( 25.0, $report['summary']['index_percentage'] );
		$this->assertSame( 3, $report['summary']['dirty_files'] );
		$this->assertSame( '2026-08-14 11:53:11', $report['summary']['last_generated'] );
		$this->assertTrue( $report['summary']['sitemap_enabled'] );
		$this->assertTrue( $report['summary']['storage_writable'] );
	}

	public function test_report_reports_never_generated_as_null(): void {
		$backfill = Mockery::mock( IndexableBackfill::class );
		$files    = Mockery::mock( SitemapFileRepository::class );
		$storage  = Mockery::mock( SitemapStorage::class );
		$settings = Mockery::mock( Settings::class );

		$backfill->shouldReceive( 'get_progress' )->andReturn(
			array( 'phase' => 'idle', 'total' => 0, 'processed' => 0, 'percentage' => 100.0 )
		);
		$settings->shouldReceive( 'get_disabled_sitemap_families' )->andReturn( array() );
		$files->shouldReceive( 'get_status_summary' )->andReturn(
			array( 'subtypes' => array(), 'dirty' => 0, 'last_generated' => null )
		);
		$settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );
		$storage->shouldReceive( 'is_writable' )->andReturn( false );

		$report = ( new StatusCommand( $backfill, $files, $storage, $settings ) )->build_report();

		$this->assertSame( array(), $report['subtypes'] );
		$this->assertNull( $report['summary']['last_generated'] );
		$this->assertFalse( $report['summary']['sitemap_enabled'] );
	}
}
