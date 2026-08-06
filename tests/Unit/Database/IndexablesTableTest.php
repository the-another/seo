<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Database;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Database\IndexablesTable;

#[CoversClass( IndexablesTable::class )]
class IndexablesTableTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb          = Mockery::mock( 'wpdb' );
		$wpdb->prefix  = 'wp_';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_table_name_uses_wpdb_prefix(): void {
		$this->assertSame( 'wp_taseo_indexables', IndexablesTable::get_table_name() );
	}

	public function test_get_schema_contains_all_spec_columns(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARACTER SET utf8mb4' );

		$schema = IndexablesTable::get_schema();

		foreach ( array(
			'object_type',
			'object_subtype',
			'object_id',
			'permalink',
			'title',
			'description',
			'canonical_url',
			'robots_noindex',
			'robots_nofollow',
			'robots_noarchive',
			'og_title',
			'og_description',
			'og_image_id',
			'twitter_title',
			'twitter_description',
			'twitter_image_id',
			'breadcrumb_title',
			'schema_disabled',
			'is_indexable',
			'sitemap_file_id',
			'last_modified',
			'created_at',
			'updated_at',
		) as $column ) {
			$this->assertStringContainsString( $column, $schema, "Missing column: {$column}" );
		}

		$this->assertStringContainsString( 'UNIQUE KEY object_lookup (object_type, object_subtype, object_id)', $schema );
		$this->assertStringContainsString( 'KEY object_lookup_by_id (object_type, object_id)', $schema );
		$this->assertStringContainsString( 'KEY sitemap_file_id (sitemap_file_id)', $schema );
	}

	public function test_maybe_upgrade_runs_create_when_version_outdated(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		Functions\expect( 'get_option' )->once()->with( 'taseo_db_version', '0' )->andReturn( '0' );
		Functions\expect( 'dbDelta' )->once();
		Functions\expect( 'update_option' )->once()->with( 'taseo_db_version', IndexablesTable::DB_VERSION );

		IndexablesTable::maybe_upgrade();
	}

	public function test_maybe_upgrade_skips_when_current(): void {
		Functions\expect( 'get_option' )->once()->with( 'taseo_db_version', '0' )->andReturn( IndexablesTable::DB_VERSION );
		Functions\expect( 'dbDelta' )->never();

		IndexablesTable::maybe_upgrade();
	}

	public function test_db_version_bumped_for_sitemap_column(): void {
		$this->assertTrue(
			version_compare( IndexablesTable::DB_VERSION, '1.1.0', '>=' ),
			'DB_VERSION regressed below the sitemap column bump.'
		);
	}

	public function test_get_installed_version_defaults_to_zero(): void {
		Functions\expect( 'get_option' )->once()->with( 'taseo_db_version', '0' )->andReturn( '0' );

		$this->assertSame( '0', IndexablesTable::get_installed_version() );
	}

	public function test_schema_declares_the_image_url_override_columns(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARACTER SET utf8mb4' );

		$schema = IndexablesTable::get_schema();

		// TEXT NULL, matching permalink and canonical_url in this same table.
		// dbDelta is exacting about type spelling, so the type is asserted,
		// not merely the column name.
		$this->assertStringContainsString( 'og_image_url TEXT NULL', $schema );
		$this->assertStringContainsString( 'twitter_image_url TEXT NULL', $schema );
	}

	public function test_db_version_is_bumped_for_the_new_columns(): void {
		$this->assertSame( '1.3.0', IndexablesTable::DB_VERSION );
	}

	public function test_schema_contains_sitemap_images_column(): void {
		global $wpdb;
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( 'DEFAULT CHARACTER SET utf8mb4' );

		$this->assertStringContainsString( 'sitemap_images TEXT NULL', IndexablesTable::get_schema() );
	}

	public function test_db_version_is_bumped_for_sitemap_images(): void {
		$this->assertSame( '1.3.0', IndexablesTable::DB_VERSION );
	}
}
