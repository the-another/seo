# The Another SEO — Meta Tags, Social Sharing & Structured Data — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the meta tags module of the-another-seo: an HPS-style indexable table (synced from posts/terms, backfilled via Action Scheduler job chains), variable-based title/description templates with per-object overrides, frontend output for title/meta/canonical/robots/Open Graph/Twitter Card tags, a Schema.org JSON-LD `@graph`, and breadcrumbs (template tag + shortcode + Gutenberg block).

**Architecture:** Container-based DI (`Container` + `HookManager`, the aucteeno-nexus house pattern) with `includes/` organized by domain: `Database/` (table schema), `Indexable/` (row model, repository, sync, backfill), `Meta/` (template resolution, head output), `Social/` (OG/Twitter), `Schema/` (JSON-LD graph), `Breadcrumbs/`, `Settings/`, `Admin/` (metabox, settings page). The custom table `wp_taseo_indexables` stores *overrides and precomputed flags only* — final titles/descriptions resolve at render time from global templates, so template changes need zero backfill. All mass operations run as self-chaining Action Scheduler jobs (bundled `woocommerce/action-scheduler`).

**Tech Stack:** PHP 8.3+, WordPress 6.9+, Composer (PSR-4 for `includes/` + `tests/`), `woocommerce/action-scheduler` (bundled, NOT Mozart-prefixed), PHPUnit 11 + Brain Monkey + Mockery (unit tests, no WP test suite/DB), `@wordpress/scripts` for the breadcrumbs block only.

## Global Constraints

- PHP 8.3+, WordPress 6.9+ (spec: "Code conventions")
- Namespace root: `TheAnother\Plugin\SEO` — StudlyCaps, no underscores anywhere; files are PSR-4 `IndexableRepository.php`, never `class-*.php` (spec: "Code conventions")
- Text domain `the-another-seo`; DB/hook/template-tag prefix `taseo`; method names stay snake_case (WP idiom)
- Plugin URI: `https://theanother.org/plugin/seo/` (spec: "Code conventions")
- Standalone: no dependency on Aucteeno/Dokan/Nexus; WooCommerce strictly optional — every WC touchpoint guarded by `function_exists`/`class_exists` (spec: assumption 1)
- Mass operations (backfill, permalink rebuild, rescan) = self-chaining Action Scheduler jobs, never long requests or WP-Cron loops (spec: "Background jobs")
- Action Scheduler is bundled via Composer and loaded from the main plugin file; it stays in its global namespace (spec: "Background jobs")
- Indexable table stores overrides only — NULL means "resolve from template"; sync never touches override columns (spec: "Data model")
- Sync bails for revisions, autosaves, and non-enabled post types/taxonomies (spec: "Sync strategy")
- Trash keeps the row with `is_indexable = 0`; permanent delete removes the row (spec: "Sync strategy")
- Backfill paginates by ID range (`WHERE ID > %d ORDER BY ID ASC LIMIT %d`), never `WP_Query` offsets (spec: "Backfill")
- Permalink-structure changes dispatch a permalink re-backfill chain and fire `taseo_permalinks_rebuilt` on completion (spec: "Sync strategy")
- Frontend canonical/`og:url` always use live `get_permalink()`; the cached `permalink` column is for bulk consumers only (spec: "Sync strategy")
- Core's `rel_canonical` is unhooked; all output escaped; nonce + capability checks on every save (spec: "Error handling")
- One JSON-LD `@graph` script per page; `BreadcrumbList` built from the same trail array the visible breadcrumbs render (spec: "Structured data")
- Undefined/unavailable template variables are silently omitted, never left as literal `%%tokens%%` in output (spec: "Titles & templates")

---

## File Structure

```
the-another-seo/
├── the-another-seo.php               # Bootstrap: constants, version checks, AS loader, Plugin::start()
├── composer.json                     # + woocommerce/action-scheduler (bundled)
├── phpunit.xml.dist
├── .phpcs.xml.dist
├── package.json                      # @wordpress/scripts — breadcrumbs block only (Task 11)
├── blocks/
│   └── breadcrumbs/
│       ├── block.json
│       ├── index.js
│       └── render.php
├── includes/
│   ├── Container.php                 # DI container (house pattern) — infrastructure
│   ├── HookManager.php               # hook registration/tracking — infrastructure
│   ├── Plugin.php                    # orchestrator: service graph + hook wiring
│   ├── Installer.php                 # activation: create table, flag initial backfill
│   ├── Database/
│   │   └── IndexablesTable.php       # dbDelta schema + db-version migration
│   ├── Indexable/
│   │   ├── IndexableRepository.php   # upsert/find/delete + override persistence
│   │   ├── IndexableSync.php         # save/trash/delete/term hooks + permalink-change dispatch
│   │   └── IndexableBackfill.php     # AS job chain: initial backfill, rescan, permalink rebuild
│   ├── Settings/
│   │   └── Settings.php              # option access, defaults, enabled types, templates, toggles
│   ├── Meta/
│   │   ├── TemplateResolver.php      # %%variable%% expansion
│   │   ├── CurrentContext.php        # resolve the current request → context array + indexable row
│   │   └── MetaOutput.php            # <title>, description, canonical, robots
│   ├── Social/
│   │   └── SocialOutput.php          # Open Graph + Twitter Card (+ WC product upgrade)
│   ├── Schema/
│   │   ├── SchemaGraph.php           # build the @graph node array
│   │   └── SchemaOutput.php          # print the JSON-LD script
│   ├── Breadcrumbs/
│   │   ├── BreadcrumbTrail.php       # single source of truth: build the trail array
│   │   └── BreadcrumbRenderer.php    # HTML renderer + template tag + shortcode glue
│   └── Admin/
│       ├── Metabox.php               # post + term edit screens: override fields + save
│       └── SettingsPage.php          # tabbed settings UI, backfill progress, rescan, conflict notice
└── tests/
    ├── bootstrap.php
    ├── ContainerTest.php
    ├── Database/IndexablesTableTest.php
    ├── Indexable/
    │   ├── IndexableRepositoryTest.php
    │   ├── IndexableSyncTest.php
    │   └── IndexableBackfillTest.php
    ├── Settings/SettingsTest.php
    ├── Meta/
    │   ├── TemplateResolverTest.php
    │   └── MetaOutputTest.php
    ├── Social/SocialOutputTest.php
    ├── Schema/SchemaGraphTest.php
    ├── Breadcrumbs/
    │   ├── BreadcrumbTrailTest.php
    │   └── BreadcrumbRendererTest.php
    └── Admin/MetaboxTest.php
```

`Indexable/` is the core bounded context (the derived index and everything that keeps it true); `Meta/`, `Social/`, `Schema/`, `Breadcrumbs/` are the four output capabilities that consume it; `Settings/` and `Admin/` are configuration surfaces. The sitemap module (separate plan) will add a `Sitemap/` context and one column — nothing here needs restructuring for it.

---

### Task 1: Plugin scaffold — Container, HookManager, bootstrap, Action Scheduler loading

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `.phpcs.xml.dist`
- Create: `the-another-seo.php`
- Create: `includes/Container.php`
- Create: `includes/HookManager.php`
- Create: `includes/Plugin.php`
- Test: `tests/bootstrap.php`
- Test: `tests/ContainerTest.php`

**Interfaces:**
- Produces: `TheAnother\Plugin\SEO\Container::get_instance(): Container`, `->register(string $key, callable $factory, bool $singleton = true): void`, `->get(string $key): mixed`, `->has(string $key): bool`, `->get_hook_manager(): HookManager`
- Produces: `TheAnother\Plugin\SEO\HookManager::register_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void`, `->register_filter(...)` (same signature), `->deregister_all(): void`
- Produces: `TheAnother\Plugin\SEO\Plugin::get_instance(): Plugin`, `->start(): void` (empty body; Task 15 fills the service graph)
- Produces: constants `THE_ANOTHER_SEO_VERSION`, `THE_ANOTHER_SEO_PLUGIN_FILE`, `THE_ANOTHER_SEO_PLUGIN_DIR`, `THE_ANOTHER_SEO_PLUGIN_URL`, `THE_ANOTHER_SEO_PLUGIN_BASENAME`

- [ ] **Step 1: Create composer.json**

```json
{
  "name": "theanother/the-another-seo",
  "description": "Performance-first SEO for WordPress at catalog scale: template-driven titles and meta, Open Graph and Twitter Cards, Schema.org JSON-LD, breadcrumbs, and an indexable table built for millions of objects.",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "version": "0.1.0",
  "author": {
    "name": "The Another",
    "email": "hello@theanother.org",
    "url": "https://theanother.org"
  },
  "keywords": ["wordpress", "plugin", "seo", "open-graph", "schema-org", "breadcrumbs"],
  "homepage": "https://theanother.org/plugin/seo/",
  "require": {
    "php": ">=8.3",
    "woocommerce/action-scheduler": "^3.9"
  },
  "require-dev": {
    "automattic/vipwpcs": "^3.0",
    "brain/monkey": "^2.6",
    "mockery/mockery": "^1.6",
    "php-stubs/wordpress-stubs": "^6.9",
    "phpunit/phpunit": "^11.0",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.0"
  },
  "autoload": {
    "psr-4": {
      "TheAnother\\Plugin\\SEO\\": "includes/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "TheAnother\\Plugin\\SEO\\Tests\\": "tests/"
    }
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    },
    "sort-packages": true
  },
  "scripts": {
    "phpcs": "phpcs",
    "phpcbf": "phpcbf",
    "test": "phpunit"
  }
}
```

Note: `woocommerce/action-scheduler` sits in `require` (production dependency, bundled into the release). It is **never** run through Mozart or any namespace prefixer — its version-negotiation with other bundled copies (e.g. WooCommerce's own) depends on its global classes.

- [ ] **Step 2: Create phpunit.xml.dist**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
		 bootstrap="tests/bootstrap.php"
		 colors="true"
		 cacheDirectory=".phpunit.cache"
		 executionOrder="depends,defects"
		 failOnRisky="true"
		 failOnWarning="true"
		 requireCoverageMetadata="false"
		 beStrictAboutCoverageMetadata="true"
		 beStrictAboutOutputDuringTests="true"
		 processIsolation="false"
		 stopOnFailure="false">
	<testsuites>
		<testsuite name="The Another SEO Test Suite">
			<directory>./tests</directory>
		</testsuite>
	</testsuites>
	<source>
		<include>
			<directory suffix=".php">./includes</directory>
		</include>
		<exclude>
			<directory>./vendor</directory>
		</exclude>
	</source>
	<php>
		<ini name="error_reporting" value="E_ALL"/>
		<ini name="display_errors" value="1"/>
		<ini name="display_startup_errors" value="1"/>
	</php>
</phpunit>
```

- [ ] **Step 3: Create .phpcs.xml.dist**

```xml
<?xml version="1.0"?>
<ruleset name="TheAnotherSEO">
	<description>WordPress Coding Standards and Automattic VIP Coding Standards for The Another SEO plugin</description>

	<file>./includes</file>
	<file>./the-another-seo.php</file>
	<file>./blocks</file>
	<exclude-pattern>*/vendor/*</exclude-pattern>
	<exclude-pattern>*/node_modules/*</exclude-pattern>
	<exclude-pattern>*/dist/*</exclude-pattern>
	<exclude-pattern>*/.git/*</exclude-pattern>

	<arg value="sp"/>
	<arg name="basepath" value="./"/>
	<arg name="colors"/>
	<arg name="extensions" value="php"/>
	<arg name="parallel" value="8"/>

	<config name="testVersion" value="8.3-"/>
	<config name="minimum_supported_wp_version" value="6.9"/>

	<rule ref="WordPress">
		<exclude name="WordPress.Files.FileName"/>
		<exclude name="WordPress.NamingConventions.PrefixAllGlobals"/>
	</rule>

	<rule ref="WordPress-VIP-Go">
		<exclude name="WordPressVIPMinimum.Functions.RestrictedFunctions"/>
	</rule>

	<rule ref="WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid">
		<severity>5</severity>
	</rule>
</ruleset>
```

- [ ] **Step 4: Create includes/Container.php**

Identical mechanics to the verified `aucteeno-nexus` container, under this plugin's namespace:

```php
<?php
/**
 * Container Class
 *
 * Dependency injection container with lazy loading and hook management.
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use Exception;

/**
 * Class Container
 *
 * Service container following WooCommerce-style patterns with lazy loading.
 */
class Container {

	/**
	 * Container instance.
	 *
	 * @var Container|null
	 */
	private static ?Container $instance = null;

	/**
	 * Registered services (factories or instances).
	 *
	 * @var array<string, mixed>
	 */
	private array $services = array();

	/**
	 * Service factories for lazy instantiation.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Instantiated singleton services.
	 *
	 * @var array<string, object>
	 */
	private array $singletons = array();

	/**
	 * Hook manager instance.
	 *
	 * @var HookManager
	 */
	private HookManager $hook_manager;

	/**
	 * Private constructor to prevent direct instantiation.
	 */
	private function __construct() {
		$this->hook_manager = new HookManager();
	}

	/**
	 * Get the container instance.
	 *
	 * @return Container Container instance.
	 */
	public static function get_instance(): Container {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get a service from the container.
	 *
	 * @param string $key Service key.
	 * @return mixed Service instance.
	 *
	 * @throws Exception If service not found.
	 */
	public function get( string $key ): mixed {
		if ( isset( $this->singletons[ $key ] ) ) {
			return $this->singletons[ $key ];
		}

		if ( isset( $this->factories[ $key ] ) ) {
			$instance = call_user_func( $this->factories[ $key ], $this );

			if ( isset( $this->services[ $key ]['singleton'] ) && $this->services[ $key ]['singleton'] ) {
				$this->singletons[ $key ] = $instance;
			}

			return $instance;
		}

		if ( isset( $this->services[ $key ] ) ) {
			return $this->services[ $key ];
		}

		throw new Exception( esc_html( sprintf( 'Service %s not found in container', $key ) ) );
	}

	/**
	 * Check if a service exists in the container.
	 *
	 * @param string $key Service key.
	 * @return bool True if service exists, false otherwise.
	 */
	public function has( string $key ): bool {
		return isset( $this->services[ $key ] ) || isset( $this->factories[ $key ] );
	}

	/**
	 * Register a service factory.
	 *
	 * @param string   $key       Service key.
	 * @param callable $factory   Factory function that receives Container as parameter.
	 * @param bool     $singleton Whether to treat as singleton (default: true).
	 * @return void
	 */
	public function register( string $key, callable $factory, bool $singleton = true ): void {
		$this->factories[ $key ] = $factory;
		$this->services[ $key ]  = array( 'singleton' => $singleton );
		unset( $this->singletons[ $key ] );
	}

	/**
	 * Register a direct service instance.
	 *
	 * @param string $key      Service key.
	 * @param mixed  $instance Service instance.
	 * @return void
	 */
	public function set( string $key, mixed $instance ): void {
		$this->services[ $key ] = $instance;
	}

	/**
	 * Get the hook manager instance.
	 *
	 * @return HookManager Hook manager instance.
	 */
	public function get_hook_manager(): HookManager {
		return $this->hook_manager;
	}

	/**
	 * Deregister all hooks managed by the container.
	 *
	 * @return void
	 */
	public function deregister_all_hooks(): void {
		$this->hook_manager->deregister_all();
	}

	/**
	 * Prevent cloning of the instance.
	 */
	private function __clone() {
	}

	/**
	 * Prevent unserialization of the instance.
	 *
	 * @throws Exception Always, to prevent unserialization.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton' );
	}
}
```

- [ ] **Step 5: Create includes/HookManager.php**

```php
<?php
/**
 * Hook Manager Class
 *
 * Manages WordPress hook registration and deregistration with tracking.
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

/**
 * Class HookManager
 *
 * Tracks and manages WordPress hooks for easy registration and deregistration.
 */
class HookManager {

	/**
	 * Registered hooks tracking.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $registered_hooks = array();

	/**
	 * Register a WordPress action hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function register_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( has_action( $hook, $callback ) !== false ) {
			return;
		}

		add_action( $hook, $callback, $priority, $accepted_args );

		$this->registered_hooks[] = array(
			'type'          => 'action',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Register a WordPress filter hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 * @return void
	 */
	public function register_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( has_filter( $hook, $callback ) !== false ) {
			return;
		}

		add_filter( $hook, $callback, $priority, $accepted_args );

		$this->registered_hooks[] = array(
			'type'          => 'filter',
			'hook'          => $hook,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
	}

	/**
	 * Deregister all hooks tracked by this manager.
	 *
	 * @return void
	 */
	public function deregister_all(): void {
		foreach ( $this->registered_hooks as $hook_data ) {
			if ( 'action' === $hook_data['type'] ) {
				remove_action( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			} else {
				remove_filter( $hook_data['hook'], $hook_data['callback'], $hook_data['priority'] );
			}
		}
		$this->registered_hooks = array();
	}

	/**
	 * Get all registered hooks.
	 *
	 * @return array<int, array<string, mixed>> Registered hooks.
	 */
	public function get_registered_hooks(): array {
		return $this->registered_hooks;
	}
}
```

- [ ] **Step 6: Create includes/Plugin.php (empty start(), filled by Task 15)**

```php
<?php
/**
 * Plugin Orchestrator Class
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

/**
 * Class Plugin
 *
 * Registers services and wires hooks. Task 15 fills in the full service
 * graph; this task only establishes the singleton shape.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->container = Container::get_instance();
	}

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin Plugin instance.
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Start the plugin: register services and hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		// Filled in by Task 15.
	}
}
```

- [ ] **Step 7: Create the-another-seo.php**

Action Scheduler must be loaded from the main plugin file at include time (not inside a hook) so its version negotiation runs before `plugins_loaded`:

```php
<?php
/**
 * Plugin Name: The Another SEO
 * Plugin URI: https://theanother.org/plugin/seo/
 * Description: Performance-first SEO for WordPress at catalog scale — template-driven titles and meta, Open Graph and Twitter Cards, Schema.org JSON-LD, and breadcrumbs.
 * Version: 0.1.0
 * Author: The Another
 * Author URI: https://theanother.org
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Text Domain: the-another-seo
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'THE_ANOTHER_SEO_VERSION', '0.1.0' );
define( 'THE_ANOTHER_SEO_PLUGIN_FILE', __FILE__ );
define( 'THE_ANOTHER_SEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'THE_ANOTHER_SEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'THE_ANOTHER_SEO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another SEO requires PHP 8.3 or higher. Please upgrade your PHP version.', 'the-another-seo' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( version_compare( get_bloginfo( 'version' ), '6.9', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			?>
			<div class="notice notice-error">
				<p><?php echo esc_html__( 'The Another SEO requires WordPress 6.9 or higher. Please upgrade WordPress.', 'the-another-seo' ); ?></p>
			</div>
			<?php
		}
	);
	return;
}

if ( file_exists( THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/autoload.php';
}

// Action Scheduler self-deduplicates across bundling plugins; it must be
// required directly from the plugin main file, at include time.
if ( file_exists( THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once THE_ANOTHER_SEO_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

register_activation_hook(
	__FILE__,
	function () {
		Installer::activate();
	}
);

add_action(
	'plugins_loaded',
	function () {
		try {
			Plugin::get_instance()->start();
		} catch ( Exception $e ) {
			wp_die(
				esc_html( $e->getMessage() ),
				'The Another SEO Error',
				array( 'response' => 500 )
			);
		}
	}
);
```

Note: `Installer` doesn't exist yet — Task 2 creates it. Until then the activation hook would fatal on activation, but nothing in Tasks 1's test cycle activates the plugin; if you want to activate early for manual poking, comment the `register_activation_hook` block out locally and restore it in Task 2.

- [ ] **Step 8: Create tests/bootstrap.php**

```php
<?php
/**
 * PHPUnit bootstrap file for The Another SEO plugin tests.
 *
 * @package TheAnother\Plugin\SEO\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/patchwork-loader.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! defined( 'THE_ANOTHER_SEO_VERSION' ) ) {
	define( 'THE_ANOTHER_SEO_VERSION', '0.1.0' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( empty( $code ) ) {
				return;
			}
			$this->errors[ $code ][]   = $message;
			$this->error_data[ $code ] = $data;
		}

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$codes = array_keys( $this->errors );
				$code  = $codes[0] ?? '';
			}
			return isset( $this->errors[ $code ] ) ? $this->errors[ $code ][0] : '';
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return ( $thing instanceof WP_Error );
	}
}
```

- [ ] **Step 9: Create tests/ContainerTest.php**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Container;

#[CoversClass( Container::class )]
class ContainerTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$reflection = new \ReflectionClass( Container::class );
		$instance   = $reflection->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_instance_returns_same_instance(): void {
		$this->assertSame( Container::get_instance(), Container::get_instance() );
	}

	public function test_register_and_get_resolves_factory(): void {
		$container = Container::get_instance();

		$container->register( 'greeting', fn() => 'hello' );

		$this->assertSame( 'hello', $container->get( 'greeting' ) );
	}

	public function test_singleton_factory_returns_same_instance_on_repeat_calls(): void {
		$container = Container::get_instance();

		$container->register( 'object_service', fn() => new \stdClass() );

		$this->assertSame( $container->get( 'object_service' ), $container->get( 'object_service' ) );
	}

	public function test_non_singleton_factory_returns_new_instance_each_call(): void {
		$container = Container::get_instance();

		$container->register( 'object_service', fn() => new \stdClass(), false );

		$this->assertNotSame( $container->get( 'object_service' ), $container->get( 'object_service' ) );
	}

	public function test_has_reflects_registered_services(): void {
		$container = Container::get_instance();

		$this->assertFalse( $container->has( 'missing' ) );

		$container->register( 'present', fn() => true );

		$this->assertTrue( $container->has( 'present' ) );
	}

	public function test_get_throws_for_unknown_service(): void {
		$this->expectException( \Exception::class );

		Container::get_instance()->get( 'does_not_exist' );
	}

	public function test_get_hook_manager_returns_hook_manager_instance(): void {
		$this->assertInstanceOf(
			\TheAnother\Plugin\SEO\HookManager::class,
			Container::get_instance()->get_hook_manager()
		);
	}
}
```

- [ ] **Step 10: Install and run tests**

Run: `composer install && composer test`
Expected: All `ContainerTest` tests PASS (no failing-first step — Container/HookManager are verified copies of the working house pattern, and this task establishes scaffolding rather than new behavior).

- [ ] **Step 11: Run phpcs**

Run: `composer phpcs`
Expected: no errors on the created files (warnings acceptable).

- [ ] **Step 12: Commit**

```bash
git add composer.json phpunit.xml.dist .phpcs.xml.dist the-another-seo.php includes/Container.php includes/HookManager.php includes/Plugin.php tests/bootstrap.php tests/ContainerTest.php
git commit -m "feat: scaffold plugin with DI container, hook manager, and bundled Action Scheduler"
```

---

### Task 2: IndexablesTable + Installer — schema, dbDelta, activation

**Files:**
- Create: `includes/Database/IndexablesTable.php`
- Create: `includes/Installer.php`
- Test: `tests/Database/IndexablesTableTest.php`

**Interfaces:**
- Consumes: global `$wpdb`, `dbDelta()`, `get_option()`/`update_option()`
- Produces: `TheAnother\Plugin\SEO\Database\IndexablesTable::get_table_name(): string` (`{$wpdb->prefix}taseo_indexables`), `::get_schema(): string` (full CREATE TABLE SQL), `::create_table(): void`, `::maybe_upgrade(): void`
- Produces: `TheAnother\Plugin\SEO\Installer::activate(): void` — creates the table and sets the `taseo_needs_backfill` option flag (consumed by Task 7)
- Produces: option keys `taseo_db_version` (string), `taseo_needs_backfill` (`'1'` until the initial backfill chain is dispatched)

Schema notes (from spec "Data model"): override columns are NULLable — NULL means "resolve from template". `object_id` is `0` for system pages (sentinel, keeps the unique key well-defined). `schema_disabled` and `is_indexable` are NOT NULL flags with defaults. The secondary `object_lookup_by_id` index serves "find row for post 123" lookups without knowing the subtype.

- [ ] **Step 1: Write the failing test**

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
			'last_modified',
			'created_at',
			'updated_at',
		) as $column ) {
			$this->assertStringContainsString( $column, $schema, "Missing column: {$column}" );
		}

		$this->assertStringContainsString( 'UNIQUE KEY object_lookup (object_type, object_subtype, object_id)', $schema );
		$this->assertStringContainsString( 'KEY object_lookup_by_id (object_type, object_id)', $schema );
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter IndexablesTableTest`
Expected: FAIL — `Class "TheAnother\Plugin\SEO\Database\IndexablesTable" not found`.

- [ ] **Step 3: Create includes/Database/IndexablesTable.php**

```php
<?php
/**
 * Indexables Table Schema
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Database;

/**
 * Class IndexablesTable
 *
 * Owns the wp_taseo_indexables schema. Override columns are NULLable —
 * NULL means "resolve from the global template"; sync never writes them.
 */
class IndexablesTable {

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
	private const DB_VERSION_OPTION = 'taseo_db_version';

	/**
	 * Get the fully prefixed table name.
	 *
	 * @return string Table name.
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'taseo_indexables';
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
			object_type VARCHAR(20) NOT NULL,
			object_subtype VARCHAR(32) NOT NULL,
			object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			permalink TEXT NULL,
			title TEXT NULL,
			description TEXT NULL,
			canonical_url TEXT NULL,
			robots_noindex TINYINT(1) NULL,
			robots_nofollow TINYINT(1) NULL,
			robots_noarchive TINYINT(1) NULL,
			og_title TEXT NULL,
			og_description TEXT NULL,
			og_image_id BIGINT UNSIGNED NULL,
			twitter_title TEXT NULL,
			twitter_description TEXT NULL,
			twitter_image_id BIGINT UNSIGNED NULL,
			breadcrumb_title TEXT NULL,
			schema_disabled TINYINT(1) NOT NULL DEFAULT 0,
			is_indexable TINYINT(1) NOT NULL DEFAULT 1,
			last_modified DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY object_lookup (object_type, object_subtype, object_id),
			KEY object_lookup_by_id (object_type, object_id),
			KEY is_indexable (is_indexable)
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

Note for the test run: `create_table()` calls `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` — the bootstrap defines `ABSPATH` as `/tmp/wordpress/`, so create the stub once: `mkdir -p /tmp/wordpress/wp-admin/includes && touch /tmp/wordpress/wp-admin/includes/upgrade.php` (add this to your local setup; CI images get it from the same command in the workflow file when one is added later).

- [ ] **Step 4: Create includes/Installer.php**

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

/**
 * Class Installer
 *
 * Activation-time setup. The initial backfill is NOT run here — activation
 * must stay instant. A flag is set; Plugin::start() dispatches the Action
 * Scheduler chain on the next normal request (Task 7 / Task 15).
 */
class Installer {

	/**
	 * Option flag: the initial backfill chain still needs dispatching.
	 *
	 * @var string
	 */
	public const NEEDS_BACKFILL_OPTION = 'taseo_needs_backfill';

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		IndexablesTable::create_table();

		update_option( self::NEEDS_BACKFILL_OPTION, '1' );
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter IndexablesTableTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Run phpcs and commit**

```bash
composer phpcs
git add includes/Database/IndexablesTable.php includes/Installer.php tests/Database/IndexablesTableTest.php
git commit -m "feat: add indexables table schema, dbDelta migration, and installer"
```

---

### Task 3: IndexableRepository — upsert, find, overrides, delete

**Files:**
- Create: `includes/Indexable/IndexableRepository.php`
- Test: `tests/Indexable/IndexableRepositoryTest.php`

**Interfaces:**
- Consumes: `IndexablesTable::get_table_name()`, global `$wpdb`
- Produces: `TheAnother\Plugin\SEO\Indexable\IndexableRepository`:
  - `->upsert_synced_fields(string $object_type, string $object_subtype, int $object_id, array $fields): void` — `$fields` may contain `permalink` (string), `is_indexable` (bool), `last_modified` (`Y-m-d H:i:s` string). Inserts the row if absent; on duplicate key updates ONLY these synced columns — override columns are never touched by this method.
  - `->find(string $object_type, string $object_subtype, int $object_id): ?array` — associative row or null.
  - `->find_for_post(int $post_id): ?array` — lookup by `(object_type = 'post', object_id)` without needing the subtype.
  - `->find_for_term(int $term_id): ?array` — same for terms.
  - `->save_overrides(string $object_type, string $object_subtype, int $object_id, array $overrides): void` — whitelisted override columns only; empty-string values are stored as NULL (= "no override"). Creates the row first if it doesn't exist.
  - `->delete(string $object_type, string $object_subtype, int $object_id): void`
- Produces: `OVERRIDE_COLUMNS` public const — the whitelist consumed by the Metabox (Task 13): `title`, `description`, `canonical_url`, `robots_noindex`, `robots_nofollow`, `robots_noarchive`, `og_title`, `og_description`, `og_image_id`, `twitter_title`, `twitter_description`, `twitter_image_id`, `breadcrumb_title`, `schema_disabled`

- [ ] **Step 1: Write the failing test**

```php
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

	public function test_save_overrides_creates_row_when_absent(): void {
		$this->wpdb->shouldReceive( 'prepare' )->twice()->andReturn( 'SQL' );
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter IndexableRepositoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Indexable/IndexableRepository.php**

```php
<?php
/**
 * Indexable Repository
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Indexable;

use TheAnother\Plugin\SEO\Database\IndexablesTable;

/**
 * Class IndexableRepository
 *
 * All reads/writes against wp_taseo_indexables. Synced fields and override
 * fields go through separate write paths on purpose: sync must never be able
 * to clobber an admin's overrides.
 */
class IndexableRepository {

	/**
	 * Override columns writable via save_overrides(). Everything else is
	 * identity or sync-owned.
	 *
	 * @var array<int, string>
	 */
	public const OVERRIDE_COLUMNS = array(
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
	);

	/**
	 * Insert the row or update its synced columns only.
	 *
	 * @param string $object_type    'post', 'term', or 'system_page'.
	 * @param string $object_subtype Post type / taxonomy / system page key.
	 * @param int    $object_id      Post or term ID; 0 for system pages.
	 * @param array  $fields         permalink?, is_indexable?, last_modified?.
	 * @return void
	 */
	public function upsert_synced_fields( string $object_type, string $object_subtype, int $object_id, array $fields ): void {
		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table, prepared below.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
				(object_type, object_subtype, object_id, permalink, is_indexable, last_modified)
			VALUES (%s, %s, %d, %s, %d, %s)
			ON DUPLICATE KEY UPDATE
				permalink = VALUES(permalink),
				is_indexable = VALUES(is_indexable),
				last_modified = VALUES(last_modified)",
			$object_type,
			$object_subtype,
			$object_id,
			$fields['permalink'] ?? '',
			empty( $fields['is_indexable'] ) ? 0 : 1,
			$fields['last_modified'] ?? gmdate( 'Y-m-d H:i:s' )
		);
		$wpdb->query( $sql );
		// phpcs:enable
	}

	/**
	 * Find a row by full identity.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	public function find( string $object_type, string $object_subtype, int $object_id ): ?array {
		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_type = %s AND object_subtype = %s AND object_id = %d",
				$object_type,
				$object_subtype,
				$object_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find the row for a post without knowing its subtype.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	public function find_for_post( int $post_id ): ?array {
		return $this->find_by_type_and_id( 'post', $post_id );
	}

	/**
	 * Find the row for a term without knowing its taxonomy.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	public function find_for_term( int $term_id ): ?array {
		return $this->find_by_type_and_id( 'term', $term_id );
	}

	/**
	 * Shared type+id lookup.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	private function find_by_type_and_id( string $object_type, int $object_id ): ?array {
		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_type = '" . esc_sql( $object_type ) . "' AND object_id = %d",
				$object_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Persist admin override values. Empty string means "clear the override"
	 * and is stored as NULL. Unknown keys are dropped.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @param array  $overrides      Column => value.
	 * @return void
	 */
	public function save_overrides( string $object_type, string $object_subtype, int $object_id, array $overrides ): void {
		global $wpdb;

		$row = $this->find( $object_type, $object_subtype, $object_id );

		if ( null === $row ) {
			$this->upsert_synced_fields( $object_type, $object_subtype, $object_id, array() );
			$row = $this->find( $object_type, $object_subtype, $object_id );

			if ( null === $row ) {
				return;
			}
		}

		$data = array();

		foreach ( $overrides as $column => $value ) {
			if ( ! in_array( $column, self::OVERRIDE_COLUMNS, true ) ) {
				continue;
			}
			$data[ $column ] = ( '' === $value ) ? null : $value;
		}

		if ( array() === $data ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( IndexablesTable::get_table_name(), $data, array( 'id' => (int) $row['id'] ) );
	}

	/**
	 * Delete a row by identity.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @param int    $object_id      Object ID.
	 * @return void
	 */
	public function delete( string $object_type, string $object_subtype, int $object_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			IndexablesTable::get_table_name(),
			array(
				'object_type'    => $object_type,
				'object_subtype' => $object_subtype,
				'object_id'      => $object_id,
			)
		);
	}
}
```

Add to `tests/bootstrap.php` (constants used by `$wpdb` calls):

```php
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'esc_sql' ) ) {
	function esc_sql( $data ) {
		return $data;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter IndexableRepositoryTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Indexable/IndexableRepository.php tests/Indexable/IndexableRepositoryTest.php tests/bootstrap.php
git commit -m "feat: add indexable repository with separated sync/override write paths"
```

---

### Task 4: Settings — option access, defaults, templates, toggles

**Files:**
- Create: `includes/Settings/Settings.php`
- Test: `tests/Settings/SettingsTest.php`

**Interfaces:**
- Consumes: `get_option()`, `get_post_types()`, `get_taxonomies()`
- Produces: `TheAnother\Plugin\SEO\Settings\Settings` (single `taseo_settings` option array):
  - `->get(string $key, mixed $default = null): mixed`, `->update(array $values): void`
  - `->get_enabled_post_types(): array<int, string>` — defaults to all public post types minus `attachment`
  - `->get_enabled_taxonomies(): array<int, string>` — defaults to all public taxonomies minus `post_format`
  - `->get_title_template(string $object_type, string $object_subtype): string` — stored per subtype; default `'%%title%% %%sep%% %%sitename%%'`
  - `->get_description_template(string $object_type, string $object_subtype): string` — default `'%%excerpt%%'`
  - `->get_separator(): string` — default `'–'`
  - `->is_open_graph_enabled(): bool` (default true), `->is_twitter_enabled(): bool` (default true)
  - `->get_default_social_image_id(): int` (default 0), `->get_facebook_app_id(): string`, `->get_twitter_site(): string`
  - `->get_schema_type(string $object_subtype): string` — stored mapping; defaults: `post → Article`, `page → WebPage`, `product → Product`, anything else → `WebPage`
  - `->get_site_represents(): string` (`organization`|`person`, default `organization`), `->get_site_represents_name(): string` (default `get_bloginfo('name')`), `->get_site_logo_id(): int`, `->get_same_as_urls(): array<int, string>`
  - `->get_breadcrumb_separator(): string` (default `'›'`), `->get_breadcrumb_home_label(): string` (default `'Home'`), `->breadcrumb_link_current(): bool` (default false), `->breadcrumb_include_taxonomy_ancestors(): bool` (default true)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( Settings::class )]
class SettingsTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_returns_stored_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'separator' => '|' ) );

		$this->assertSame( '|', ( new Settings() )->get( 'separator' ) );
	}

	public function test_get_returns_default_when_missing(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame( 'fallback', ( new Settings() )->get( 'missing', 'fallback' ) );
	}

	public function test_enabled_post_types_default_to_public_minus_attachment(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\expect( 'get_post_types' )
			->once()
			->with( array( 'public' => true ), 'names' )
			->andReturn( array( 'post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'product' => 'product' ) );

		$this->assertSame( array( 'post', 'page', 'product' ), ( new Settings() )->get_enabled_post_types() );
	}

	public function test_enabled_post_types_respect_stored_selection(): void {
		Functions\when( 'get_option' )->justReturn( array( 'enabled_post_types' => array( 'product' ) ) );

		$this->assertSame( array( 'product' ), ( new Settings() )->get_enabled_post_types() );
	}

	public function test_title_template_falls_back_to_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$this->assertSame(
			'%%title%% %%sep%% %%sitename%%',
			( new Settings() )->get_title_template( 'post', 'product' )
		);
	}

	public function test_title_template_reads_stored_per_subtype_value(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'title_templates' => array( 'post:product' => '%%title%% %%sep%% %%price%%' ) )
		);

		$this->assertSame(
			'%%title%% %%sep%% %%price%%',
			( new Settings() )->get_title_template( 'post', 'product' )
		);
	}

	public function test_schema_type_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$settings = new Settings();

		$this->assertSame( 'Article', $settings->get_schema_type( 'post' ) );
		$this->assertSame( 'WebPage', $settings->get_schema_type( 'page' ) );
		$this->assertSame( 'Product', $settings->get_schema_type( 'product' ) );
		$this->assertSame( 'WebPage', $settings->get_schema_type( 'custom_thing' ) );
	}

	public function test_schema_type_stored_mapping_wins(): void {
		Functions\when( 'get_option' )->justReturn(
			array( 'schema_types' => array( 'post' => 'WebPage' ) )
		);

		$this->assertSame( 'WebPage', ( new Settings() )->get_schema_type( 'post' ) );
	}

	public function test_social_toggles_default_on(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$settings = new Settings();

		$this->assertTrue( $settings->is_open_graph_enabled() );
		$this->assertTrue( $settings->is_twitter_enabled() );
	}

	public function test_update_merges_into_stored_option(): void {
		Functions\expect( 'get_option' )->once()->with( 'taseo_settings', array() )->andReturn( array( 'separator' => '|' ) );
		Functions\expect( 'update_option' )
			->once()
			->with( 'taseo_settings', array( 'separator' => '|', 'twitter_enabled' => false ) );

		( new Settings() )->update( array( 'twitter_enabled' => false ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SettingsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Settings/Settings.php**

```php
<?php
/**
 * Settings Service
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Settings;

/**
 * Class Settings
 *
 * Typed access over the single taseo_settings option array. All getters
 * carry their defaults so an empty option is fully functional.
 */
class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'taseo_settings';

	/**
	 * Default schema.org type per object subtype.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_TYPE_DEFAULTS = array(
		'post'    => 'Article',
		'page'    => 'WebPage',
		'product' => 'Product',
	);

	/**
	 * Get one settings key.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Default when unset.
	 * @return mixed Value.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		$all = get_option( self::OPTION_NAME, array() );

		return is_array( $all ) && array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Merge values into the stored option.
	 *
	 * @param array<string, mixed> $values Values to merge.
	 * @return void
	 */
	public function update( array $values ): void {
		$all = get_option( self::OPTION_NAME, array() );
		$all = is_array( $all ) ? $all : array();

		update_option( self::OPTION_NAME, array_merge( $all, $values ) );
	}

	/**
	 * Post types the plugin manages.
	 *
	 * @return array<int, string> Post type names.
	 */
	public function get_enabled_post_types(): array {
		$stored = $this->get( 'enabled_post_types' );

		if ( is_array( $stored ) ) {
			return array_values( $stored );
		}

		$public = get_post_types( array( 'public' => true ), 'names' );
		unset( $public['attachment'] );

		return array_values( $public );
	}

	/**
	 * Taxonomies the plugin manages.
	 *
	 * @return array<int, string> Taxonomy names.
	 */
	public function get_enabled_taxonomies(): array {
		$stored = $this->get( 'enabled_taxonomies' );

		if ( is_array( $stored ) ) {
			return array_values( $stored );
		}

		$public = get_taxonomies( array( 'public' => true ), 'names' );
		unset( $public['post_format'] );

		return array_values( $public );
	}

	/**
	 * Title template for a subtype.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return string Template.
	 */
	public function get_title_template( string $object_type, string $object_subtype ): string {
		$templates = $this->get( 'title_templates', array() );
		$key       = $object_type . ':' . $object_subtype;

		return is_array( $templates ) && ! empty( $templates[ $key ] )
			? (string) $templates[ $key ]
			: '%%title%% %%sep%% %%sitename%%';
	}

	/**
	 * Description template for a subtype.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return string Template.
	 */
	public function get_description_template( string $object_type, string $object_subtype ): string {
		$templates = $this->get( 'description_templates', array() );
		$key       = $object_type . ':' . $object_subtype;

		return is_array( $templates ) && ! empty( $templates[ $key ] )
			? (string) $templates[ $key ]
			: '%%excerpt%%';
	}

	/**
	 * Title separator.
	 *
	 * @return string Separator.
	 */
	public function get_separator(): string {
		return (string) $this->get( 'separator', '–' );
	}

	/**
	 * Whether Open Graph output is enabled.
	 *
	 * @return bool Enabled.
	 */
	public function is_open_graph_enabled(): bool {
		return (bool) $this->get( 'open_graph_enabled', true );
	}

	/**
	 * Whether Twitter Card output is enabled.
	 *
	 * @return bool Enabled.
	 */
	public function is_twitter_enabled(): bool {
		return (bool) $this->get( 'twitter_enabled', true );
	}

	/**
	 * Sitewide fallback social image attachment ID.
	 *
	 * @return int Attachment ID, 0 for none.
	 */
	public function get_default_social_image_id(): int {
		return (int) $this->get( 'default_social_image_id', 0 );
	}

	/**
	 * Facebook App ID.
	 *
	 * @return string App ID or ''.
	 */
	public function get_facebook_app_id(): string {
		return (string) $this->get( 'facebook_app_id', '' );
	}

	/**
	 * X/Twitter site handle (e.g. "@theanother").
	 *
	 * @return string Handle or ''.
	 */
	public function get_twitter_site(): string {
		return (string) $this->get( 'twitter_site', '' );
	}

	/**
	 * Schema.org type for a subtype.
	 *
	 * @param string $object_subtype Post type or taxonomy name.
	 * @return string Schema type.
	 */
	public function get_schema_type( string $object_subtype ): string {
		$stored = $this->get( 'schema_types', array() );

		if ( is_array( $stored ) && ! empty( $stored[ $object_subtype ] ) ) {
			return (string) $stored[ $object_subtype ];
		}

		return self::SCHEMA_TYPE_DEFAULTS[ $object_subtype ] ?? 'WebPage';
	}

	/**
	 * What the site represents in schema output.
	 *
	 * @return string 'organization' or 'person'.
	 */
	public function get_site_represents(): string {
		return (string) $this->get( 'site_represents', 'organization' );
	}

	/**
	 * Name of the represented entity.
	 *
	 * @return string Name.
	 */
	public function get_site_represents_name(): string {
		$stored = (string) $this->get( 'site_represents_name', '' );

		return '' !== $stored ? $stored : (string) get_bloginfo( 'name' );
	}

	/**
	 * Logo attachment ID for the Organization node.
	 *
	 * @return int Attachment ID, 0 for none.
	 */
	public function get_site_logo_id(): int {
		return (int) $this->get( 'site_logo_id', 0 );
	}

	/**
	 * sameAs profile URLs.
	 *
	 * @return array<int, string> URLs.
	 */
	public function get_same_as_urls(): array {
		$stored = $this->get( 'same_as_urls', array() );

		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/**
	 * Breadcrumb separator.
	 *
	 * @return string Separator.
	 */
	public function get_breadcrumb_separator(): string {
		return (string) $this->get( 'breadcrumb_separator', '›' );
	}

	/**
	 * Breadcrumb home label.
	 *
	 * @return string Label.
	 */
	public function get_breadcrumb_home_label(): string {
		return (string) $this->get( 'breadcrumb_home_label', 'Home' );
	}

	/**
	 * Whether the final breadcrumb item is a link.
	 *
	 * @return bool Link the current item.
	 */
	public function breadcrumb_link_current(): bool {
		return (bool) $this->get( 'breadcrumb_link_current', false );
	}

	/**
	 * Whether trails include taxonomy term ancestors.
	 *
	 * @return bool Include ancestors.
	 */
	public function breadcrumb_include_taxonomy_ancestors(): bool {
		return (bool) $this->get( 'breadcrumb_include_taxonomy_ancestors', true );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter SettingsTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Settings/Settings.php tests/Settings/SettingsTest.php
git commit -m "feat: add settings service with defaults for templates, social, schema, breadcrumbs"
```

---

### Task 5: TemplateResolver — %%variable%% expansion

**Files:**
- Create: `includes/Meta/TemplateResolver.php`
- Test: `tests/Meta/TemplateResolverTest.php`

**Interfaces:**
- Consumes: nothing (pure string logic — context values are provided by the caller)
- Produces: `TheAnother\Plugin\SEO\Meta\TemplateResolver::resolve(string $template, array $context): string` — replaces every `%%key%%` with `$context['key']`; tokens with no context entry are **removed** (spec: unavailable variables are silently omitted, never left literal); whitespace is collapsed and trimmed afterward so removed tokens don't leave double spaces or dangling separators.

Context keys used across the plugin (callers fill what they have): `title`, `sitename`, `tagline`, `sep`, `excerpt`, `primary_category`, `date`, `page`, `price`, `sku`. The resolver itself is key-agnostic — that's what keeps WooCommerce-only variables working with zero special-casing (an inactive WooCommerce simply never puts `price`/`sku` into the context).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;

#[CoversClass( TemplateResolver::class )]
class TemplateResolverTest extends TestCase {

	private TemplateResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new TemplateResolver();
	}

	public static function resolve_cases(): array {
		$context = array(
			'title'    => 'Vintage Watch',
			'sitename' => 'Acme Auctions',
			'sep'      => '–',
		);

		return array(
			'all tokens present'          => array( '%%title%% %%sep%% %%sitename%%', $context, 'Vintage Watch – Acme Auctions' ),
			'missing token removed'       => array( '%%title%% %%sep%% %%price%%', $context, 'Vintage Watch –' ),
			'unknown token removed'       => array( '%%title%% %%bogus%%', $context, 'Vintage Watch' ),
			'no tokens'                   => array( 'Static text', $context, 'Static text' ),
			'whitespace collapsed'        => array( '%%missing%%  %%title%%  ', $context, 'Vintage Watch' ),
			'empty template'              => array( '', $context, '' ),
			'empty context value removed' => array( '%%excerpt%% %%title%%', array_merge( $context, array( 'excerpt' => '' ) ), 'Vintage Watch' ),
		);
	}

	#[DataProvider( 'resolve_cases' )]
	public function test_resolve( string $template, array $context, string $expected ): void {
		$this->assertSame( $expected, $this->resolver->resolve( $template, $context ) );
	}

	public function test_resolve_does_not_recurse_into_replaced_values(): void {
		$this->assertSame(
			'%%sitename%%',
			$this->resolver->resolve( '%%title%%', array( 'title' => '%%sitename%%', 'sitename' => 'X' ) )
		);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter TemplateResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Meta/TemplateResolver.php**

```php
<?php
/**
 * Template Resolver
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

/**
 * Class TemplateResolver
 *
 * Expands %%variable%% tokens against a context array in a single
 * non-recursive pass. Unknown/empty variables vanish (with their
 * surrounding whitespace collapsed) rather than leaking literal tokens.
 */
class TemplateResolver {

	/**
	 * Resolve a template against a context.
	 *
	 * @param string                $template Template with %%tokens%%.
	 * @param array<string, string> $context  Token => replacement value.
	 * @return string Resolved string.
	 */
	public function resolve( string $template, array $context ): string {
		$resolved = preg_replace_callback(
			'/%%([a-z0-9_]+)%%/i',
			static function ( array $matches ) use ( $context ): string {
				return (string) ( $context[ strtolower( $matches[1] ) ] ?? '' );
			},
			$template
		);

		$resolved = preg_replace( '/\s+/', ' ', (string) $resolved );

		return trim( (string) $resolved );
	}
}
```

Note the single-pass `preg_replace_callback`: replacement values are never re-scanned, so a title containing a literal `%%sitename%%` can't trigger recursive expansion (covered by the last test).

Known cosmetic limitation (accept for v1): a template whose *last* token is missing can leave a dangling separator (`'Vintage Watch –'` in the data provider). Admins control both templates and separators; documenting beats special-casing.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter TemplateResolverTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Meta/TemplateResolver.php tests/Meta/TemplateResolverTest.php
git commit -m "feat: add template resolver with silent omission of unavailable variables"
```

---

### Task 6: IndexableSync — post/term hooks, is_indexable computation, permalink-change dispatch

**Files:**
- Create: `includes/Indexable/IndexableSync.php`
- Test: `tests/Indexable/IndexableSyncTest.php`

**Interfaces:**
- Consumes: `IndexableRepository` (Task 3), `Settings` (Task 4), `HookManager` (Task 1); WP core `get_post()`, `get_permalink()`, `get_term()`, `get_term_link()`, `wp_is_post_revision()`, `wp_is_post_autosave()`, `is_post_type_viewable()`, `is_taxonomy_viewable()`
- Produces: `TheAnother\Plugin\SEO\Indexable\IndexableSync`:
  - `->init(): void` — registers all hooks via HookManager
  - `->sync_post(int $post_id): void` — the reusable "recompute + upsert one post" unit (also consumed by `IndexableBackfill`, Task 7)
  - `->sync_term(int $term_id, string $taxonomy): void` — same for terms
  - `->handle_save_post(int $post_id, \WP_Post $post): void`, `->handle_trash_post(int $post_id): void`, `->handle_before_delete_post(int $post_id): void`, `->handle_created_term(int $term_id, int $tt_id, string $taxonomy): void`, `->handle_edited_term(int $term_id, int $tt_id, string $taxonomy): void`, `->handle_delete_term(int $term_id, int $tt_id, string $taxonomy): void`, `->handle_permalink_structure_changed(): void`
- Hooks registered by `init()`: `save_post` (10, 2), `wp_trash_post`, `before_delete_post`, `created_term` (10, 3), `edited_term` (10, 3), `delete_term` (10, 3), `permalink_structure_changed`, `update_option_woocommerce_permalinks`
- Fires: nothing directly — permalink changes delegate to `IndexableBackfill::dispatch_permalink_rebuild()` (Task 7; injected as a lazy callable so Task 6 is testable before Task 7 exists)

Sync rules (spec "Sync strategy"): bail early for revisions/autosaves/non-enabled types; `is_indexable` = `'publish' === status && is_post_type_viewable`; trash keeps the row with `is_indexable = 0`; permanent delete removes the row; override columns are never written here.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Indexable;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Indexable\IndexableSync;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( IndexableSync::class )]
class IndexableSyncTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private array $rebuild_calls = array();
	private IndexableSync $sync;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository    = Mockery::mock( IndexableRepository::class );
		$this->settings      = Mockery::mock( Settings::class );
		$this->rebuild_calls = array();

		$this->sync = new IndexableSync(
			$this->repository,
			$this->settings,
			function (): void {
				$this->rebuild_calls[] = true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_post( int $id, string $type = 'product', string $status = 'publish' ): object {
		$post                    = Mockery::mock( 'WP_Post' );
		$post->ID                = $id;
		$post->post_type         = $type;
		$post->post_status       = $status;
		$post->post_modified_gmt = '2026-07-02 10:00:00';
		return $post;
	}

	public function test_sync_post_upserts_published_enabled_post_as_indexable(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/product/widget/' );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with(
				'post',
				'product',
				88123,
				array(
					'permalink'     => 'https://example.com/product/widget/',
					'is_indexable'  => true,
					'last_modified' => '2026-07-02 10:00:00',
				)
			);

		$this->sync->sync_post( 88123 );
	}

	public function test_sync_post_marks_non_published_as_not_indexable(): void {
		$post = $this->make_post( 88123, 'product', 'draft' );

		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/?p=88123' );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with( 'post', 'product', 88123, Mockery::on( fn( array $f ): bool => false === $f['is_indexable'] ) );

		$this->sync->sync_post( 88123 );
	}

	public function test_sync_post_bails_for_revision(): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 5, 'revision' ) );
		Functions\when( 'wp_is_post_revision' )->justReturn( 4 );

		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->sync->sync_post( 5 );
	}

	public function test_sync_post_bails_for_disabled_post_type(): void {
		Functions\when( 'get_post' )->justReturn( $this->make_post( 5, 'shop_order' ) );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'post', 'product' ) );

		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->sync->sync_post( 5 );
	}

	public function test_handle_trash_post_keeps_row_not_indexable(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );
		$this->settings->shouldReceive( 'get_enabled_post_types' )->andReturn( array( 'product' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with( 'post', 'product', 88123, Mockery::on( fn( array $f ): bool => false === $f['is_indexable'] ) );

		$this->sync->handle_trash_post( 88123 );
	}

	public function test_handle_before_delete_post_deletes_row(): void {
		$post = $this->make_post( 88123 );

		Functions\when( 'get_post' )->justReturn( $post );

		$this->repository->shouldReceive( 'delete' )->once()->with( 'post', 'product', 88123 );

		$this->sync->handle_before_delete_post( 88123 );
	}

	public function test_sync_term_upserts_enabled_taxonomy_term(): void {
		$term                   = Mockery::mock( 'WP_Term' );
		$term->term_id          = 44;
		$term->taxonomy         = 'product_cat';

		Functions\when( 'get_term' )->justReturn( $term );
		Functions\when( 'is_taxonomy_viewable' )->justReturn( true );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.com/product-category/tools/' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category', 'product_cat' ) );

		$this->repository->shouldReceive( 'upsert_synced_fields' )
			->once()
			->with(
				'term',
				'product_cat',
				44,
				Mockery::on(
					fn( array $f ): bool => 'https://example.com/product-category/tools/' === $f['permalink']
						&& true === $f['is_indexable']
				)
			);

		$this->sync->sync_term( 44, 'product_cat' );
	}

	public function test_sync_term_bails_for_disabled_taxonomy(): void {
		$this->settings->shouldReceive( 'get_enabled_taxonomies' )->andReturn( array( 'category' ) );

		$this->repository->shouldNotReceive( 'upsert_synced_fields' );

		$this->sync->sync_term( 44, 'nav_menu' );
	}

	public function test_handle_delete_term_deletes_row(): void {
		$this->repository->shouldReceive( 'delete' )->once()->with( 'term', 'product_cat', 44 );

		$this->sync->handle_delete_term( 44, 99, 'product_cat' );
	}

	public function test_permalink_structure_change_invokes_rebuild_dispatcher(): void {
		$this->sync->handle_permalink_structure_changed();

		$this->assertCount( 1, $this->rebuild_calls );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter IndexableSyncTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Indexable/IndexableSync.php**

```php
<?php
/**
 * Indexable Sync
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Indexable;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;

/**
 * Class IndexableSync
 *
 * Keeps wp_taseo_indexables true to wp_posts/wp_terms. Recomputes ONLY the
 * synced columns (permalink, is_indexable, last_modified); override columns
 * belong to the admin and are never written here.
 */
class IndexableSync {

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository         Repository.
	 * @param Settings            $settings           Settings.
	 * @param callable            $rebuild_dispatcher Invoked when a permalink-structure
	 *                                                change requires a full permalink
	 *                                                rebuild (wired to IndexableBackfill
	 *                                                ::dispatch_permalink_rebuild in Task 15).
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings,
		private $rebuild_dispatcher
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'save_post', array( $this, 'handle_save_post' ), 10, 2 );
		$hook_manager->register_action( 'wp_trash_post', array( $this, 'handle_trash_post' ) );
		$hook_manager->register_action( 'before_delete_post', array( $this, 'handle_before_delete_post' ) );
		$hook_manager->register_action( 'created_term', array( $this, 'handle_created_term' ), 10, 3 );
		$hook_manager->register_action( 'edited_term', array( $this, 'handle_edited_term' ), 10, 3 );
		$hook_manager->register_action( 'delete_term', array( $this, 'handle_delete_term' ), 10, 3 );
		$hook_manager->register_action( 'permalink_structure_changed', array( $this, 'handle_permalink_structure_changed' ) );
		$hook_manager->register_action( 'update_option_woocommerce_permalinks', array( $this, 'handle_permalink_structure_changed' ) );
	}

	/**
	 * save_post handler.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function handle_save_post( int $post_id, WP_Post $post ): void {
		$this->sync_post( $post_id );
	}

	/**
	 * Recompute and upsert one post's synced fields.
	 *
	 * Also the unit IndexableBackfill drives during backfill/rescan.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function sync_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
			return;
		}

		$is_indexable = 'publish' === $post->post_status && is_post_type_viewable( $post->post_type );

		$this->repository->upsert_synced_fields(
			'post',
			$post->post_type,
			$post_id,
			array(
				'permalink'     => (string) get_permalink( $post_id ),
				'is_indexable'  => $is_indexable,
				'last_modified' => $post->post_modified_gmt,
			)
		);
	}

	/**
	 * Trash keeps the row, flagged non-indexable, so restore is cheap.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_trash_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
			return;
		}

		$this->repository->upsert_synced_fields(
			'post',
			$post->post_type,
			$post_id,
			array(
				'permalink'     => '',
				'is_indexable'  => false,
				'last_modified' => $post->post_modified_gmt,
			)
		);
	}

	/**
	 * Permanent deletion removes the row outright.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_before_delete_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		$this->repository->delete( 'post', $post->post_type, $post_id );
	}

	/**
	 * created_term handler.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_created_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->sync_term( $term_id, $taxonomy );
	}

	/**
	 * edited_term handler.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_edited_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->sync_term( $term_id, $taxonomy );
	}

	/**
	 * Recompute and upsert one term's synced fields.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function sync_term( int $term_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, $this->settings->get_enabled_taxonomies(), true ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$link = get_term_link( $term, $taxonomy );

		$this->repository->upsert_synced_fields(
			'term',
			$taxonomy,
			$term_id,
			array(
				'permalink'     => is_wp_error( $link ) ? '' : (string) $link,
				'is_indexable'  => is_taxonomy_viewable( $taxonomy ),
				'last_modified' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * delete_term handler — removes the row.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_delete_term( int $term_id, int $tt_id, string $taxonomy ): void {
		$this->repository->delete( 'term', $taxonomy, $term_id );
	}

	/**
	 * Permalink structure changed — every cached permalink is now suspect.
	 * Dispatch the full rebuild chain (never rebuilt inline; see spec
	 * "Background jobs").
	 *
	 * @return void
	 */
	public function handle_permalink_structure_changed(): void {
		call_user_func( $this->rebuild_dispatcher );
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter IndexableSyncTest`
Expected: PASS (10 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Indexable/IndexableSync.php tests/Indexable/IndexableSyncTest.php
git commit -m "feat: add indexable sync with trash/delete semantics and permalink-change dispatch"
```

---

### Task 7: IndexableBackfill — self-chaining Action Scheduler jobs, rescan, permalink rebuild

**Files:**
- Create: `includes/Indexable/IndexableBackfill.php`
- Test: `tests/Indexable/IndexableBackfillTest.php`

**Interfaces:**
- Consumes: `IndexableSync::sync_post()` / `->sync_term()` (Task 6), `Settings` (Task 4), global `$wpdb`; Action Scheduler functions `as_enqueue_async_action()`, `as_has_scheduled_action()`
- Produces: `TheAnother\Plugin\SEO\Indexable\IndexableBackfill`:
  - `->init(HookManager $hook_manager): void` — registers the `taseo_backfill_batch` action handler
  - `->dispatch(string $mode = 'full'): void` — resets progress, enqueues the first job. `$mode` is `'full'` (initial backfill / rescan) or `'permalink'` (fires `taseo_permalinks_rebuilt` on completion)
  - `->process_batch(string $mode): void` — one bounded slice; re-enqueues itself while work remains
  - `->get_progress(): array{phase: string, total: int, processed: int, percentage: float}`
- Constants: `HOOK = 'taseo_backfill_batch'`, `GROUP = 'taseo'`, `BATCH_SIZE = 500`, `PROGRESS_OPTION = 'taseo_backfill_progress'`
- Fires: `do_action( 'taseo_permalinks_rebuilt' )` when a `'permalink'`-mode chain completes (the sitemap module subscribes to this later)

Chain mechanics (spec "Background jobs"): progress option holds `array( 'phase' => 'posts'|'terms', 'last_id' => int )`. Posts phase walks `wp_posts` by ID range for enabled post types; when a batch comes back short, phase flips to `terms`; when terms exhaust, the option is deleted and (permalink mode) the completion action fires. Every batch that *might* leave work re-enqueues `taseo_backfill_batch` — each job is short; the chain is the long-running thing.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter IndexableBackfillTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Indexable/IndexableBackfill.php**

```php
<?php
/**
 * Indexable Backfill
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Indexable;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class IndexableBackfill
 *
 * Mass (re)indexing as a self-chaining Action Scheduler job series. One job
 * processes one bounded ID-range slice and enqueues the next; no single
 * request ever processes the whole catalog. Used for: initial backfill on
 * activation, admin "Rescan everything", and permalink-structure rebuilds.
 */
class IndexableBackfill {

	/**
	 * Action Scheduler hook name.
	 *
	 * @var string
	 */
	public const HOOK = 'taseo_backfill_batch';

	/**
	 * Action Scheduler group.
	 *
	 * @var string
	 */
	public const GROUP = 'taseo';

	/**
	 * Rows per job.
	 *
	 * @var int
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Progress option name.
	 *
	 * @var string
	 */
	public const PROGRESS_OPTION = 'taseo_backfill_progress';

	/**
	 * Constructor.
	 *
	 * @param IndexableSync $sync     Sync (provides the per-object recompute unit).
	 * @param Settings      $settings Settings.
	 */
	public function __construct(
		private readonly IndexableSync $sync,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register the Action Scheduler callback.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( self::HOOK, array( $this, 'handle_batch_action' ) );
	}

	/**
	 * Action Scheduler entry point.
	 *
	 * @param mixed $args Action args (array with 'mode', or the mode string).
	 * @return void
	 */
	public function handle_batch_action( $args = array() ): void {
		$mode = is_array( $args ) ? ( $args['mode'] ?? 'full' ) : (string) $args;

		$this->process_batch( $mode );
	}

	/**
	 * Start (or restart) a chain. No-op if one is already queued.
	 *
	 * @param string $mode 'full' or 'permalink'.
	 * @return void
	 */
	public function dispatch( string $mode = 'full' ): void {
		if ( as_has_scheduled_action( self::HOOK, null, self::GROUP ) ) {
			return;
		}

		update_option(
			self::PROGRESS_OPTION,
			array(
				'phase'   => 'posts',
				'last_id' => 0,
				'mode'    => $mode,
			)
		);

		as_enqueue_async_action( self::HOOK, array( 'mode' => $mode ), self::GROUP );
	}

	/**
	 * Process one slice, then re-enqueue or finish.
	 *
	 * @param string $mode 'full' or 'permalink'.
	 * @return void
	 */
	public function process_batch( string $mode ): void {
		$progress = get_option( self::PROGRESS_OPTION, false );

		if ( ! is_array( $progress ) ) {
			return;
		}

		if ( 'posts' === $progress['phase'] ) {
			$ids = $this->next_post_ids( (int) $progress['last_id'] );

			foreach ( $ids as $post_id ) {
				$this->sync->sync_post( (int) $post_id );
			}

			if ( count( $ids ) < self::BATCH_SIZE ) {
				$progress['phase']   = 'terms';
				$progress['last_id'] = 0;
			} else {
				$progress['last_id'] = (int) max( $ids );
			}

			update_option( self::PROGRESS_OPTION, $progress );
			as_enqueue_async_action( self::HOOK, array( 'mode' => $mode ), self::GROUP );

			return;
		}

		// Terms phase.
		$rows = $this->next_term_rows( (int) $progress['last_id'] );

		foreach ( $rows as $row ) {
			$this->sync->sync_term( (int) $row->term_id, (string) $row->taxonomy );
		}

		if ( count( $rows ) < self::BATCH_SIZE ) {
			delete_option( self::PROGRESS_OPTION );

			if ( 'permalink' === $mode ) {
				/**
				 * Fires when a permalink rebuild chain has refreshed every row.
				 * The sitemap module marks all chunk files dirty on this.
				 *
				 * @since 1.0.0
				 */
				do_action( 'taseo_permalinks_rebuilt' );
			}

			return;
		}

		$last_row            = end( $rows );
		$progress['last_id'] = (int) $last_row->term_id;
		update_option( self::PROGRESS_OPTION, $progress );
		as_enqueue_async_action( self::HOOK, array( 'mode' => $mode ), self::GROUP );
	}

	/**
	 * Next slice of post IDs by ID range (never OFFSET pagination).
	 *
	 * @param int $last_id Last processed post ID.
	 * @return array<int, string> Post IDs.
	 */
	private function next_post_ids( int $last_id ): array {
		global $wpdb;

		$types = $this->settings->get_enabled_post_types();

		if ( array() === $types ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE ID > %d AND post_type IN ({$placeholders}) AND post_status NOT IN ('auto-draft', 'inherit')
				ORDER BY ID ASC
				LIMIT %d",
				array_merge( array( $last_id ), $types, array( self::BATCH_SIZE ) )
			)
		);
		// phpcs:enable

		return is_array( $ids ) ? $ids : array();
	}

	/**
	 * Next slice of term rows by ID range.
	 *
	 * @param int $last_id Last processed term ID.
	 * @return array<int, object> Rows with term_id + taxonomy.
	 */
	private function next_term_rows( int $last_id ): array {
		global $wpdb;

		$taxonomies = $this->settings->get_enabled_taxonomies();

		if ( array() === $taxonomies ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy
				FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				WHERE t.term_id > %d AND tt.taxonomy IN ({$placeholders})
				ORDER BY t.term_id ASC
				LIMIT %d",
				array_merge( array( $last_id ), $taxonomies, array( self::BATCH_SIZE ) )
			)
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Progress for the settings-screen indicator.
	 *
	 * @return array{phase: string, total: int, processed: int, percentage: float} Progress.
	 */
	public function get_progress(): array {
		global $wpdb;

		$progress = get_option( self::PROGRESS_OPTION, false );

		if ( ! is_array( $progress ) ) {
			return array(
				'phase'      => 'idle',
				'total'      => 0,
				'processed'  => 0,
				'percentage' => 100.0,
			);
		}

		$types        = $this->settings->get_enabled_post_types();
		$placeholders = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders})",
				$types
			)
		);
		$done  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND ID <= %d",
				array_merge( $types, array( (int) $progress['last_id'] ) )
			)
		);
		// phpcs:enable

		return array(
			'phase'      => (string) $progress['phase'],
			'total'      => $total,
			'processed'  => 'terms' === $progress['phase'] ? $total : $done,
			'percentage' => $total > 0 ? round( ( ( 'terms' === $progress['phase'] ? $total : $done ) / $total ) * 100, 2 ) : 100.0,
		);
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter IndexableBackfillTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Indexable/IndexableBackfill.php tests/Indexable/IndexableBackfillTest.php
git commit -m "feat: add self-chaining Action Scheduler backfill with rescan and permalink-rebuild modes"
```

---

### Task 8: CurrentContext + MetaOutput — title, description, canonical, robots

**Files:**
- Create: `includes/Meta/CurrentContext.php`
- Create: `includes/Meta/MetaOutput.php`
- Test: `tests/Meta/MetaOutputTest.php`

**Interfaces:**
- Consumes: `IndexableRepository` (Task 3), `Settings` (Task 4), `TemplateResolver` (Task 5), WP conditionals + `get_queried_object()`, `get_permalink()`, `get_bloginfo()`, `get_the_excerpt()`, `wp_strip_all_tags()`, `get_the_terms()`
- Produces: `TheAnother\Plugin\SEO\Meta\CurrentContext`:
  - `->resolve(): ?array{object_type: string, object_subtype: string, object_id: int, row: ?array, vars: array<string, string>, permalink: string}` — inspects the main query once per request (memoized) and returns everything the output classes need; `null` when the request isn't something we manage (e.g. a disabled post type). System pages resolve as `object_type = 'system_page'` with subtype `home`/`search`/`404`/`archive:{post_type}` and `object_id = 0`.
  - `vars` carries the TemplateResolver context: `title`, `sitename`, `tagline`, `sep`, `excerpt`, `primary_category`, `page`, and — only when WooCommerce is active and the object is a product — `price` and `sku`.
- Produces: `TheAnother\Plugin\SEO\Meta\MetaOutput`:
  - `->init(HookManager $hook_manager): void` — registers `pre_get_document_title` (filter), `wp_head` at priority 1 (action), and unhooks core `rel_canonical`
  - `->filter_document_title(string $title): string`
  - `->print_head_tags(): void` — meta description, canonical `<link>`, robots `<meta>`
- Resolution order (spec "Titles & templates"): row override → subtype template via TemplateResolver → the raw object title. Canonical: row `canonical_url` override → live `get_permalink()` (never the cached column). Robots: only printed when at least one override flag is set.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Meta;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;

#[CoversClass( MetaOutput::class )]
#[CoversClass( CurrentContext::class )]
class MetaOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $context;
	private MetaOutput $output;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->context = Mockery::mock( CurrentContext::class );
		$this->output  = new MetaOutput( $this->context, new TemplateResolver() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Helper: a resolved context for a product with optional row overrides.
	 */
	private function product_context( ?array $row, array $vars = array() ): array {
		return array(
			'object_type'    => 'post',
			'object_subtype' => 'product',
			'object_id'      => 88123,
			'row'            => $row,
			'vars'           => array_merge(
				array(
					'title'    => 'Vintage Watch',
					'sitename' => 'Acme Auctions',
					'sep'      => '–',
					'excerpt'  => 'A rare vintage watch.',
				),
				$vars
			),
			'permalink'      => 'https://example.com/product/vintage-watch/',
			'title_template'       => '%%title%% %%sep%% %%sitename%%',
			'description_template' => '%%excerpt%%',
		);
	}

	public function test_title_uses_row_override_when_set(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn(
			$this->product_context( array( 'title' => 'Hand-tuned Title', 'description' => null ) )
		);

		$this->assertSame( 'Hand-tuned Title', $this->output->filter_document_title( 'WP Default' ) );
	}

	public function test_title_resolves_template_when_no_override(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->product_context( null ) );

		$this->assertSame( 'Vintage Watch – Acme Auctions', $this->output->filter_document_title( 'WP Default' ) );
	}

	public function test_title_passes_through_when_context_unmanaged(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		$this->assertSame( 'WP Default', $this->output->filter_document_title( 'WP Default' ) );
	}

	public function test_head_tags_print_description_canonical_from_live_permalink(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->product_context( null ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		ob_start();
		$this->output->print_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta name="description" content="A rare vintage watch." />', $html );
		$this->assertStringContainsString( '<link rel="canonical" href="https://example.com/product/vintage-watch/" />', $html );
		$this->assertStringNotContainsString( 'robots', $html );
	}

	public function test_head_tags_use_canonical_override_and_robots_flags(): void {
		$row = array(
			'title'            => null,
			'description'      => null,
			'canonical_url'    => 'https://example.com/preferred/',
			'robots_noindex'   => '1',
			'robots_nofollow'  => '1',
			'robots_noarchive' => null,
		);
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->product_context( $row ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		ob_start();
		$this->output->print_head_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<link rel="canonical" href="https://example.com/preferred/" />', $html );
		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow" />', $html );
	}

	public function test_head_tags_print_nothing_when_context_unmanaged(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		ob_start();
		$this->output->print_head_tags();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_description_override_wins_over_template(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn(
			$this->product_context( array( 'title' => null, 'description' => 'Hand-written description.' ) )
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();

		ob_start();
		$this->output->print_head_tags();

		$this->assertStringContainsString( 'content="Hand-written description."', ob_get_clean() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter MetaOutputTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create includes/Meta/CurrentContext.php**

```php
<?php
/**
 * Current Request Context
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;
use WP_Term;

/**
 * Class CurrentContext
 *
 * Resolves the current main query into the one array every output class
 * consumes: object identity, its indexable row (overrides), template
 * variables, live permalink, and the applicable templates. Memoized —
 * MetaOutput, SocialOutput, and SchemaOutput all share a single resolution
 * per request.
 */
class CurrentContext {

	/**
	 * Memoized resolution (false = not yet resolved; null = unmanaged request).
	 *
	 * @var array<string, mixed>|null|false
	 */
	private array|null|false $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository Repository.
	 * @param Settings            $settings   Settings.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings
	) {
	}

	/**
	 * Resolve the current request.
	 *
	 * @return array<string, mixed>|null Context array, or null if unmanaged.
	 */
	public function resolve(): ?array {
		if ( false !== $this->resolved ) {
			return $this->resolved;
		}

		$this->resolved = $this->do_resolve();

		return $this->resolved;
	}

	/**
	 * Uncached resolution.
	 *
	 * @return array<string, mixed>|null Context array, or null if unmanaged.
	 */
	private function do_resolve(): ?array {
		if ( is_singular() ) {
			$post = get_queried_object();

			if ( ! $post instanceof WP_Post
				|| ! in_array( $post->post_type, $this->settings->get_enabled_post_types(), true ) ) {
				return null;
			}

			return $this->build( 'post', $post->post_type, (int) $post->ID, $this->post_vars( $post ), (string) get_permalink( $post ) );
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();

			if ( ! $term instanceof WP_Term
				|| ! in_array( $term->taxonomy, $this->settings->get_enabled_taxonomies(), true ) ) {
				return null;
			}

			$link = get_term_link( $term );

			return $this->build( 'term', $term->taxonomy, (int) $term->term_id, $this->term_vars( $term ), is_wp_error( $link ) ? '' : (string) $link );
		}

		if ( is_front_page() || is_home() ) {
			return $this->build( 'system_page', 'home', 0, $this->site_vars(), (string) home_url( '/' ) );
		}

		if ( is_search() ) {
			return $this->build( 'system_page', 'search', 0, array_merge( $this->site_vars(), array( 'title' => (string) get_search_query() ) ), '' );
		}

		if ( is_404() ) {
			return $this->build( 'system_page', '404', 0, $this->site_vars(), '' );
		}

		if ( is_post_type_archive() ) {
			$post_type = (string) get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : $post_type;

			$archive_link = get_post_type_archive_link( $post_type );

			return $this->build(
				'system_page',
				'archive:' . $post_type,
				0,
				array_merge( $this->site_vars(), array( 'title' => (string) post_type_archive_title( '', false ) ) ),
				is_string( $archive_link ) ? $archive_link : ''
			);
		}

		return null;
	}

	/**
	 * Assemble the context array shape shared by all consumers.
	 *
	 * @param string                $object_type    Object type.
	 * @param string                $object_subtype Object subtype.
	 * @param int                   $object_id      Object ID.
	 * @param array<string, string> $vars           Template variables.
	 * @param string                $permalink      Live permalink.
	 * @return array<string, mixed> Context.
	 */
	private function build( string $object_type, string $object_subtype, int $object_id, array $vars, string $permalink ): array {
		return array(
			'object_type'          => $object_type,
			'object_subtype'       => $object_subtype,
			'object_id'            => $object_id,
			'row'                  => $this->repository->find( $object_type, $object_subtype, $object_id ),
			'vars'                 => $vars,
			'permalink'            => $permalink,
			'title_template'       => $this->settings->get_title_template( $object_type, $object_subtype ),
			'description_template' => $this->settings->get_description_template( $object_type, $object_subtype ),
		);
	}

	/**
	 * Site-level variables present in every context.
	 *
	 * @return array<string, string> Variables.
	 */
	private function site_vars(): array {
		return array(
			'title'    => (string) get_bloginfo( 'name' ),
			'sitename' => (string) get_bloginfo( 'name' ),
			'tagline'  => (string) get_bloginfo( 'description' ),
			'sep'      => $this->settings->get_separator(),
			'page'     => (string) ( max( 1, (int) get_query_var( 'paged' ) ) > 1 ? 'Page ' . (int) get_query_var( 'paged' ) : '' ),
		);
	}

	/**
	 * Variables for a post context.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, string> Variables.
	 */
	private function post_vars( WP_Post $post ): array {
		$vars            = $this->site_vars();
		$vars['title']   = (string) get_the_title( $post );
		$vars['excerpt'] = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		$vars['date']    = (string) get_the_date( '', $post );

		$taxonomy = 'product' === $post->post_type ? 'product_cat' : 'category';
		$terms    = get_the_terms( $post, $taxonomy );

		if ( is_array( $terms ) && array() !== $terms ) {
			$vars['primary_category'] = (string) $terms[0]->name;
		}

		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );

			if ( $product ) {
				$vars['price'] = (string) $product->get_price();
				$vars['sku']   = (string) $product->get_sku();
			}
		}

		return $vars;
	}

	/**
	 * Variables for a term context.
	 *
	 * @param WP_Term $term Term.
	 * @return array<string, string> Variables.
	 */
	private function term_vars( WP_Term $term ): array {
		$vars            = $this->site_vars();
		$vars['title']   = (string) $term->name;
		$vars['excerpt'] = wp_strip_all_tags( (string) $term->description );

		return $vars;
	}
}
```

- [ ] **Step 4: Create includes/Meta/MetaOutput.php**

```php
<?php
/**
 * Meta Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Meta;

use TheAnother\Plugin\SEO\HookManager;

/**
 * Class MetaOutput
 *
 * Emits <title>, meta description, canonical, and robots for the current
 * request from its resolved context. Canonical always uses the live
 * permalink (or the admin's canonical_url override) — never the cached
 * permalink column, which exists for bulk consumers only.
 */
class MetaOutput {

	/**
	 * Constructor.
	 *
	 * @param CurrentContext   $context  Current request context.
	 * @param TemplateResolver $resolver Template resolver.
	 */
	public function __construct(
		private readonly CurrentContext $context,
		private readonly TemplateResolver $resolver
	) {
	}

	/**
	 * Register hooks and unhook core's canonical output.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ) );
		$hook_manager->register_action( 'wp_head', array( $this, 'print_head_tags' ), 1 );

		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Resolve the document title.
	 *
	 * @param string $title Incoming title.
	 * @return string Resolved title.
	 */
	public function filter_document_title( string $title ): string {
		$ctx = $this->context->resolve();

		if ( null === $ctx ) {
			return $title;
		}

		return $this->resolve_title( $ctx );
	}

	/**
	 * Print description, canonical, and robots tags.
	 *
	 * @return void
	 */
	public function print_head_tags(): void {
		$ctx = $this->context->resolve();

		if ( null === $ctx ) {
			return;
		}

		$description = $this->resolve_description( $ctx );

		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}

		$canonical = ! empty( $ctx['row']['canonical_url'] ) ? (string) $ctx['row']['canonical_url'] : (string) $ctx['permalink'];

		if ( '' !== $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		}

		$robots = array();

		if ( ! empty( $ctx['row']['robots_noindex'] ) ) {
			$robots[] = 'noindex';
		}
		if ( ! empty( $ctx['row']['robots_nofollow'] ) ) {
			$robots[] = 'nofollow';
		}
		if ( ! empty( $ctx['row']['robots_noarchive'] ) ) {
			$robots[] = 'noarchive';
		}

		if ( array() !== $robots ) {
			echo '<meta name="robots" content="' . esc_attr( implode( ', ', $robots ) ) . '" />' . "\n";
		}
	}

	/**
	 * Title: override → template.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return string Title.
	 */
	public function resolve_title( array $ctx ): string {
		if ( ! empty( $ctx['row']['title'] ) ) {
			return (string) $ctx['row']['title'];
		}

		return $this->resolver->resolve( (string) $ctx['title_template'], $ctx['vars'] );
	}

	/**
	 * Description: override → template.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return string Description.
	 */
	public function resolve_description( array $ctx ): string {
		if ( ! empty( $ctx['row']['description'] ) ) {
			return (string) $ctx['row']['description'];
		}

		return $this->resolver->resolve( (string) $ctx['description_template'], $ctx['vars'] );
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter MetaOutputTest`
Expected: PASS (7 tests). `CurrentContext` is covered here structurally (it's constructor-injected and mocked); its conditional-tag branching is exercised end-to-end in a real WP environment, which unit tests can't reach — keep its methods thin and branch-only for that reason.

- [ ] **Step 6: Run phpcs and commit**

```bash
composer phpcs
git add includes/Meta/CurrentContext.php includes/Meta/MetaOutput.php tests/Meta/MetaOutputTest.php
git commit -m "feat: add request context resolution and title/description/canonical/robots output"
```

---

### Task 9: SocialOutput — Open Graph + Twitter Card (+ WooCommerce product upgrade)

**Files:**
- Create: `includes/Social/SocialOutput.php`
- Test: `tests/Social/SocialOutputTest.php`

**Interfaces:**
- Consumes: `CurrentContext` (Task 8), `MetaOutput::resolve_title()/resolve_description()` (Task 8), `Settings` (Task 4); WP `wp_get_attachment_image_url()`; WC `wc_get_product()` (guarded)
- Produces: `TheAnother\Plugin\SEO\Social\SocialOutput::init(HookManager): void` (hooks `wp_head` priority 2), `->print_tags(): void`
- Output rules (spec "Social" / "Frontend output"): OG and Twitter are independent toggles. Social title/description overrides (`og_title` etc.) fall back to the resolved SEO title/description. Image: per-object override ID → site default ID → nothing. Product pages (WC active): `og:type` = `product` + `product:price:amount` / `product:price:currency` / `og:availability`. Twitter card type: `summary_large_image`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Social;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Social\SocialOutput;

#[CoversClass( SocialOutput::class )]
class SocialOutputTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $context;
	private $settings;
	private SocialOutput $social;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->context  = Mockery::mock( CurrentContext::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'is_open_graph_enabled' )->andReturn( true )->byDefault();
		$this->settings->shouldReceive( 'is_twitter_enabled' )->andReturn( true )->byDefault();
		$this->settings->shouldReceive( 'get_default_social_image_id' )->andReturn( 0 )->byDefault();
		$this->settings->shouldReceive( 'get_facebook_app_id' )->andReturn( '' )->byDefault();
		$this->settings->shouldReceive( 'get_twitter_site' )->andReturn( '' )->byDefault();

		$meta_output = new MetaOutput( $this->context, new TemplateResolver() );

		$this->social = new SocialOutput( $this->context, $meta_output, $this->settings );

		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Auctions' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function page_context( ?array $row = null ): array {
		return array(
			'object_type'          => 'post',
			'object_subtype'       => 'page',
			'object_id'            => 512,
			'row'                  => $row,
			'vars'                 => array( 'title' => 'About Us', 'sitename' => 'Acme Auctions', 'sep' => '–', 'excerpt' => 'Who we are.' ),
			'permalink'            => 'https://example.com/about/',
			'title_template'       => '%%title%% %%sep%% %%sitename%%',
			'description_template' => '%%excerpt%%',
		);
	}

	public function test_prints_open_graph_and_twitter_tags_from_resolved_meta(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $html );
		$this->assertStringContainsString( '<meta property="og:title" content="About Us – Acme Auctions" />', $html );
		$this->assertStringContainsString( '<meta property="og:description" content="Who we are." />', $html );
		$this->assertStringContainsString( '<meta property="og:url" content="https://example.com/about/" />', $html );
		$this->assertStringContainsString( '<meta property="og:site_name" content="Acme Auctions" />', $html );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $html );
		$this->assertStringContainsString( '<meta name="twitter:title" content="About Us – Acme Auctions" />', $html );
	}

	public function test_social_overrides_win_and_image_override_used(): void {
		$row = array(
			'og_title'       => 'Custom OG Title',
			'og_description' => 'Custom OG Desc',
			'og_image_id'    => '77',
		);
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context( $row ) );
		Functions\expect( 'wp_get_attachment_image_url' )->once()->with( 77, 'full' )->andReturn( 'https://example.com/img.jpg' );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'og:title" content="Custom OG Title"', $html );
		$this->assertStringContainsString( 'og:description" content="Custom OG Desc"', $html );
		$this->assertStringContainsString( '<meta property="og:image" content="https://example.com/img.jpg" />', $html );
	}

	public function test_og_disabled_suppresses_og_but_not_twitter(): void {
		$this->settings->shouldReceive( 'is_open_graph_enabled' )->andReturn( false );
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'og:', $html );
		$this->assertStringContainsString( 'twitter:card', $html );
	}

	public function test_nothing_printed_for_unmanaged_context(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		ob_start();
		$this->social->print_tags();

		$this->assertSame( '', ob_get_clean() );
	}

	public function test_product_context_upgrades_og_type_with_price(): void {
		$ctx                   = $this->page_context();
		$ctx['object_subtype'] = 'product';
		$ctx['object_id']      = 88123;
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_price' )->andReturn( '129.00' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		ob_start();
		$this->social->print_tags();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<meta property="og:type" content="product" />', $html );
		$this->assertStringContainsString( '<meta property="product:price:amount" content="129.00" />', $html );
		$this->assertStringContainsString( '<meta property="product:price:currency" content="USD" />', $html );
		$this->assertStringContainsString( '<meta property="og:availability" content="instock" />', $html );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SocialOutputTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Social/SocialOutput.php**

```php
<?php
/**
 * Social Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Social;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SocialOutput
 *
 * Open Graph + Twitter Card tags. OG covers Facebook, LinkedIn, Pinterest,
 * and Instagram link-preview surfaces (there is no instagram:* protocol);
 * the two toggles are independent. WooCommerce products upgrade og:type
 * to product with price/currency/availability.
 */
class SocialOutput {

	/**
	 * Constructor.
	 *
	 * @param CurrentContext $context     Request context.
	 * @param MetaOutput     $meta_output Resolved title/description source.
	 * @param Settings       $settings    Settings.
	 */
	public function __construct(
		private readonly CurrentContext $context,
		private readonly MetaOutput $meta_output,
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
		$hook_manager->register_action( 'wp_head', array( $this, 'print_tags' ), 2 );
	}

	/**
	 * Print all enabled social tags.
	 *
	 * @return void
	 */
	public function print_tags(): void {
		$ctx = $this->context->resolve();

		if ( null === $ctx ) {
			return;
		}

		$title       = $this->meta_output->resolve_title( $ctx );
		$description = $this->meta_output->resolve_description( $ctx );
		$image_url   = $this->resolve_image_url( $ctx );

		if ( $this->settings->is_open_graph_enabled() ) {
			$this->print_open_graph( $ctx, $title, $description, $image_url );
		}

		if ( $this->settings->is_twitter_enabled() ) {
			$this->print_twitter( $ctx, $title, $description, $image_url );
		}
	}

	/**
	 * Open Graph tags.
	 *
	 * @param array<string, mixed> $ctx         Context.
	 * @param string               $title       Resolved title.
	 * @param string               $description Resolved description.
	 * @param string               $image_url   Image URL or ''.
	 * @return void
	 */
	private function print_open_graph( array $ctx, string $title, string $description, string $image_url ): void {
		$og_title       = ! empty( $ctx['row']['og_title'] ) ? (string) $ctx['row']['og_title'] : $title;
		$og_description = ! empty( $ctx['row']['og_description'] ) ? (string) $ctx['row']['og_description'] : $description;

		$product = $this->get_product( $ctx );

		echo '<meta property="og:type" content="' . esc_attr( $product ? 'product' : 'website' ) . '" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '" />' . "\n";

		if ( '' !== $og_description ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_description ) . '" />' . "\n";
		}

		if ( ! empty( $ctx['permalink'] ) ) {
			echo '<meta property="og:url" content="' . esc_url( (string) $ctx['permalink'] ) . '" />' . "\n";
		}

		echo '<meta property="og:site_name" content="' . esc_attr( (string) get_bloginfo( 'name' ) ) . '" />' . "\n";

		if ( '' !== $image_url ) {
			echo '<meta property="og:image" content="' . esc_url( $image_url ) . '" />' . "\n";
		}

		$app_id = $this->settings->get_facebook_app_id();

		if ( '' !== $app_id ) {
			echo '<meta property="fb:app_id" content="' . esc_attr( $app_id ) . '" />' . "\n";
		}

		if ( $product ) {
			echo '<meta property="product:price:amount" content="' . esc_attr( (string) $product->get_price() ) . '" />' . "\n";
			echo '<meta property="product:price:currency" content="' . esc_attr( (string) get_woocommerce_currency() ) . '" />' . "\n";
			echo '<meta property="og:availability" content="' . esc_attr( $product->is_in_stock() ? 'instock' : 'oos' ) . '" />' . "\n";
		}
	}

	/**
	 * Twitter Card tags.
	 *
	 * @param array<string, mixed> $ctx         Context.
	 * @param string               $title       Resolved title.
	 * @param string               $description Resolved description.
	 * @param string               $image_url   Image URL or ''.
	 * @return void
	 */
	private function print_twitter( array $ctx, string $title, string $description, string $image_url ): void {
		$tw_title       = ! empty( $ctx['row']['twitter_title'] ) ? (string) $ctx['row']['twitter_title'] : $title;
		$tw_description = ! empty( $ctx['row']['twitter_description'] ) ? (string) $ctx['row']['twitter_description'] : $description;

		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $tw_title ) . '" />' . "\n";

		if ( '' !== $tw_description ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $tw_description ) . '" />' . "\n";
		}

		$tw_image = ! empty( $ctx['row']['twitter_image_id'] )
			? (string) wp_get_attachment_image_url( (int) $ctx['row']['twitter_image_id'], 'full' )
			: $image_url;

		if ( '' !== $tw_image && false !== $tw_image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $tw_image ) . '" />' . "\n";
		}

		$site = $this->settings->get_twitter_site();

		if ( '' !== $site ) {
			echo '<meta name="twitter:site" content="' . esc_attr( $site ) . '" />' . "\n";
		}
	}

	/**
	 * Image: per-object OG override → site default → ''.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return string URL or ''.
	 */
	private function resolve_image_url( array $ctx ): string {
		$image_id = ! empty( $ctx['row']['og_image_id'] )
			? (int) $ctx['row']['og_image_id']
			: $this->settings->get_default_social_image_id();

		if ( 0 === $image_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $image_id, 'full' );

		return is_string( $url ) ? $url : '';
	}

	/**
	 * WC product for the context, when applicable.
	 *
	 * @param array<string, mixed> $ctx Context.
	 * @return object|null Product or null.
	 */
	private function get_product( array $ctx ): ?object {
		if ( 'product' !== $ctx['object_subtype'] || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( (int) $ctx['object_id'] );

		return $product ? $product : null;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter SocialOutputTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Social/SocialOutput.php tests/Social/SocialOutputTest.php
git commit -m "feat: add Open Graph and Twitter Card output with WooCommerce product upgrade"
```

---

### Task 10: BreadcrumbTrail + BreadcrumbRenderer — trail array, HTML, template tag, shortcode

**Files:**
- Create: `includes/Breadcrumbs/BreadcrumbTrail.php`
- Create: `includes/Breadcrumbs/BreadcrumbRenderer.php`
- Test: `tests/Breadcrumbs/BreadcrumbTrailTest.php`
- Test: `tests/Breadcrumbs/BreadcrumbRendererTest.php`

**Interfaces:**
- Consumes: `IndexableRepository` (Task 3 — `breadcrumb_title` override), `Settings` (Task 4); WP `get_ancestors()`, `get_post_type_archive_link()`, `get_the_terms()`, `get_term_link()`, `home_url()`
- Produces: `TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail::build(): array<int, array{title: string, url: string}>` — home first, current object last; the **single source of truth** consumed by the renderer AND by `SchemaGraph` (Task 12), so the visible trail and `BreadcrumbList` can never drift.
- Produces: `TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbRenderer::render(): string` (HTML `<nav>`), `->init(HookManager): void` — registers the `[taseo_breadcrumbs]` shortcode and defines the `taseo_breadcrumbs()` template tag.
- The template tag is a plain global function declared in the renderer file, guarded by `function_exists`:
  ```php
  function taseo_breadcrumbs(): void { /* echoes BreadcrumbRenderer::render() via the container */ }
  ```

- [ ] **Step 1: Write the failing trail test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( BreadcrumbTrail::class )]
class BreadcrumbTrailTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private BreadcrumbTrail $trail;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->settings   = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'get_breadcrumb_home_label' )->andReturn( 'Home' )->byDefault();
		$this->settings->shouldReceive( 'breadcrumb_include_taxonomy_ancestors' )->andReturn( true )->byDefault();
		$this->repository->shouldReceive( 'find_for_post' )->andReturn( null )->byDefault();

		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );

		$this->trail = new BreadcrumbTrail( $this->repository, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mock_singular_post( int $id, string $type, string $title ): object {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_title  = $title;
		$post->post_parent = 0;

		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $post );
		Functions\when( 'get_the_title' )->alias( fn( $p ) => is_object( $p ) ? $p->post_title : 'Parent Page' );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.com/current/' );

		return $post;
	}

	public function test_simple_page_trail_is_home_then_current(): void {
		$this->mock_singular_post( 512, 'page', 'About Us' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );

		$this->assertSame(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'About Us', 'url' => 'https://example.com/current/' ),
			),
			$this->trail->build()
		);
	}

	public function test_product_trail_includes_archive_and_term_ancestors(): void {
		$this->mock_singular_post( 88123, 'product', 'Vintage Watch' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( 'https://example.com/shop/' );
		Functions\when( 'post_type_archive_title' )->justReturn( 'Shop' );

		$parent_term            = Mockery::mock( 'WP_Term' );
		$parent_term->term_id   = 9;
		$parent_term->name      = 'Watches';
		$parent_term->parent    = 0;
		$leaf_term              = Mockery::mock( 'WP_Term' );
		$leaf_term->term_id     = 10;
		$leaf_term->name        = 'Vintage';
		$leaf_term->parent      = 9;

		Functions\when( 'get_the_terms' )->justReturn( array( $leaf_term ) );
		Functions\when( 'get_term' )->justReturn( $parent_term );
		Functions\when( 'get_term_link' )->alias( fn( $t ) => 9 === $t->term_id ? 'https://example.com/watches/' : 'https://example.com/watches/vintage/' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post_type_object' )->justReturn( (object) array( 'labels' => (object) array( 'name' => 'Products' ) ) );

		$trail = $this->trail->build();

		$this->assertSame( 'Home', $trail[0]['title'] );
		$this->assertSame( 'Shop', $trail[1]['title'] );
		$this->assertSame( 'Watches', $trail[2]['title'] );
		$this->assertSame( 'Vintage', $trail[3]['title'] );
		$this->assertSame( 'Vintage Watch', $trail[4]['title'] );
	}

	public function test_breadcrumb_title_override_replaces_current_title(): void {
		$this->mock_singular_post( 88123, 'page', 'A Very Long Original Product Title' );
		Functions\when( 'get_ancestors' )->justReturn( array() );
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );

		$this->repository->shouldReceive( 'find_for_post' )
			->with( 88123 )
			->andReturn( array( 'breadcrumb_title' => 'Short Title' ) );

		$trail = $this->trail->build();

		$this->assertSame( 'Short Title', end( $trail )['title'] );
	}

	public function test_hierarchical_page_ancestors_appear_in_order(): void {
		$post = $this->mock_singular_post( 30, 'page', 'Grandchild' );
		Functions\when( 'get_ancestors' )->justReturn( array( 20, 10 ) ); // closest first (WP order).
		Functions\when( 'get_post_type_archive_link' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_permalink' )->alias( fn( $p = null ) => is_numeric( $p ) ? "https://example.com/page-{$p}/" : 'https://example.com/current/' );

		$trail  = $this->trail->build();
		$titles = array_column( $trail, 'title' );

		// Home, root ancestor (10), then 20, then current.
		$this->assertSame( 'Home', $titles[0] );
		$this->assertSame( 'https://example.com/page-10/', $trail[1]['url'] );
		$this->assertSame( 'https://example.com/page-20/', $trail[2]['url'] );
		$this->assertSame( 'Grandchild', $titles[3] );
	}

	public function test_non_singular_returns_home_only(): void {
		Functions\when( 'is_singular' )->justReturn( false );

		$this->assertSame(
			array( array( 'title' => 'Home', 'url' => 'https://example.com/' ) ),
			$this->trail->build()
		);
	}
}
```

- [ ] **Step 2: Write the failing renderer test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Breadcrumbs;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbRenderer;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( BreadcrumbRenderer::class )]
class BreadcrumbRendererTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $trail;
	private $settings;
	private BreadcrumbRenderer $renderer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->trail    = Mockery::mock( BreadcrumbTrail::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'get_breadcrumb_separator' )->andReturn( '›' )->byDefault();
		$this->settings->shouldReceive( 'breadcrumb_link_current' )->andReturn( false )->byDefault();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();

		$this->renderer = new BreadcrumbRenderer( $this->trail, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_links_all_but_current_by_default(): void {
		$this->trail->shouldReceive( 'build' )->andReturn(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'Shop', 'url' => 'https://example.com/shop/' ),
				array( 'title' => 'Vintage Watch', 'url' => 'https://example.com/product/vintage-watch/' ),
			)
		);

		$html = $this->renderer->render();

		$this->assertStringContainsString( '<nav class="taseo-breadcrumbs" aria-label="Breadcrumb">', $html );
		$this->assertStringContainsString( '<a href="https://example.com/">Home</a>', $html );
		$this->assertStringContainsString( '<a href="https://example.com/shop/">Shop</a>', $html );
		$this->assertStringContainsString( '<span aria-current="page">Vintage Watch</span>', $html );
		$this->assertStringNotContainsString( '<a href="https://example.com/product/vintage-watch/">', $html );
		$this->assertSame( 2, substr_count( $html, '›' ) );
	}

	public function test_render_links_current_when_setting_enabled(): void {
		$this->settings->shouldReceive( 'breadcrumb_link_current' )->andReturn( true );
		$this->trail->shouldReceive( 'build' )->andReturn(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'About', 'url' => 'https://example.com/about/' ),
			)
		);

		$this->assertStringContainsString(
			'<a href="https://example.com/about/" aria-current="page">About</a>',
			$this->renderer->render()
		);
	}

	public function test_render_empty_trail_returns_empty_string(): void {
		$this->trail->shouldReceive( 'build' )->andReturn( array() );

		$this->assertSame( '', $this->renderer->render() );
	}
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `composer test -- --filter Breadcrumb`
Expected: FAIL — classes not found.

- [ ] **Step 4: Create includes/Breadcrumbs/BreadcrumbTrail.php**

```php
<?php
/**
 * Breadcrumb Trail Builder
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Breadcrumbs;

use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;

/**
 * Class BreadcrumbTrail
 *
 * Builds the one trail array consumed by the HTML renderer, the shortcode,
 * the block, AND SchemaGraph's BreadcrumbList — the single source of truth
 * that keeps visible breadcrumbs and structured data identical.
 */
class BreadcrumbTrail {

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository Repository (breadcrumb_title overrides).
	 * @param Settings            $settings   Settings.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings
	) {
	}

	/**
	 * Build the trail for the current request.
	 *
	 * @return array<int, array{title: string, url: string}> Trail, home first.
	 */
	public function build(): array {
		$trail = array(
			array(
				'title' => $this->settings->get_breadcrumb_home_label(),
				'url'   => (string) home_url( '/' ),
			),
		);

		if ( ! is_singular() ) {
			return $trail;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return $trail;
		}

		// Post type archive.
		$archive_link = get_post_type_archive_link( $post->post_type );

		if ( is_string( $archive_link ) && '' !== $archive_link ) {
			$post_type_object = get_post_type_object( $post->post_type );

			$trail[] = array(
				'title' => $post_type_object ? (string) $post_type_object->labels->name : $post->post_type,
				'url'   => $archive_link,
			);
		}

		// Taxonomy term ancestors (primary term's lineage).
		if ( $this->settings->breadcrumb_include_taxonomy_ancestors() ) {
			foreach ( $this->term_lineage( $post ) as $crumb ) {
				$trail[] = $crumb;
			}
		}

		// Parent page ancestors (root first).
		foreach ( array_reverse( get_ancestors( $post->ID, $post->post_type ) ) as $ancestor_id ) {
			$trail[] = array(
				'title' => (string) get_the_title( $ancestor_id ),
				'url'   => (string) get_permalink( $ancestor_id ),
			);
		}

		// Current object, with breadcrumb_title override.
		$row   = $this->repository->find_for_post( (int) $post->ID );
		$title = ! empty( $row['breadcrumb_title'] ) ? (string) $row['breadcrumb_title'] : (string) get_the_title( $post );

		$trail[] = array(
			'title' => $title,
			'url'   => (string) get_permalink( $post ),
		);

		return $trail;
	}

	/**
	 * The primary term's ancestor chain (root first), then the term itself.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{title: string, url: string}> Crumbs.
	 */
	private function term_lineage( WP_Post $post ): array {
		$taxonomy = 'product' === $post->post_type ? 'product_cat' : 'category';
		$terms    = get_the_terms( $post, $taxonomy );

		if ( ! is_array( $terms ) || array() === $terms ) {
			return array();
		}

		$term    = $terms[0];
		$lineage = array();

		// Walk up via parent pointers.
		$cursor = $term;

		while ( $cursor ) {
			$link = get_term_link( $cursor );

			if ( ! is_wp_error( $link ) ) {
				array_unshift(
					$lineage,
					array(
						'title' => (string) $cursor->name,
						'url'   => (string) $link,
					)
				);
			}

			$cursor = $cursor->parent ? get_term( $cursor->parent, $taxonomy ) : null;

			if ( is_wp_error( $cursor ) ) {
				$cursor = null;
			}
		}

		return $lineage;
	}
}
```

- [ ] **Step 5: Create includes/Breadcrumbs/BreadcrumbRenderer.php**

```php
<?php
/**
 * Breadcrumb Renderer
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Breadcrumbs;

use TheAnother\Plugin\SEO\Container;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class BreadcrumbRenderer
 *
 * Renders the trail as an accessible <nav>. Exposed three ways — template
 * tag, shortcode, block (blocks/breadcrumbs/render.php) — all through this
 * one render() method.
 */
class BreadcrumbRenderer {

	/**
	 * Constructor.
	 *
	 * @param BreadcrumbTrail $trail    Trail builder.
	 * @param Settings        $settings Settings.
	 */
	public function __construct(
		private readonly BreadcrumbTrail $trail,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register the shortcode.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		add_shortcode( 'taseo_breadcrumbs', array( $this, 'render' ) );
	}

	/**
	 * Render the trail as HTML.
	 *
	 * @return string HTML, '' when the trail is empty.
	 */
	public function render(): string {
		$crumbs = $this->trail->build();

		if ( array() === $crumbs ) {
			return '';
		}

		$separator    = $this->settings->get_breadcrumb_separator();
		$link_current = $this->settings->breadcrumb_link_current();
		$last_index   = count( $crumbs ) - 1;
		$parts        = array();

		foreach ( $crumbs as $index => $crumb ) {
			$is_current = ( $index === $last_index );

			if ( $is_current && ! $link_current ) {
				$parts[] = '<span aria-current="page">' . esc_html( $crumb['title'] ) . '</span>';
			} elseif ( $is_current ) {
				$parts[] = '<a href="' . esc_url( $crumb['url'] ) . '" aria-current="page">' . esc_html( $crumb['title'] ) . '</a>';
			} else {
				$parts[] = '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['title'] ) . '</a>';
			}
		}

		return '<nav class="taseo-breadcrumbs" aria-label="Breadcrumb">'
			. implode( ' <span class="taseo-breadcrumbs__sep" aria-hidden="true">' . esc_html( $separator ) . '</span> ', $parts )
			. '</nav>';
	}
}

if ( ! function_exists( 'taseo_breadcrumbs' ) ) {
	/**
	 * Template tag: echo the breadcrumb trail.
	 *
	 * @return void
	 */
	function taseo_breadcrumbs(): void {
		echo Container::get_instance()->get( 'breadcrumb_renderer' )->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes internally.
	}
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `composer test -- --filter Breadcrumb`
Expected: PASS (8 tests across both files).

- [ ] **Step 7: Run phpcs and commit**

```bash
composer phpcs
git add includes/Breadcrumbs/ tests/Breadcrumbs/
git commit -m "feat: add breadcrumb trail builder and renderer with template tag and shortcode"
```

---

### Task 11: Breadcrumbs Gutenberg block — build tooling + dynamic block

**Files:**
- Create: `package.json`
- Create: `blocks/breadcrumbs/block.json`
- Create: `blocks/breadcrumbs/index.js`
- Create: `blocks/breadcrumbs/render.php`
- Create: `includes/Blocks.php`
- Test: (no PHPUnit test file — `Blocks` is a two-line registration wrapper; verification is `npm run build` succeeding plus the lint step. The render callback delegates to `BreadcrumbRenderer::render()`, already covered by Task 10's tests.)

**Interfaces:**
- Consumes: `BreadcrumbRenderer` (Task 10) via the container key `breadcrumb_renderer` (registered in Task 15)
- Produces: `TheAnother\Plugin\SEO\Blocks::init(HookManager): void` — registers block type from `blocks/breadcrumbs` metadata on WP `init`
- Produces: block `the-another/seo-breadcrumbs` (dynamic; `render.php` server-side)

- [ ] **Step 1: Create package.json**

```json
{
  "name": "the-another-seo",
  "version": "0.1.0",
  "description": "Blocks for The Another SEO.",
  "license": "GPL-2.0-or-later",
  "scripts": {
    "build": "wp-scripts build blocks/breadcrumbs/index.js --output-path=dist/breadcrumbs",
    "start": "wp-scripts start blocks/breadcrumbs/index.js --output-path=dist/breadcrumbs",
    "lint:js": "wp-scripts lint-js blocks"
  },
  "devDependencies": {
    "@wordpress/scripts": "^30.0.0"
  }
}
```

- [ ] **Step 2: Create blocks/breadcrumbs/block.json**

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "the-another/seo-breadcrumbs",
  "title": "Breadcrumbs (The Another SEO)",
  "category": "widgets",
  "icon": "ellipsis",
  "description": "Displays the breadcrumb trail for the current page. Matches the BreadcrumbList structured data exactly.",
  "textdomain": "the-another-seo",
  "supports": {
    "html": false,
    "spacing": {
      "margin": true,
      "padding": true
    },
    "typography": {
      "fontSize": true
    }
  },
  "editorScript": "file:../../dist/breadcrumbs/index.js",
  "render": "file:./render.php"
}
```

- [ ] **Step 3: Create blocks/breadcrumbs/index.js**

```javascript
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: () => {
		const blockProps = useBlockProps();

		return (
			<nav { ...blockProps } className="taseo-breadcrumbs" aria-label="Breadcrumb">
				<a href="#home" onClick={ ( e ) => e.preventDefault() }>
					{ __( 'Home', 'the-another-seo' ) }
				</a>
				{ ' › ' }
				<a href="#section" onClick={ ( e ) => e.preventDefault() }>
					{ __( 'Section', 'the-another-seo' ) }
				</a>
				{ ' › ' }
				<span aria-current="page">{ __( 'Current page', 'the-another-seo' ) }</span>
			</nav>
		);
	},
	save: () => null,
} );
```

(The editor preview is static placeholder content — the real trail depends on the front-end request and renders server-side.)

- [ ] **Step 4: Create blocks/breadcrumbs/render.php**

```php
<?php
/**
 * Breadcrumbs block render callback.
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$taseo_renderer = \TheAnother\Plugin\SEO\Container::get_instance()->get( 'breadcrumb_renderer' );
$taseo_html     = $taseo_renderer->render();

if ( '' === $taseo_html ) {
	return;
}

?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated attributes. ?>>
	<?php echo $taseo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes internally. ?>
</div>
<?php
```

- [ ] **Step 5: Create includes/Blocks.php**

```php
<?php
/**
 * Block Registration
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

/**
 * Class Blocks
 *
 * Registers the plugin's block types from their block.json metadata.
 */
class Blocks {

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'init', array( $this, 'register_blocks' ) );
	}

	/**
	 * Register block types.
	 *
	 * @return void
	 */
	public function register_blocks(): void {
		register_block_type( THE_ANOTHER_SEO_PLUGIN_DIR . 'blocks/breadcrumbs' );
	}
}
```

- [ ] **Step 6: Install and build**

Run: `npm install && npm run build`
Expected: `dist/breadcrumbs/index.js` (+ asset file) produced, zero build errors.

- [ ] **Step 7: Lint and commit**

```bash
npm run lint:js
composer phpcs
git add package.json blocks/ includes/Blocks.php
git commit -m "feat: add breadcrumbs Gutenberg block with server-side rendering"
```

(Don't commit `node_modules/` or `dist/` — add a `.gitignore` with `node_modules/`, `dist/`, `vendor/`, `.phpunit.cache/` in this commit if one doesn't exist yet.)

---

### Task 12: SchemaGraph + SchemaOutput — JSON-LD @graph

**Files:**
- Create: `includes/Schema/SchemaGraph.php`
- Create: `includes/Schema/SchemaOutput.php`
- Test: `tests/Schema/SchemaGraphTest.php`

**Interfaces:**
- Consumes: `CurrentContext` (Task 8), `BreadcrumbTrail` (Task 10), `Settings` (Task 4), `MetaOutput::resolve_title()/resolve_description()` (Task 8); WP `home_url()`, `get_bloginfo()`, `wp_get_attachment_image_url()`, `get_the_date()`, `get_the_modified_date()`, `get_the_author_meta()`; WC `wc_get_product()` (guarded)
- Produces: `TheAnother\Plugin\SEO\Schema\SchemaGraph::build(): array` — the full `@graph` node list (empty array when the row has `schema_disabled` or the context is unmanaged)
- Produces: `TheAnother\Plugin\SEO\Schema\SchemaOutput::init(HookManager): void` (hooks `wp_head` priority 3), `->print_json_ld(): void` — one `<script type="application/ld+json">` wrapping `{"@context": "https://schema.org", "@graph": [...]}` via `wp_json_encode`
- Node structure (spec "Structured data"): `WebSite` (`@id` `{home}#website`), `Organization`/`Person` (`@id` `{home}#identity`, referenced by `WebSite.publisher`), `WebPage` (`@id` `{permalink}#webpage`, `isPartOf` → website), `BreadcrumbList` (`@id` `{permalink}#breadcrumb`, built from `BreadcrumbTrail::build()`), plus per-type: `Article` (headline, datePublished, dateModified, author) or `Product` (name, sku, offers) linked via `WebPage.mainEntity` — or nothing extra when the schema type is `WebPage`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Schema;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Schema\SchemaGraph;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( SchemaGraph::class )]
class SchemaGraphTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $context;
	private $trail;
	private $settings;
	private SchemaGraph $graph;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->context  = Mockery::mock( CurrentContext::class );
		$this->trail    = Mockery::mock( BreadcrumbTrail::class );
		$this->settings = Mockery::mock( Settings::class );

		$this->settings->shouldReceive( 'get_site_represents' )->andReturn( 'organization' )->byDefault();
		$this->settings->shouldReceive( 'get_site_represents_name' )->andReturn( 'Acme Auctions' )->byDefault();
		$this->settings->shouldReceive( 'get_site_logo_id' )->andReturn( 0 )->byDefault();
		$this->settings->shouldReceive( 'get_same_as_urls' )->andReturn( array() )->byDefault();
		$this->settings->shouldReceive( 'get_schema_type' )->andReturn( 'WebPage' )->byDefault();

		$this->trail->shouldReceive( 'build' )->andReturn(
			array(
				array( 'title' => 'Home', 'url' => 'https://example.com/' ),
				array( 'title' => 'About', 'url' => 'https://example.com/about/' ),
			)
		)->byDefault();

		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme Auctions' );

		$meta_output = new MetaOutput( $this->context, new TemplateResolver() );
		$this->graph = new SchemaGraph( $this->context, $meta_output, $this->trail, $this->settings );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function page_context( ?array $row = null ): array {
		return array(
			'object_type'          => 'post',
			'object_subtype'       => 'page',
			'object_id'            => 512,
			'row'                  => $row,
			'vars'                 => array( 'title' => 'About Us', 'sitename' => 'Acme Auctions', 'sep' => '–', 'excerpt' => 'Who we are.' ),
			'permalink'            => 'https://example.com/about/',
			'title_template'       => '%%title%%',
			'description_template' => '%%excerpt%%',
		);
	}

	public function test_graph_contains_website_identity_webpage_breadcrumb_nodes(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		$graph = $this->graph->build();
		$types = array_column( $graph, '@type' );

		$this->assertContains( 'WebSite', $types );
		$this->assertContains( 'Organization', $types );
		$this->assertContains( 'WebPage', $types );
		$this->assertContains( 'BreadcrumbList', $types );
	}

	public function test_breadcrumb_list_mirrors_trail_positions(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( $this->page_context() );

		$graph      = $this->graph->build();
		$breadcrumb = null;

		foreach ( $graph as $node ) {
			if ( 'BreadcrumbList' === $node['@type'] ) {
				$breadcrumb = $node;
			}
		}

		$this->assertNotNull( $breadcrumb );
		$this->assertCount( 2, $breadcrumb['itemListElement'] );
		$this->assertSame( 1, $breadcrumb['itemListElement'][0]['position'] );
		$this->assertSame( 'Home', $breadcrumb['itemListElement'][0]['name'] );
		$this->assertSame( 'https://example.com/about/', $breadcrumb['itemListElement'][1]['item'] );
	}

	public function test_schema_disabled_row_yields_empty_graph(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn(
			$this->page_context( array( 'schema_disabled' => '1' ) )
		);

		$this->assertSame( array(), $this->graph->build() );
	}

	public function test_unmanaged_context_yields_empty_graph(): void {
		$this->context->shouldReceive( 'resolve' )->andReturn( null );

		$this->assertSame( array(), $this->graph->build() );
	}

	public function test_article_type_adds_article_node_as_main_entity(): void {
		$this->settings->shouldReceive( 'get_schema_type' )->with( 'post' )->andReturn( 'Article' );

		$ctx                   = $this->page_context();
		$ctx['object_subtype'] = 'post';
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		Functions\when( 'get_the_date' )->justReturn( '2026-07-01' );
		Functions\when( 'get_the_modified_date' )->justReturn( '2026-07-02' );
		Functions\when( 'get_post_field' )->justReturn( '3' );
		Functions\when( 'get_the_author_meta' )->justReturn( 'Jane Editor' );

		$graph = $this->graph->build();
		$types = array_column( $graph, '@type' );

		$this->assertContains( 'Article', $types );

		foreach ( $graph as $node ) {
			if ( 'Article' === $node['@type'] ) {
				$this->assertSame( 'About Us', $node['headline'] );
				$this->assertSame( '2026-07-01', $node['datePublished'] );
			}
			if ( 'WebPage' === $node['@type'] ) {
				$this->assertArrayHasKey( 'mainEntity', $node );
			}
		}
	}

	public function test_product_type_adds_product_node_with_offer(): void {
		$this->settings->shouldReceive( 'get_schema_type' )->with( 'product' )->andReturn( 'Product' );

		$ctx                   = $this->page_context();
		$ctx['object_subtype'] = 'product';
		$ctx['object_id']      = 88123;
		$this->context->shouldReceive( 'resolve' )->andReturn( $ctx );

		$product = Mockery::mock( 'WC_Product' );
		$product->shouldReceive( 'get_sku' )->andReturn( 'VW-1' );
		$product->shouldReceive( 'get_price' )->andReturn( '129.00' );
		$product->shouldReceive( 'is_in_stock' )->andReturn( true );

		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );

		$graph = $this->graph->build();

		foreach ( $graph as $node ) {
			if ( 'Product' === $node['@type'] ) {
				$this->assertSame( 'VW-1', $node['sku'] );
				$this->assertSame( '129.00', $node['offers']['price'] );
				$this->assertSame( 'https://schema.org/InStock', $node['offers']['availability'] );
				return;
			}
		}

		$this->fail( 'No Product node found.' );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SchemaGraphTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Schema/SchemaGraph.php**

```php
<?php
/**
 * Schema Graph Builder
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Schema;

use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SchemaGraph
 *
 * Builds one interlinked @graph per page: WebSite, Organization/Person,
 * WebPage, BreadcrumbList (from the SAME trail the visible breadcrumbs
 * render), and an Article/Product main entity per the configured type.
 */
class SchemaGraph {

	/**
	 * Constructor.
	 *
	 * @param CurrentContext  $context     Request context.
	 * @param MetaOutput      $meta_output Resolved title/description source.
	 * @param BreadcrumbTrail $trail       Trail builder.
	 * @param Settings        $settings    Settings.
	 */
	public function __construct(
		private readonly CurrentContext $context,
		private readonly MetaOutput $meta_output,
		private readonly BreadcrumbTrail $trail,
		private readonly Settings $settings
	) {
	}

	/**
	 * Build the @graph node list.
	 *
	 * @return array<int, array<string, mixed>> Nodes; empty when disabled/unmanaged.
	 */
	public function build(): array {
		$ctx = $this->context->resolve();

		if ( null === $ctx || ! empty( $ctx['row']['schema_disabled'] ) ) {
			return array();
		}

		$home        = (string) home_url( '/' );
		$website_id  = $home . '#website';
		$identity_id = $home . '#identity';
		$permalink   = '' !== (string) $ctx['permalink'] ? (string) $ctx['permalink'] : $home;
		$webpage_id  = $permalink . '#webpage';

		$graph   = array();
		$graph[] = $this->identity_node( $identity_id );
		$graph[] = array(
			'@type'     => 'WebSite',
			'@id'       => $website_id,
			'url'       => $home,
			'name'      => (string) get_bloginfo( 'name' ),
			'publisher' => array( '@id' => $identity_id ),
		);

		$webpage = array(
			'@type'      => 'WebPage',
			'@id'        => $webpage_id,
			'url'        => $permalink,
			'name'       => $this->meta_output->resolve_title( $ctx ),
			'isPartOf'   => array( '@id' => $website_id ),
			'breadcrumb' => array( '@id' => $permalink . '#breadcrumb' ),
		);

		$description = $this->meta_output->resolve_description( $ctx );

		if ( '' !== $description ) {
			$webpage['description'] = $description;
		}

		$main_entity = $this->main_entity_node( $ctx, $webpage_id );

		if ( null !== $main_entity ) {
			$webpage['mainEntity'] = array( '@id' => $main_entity['@id'] );
		}

		$graph[] = $webpage;
		$graph[] = $this->breadcrumb_node( $permalink );

		if ( null !== $main_entity ) {
			$graph[] = $main_entity;
		}

		return $graph;
	}

	/**
	 * Organization or Person node.
	 *
	 * @param string $identity_id Node @id.
	 * @return array<string, mixed> Node.
	 */
	private function identity_node( string $identity_id ): array {
		$node = array(
			'@type' => 'person' === $this->settings->get_site_represents() ? 'Person' : 'Organization',
			'@id'   => $identity_id,
			'name'  => $this->settings->get_site_represents_name(),
		);

		$logo_id = $this->settings->get_site_logo_id();

		if ( $logo_id > 0 ) {
			$logo_url = wp_get_attachment_image_url( $logo_id, 'full' );

			if ( is_string( $logo_url ) ) {
				$node['logo'] = $logo_url;
			}
		}

		$same_as = $this->settings->get_same_as_urls();

		if ( array() !== $same_as ) {
			$node['sameAs'] = $same_as;
		}

		return $node;
	}

	/**
	 * BreadcrumbList node built from the shared trail.
	 *
	 * @param string $permalink Current permalink.
	 * @return array<string, mixed> Node.
	 */
	private function breadcrumb_node( string $permalink ): array {
		$items = array();

		foreach ( $this->trail->build() as $index => $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $crumb['title'],
				'item'     => $crumb['url'],
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $permalink . '#breadcrumb',
			'itemListElement' => $items,
		);
	}

	/**
	 * Article/Product main entity per the configured schema type.
	 *
	 * @param array<string, mixed> $ctx        Context.
	 * @param string               $webpage_id WebPage node @id.
	 * @return array<string, mixed>|null Node or null for plain WebPage types.
	 */
	private function main_entity_node( array $ctx, string $webpage_id ): ?array {
		if ( 'post' !== $ctx['object_type'] ) {
			return null;
		}

		$type      = $this->settings->get_schema_type( (string) $ctx['object_subtype'] );
		$permalink = (string) $ctx['permalink'];

		if ( 'Article' === $type ) {
			$node = array(
				'@type'         => 'Article',
				'@id'           => $permalink . '#article',
				'headline'      => (string) ( $ctx['vars']['title'] ?? '' ),
				'datePublished' => (string) get_the_date( 'c', (int) $ctx['object_id'] ),
				'dateModified'  => (string) get_the_modified_date( 'c', (int) $ctx['object_id'] ),
				'mainEntityOfPage' => array( '@id' => $webpage_id ),
			);

			$author_id = (int) get_post_field( 'post_author', (int) $ctx['object_id'] );

			if ( $author_id > 0 ) {
				$node['author'] = array(
					'@type' => 'Person',
					'name'  => (string) get_the_author_meta( 'display_name', $author_id ),
				);
			}

			return $node;
		}

		if ( 'Product' === $type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $ctx['object_id'] );

			if ( ! $product ) {
				return null;
			}

			return array(
				'@type'  => 'Product',
				'@id'    => $permalink . '#product',
				'name'   => (string) ( $ctx['vars']['title'] ?? '' ),
				'sku'    => (string) $product->get_sku(),
				'offers' => array(
					'@type'         => 'Offer',
					'url'           => $permalink,
					'price'         => (string) $product->get_price(),
					'priceCurrency' => (string) get_woocommerce_currency(),
					'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
				),
			);
		}

		return null;
	}
}
```

- [ ] **Step 4: Create includes/Schema/SchemaOutput.php**

```php
<?php
/**
 * Schema Output
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Schema;

use TheAnother\Plugin\SEO\HookManager;

/**
 * Class SchemaOutput
 *
 * Prints the single JSON-LD @graph script.
 */
class SchemaOutput {

	/**
	 * Constructor.
	 *
	 * @param SchemaGraph $graph Graph builder.
	 */
	public function __construct( private readonly SchemaGraph $graph ) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'wp_head', array( $this, 'print_json_ld' ), 3 );
	}

	/**
	 * Print the JSON-LD script when the graph is non-empty.
	 *
	 * @return void
	 */
	public function print_json_ld(): void {
		$nodes = $this->graph->build();

		if ( array() === $nodes ) {
			return;
		}

		$payload = array(
			'@context' => 'https://schema.org',
			'@graph'   => $nodes,
		);

		echo '<script type="application/ld+json">'
			. wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside script tag.
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `composer test -- --filter SchemaGraphTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Run phpcs and commit**

```bash
composer phpcs
git add includes/Schema/ tests/Schema/
git commit -m "feat: add Schema.org JSON-LD graph with breadcrumb parity and Article/Product entities"
```

---

### Task 13: Metabox — post + term override fields and save

**Files:**
- Create: `includes/Admin/Metabox.php`
- Test: `tests/Admin/MetaboxTest.php`

**Interfaces:**
- Consumes: `IndexableRepository::save_overrides()` + `OVERRIDE_COLUMNS` (Task 3), `Settings` (Task 4)
- Produces: `TheAnother\Plugin\SEO\Admin\Metabox`:
  - `->init(HookManager): void` — `add_meta_boxes` (posts), `{$taxonomy}_edit_form_fields` render + `edited_term` save for each enabled taxonomy (registered lazily on `admin_init`), `save_post` for post saves
  - `->render_post_metabox(\WP_Post $post): void`, `->render_term_fields(\WP_Term $term): void`
  - `->handle_save_post(int $post_id): void`, `->handle_save_term(int $term_id, int $tt_id, string $taxonomy): void`
  - `->sanitize_submission(array $raw): array` — the pure, tested unit: maps `$_POST['taseo_meta']` to override columns with correct sanitizers (text → `sanitize_text_field`, descriptions → `sanitize_textarea_field`, canonical → `esc_url_raw`, image IDs → `absint`-or-`''`, checkboxes → `'1'`/`''`)
- Save guards: nonce `taseo_meta_nonce` (action `taseo_save_meta`), `current_user_can( 'edit_post', $post_id )` / `current_user_can( 'manage_categories' )`, bail on autosave. Empty string → NULL ("no override") happens inside `save_overrides` (Task 3).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Admin\Metabox;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( Metabox::class )]
class MetaboxTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $repository;
	private $settings;
	private Metabox $metabox;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->repository = Mockery::mock( IndexableRepository::class );
		$this->settings   = Mockery::mock( Settings::class );

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$this->metabox = new Metabox( $this->repository, $this->settings );
	}

	protected function tearDown(): void {
		unset( $_POST['taseo_meta'], $_POST['taseo_meta_nonce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_submission_maps_fields_with_correct_sanitizers(): void {
		$clean = $this->metabox->sanitize_submission(
			array(
				'title'            => 'Custom Title',
				'description'      => "Multi line\ndescription",
				'canonical_url'    => 'https://example.com/canonical/',
				'og_image_id'      => '77',
				'robots_noindex'   => '1',
				'schema_disabled'  => '1',
				'breadcrumb_title' => 'Short',
				'unknown_field'    => 'dropped',
			)
		);

		$this->assertSame( 'Custom Title', $clean['title'] );
		$this->assertSame( "Multi line\ndescription", $clean['description'] );
		$this->assertSame( 'https://example.com/canonical/', $clean['canonical_url'] );
		$this->assertSame( 77, $clean['og_image_id'] );
		$this->assertSame( '1', $clean['robots_noindex'] );
		$this->assertSame( '1', $clean['schema_disabled'] );
		$this->assertSame( 'Short', $clean['breadcrumb_title'] );
		$this->assertArrayNotHasKey( 'unknown_field', $clean );
	}

	public function test_sanitize_submission_unchecked_boxes_and_blanks_become_empty_string(): void {
		$clean = $this->metabox->sanitize_submission( array( 'title' => '' ) );

		$this->assertSame( '', $clean['title'] );
		$this->assertSame( '', $clean['robots_noindex'] ); // absent checkbox.
		$this->assertSame( '', $clean['og_image_id'] );    // absent ID.
	}

	public function test_handle_save_post_persists_overrides_on_valid_request(): void {
		$_POST['taseo_meta_nonce'] = 'nonce-value';
		$_POST['taseo_meta']       = array( 'title' => 'Custom' );

		Functions\expect( 'wp_verify_nonce' )->once()->with( 'nonce-value', 'taseo_save_meta' )->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 88123 )->andReturn( true );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();

		$post            = Mockery::mock( 'WP_Post' );
		$post->post_type = 'product';
		Functions\when( 'get_post' )->justReturn( $post );

		$this->repository->shouldReceive( 'save_overrides' )
			->once()
			->with( 'post', 'product', 88123, Mockery::on( fn( array $o ): bool => 'Custom' === $o['title'] ) );

		$this->metabox->handle_save_post( 88123 );
	}

	public function test_handle_save_post_bails_without_nonce(): void {
		$this->repository->shouldNotReceive( 'save_overrides' );

		$this->metabox->handle_save_post( 88123 );
	}

	public function test_handle_save_post_bails_when_nonce_invalid(): void {
		$_POST['taseo_meta_nonce'] = 'bad';
		$_POST['taseo_meta']       = array( 'title' => 'X' );

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( false );

		$this->repository->shouldNotReceive( 'save_overrides' );

		$this->metabox->handle_save_post( 88123 );
	}

	public function test_handle_save_term_persists_with_taxonomy_capability(): void {
		$_POST['taseo_meta_nonce'] = 'nonce-value';
		$_POST['taseo_meta']       = array( 'title' => 'Term Title' );

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_categories' )->andReturn( true );
		Functions\when( 'wp_unslash' )->returnArg();

		$this->repository->shouldReceive( 'save_overrides' )
			->once()
			->with( 'term', 'product_cat', 44, Mockery::type( 'array' ) );

		$this->metabox->handle_save_term( 44, 99, 'product_cat' );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter MetaboxTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Admin/Metabox.php**

```php
<?php
/**
 * SEO Metabox
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Admin;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;
use WP_Term;

/**
 * Class Metabox
 *
 * Per-object override fields on post-edit and term-edit screens. Values are
 * stored in the indexable row's override columns; blank = "no override".
 */
class Metabox {

	/**
	 * Field => sanitizer map. Order matters for render.
	 *
	 * @var array<string, string>
	 */
	private const FIELDS = array(
		'title'               => 'text',
		'description'         => 'textarea',
		'canonical_url'       => 'url',
		'robots_noindex'      => 'checkbox',
		'robots_nofollow'     => 'checkbox',
		'robots_noarchive'    => 'checkbox',
		'og_title'            => 'text',
		'og_description'      => 'textarea',
		'og_image_id'         => 'image_id',
		'twitter_title'       => 'text',
		'twitter_description' => 'textarea',
		'twitter_image_id'    => 'image_id',
		'breadcrumb_title'    => 'text',
		'schema_disabled'     => 'checkbox',
	);

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository Repository.
	 * @param Settings            $settings   Settings.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
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
		$hook_manager->register_action( 'add_meta_boxes', array( $this, 'register_post_metabox' ) );
		$hook_manager->register_action( 'save_post', array( $this, 'handle_save_post' ), 20 );
		$hook_manager->register_action( 'edited_term', array( $this, 'handle_save_term' ), 20, 3 );

		$hook_manager->register_action(
			'admin_init',
			function () use ( $hook_manager ): void {
				foreach ( $this->settings->get_enabled_taxonomies() as $taxonomy ) {
					$hook_manager->register_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term_fields' ) );
				}
			}
		);
	}

	/**
	 * Register the metabox for enabled post types.
	 *
	 * @return void
	 */
	public function register_post_metabox(): void {
		add_meta_box(
			'taseo_meta',
			__( 'SEO', 'the-another-seo' ),
			array( $this, 'render_post_metabox' ),
			$this->settings->get_enabled_post_types(),
			'normal',
			'default'
		);
	}

	/**
	 * Render fields on the post edit screen.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_post_metabox( WP_Post $post ): void {
		$row = $this->repository->find( 'post', $post->post_type, (int) $post->ID );

		$this->render_fields( $row );
	}

	/**
	 * Render fields on the term edit screen.
	 *
	 * @param WP_Term $term Term.
	 * @return void
	 */
	public function render_term_fields( WP_Term $term ): void {
		$row = $this->repository->find( 'term', $term->taxonomy, (int) $term->term_id );

		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'SEO', 'the-another-seo' ) . '</th><td>';
		$this->render_fields( $row );
		echo '</td></tr>';
	}

	/**
	 * Shared field markup.
	 *
	 * @param array<string, mixed>|null $row Indexable row or null.
	 * @return void
	 */
	private function render_fields( ?array $row ): void {
		wp_nonce_field( 'taseo_save_meta', 'taseo_meta_nonce' );

		foreach ( self::FIELDS as $field => $type ) {
			$value = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
			$label = ucwords( str_replace( '_', ' ', $field ) );
			$name  = 'taseo_meta[' . $field . ']';

			echo '<p>';

			if ( 'checkbox' === $type ) {
				echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( '1', $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
			} elseif ( 'textarea' === $type ) {
				echo '<label>' . esc_html( $label ) . '<br /><textarea name="' . esc_attr( $name ) . '" rows="2" class="large-text">' . esc_textarea( $value ) . '</textarea></label>';
			} else {
				echo '<label>' . esc_html( $label ) . '<br /><input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="large-text" /></label>';
			}

			echo '</p>';
		}
	}

	/**
	 * save_post handler.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_save_post( int $post_id ): void {
		if ( ! isset( $_POST['taseo_meta_nonce'], $_POST['taseo_meta'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['taseo_meta_nonce'] ) ), 'taseo_save_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		$clean = $this->sanitize_submission( (array) wp_unslash( $_POST['taseo_meta'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.

		$this->repository->save_overrides( 'post', $post->post_type, $post_id, $clean );
	}

	/**
	 * edited_term handler.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_save_term( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! isset( $_POST['taseo_meta_nonce'], $_POST['taseo_meta'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['taseo_meta_nonce'] ) ), 'taseo_save_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$clean = $this->sanitize_submission( (array) wp_unslash( $_POST['taseo_meta'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.

		$this->repository->save_overrides( 'term', $taxonomy, $term_id, $clean );
	}

	/**
	 * Sanitize a raw taseo_meta submission. Every known field is present in
	 * the result; blanks/unchecked boxes come back as '' (which
	 * save_overrides stores as NULL = "no override").
	 *
	 * @param array<string, mixed> $raw Raw submission.
	 * @return array<string, mixed> Clean columns.
	 */
	public function sanitize_submission( array $raw ): array {
		$clean = array();

		foreach ( self::FIELDS as $field => $type ) {
			$value = $raw[ $field ] ?? '';

			$clean[ $field ] = match ( $type ) {
				'text'     => sanitize_text_field( (string) $value ),
				'textarea' => sanitize_textarea_field( (string) $value ),
				'url'      => esc_url_raw( (string) $value ),
				'image_id' => '' === $value ? '' : absint( $value ),
				'checkbox' => '' === $value ? '' : '1',
			};
		}

		return $clean;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter MetaboxTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Admin/Metabox.php tests/Admin/MetaboxTest.php
git commit -m "feat: add SEO override metabox for posts and terms with nonce-guarded saves"
```

---

### Task 14: SettingsPage — tabbed admin UI, backfill progress, rescan, conflict notice

**Files:**
- Create: `includes/Admin/SettingsPage.php`
- Test: `tests/Admin/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Settings` (Task 4), `IndexableBackfill::dispatch()/get_progress()` (Task 7)
- Produces: `TheAnother\Plugin\SEO\Admin\SettingsPage`:
  - `->init(HookManager): void` — `admin_menu` (options page "SEO — The Another" under Settings), `admin_post_taseo_save_settings`, `admin_post_taseo_rescan`, `admin_notices` (SEO-plugin conflict warning)
  - `->render_page(): void` — tabs: General | Post Types & Taxonomies | Titles & Templates | Social Networks | Schema & Breadcrumbs (spec "Admin UI"); the General tab shows backfill progress (`get_progress()`) and the "Rescan everything" button
  - `->handle_save(): void` — nonce (`taseo_settings_nonce` / action `taseo_save_settings`) + `current_user_can( 'manage_options' )`, sanitizes via `sanitize_settings()`, then `Settings::update()`, redirect back with `&updated=1`
  - `->handle_rescan(): void` — nonce + capability, then `IndexableBackfill::dispatch( 'full' )`, redirect back
  - `->sanitize_settings(array $raw): array` — the pure, tested unit
  - `->detect_conflicting_plugin(): ?string` — returns a plugin name when another SEO plugin's output is active (`defined( 'WPSEO_VERSION' )` → "Yoast SEO", `class_exists( 'RankMath' )` → "Rank Math", `defined( 'AIOSEO_VERSION' )` → "All in One SEO"), else null
- Sanitize rules: post type/taxonomy lists → `array_map( 'sanitize_key', ... )`; template strings + separator → `sanitize_text_field`; toggles → bool; image/logo IDs → `absint`; `same_as_urls` (textarea, one per line) → `esc_url_raw` per line, blanks dropped; `schema_types` values restricted to `array( 'None', 'WebPage', 'Article', 'Product' )`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Admin\SettingsPage;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Settings\Settings;

#[CoversClass( SettingsPage::class )]
class SettingsPageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private $settings;
	private $backfill;
	private SettingsPage $page;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings = Mockery::mock( Settings::class );
		$this->backfill = Mockery::mock( IndexableBackfill::class );

		Functions\when( 'sanitize_key' )->alias( fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias( fn( $v ) => abs( (int) $v ) );

		$this->page = new SettingsPage( $this->settings, $this->backfill );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_settings_handles_all_field_families(): void {
		$clean = $this->page->sanitize_settings(
			array(
				'enabled_post_types' => array( 'post', 'Product<script>' ),
				'separator'          => '|',
				'title_templates'    => array( 'post:product' => '%%title%%' ),
				'open_graph_enabled' => '1',
				'twitter_enabled'    => '',
				'site_logo_id'       => '42',
				'same_as_urls'       => "https://x.com/acme\n\nhttps://facebook.com/acme",
				'schema_types'       => array( 'post' => 'Article', 'page' => 'HackType' ),
			)
		);

		$this->assertSame( array( 'post', 'product<script>' ), $clean['enabled_post_types'] );
		$this->assertSame( '|', $clean['separator'] );
		$this->assertSame( array( 'post:product' => '%%title%%' ), $clean['title_templates'] );
		$this->assertTrue( $clean['open_graph_enabled'] );
		$this->assertFalse( $clean['twitter_enabled'] );
		$this->assertSame( 42, $clean['site_logo_id'] );
		$this->assertSame( array( 'https://x.com/acme', 'https://facebook.com/acme' ), $clean['same_as_urls'] );
		$this->assertSame( 'Article', $clean['schema_types']['post'] );
		$this->assertSame( 'WebPage', $clean['schema_types']['page'] ); // invalid value coerced to default.
	}

	public function test_handle_rescan_dispatches_full_chain(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'manage_options' )->andReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\expect( 'wp_safe_redirect' )->once();
		Functions\expect( 'admin_url' )->once()->andReturn( 'https://example.com/wp-admin/options-general.php?page=taseo' );

		$this->backfill->shouldReceive( 'dispatch' )->once()->with( 'full' );

		$this->page->handle_rescan( false ); // false = don't exit (testability flag).

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_handle_rescan_bails_without_capability(): void {
		$_POST['taseo_settings_nonce'] = 'nonce';

		Functions\expect( 'wp_verify_nonce' )->once()->andReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->andReturn( false );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->backfill->shouldNotReceive( 'dispatch' );

		$this->page->handle_rescan( false );

		unset( $_POST['taseo_settings_nonce'] );
	}

	public function test_detect_conflicting_plugin_finds_yoast(): void {
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			define( 'WPSEO_VERSION', '23.0' );
		}

		$this->assertSame( 'Yoast SEO', $this->page->detect_conflicting_plugin() );
	}
}
```

Note: the Yoast test defines a constant that leaks into the process — run it last alphabetically or accept it; it only ever *adds* detection. If it interferes locally, move that test into a separate process-isolated test class.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter SettingsPageTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create includes/Admin/SettingsPage.php**

```php
<?php
/**
 * Settings Page
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Admin;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Settings\Settings;

/**
 * Class SettingsPage
 *
 * Tabbed options screen. Tabs: General, Post Types & Taxonomies, Titles &
 * Templates, Social Networks, Schema & Breadcrumbs. General carries the
 * backfill progress indicator and the Rescan everything action.
 */
class SettingsPage {

	/**
	 * Valid schema type choices.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_TYPE_CHOICES = array( 'None', 'WebPage', 'Article', 'Product' );

	/**
	 * Tab slugs => labels (labels translated at render time).
	 *
	 * @var array<string, string>
	 */
	private const TABS = array(
		'general'    => 'General',
		'types'      => 'Post Types & Taxonomies',
		'templates'  => 'Titles & Templates',
		'social'     => 'Social Networks',
		'schema'     => 'Schema & Breadcrumbs',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param IndexableBackfill $backfill Backfill.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly IndexableBackfill $backfill
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'admin_menu', array( $this, 'register_menu' ) );
		$hook_manager->register_action( 'admin_post_taseo_save_settings', array( $this, 'handle_save' ) );
		$hook_manager->register_action( 'admin_post_taseo_rescan', array( $this, 'handle_rescan' ) );
		$hook_manager->register_action( 'admin_notices', array( $this, 'maybe_print_conflict_notice' ) );
	}

	/**
	 * Add the options page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'SEO — The Another', 'the-another-seo' ),
			__( 'SEO — The Another', 'the-another-seo' ),
			'manage_options',
			'taseo',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Warn when another SEO plugin also controls head output.
	 *
	 * @return void
	 */
	public function maybe_print_conflict_notice(): void {
		$conflict = $this->detect_conflicting_plugin();

		if ( null === $conflict ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: conflicting plugin name. */
					__( 'The Another SEO: %s is also active and outputs its own title/meta/schema tags. Disable one of them to avoid duplicate tags.', 'the-another-seo' ),
					$conflict
				)
			)
		);
	}

	/**
	 * Detect an active competing SEO plugin.
	 *
	 * @return string|null Plugin name or null.
	 */
	public function detect_conflicting_plugin(): ?string {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'Yoast SEO';
		}

		if ( class_exists( 'RankMath' ) ) {
			return 'Rank Math';
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'All in One SEO';
		}

		return null;
	}

	/**
	 * Render the tabbed page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		$active = array_key_exists( $active, self::TABS ) ? $active : 'general';

		echo '<div class="wrap"><h1>' . esc_html__( 'SEO — The Another', 'the-another-seo' ) . '</h1>';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::TABS as $slug => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=taseo&tab=' . $slug ) ),
				$active === $slug ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'taseo_save_settings', 'taseo_settings_nonce' );
		echo '<input type="hidden" name="action" value="taseo_save_settings" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $active ) . '" />';

		match ( $active ) {
			'types'     => $this->render_types_tab(),
			'templates' => $this->render_templates_tab(),
			'social'    => $this->render_social_tab(),
			'schema'    => $this->render_schema_tab(),
			default     => $this->render_general_tab(),
		};

		submit_button();
		echo '</form></div>';
	}

	/**
	 * General tab: separator, backfill progress, rescan.
	 *
	 * @return void
	 */
	private function render_general_tab(): void {
		$progress = $this->backfill->get_progress();

		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row"><label for="taseo-separator">%s</label></th><td><input type="text" id="taseo-separator" name="taseo_settings[separator]" value="%s" class="small-text" /></td></tr>',
			esc_html__( 'Title separator', 'the-another-seo' ),
			esc_attr( $this->settings->get_separator() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><strong>%s</strong> — %s%%</td></tr>',
			esc_html__( 'Index status', 'the-another-seo' ),
			esc_html( 'idle' === $progress['phase'] ? __( 'Up to date', 'the-another-seo' ) : $progress['phase'] ),
			esc_html( (string) $progress['percentage'] )
		);
		echo '</table>';

		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=taseo_rescan' ), 'taseo_save_settings', 'taseo_settings_nonce' ) ),
			esc_html__( 'Rescan everything', 'the-another-seo' )
		);
	}

	/**
	 * Post Types & Taxonomies tab (checkbox lists + schema type per subtype).
	 *
	 * @return void
	 */
	private function render_types_tab(): void {
		$enabled_types = $this->settings->get_enabled_post_types();
		$enabled_taxes = $this->settings->get_enabled_taxonomies();

		echo '<h2>' . esc_html__( 'Post types', 'the-another-seo' ) . '</h2>';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			printf(
				'<p><label><input type="checkbox" name="taseo_settings[enabled_post_types][]" value="%1$s" %2$s /> %3$s</label>
				%4$s <select name="taseo_settings[schema_types][%1$s]">%5$s</select></p>',
				esc_attr( $type->name ),
				checked( in_array( $type->name, $enabled_types, true ), true, false ),
				esc_html( $type->labels->name ),
				esc_html__( 'Schema type:', 'the-another-seo' ),
				$this->schema_type_options( $this->settings->get_schema_type( $type->name ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
			);
		}

		echo '<h2>' . esc_html__( 'Taxonomies', 'the-another-seo' ) . '</h2>';

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( 'post_format' === $tax->name ) {
				continue;
			}

			printf(
				'<p><label><input type="checkbox" name="taseo_settings[enabled_taxonomies][]" value="%s" %s /> %s</label></p>',
				esc_attr( $tax->name ),
				checked( in_array( $tax->name, $enabled_taxes, true ), true, false ),
				esc_html( $tax->labels->name )
			);
		}
	}

	/**
	 * Escaped <option> list for a schema type select.
	 *
	 * @param string $current Current value.
	 * @return string Options HTML.
	 */
	private function schema_type_options( string $current ): string {
		$html = '';

		foreach ( self::SCHEMA_TYPE_CHOICES as $choice ) {
			$html .= '<option value="' . esc_attr( $choice ) . '"' . selected( $choice, $current, false ) . '>' . esc_html( $choice ) . '</option>';
		}

		return $html;
	}

	/**
	 * Titles & Templates tab.
	 *
	 * @return void
	 */
	private function render_templates_tab(): void {
		echo '<p>' . esc_html__( 'Available variables: %%title%% %%sitename%% %%tagline%% %%sep%% %%excerpt%% %%primary_category%% %%date%% %%page%% %%price%% %%sku%%', 'the-another-seo' ) . '</p>';
		echo '<table class="form-table">';

		foreach ( $this->settings->get_enabled_post_types() as $type ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>
					<input type="text" name="taseo_settings[title_templates][post:%2$s]" value="%3$s" class="large-text" placeholder="%4$s" />
					<input type="text" name="taseo_settings[description_templates][post:%2$s]" value="%5$s" class="large-text" placeholder="%6$s" />
				</td></tr>',
				esc_html( $type ),
				esc_attr( $type ),
				esc_attr( $this->settings->get_title_template( 'post', $type ) ),
				esc_attr__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_description_template( 'post', $type ) ),
				esc_attr__( 'Description template', 'the-another-seo' )
			);
		}

		foreach ( $this->settings->get_enabled_taxonomies() as $tax ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>
					<input type="text" name="taseo_settings[title_templates][term:%2$s]" value="%3$s" class="large-text" />
					<input type="text" name="taseo_settings[description_templates][term:%2$s]" value="%4$s" class="large-text" />
				</td></tr>',
				esc_html( $tax ),
				esc_attr( $tax ),
				esc_attr( $this->settings->get_title_template( 'term', $tax ) ),
				esc_attr( $this->settings->get_description_template( 'term', $tax ) )
			);
		}

		// System pages.
		foreach ( array( 'home', 'search', '404' ) as $system ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><input type="text" name="taseo_settings[title_templates][system_page:%2$s]" value="%3$s" class="large-text" /></td></tr>',
				esc_html( $system ),
				esc_attr( $system ),
				esc_attr( $this->settings->get_title_template( 'system_page', $system ) )
			);
		}

		echo '</table>';
	}

	/**
	 * Social Networks tab.
	 *
	 * @return void
	 */
	private function render_social_tab(): void {
		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="taseo_settings[open_graph_enabled]" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Open Graph', 'the-another-seo' ),
			checked( $this->settings->is_open_graph_enabled(), true, false ),
			esc_html__( 'Output Open Graph tags (Facebook, LinkedIn, Instagram link previews, Pinterest)', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="taseo_settings[twitter_enabled]" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Twitter Card', 'the-another-seo' ),
			checked( $this->settings->is_twitter_enabled(), true, false ),
			esc_html__( 'Output Twitter Card tags (X)', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="number" name="taseo_settings[default_social_image_id]" value="%d" class="small-text" /> %s</td></tr>',
			esc_html__( 'Default social image', 'the-another-seo' ),
			(int) $this->settings->get_default_social_image_id(),
			esc_html__( '(attachment ID)', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[facebook_app_id]" value="%s" /></td></tr>',
			esc_html__( 'Facebook App ID', 'the-another-seo' ),
			esc_attr( $this->settings->get_facebook_app_id() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[twitter_site]" value="%s" placeholder="@handle" /></td></tr>',
			esc_html__( 'X / Twitter site handle', 'the-another-seo' ),
			esc_attr( $this->settings->get_twitter_site() )
		);
		echo '</table>';
	}

	/**
	 * Schema & Breadcrumbs tab.
	 *
	 * @return void
	 */
	private function render_schema_tab(): void {
		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row">%s</th><td>
				<label><input type="radio" name="taseo_settings[site_represents]" value="organization" %s /> %s</label><br />
				<label><input type="radio" name="taseo_settings[site_represents]" value="person" %s /> %s</label>
			</td></tr>',
			esc_html__( 'This site represents', 'the-another-seo' ),
			checked( 'organization', $this->settings->get_site_represents(), false ),
			esc_html__( 'An organization', 'the-another-seo' ),
			checked( 'person', $this->settings->get_site_represents(), false ),
			esc_html__( 'A person', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[site_represents_name]" value="%s" class="regular-text" /></td></tr>',
			esc_html__( 'Name', 'the-another-seo' ),
			esc_attr( $this->settings->get_site_represents_name() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="number" name="taseo_settings[site_logo_id]" value="%d" class="small-text" /> %s</td></tr>',
			esc_html__( 'Logo', 'the-another-seo' ),
			(int) $this->settings->get_site_logo_id(),
			esc_html__( '(attachment ID)', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><textarea name="taseo_settings[same_as_urls]" rows="4" class="large-text" placeholder="https://…">%s</textarea><br />%s</td></tr>',
			esc_html__( 'Social profile URLs (sameAs)', 'the-another-seo' ),
			esc_textarea( implode( "\n", $this->settings->get_same_as_urls() ) ),
			esc_html__( 'One URL per line.', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[breadcrumb_separator]" value="%s" class="small-text" /></td></tr>',
			esc_html__( 'Breadcrumb separator', 'the-another-seo' ),
			esc_attr( $this->settings->get_breadcrumb_separator() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[breadcrumb_home_label]" value="%s" class="regular-text" /></td></tr>',
			esc_html__( 'Breadcrumb home label', 'the-another-seo' ),
			esc_attr( $this->settings->get_breadcrumb_home_label() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="taseo_settings[breadcrumb_link_current]" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Current item', 'the-another-seo' ),
			checked( $this->settings->breadcrumb_link_current(), true, false ),
			esc_html__( 'Link the current (last) breadcrumb item', 'the-another-seo' )
		);
		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="taseo_settings[breadcrumb_include_taxonomy_ancestors]" value="1" %s /> %s</label></td></tr>',
			esc_html__( 'Taxonomy ancestors', 'the-another-seo' ),
			checked( $this->settings->breadcrumb_include_taxonomy_ancestors(), true, false ),
			esc_html__( 'Include taxonomy term ancestors in trails', 'the-another-seo' )
		);
		echo '</table>';
	}

	/**
	 * admin_post save handler.
	 *
	 * @param bool $do_exit Exit after redirect (false in tests).
	 * @return void
	 */
	public function handle_save( bool $do_exit = true ): void {
		if ( ! $this->verify_request() ) {
			return;
		}

		$raw = isset( $_POST['taseo_settings'] ) ? (array) wp_unslash( $_POST['taseo_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_settings().

		$this->settings->update( $this->sanitize_settings( $raw ) );

		wp_safe_redirect( admin_url( 'options-general.php?page=taseo&updated=1' ) );

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * admin_post rescan handler.
	 *
	 * @param bool $do_exit Exit after redirect (false in tests).
	 * @return void
	 */
	public function handle_rescan( bool $do_exit = true ): void {
		if ( ! $this->verify_request() ) {
			return;
		}

		$this->backfill->dispatch( 'full' );

		wp_safe_redirect( admin_url( 'options-general.php?page=taseo' ) );

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Shared nonce + capability guard for admin_post handlers.
	 *
	 * @return bool Request is valid.
	 */
	private function verify_request(): bool {
		$nonce = null;

		if ( isset( $_POST['taseo_settings_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['taseo_settings_nonce'] ) );
		} elseif ( isset( $_GET['taseo_settings_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['taseo_settings_nonce'] ) );
		}

		if ( null === $nonce || ! wp_verify_nonce( $nonce, 'taseo_save_settings' ) ) {
			return false;
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Sanitize a raw settings submission.
	 *
	 * @param array<string, mixed> $raw Raw values.
	 * @return array<string, mixed> Clean values.
	 */
	public function sanitize_settings( array $raw ): array {
		$clean = array();

		foreach ( array( 'enabled_post_types', 'enabled_taxonomies' ) as $list_key ) {
			if ( isset( $raw[ $list_key ] ) && is_array( $raw[ $list_key ] ) ) {
				$clean[ $list_key ] = array_values( array_map( 'sanitize_key', $raw[ $list_key ] ) );
			}
		}

		foreach ( array( 'title_templates', 'description_templates' ) as $tpl_key ) {
			if ( isset( $raw[ $tpl_key ] ) && is_array( $raw[ $tpl_key ] ) ) {
				$clean[ $tpl_key ] = array_map( 'sanitize_text_field', $raw[ $tpl_key ] );
			}
		}

		foreach ( array( 'separator', 'facebook_app_id', 'twitter_site', 'site_represents_name', 'breadcrumb_separator', 'breadcrumb_home_label' ) as $text_key ) {
			if ( isset( $raw[ $text_key ] ) ) {
				$clean[ $text_key ] = sanitize_text_field( (string) $raw[ $text_key ] );
			}
		}

		foreach ( array( 'open_graph_enabled', 'twitter_enabled', 'breadcrumb_link_current', 'breadcrumb_include_taxonomy_ancestors' ) as $bool_key ) {
			if ( array_key_exists( $bool_key, $raw ) ) {
				$clean[ $bool_key ] = ! empty( $raw[ $bool_key ] );
			}
		}

		foreach ( array( 'default_social_image_id', 'site_logo_id' ) as $id_key ) {
			if ( isset( $raw[ $id_key ] ) ) {
				$clean[ $id_key ] = absint( $raw[ $id_key ] );
			}
		}

		if ( isset( $raw['site_represents'] ) ) {
			$clean['site_represents'] = 'person' === $raw['site_represents'] ? 'person' : 'organization';
		}

		if ( isset( $raw['same_as_urls'] ) && is_string( $raw['same_as_urls'] ) ) {
			$urls = array();

			foreach ( preg_split( '/\r\n|\r|\n/', $raw['same_as_urls'] ) as $line ) {
				$url = esc_url_raw( trim( $line ) );

				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}

			$clean['same_as_urls'] = $urls;
		}

		if ( isset( $raw['schema_types'] ) && is_array( $raw['schema_types'] ) ) {
			$clean['schema_types'] = array();

			foreach ( $raw['schema_types'] as $subtype => $type ) {
				$clean['schema_types'][ sanitize_key( (string) $subtype ) ] =
					in_array( $type, self::SCHEMA_TYPE_CHOICES, true ) ? $type : 'WebPage';
			}
		}

		return $clean;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter SettingsPageTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Run phpcs and commit**

```bash
composer phpcs
git add includes/Admin/SettingsPage.php tests/Admin/SettingsPageTest.php
git commit -m "feat: add tabbed settings page with backfill progress, rescan, and conflict notice"
```

---

### Task 15: Plugin wiring — full service graph, activation flow, initial backfill dispatch

**Files:**
- Modify: `includes/Plugin.php` (fill `start()`)
- Test: `tests/PluginTest.php`

**Interfaces:**
- Consumes: everything above via the container
- Produces: container service keys — the canonical registry other code (block render.php, template tag) relies on:
  `settings`, `indexable_repository`, `indexable_sync`, `indexable_backfill`, `current_context`, `template_resolver`, `meta_output`, `social_output`, `schema_graph`, `schema_output`, `breadcrumb_trail`, `breadcrumb_renderer`, `metabox`, `settings_page`, `blocks`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TheAnother\Plugin\SEO\Container;
use TheAnother\Plugin\SEO\Plugin;

#[CoversClass( Plugin::class )]
class PluginTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		foreach ( array( Container::class, Plugin::class ) as $singleton ) {
			$reflection = new \ReflectionClass( $singleton );
			$instance   = $reflection->getProperty( 'instance' );
			$instance->setAccessible( true );
			$instance->setValue( null, null );
		}

		// start() runs the schema migration and registers a shortcode; stub
		// everything those paths touch.
		global $wpdb;
		$wpdb         = \Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'get_charset_collate' )->andReturn( '' );

		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'dbDelta' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_shortcode' )->justReturn( null );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_start_registers_all_services(): void {
		Plugin::get_instance()->start();

		$container = Container::get_instance();

		foreach ( array(
			'settings',
			'indexable_repository',
			'indexable_sync',
			'indexable_backfill',
			'current_context',
			'template_resolver',
			'meta_output',
			'social_output',
			'schema_graph',
			'schema_output',
			'breadcrumb_trail',
			'breadcrumb_renderer',
			'metabox',
			'settings_page',
			'blocks',
		) as $key ) {
			$this->assertTrue( $container->has( $key ), "Missing service: {$key}" );
		}
	}

	public function test_start_registers_frontend_hooks(): void {
		Plugin::get_instance()->start();

		$hooks = Container::get_instance()->get_hook_manager()->get_registered_hooks();
		$names = array_column( $hooks, 'hook' );

		$this->assertContains( 'save_post', $names );
		$this->assertContains( 'wp_head', $names );
		$this->assertContains( 'pre_get_document_title', $names );
		$this->assertContains( 'init', $names );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter PluginTest`
Expected: FAIL — services not registered (start() is empty).

- [ ] **Step 3: Fill in Plugin::start()**

Replace the `start()` method (and add the imports + helper methods) in `includes/Plugin.php`:

```php
<?php
/**
 * Plugin Orchestrator Class
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO;

use TheAnother\Plugin\SEO\Admin\Metabox;
use TheAnother\Plugin\SEO\Admin\SettingsPage;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbRenderer;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Indexable\IndexableSync;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Schema\SchemaGraph;
use TheAnother\Plugin\SEO\Schema\SchemaOutput;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Social\SocialOutput;

/**
 * Class Plugin
 *
 * Registers the full service graph and wires all hooks.
 */
class Plugin {

	/**
	 * Plugin instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Private constructor.
	 */
	private function __construct() {
		$this->container = Container::get_instance();
	}

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin Plugin instance.
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Start the plugin: register services and hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		IndexablesTable::maybe_upgrade();

		$this->register_services();
		$this->init_services();
		$this->maybe_dispatch_initial_backfill();
	}

	/**
	 * Register the service graph.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$c = $this->container;

		$c->register( 'settings', fn() => new Settings() );
		$c->register( 'template_resolver', fn() => new TemplateResolver() );
		$c->register( 'indexable_repository', fn() => new IndexableRepository() );
		$c->register(
			'indexable_backfill',
			fn( Container $c ) => new IndexableBackfill( $c->get( 'indexable_sync' ), $c->get( 'settings' ) )
		);
		$c->register(
			'indexable_sync',
			fn( Container $c ) => new IndexableSync(
				$c->get( 'indexable_repository' ),
				$c->get( 'settings' ),
				function () use ( $c ): void {
					$c->get( 'indexable_backfill' )->dispatch( 'permalink' );
				}
			)
		);
		$c->register(
			'current_context',
			fn( Container $c ) => new CurrentContext( $c->get( 'indexable_repository' ), $c->get( 'settings' ) )
		);
		$c->register(
			'meta_output',
			fn( Container $c ) => new MetaOutput( $c->get( 'current_context' ), $c->get( 'template_resolver' ) )
		);
		$c->register(
			'social_output',
			fn( Container $c ) => new SocialOutput( $c->get( 'current_context' ), $c->get( 'meta_output' ), $c->get( 'settings' ) )
		);
		$c->register(
			'breadcrumb_trail',
			fn( Container $c ) => new BreadcrumbTrail( $c->get( 'indexable_repository' ), $c->get( 'settings' ) )
		);
		$c->register(
			'breadcrumb_renderer',
			fn( Container $c ) => new BreadcrumbRenderer( $c->get( 'breadcrumb_trail' ), $c->get( 'settings' ) )
		);
		$c->register(
			'schema_graph',
			fn( Container $c ) => new SchemaGraph(
				$c->get( 'current_context' ),
				$c->get( 'meta_output' ),
				$c->get( 'breadcrumb_trail' ),
				$c->get( 'settings' )
			)
		);
		$c->register( 'schema_output', fn( Container $c ) => new SchemaOutput( $c->get( 'schema_graph' ) ) );
		$c->register(
			'metabox',
			fn( Container $c ) => new Metabox( $c->get( 'indexable_repository' ), $c->get( 'settings' ) )
		);
		$c->register(
			'settings_page',
			fn( Container $c ) => new SettingsPage( $c->get( 'settings' ), $c->get( 'indexable_backfill' ) )
		);
		$c->register( 'blocks', fn() => new Blocks() );
	}

	/**
	 * Initialize hook-bearing services.
	 *
	 * @return void
	 */
	private function init_services(): void {
		$hook_manager = $this->container->get_hook_manager();

		$this->container->get( 'indexable_sync' )->init( $hook_manager );
		$this->container->get( 'indexable_backfill' )->init( $hook_manager );
		$this->container->get( 'meta_output' )->init( $hook_manager );
		$this->container->get( 'social_output' )->init( $hook_manager );
		$this->container->get( 'schema_output' )->init( $hook_manager );
		$this->container->get( 'breadcrumb_renderer' )->init( $hook_manager );
		$this->container->get( 'blocks' )->init( $hook_manager );

		if ( is_admin() ) {
			$this->container->get( 'metabox' )->init( $hook_manager );
			$this->container->get( 'settings_page' )->init( $hook_manager );
		}
	}

	/**
	 * Dispatch the initial backfill chain flagged by Installer::activate().
	 *
	 * Runs on plugins_loaded-time start(), but the dispatch itself is
	 * deferred to init so Action Scheduler is fully booted.
	 *
	 * @return void
	 */
	private function maybe_dispatch_initial_backfill(): void {
		if ( '1' !== get_option( Installer::NEEDS_BACKFILL_OPTION, '' ) ) {
			return;
		}

		$container = $this->container;

		$this->container->get_hook_manager()->register_action(
			'init',
			static function () use ( $container ): void {
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return; // Action Scheduler unavailable; retry next request.
				}

				$container->get( 'indexable_backfill' )->dispatch( 'full' );
				delete_option( Installer::NEEDS_BACKFILL_OPTION );
			},
			20
		);
	}
}
```

- [ ] **Step 4: Run the full suite**

Run: `composer test`
Expected: ALL tests PASS (every task's suite). The two `PluginTest` tests pass; no other test regresses.

- [ ] **Step 5: Run phpcs, build, and commit**

```bash
composer phpcs
npm run build
git add includes/Plugin.php tests/PluginTest.php
git commit -m "feat: wire full service graph with activation-flagged initial backfill dispatch"
```

- [ ] **Step 6: Manual smoke test (local WP environment)**

1. Activate the plugin on a local site → no fatal; `wp_taseo_indexables` exists (`wp db query "DESCRIBE wp_taseo_indexables"`).
2. Visit any admin page once, then Tools → Scheduled Actions → confirm `taseo_backfill_batch` actions ran in group `taseo`; spot-check the table has rows.
3. View a published post's source: `<title>` matches the template, one `<meta name="description">`, exactly one canonical, OG + Twitter tags, one JSON-LD `@graph` block with `BreadcrumbList`.
4. Edit a post → fill SEO title override → view source shows the override verbatim.
5. Add `[taseo_breadcrumbs]` to a page and the Breadcrumbs block to a template → both render the same trail as the JSON-LD `BreadcrumbList`.
6. Settings → SEO — The Another → toggle Twitter Card off → tags disappear; press "Rescan everything" → new `taseo_backfill_batch` chain appears in Scheduled Actions.
7. Settings → Permalinks → change structure → confirm a backfill chain dispatches (mode `permalink`) and a `taseo_permalinks_rebuilt` action fires when it drains (add a temporary `error_log` listener if needed).

---

## Plan Self-Review Notes

**Spec coverage check** (spec → task): indexable table schema → T2; overrides-only storage + upsert separation → T3; enabled types/taxonomies + all settings surfaces → T4, T14; template variables & resolution order → T5, T8; sync hooks incl. trash-keeps-row / delete-removes-row / revision-autosave bail → T6; AS bundling + job chains + rescan + `taseo_permalinks_rebuilt` → T1 (bundle/load), T7, T15 (dispatch); ID-range pagination → T7; frontend title/description/canonical/robots + `rel_canonical` unhook + live-permalink canonical → T8; OG/Twitter toggles, overrides, image fallback, WC product upgrade → T9; breadcrumb trail single-source-of-truth + `breadcrumb_title` override + template tag + shortcode → T10; Gutenberg block + `@wordpress/scripts` → T11; `@graph` JSON-LD with `WebSite`/`Organization`/`Person`/`WebPage`/`BreadcrumbList`/`Article`/`Product`, `schema_disabled` → T12; metabox with nonce/capability and all override fields → T13; settings tabs, backfill progress, rescan action, conflict notice → T14; activation → instant, backfill deferred via option flag → T2, T15.

**Known deliberate scope notes:**
- The `sitemap_file_id` column is NOT in this plan — the sitemap plan adds it (single-owner migration there; `IndexablesTable::DB_VERSION` bump).
- Term-context breadcrumbs (trails on taxonomy archive pages) return home-only in v1 of `BreadcrumbTrail::build()`; the spec's breadcrumb section is post-centric ("walking the current object's ancestry"), and taxonomy-archive trails can be added to `build()` without interface changes.
- `CurrentContext`'s WP-conditional branching is exercised by the Task 15 manual smoke test rather than unit tests — Brain Monkey can't meaningfully simulate a main query.
