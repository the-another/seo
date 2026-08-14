<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\CLI;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheAnother\Plugin\SEO\CLI\QueueWaiter;
use TheAnother\Plugin\SEO\CLI\RegenerateCommand;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;

#[CoversClass( RegenerateCommand::class )]
class RegenerateCommandTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $sweeper;
	private $files;
	private $storage;
	private $settings;
	private $waiter;
	private RegenerateCommand $command;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->sweeper  = Mockery::mock( SitemapSweeper::class );
		$this->files    = Mockery::mock( SitemapFileRepository::class );
		$this->storage  = Mockery::mock( SitemapStorage::class );
		$this->settings = Mockery::mock( Settings::class );
		$this->waiter   = Mockery::mock( QueueWaiter::class );

		$this->command = new RegenerateCommand(
			$this->sweeper,
			$this->files,
			$this->storage,
			$this->settings,
			$this->waiter
		);

		Monkey\Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		Monkey\Functions\when( 'as_has_scheduled_action' )->justReturn( false );

		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true )->byDefault();
		$this->settings->shouldReceive( 'get_disabled_sitemap_families' )->andReturn( array() )->byDefault();
		$this->storage->shouldReceive( 'is_writable' )->andReturn( true )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_dispatches_a_full_regeneration(): void {
		$this->sweeper->shouldReceive( 'dispatch_full_regeneration' )->once();
		$this->waiter->shouldNotReceive( 'wait' );

		$this->command->__invoke( array(), array() );
	}

	public function test_errors_when_the_sitemap_feature_is_off(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );
		$this->sweeper->shouldNotReceive( 'dispatch_full_regeneration' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/disabled/i' );

		$this->command->__invoke( array(), array() );
	}

	public function test_errors_when_storage_is_unwritable(): void {
		$this->storage->shouldReceive( 'is_writable' )->andReturn( false );
		$this->sweeper->shouldNotReceive( 'dispatch_full_regeneration' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessageMatches( '/writable/i' );

		$this->command->__invoke( array(), array() );
	}

	public function test_progress_is_the_share_of_the_initial_backlog_drained(): void {
		$this->sweeper->shouldReceive( 'dispatch_full_regeneration' )->once();

		// 40 dirty at dispatch, 10 left => 75% drained.
		$this->files->shouldReceive( 'count_dirty' )->with( array() )->andReturn( 40, 10 );

		$this->waiter->shouldReceive( 'wait' )
			->once()
			->with( SitemapSweeper::GROUP, Mockery::type( 'callable' ) )
			->andReturnUsing(
				static function ( string $group, callable $progress ): void {
					self::assertSame( 75, $progress() );
				}
			);

		$this->command->__invoke( array(), array( 'wait' => true ) );
	}

	public function test_progress_is_complete_when_nothing_was_dirty(): void {
		$this->sweeper->shouldReceive( 'dispatch_full_regeneration' )->once();
		$this->files->shouldReceive( 'count_dirty' )->andReturn( 0 );

		$this->waiter->shouldReceive( 'wait' )->once()->andReturnUsing(
			static function ( string $group, callable $progress ): void {
				self::assertSame( 100, $progress() );
			}
		);

		$this->command->__invoke( array(), array( 'wait' => true ) );
	}
}
