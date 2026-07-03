<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;

#[CoversClass( SitemapSweeper::class )]
class SitemapSweeperTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $files;
	private $writer;
	private $settings;
	private SitemapSweeper $sweeper;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->files    = Mockery::mock( SitemapFileRepository::class );
		$this->writer   = Mockery::mock( SitemapFileWriter::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->sweeper = new SitemapSweeper( $this->files, $this->writer, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_handle_sweep_rebuilds_each_dirty_chunk_in_one_bounded_batch(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->writer->shouldReceive( 'is_writable' )->andReturn( true );

		$chunks = array( array( 'id' => '1' ), array( 'id' => '2' ) );
		$this->files->shouldReceive( 'get_dirty_chunks' )->once()->with( SitemapSweeper::BATCH_SIZE )->andReturn( $chunks );
		$this->writer->shouldReceive( 'rebuild' )->twice()->andReturn( true );

		// Partial batch — backlog drained, no follow-up chain.
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->sweeper->handle_sweep();
	}

	public function test_handle_sweep_chains_follow_up_when_backlog_remains(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->writer->shouldReceive( 'is_writable' )->andReturn( true );

		$chunks = array_fill( 0, SitemapSweeper::BATCH_SIZE, array( 'id' => '1' ) );
		$this->files->shouldReceive( 'get_dirty_chunks' )->once()->andReturn( $chunks );
		$this->writer->shouldReceive( 'rebuild' )->times( SitemapSweeper::BATCH_SIZE )->andReturn( true );
		$this->files->shouldReceive( 'count_dirty' )->once()->andReturn( 3980 );

		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( SitemapSweeper::HOOK, array(), SitemapSweeper::GROUP );

		$this->sweeper->handle_sweep();
	}

	public function test_handle_sweep_does_not_chain_on_failed_rebuilds(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->writer->shouldReceive( 'is_writable' )->andReturn( true );

		$chunks = array_fill( 0, SitemapSweeper::BATCH_SIZE, array( 'id' => '1' ) );
		$this->files->shouldReceive( 'get_dirty_chunks' )->once()->andReturn( $chunks );
		$this->writer->shouldReceive( 'rebuild' )->times( SitemapSweeper::BATCH_SIZE )->andReturn( false );

		// Failing writes must wait for the recurring tick, not spin a hot chain.
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->sweeper->handle_sweep();
	}

	public function test_handle_sweep_bails_when_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		$this->files->shouldNotReceive( 'get_dirty_chunks' );

		$this->sweeper->handle_sweep();
	}

	public function test_handle_sweep_bails_when_uploads_not_writable(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->writer->shouldReceive( 'is_writable' )->andReturn( false );

		// Spec: never fatal, never partially write.
		$this->files->shouldNotReceive( 'get_dirty_chunks' );
		$this->writer->shouldNotReceive( 'rebuild' );

		$this->sweeper->handle_sweep();
	}

	public function test_ensure_recurring_schedules_exactly_once(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( SitemapSweeper::HOOK, null, SitemapSweeper::GROUP )
			->andReturn( false );
		Functions\expect( 'as_schedule_recurring_action' )
			->once()
			->with(
				Mockery::type( 'int' ),
				SitemapSweeper::INTERVAL,
				SitemapSweeper::HOOK,
				array(),
				SitemapSweeper::GROUP
			);

		$this->sweeper->ensure_recurring();
	}

	public function test_ensure_recurring_skips_when_already_scheduled(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\expect( 'as_has_scheduled_action' )->once()->andReturn( true );
		Functions\expect( 'as_schedule_recurring_action' )->never();

		$this->sweeper->ensure_recurring();
	}

	public function test_ensure_recurring_skips_frontend_requests(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );

		Functions\expect( 'as_has_scheduled_action' )->never();
		Functions\expect( 'as_schedule_recurring_action' )->never();

		$this->sweeper->ensure_recurring();
	}

	public function test_ensure_recurring_unschedules_when_disabled(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		// Define the AS function so the function_exists guard passes.
		Functions\when( 'as_schedule_recurring_action' )->justReturn( null );
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( SitemapSweeper::HOOK, null, SitemapSweeper::GROUP )
			->andReturn( true );
		Functions\expect( 'as_unschedule_all_actions' )
			->once()
			->with( SitemapSweeper::HOOK, array(), SitemapSweeper::GROUP );
		Functions\expect( 'as_schedule_recurring_action' )->never();

		$this->sweeper->ensure_recurring();
	}

	public function test_dispatch_full_regeneration_marks_all_dirty_and_sweeps_now(): void {
		$this->files->shouldReceive( 'mark_all_dirty' )->once();

		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( SitemapSweeper::HOOK, array(), SitemapSweeper::GROUP );

		$this->sweeper->dispatch_full_regeneration();
	}

	public function test_init_registers_sweep_and_permalink_rebuild_listeners(): void {
		Functions\when( 'has_action' )->justReturn( false );

		$hook_manager = new HookManager();
		$this->sweeper->init( $hook_manager );

		$names = array_column( $hook_manager->get_registered_hooks(), 'hook' );

		$this->assertContains( SitemapSweeper::HOOK, $names );
		$this->assertContains( 'taseo_permalinks_rebuilt', $names );
		$this->assertContains( 'init', $names );
	}
}
