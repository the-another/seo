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
use TheAnother\Plugin\SEO\Analytics\AnalyticsOutput;
use TheAnother\Plugin\SEO\Analytics\MetaPixelOutput;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbRenderer;
use TheAnother\Plugin\SEO\Breadcrumbs\BreadcrumbTrail;
use TheAnother\Plugin\SEO\Database\IndexablesTable;
use TheAnother\Plugin\SEO\Database\SitemapFilesTable;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Indexable\IndexableSync;
use TheAnother\Plugin\SEO\Meta\CurrentContext;
use TheAnother\Plugin\SEO\Meta\CustomPages;
use TheAnother\Plugin\SEO\Meta\MetaOutput;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
use TheAnother\Plugin\SEO\Schema\SchemaGraph;
use TheAnother\Plugin\SEO\Schema\SchemaOutput;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\ExternalUrls;
use TheAnother\Plugin\SEO\Sitemap\SitemapAssignment;
use TheAnother\Plugin\SEO\Sitemap\SitemapFamilies;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
use TheAnother\Plugin\SEO\Sitemap\SitemapServer;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;
use TheAnother\Plugin\SEO\Social\SocialOutput;
use TheAnother\Plugin\SEO\Verification\VerificationFileServer;
use TheAnother\Plugin\SEO\Verification\VerificationOutput;

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
		$this->maybe_flag_upgrade_backfill();

		IndexablesTable::maybe_upgrade();
		SitemapFilesTable::maybe_upgrade();

		$this->register_services();
		$this->init_services();
		$this->maybe_dispatch_initial_backfill();
		$this->maybe_flush_rewrites();
	}

	/**
	 * Register the service graph.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$c = $this->container;

		$c->register( 'settings', fn() => new Settings() );
		$c->register( 'custom_pages', fn() => new CustomPages() );
		$c->register( 'template_resolver', fn() => new TemplateResolver() );
		$c->register( 'template_variables', fn() => new TemplateVariables() );
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
			fn( Container $c ) => new CurrentContext( $c->get( 'indexable_repository' ), $c->get( 'settings' ), $c->get( 'custom_pages' ) )
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
			fn( Container $c ) => new SettingsPage(
				$c->get( 'settings' ),
				$c->get( 'indexable_backfill' ),
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_storage' ),
				$c->get( 'sitemap_sweeper' ),
				$c->get( 'template_variables' ),
				$c->get( 'custom_pages' ),
				$c->get( 'sitemap_families' ),
				$c->get( 'sitemap_assignment' )
			)
		);
		$c->register( 'sitemap_file_repository', fn() => new SitemapFileRepository() );
		$c->register( 'sitemap_storage', fn() => new SitemapStorage() );
		$c->register(
			'sitemap_file_writer',
			fn( Container $c ) => new SitemapFileWriter( $c->get( 'sitemap_file_repository' ), $c->get( 'sitemap_storage' ) )
		);
		$c->register(
			'sitemap_assignment',
			fn( Container $c ) => new SitemapAssignment(
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_storage' ),
				$c->get( 'settings' )
			)
		);
		$c->register(
			'sitemap_sweeper',
			fn( Container $c ) => new SitemapSweeper(
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_file_writer' ),
				$c->get( 'sitemap_storage' ),
				$c->get( 'settings' )
			)
		);
		$c->register(
			'sitemap_server',
			fn( Container $c ) => new SitemapServer(
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_storage' ),
				$c->get( 'settings' )
			)
		);
		$c->register( 'sitemap_families', fn() => new SitemapFamilies() );
		$c->register(
			'sitemap_external_urls',
			fn( Container $c ) => new ExternalUrls(
				$c->get( 'sitemap_families' ),
				$c->get( 'indexable_repository' ),
				$c->get( 'sitemap_file_repository' ),
				$c->get( 'sitemap_storage' )
			)
		);
		$c->register( 'blocks', fn() => new Blocks() );
		$c->register(
			'verification_output',
			fn( Container $c ) => new VerificationOutput( $c->get( 'settings' ) )
		);
		$c->register(
			'verification_file_server',
			fn( Container $c ) => new VerificationFileServer( $c->get( 'settings' ) )
		);
		$c->register(
			'analytics_output',
			fn( Container $c ) => new AnalyticsOutput( $c->get( 'settings' ) )
		);
		$c->register(
			'meta_pixel_output',
			fn( Container $c ) => new MetaPixelOutput( $c->get( 'settings' ) )
		);
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
		$this->container->get( 'sitemap_assignment' )->init( $hook_manager );
		$this->container->get( 'sitemap_sweeper' )->init( $hook_manager );
		$this->container->get( 'sitemap_server' )->init( $hook_manager );
		$this->container->get( 'verification_output' )->init( $hook_manager );
		$this->container->get( 'verification_file_server' )->init( $hook_manager );
		$this->container->get( 'analytics_output' )->init( $hook_manager );
		$this->container->get( 'meta_pixel_output' )->init( $hook_manager );

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

	/**
	 * Re-dispatch a full backfill, and flag a rewrite flush, when upgrading
	 * an existing install to the sitemap schema: pre-upgrade rows have no
	 * chunk assignment, and only a resync (which re-fires
	 * taseo_indexable_synced per row) assigns them. In-place plugin updates
	 * never re-run Installer::activate(), so the rewrite flush that exposes
	 * /sitemap.xml must be flagged here too, or upgraders 404 forever.
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
			update_option( Installer::FLUSH_REWRITE_OPTION, '1' );
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
}
