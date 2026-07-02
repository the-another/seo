<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Indexable;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Indexable\IndexableSync;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( IndexableBackfill::class )]
class IndexableBackfillTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $sync;
	private $settings;
	private $wpdb;
	private IndexableBackfill $backfill;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->posts  = 'wp_posts';
		$wpdb->terms  = 'wp_terms';
		$wpdb->term_taxonomy = 'wp_term_taxonomy';
		$this->wpdb   = $wpdb;

		$this->sync     = Mockery::mock( IndexableSync::class );
		$this->settings = Mockery::mock( Settings::class );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) )->byDefault();
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category' ) )->byDefault();

		$this->backfill = new IndexableBackfill( $this->sync, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_dispatch_resets_progress_and_enqueues_first_job(): void {
		Functions\expect( 'as_has_scheduled_action' )
			->once()
			->with( IndexableBackfill::HOOK, null, IndexableBackfill::GROUP )
			->andReturn( false );
		Functions\expect( 'update_option' )
			->once()
			->with( IndexableBackfill::PROGRESS_OPTION, array( 'phase' => 'posts', 'last_id' => 0, 'mode' => 'full' ) );
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( IndexableBackfill::HOOK, array( 'mode' => 'full' ), IndexableBackfill::GROUP );

		$this->backfill->dispatch( 'full' );
	}

	public function test_dispatch_skips_when_chain_already_running(): void {
		Functions\expect( 'as_has_scheduled_action' )->once()->andReturn( true );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->backfill->dispatch( 'full' );
	}

	public function test_full_posts_batch_syncs_each_id_and_reenqueues(): void {
		Functions\expect( 'get_option' )
			->once()
			->with( IndexableBackfill::PROGRESS_OPTION, false )
			->andReturn( array( 'phase' => 'posts', 'last_id' => 0, 'mode' => 'full' ) );

		// A full batch (size == BATCH_SIZE) of post IDs.
		$ids = range( 1, IndexableBackfill::BATCH_SIZE );
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->with( 'SQL' )->andReturn( array_map( 'strval', $ids ) );

		$this->sync->shouldReceive( 'sync_post' )->times( IndexableBackfill::BATCH_SIZE );

		Functions\expect( 'update_option' )
			->once()
			->with(
				IndexableBackfill::PROGRESS_OPTION,
				array( 'phase' => 'posts', 'last_id' => IndexableBackfill::BATCH_SIZE, 'mode' => 'full' )
			);
		Functions\expect( 'as_enqueue_async_action' )
			->once()
			->with( IndexableBackfill::HOOK, array( 'mode' => 'full' ), IndexableBackfill::GROUP );

		$this->backfill->process_batch( 'full' );
	}

	public function test_short_posts_batch_flips_phase_to_terms(): void {
		Functions\expect( 'get_option' )
			->once()
			->andReturn( array( 'phase' => 'posts', 'last_id' => 5000, 'mode' => 'full' ) );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '5001', '5002' ) ); // short batch.

		$this->sync->shouldReceive( 'sync_post' )->twice();

		Functions\expect( 'update_option' )
			->once()
			->with(
				IndexableBackfill::PROGRESS_OPTION,
				array( 'phase' => 'terms', 'last_id' => 0, 'mode' => 'full' )
			);
		Functions\expect( 'as_enqueue_async_action' )->once();

		$this->backfill->process_batch( 'full' );
	}

	public function test_exhausted_terms_phase_completes_and_cleans_up(): void {
		Functions\expect( 'get_option' )
			->once()
			->andReturn( array( 'phase' => 'terms', 'last_id' => 900, 'mode' => 'full' ) );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() ); // nothing left.

		Functions\expect( 'delete_option' )->once()->with( IndexableBackfill::PROGRESS_OPTION );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->backfill->process_batch( 'full' );
	}

	public function test_permalink_mode_completion_fires_rebuilt_action(): void {
		Functions\expect( 'get_option' )
			->once()
			->andReturn( array( 'phase' => 'terms', 'last_id' => 0, 'mode' => 'permalink' ) );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		Functions\expect( 'delete_option' )->once();
		Actions\expectDone( 'taseo_permalinks_rebuilt' )->once();

		$this->backfill->process_batch( 'permalink' );
	}

	public function test_terms_batch_syncs_term_ids_with_taxonomy(): void {
		Functions\expect( 'get_option' )
			->once()
			->andReturn( array( 'phase' => 'terms', 'last_id' => 0, 'mode' => 'full' ) );

		$rows = array(
			(object) array( 'term_id' => '10', 'taxonomy' => 'category' ),
			(object) array( 'term_id' => '11', 'taxonomy' => 'category' ),
		);
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->sync->shouldReceive( 'sync_term' )->once()->with( 10, 'category' );
		$this->sync->shouldReceive( 'sync_term' )->once()->with( 11, 'category' );

		Functions\expect( 'delete_option' )->once(); // short batch => done.

		$this->backfill->process_batch( 'full' );
	}

	public function test_process_batch_without_progress_option_is_a_noop(): void {
		Functions\expect( 'get_option' )->once()->andReturn( false );
		Functions\expect( 'as_enqueue_async_action' )->never();

		$this->backfill->process_batch( 'full' );
	}
}
