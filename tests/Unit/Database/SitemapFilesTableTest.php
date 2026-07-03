<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Database\SitemapFilesTable;

#[CoversClass( SitemapFilesTable::class )]
class SitemapFilesTableTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_table_name_uses_wpdb_prefix(): void {
		$this->assertSame( 'wp_taseo_sitemap_files', SitemapFilesTable::get_table_name() );
	}

	public function test_get_schema_contains_all_spec_columns_and_keys(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARACTER SET utf8mb4' );

		$schema = SitemapFilesTable::get_schema();

		foreach ( array(
			'object_subtype',
			'chunk_number',
			'link_count',
			'is_dirty',
			'last_modified',
			'generated_at',
			'created_at',
		) as $column ) {
			$this->assertStringContainsString( $column, $schema, "Missing column: {$column}" );
		}

		$this->assertStringContainsString( 'UNIQUE KEY subtype_chunk (object_subtype, chunk_number)', $schema );
		$this->assertStringContainsString( 'KEY subtype_capacity (object_subtype, link_count)', $schema );
		$this->assertStringContainsString( 'KEY is_dirty (is_dirty)', $schema );
	}

	public function test_maybe_upgrade_runs_create_when_version_outdated(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		Functions\expect( 'get_option' )->once()->with( 'taseo_sitemap_db_version', '0' )->andReturn( '0' );
		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )->once()->with( 'taseo_sitemap_db_version', SitemapFilesTable::DB_VERSION );

		SitemapFilesTable::maybe_upgrade();
	}

	public function test_maybe_upgrade_skips_when_current(): void {
		Functions\expect( 'get_option' )->once()->with( 'taseo_sitemap_db_version', '0' )->andReturn( SitemapFilesTable::DB_VERSION );
		Functions\expect( 'dbDelta' )->never();

		SitemapFilesTable::maybe_upgrade();
	}
}
