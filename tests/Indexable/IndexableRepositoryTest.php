<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Indexable;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;

#[CoversClass( IndexableRepository::class )]
class IndexableRepositoryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private IndexableRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->repository = new IndexableRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_upsert_synced_fields_issues_insert_on_duplicate_key_update(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					function ( string $sql ): bool {
						return str_contains( $sql, 'INSERT INTO wp_taseo_indexables' )
							&& str_contains( $sql, 'ON DUPLICATE KEY UPDATE' )
							&& str_contains( $sql, 'permalink = VALUES(permalink)' )
							&& str_contains( $sql, 'is_indexable = VALUES(is_indexable)' )
							&& str_contains( $sql, 'last_modified = VALUES(last_modified)' )
							&& ! str_contains( $sql, 'title = VALUES' ); // overrides never synced.
					}
				),
				'post',
				'product',
				88123,
				'https://example.com/product/widget/',
				1,
				'2026-07-02 10:00:00'
			)
			->andReturn( 'PREPARED_SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'PREPARED_SQL' );

		$this->repository->upsert_synced_fields(
			'post',
			'product',
			88123,
			array(
				'permalink'     => 'https://example.com/product/widget/',
				'is_indexable'  => true,
				'last_modified' => '2026-07-02 10:00:00',
			)
		);
	}

	public function test_find_returns_row_as_assoc_array(): void {
		$row = array( 'id' => '9', 'object_type' => 'post', 'object_id' => '5' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'SQL', ARRAY_A )->andReturn( $row );

		$this->assertSame( $row, $this->repository->find( 'post', 'page', 5 ) );
	}

	public function test_find_returns_null_when_absent(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( $this->repository->find( 'post', 'page', 5 ) );
	}

	public function test_find_for_post_looks_up_without_subtype(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, "object_type = 'post'" )
						&& str_contains( $sql, 'object_id = %d' )
						&& ! str_contains( $sql, 'object_subtype' )
				),
				123
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( array( 'id' => '1' ) );

		$this->assertSame( array( 'id' => '1' ), $this->repository->find_for_post( 123 ) );
	}

	public function test_save_overrides_stores_empty_string_as_null_and_ignores_unknown_keys(): void {
		// Row exists already.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'FIND_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'FIND_SQL', ARRAY_A )->andReturn( array( 'id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_taseo_indexables',
				array(
					'title'           => 'Custom Title',
					'description'     => null,
					'schema_disabled' => 1,
				),
				array( 'id' => 7 )
			);

		$this->repository->save_overrides(
			'post',
			'product',
			88123,
			array(
				'title'           => 'Custom Title',
				'description'     => '',
				'schema_disabled' => 1,
				'hack_column'     => 'ignored', // not in whitelist.
			)
		);
	}

	public function test_save_overrides_never_writes_null_for_schema_disabled(): void {
		// Row exists already.
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'FIND_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'FIND_SQL', ARRAY_A )->andReturn( array( 'id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_taseo_indexables',
				array(
					'schema_disabled' => 0,
					'description'     => null,
				),
				array( 'id' => 7 )
			);

		$this->repository->save_overrides(
			'post',
			'product',
			88123,
			array(
				'schema_disabled' => '', // must coerce to 0, never NULL: column is NOT NULL.
				'description'     => '', // still clears to NULL for nullable columns.
			)
		);
	}

	public function test_save_overrides_creates_row_when_absent(): void {
		$this->wpdb->shouldReceive( 'prepare' )->times( 3 )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn( null, array( 'id' => '11' ) ); // absent, then present after upsert.
		$this->wpdb->shouldReceive( 'query' )->once(); // the upsert.
		$this->wpdb->shouldReceive( 'update' )->once();

		$this->repository->save_overrides( 'term', 'product_cat', 44, array( 'title' => 'Cat title' ) );
	}

	public function test_delete_removes_row(): void {
		$this->wpdb->shouldReceive( 'delete' )
			->once()
			->with(
				'wp_taseo_indexables',
				array(
					'object_type'    => 'post',
					'object_subtype' => 'product',
					'object_id'      => 88123,
				)
			);

		$this->repository->delete( 'post', 'product', 88123 );
	}
}
