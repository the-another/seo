# The Another SEO — XML Sitemap Generator — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the XML sitemap module of the-another-seo: stored (never positional) chunk assignment via a small `wp_taseo_sitemap_files` registry table, dirty-flag + asynchronous rebuild of physical `.xml` files under `wp-content/uploads/taseo-sitemaps/`, a live root index at `/sitemap.xml`, root-level chunk URLs served via webserver rewrite (Apache) with a WP `readfile()` fallback, and a Sitemap settings tab with operational status.

**Architecture:** A new `includes/Sitemap/` bounded context consuming the existing indexable table. Membership lives on each indexable row (`sitemap_file_id` pointer, new column); the registry row only caches `link_count`/`is_dirty`/`last_modified`. Module 1 fires two new lifecycle actions from `IndexableRepository` (`taseo_indexable_synced`, `taseo_indexable_deleting`); `SitemapAssignment` listens and reconciles chunk membership with a race-safe conditional-UPDATE slot claim. A recurring Action Scheduler sweep (`SitemapSweeper`) rebuilds dirty chunks in bounded batches and self-chains when a backlog remains. `SitemapFileWriter` renders/writes one chunk file idempotently. `SitemapServer` owns the rewrite endpoints, robots.txt line, and the `.htaccess` static-serving rules.

**Tech Stack:** PHP 8.3+, WordPress 6.9+, Composer PSR-4, bundled `woocommerce/action-scheduler`, PHPUnit 11 + Brain Monkey + Mockery (unit tests, no WP test suite/DB). No new dependencies, no JS.

## Global Constraints

- PHP 8.3+, WordPress 6.9+ (spec: "Code conventions" of the meta tags design, inherited)
- Namespace root: `TheAnother\Plugin\SEO` — StudlyCaps, no underscores anywhere; files are PSR-4 `SitemapFileWriter.php`, never `class-*.php`
- Text domain `the-another-seo`; DB/hook prefix `taseo`; method names stay snake_case (WP idiom)
- Mass operations run as self-chaining Action Scheduler jobs in group `taseo`, never long requests or WP-Cron loops (spec: "Regeneration")
- Chunk size is admin-configurable, default and hard max **1000** (spec: assumption 1)
- Regeneration is dirty-flag + asynchronous sweep; save/delete hooks only flip cheap flags (spec: assumption 2)
- Physical `.xml` files under `wp-content/uploads/taseo-sitemaps/`, written via the WP Filesystem API; WordPress never generates a chunk on the fly (spec: assumption 3, "Regeneration")
- Any under-capacity chunk can receive new objects — lowest-numbered chunk with room wins; objects are never moved between chunks after assignment (spec: assumption 4, "Assignment algorithm")
- Slot claims are a single conditional `UPDATE ... WHERE id = %d AND link_count < %d`; an affected-rows-0 result means a lost race and the search re-runs (spec: "Assignment algorithm")
- Only `object_type` `post`/`term` rows participate; `system_page` rows never do, regardless of `is_indexable` (spec: assumption 5, "Assignment algorithm")
- No separate "include in sitemap" toggle — participation is `enabled types registry` × `is_indexable` (spec: assumption 6)
- `robots.txt` gets an auto-added `Sitemap:` line pointing at `/sitemap.xml` (spec: assumption 7)
- No `<priority>`/`<changefreq>` tags — `<loc>` and `<lastmod>` only (spec: assumption 8)
- Root index is generated live per request from the small registry table; chunk URLs in it are **root-level** (`/product-sitemap-3.xml`), never uploads URLs (spec: "Root index")
- Empty chunks (link_count 0) are deleted immediately — registry row and physical file — and vanish from the root index (spec: "How objects link to sitemap files")
- Concurrent rebuilds are accepted as harmless (idempotent renders); no locking (spec: "Error handling")
- Uploads-not-writable disables generation with an admin notice; it never fatals or partially writes (spec: "Error handling")
- All output escaped; nonce + capability checks on every admin action (house rule from module 1)

---

## File Structure

```
the-another-seo/
├── the-another-seo.php                     # MODIFY (Task 9): deactivation hook unschedules the sweep
├── includes/
│   ├── Plugin.php                          # MODIFY (Task 9): sitemap service graph, rewrite flush, upgrade backfill flag
│   ├── Installer.php                       # MODIFY (Task 1): create registry table, set rewrite-flush flag
│   ├── Database/
│   │   ├── IndexablesTable.php             # MODIFY (Task 1): +sitemap_file_id column/key, DB_VERSION 1.1.0, get_installed_version()
│   │   └── SitemapFilesTable.php           # NEW (Task 1): wp_taseo_sitemap_files schema + migration
│   ├── Indexable/
│   │   └── IndexableRepository.php         # MODIFY (Task 2): fire taseo_indexable_synced / taseo_indexable_deleting
│   ├── Settings/
│   │   └── Settings.php                    # MODIFY (Task 5): is_sitemap_enabled(), get_sitemap_max_links()
│   ├── Sitemap/
│   │   ├── SitemapFileRepository.php       # NEW (Task 3): registry CRUD, atomic slot claim/release, dirty queries, status
│   │   ├── SitemapFileWriter.php           # NEW (Task 4): render <urlset>, write/delete physical files, lastmod format
│   │   ├── SitemapAssignment.php           # NEW (Task 5): reconcile chunk membership on indexable lifecycle events
│   │   ├── SitemapSweeper.php              # NEW (Task 6): recurring AS sweep, batch rebuild, self-chain, full regeneration
│   │   └── SitemapServer.php               # NEW (Task 7): rewrites, root index, chunk fallback serving, robots.txt, .htaccess rules
│   └── Admin/
│       └── SettingsPage.php                # MODIFY (Task 8): Sitemap tab, regenerate action, storage notice
└── tests/
    ├── InstallerTest.php                   # NEW (Task 1)
    ├── PluginTest.php                      # MODIFY (Task 9)
    ├── Database/
    │   ├── IndexablesTableTest.php         # MODIFY (Task 1)
    │   └── SitemapFilesTableTest.php       # NEW (Task 1)
    ├── Indexable/IndexableRepositoryTest.php  # MODIFY (Task 2)
    ├── Settings/SettingsTest.php           # MODIFY (Task 5)
    ├── Sitemap/
    │   ├── SitemapFileRepositoryTest.php   # NEW (Task 3)
    │   ├── SitemapFileWriterTest.php       # NEW (Task 4)
    │   ├── SitemapAssignmentTest.php       # NEW (Task 5)
    │   ├── SitemapSweeperTest.php          # NEW (Task 6)
    │   └── SitemapServerTest.php           # NEW (Task 7)
    └── Admin/SettingsPageTest.php          # MODIFY (Task 8)
```

Data flow: `IndexableSync`/`IndexableBackfill` → `IndexableRepository` (fires lifecycle actions) → `SitemapAssignment` (writes `sitemap_file_id` pointers + registry counters) → `SitemapSweeper` (drains dirty flags) → `SitemapFileWriter` (physical files) → `SitemapServer` (serves root index live; chunk files are static). The backfill needs zero sitemap-specific code: every row it syncs fires `taseo_indexable_synced`, so initial population happens inline exactly as the spec requires.

---

### Task 1: Schema — SitemapFilesTable, sitemap_file_id column, Installer

**Files:**
- Create: `includes/Database/SitemapFilesTable.php`
- Modify: `includes/Database/IndexablesTable.php`
- Modify: `includes/Installer.php`
- Test: `tests/Database/SitemapFilesTableTest.php` (new), `tests/Database/IndexablesTableTest.php` (modify), `tests/InstallerTest.php` (new)

**Interfaces:**
- Consumes: `IndexablesTable` (module 1), WP `dbDelta()`, `get_option()`/`update_option()`
- Produces:
  - `TheAnother\Plugin\SEO\Database\SitemapFilesTable::get_table_name(): string`, `::get_schema(): string`, `::create_table(): void`, `::maybe_upgrade(): void`, `::DB_VERSION = '1.0.0'` (option `taseo_sitemap_db_version`)
  - `IndexablesTable::DB_VERSION = '1.1.0'` and new column `sitemap_file_id BIGINT UNSIGNED NULL` + `KEY sitemap_file_id`
  - `IndexablesTable::get_installed_version(): string` — `'0'` when never installed (consumed by Task 9's upgrade-backfill flag)
  - `Installer::FLUSH_REWRITE_OPTION = 'taseo_needs_rewrite_flush'` (consumed by Task 9)

- [ ] **Step 1: Write the failing tests**

Create `tests/Database/SitemapFilesTableTest.php`:

```php
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
```

Modify `tests/Database/IndexablesTableTest.php` — in `test_get_schema_contains_all_spec_columns()`, add `'sitemap_file_id'` to the column list (after `'is_indexable'`), and add this assertion after the existing two key assertions:

```php
		$this->assertStringContainsString( 'KEY sitemap_file_id (sitemap_file_id)', $schema );
```

Then add two new test methods to the same class:

```php
	public function test_db_version_bumped_for_sitemap_column(): void {
		$this->assertSame( '1.1.0', IndexablesTable::DB_VERSION );
	}

	public function test_get_installed_version_defaults_to_zero(): void {
		Functions\expect( 'get_option' )->once()->with( 'taseo_db_version', '0' )->andReturn( '0' );

		$this->assertSame( '0', IndexablesTable::get_installed_version() );
	}
```

(`Functions` is already imported in that test file.)

Create `tests/InstallerTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Installer;

#[CoversClass( Installer::class )]
class InstallerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_activate_creates_both_tables_and_sets_flags(): void {
		$updated = array();

		Functions\expect( 'dbDelta' )->twice();
		Functions\expect( 'update_option' )
			->times( 4 )
			->andReturnUsing(
				function ( string $option ) use ( &$updated ): bool {
					$updated[] = $option;
					return true;
				}
			);

		Installer::activate();

		$this->assertContains( 'taseo_db_version', $updated );
		$this->assertContains( 'taseo_sitemap_db_version', $updated );
		$this->assertContains( 'taseo_needs_backfill', $updated );
		$this->assertContains( 'taseo_needs_rewrite_flush', $updated );
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter 'SitemapFilesTableTest|IndexablesTableTest|InstallerTest'`
Expected: FAIL — `SitemapFilesTable` class not found; `sitemap_file_id` missing from schema; `DB_VERSION` is `1.0.0`; `get_installed_version` undefined; Installer expects one `dbDelta` but test wants two.

- [ ] **Step 3: Create includes/Database/SitemapFilesTable.php**

```php
<?php
/**
 * Sitemap Files Table Schema
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Database;

/**
 * Class SitemapFilesTable
 *
 * Owns the wp_taseo_sitemap_files schema — one registry row per physical
 * sitemap chunk file. Membership is never stored here: indexable rows point
 * at a chunk via their sitemap_file_id column, and a chunk's contents are
 * always the reverse lookup "which rows point at me". link_count is a cached
 * counter maintained by atomic claims/releases, so "find a chunk with room"
 * never scans the indexable table.
 */
class SitemapFilesTable {

	/**
	 * Database schema version. Bump on any schema change.
	 *
	 * @var string
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Version option name.
	 *
	 * @var string
	 */
	private const DB_VERSION_OPTION = 'taseo_sitemap_db_version';

	/**
	 * Get the fully prefixed table name.
	 *
	 * @return string Table name.
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'taseo_sitemap_files';
	}

	/**
	 * Get the dbDelta-compatible CREATE TABLE statement.
	 *
	 * @return string SQL schema.
	 */
	public static function get_schema(): string {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_subtype VARCHAR(32) NOT NULL,
			chunk_number INT UNSIGNED NOT NULL,
			link_count INT UNSIGNED NOT NULL DEFAULT 0,
			is_dirty TINYINT(1) NOT NULL DEFAULT 0,
			last_modified DATETIME NULL,
			generated_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY subtype_chunk (object_subtype, chunk_number),
			KEY subtype_capacity (object_subtype, link_count),
			KEY is_dirty (is_dirty)
		) {$charset_collate};";
	}

	/**
	 * Create or update the table via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::get_schema() );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run the schema migration when the stored version is outdated.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( version_compare( get_option( self::DB_VERSION_OPTION, '0' ), self::DB_VERSION, '<' ) ) {
			self::create_table();
		}
	}
}
```

- [ ] **Step 4: Modify includes/Database/IndexablesTable.php**

Three changes:

1. Bump the version constant:

```php
	public const DB_VERSION = '1.1.0';
```

2. In `get_schema()`, add the pointer column after the `is_indexable` line and its key after the `is_indexable` key line, so the block reads:

```php
			schema_disabled TINYINT(1) NOT NULL DEFAULT 0,
			is_indexable TINYINT(1) NOT NULL DEFAULT 1,
			sitemap_file_id BIGINT UNSIGNED NULL,
			last_modified DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY object_lookup (object_type, object_subtype, object_id),
			KEY object_lookup_by_id (object_type, object_id),
			KEY is_indexable (is_indexable),
			KEY sitemap_file_id (sitemap_file_id)
```

3. Add a public accessor after `maybe_upgrade()` (Task 9 uses it to detect in-place upgrades that need a re-backfill):

```php
	/**
	 * Version currently recorded in the database, '0' when never installed.
	 *
	 * @return string Installed schema version.
	 */
	public static function get_installed_version(): string {
		return (string) get_option( self::DB_VERSION_OPTION, '0' );
	}
```

- [ ] **Step 5: Modify includes/Installer.php**

Replace the class body so activation also creates the registry table and flags the rewrite flush (rules are registered by `SitemapServer` on the next request; Task 9 consumes the flag):

```php
<?php
/**
 * Installer Class
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\Database\SitemapFilesTable;

/**
 * Class Installer
 *
 * Activation-time setup. The initial backfill is NOT run here — activation
 * must stay instant. A flag is set; Plugin::start() dispatches the Action
 * Scheduler chain on the next normal request. The rewrite flush is likewise
 * deferred: sitemap rewrite rules only exist once SitemapServer has
 * registered them on a normal request's init.
 */
class Installer {

	/**
	 * Option flag: the initial backfill chain still needs dispatching.
	 *
	 * @var string
	 */
	public const NEEDS_BACKFILL_OPTION = 'taseo_needs_backfill';

	/**
	 * Option flag: rewrite rules need flushing on the next request.
	 *
	 * @var string
	 */
	public const FLUSH_REWRITE_OPTION = 'taseo_needs_rewrite_flush';

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		IndexablesTable::create_table();
		SitemapFilesTable::create_table();

		update_option( self::NEEDS_BACKFILL_OPTION, '1' );
		update_option( self::FLUSH_REWRITE_OPTION, '1' );
	}
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `composer test`
Expected: ALL tests PASS (the three touched suites plus every existing suite — the schema change must not break `PluginTest`, which stubs `dbDelta`).

- [ ] **Step 7: Run phpcs and commit**

```bash
composer phpcs
git add includes/Database/SitemapFilesTable.php includes/Database/IndexablesTable.php includes/Installer.php tests/Database/SitemapFilesTableTest.php tests/Database/IndexablesTableTest.php tests/InstallerTest.php
git commit -m "feat: add sitemap files registry table and sitemap_file_id pointer column"
```

---

### Task 2: Indexable lifecycle actions — the module boundary

**Files:**
- Modify: `includes/Indexable/IndexableRepository.php`
- Test: `tests/Indexable/IndexableRepositoryTest.php` (modify)

**Interfaces:**
- Consumes: nothing new
- Produces (consumed by `SitemapAssignment`, Task 5):
  - Action `taseo_indexable_synced( string $object_type, string $object_subtype, int $object_id )` — fires after synced columns are written (covers save, trash→non-indexable, backfill/rescan, permalink rebuild, and the stub row `save_overrides()` creates)
  - Action `taseo_indexable_deleting( string $object_type, string $object_subtype, int $object_id )` — fires immediately BEFORE the row is deleted, while its `sitemap_file_id` pointer is still readable

This is the entire coupling surface between module 1 and module 2: the sitemap module never patches `IndexableSync` or `IndexableBackfill`, so backfill performs chunk assignment inline for free (spec: "Initial population").

- [ ] **Step 1: Write the failing tests**

In `tests/Indexable/IndexableRepositoryTest.php`, add `use Brain\Monkey\Actions;` to the imports, then add two test methods:

```php
	public function test_upsert_synced_fields_fires_synced_action(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once();

		Actions\expectDone( 'taseo_indexable_synced' )->once()->with( 'post', 'product', 88123 );

		$this->repository->upsert_synced_fields( 'post', 'product', 88123, array() );
	}

	public function test_delete_fires_deleting_action(): void {
		Actions\expectDone( 'taseo_indexable_deleting' )->once()->with( 'post', 'product', 88123 );

		$this->wpdb->shouldReceive( 'delete' )->once();

		$this->repository->delete( 'post', 'product', 88123 );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter IndexableRepositoryTest`
Expected: FAIL — the two new tests report the actions were never fired ("was not done"). All pre-existing tests still pass (Brain Monkey tolerates un-expected `do_action` calls, so adding the actions won't break them either).

- [ ] **Step 3: Fire the actions in includes/Indexable/IndexableRepository.php**

In `upsert_synced_fields()`, immediately after the `$wpdb->query( $sql );` / `// phpcs:enable` pair, add:

```php
		/**
		 * Fires after an indexable row's synced columns are written.
		 *
		 * The sitemap module reconciles chunk assignment on this: assign when
		 * newly indexable, mark the chunk dirty on edits, release the slot
		 * when the row stops being indexable.
		 *
		 * @since 1.0.0
		 *
		 * @param string $object_type    'post', 'term', or 'system_page'.
		 * @param string $object_subtype Post type / taxonomy / system page key.
		 * @param int    $object_id      Post or term ID; 0 for system pages.
		 */
		do_action( 'taseo_indexable_synced', $object_type, $object_subtype, $object_id );
```

In `delete()`, immediately before the `$wpdb->delete(` call, add:

```php
		/**
		 * Fires immediately before an indexable row is deleted.
		 *
		 * The sitemap module releases the object's chunk slot on this, while
		 * the row (and its sitemap_file_id pointer) is still readable.
		 *
		 * @since 1.0.0
		 *
		 * @param string $object_type    Object type.
		 * @param string $object_subtype Object subtype.
		 * @param int    $object_id      Object ID.
		 */
		do_action( 'taseo_indexable_deleting', $object_type, $object_subtype, $object_id );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: ALL tests PASS.

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Indexable/IndexableRepository.php tests/Indexable/IndexableRepositoryTest.php
git commit -m "feat: fire indexable lifecycle actions at the sitemap module boundary"
```

---

### Task 3: SitemapFileRepository — registry CRUD and atomic slot accounting

**Files:**
- Create: `includes/Sitemap/SitemapFileRepository.php`
- Test: `tests/Sitemap/SitemapFileRepositoryTest.php`

**Interfaces:**
- Consumes: `SitemapFilesTable::get_table_name()` (Task 1), global `$wpdb`
- Produces (consumed by Tasks 4–8):
  - `get( int $chunk_id ): ?array` — full registry row (string-keyed, string values as wpdb returns them) or null
  - `find_lowest_open_chunk( string $object_subtype, int $cap ): ?array` — lowest `chunk_number` with `link_count < $cap`
  - `claim_slot( int $chunk_id, int $cap ): bool` — atomic conditional increment; false = lost race, re-run the search
  - `create_chunk( string $object_subtype ): ?array` — inserts `MAX(chunk_number)+1` with `link_count = 1, is_dirty = 1`; null = lost the unique-key race, re-run the search
  - `release_slot( int $chunk_id ): int` — decrement + dirty, returns REMAINING link_count (0 means "delete me")
  - `delete_chunk( int $chunk_id ): void`
  - `mark_dirty( int $chunk_id ): void`, `mark_all_dirty(): void`
  - `get_dirty_chunks( int $limit ): array`, `count_dirty(): int`
  - `get_all_chunks(): array` — ordered by subtype, chunk_number (root index + status)
  - `update_after_rebuild( int $chunk_id, ?string $last_modified ): void` — clears dirty, stamps `generated_at`
  - `get_status_summary(): array{subtypes: array<string, array{chunks: int, links: int}>, dirty: int, last_generated: ?string}`

- [ ] **Step 1: Write the failing test**

Create `tests/Sitemap/SitemapFileRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;

#[CoversClass( SitemapFileRepository::class )]
class SitemapFileRepositoryTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private SitemapFileRepository $files;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->files = new SitemapFileRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_find_lowest_open_chunk_orders_by_chunk_number(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'FROM wp_taseo_sitemap_files' )
						&& str_contains( $sql, 'link_count < %d' )
						&& str_contains( $sql, 'ORDER BY chunk_number ASC' )
						&& str_contains( $sql, 'LIMIT 1' )
				),
				'product',
				1000
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'SQL', ARRAY_A )->andReturn( array( 'id' => '3' ) );

		$this->assertSame( array( 'id' => '3' ), $this->files->find_lowest_open_chunk( 'product', 1000 ) );
	}

	public function test_find_lowest_open_chunk_returns_null_when_all_full(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$this->assertNull( $this->files->find_lowest_open_chunk( 'product', 1000 ) );
	}

	public function test_claim_slot_is_a_single_conditional_update(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'link_count = link_count + 1' )
						&& str_contains( $sql, 'is_dirty = 1' )
						&& str_contains( $sql, 'AND link_count < %d' )
				),
				3,
				1000
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( 1 );

		$this->assertTrue( $this->files->claim_slot( 3, 1000 ) );
	}

	public function test_claim_slot_reports_lost_race(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		$this->assertFalse( $this->files->claim_slot( 3, 1000 ) );
	}

	public function test_create_chunk_appends_next_number_with_first_slot_claimed(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'COALESCE(MAX(chunk_number), 0) + 1' ) ),
				'product'
			)
			->andReturn( 'MAX_SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'MAX_SQL' )->andReturn( '8' );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'INSERT INTO wp_taseo_sitemap_files' )
						&& str_contains( $sql, 'VALUES (%s, %d, 1, 1)' )
				),
				'product',
				8
			)
			->andReturn( 'INSERT_SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'INSERT_SQL' )->andReturn( 1 );
		$this->wpdb->insert_id = 41;

		$this->wpdb->shouldReceive( 'prepare' )->once()->with( Mockery::type( 'string' ), 41 )->andReturn( 'GET_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )
			->once()
			->with( 'GET_SQL', ARRAY_A )
			->andReturn( array( 'id' => '41', 'chunk_number' => '8' ) );

		$this->assertSame( array( 'id' => '41', 'chunk_number' => '8' ), $this->files->create_chunk( 'product' ) );
	}

	public function test_create_chunk_returns_null_when_duplicate_key_race_lost(): void {
		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '8' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false ); // unique key violation.

		$this->assertNull( $this->files->create_chunk( 'product' ) );
	}

	public function test_release_slot_decrements_and_returns_remaining(): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'link_count = link_count - 1' )
						&& str_contains( $sql, 'is_dirty = 1' )
						&& str_contains( $sql, 'AND link_count > 0' )
				),
				7
			)
			->andReturn( 'DEC_SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'DEC_SQL' )->andReturn( 1 );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'SELECT link_count' ) ), 7 )
			->andReturn( 'COUNT_SQL' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'COUNT_SQL' )->andReturn( '0' );

		$this->assertSame( 0, $this->files->release_slot( 7 ) );
	}

	public function test_delete_chunk_removes_registry_row(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->with( 'wp_taseo_sitemap_files', array( 'id' => 7 ) );

		$this->files->delete_chunk( 7 );
	}

	public function test_mark_dirty_flags_one_chunk(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_sitemap_files', array( 'is_dirty' => 1 ), array( 'id' => 7 ) );

		$this->files->mark_dirty( 7 );
	}

	public function test_mark_all_dirty_flags_every_chunk(): void {
		$this->wpdb->shouldReceive( 'query' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'SET is_dirty = 1' ) && ! str_contains( $sql, 'WHERE' )
				)
			);

		$this->files->mark_all_dirty();
	}

	public function test_get_dirty_chunks_respects_limit(): void {
		$rows = array( array( 'id' => '1' ), array( 'id' => '2' ) );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'is_dirty = 1' ) && str_contains( $sql, 'LIMIT %d' ) ),
				20
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn( $rows );

		$this->assertSame( $rows, $this->files->get_dirty_chunks( 20 ) );
	}

	public function test_update_after_rebuild_clears_dirty_and_stamps_generation(): void {
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with(
				'wp_taseo_sitemap_files',
				Mockery::on(
					fn( array $data ): bool => 0 === $data['is_dirty']
						&& is_string( $data['generated_at'] )
						&& '2026-07-02 10:00:00' === $data['last_modified']
				),
				array( 'id' => 7 )
			);

		$this->files->update_after_rebuild( 7, '2026-07-02 10:00:00' );
	}

	public function test_get_status_summary_shapes_per_subtype_counts(): void {
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'GROUP BY object_subtype' ) ), ARRAY_A )
			->andReturn(
				array(
					array( 'object_subtype' => 'page', 'chunks' => '1', 'links' => '87' ),
					array( 'object_subtype' => 'product', 'chunks' => '4', 'links' => '3412' ),
				)
			);
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'is_dirty = 1' ) ) )
			->andReturn( '2' );
		$this->wpdb->shouldReceive( 'get_var' )
			->once()
			->with( Mockery::on( fn( string $sql ): bool => str_contains( $sql, 'MAX(generated_at)' ) ) )
			->andReturn( '2026-07-03 09:00:00' );

		$summary = $this->files->get_status_summary();

		$this->assertSame( array( 'chunks' => 1, 'links' => 87 ), $summary['subtypes']['page'] );
		$this->assertSame( array( 'chunks' => 4, 'links' => 3412 ), $summary['subtypes']['product'] );
		$this->assertSame( 2, $summary['dirty'] );
		$this->assertSame( '2026-07-03 09:00:00', $summary['last_generated'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SitemapFileRepositoryTest`
Expected: FAIL with "Class `TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository` not found".

- [ ] **Step 3: Create includes/Sitemap/SitemapFileRepository.php**

```php
<?php
/**
 * Sitemap File Repository
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\Database\SitemapFilesTable;

/**
 * Class SitemapFileRepository
 *
 * All reads/writes against wp_taseo_sitemap_files. Slot claims and releases
 * are single conditional UPDATEs so concurrent saves can never overshoot the
 * cap or underflow the counter — a claim that affects zero rows means
 * another process took the last slot and the caller re-runs its search.
 */
class SitemapFileRepository {

	/**
	 * Fetch one registry row.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	public function get( int $chunk_id ): ?array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $chunk_id ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lowest-numbered chunk for a subtype that still has room.
	 *
	 * @param string $object_subtype Post type or taxonomy slug.
	 * @param int    $cap            Configured links-per-file cap.
	 * @return array<string, mixed>|null Row or null when every chunk is full.
	 */
	public function find_lowest_open_chunk( string $object_subtype, int $cap ): ?array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_subtype = %s AND link_count < %d ORDER BY chunk_number ASC LIMIT 1",
				$object_subtype,
				$cap
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomically claim one slot in a chunk.
	 *
	 * The conditional WHERE makes the read-then-write race impossible: if a
	 * concurrent save took the last slot after our search, zero rows are
	 * affected and the caller re-runs the search instead of overshooting.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @param int $cap      Configured links-per-file cap.
	 * @return bool True when the slot was claimed.
	 */
	public function claim_slot( int $chunk_id, int $cap ): bool {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET link_count = link_count + 1, is_dirty = 1 WHERE id = %d AND link_count < %d",
				$chunk_id,
				$cap
			)
		);
		// phpcs:enable

		return (int) $affected > 0;
	}

	/**
	 * Create the next chunk for a subtype with its first slot pre-claimed.
	 *
	 * Two processes can race to create the same chunk_number; the unique key
	 * on (object_subtype, chunk_number) rejects the loser, who gets null and
	 * re-runs the search (the winner's chunk now has room).
	 *
	 * @param string $object_subtype Post type or taxonomy slug.
	 * @return array<string, mixed>|null New row, or null on a lost creation race.
	 */
	public function create_chunk( string $object_subtype ): ?array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(MAX(chunk_number), 0) + 1 FROM {$table} WHERE object_subtype = %s",
				$object_subtype
			)
		);

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (object_subtype, chunk_number, link_count, is_dirty) VALUES (%s, %d, 1, 1)",
				$object_subtype,
				$next
			)
		);
		// phpcs:enable

		if ( false === $inserted || 0 === (int) $inserted ) {
			return null;
		}

		return $this->get( (int) $wpdb->insert_id );
	}

	/**
	 * Give one slot back and report how many links remain.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return int Remaining link_count (0 means the chunk should be deleted).
	 */
	public function release_slot( int $chunk_id ): int {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET link_count = link_count - 1, is_dirty = 1 WHERE id = %d AND link_count > 0",
				$chunk_id
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT link_count FROM {$table} WHERE id = %d", $chunk_id )
		);
		// phpcs:enable
	}

	/**
	 * Delete a registry row (the physical file is the writer's problem).
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return void
	 */
	public function delete_chunk( int $chunk_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( SitemapFilesTable::get_table_name(), array( 'id' => $chunk_id ) );
	}

	/**
	 * Flag one chunk for rebuild.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return void
	 */
	public function mark_dirty( int $chunk_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( SitemapFilesTable::get_table_name(), array( 'is_dirty' => 1 ), array( 'id' => $chunk_id ) );
	}

	/**
	 * Flag every chunk for rebuild (permalink structure changed).
	 *
	 * @return void
	 */
	public function mark_all_dirty(): void {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "UPDATE {$table} SET is_dirty = 1" );
	}

	/**
	 * A bounded batch of chunks awaiting rebuild.
	 *
	 * @param int $limit Batch size.
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function get_dirty_chunks( int $limit ): array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE is_dirty = 1 ORDER BY id ASC LIMIT %d", $limit ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many chunks still await rebuild.
	 *
	 * @return int Dirty count.
	 */
	public function count_dirty(): int {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_dirty = 1" );
	}

	/**
	 * Every registry row — the whole table is small by design (~4,000 rows
	 * at 4M objects), so reading it in full is the intended access pattern.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function get_all_chunks(): array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY object_subtype ASC, chunk_number ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Clear the dirty flag after a successful file write.
	 *
	 * @param int         $chunk_id      Chunk row ID.
	 * @param string|null $last_modified MAX(last_modified) across members, or null when empty.
	 * @return void
	 */
	public function update_after_rebuild( int $chunk_id, ?string $last_modified ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SitemapFilesTable::get_table_name(),
			array(
				'is_dirty'      => 0,
				'generated_at'  => gmdate( 'Y-m-d H:i:s' ),
				'last_modified' => $last_modified,
			),
			array( 'id' => $chunk_id )
		);
	}

	/**
	 * Operational summary for the settings status panel.
	 *
	 * @return array{subtypes: array<string, array{chunks: int, links: int}>, dirty: int, last_generated: ?string} Summary.
	 */
	public function get_status_summary(): array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT object_subtype, COUNT(*) AS chunks, COALESCE(SUM(link_count), 0) AS links
			FROM {$table} GROUP BY object_subtype ORDER BY object_subtype ASC",
			ARRAY_A
		);

		$last_generated = $wpdb->get_var( "SELECT MAX(generated_at) FROM {$table}" );
		// phpcs:enable

		$subtypes = array();

		foreach ( ( is_array( $rows ) ? $rows : array() ) as $row ) {
			$subtypes[ (string) $row['object_subtype'] ] = array(
				'chunks' => (int) $row['chunks'],
				'links'  => (int) $row['links'],
			);
		}

		return array(
			'subtypes'       => $subtypes,
			'dirty'          => $this->count_dirty(),
			'last_generated' => is_string( $last_generated ) && '' !== $last_generated ? $last_generated : null,
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter SitemapFileRepositoryTest`
Expected: PASS (14 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Sitemap/SitemapFileRepository.php tests/Sitemap/SitemapFileRepositoryTest.php
git commit -m "feat: add sitemap file registry repository with atomic slot accounting"
```

---

### Task 4: SitemapFileWriter — render and write physical chunk files

**Files:**
- Create: `includes/Sitemap/SitemapFileWriter.php`
- Test: `tests/Sitemap/SitemapFileWriterTest.php`

**Interfaces:**
- Consumes: `SitemapFileRepository::update_after_rebuild()` (Task 3), `IndexablesTable::get_table_name()`, WP `wp_upload_dir()`, `wp_mkdir_p()`, `wp_is_writable()`, `wp_delete_file()`, `trailingslashit()`, `esc_url()`, `WP_Filesystem()` + global `$wp_filesystem`
- Produces (consumed by Tasks 5–8):
  - `DIRECTORY = 'taseo-sitemaps'` public const
  - `rebuild( array $chunk ): bool` — chunk = a registry row; queries members, writes the file, clears dirty; false on write failure (dirty flag stays set for retry)
  - `delete_file( array $chunk ): void`
  - `get_directory_path(): string`, `get_file_name( array $chunk ): string` (`{subtype}-sitemap-{n}.xml`), `get_file_path( array $chunk ): string`
  - `is_writable(): bool` — uploads dir usable
  - `static format_lastmod( ?string $datetime ): ?string` — stored GMT DATETIME → `2026-07-02T10:00:00+00:00`, null in/invalid → null (also used by `SitemapServer` for the root index)

- [ ] **Step 1: Write the failing test**

Create `tests/Sitemap/SitemapFileWriterTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;

#[CoversClass( SitemapFileWriter::class )]
class SitemapFileWriterTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private $files;
	private SitemapFileWriter $writer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/uploads',
				'baseurl' => 'https://example.com/wp-content/uploads',
				'error'   => false,
			)
		);
		Functions\when( 'trailingslashit' )->alias( fn( string $s ): string => rtrim( $s, '/' ) . '/' );
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
		Functions\when( 'esc_url' )->returnArg();

		$this->files  = Mockery::mock( SitemapFileRepository::class );
		$this->writer = new SitemapFileWriter( $this->files );
	}

	protected function tearDown(): void {
		global $wp_filesystem;
		$wp_filesystem = null;

		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_file_naming_follows_subtype_and_chunk_number(): void {
		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->assertSame( 'product-sitemap-3.xml', $this->writer->get_file_name( $chunk ) );
		$this->assertSame( '/tmp/uploads/taseo-sitemaps/product-sitemap-3.xml', $this->writer->get_file_path( $chunk ) );
	}

	public function test_format_lastmod_converts_gmt_datetime_to_w3c(): void {
		$this->assertSame( '2026-07-02T10:00:00+00:00', SitemapFileWriter::format_lastmod( '2026-07-02 10:00:00' ) );
		$this->assertNull( SitemapFileWriter::format_lastmod( null ) );
		$this->assertNull( SitemapFileWriter::format_lastmod( '' ) );
	}

	public function test_rebuild_writes_urlset_and_clears_dirty(): void {
		global $wp_filesystem;
		$wp_filesystem = Mockery::mock();

		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'FROM wp_taseo_indexables' )
						&& str_contains( $sql, 'sitemap_file_id = %d' )
						&& str_contains( $sql, 'is_indexable = 1' )
						&& str_contains( $sql, 'ORDER BY id ASC' )
				),
				7
			)
			->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'SQL', ARRAY_A )->andReturn(
			array(
				array( 'permalink' => 'https://example.com/product/widget/', 'last_modified' => '2026-07-02 10:00:00' ),
				array( 'permalink' => 'https://example.com/product/gadget/', 'last_modified' => '2026-06-30 08:00:00' ),
				array( 'permalink' => '', 'last_modified' => '2026-07-01 00:00:00' ), // no permalink — skipped.
			)
		);

		$captured = null;
		$wp_filesystem->shouldReceive( 'put_contents' )
			->once()
			->with( '/tmp/uploads/taseo-sitemaps/product-sitemap-3.xml', Mockery::capture( $captured ), Mockery::any() )
			->andReturn( true );

		$this->files->shouldReceive( 'update_after_rebuild' )->once()->with( 7, '2026-07-02 10:00:00' );

		$this->assertTrue( $this->writer->rebuild( $chunk ) );

		$this->assertStringContainsString( '<?xml version="1.0" encoding="UTF-8"?>', $captured );
		$this->assertStringContainsString( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $captured );
		$this->assertStringContainsString( '<loc>https://example.com/product/widget/</loc>', $captured );
		$this->assertStringContainsString( '<lastmod>2026-07-02T10:00:00+00:00</lastmod>', $captured );
		$this->assertSame( 2, substr_count( $captured, '<url>' ) );
	}

	public function test_rebuild_keeps_dirty_flag_when_write_fails(): void {
		global $wp_filesystem;
		$wp_filesystem = Mockery::mock();

		Functions\when( 'WP_Filesystem' )->justReturn( true );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' );

		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$wp_filesystem->shouldReceive( 'put_contents' )->once()->andReturn( false );

		$this->files->shouldNotReceive( 'update_after_rebuild' );

		$this->assertFalse( $this->writer->rebuild( $chunk ) );
	}

	public function test_delete_file_unlinks_existing_file(): void {
		$dir = sys_get_temp_dir() . '/taseo-writer-test-uploads';

		if ( ! is_dir( $dir . '/taseo-sitemaps' ) ) {
			mkdir( $dir . '/taseo-sitemaps', 0777, true );
		}

		touch( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );

		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $dir, 'error' => false ) );
		Functions\expect( 'wp_delete_file' )->once()->with( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );

		$this->writer->delete_file( array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '3' ) );

		unlink( $dir . '/taseo-sitemaps/product-sitemap-3.xml' );
	}

	public function test_delete_file_skips_missing_file(): void {
		Functions\expect( 'wp_delete_file' )->never();

		$this->writer->delete_file( array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '999' ) );
	}

	public function test_is_writable_requires_healthy_writable_uploads_dir(): void {
		Functions\when( 'wp_is_writable' )->justReturn( true );

		$this->assertTrue( $this->writer->is_writable() );
	}

	public function test_is_writable_fails_on_uploads_error(): void {
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => '/tmp/uploads', 'error' => 'nope' ) );

		$this->assertFalse( $this->writer->is_writable() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SitemapFileWriterTest`
Expected: FAIL with "Class `TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter` not found".

- [ ] **Step 3: Create includes/Sitemap/SitemapFileWriter.php**

```php
<?php
/**
 * Sitemap File Writer
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\Database\IndexablesTable;

/**
 * Class SitemapFileWriter
 *
 * Renders and writes one chunk's physical XML file. A file is always a pure
 * rendering of "indexable rows currently pointing at this chunk", so rebuilds
 * are idempotent — two processes racing on the same chunk produce a redundant
 * write, never corruption. The dirty flag is only cleared after a successful
 * write, so failures self-heal on the next sweep.
 */
class SitemapFileWriter {

	/**
	 * Directory under uploads/ holding all chunk files.
	 *
	 * @var string
	 */
	public const DIRECTORY = 'taseo-sitemaps';

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files Registry repository.
	 */
	public function __construct( private readonly SitemapFileRepository $files ) {
	}

	/**
	 * Absolute path of the sitemap directory.
	 *
	 * @return string Path without trailing slash.
	 */
	public function get_directory_path(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( (string) $uploads['basedir'] ) . self::DIRECTORY;
	}

	/**
	 * File name for a chunk: {subtype}-sitemap-{n}.xml.
	 *
	 * @param array<string, mixed> $chunk Registry row (object_subtype, chunk_number).
	 * @return string File name.
	 */
	public function get_file_name( array $chunk ): string {
		return sprintf( '%s-sitemap-%d.xml', (string) $chunk['object_subtype'], (int) $chunk['chunk_number'] );
	}

	/**
	 * Absolute path of a chunk's file.
	 *
	 * @param array<string, mixed> $chunk Registry row.
	 * @return string Path.
	 */
	public function get_file_path( array $chunk ): string {
		return trailingslashit( $this->get_directory_path() ) . $this->get_file_name( $chunk );
	}

	/**
	 * Whether sitemap files can be written at all.
	 *
	 * @return bool Uploads dir resolved and writable.
	 */
	public function is_writable(): bool {
		$uploads = wp_upload_dir();

		return empty( $uploads['error'] ) && wp_is_writable( (string) $uploads['basedir'] );
	}

	/**
	 * Convert a stored GMT DATETIME to the W3C datetime sitemaps use.
	 *
	 * @param string|null $datetime 'Y-m-d H:i:s' in GMT, or null.
	 * @return string|null W3C datetime or null when absent/invalid.
	 */
	public static function format_lastmod( ?string $datetime ): ?string {
		if ( null === $datetime || '' === $datetime ) {
			return null;
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s+00:00', $timestamp );
	}

	/**
	 * Rebuild one chunk's physical file from current DB state.
	 *
	 * Bounded to at most the chunk cap (<=1000) member rows regardless of
	 * catalog size — that's the whole point of stored assignment.
	 *
	 * @param array<string, mixed> $chunk Registry row.
	 * @return bool True when the file was written and the dirty flag cleared.
	 */
	public function rebuild( array $chunk ): bool {
		global $wpdb;

		$indexables = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT permalink, last_modified FROM {$indexables} WHERE sitemap_file_id = %d AND is_indexable = 1 ORDER BY id ASC",
				(int) $chunk['id']
			),
			ARRAY_A
		);
		// phpcs:enable

		$rows = is_array( $rows ) ? $rows : array();

		if ( ! wp_mkdir_p( $this->get_directory_path() ) ) {
			return false;
		}

		if ( ! $this->write_file( $this->get_file_path( $chunk ), $this->render_urlset( $rows ) ) ) {
			return false;
		}

		$max_modified = null;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['last_modified'] ) && ( null === $max_modified || $row['last_modified'] > $max_modified ) ) {
				$max_modified = (string) $row['last_modified'];
			}
		}

		$this->files->update_after_rebuild( (int) $chunk['id'], $max_modified );

		return true;
	}

	/**
	 * Remove a chunk's physical file (chunk emptied and deleted).
	 *
	 * @param array<string, mixed> $chunk Registry row.
	 * @return void
	 */
	public function delete_file( array $chunk ): void {
		$path = $this->get_file_path( $chunk );

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Render the <urlset> document: <loc> + <lastmod> only, per spec.
	 *
	 * @param array<int, array<string, mixed>> $rows Member rows (permalink, last_modified).
	 * @return string XML document.
	 */
	private function render_urlset( array $rows ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $rows as $row ) {
			$loc = (string) ( $row['permalink'] ?? '' );

			if ( '' === $loc ) {
				continue;
			}

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $loc ) . "</loc>\n";

			$lastmod = self::format_lastmod( isset( $row['last_modified'] ) ? (string) $row['last_modified'] : null );

			if ( null !== $lastmod ) {
				$xml .= "\t\t<lastmod>" . $lastmod . "</lastmod>\n";
			}

			$xml .= "\t</url>\n";
		}

		return $xml . '</urlset>' . "\n";
	}

	/**
	 * Write via the WP Filesystem API.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $contents File contents.
	 * @return bool Success.
	 */
	private function write_file( string $path, string $contents ): bool {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents( $path, $contents, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter SitemapFileWriterTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Sitemap/SitemapFileWriter.php tests/Sitemap/SitemapFileWriterTest.php
git commit -m "feat: add sitemap file writer with idempotent urlset rendering"
```

---

### Task 5: Settings getters + SitemapAssignment — the assignment algorithm

**Files:**
- Modify: `includes/Settings/Settings.php`
- Create: `includes/Sitemap/SitemapAssignment.php`
- Test: `tests/Settings/SettingsTest.php` (modify), `tests/Sitemap/SitemapAssignmentTest.php` (new)

**Interfaces:**
- Consumes: `SitemapFileRepository` (Task 3), `SitemapFileWriter::delete_file()` (Task 4), the Task 2 actions, `IndexablesTable::get_table_name()`, `HookManager`
- Produces:
  - `Settings::is_sitemap_enabled(): bool` (default true), `Settings::get_sitemap_max_links(): int` (default 1000, clamped 1–1000) — also consumed by Tasks 6–8
  - `SitemapAssignment::init( HookManager $hook_manager ): void` — listens on `taseo_indexable_synced` / `taseo_indexable_deleting`
  - `SitemapAssignment::handle_indexable_synced( string $object_type, string $object_subtype, int $object_id ): void`
  - `SitemapAssignment::handle_indexable_deleting( string $object_type, string $object_subtype, int $object_id ): void`

Reconciliation rules (spec: "Assignment algorithm"):
- `system_page` rows never participate; only `post`/`term` do.
- Indexable + no pointer → claim lowest open chunk (atomic), else create a new one; bounded retry loop on lost races.
- Indexable + pointer → mark that chunk dirty, nothing else (this is how edits reach `<lastmod>`).
- Non-indexable (or deleting) + pointer → clear pointer, decrement; at zero links delete the registry row AND unlink the physical file immediately.
- The release path is NOT gated on `is_sitemap_enabled()` — counters must stay true even while output is switched off; only new assignment is gated.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Settings/SettingsTest.php`:

```php
	public function test_sitemap_enabled_defaults_on(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertTrue( ( new Settings() )->is_sitemap_enabled() );
	}

	public function test_sitemap_max_links_defaults_to_protocol_cap(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 1000, ( new Settings() )->get_sitemap_max_links() );
	}

	public function test_sitemap_max_links_is_clamped_to_1_1000(): void {
		Functions\when( 'get_option' )->justReturn( array( 'sitemap_max_links' => 5000 ) );
		$this->assertSame( 1000, ( new Settings() )->get_sitemap_max_links() );

		Functions\when( 'get_option' )->justReturn( array( 'sitemap_max_links' => 0 ) );
		$this->assertSame( 1, ( new Settings() )->get_sitemap_max_links() );

		Functions\when( 'get_option' )->justReturn( array( 'sitemap_max_links' => 500 ) );
		$this->assertSame( 500, ( new Settings() )->get_sitemap_max_links() );
	}
```

Create `tests/Sitemap/SitemapAssignmentTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapAssignment;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;

#[CoversClass( SitemapAssignment::class )]
class SitemapAssignmentTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $wpdb;
	private $files;
	private $writer;
	private $settings;
	private SitemapAssignment $assignment;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		$this->files    = Mockery::mock( SitemapFileRepository::class );
		$this->writer   = Mockery::mock( SitemapFileWriter::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->assignment = new SitemapAssignment( $this->files, $this->writer, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub the indexable-row lookup handle_* methods start with.
	 */
	private function stub_indexable_row( ?array $row ): void {
		$this->wpdb->shouldReceive( 'prepare' )
			->once()
			->with(
				Mockery::on(
					fn( string $sql ): bool => str_contains( $sql, 'SELECT id, is_indexable, sitemap_file_id' )
						&& str_contains( $sql, 'FROM wp_taseo_indexables' )
				),
				'post',
				'product',
				88123
			)
			->andReturn( 'FIND_SQL' );
		$this->wpdb->shouldReceive( 'get_row' )->once()->with( 'FIND_SQL', ARRAY_A )->andReturn( $row );
	}

	public function test_new_indexable_claims_lowest_open_chunk(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )->once()->with( 'product', 1000 )->andReturn( array( 'id' => '3' ) );
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 3, 1000 )->andReturn( true );
		$this->files->shouldNotReceive( 'create_chunk' );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 3 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_opens_new_chunk_when_every_existing_chunk_is_full(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )->once()->andReturn( null );
		$this->files->shouldReceive( 'create_chunk' )->once()->with( 'product' )->andReturn( array( 'id' => '8' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 8 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_lost_slot_race_reruns_the_search(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )
			->twice()
			->andReturn( array( 'id' => '3' ), array( 'id' => '4' ) );
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 3, 1000 )->andReturn( false ); // lost the last slot.
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 4, 1000 )->andReturn( true );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 4 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_lost_create_race_reruns_the_search(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )
			->twice()
			->andReturn( null, array( 'id' => '5' ) ); // second pass sees the race winner's chunk.
		$this->files->shouldReceive( 'create_chunk' )->once()->andReturn( null ); // unique-key race lost.
		$this->files->shouldReceive( 'claim_slot' )->once()->with( 5, 1000 )->andReturn( true );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => 5 ), array( 'id' => 9 ) );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_retry_exhaustion_gives_up_without_assignment(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->settings->shouldReceive( 'get_sitemap_max_links' )->andReturn( 1000 );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldReceive( 'find_lowest_open_chunk' )->times( 5 )->andReturn( array( 'id' => '3' ) );
		$this->files->shouldReceive( 'claim_slot' )->times( 5 )->andReturn( false );

		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_edit_to_assigned_object_only_marks_chunk_dirty(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => '7' ) );

		$this->files->shouldReceive( 'mark_dirty' )->once()->with( 7 );
		$this->files->shouldNotReceive( 'find_lowest_open_chunk' );
		$this->files->shouldNotReceive( 'claim_slot' );

		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_becoming_non_indexable_releases_slot(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => null ), array( 'id' => 9 ) );
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 412 );
		$this->files->shouldNotReceive( 'delete_chunk' );
		$this->writer->shouldNotReceive( 'delete_file' );

		// Releases are never gated on the enabled toggle — counters must stay true.
		$this->settings->shouldNotReceive( 'is_sitemap_enabled' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_chunk_deleted_and_file_unlinked_at_zero_links(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => '7' ) );

		$chunk = array( 'id' => '7', 'object_subtype' => 'product', 'chunk_number' => '7' );

		$this->wpdb->shouldReceive( 'update' )->once();
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 0 );
		$this->files->shouldReceive( 'get' )->once()->with( 7 )->andReturn( $chunk );
		$this->files->shouldReceive( 'delete_chunk' )->once()->with( 7 );
		$this->writer->shouldReceive( 'delete_file' )->once()->with( $chunk );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_non_indexable_without_assignment_is_a_noop(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '0', 'sitemap_file_id' => null ) );

		$this->files->shouldNotReceive( 'release_slot' );
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_system_pages_never_participate(): void {
		$this->wpdb->shouldNotReceive( 'get_row' );

		$this->assignment->handle_indexable_synced( 'system_page', 'home', 0 );
		$this->assignment->handle_indexable_deleting( 'system_page', 'search', 0 );
	}

	public function test_disabled_sitemap_skips_new_assignment(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => null ) );

		$this->files->shouldNotReceive( 'find_lowest_open_chunk' );
		$this->wpdb->shouldNotReceive( 'update' );

		$this->assignment->handle_indexable_synced( 'post', 'product', 88123 );
	}

	public function test_deleting_handler_releases_while_pointer_still_readable(): void {
		$this->stub_indexable_row( array( 'id' => '9', 'is_indexable' => '1', 'sitemap_file_id' => '7' ) );

		$this->wpdb->shouldReceive( 'update' )
			->once()
			->with( 'wp_taseo_indexables', array( 'sitemap_file_id' => null ), array( 'id' => 9 ) );
		$this->files->shouldReceive( 'release_slot' )->once()->with( 7 )->andReturn( 412 );

		$this->assignment->handle_indexable_deleting( 'post', 'product', 88123 );
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter 'SettingsTest|SitemapAssignmentTest'`
Expected: FAIL — `is_sitemap_enabled`/`get_sitemap_max_links` undefined; `SitemapAssignment` class not found.

- [ ] **Step 3: Add the getters to includes/Settings/Settings.php**

Append after `breadcrumb_include_taxonomy_ancestors()`:

```php
	/**
	 * Whether the XML sitemap feature is enabled.
	 *
	 * @return bool Enabled.
	 */
	public function is_sitemap_enabled(): bool {
		return (bool) $this->get( 'sitemap_enabled', true );
	}

	/**
	 * Links per sitemap chunk file.
	 *
	 * 1000 is both the default and the hard ceiling (sitemaps.org allows
	 * 50k, but 1000 is this plugin's performance envelope per spec).
	 *
	 * @return int Cap, clamped to 1–1000.
	 */
	public function get_sitemap_max_links(): int {
		$stored = (int) $this->get( 'sitemap_max_links', 1000 );

		return max( 1, min( 1000, $stored ) );
	}
```

- [ ] **Step 4: Create includes/Sitemap/SitemapAssignment.php**

```php
<?php
/**
 * Sitemap Assignment
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SitemapAssignment
 *
 * Keeps chunk membership true to the indexable table. Membership is stored
 * (the indexable row's sitemap_file_id pointer), never computed from
 * position, so an add/remove only ever touches the one chunk involved —
 * no cascade. Objects are never moved between chunks after assignment.
 *
 * Listens on the module-boundary actions IndexableRepository fires, which
 * means the initial backfill assigns chunks inline with zero extra code:
 * every row it syncs lands here.
 */
class SitemapAssignment {

	/**
	 * Bounded retries for the claim/create race loop. In practice one retry
	 * suffices (a lost race means someone else just made room-accounting
	 * progress); the bound only guards against pathological contention.
	 *
	 * @var int
	 */
	private const CLAIM_RETRIES = 5;

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files    Registry repository.
	 * @param SitemapFileWriter     $writer   File writer (for immediate unlink at zero links).
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private readonly SitemapFileRepository $files,
		private readonly SitemapFileWriter $writer,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'taseo_indexable_synced', array( $this, 'handle_indexable_synced' ), 10, 3 );
		$hook_manager->register_action( 'taseo_indexable_deleting', array( $this, 'handle_indexable_deleting' ), 10, 3 );
	}

	/**
	 * Reconcile chunk membership after a sync write.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return void
	 */
	public function handle_indexable_synced( string $object_type, string $object_subtype, int $object_id ): void {
		if ( ! in_array( $object_type, array( 'post', 'term' ), true ) ) {
			return;
		}

		$row = $this->find_indexable( $object_type, $object_subtype, $object_id );

		if ( null === $row ) {
			return;
		}

		$chunk_id = null !== $row['sitemap_file_id'] ? (int) $row['sitemap_file_id'] : null;

		if ( ! (bool) (int) $row['is_indexable'] ) {
			// Releases are never gated on the enabled toggle: counters must
			// stay true even while sitemap output is switched off.
			if ( null !== $chunk_id ) {
				$this->release( (int) $row['id'], $chunk_id );
			}

			return;
		}

		if ( ! $this->settings->is_sitemap_enabled() ) {
			return;
		}

		if ( null === $chunk_id ) {
			$this->assign( (int) $row['id'], $object_subtype );

			return;
		}

		// Already assigned and staying indexable: an edit. Flag the chunk so
		// the next sweep re-renders it with fresh <loc>/<lastmod> values.
		$this->files->mark_dirty( $chunk_id );
	}

	/**
	 * Release the slot before the indexable row (and its pointer) disappears.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return void
	 */
	public function handle_indexable_deleting( string $object_type, string $object_subtype, int $object_id ): void {
		if ( ! in_array( $object_type, array( 'post', 'term' ), true ) ) {
			return;
		}

		$row = $this->find_indexable( $object_type, $object_subtype, $object_id );

		if ( null === $row || null === $row['sitemap_file_id'] ) {
			return;
		}

		$this->release( (int) $row['id'], (int) $row['sitemap_file_id'] );
	}

	/**
	 * Claim a slot: lowest open chunk first, new chunk as fallback.
	 *
	 * Both the claim and the create can lose a concurrency race (conditional
	 * UPDATE affecting zero rows / unique-key violation); either way the
	 * loop re-runs the search, which now sees the winner's state.
	 *
	 * @param int    $indexable_id   Indexable row ID.
	 * @param string $object_subtype Object subtype.
	 * @return void
	 */
	private function assign( int $indexable_id, string $object_subtype ): void {
		$cap = $this->settings->get_sitemap_max_links();

		for ( $attempt = 0; $attempt < self::CLAIM_RETRIES; $attempt++ ) {
			$chunk = $this->files->find_lowest_open_chunk( $object_subtype, $cap );

			if ( null === $chunk ) {
				$chunk = $this->files->create_chunk( $object_subtype );

				if ( null === $chunk ) {
					continue; // Lost the creation race; search again.
				}

				$this->set_pointer( $indexable_id, (int) $chunk['id'] );

				return;
			}

			if ( $this->files->claim_slot( (int) $chunk['id'], $cap ) ) {
				$this->set_pointer( $indexable_id, (int) $chunk['id'] );

				return;
			}
		}
	}

	/**
	 * Give a slot back; delete the chunk (row + physical file) at zero links.
	 *
	 * @param int $indexable_id Indexable row ID.
	 * @param int $chunk_id     Chunk row ID.
	 * @return void
	 */
	private function release( int $indexable_id, int $chunk_id ): void {
		$this->set_pointer( $indexable_id, null );

		if ( 0 !== $this->files->release_slot( $chunk_id ) ) {
			return;
		}

		$chunk = $this->files->get( $chunk_id );

		if ( null === $chunk ) {
			return;
		}

		// Deletion is a cheap unlink — no need to wait for the sweep.
		$this->files->delete_chunk( $chunk_id );
		$this->writer->delete_file( $chunk );
	}

	/**
	 * Write (or clear) the stored pointer on the indexable row.
	 *
	 * @param int      $indexable_id Indexable row ID.
	 * @param int|null $chunk_id     Chunk row ID or null to clear.
	 * @return void
	 */
	private function set_pointer( int $indexable_id, ?int $chunk_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			IndexablesTable::get_table_name(),
			array( 'sitemap_file_id' => $chunk_id ),
			array( 'id' => $indexable_id )
		);
	}

	/**
	 * Read the columns reconciliation needs from the indexable row.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	private function find_indexable( string $object_type, string $object_subtype, int $object_id ): ?array {
		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, is_indexable, sitemap_file_id FROM {$table} WHERE object_type = %s AND object_subtype = %s AND object_id = %d",
				$object_type,
				$object_subtype,
				$object_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `composer test`
Expected: ALL tests PASS.

- [ ] **Step 6: Run phpcs and commit**

```bash
composer phpcs
git add includes/Settings/Settings.php includes/Sitemap/SitemapAssignment.php tests/Settings/SettingsTest.php tests/Sitemap/SitemapAssignmentTest.php
git commit -m "feat: add race-safe sitemap chunk assignment on indexable lifecycle events"
```

---

### Task 6: SitemapSweeper — recurring dirty-chunk rebuild job

**Files:**
- Create: `includes/Sitemap/SitemapSweeper.php`
- Test: `tests/Sitemap/SitemapSweeperTest.php`

**Interfaces:**
- Consumes: `SitemapFileRepository` (Task 3), `SitemapFileWriter` (Task 4), `Settings` (Task 5), `HookManager`, Action Scheduler (`as_schedule_recurring_action()`, `as_has_scheduled_action()`, `as_enqueue_async_action()`), module 1's `taseo_permalinks_rebuilt` action
- Produces (consumed by Tasks 8–9):
  - `HOOK = 'taseo_sitemap_sweep'`, `GROUP = 'taseo'`, `BATCH_SIZE = 20`, `INTERVAL = 300` public consts
  - `init( HookManager $hook_manager ): void` — AS callback + recurring bootstrap on `init` (priority 20) + `taseo_permalinks_rebuilt` listener
  - `handle_sweep(): void` — one bounded batch; self-chains an async follow-up when a backlog remains
  - `dispatch_full_regeneration(): void` — mark ALL chunks dirty + immediate async sweep (permalink rebuilds, admin "Regenerate now")

- [ ] **Step 1: Write the failing test**

Create `tests/Sitemap/SitemapSweeperTest.php`:

```php
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
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\expect( 'as_has_scheduled_action' )->once()->andReturn( true );
		Functions\expect( 'as_schedule_recurring_action' )->never();

		$this->sweeper->ensure_recurring();
	}

	public function test_ensure_recurring_skips_when_disabled(): void {
		// Define the AS function so the function_exists guard passes.
		Functions\when( 'as_schedule_recurring_action' )->justReturn( null );
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		Functions\expect( 'as_has_scheduled_action' )->never();

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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SitemapSweeperTest`
Expected: FAIL with "Class `TheAnother\Plugin\SEO\Sitemap\SitemapSweeper` not found".

- [ ] **Step 3: Create includes/Sitemap/SitemapSweeper.php**

```php
<?php
/**
 * Sitemap Sweeper
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SitemapSweeper
 *
 * The asynchronous half of dirty-flag regeneration: a recurring Action
 * Scheduler action rebuilds a bounded batch of dirty chunks per run, and
 * self-chains an immediate follow-up while a backlog remains (e.g. after a
 * permalink rebuild marks every chunk dirty) — the backlog drains as a chain
 * of short jobs instead of waiting one recurring tick per batch.
 *
 * Concurrent sweeps racing on the same chunk are harmless: rebuild always
 * renders current DB state, so a race is a redundant write, not corruption.
 */
class SitemapSweeper {

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	public const HOOK = 'taseo_sitemap_sweep';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	public const GROUP = 'taseo';

	/**
	 * Dirty chunks rebuilt per run — bounds execution time per job
	 * regardless of how much churn happened.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 20;

	/**
	 * Recurring interval in seconds.
	 *
	 * @var int
	 */
	public const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files    Registry repository.
	 * @param SitemapFileWriter     $writer   File writer.
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private readonly SitemapFileRepository $files,
		private readonly SitemapFileWriter $writer,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( self::HOOK, array( $this, 'handle_sweep' ) );
		$hook_manager->register_action( 'init', array( $this, 'ensure_recurring' ), 20 );

		// The one legitimate "everything regenerates" event (spec: "Error
		// handling"): module 1 fires this after re-caching every permalink.
		$hook_manager->register_action( 'taseo_permalinks_rebuilt', array( $this, 'dispatch_full_regeneration' ) );
	}

	/**
	 * Keep the recurring sweep scheduled.
	 *
	 * @return void
	 */
	public function ensure_recurring(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! $this->settings->is_sitemap_enabled() ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, array(), self::GROUP );
	}

	/**
	 * Action Scheduler entry point: rebuild one bounded batch.
	 *
	 * @return void
	 */
	public function handle_sweep(): void {
		if ( ! $this->settings->is_sitemap_enabled() ) {
			return;
		}

		if ( ! $this->writer->is_writable() ) {
			// Environment problem (surfaced as an admin notice by the
			// settings page) — bail without fataling or partially writing.
			return;
		}

		$dirty   = $this->files->get_dirty_chunks( self::BATCH_SIZE );
		$rebuilt = 0;

		foreach ( $dirty as $chunk ) {
			if ( $this->writer->rebuild( $chunk ) ) {
				++$rebuilt;
			}
		}

		// Chain immediately only on a full, fully-successful batch with
		// backlog left. Failed rebuilds keep their dirty flag and wait for
		// the recurring tick — chaining on failure would spin a hot loop
		// against a broken filesystem.
		if ( count( $dirty ) === self::BATCH_SIZE && $rebuilt === self::BATCH_SIZE && $this->files->count_dirty() > 0 ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Mark every chunk dirty and start draining right away.
	 *
	 * Used by the taseo_permalinks_rebuilt listener and the admin
	 * "Regenerate now" action.
	 *
	 * @return void
	 */
	public function dispatch_full_regeneration(): void {
		$this->files->mark_all_dirty();

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array(), self::GROUP );
		}
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test`
Expected: ALL tests PASS.

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Sitemap/SitemapSweeper.php tests/Sitemap/SitemapSweeperTest.php
git commit -m "feat: add self-chaining sitemap sweep with bounded dirty-chunk batches"
```

---

### Task 7: SitemapServer — root index, chunk serving, robots.txt, Apache rules

**Files:**
- Create: `includes/Sitemap/SitemapServer.php`
- Test: `tests/Sitemap/SitemapServerTest.php`

**Interfaces:**
- Consumes: `SitemapFileRepository::get_all_chunks()` (Task 3), `SitemapFileWriter::get_file_name()/get_file_path()/format_lastmod()/DIRECTORY` (Task 4), `Settings::is_sitemap_enabled()` (Task 5), `HookManager`, WP rewrite API + `get_query_var()`, `home_url()`, `status_header()`, `wp_upload_dir()`, `wp_parse_url()`
- Produces (consumed by Task 9):
  - `QUERY_VAR = 'taseo_sitemap'` public const
  - `init( HookManager $hook_manager ): void`
  - `register_rewrites(): void` — `^sitemap\.xml$` and `^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$`, both `'top'`
  - `register_query_vars( array $vars ): array`
  - `maybe_serve( bool $do_exit = true ): void` — registered on `template_redirect` with **0 accepted args** (WP passes a legacy `''` arg to 1-arg callbacks, which would silently falsify `$do_exit`)
  - `append_sitemap_line( string $output, $is_public ): string` — `robots_txt` filter
  - `prepend_apache_static_rules( string $rules ): string` — `mod_rewrite_rules` filter; static-serve rules with an `-f` existence cond so missing files fall through to the WP fallback

Serving model (spec: "Root index"): the root index is rendered live per request (cheap registry query); chunk URLs are root-level (`/product-sitemap-3.xml`) for sitemaps.org directory-scope compliance. On Apache the `.htaccess` block serves the physical file with WordPress never loading; everywhere else the WP rewrite fallback streams the pre-built file via `readfile()` — still never generating on the fly.

- [ ] **Step 1: Write the failing test**

Create `tests/Sitemap/SitemapServerTest.php`:

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Sitemap;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapServer;

#[CoversClass( SitemapServer::class )]
class SitemapServerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $files;
	private $writer;
	private $settings;
	private SitemapServer $server;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->files    = Mockery::mock( SitemapFileRepository::class );
		$this->writer   = Mockery::mock( SitemapFileWriter::class );
		$this->settings = Mockery::mock( Settings::class );

		Functions\when( 'home_url' )->alias( fn( string $path = '' ): string => 'https://example.com' . $path );
		Functions\when( 'esc_url' )->returnArg();

		$this->server = new SitemapServer( $this->files, $this->writer, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_root_index_lists_only_populated_chunks_at_root_level_urls(): void {
		$this->writer->shouldReceive( 'get_file_name' )->andReturnUsing(
			fn( array $chunk ): string => $chunk['object_subtype'] . '-sitemap-' . $chunk['chunk_number'] . '.xml'
		);

		$this->files->shouldReceive( 'get_all_chunks' )->once()->andReturn(
			array(
				array( 'object_subtype' => 'page', 'chunk_number' => '1', 'link_count' => '87', 'last_modified' => null ),
				array( 'object_subtype' => 'product', 'chunk_number' => '1', 'link_count' => '1000', 'last_modified' => '2026-07-02 10:00:00' ),
				array( 'object_subtype' => 'product', 'chunk_number' => '2', 'link_count' => '0', 'last_modified' => null ),
			)
		);

		$xml = $this->server->render_root_index();

		$this->assertStringContainsString( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml );
		$this->assertStringContainsString( '<loc>https://example.com/page-sitemap-1.xml</loc>', $xml );
		$this->assertStringContainsString( '<loc>https://example.com/product-sitemap-1.xml</loc>', $xml );
		$this->assertStringContainsString( '<lastmod>2026-07-02T10:00:00+00:00</lastmod>', $xml );
		// Empty chunks and uploads URLs never appear.
		$this->assertStringNotContainsString( 'product-sitemap-2.xml', $xml );
		$this->assertStringNotContainsString( 'wp-content/uploads', $xml );
	}

	public function test_maybe_serve_outputs_live_root_index(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->files->shouldReceive( 'get_all_chunks' )->once()->andReturn( array() );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => 'taseo_sitemap' === $var ? 'index' : ''
		);
		Functions\expect( 'status_header' )->once()->with( 200 );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<sitemapindex', $output );
	}

	public function test_maybe_serve_ignores_normal_requests(): void {
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\expect( 'status_header' )->never();

		$this->server->maybe_serve( false );
	}

	public function test_maybe_serve_ignores_requests_when_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => 'taseo_sitemap' === $var ? 'index' : ''
		);
		Functions\expect( 'status_header' )->never();

		$this->server->maybe_serve( false );
	}

	public function test_maybe_serve_streams_existing_chunk_file(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		$path = tempnam( sys_get_temp_dir(), 'taseo' );
		file_put_contents( $path, '<urlset>static</urlset>' );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '3',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		Functions\expect( 'status_header' )->once()->with( 200 );

		$this->writer->shouldReceive( 'get_file_path' )
			->once()
			->with( array( 'object_subtype' => 'product', 'chunk_number' => 3 ) )
			->andReturn( $path );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '<urlset>static</urlset>', $output );

		unlink( $path );
	}

	public function test_maybe_serve_404s_missing_chunk_file(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'get_query_var' )->alias(
			fn( string $var ): string => match ( $var ) {
				'taseo_sitemap'         => 'chunk',
				'taseo_sitemap_subtype' => 'product',
				'taseo_sitemap_chunk'   => '999',
				default                 => '',
			}
		);
		Functions\when( 'sanitize_key' )->alias( fn( string $v ): string => strtolower( $v ) );
		Functions\expect( 'status_header' )->once()->with( 404 );

		$this->writer->shouldReceive( 'get_file_path' )->once()->andReturn( '/nonexistent/product-sitemap-999.xml' );

		ob_start();
		$this->server->maybe_serve( false );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_register_rewrites_adds_both_rules_at_top(): void {
		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with( '^sitemap\.xml$', 'index.php?taseo_sitemap=index', 'top' );
		Functions\expect( 'add_rewrite_rule' )
			->once()
			->with(
				'^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$',
				'index.php?taseo_sitemap=chunk&taseo_sitemap_subtype=$matches[1]&taseo_sitemap_chunk=$matches[2]',
				'top'
			);

		$this->server->register_rewrites();
	}

	public function test_register_query_vars_appends_all_three(): void {
		$vars = $this->server->register_query_vars( array( 'p' ) );

		$this->assertContains( 'taseo_sitemap', $vars );
		$this->assertContains( 'taseo_sitemap_subtype', $vars );
		$this->assertContains( 'taseo_sitemap_chunk', $vars );
	}

	public function test_robots_txt_gets_sitemap_line(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		$output = $this->server->append_sitemap_line( "User-agent: *\nDisallow:\n", '1' );

		$this->assertStringContainsString( 'Sitemap: https://example.com/sitemap.xml', $output );
	}

	public function test_robots_txt_untouched_for_private_sites_or_disabled_sitemap(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		$this->assertSame( 'X', $this->server->append_sitemap_line( 'X', '1' ) );
		$this->assertSame( 'X', $this->server->append_sitemap_line( 'X', '0' ) );
	}

	public function test_apache_rules_prepend_static_serving_with_existence_guard(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );

		Functions\when( 'wp_upload_dir' )->justReturn(
			array( 'basedir' => '/var/www/wp-content/uploads', 'baseurl' => 'https://example.com/wp-content/uploads', 'error' => false )
		);
		Functions\when( 'wp_parse_url' )->alias( fn( string $url, int $component ) => parse_url( $url, $component ) );

		$rules = $this->server->prepend_apache_static_rules( "# WP rules\n" );

		$this->assertStringContainsString( 'RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/taseo-sitemaps/$1-sitemap-$2.xml -f', $rules );
		$this->assertStringContainsString( 'RewriteRule ^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ /wp-content/uploads/taseo-sitemaps/$1-sitemap-$2.xml [L]', $rules );
		// Our block comes BEFORE WP's catch-all.
		$this->assertLessThan( strpos( $rules, '# WP rules' ), strpos( $rules, 'RewriteRule ^([a-z0-9_-]+)-sitemap-' ) );
	}

	public function test_apache_rules_untouched_when_disabled(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( false );

		$this->assertSame( "# WP rules\n", $this->server->prepend_apache_static_rules( "# WP rules\n" ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SitemapServerTest`
Expected: FAIL with "Class `TheAnother\Plugin\SEO\Sitemap\SitemapServer` not found".

- [ ] **Step 3: Create includes/Sitemap/SitemapServer.php**

```php
<?php
/**
 * Sitemap Server
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SitemapServer
 *
 * Serves /sitemap.xml (root index, generated live — a query over a few
 * thousand small registry rows) and the root-level chunk URLs. Chunk URLs
 * are root-level because the sitemaps.org protocol scopes a sitemap to URLs
 * at or below its own directory — a urlset served from uploads/ could not
 * legitimately list site URLs (Bing enforces this even though Google
 * relaxes it for robots.txt-submitted sitemaps).
 *
 * Chunk serving is two-tier: an Apache .htaccess block (via the
 * mod_rewrite_rules filter) serves the physical file without loading
 * WordPress; the WP rewrite fallback streams the pre-built file via
 * readfile(). Neither path ever generates content on the fly.
 */
class SitemapServer {

	/**
	 * Main query var carrying the request kind ('index' or 'chunk').
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'taseo_sitemap';

	/**
	 * Constructor.
	 *
	 * @param SitemapFileRepository $files    Registry repository.
	 * @param SitemapFileWriter     $writer   File writer (path/name helpers).
	 * @param Settings              $settings Settings.
	 */
	public function __construct(
		private readonly SitemapFileRepository $files,
		private readonly SitemapFileWriter $writer,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 *
	 * maybe_serve is registered with 0 accepted args on purpose: WP's
	 * do_action() passes a legacy '' argument to 1-arg callbacks on no-arg
	 * hooks, which would silently falsify the $do_exit default.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'init', array( $this, 'register_rewrites' ) );
		$hook_manager->register_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		$hook_manager->register_action( 'template_redirect', array( $this, 'maybe_serve' ), 0, 0 );
		$hook_manager->register_filter( 'robots_txt', array( $this, 'append_sitemap_line' ), 10, 2 );
		$hook_manager->register_filter( 'mod_rewrite_rules', array( $this, 'prepend_apache_static_rules' ) );
	}

	/**
	 * Add the rewrite rules (flushed once via Installer's flag, Task 9).
	 *
	 * @return void
	 */
	public function register_rewrites(): void {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . self::QUERY_VAR . '=index', 'top' );
		add_rewrite_rule(
			'^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$',
			'index.php?' . self::QUERY_VAR . '=chunk&taseo_sitemap_subtype=$matches[1]&taseo_sitemap_chunk=$matches[2]',
			'top'
		);
	}

	/**
	 * Whitelist the query vars.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string> Vars.
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'taseo_sitemap_subtype';
		$vars[] = 'taseo_sitemap_chunk';

		return $vars;
	}

	/**
	 * Serve sitemap requests on template_redirect.
	 *
	 * @param bool $do_exit Exit after serving (false in tests).
	 * @return void
	 */
	public function maybe_serve( bool $do_exit = true ): void {
		$kind = (string) get_query_var( self::QUERY_VAR );

		if ( '' === $kind || ! $this->settings->is_sitemap_enabled() ) {
			return;
		}

		if ( 'index' === $kind ) {
			status_header( 200 );
			$this->send_xml_headers();
			echo $this->render_root_index(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML document; every value escaped during rendering.
		} elseif ( 'chunk' === $kind ) {
			$this->serve_chunk();
		} else {
			return;
		}

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Render the <sitemapindex> from current registry state.
	 *
	 * Deliberately live (no dirty-tracking, no caching lag on our side):
	 * the registry stays small at any catalog size.
	 *
	 * @return string XML document.
	 */
	public function render_root_index(): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $this->files->get_all_chunks() as $chunk ) {
			if ( (int) $chunk['link_count'] < 1 ) {
				continue;
			}

			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_url( home_url( '/' . $this->writer->get_file_name( $chunk ) ) ) . "</loc>\n";

			$lastmod = SitemapFileWriter::format_lastmod(
				isset( $chunk['last_modified'] ) ? (string) $chunk['last_modified'] : null
			);

			if ( null !== $lastmod ) {
				$xml .= "\t\t<lastmod>" . $lastmod . "</lastmod>\n";
			}

			$xml .= "\t</sitemap>\n";
		}

		return $xml . '</sitemapindex>' . "\n";
	}

	/**
	 * WP fallback for chunk URLs: stream the pre-built physical file.
	 *
	 * @return void
	 */
	private function serve_chunk(): void {
		$subtype = sanitize_key( (string) get_query_var( 'taseo_sitemap_subtype' ) );
		$number  = (int) get_query_var( 'taseo_sitemap_chunk' );

		$path = $this->writer->get_file_path(
			array(
				'object_subtype' => $subtype,
				'chunk_number'   => $number,
			)
		);

		if ( '' === $subtype || $number < 1 || ! file_exists( $path ) ) {
			status_header( 404 );

			return;
		}

		status_header( 200 );
		$this->send_xml_headers();

		// Never generated here — only reads what the sweep already built.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- streaming a plugin-generated local static file is the designed fallback path.
	}

	/**
	 * Content headers shared by both serving paths.
	 *
	 * @return void
	 */
	private function send_xml_headers(): void {
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
	}

	/**
	 * Add the standard Sitemap: line to robots.txt.
	 *
	 * @param string $output    Robots.txt body.
	 * @param mixed  $is_public 'blog_public' option value (string '0'/'1').
	 * @return string Body.
	 */
	public function append_sitemap_line( string $output, $is_public ): string {
		if ( ! $is_public || ! $this->settings->is_sitemap_enabled() ) {
			return $output;
		}

		return rtrim( $output, "\n" ) . "\n\nSitemap: " . esc_url( home_url( '/sitemap.xml' ) ) . "\n";
	}

	/**
	 * Prepend static-serving rules to the .htaccess block WP writes.
	 *
	 * The -f condition serves the physical file directly (WordPress never
	 * loads); a missing file falls through to WP's rules and lands in the
	 * serve_chunk() fallback. Non-Apache hosts simply never apply this
	 * filter's output and always use the fallback.
	 *
	 * @param string $rules mod_rewrite block WP is about to write.
	 * @return string Rules.
	 */
	public function prepend_apache_static_rules( string $rules ): string {
		if ( ! $this->settings->is_sitemap_enabled() ) {
			return $rules;
		}

		$uploads = wp_upload_dir();
		$base    = (string) wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );

		if ( '' === $base ) {
			return $rules;
		}

		$directory = $base . '/' . SitemapFileWriter::DIRECTORY;

		$snippet  = "# BEGIN The Another SEO sitemap files\n";
		$snippet .= "<IfModule mod_rewrite.c>\n";
		$snippet .= "RewriteEngine On\n";
		$snippet .= 'RewriteCond %{DOCUMENT_ROOT}' . $directory . '/$1-sitemap-$2.xml -f' . "\n";
		$snippet .= 'RewriteRule ^([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ ' . $directory . '/$1-sitemap-$2.xml [L]' . "\n";
		$snippet .= "</IfModule>\n";
		$snippet .= "# END The Another SEO sitemap files\n\n";

		return $snippet . $rules;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test`
Expected: ALL tests PASS. (`header()` calls are silent no-ops under the CLI SAPI, so no stubbing is needed.)

- [ ] **Step 5: Document the Nginx snippet**

Nginx has no `.htaccess` equivalent, so the static-serving rule is a copy-paste snippet (spec: "Root index"). Add it to the class docblock of `SitemapServer` (append to the existing docblock, before the closing `*/`):

```php
 *
 * Nginx equivalent of the Apache block (manual, server config):
 *
 *     location ~ ^/([a-z0-9_-]+)-sitemap-([0-9]+)\.xml$ {
 *         try_files /wp-content/uploads/taseo-sitemaps/$1-sitemap-$2.xml /index.php?taseo_sitemap=chunk&taseo_sitemap_subtype=$1&taseo_sitemap_chunk=$2;
 *     }
```

- [ ] **Step 6: Run phpcs and commit**

```bash
composer phpcs
git add includes/Sitemap/SitemapServer.php tests/Sitemap/SitemapServerTest.php
git commit -m "feat: serve root sitemap index live with static chunk rewrites and WP fallback"
```

---

### Task 8: SettingsPage — Sitemap tab, regenerate action, storage notice

**Files:**
- Modify: `includes/Admin/SettingsPage.php`
- Test: `tests/Admin/SettingsPageTest.php` (modify)

**Interfaces:**
- Consumes: `Settings::is_sitemap_enabled()/get_sitemap_max_links()` (Task 5), `SitemapFileRepository::get_status_summary()` (Task 3), `SitemapFileWriter::is_writable()` (Task 4), `SitemapSweeper::dispatch_full_regeneration()` (Task 6)
- Produces:
  - New constructor signature (Task 9 must wire it): `new SettingsPage( Settings $settings, IndexableBackfill $backfill, SitemapFileRepository $sitemap_files, SitemapFileWriter $sitemap_writer, SitemapSweeper $sitemap_sweeper )`
  - `handle_sitemap_regenerate( bool $do_exit = true ): void` on `admin_post_taseo_sitemap_regenerate`
  - `maybe_print_sitemap_storage_notice(): void` on `admin_notices`
  - `sanitize_settings()` handles `sitemap_enabled` (bool, force-cleared on the sitemap tab) and `sitemap_max_links` (absint clamped 1–1000)

Drive-by fix included: the three `admin_post_*` handlers are registered with **0 accepted args** — WP's `do_action()` passes a legacy `''` to 1-arg callbacks on no-arg hooks, which was silently falsifying `$do_exit` on the existing save/rescan handlers (redirect worked only because nothing runs after `admin-post.php` fires the hook).

- [ ] **Step 1: Write the failing tests**

In `tests/Admin/SettingsPageTest.php`:

1. Add imports:

```php
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;
```

2. Add properties and update `setUp()` — replace the `$this->page = new SettingsPage( ... );` line and add the three mocks above it:

```php
	private $sitemap_files;
	private $sitemap_writer;
	private $sitemap_sweeper;
```

```php
		$this->sitemap_files   = Mockery::mock( SitemapFileRepository::class );
		$this->sitemap_writer  = Mockery::mock( SitemapFileWriter::class );
		$this->sitemap_sweeper = Mockery::mock( SitemapSweeper::class );

		$this->page = new SettingsPage(
			$this->settings,
			$this->backfill,
			$this->sitemap_files,
			$this->sitemap_writer,
			$this->sitemap_sweeper
		);
```

3. Add new test methods:

```php
	public function test_sanitize_settings_clamps_sitemap_max_links(): void {
		$this->assertSame( 1000, $this->page->sanitize_settings( array( 'sitemap_max_links' => '5000' ) )['sitemap_max_links'] );
		$this->assertSame( 1, $this->page->sanitize_settings( array( 'sitemap_max_links' => '0' ) )['sitemap_max_links'] );
		$this->assertSame( 500, $this->page->sanitize_settings( array( 'sitemap_max_links' => '500' ) )['sitemap_max_links'] );
	}

	public function test_sanitize_settings_sitemap_tab_forces_unchecked_toggle_off(): void {
		$clean = $this->page->sanitize_settings( array( 'sitemap_max_links' => '1000' ), 'sitemap' );

		$this->assertArrayHasKey( 'sitemap_enabled', $clean );
		$this->assertFalse( $clean['sitemap_enabled'] );
	}

	public function test_handle_sitemap_regenerate_dispatches_full_regeneration(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'wp_safe_redirect' )->once();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo&tab=sitemap' );

		$this->sitemap_sweeper->shouldReceive( 'dispatch_full_regeneration' )->once();

		$this->page->handle_sitemap_regenerate( false );

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_handle_sitemap_regenerate_bails_without_capability(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->andReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->sitemap_sweeper->shouldNotReceive( 'dispatch_full_regeneration' );

		$this->page->handle_sitemap_regenerate( false );

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_sitemap_storage_notice_prints_when_uploads_unwritable(): void {
		Functions\when( 'esc_html__' )->returnArg();

		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->sitemap_writer->shouldReceive( 'is_writable' )->andReturn( false );

		ob_start();
		$this->page->maybe_print_sitemap_storage_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
	}

	public function test_sitemap_storage_notice_silent_when_writable(): void {
		$this->settings->shouldReceive( 'is_sitemap_enabled' )->andReturn( true );
		$this->sitemap_writer->shouldReceive( 'is_writable' )->andReturn( true );

		ob_start();
		$this->page->maybe_print_sitemap_storage_notice();

		$this->assertSame( '', ob_get_clean() );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter SettingsPageTest`
Expected: FAIL — every test in the class errors on the 5-arg constructor (ArgumentCountError) until the implementation lands; that's expected for this step.

- [ ] **Step 3: Modify includes/Admin/SettingsPage.php**

1. Add imports:

```php
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;
```

2. Add `'sitemap' => 'Sitemap'` to the `TABS` const (after `'schema'`), and update the class docblock's tab list sentence accordingly.

3. Replace the constructor:

```php
	/**
	 * Constructor.
	 *
	 * @param Settings              $settings        Settings.
	 * @param IndexableBackfill     $backfill        Backfill.
	 * @param SitemapFileRepository $sitemap_files   Sitemap registry (status panel).
	 * @param SitemapFileWriter     $sitemap_writer  Sitemap writer (writability probe).
	 * @param SitemapSweeper        $sitemap_sweeper Sitemap sweeper (regenerate action).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly IndexableBackfill $backfill,
		private readonly SitemapFileRepository $sitemap_files,
		private readonly SitemapFileWriter $sitemap_writer,
		private readonly SitemapSweeper $sitemap_sweeper
	) {
	}
```

4. In `init()`, change the two existing `admin_post_*` registrations to pass 0 accepted args, and add the two new hooks, so the method body reads:

```php
		$hook_manager->register_action( 'admin_menu', array( $this, 'register_menu' ) );
		// 0 accepted args: WP passes a legacy '' to 1-arg callbacks on no-arg
		// hooks, which would falsify the handlers' $do_exit default.
		$hook_manager->register_action( 'admin_post_taseo_save_settings', array( $this, 'handle_save' ), 10, 0 );
		$hook_manager->register_action( 'admin_post_taseo_rescan', array( $this, 'handle_rescan' ), 10, 0 );
		$hook_manager->register_action( 'admin_post_taseo_sitemap_regenerate', array( $this, 'handle_sitemap_regenerate' ), 10, 0 );
		$hook_manager->register_action( 'admin_notices', array( $this, 'maybe_print_conflict_notice' ) );
		$hook_manager->register_action( 'admin_notices', array( $this, 'maybe_print_sitemap_storage_notice' ) );
```

5. In `render_page()`, add the sitemap arm to the `match`:

```php
			match ( $active ) {
				'types'     => $this->render_types_tab(),
				'templates' => $this->render_templates_tab(),
				'social'    => $this->render_social_tab(),
				'schema'    => $this->render_schema_tab(),
				'sitemap'   => $this->render_sitemap_tab(),
				default     => $this->render_general_tab(),
			};
```

6. Add the tab renderer (after `render_schema_tab()`):

```php
	/**
	 * Sitemap tab: toggle, chunk size, operational status, regenerate.
	 *
	 * @return void
	 */
	private function render_sitemap_tab(): void {
		$status = $this->sitemap_files->get_status_summary();

		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="taseo_settings[sitemap_enabled]" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'XML sitemap', 'the-another-seo' ),
			checked( $this->settings->is_sitemap_enabled(), true, false ),
			esc_html__( 'Generate XML sitemap files and announce them in robots.txt', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row"><label for="taseo-sitemap-max-links">%s</label></th><td><input type="number" id="taseo-sitemap-max-links" name="taseo_settings[sitemap_max_links]" value="%d" min="1" max="1000" class="small-text" /> %s</td></tr>',
			esc_html__( 'Links per file', 'the-another-seo' ),
			(int) $this->settings->get_sitemap_max_links(),
			esc_html__( '(1–1000; applies to newly created files)', 'the-another-seo' )
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Status', 'the-another-seo' ) . '</h2>';

		echo '<table class="widefat striped" style="max-width: 480px;"><thead><tr><th>'
			. esc_html__( 'Content type', 'the-another-seo' ) . '</th><th>'
			. esc_html__( 'Files', 'the-another-seo' ) . '</th><th>'
			. esc_html__( 'Links', 'the-another-seo' ) . '</th></tr></thead><tbody>';

		foreach ( $status['subtypes'] as $subtype => $counts ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%d</td></tr>',
				esc_html( (string) $subtype ),
				(int) $counts['chunks'],
				(int) $counts['links']
			);
		}

		echo '</tbody></table>';

		printf(
			'<p>%s <strong>%d</strong> — %s %s</p>',
			esc_html__( 'Files awaiting regeneration:', 'the-another-seo' ),
			(int) $status['dirty'],
			esc_html__( 'last file written:', 'the-another-seo' ),
			esc_html( $status['last_generated'] ?? __( 'never', 'the-another-seo' ) )
		);

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=taseo_sitemap_regenerate' ), 'taseo_save_settings', 'taseo_settings_nonce' ) ),
			esc_html__( 'Regenerate all sitemap files now', 'the-another-seo' )
		);
	}
```

7. Add the handler and notice (after `handle_rescan()`):

```php
	/**
	 * Admin_post regenerate handler: mark everything dirty, drain via AS.
	 *
	 * @param bool $do_exit Exit after redirect (false in tests).
	 * @return void
	 */
	public function handle_sitemap_regenerate( bool $do_exit = true ): void {
		if ( ! $this->verify_request() ) {
			return;
		}

		$this->sitemap_sweeper->dispatch_full_regeneration();

		// phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit -- conditional exit based on testability flag.
		wp_safe_redirect( admin_url( 'options-general.php?page=taseo&tab=sitemap' ) );

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Surface the uploads-not-writable environment problem (spec: sitemap
	 * generation is disabled with a clear admin notice, never a silent fail).
	 *
	 * @return void
	 */
	public function maybe_print_sitemap_storage_notice(): void {
		if ( ! $this->settings->is_sitemap_enabled() || $this->sitemap_writer->is_writable() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The Another SEO: the uploads directory is not writable, so XML sitemap files cannot be generated. Fix the uploads directory permissions to resume sitemap generation.', 'the-another-seo' )
		);
	}
```

8. In `sanitize_settings()`, add before the `return $clean;`:

```php
		if ( isset( $raw['sitemap_max_links'] ) ) {
			$clean['sitemap_max_links'] = max( 1, min( 1000, absint( $raw['sitemap_max_links'] ) ) );
		}

		if ( array_key_exists( 'sitemap_enabled', $raw ) ) {
			$clean['sitemap_enabled'] = ! empty( $raw['sitemap_enabled'] );
		}

		if ( 'sitemap' === $tab ) {
			$clean['sitemap_enabled'] = ! empty( $raw['sitemap_enabled'] );
		}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test`
Expected: ALL tests PASS (including every pre-existing SettingsPage test under the new constructor).

Note: `includes/Plugin.php` still constructs `SettingsPage` with 2 args at this point; it is only instantiated behind `is_admin()`, which every test stubs to false. Task 9 fixes the wiring — do not activate the plugin on a real site between these two commits.

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Admin/SettingsPage.php tests/Admin/SettingsPageTest.php
git commit -m "feat: add sitemap settings tab with status panel, regenerate action, and storage notice"
```

---

### Task 9: Plugin wiring — service graph, rewrite flush, upgrade backfill, deactivation

**Files:**
- Modify: `includes/Plugin.php`
- Modify: `the-another-seo.php`
- Test: `tests/PluginTest.php` (modify)

**Interfaces:**
- Consumes: everything from Tasks 1–8
- Produces: container keys `sitemap_file_repository`, `sitemap_file_writer`, `sitemap_assignment`, `sitemap_sweeper`, `sitemap_server`; one-shot rewrite flush driven by `Installer::FLUSH_REWRITE_OPTION`; upgrade-path re-backfill via `Installer::NEEDS_BACKFILL_OPTION`; deactivation unschedules the recurring sweep

- [ ] **Step 1: Write the failing tests**

In `tests/PluginTest.php`:

1. In `test_start_registers_all_services()`, extend the service key list with:

```php
				'sitemap_file_repository',
				'sitemap_file_writer',
				'sitemap_assignment',
				'sitemap_sweeper',
				'sitemap_server',
```

2. In `test_start_registers_frontend_hooks()`, add assertions after the existing ones:

```php
		$this->assertContains( 'taseo_indexable_synced', $names );
		$this->assertContains( 'taseo_indexable_deleting', $names );
		$this->assertContains( 'taseo_sitemap_sweep', $names );
		$this->assertContains( 'taseo_permalinks_rebuilt', $names );
		$this->assertContains( 'template_redirect', $names );
		$this->assertContains( 'robots_txt', $names );
		$this->assertContains( 'mod_rewrite_rules', $names );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter PluginTest`
Expected: FAIL — "Missing service: sitemap_file_repository" and the hook assertions.

- [ ] **Step 3: Modify includes/Plugin.php**

1. Add imports:

```php
use TheAnother\Plugin\SEO\Database\SitemapFilesTable;
use TheAnother\Plugin\SEO\Sitemap\SitemapAssignment;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapServer;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;
```

2. Replace `start()`:

```php
	/**
	 * Start the plugin: register services and hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->maybe_flag_upgrade_backfill();

		IndexablesTable::maybe_upgrade();
		SitemapFilesTable::maybe_upgrade();

		$this->register_services();
		$this->init_services();
		$this->maybe_dispatch_initial_backfill();
		$this->maybe_flush_rewrites();
	}
```

3. In `register_services()`, replace the `settings_page` registration and append the sitemap services before the `blocks` line:

```php
		$c->register(
			'settings_page',
			fn( Container $c ) => new SettingsPage(
				$c->get( 'settings' ),
				$c->get( 'indexable_backfill' ),
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_file_writer' ),
				$c->get( 'sitemap_sweeper' )
			)
		);
		$c->register( 'sitemap_file_repository', fn() => new SitemapFileRepository() );
		$c->register(
			'sitemap_file_writer',
			fn( Container $c ) => new SitemapFileWriter( $c->get( 'sitemap_file_repository' ) )
		);
		$c->register(
			'sitemap_assignment',
			fn( Container $c ) => new SitemapAssignment(
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_file_writer' ),
				$c->get( 'settings' )
			)
		);
		$c->register(
			'sitemap_sweeper',
			fn( Container $c ) => new SitemapSweeper(
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_file_writer' ),
				$c->get( 'settings' )
			)
		);
		$c->register(
			'sitemap_server',
			fn( Container $c ) => new SitemapServer(
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_file_writer' ),
				$c->get( 'settings' )
			)
		);
```

4. In `init_services()`, add before the `is_admin()` block:

```php
		$this->container->get( 'sitemap_assignment' )->init( $hook_manager );
		$this->container->get( 'sitemap_sweeper' )->init( $hook_manager );
		$this->container->get( 'sitemap_server' )->init( $hook_manager );
```

5. Append two private methods after `maybe_dispatch_initial_backfill()`:

```php
	/**
	 * Re-dispatch a full backfill when upgrading an existing install to the
	 * sitemap schema: pre-upgrade rows have no chunk assignment, and only a
	 * resync (which re-fires taseo_indexable_synced per row) assigns them.
	 * Fresh installs report '0' and are handled by Installer::activate().
	 *
	 * Must run BEFORE IndexablesTable::maybe_upgrade() stamps the new version.
	 *
	 * @return void
	 */
	private function maybe_flag_upgrade_backfill(): void {
		$installed = IndexablesTable::get_installed_version();

		if ( '0' !== $installed && version_compare( $installed, '1.1.0', '<' ) ) {
			update_option( Installer::NEEDS_BACKFILL_OPTION, '1' );
		}
	}

	/**
	 * One-shot rewrite flush after activation/upgrade, deferred to init
	 * priority 30 so SitemapServer::register_rewrites() (init 10) has added
	 * its rules first. Flushing also rewrites .htaccess, which installs the
	 * static-serving block via the mod_rewrite_rules filter.
	 *
	 * @return void
	 */
	private function maybe_flush_rewrites(): void {
		if ( '1' !== get_option( Installer::FLUSH_REWRITE_OPTION, '' ) ) {
			return;
		}

		$this->container->get_hook_manager()->register_action(
			'init',
			static function (): void {
				flush_rewrite_rules();
				delete_option( Installer::FLUSH_REWRITE_OPTION );
			},
			30
		);
	}
```

- [ ] **Step 4: Modify the-another-seo.php**

After the `register_activation_hook` block, add:

```php
register_deactivation_hook(
	__FILE__,
	function () {
		// Stop the recurring sweep; Action Scheduler would otherwise keep
		// firing it (via any other AS-bundling plugin) with no listener.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Sitemap\SitemapSweeper::HOOK, array(), Sitemap\SitemapSweeper::GROUP );
		}

		flush_rewrite_rules();
	}
);
```

- [ ] **Step 5: Run the full suite**

Run: `composer test`
Expected: ALL tests PASS — the sitemap suites plus every module 1 suite; the two extended `PluginTest` tests pass.

- [ ] **Step 6: Run phpcs and commit**

```bash
composer phpcs
git add includes/Plugin.php the-another-seo.php tests/PluginTest.php
git commit -m "feat: wire sitemap service graph with rewrite flush and upgrade re-backfill"
```

- [ ] **Step 7: Manual smoke test (local WP environment)**

1. **Activation**: activate (or deactivate → reactivate) the plugin → no fatal; `wp db query "DESCRIBE wp_taseo_sitemap_files"` shows the registry; `wp db query "SHOW COLUMNS FROM wp_taseo_indexables LIKE 'sitemap_file_id'"` shows the pointer column.
2. **Backfill assignment**: visit any page once, wait for the backfill chain (Tools → Scheduled Actions, group `taseo`) → `wp db query "SELECT object_subtype, chunk_number, link_count, is_dirty FROM wp_taseo_sitemap_files"` shows chunks with counts matching published content; `SELECT COUNT(*) FROM wp_taseo_indexables WHERE is_indexable = 1 AND sitemap_file_id IS NULL AND object_type IN ('post','term')` is 0.
3. **Sweep**: within 5 minutes (or run the pending `taseo_sitemap_sweep` action manually from Tools → Scheduled Actions) → `.xml` files appear under `wp-content/uploads/taseo-sitemaps/`, and the registry rows show `is_dirty = 0` with fresh `generated_at`.
4. **Serving**: `/sitemap.xml` returns a `<sitemapindex>` listing root-level chunk URLs; each listed URL (e.g. `/post-sitemap-1.xml`) returns the physical file. On Apache, confirm `.htaccess` contains the `# BEGIN The Another SEO sitemap files` block and (via server logs or a temporary `error_log` in `serve_chunk()`) that chunk requests do NOT boot WordPress.
5. **robots.txt**: `/robots.txt` ends with `Sitemap: https://<site>/sitemap.xml`.
6. **Removal path**: trash a published post → its chunk's `link_count` drops by one and `is_dirty` flips; after the next sweep the file has one fewer `<url>`. Restore it → count returns (possibly to a different chunk — that's fine, it re-enters fresh).
7. **Delete-at-zero**: on a subtype with one nearly-empty chunk, trash all its objects → the registry row disappears, the physical file is unlinked immediately, and `/sitemap.xml` stops listing it without waiting for a sweep.
8. **Settings tab**: Settings → SEO — The Another → Sitemap shows per-subtype file/link counts and the dirty counter; "Regenerate all sitemap files now" marks everything dirty and a sweep chain drains it (watch the dirty counter fall on refresh).
9. **Permalink change**: Settings → Permalinks → change structure → after the permalink backfill chain completes, every chunk goes dirty and regenerates with the new URLs.
10. **Toggle off**: uncheck "XML sitemap" → `/sitemap.xml` 404s (rule still matches but `maybe_serve` declines and normal query handling 404s the path), robots.txt line disappears, sweep stops rebuilding.

---

## Plan Self-Review Notes

**Spec coverage check** (spec section → task): registry table schema, unique/capacity keys → T1; `sitemap_file_id` pointer column, cleared on exit → T1 (column), T5 (clear-on-release); stored-pointer linkage & object lifecycle walk → T2 (events), T5 (reconcile); assignment algorithm incl. lowest-open-chunk, atomic conditional claim, lost-race re-search, new-chunk fallback, edit→dirty, decrement/delete-at-zero + immediate unlink → T3 (primitives), T5 (orchestration); backfill inline assignment → T2 (the actions fire from the repository, which the backfill drives) — zero backfill-specific code, noted in T2; regeneration procedure (bounded member query, urlset with loc+lastmod only, WP Filesystem write, clear-dirty-with-stamp) → T4; recurring sweep, bounded batch, immediate self-chain on backlog, idempotent-race tolerance → T6; root index live, root-level chunk URLs, Apache static rewrite + WP readfile fallback, Nginx snippet documented → T7; robots.txt `Sitemap:` line → T7; admin UI (enable toggle, links-per-file 1–1000, status panel with per-subtype counts + dirty count + last generation, regenerate now) → T8; uploads-not-writable notice + non-fatal bail → T6 (bail), T8 (notice); disabled-post-type flow → no special code (flows through `is_indexable` → T5 release path, as the spec requires); `taseo_permalinks_rebuilt` → mark all dirty → T6; full-page-cache caveat → documentation-only in spec, no code; testing list → each item mapped to a named test in T1–T8.

**Known deliberate decisions:**
- Lifecycle actions fire from `IndexableRepository` (not `IndexableSync`) so every write path — save, trash, backfill, rescan, permalink rebuild, metabox stub-row creation — hits the sitemap reconciler through one seam. The stub-row path arrives with `is_indexable = 0` and no pointer, which is a no-op.
- Releases are not gated on `is_sitemap_enabled()`; only new assignments are. This keeps `link_count` truthful while output is toggled off, at the cost of maintaining counters for a disabled feature (cheap single-row updates).
- `maybe_serve` and the `admin_post_*` handlers register with 0 accepted args because WP's `do_action()` passes a legacy `''` to 1-arg callbacks — Task 8 also fixes module 1's two existing handlers, which had this latent (harmless in practice) bug.
- The upgrade-path re-backfill (T9's `maybe_flag_upgrade_backfill()`) covers installs that activated module 1 before the sitemap schema existed; fresh activations are covered by `Installer::activate()`.
- Lowering `sitemap_max_links` after chunks exist does not shrink existing chunks (spec: objects are never moved); the cap applies to claims/creations from then on. The settings field says "applies to newly created files".
- `SitemapServer` chunk requests that lose to the Apache static rule never reach PHP; the fallback 404s unknown subtypes/numbers itself rather than handing back to WP's template stack (spec: sitemaps should never render themes).
- Sweep chaining requires a fully-successful full batch — a failing filesystem degrades to one bounded attempt per recurring tick instead of a hot enqueue loop.

**Type-consistency check:** registry rows travel as `array<string, mixed>` (wpdb `ARRAY_A`, string-typed values — tests use `'id' => '7'` deliberately) and every consumer casts at use (`(int) $chunk['id']`); `SitemapFileWriter::format_lastmod( ?string ): ?string` is the single lastmod formatter for both T4 (urlset) and T7 (root index); `release_slot(): int` returns REMAINING count and `0` triggers delete-at-zero in T5; constructor orders are `( files, writer, settings )` for assignment/sweeper/server uniformly; `SettingsPage` gains `( ..., sitemap_files, sitemap_writer, sitemap_sweeper )` and T9 wires exactly that order.
