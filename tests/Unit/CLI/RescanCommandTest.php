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
use TheAnother\Plugin\SEO\CLI\RescanCommand;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;

#[CoversClass( RescanCommand::class )]
class RescanCommandTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $backfill;
	private $waiter;
	private RescanCommand $command;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->backfill = Mockery::mock( IndexableBackfill::class );
		$this->waiter   = Mockery::mock( QueueWaiter::class );
		$this->command  = new RescanCommand( $this->backfill, $this->waiter );

		Monkey\Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		Monkey\Functions\when( 'as_has_scheduled_action' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_dispatches_full_mode_by_default(): void {
		$this->backfill->shouldReceive( 'dispatch' )->once()->with( 'full' );
		$this->waiter->shouldNotReceive( 'wait' );

		$this->command->__invoke( array(), array() );
	}

	public function test_dispatches_permalink_mode_when_asked(): void {
		$this->backfill->shouldReceive( 'dispatch' )->once()->with( 'permalink' );

		$this->command->__invoke( array(), array( 'mode' => 'permalink' ) );
	}

	public function test_rejects_an_unknown_mode(): void {
		$this->backfill->shouldNotReceive( 'dispatch' );

		$this->expectException( RuntimeException::class );

		$this->command->__invoke( array(), array( 'mode' => 'sideways' ) );
	}

	public function test_warns_and_does_not_dispatch_when_a_chain_is_queued(): void {
		Monkey\Functions\when( 'as_has_scheduled_action' )->justReturn( true );

		$this->backfill->shouldNotReceive( 'dispatch' );
		$this->waiter->shouldNotReceive( 'wait' );

		$this->command->__invoke( array(), array() );
	}

	public function test_waits_when_asked(): void {
		$this->backfill->shouldReceive( 'dispatch' )->once()->with( 'full' );
		$this->backfill->shouldReceive( 'get_progress' )->andReturn(
			array( 'phase' => 'posts', 'total' => 10, 'processed' => 5, 'percentage' => 50.0 )
		);

		$this->waiter->shouldReceive( 'wait' )
			->once()
			->with( IndexableBackfill::GROUP, Mockery::type( 'callable' ) )
			->andReturnUsing(
				static function ( string $group, callable $progress ): void {
					// The callback must return an int for the progress bar.
					self::assertSame( 50, $progress() );
				}
			);

		$this->command->__invoke( array(), array( 'wait' => true ) );
	}
}
