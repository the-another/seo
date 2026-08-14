<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\CLI;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\CLI\QueueWaiter;

#[CoversClass( QueueWaiter::class )]
class QueueWaiterTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_runs_batches_until_the_group_is_empty(): void {
		$pending = array( array( 1 ), array( 1 ), array() );

		Monkey\Functions\when( 'as_get_scheduled_actions' )->alias(
			static function () use ( &$pending ): array {
				return array_shift( $pending ) ?? array();
			}
		);

		$runs      = 0;
		$reported  = array();
		$percents  = array( 40, 100 );

		$waiter = new QueueWaiter(
			static function ( string $group ) use ( &$runs ): void {
				++$runs;
			},
			static function ( int $percent, bool $done ) use ( &$reported ): void {
				$reported[] = array( $percent, $done );
			}
		);

		$waiter->wait( 'taseo', static function () use ( &$percents ): int {
			return array_shift( $percents ) ?? 100;
		} );

		$this->assertSame( 2, $runs );
		$this->assertSame( array( 40, false ), $reported[0] );
		$this->assertSame( array( 100, true ), $reported[ count( $reported ) - 1 ] );
	}

	public function test_require_action_scheduler_errors_when_unavailable(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/Action Scheduler/' );

		QueueWaiter::require_action_scheduler( false );
	}

	public function test_require_action_scheduler_is_silent_when_available(): void {
		\WP_CLI::$lines = array();

		QueueWaiter::require_action_scheduler( true );

		// Nothing printed and nothing thrown: the guard took the early return.
		$this->assertSame( array(), \WP_CLI::$lines );
	}

	public function test_reports_completion_without_running_when_nothing_is_pending(): void {
		Monkey\Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );

		$runs     = 0;
		$reported = array();

		$waiter = new QueueWaiter(
			static function ( string $group ) use ( &$runs ): void {
				++$runs;
			},
			static function ( int $percent, bool $done ) use ( &$reported ): void {
				$reported[] = array( $percent, $done );
			}
		);

		$waiter->wait( 'taseo', static fn(): int => 100 );

		$this->assertSame( 0, $runs );
		$this->assertSame( array( array( 100, true ) ), $reported );
	}
}
