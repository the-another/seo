<?php
/**
 * Settings Page
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Admin;

use TheAnother\Plugin\SEO\Domains\DomainRegistry;
use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Indexable\IndexableBackfill;
use TheAnother\Plugin\SEO\Indexable\PostSubtypes;
use TheAnother\Plugin\SEO\Meta\CustomPages;
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapAssignment;
use TheAnother\Plugin\SEO\Sitemap\SitemapFamilies;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapStorage;
use TheAnother\Plugin\SEO\Sitemap\SitemapSweeper;
use TheAnother\Plugin\SEO\Verification\VerificationFileServer;
use TheAnother\Plugin\SEO\Verification\VerificationOutput;

/**
 * Class SettingsPage
 *
 * Tabbed options screen. Tabs: General, Post Types & Taxonomies, Titles &
 * Templates, Social Networks, Schema & Breadcrumbs, Sitemap, Webmaster Tools.
 * General carries the backfill progress indicator and the Rescan everything
 * action.
 */
class SettingsPage {

	/**
	 * Valid schema type choices.
	 *
	 * @var array<int, string>
	 */
	private const SCHEMA_TYPE_CHOICES = array( 'None', 'WebPage', 'Article', 'Product' );

	/**
	 * Engine slug => pattern a file-mode token must match, and the prefix and
	 * suffix stripped when an operator pastes a whole filename instead.
	 *
	 * Must be kept in agreement with VerificationFileServer::TOKEN_PATTERNS,
	 * which re-validates the same tokens at output time, and with the filename
	 * shapes in self::verification_filename() and
	 * VerificationFileServer::build_files(). Adding a service means touching
	 * all four plus the $services list in render_webmaster_tab() and the engine
	 * loop in sanitize_settings(); both filename builders answer '' / serve
	 * nothing for an engine they do not know, so an omission shows up as a
	 * missing file rather than as another service's shape.
	 *
	 * MethodMigration::LEGACY_FILE_SHAPES is deliberately NOT part of that
	 * agreement: it is frozen on the pre-0.5.0 storage format.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, array{pattern: string, prefix: string, suffix: string, lowercase: bool}>
	 */
	private const TOKEN_SHAPES = array(
		'google' => array(
			'pattern'   => '/^[a-z0-9]+$/',
			'prefix'    => 'google',
			'suffix'    => '.html',
			'lowercase' => true,
		),
		'bing'   => array(
			'pattern'   => '/^[A-Za-z0-9]+$/',
			'prefix'    => '',
			'suffix'    => '',
			'lowercase' => false,
		),
		'yandex' => array(
			'pattern'   => '/^[a-z0-9]+$/',
			'prefix'    => 'yandex_',
			'suffix'    => '.html',
			'lowercase' => true,
		),
	);

	/**
	 * Tracking ID settings keys => validation pattern.
	 *
	 * @var array<string, string>
	 */
	private const TRACKING_ID_PATTERNS = array(
		'analytics_ga4_id' => '/^G-[A-Z0-9]{4,}$/',
		'analytics_gtm_id' => '/^GTM-[A-Z0-9]{4,}$/',
		'meta_pixel_id'    => '/^[0-9]{10,20}$/',
	);

	/**
	 * Tab slugs => labels (labels translated at render time).
	 *
	 * @var array<string, string>
	 */
	private const TABS = array(
		'general'   => 'General',
		'types'     => 'Post Types & Taxonomies',
		'templates' => 'Titles & Templates',
		'social'    => 'Social Networks',
		'schema'    => 'Schema & Breadcrumbs',
		'sitemap'   => 'Sitemap',
		'webmaster' => 'Webmaster Tools',
	);

	/**
	 * Settings-error code prefix. The settings key and row key are appended
	 * so render_page() can recover which fields failed after the redirect —
	 * validation and rendering happen in different requests, so nothing held
	 * in object state survives between them. Double underscore separates,
	 * because row keys contain colons and "system_page" contains a single
	 * underscore.
	 *
	 * @var string
	 */
	private const INVALID_TEMPLATE_CODE = 'taseo_invalid_template__';

	/**
	 * Settings-error code prefix for a verification value the sanitizer had to
	 * discard. The settings key is appended, following INVALID_TEMPLATE_CODE's
	 * convention, so one save can report several services and a reader of the
	 * errors can tell which field failed. Deliberately a different prefix:
	 * collect_invalid_rows() treats everything under INVALID_TEMPLATE_CODE as
	 * a template row key.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private const INVALID_VERIFICATION_CODE = 'taseo_invalid_verification__';

	/**
	 * Hook suffix of this settings page, for gating asset enqueue.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Row keys rejected by the save that redirected here, as
	 * "<settings key>__<row key>". Populated once per render from the
	 * settings errors, since the validating request and this one are
	 * different requests.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $invalid_rows = null;

	/**
	 * Constructor.
	 *
	 * @param Settings              $settings           Settings.
	 * @param IndexableBackfill     $backfill           Backfill.
	 * @param SitemapFileRepository $sitemap_files      Sitemap registry (status panel).
	 * @param SitemapStorage        $sitemap_storage    Sitemap storage (writability probe).
	 * @param SitemapSweeper        $sitemap_sweeper    Sitemap sweeper (regenerate action).
	 * @param TemplateVariables     $template_variables Template variables registry (per-row pills).
	 * @param CustomPages           $custom_pages       Plugin-registered custom pages.
	 * @param SitemapFamilies       $sitemap_families   External URL families registry.
	 * @param SitemapAssignment     $sitemap_assignment Sitemap chunk assignment (family toggle transitions).
	 * @param PostSubtypes          $post_subtypes      Post subtype registry (per-subtype rows).
	 * @param DomainRegistry        $domains            Verification domain registry.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly IndexableBackfill $backfill,
		private readonly SitemapFileRepository $sitemap_files,
		private readonly SitemapStorage $sitemap_storage,
		private readonly SitemapSweeper $sitemap_sweeper,
		private readonly TemplateVariables $template_variables,
		private readonly CustomPages $custom_pages,
		private readonly SitemapFamilies $sitemap_families,
		private readonly SitemapAssignment $sitemap_assignment,
		private readonly PostSubtypes $post_subtypes,
		private readonly DomainRegistry $domains
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
		// 0 accepted args: WP passes a legacy '' to 1-arg callbacks on no-arg
		// hooks, which would falsify the handlers' $do_exit default.
		$hook_manager->register_action( 'admin_post_taseo_save_settings', array( $this, 'handle_save' ), 10, 0 );
		$hook_manager->register_action( 'admin_post_taseo_rescan', array( $this, 'handle_rescan' ), 10, 0 );
		$hook_manager->register_action( 'admin_post_taseo_sitemap_regenerate', array( $this, 'handle_sitemap_regenerate' ), 10, 0 );
		$hook_manager->register_action( 'admin_notices', array( $this, 'maybe_print_conflict_notice' ) );
		$hook_manager->register_action( 'admin_notices', array( $this, 'maybe_print_sitemap_storage_notice' ) );
		$hook_manager->register_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the options page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_options_page(
			__( 'SEO — The Another', 'the-another-seo' ),
			__( 'SEO — The Another', 'the-another-seo' ),
			'manage_options',
			'taseo',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue this page's script, and only on this page.
	 *
	 * Dependencies and version come from the generated asset file rather than
	 * a hand-written list, which is how core tracks what a built bundle
	 * actually imports — the surface pulls in wp-rich-text, wp-components and
	 * wp-element, all of them already shipped by WordPress. The file_exists()
	 * guard matters: a source checkout that has not been built would otherwise
	 * fatal on `require`, and this runs in wp-admin. dist/ is deliberately not
	 * excluded by .distignore, so the built file ships in the release zip.
	 *
	 * The stylesheet is core's own wp-components, which the autocomplete
	 * popover needs to be readable. ImageField::enqueue() at the end pulls in
	 * core's media library plus the picker bundle the social/schema tabs'
	 * image fields need; no stylesheet of our own is enqueued.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset_file = THE_ANOTHER_SEO_PLUGIN_DIR . 'dist/settings/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'taseo-settings',
			THE_ANOTHER_SEO_PLUGIN_URL . 'dist/settings/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_enqueue_style( 'wp-components' );
		ImageField::enqueue();
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

		$this->collect_invalid_rows();

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
			'sitemap'   => $this->render_sitemap_tab(),
			'webmaster' => $this->render_webmaster_tab(),
			default     => $this->render_general_tab(),
		};

		submit_button();
		echo '</form></div>';
	}

	/**
	 * Read the settings errors once and remember which rows they name so
	 * the matching inputs can be marked.
	 *
	 * Deliberately does not print anything. This options page is registered
	 * with add_options_page(), so its parent is options-general.php; core's
	 * wp-admin/admin-header.php requires wp-admin/options-head.php for every
	 * such page, and that file calls bare settings_errors(), which — once
	 * 'settings-updated' is on the query string — already pulls our errors
	 * out of the settings_errors transient and prints them, before
	 * render_page() ever runs. Printing them again here would duplicate
	 * every notice. The get_settings_errors() call below still has to run:
	 * it is what merges the transient into the request-lifetime
	 * $wp_settings_errors global, and this is the only place that recovers
	 * which rows failed so template_input_class() can mark them.
	 *
	 * @return void
	 */
	private function collect_invalid_rows(): void {
		$errors             = get_settings_errors( 'taseo_messages' );
		$this->invalid_rows = array();

		foreach ( $errors as $error ) {
			$code = (string) ( $error['code'] ?? '' );

			if ( str_starts_with( $code, self::INVALID_TEMPLATE_CODE ) ) {
				$this->invalid_rows[] = substr( $code, strlen( self::INVALID_TEMPLATE_CODE ) );
			}
		}
	}

	/**
	 * The CSS classes for a template input, adding core's .form-invalid
	 * when the last save rejected this row.
	 *
	 * @param string $settings_key 'title_templates' or 'description_templates'.
	 * @param string $row_key      Row key such as 'post:product'.
	 * @return string Class attribute value.
	 */
	private function template_input_class( string $settings_key, string $row_key ): string {
		$rows = $this->invalid_rows ?? array();

		return in_array( $settings_key . '__' . $row_key, $rows, true )
			? 'large-text form-invalid'
			: 'large-text';
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

			$subtypes = $this->post_subtypes->for_post_type( $type->name );

			// The checkbox stays post-type level — it is the coarse gate the
			// backfill query and the sync early-return both read. Schema type
			// is per subtype, because that is the granularity the output
			// actually resolves at.
			printf(
				'<p><label><input type="checkbox" name="taseo_settings[enabled_post_types][]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $type->name ),
				checked( in_array( $type->name, $enabled_types, true ), true, false ),
				esc_html( $type->labels->name )
			);

			// An unsplit post type has exactly one subtype — its own name — so
			// this renders the single inline select it always did.
			$indent = 1 === count( $subtypes ) ? '' : '<br /><span style="padding-left:2em">';

			foreach ( $subtypes as $subtype => $subtype_label ) {
				printf(
					'%1$s %2$s %3$s <select name="taseo_settings[schema_types][%4$s]">%5$s</select>%6$s',
					$indent, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup.
					'' === $indent ? '' : esc_html( $subtype_label ),
					esc_html__( 'Schema type:', 'the-another-seo' ),
					esc_attr( $subtype ),
					$this->schema_type_options( $this->settings->get_schema_type( $subtype, $type->name ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
					'' === $indent ? '' : '</span>'
				);
			}

			echo '</p>';
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
	 * Every subtype key carrying a sitemap inclusion toggle.
	 *
	 * Post subtypes, taxonomies, and external families share one namespace
	 * and one disabled-list, so the render and the save must agree on the
	 * same set — a key the save cannot see would be pruned back to enabled
	 * on the next save no matter what the admin unchecked.
	 *
	 * @return array<string, string> Key => label.
	 */
	private function toggleable_subtypes(): array {
		return $this->post_subtypes->flatten( $this->settings->get_enabled_post_types() )
			+ $this->taxonomy_labels()
			+ $this->sitemap_families->all();
	}

	/**
	 * Enabled taxonomies as key => label.
	 *
	 * @return array<string, string> Taxonomy => label.
	 */
	private function taxonomy_labels(): array {
		$labels = array();

		foreach ( $this->settings->get_enabled_taxonomies() as $taxonomy ) {
			$labels[ $taxonomy ] = $this->template_row_label( 'term', (string) $taxonomy );
		}

		return $labels;
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
		$sections = array(
			'taseo-post-types'   => __( 'Post types', 'the-another-seo' ),
			'taseo-taxonomies'   => __( 'Taxonomies', 'the-another-seo' ),
			'taseo-system-pages' => __( 'System pages', 'the-another-seo' ),
			'taseo-custom-pages' => __( 'Custom pages', 'the-another-seo' ),
		);
		$last     = array_key_last( $sections );

		echo '<ul class="subsubsub">';

		foreach ( $sections as $anchor => $section_label ) {
			printf(
				'<li><a href="#%1$s">%2$s</a>%3$s</li>',
				esc_attr( $anchor ),
				esc_html( $section_label ),
				$anchor === $last ? '' : ' |'
			);
		}

		echo '</ul>';
		// .subsubsub is float: left (wp-admin/css/common.css:428), so without
		// this the first heading wraps beside the nav instead of below it.
		echo '<div class="clear"></div>';

		echo '<h2 id="taseo-post-types">' . esc_html__( 'Post types', 'the-another-seo' ) . '</h2>';
		echo '<table class="form-table">';

		// One row per SUBTYPE, not per post type: a post type split into
		// subtypes has a row each, and one that isn't has exactly one row
		// keyed by its own name — which is the pre-subtype behaviour, so
		// stored templates keep matching their rows without a migration.
		foreach ( $this->post_subtypes->flatten( $this->settings->get_enabled_post_types() ) as $type => $label ) {
			$row_key = 'post:' . $type;

			printf(
				'<tr><th scope="row">%1$s<p class="description"><code>%2$s</code></p></th><td>
					<fieldset>
						<legend class="screen-reader-text"><span>%1$s</span></legend>
						<label for="taseo-title-%3$s">%4$s</label><br />
						<input type="text" id="taseo-title-%3$s" name="taseo_settings[title_templates][%2$s]" value="%5$s" class="%6$s" data-taseo-template-input /><br />
						<label for="taseo-desc-%3$s">%7$s</label><br />
						<input type="text" id="taseo-desc-%3$s" name="taseo_settings[description_templates][%2$s]" value="%8$s" class="%9$s" data-taseo-template-input />
					</fieldset>',
				esc_html( $label ),
				esc_attr( $row_key ),
				esc_attr( 'post-' . $type ),
				esc_html__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_title_template( 'post', $type ) ),
				esc_attr( $this->template_input_class( 'title_templates', $row_key ) ),
				esc_html__( 'Meta description template', 'the-another-seo' ),
				esc_attr( $this->settings->get_description_template( 'post', $type ) ),
				esc_attr( $this->template_input_class( 'description_templates', $row_key ) )
			);
			$this->render_variable_pills( 'post', $type );
			echo '</td></tr>';
		}

		echo '</table>';

		echo '<hr />';

		echo '<h2 id="taseo-taxonomies">' . esc_html__( 'Taxonomies', 'the-another-seo' ) . '</h2>';
		echo '<table class="form-table">';

		foreach ( $this->settings->get_enabled_taxonomies() as $tax ) {
			$label   = $this->template_row_label( 'term', $tax );
			$row_key = 'term:' . $tax;

			printf(
				'<tr><th scope="row">%1$s<p class="description"><code>%2$s</code></p></th><td>
					<fieldset>
						<legend class="screen-reader-text"><span>%1$s</span></legend>
						<label for="taseo-title-%3$s">%4$s</label><br />
						<input type="text" id="taseo-title-%3$s" name="taseo_settings[title_templates][%2$s]" value="%5$s" class="%6$s" data-taseo-template-input /><br />
						<label for="taseo-desc-%3$s">%7$s</label><br />
						<input type="text" id="taseo-desc-%3$s" name="taseo_settings[description_templates][%2$s]" value="%8$s" class="%9$s" data-taseo-template-input />
					</fieldset>',
				esc_html( $label ),
				esc_attr( $row_key ),
				esc_attr( 'term-' . $tax ),
				esc_html__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_title_template( 'term', $tax ) ),
				esc_attr( $this->template_input_class( 'title_templates', $row_key ) ),
				esc_html__( 'Meta description template', 'the-another-seo' ),
				esc_attr( $this->settings->get_description_template( 'term', $tax ) ),
				esc_attr( $this->template_input_class( 'description_templates', $row_key ) )
			);
			$this->render_variable_pills( 'term', $tax );
			echo '</td></tr>';
		}

		echo '</table>';

		echo '<hr />';

		echo '<h2 id="taseo-system-pages">' . esc_html__( 'System pages', 'the-another-seo' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'System pages take a title template only.', 'the-another-seo' )
		);
		echo '<table class="form-table">';

		foreach ( array( 'home', 'search', '404' ) as $system ) {
			$label   = $this->template_row_label( 'system_page', $system );
			$row_key = 'system_page:' . $system;

			printf(
				'<tr><th scope="row">%1$s<p class="description"><code>%2$s</code></p></th><td>
					<fieldset>
						<legend class="screen-reader-text"><span>%1$s</span></legend>
						<label for="taseo-title-%3$s">%4$s</label><br />
						<input type="text" id="taseo-title-%3$s" name="taseo_settings[title_templates][%2$s]" value="%5$s" class="%6$s" data-taseo-template-input />
					</fieldset>',
				esc_html( $label ),
				esc_attr( $row_key ),
				esc_attr( 'system-page-' . $system ),
				esc_html__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_title_template( 'system_page', $system ) ),
				esc_attr( $this->template_input_class( 'title_templates', $row_key ) )
			);
			$this->render_variable_pills( 'system_page', $system, false );
			echo '</td></tr>';
		}

		echo '</table>';

		echo '<hr />';

		echo '<h2 id="taseo-custom-pages">' . esc_html__( 'Custom pages', 'the-another-seo' ) . '</h2>';

		$custom_pages = $this->custom_pages->all();

		if ( array() === $custom_pages ) {
			$this->render_custom_pages_empty_state();

			return;
		}

		echo '<table class="form-table">';

		foreach ( $custom_pages as $key => $page_label ) {
			$row_key = 'custom_page:' . $key;

			printf(
				'<tr><th scope="row">%1$s<p class="description"><code>%2$s</code></p></th><td>
					<fieldset>
						<legend class="screen-reader-text"><span>%1$s</span></legend>
						<label for="taseo-title-%3$s">%4$s</label><br />
						<input type="text" id="taseo-title-%3$s" name="taseo_settings[title_templates][%2$s]" value="%5$s" class="%6$s" data-taseo-template-input /><br />
						<label for="taseo-desc-%3$s">%7$s</label><br />
						<input type="text" id="taseo-desc-%3$s" name="taseo_settings[description_templates][%2$s]" value="%8$s" class="%9$s" data-taseo-template-input />
					</fieldset>',
				esc_html( $this->template_row_label( 'custom_page', $key ) ),
				esc_attr( $row_key ),
				esc_attr( 'custom-page-' . $key ),
				esc_html__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_title_template( 'custom_page', $key ) ),
				esc_attr( $this->template_input_class( 'title_templates', $row_key ) ),
				esc_html__( 'Meta description template', 'the-another-seo' ),
				esc_attr( $this->settings->get_description_template( 'custom_page', $key ) ),
				esc_attr( $this->template_input_class( 'description_templates', $row_key ) )
			);
			$this->render_variable_pills( 'custom_page', $key );
			echo '</td></tr>';
		}

		echo '</table>';
	}

	/**
	 * Explain how to register a custom page, when none are.
	 *
	 * Both filters are shown deliberately. Registering a row without also
	 * claiming a request produces template fields that save, redisplay, and
	 * never render — the exact mistake this text exists to prevent.
	 *
	 * @return void
	 */
	private function render_custom_pages_empty_state(): void {
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'No custom pages are registered. Another plugin can add one — a checkout screen, an account area, any page this plugin cannot know about — by registering it and then claiming the request it appears on. Both steps are needed: a registered page with no claimed request gets template fields that never render.', 'the-another-seo' )
		);

		$snippet = "add_filter( 'taseo_custom_pages', function ( \$pages ) {\n"
			. "    \$pages['checkout'] = __( 'Checkout', 'my-plugin' );\n"
			. "    return \$pages;\n"
			. "} );\n\n"
			. "add_filter( 'taseo_custom_page_context', function ( \$context ) {\n"
			. "    if ( function_exists( 'is_checkout' ) && is_checkout() ) {\n"
			. "        return array(\n"
			. "            'subtype' => 'checkout',\n"
			. "            'vars'    => array( 'title' => __( 'Checkout', 'my-plugin' ) ),\n"
			. "        );\n"
			. "    }\n\n"
			. "    return \$context;\n"
			. '} );';

		printf( '<pre><code>%s</code></pre>', esc_html( $snippet ) );
	}

	/**
	 * Render the variable pills for one template row.
	 *
	 * Core's own button component inside core's help-text element — no
	 * stylesheet is involved. The data attribute is also the only channel
	 * by which the admin script learns this row's variables: it reads the
	 * rendered pills rather than a second, separately-serialised copy of
	 * the registry, so the two cannot drift.
	 *
	 * The pill shows the variable's human label rather than its %%token%%,
	 * matching the chip that clicking it inserts. The token stays in
	 * data-taseo-template-var, which is what the script and the tests read;
	 * nothing depends on the visible text.
	 *
	 * The heading is load-bearing rather than decorative. The pills sit
	 * below the last input in the row, so without it they read as belonging
	 * to the meta description alone — which is backwards, since a click
	 * lands in whichever field was last focused and defaults to the title.
	 *
	 * @param string $object_type     Object type.
	 * @param string $object_subtype  Object subtype.
	 * @param bool   $has_description Whether the row also has a description field.
	 * @return void
	 */
	private function render_variable_pills( string $object_type, string $object_subtype, bool $has_description = true ): void {
		printf(
			'<p class="description"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Available variables', 'the-another-seo' ),
			$has_description
				? esc_html__( '— these apply to both fields above.', 'the-another-seo' )
				: ''
		);

		echo '<p class="description">';

		foreach ( $this->template_variables->get_for( $object_type, $object_subtype ) as $slug => $label ) {
			$token = '%%' . $slug . '%%';

			printf(
				'<button type="button" class="button button-small" data-taseo-template-var="%1$s" data-taseo-template-label="%2$s">%3$s</button> ',
				esc_attr( $token ),
				esc_attr( $label ),
				esc_html( $label )
			);
		}

		echo '</p>';
	}

	/**
	 * Human-readable name for one template row.
	 *
	 * Post types and taxonomies carry registered labels; render_types_tab()
	 * already reads the same properties. System pages are ours to name.
	 *
	 * Falls back to the subtype slug when nothing is registered under it: a
	 * post type whose plugin has been deactivated leaves its stored
	 * templates behind, and that row must stay identifiable and editable
	 * rather than rendering an empty heading.
	 *
	 * The row heading and the validation error both call this, so the
	 * screen and its error messages cannot describe the same row
	 * differently.
	 *
	 * @param string $object_type    'post', 'term', 'system_page', or 'custom_page'.
	 * @param string $object_subtype Post type, taxonomy, system page key, or custom page key.
	 * @return string Human-readable label.
	 */
	private function template_row_label( string $object_type, string $object_subtype ): string {
		if ( 'post' === $object_type ) {
			// A declared subtype is not a post type, so its label comes from
			// the registry. Everything else is a post type and keeps reading
			// exactly as it always did.
			if ( $this->post_subtypes->has( $object_subtype ) ) {
				$owner = $this->post_subtypes->post_type_for( $object_subtype );

				return $this->post_subtypes->for_post_type( $owner )[ $object_subtype ] ?? $object_subtype;
			}

			$object = get_post_type_object( $object_subtype );

			return isset( $object->labels->name ) ? (string) $object->labels->name : $object_subtype;
		}

		if ( 'term' === $object_type ) {
			$taxonomy = get_taxonomy( $object_subtype );

			return isset( $taxonomy->labels->name ) ? (string) $taxonomy->labels->name : $object_subtype;
		}

		if ( 'custom_page' === $object_type ) {
			return $this->custom_pages->all()[ $object_subtype ] ?? $object_subtype;
		}

		$system_labels = array(
			'home'   => __( 'Home page', 'the-another-seo' ),
			'search' => __( 'Search results', 'the-another-seo' ),
			'404'    => __( 'Not found (404)', 'the-another-seo' ),
		);

		return $system_labels[ $object_subtype ] ?? $object_subtype;
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
		echo '<tr><th scope="row">' . esc_html__( 'Default social image', 'the-another-seo' ) . '</th><td>';
		ImageField::render(
			'taseo_settings[default_social_image_id]',
			(int) $this->settings->get_default_social_image_id(),
			'taseo_settings[default_social_image_url]',
			$this->settings->get_default_social_image_url(),
			'taseo-default-social-image',
			__( 'Default social image', 'the-another-seo' )
		);
		echo '</td></tr>';
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
		echo '<tr><th scope="row">' . esc_html__( 'Logo', 'the-another-seo' ) . '</th><td>';
		ImageField::render(
			'taseo_settings[site_logo_id]',
			(int) $this->settings->get_site_logo_id(),
			'taseo_settings[site_logo_url]',
			$this->settings->get_site_logo_url(),
			'taseo-site-logo',
			__( 'Logo', 'the-another-seo' )
		);
		echo '</td></tr>';
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
	 * Sitemap tab: toggle, chunk size, operational status, regenerate.
	 *
	 * @return void
	 */
	private function render_sitemap_tab(): void {
		// A disabled family's chunks are suspended (permanently dirty via
		// SitemapFileRepository::suspend_subtype_chunks()), so without this
		// exclusion the dirty count would include a figure that never drains.
		$status = $this->sitemap_files->get_status_summary( $this->settings->get_disabled_sitemap_families() );

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

		$groups = array(
			__( 'Post types', 'the-another-seo' ) => $this->post_subtypes->flatten( $this->settings->get_enabled_post_types() ),
			__( 'Taxonomies', 'the-another-seo' ) => $this->taxonomy_labels(),
			__( 'External URL families', 'the-another-seo' ) => $this->sitemap_families->all(),
		);

		foreach ( $groups as $heading => $entries ) {
			if ( array() === $entries ) {
				continue;
			}

			echo '<h2>' . esc_html( (string) $heading ) . '</h2>';
			echo '<p>' . esc_html__( 'Unchecked entries are excluded from the sitemap. Their indexable rows — and any per-object overrides on them — are kept, so re-checking restores the URLs.', 'the-another-seo' ) . '</p>';
			echo '<table class="form-table">';

			foreach ( $entries as $key => $label ) {
				printf(
					'<tr><th scope="row">%1$s<br /><span style="font-weight: normal; color: #646970;">%2$s</span></th><td><label><input type="checkbox" name="taseo_settings[sitemap_families][]" value="%2$s" %3$s /> %4$s</label></td></tr>',
					esc_html( (string) $label ),
					esc_attr( (string) $key ),
					checked( $this->settings->is_sitemap_family_enabled( (string) $key ), true, false ),
					esc_html__( 'Include in sitemap', 'the-another-seo' )
				);
			}

			echo '</table>';
		}

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

	/**
	 * Webmaster Tools tab: site verification and tracking snippets.
	 *
	 * @return void
	 */
	private function render_webmaster_tab(): void {
		$hosts = $this->domains->get_hosts();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only domain switch.
		$raw_domain = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
		$requested  = DomainRegistry::normalize_host( $raw_domain );
		$active     = in_array( $requested, $hosts, true ) ? $requested : (string) ( $hosts[0] ?? '' );
		$default    = DomainRegistry::default_host();

		// '' is Settings' word for "the default domain", which is where the
		// flat keys live. Everything below reads and writes through $lookup so
		// the default domain keeps using them.
		$lookup = $active === $default ? '' : $active;

		$this->render_domain_nav( $hosts, $active, $default );

		printf( '<input type="hidden" name="domain" value="%s" />', esc_attr( $active ) );

		// Engine slug => whether the service publishes a file method. Labels
		// come from verification_service_label(), which the sanitizer also
		// uses, so a rejected value is named exactly as its row is.
		$services = array(
			'google'   => true,
			'bing'     => true,
			'yandex'   => true,
			'yahoo'    => false,
			'facebook' => false,
		);

		echo '<h2>' . esc_html__( 'Site verification', 'the-another-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste the code or the file name — either works, and the file name is worked out for you. Each domain must be verified on its own; codes are never shared between domains.', 'the-another-seo' ) . '</p>';
		echo '<table class="form-table">';

		foreach ( $services as $engine => $has_methods ) {
			$code   = $this->settings->get_verification_code( $engine, $lookup );
			$method = $has_methods ? $this->settings->get_verification_method( $engine, $lookup ) : Settings::METHOD_META;

			printf( '<tr><th scope="row">%s</th><td>', esc_html( self::verification_service_label( $engine ) ) );

			if ( $has_methods ) {
				$this->render_method_radios( $engine, $method );
			}

			printf(
				'<input type="text" name="taseo_settings[verify_%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
				esc_attr( $engine ),
				esc_attr( $code ),
				esc_attr( self::verification_placeholder( $engine, $method ) )
			);

			// verification_filename() is the single guard here: it answers ''
			// for an empty token and for one the server would refuse, so the
			// link is offered only for a file this install actually serves.
			$filename = Settings::METHOD_FILE === $method
				? self::verification_filename( $engine, $code )
				: '';

			if ( '' !== $filename ) {
				printf(
					'<br /><a href="%1$s" target="_blank" rel="noreferrer noopener">%1$s</a>',
					esc_url( $this->verification_file_url( $active, $filename ) )
				);
			}

			echo '</td></tr>';
		}

		echo '</table>';

		// Read the stored record directly rather than through the getters: they
		// inherit, and rendering an inherited ID into the input would save it as
		// this domain's own on the next submit, quietly turning inheritance into
		// a copy that then drifts.
		$record = '' === $lookup ? array() : $this->settings->get_domain_record( $active );

		$tracking = array(
			'analytics_ga4_id' => array( __( 'GA4 Measurement ID', 'the-another-seo' ), $this->settings->get_ga4_id(), 'G-XXXXXXXXXX' ),
			'analytics_gtm_id' => array( __( 'Tag Manager Container ID', 'the-another-seo' ), $this->settings->get_gtm_id(), 'GTM-XXXXXXX' ),
			'meta_pixel_id'    => array( __( 'Meta Pixel ID', 'the-another-seo' ), $this->settings->get_meta_pixel_id(), '123456789012345' ),
		);

		echo '<h2>' . esc_html__( 'Tracking', 'the-another-seo' ) . '</h2>';
		echo '<table class="form-table">';

		foreach ( $tracking as $key => $field ) {
			list( $label, $default_value, $hint ) = $field;

			printf(
				'<tr><th scope="row">%1$s</th><td><input type="text" name="taseo_settings[%2$s]" value="%3$s" placeholder="%4$s" /></td></tr>',
				esc_html( $label ),
				esc_attr( $key ),
				esc_attr( '' === $lookup ? $default_value : (string) ( $record[ $key ] ?? '' ) ),
				esc_attr( $this->tracking_placeholder( $lookup, $default_value, $hint ) )
			);
		}

		echo '</table>';

		if ( '' !== $this->settings->get_ga4_id( $lookup ) && '' !== $this->settings->get_gtm_id( $lookup ) ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Both a GA4 Measurement ID and a Tag Manager Container ID are set. If your Tag Manager container already fires a GA4 tag, pageviews will be counted twice.', 'the-another-seo' )
			);
		}
	}

	/**
	 * The method choice for one service.
	 *
	 * A fieldset with a screen-reader legend, matching how the Post Types tab
	 * groups its checkboxes. No JavaScript: the input's placeholder is chosen
	 * from the saved method by verification_placeholder(), so picking a radio
	 * relabels nothing until the form is saved.
	 *
	 * @since 1.0.0
	 *
	 * @param string $engine Engine slug.
	 * @param string $method Saved method.
	 * @return void
	 */
	private function render_method_radios( string $engine, string $method ): void {
		$choices = array(
			Settings::METHOD_META => __( 'Meta tag', 'the-another-seo' ),
			Settings::METHOD_FILE => __( 'File', 'the-another-seo' ),
		);

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Verification method', 'the-another-seo' ) . '</legend>';

		foreach ( $choices as $value => $choice_label ) {
			printf(
				'<label style="margin-right:1em;"><input type="radio" name="taseo_settings[verify_%1$s_method]" value="%2$s"%3$s /> %4$s</label>',
				esc_attr( $engine ),
				esc_attr( $value ),
				checked( $method, $value, false ),
				esc_html( $choice_label )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Human name of a verification service.
	 *
	 * Shared by the renderer and by the sanitizer's rejection notice, so a
	 * discarded value is named on screen exactly as its own row is.
	 *
	 * @since 1.0.0
	 *
	 * @param string $engine Engine slug.
	 * @return string Translated label, the slug itself for an unknown engine.
	 */
	private static function verification_service_label( string $engine ): string {
		return match ( $engine ) {
			'google'   => __( 'Google Search Console', 'the-another-seo' ),
			'bing'     => __( 'Bing Webmaster Tools', 'the-another-seo' ),
			'yandex'   => __( 'Yandex Webmaster', 'the-another-seo' ),
			'yahoo'    => __( 'Yahoo', 'the-another-seo' ),
			'facebook' => __( 'Meta Business Manager', 'the-another-seo' ),
			default    => $engine,
		};
	}

	/**
	 * Placeholder for a service's single verification input.
	 *
	 * The two methods take unrelated credentials — a Google meta token is
	 * [A-Za-z0-9_-]+ and its file token [a-z0-9]+, issued separately by Search
	 * Console — so one neutral hint under both radios tells the operator
	 * nothing about which of the two the field is currently asking for. Saving
	 * a meta code under the file method discards it (see sanitize_token()),
	 * which is why the field has to say what this mode expects.
	 *
	 * @since 1.0.0
	 *
	 * @param string $engine Engine slug.
	 * @param string $method Resolved method, Settings::METHOD_META for a
	 *                       service that publishes no file method.
	 * @return string Placeholder text.
	 */
	private static function verification_placeholder( string $engine, string $method ): string {
		if ( Settings::METHOD_FILE !== $method ) {
			return __( 'Verification code', 'the-another-seo' );
		}

		return match ( $engine ) {
			'google' => __( 'File name, e.g. google1a2b3c.html', 'the-another-seo' ),
			'bing'   => __( 'Token from BingSiteAuth.xml', 'the-another-seo' ),
			'yandex' => __( 'File name, e.g. yandex_9f8e7d.html', 'the-another-seo' ),
			default  => __( 'Verification code', 'the-another-seo' ),
		};
	}

	/**
	 * The public filename a service's token is served at.
	 *
	 * Mirrors VerificationFileServer::build_files() — its filename shapes and
	 * its token validation both. That validation is not redundant here: the
	 * option is writable outside this page's sanitizer (WP-CLI, a migration,
	 * a hand edit), and the server refuses to answer for a token that fails
	 * its pattern, so an unchecked link would point at a guaranteed 404.
	 * Static so the renderer can show the link without reaching into the
	 * server.
	 *
	 * @since 1.0.0
	 *
	 * @param string $engine Engine slug.
	 * @param string $token  Stored token.
	 * @return string Filename, '' when the engine publishes no file method or
	 *                the token is not one this plugin would serve.
	 */
	private static function verification_filename( string $engine, string $token ): string {
		$shape = self::TOKEN_SHAPES[ $engine ] ?? null;

		if ( null === $shape || 1 !== preg_match( $shape['pattern'], $token ) ) {
			return '';
		}

		// Explicit default: a service added to TOKEN_SHAPES without an arm
		// here shows no link, rather than a link built from Google's shape.
		return match ( $engine ) {
			'google' => 'google' . $token . '.html',
			'bing'   => VerificationFileServer::BING_FILENAME,
			'yandex' => 'yandex_' . $token . '.html',
			default  => '',
		};
	}

	/**
	 * The domain switcher for the Webmaster Tools tab.
	 *
	 * Core's own secondary-navigation pattern (`.subsubsub`, styled in
	 * wp-admin/css/common.css), the same one the Titles & Templates tab uses.
	 * Unlike that one these carry a `current` state, because they are separate
	 * views of the tab rather than in-page anchors. The trailing
	 * `<div class="clear">` is load-bearing: `.subsubsub` is `float: left`, so
	 * without it the first `<h2>` wraps alongside the nav.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, string> $hosts        Registered hosts, default first.
	 * @param string             $active       Host being edited.
	 * @param string             $default_host Default host.
	 * @return void
	 */
	private function render_domain_nav( array $hosts, string $active, string $default_host ): void {
		echo '<ul class="subsubsub">';

		$last = array_key_last( $hosts );

		foreach ( $hosts as $index => $host ) {
			$url = add_query_arg(
				array(
					'page'   => 'taseo',
					'tab'    => 'webmaster',
					'domain' => $host,
				),
				admin_url( 'options-general.php' )
			);

			if ( $host === $default_host ) {
				/* translators: %s: hostname of the site's own domain. */
				$label = sprintf( __( '%s (default)', 'the-another-seo' ), $host );
			} else {
				$label = $host;
			}

			printf(
				'<li><a href="%s" class="%s">%s</a>%s</li>',
				esc_url( $url ),
				$host === $active ? 'current' : '',
				esc_html( $label ),
				$index === $last ? '' : ' |'
			);
		}

		echo '</ul>';
		echo '<div class="clear"></div>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Domains come from your Brands. Add a URL rule to a Brand to add one here.', 'the-another-seo' )
		);
	}

	/**
	 * Public URL of a domain's verification file.
	 *
	 * Both branches start from home_url(), so a subdirectory install keeps its
	 * path; other domains then swap only the host, since nothing here knows how
	 * they are served. Dropping the path on the host-swap branch would produce
	 * a link that never reaches WordPress: VerificationFileServer strips the
	 * install's path from the request the same way on every domain, so a
	 * `/blog` install serves the file at `scheme://host/blog/<filename>`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host     Normalized host.
	 * @param string $filename Verification filename.
	 * @return string URL.
	 */
	private function verification_file_url( string $host, string $filename ): string {
		$url = (string) home_url( '/' . $filename );

		if ( DomainRegistry::default_host() === $host ) {
			return $url;
		}

		$parts  = wp_parse_url( $url );
		$parts  = is_array( $parts ) ? $parts : array();
		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/' . $filename;

		return ( '' !== $scheme ? $scheme : 'https' ) . '://' . $host . $path;
	}

	/**
	 * Placeholder for a tracking field.
	 *
	 * On a non-default domain a blank field inherits the default's ID, so the
	 * placeholder shows what will actually fire. Without it "blank" reads as
	 * "no tracking", which is the opposite of what happens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $lookup        Active domain lookup key, '' for the default.
	 * @param string $default_value The default domain's stored ID.
	 * @param string $fallback      Shape hint used when there is nothing to inherit.
	 * @return string Placeholder.
	 */
	private function tracking_placeholder( string $lookup, string $default_value, string $fallback ): string {
		if ( '' === $lookup || '' === $default_value ) {
			return $fallback;
		}

		/* translators: %s: the default domain's tracking ID. */
		return sprintf( __( '%s (inherited)', 'the-another-seo' ), $default_value );
	}

	/**
	 * Admin_post save handler.
	 *
	 * @param bool $do_exit Exit after redirect (false in tests).
	 * @return void
	 */
	public function handle_save( bool $do_exit = true ): void {
		if ( ! $this->verify_request() ) {
			return;
		}

		$raw    = isset( $_POST['taseo_settings'] ) ? (array) wp_unslash( $_POST['taseo_settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via verify_request(); sanitized in sanitize_settings().
		$tab    = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_request().
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_request().

		$previously_disabled = $this->settings->get_disabled_sitemap_families();

		$this->settings->update( $this->sanitize_settings( $raw, $tab, $domain ) );

		if ( 'sitemap' === $tab ) {
			$now_disabled = $this->settings->get_disabled_sitemap_families();

			foreach ( array_diff( $now_disabled, $previously_disabled ) as $family ) {
				$this->sitemap_assignment->handle_family_disabled( $family );
			}

			foreach ( array_diff( $previously_disabled, $now_disabled ) as $family ) {
				$this->sitemap_assignment->handle_family_enabled( $family );
			}
		}

		$errors = get_settings_errors();

		if ( array() !== $errors ) {
			// Exactly how core's options.php hands validation failures to
			// the page it redirects to.
			set_transient( 'settings_errors', $errors, 30 );
		}

		$redirect = admin_url( 'options-general.php?page=taseo&updated=1' );

		if ( array_key_exists( $tab, self::TABS ) ) {
			$redirect = add_query_arg( 'tab', $tab, $redirect );
		}

		if ( 'webmaster' === $tab ) {
			// Only advertise a domain the save actually wrote to. Carrying the
			// raw posted value through would re-open the form on a domain the
			// sanitizer just rejected, and normalizing keeps the arg in the
			// same shape the domain nav emits.
			$host = DomainRegistry::normalize_host( $domain );

			if ( '' !== $host && in_array( $host, $this->domains->get_hosts(), true ) ) {
				$redirect = add_query_arg( 'domain', $host, $redirect );
			}
		}

		$redirect = add_query_arg( 'settings-updated', 'true', $redirect );

		// phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit -- conditional exit based on testability flag.
		wp_safe_redirect( $redirect );

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Admin_post rescan handler.
	 *
	 * @param bool $do_exit Exit after redirect (false in tests).
	 * @return void
	 */
	public function handle_rescan( bool $do_exit = true ): void {
		if ( ! $this->verify_request() ) {
			return;
		}

		$this->backfill->dispatch( 'full' );

		// phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit -- conditional exit based on testability flag.
		wp_safe_redirect( admin_url( 'options-general.php?page=taseo' ) );

		if ( $do_exit ) {
			exit;
		}
	}

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
		if ( ! $this->settings->is_sitemap_enabled() || $this->sitemap_storage->is_writable() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The Another SEO: the uploads directory is not writable, so XML sitemap files cannot be generated. Fix the uploads directory permissions to resume sitemap generation.', 'the-another-seo' )
		);
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
	 * Sanitize a title/description template value.
	 *
	 * Neither sanitize_text_field() nor sanitize_textarea_field() (they
	 * share the same code path) may be used here. WordPress's underlying
	 * _sanitize_text_fields() ends with an unconditional loop that treats
	 * any substring matching /%[a-f0-9]{2}/i as a stray percent-encoded
	 * byte and deletes it. `%%date%%` contains such a substring at offset 1
	 * ("%da"), so sanitize_text_field( '%%date%%' ) silently returns
	 * '%te%%' — the row is stored corrupted, and because the mangled text
	 * no longer contains a %%token%%, TemplateResolver::extract_variables()
	 * finds nothing to reject, so validation never notices either. `date`
	 * is the only current registry slug whose first two characters are
	 * both hex digits, but any future slug with that shape would break the
	 * same way. See the round-trip test in SettingsPageTest that fails if
	 * this helper is replaced with sanitize_text_field() again.
	 *
	 * This does everything sanitize_text_field() does except the
	 * percent-octet strip: invalid-UTF8 check, tag stripping, whitespace/
	 * newline collapse to a single space, trim. Tags must still be
	 * stripped and newlines collapsed — this value is rendered into a
	 * <title> element and a meta tag.
	 *
	 * @param string $template Raw posted template.
	 * @return string Sanitized template.
	 */
	private function sanitize_template( string $template ): string {
		$template = wp_check_invalid_utf8( $template );
		$template = wp_strip_all_tags( $template );

		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $template ) );
	}

	/**
	 * Which domain a webmaster submission writes to.
	 *
	 * Three outcomes, and telling the last two apart is the whole point:
	 *
	 * - `''` — the default domain, i.e. the flat keys. This is what an absent
	 *   `domain` field means, which covers every non-webmaster tab and any
	 *   form rendered before the domain switcher existed.
	 * - a host — that domain's record under Settings::DOMAINS_KEY.
	 * - `null` — write nothing. The field carried a host that is not
	 *   registered, and there is no safe place to put the values. Treating it
	 *   as the default is the one genuinely destructive option: get_hosts() is
	 *   dynamic (a Brand's URL rule edited in another tab changes it
	 *   mid-session), so an operator can return to a stale Webmaster Tools
	 *   screen and press Save with no attacker involved — and the posted
	 *   codes would overwrite the site's own. Seeding a record on an
	 *   unregistered host is rejected too, since a record key decides which
	 *   body a public request is answered with. Discarding the edit loses
	 *   nothing that still exists.
	 *
	 * @since 1.0.0
	 *
	 * @param string $domain Posted domain field, '' when none was posted.
	 * @return string|null Lookup key, or null to write nothing.
	 */
	private function webmaster_target( string $domain ): ?string {
		if ( '' === trim( $domain ) ) {
			return '';
		}

		$host = DomainRegistry::normalize_host( $domain );

		if ( DomainRegistry::default_host() === $host ) {
			return '';
		}

		return in_array( $host, $this->domains->get_hosts(), true ) ? $host : null;
	}

	/**
	 * Sanitize a raw settings submission.
	 *
	 * Boolean and checkbox-list keys owned by the submitted tab are force-set
	 * from $raw (so unchecking the last box in a tab actually clears it);
	 * keys belonging to other tabs are merge-preserved by Settings::update(),
	 * since a tab's form never submits fields it doesn't own.
	 *
	 * @param array<string, mixed> $raw    Raw values.
	 * @param string               $tab    Active tab slug (from the posted 'tab' field).
	 * @param string               $domain Active domain (from the posted 'domain' field), '' for the default.
	 * @return array<string, mixed> Clean values.
	 */
	public function sanitize_settings( array $raw, string $tab = '', string $domain = '' ): array {
		$clean = array();

		foreach ( array( 'enabled_post_types', 'enabled_taxonomies' ) as $list_key ) {
			if ( isset( $raw[ $list_key ] ) && is_array( $raw[ $list_key ] ) ) {
				$clean[ $list_key ] = array_values( array_map( 'sanitize_key', $raw[ $list_key ] ) );
			}
		}

		foreach ( array( 'title_templates', 'description_templates' ) as $tpl_key ) {
			if ( ! isset( $raw[ $tpl_key ] ) || ! is_array( $raw[ $tpl_key ] ) ) {
				continue;
			}

			// Start from what is stored: a row whose template is rejected
			// keeps its previous value while its siblings save normally.
			// These keys hold every row, so replacing the array wholesale
			// would let one bad row discard unrelated edits.
			$stored = $this->settings->get( $tpl_key, array() );
			$rows   = is_array( $stored ) ? $stored : array();

			foreach ( $raw[ $tpl_key ] as $row_key => $template ) {
				$row_key  = (string) $row_key;
				$template = $this->sanitize_template( (string) $template );
				$parts    = explode( ':', $row_key, 2 );
				$type     = $parts[0] ?? '';
				$subtype  = $parts[1] ?? '';
				$invalid  = array();

				foreach ( TemplateResolver::extract_variables( $template ) as $variable ) {
					if ( ! $this->template_variables->is_available( $variable, $type, $subtype ) ) {
						$invalid[] = '%%' . $variable . '%%';
					}
				}

				if ( array() !== $invalid ) {
					$row_label = $this->template_row_label( $type, $subtype );

					if ( '' === $row_label ) {
						// A posted row key with no colon (not something a form
						// control can produce, but the key is attacker-
						// controlled) resolves to an empty label. Every other
						// call site here passes form-controlled type/subtype
						// pairs; this is the one whose input is not, so fall
						// back to the raw key rather than print a blank name.
						$row_label = $row_key;
					}

					add_settings_error(
						'taseo_messages',
						self::INVALID_TEMPLATE_CODE . $tpl_key . '__' . $row_key,
						sprintf(
							/* translators: 1: row label such as Products, 2: comma-separated variable tokens. */
							esc_html__( '%1$s: %2$s is not available for this content type. That field was not saved; the others were.', 'the-another-seo' ),
							esc_html( $row_label ),
							esc_html( implode( ', ', $invalid ) )
						),
						'error'
					);

					continue;
				}

				$rows[ $row_key ] = $template;
			}

			$clean[ $tpl_key ] = $rows;
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

		foreach ( array( 'default_social_image_url', 'site_logo_url' ) as $url_key ) {
			if ( isset( $raw[ $url_key ] ) ) {
				$clean[ $url_key ] = esc_url_raw( (string) $raw[ $url_key ] );
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

		$target = $this->webmaster_target( $domain );

		$webmaster = array();

		foreach ( array( 'google', 'bing', 'yandex', 'yahoo', 'facebook' ) as $engine ) {
			$code_key   = 'verify_' . $engine;
			$method_key = $code_key . '_method';
			$has_method = isset( self::TOKEN_SHAPES[ $engine ] );

			$method = Settings::METHOD_META;

			if ( $has_method && isset( $raw[ $method_key ] ) && Settings::METHOD_FILE === $raw[ $method_key ] ) {
				$method = Settings::METHOD_FILE;
			}

			if ( $has_method && isset( $raw[ $method_key ] ) ) {
				$webmaster[ $method_key ] = $method;
			}

			if ( ! isset( $raw[ $code_key ] ) ) {
				continue;
			}

			// The method is read from $raw rather than from storage because the
			// radios and the code input sit in the same row of the same form:
			// a posted code always arrives with the method it was typed under.
			// The METHOD_META fallback for a code posted without its radio is
			// therefore not reachable from this screen.
			$posted = (string) $raw[ $code_key ];

			$webmaster[ $code_key ] = Settings::METHOD_FILE === $method
				? self::sanitize_token( $engine, $posted )
				: VerificationOutput::sanitize_code( $posted );

			// A submitted value that sanitizes away is reported rather than
			// swallowed. The two methods take unrelated credentials, so
			// switching one and pressing Save clears the stored code — behind
			// a "Settings saved" notice and an empty field, unless this says
			// otherwise.
			if ( '' === $webmaster[ $code_key ] && '' !== trim( $posted ) ) {
				add_settings_error(
					'taseo_messages',
					self::INVALID_VERIFICATION_CODE . $code_key,
					sprintf(
						/* translators: %s: verification service name, such as Google Search Console. */
						esc_html__( '%s: that verification value was not saved. It is not the shape the selected method expects — the field now shows an example of what to paste.', 'the-another-seo' ),
						esc_html( self::verification_service_label( $engine ) )
					),
					'error'
				);
			}
		}

		foreach ( self::TRACKING_ID_PATTERNS as $id_key => $pattern ) {
			if ( ! isset( $raw[ $id_key ] ) ) {
				continue;
			}

			$value = trim( (string) $raw[ $id_key ] );
			$value = 'meta_pixel_id' === $id_key ? $value : strtoupper( $value );

			$webmaster[ $id_key ] = 1 === preg_match( $pattern, $value ) ? $value : '';
		}

		// A null target means the submission named a domain that is not
		// registered: nothing is written, so the values are simply discarded.
		if ( array() !== $webmaster && null !== $target ) {
			if ( '' === $target ) {
				$clean = array_merge( $clean, $webmaster );
			} else {
				// Start from what is stored: this key holds every domain's
				// record, so replacing it wholesale would discard the sibling
				// domains the submitted form never carried.
				$stored = $this->settings->get( Settings::DOMAINS_KEY, array() );
				$rows   = is_array( $stored ) ? $stored : array();
				$row    = isset( $rows[ $target ] ) && is_array( $rows[ $target ] ) ? $rows[ $target ] : array();

				$rows[ $target ] = array_merge( $row, $webmaster );

				$clean[ Settings::DOMAINS_KEY ] = $rows;
			}
		}

		if ( 'social' === $tab ) {
			$clean['open_graph_enabled'] = ! empty( $raw['open_graph_enabled'] );
			$clean['twitter_enabled']    = ! empty( $raw['twitter_enabled'] );
		}

		if ( 'schema' === $tab ) {
			$clean['breadcrumb_link_current']               = ! empty( $raw['breadcrumb_link_current'] );
			$clean['breadcrumb_include_taxonomy_ancestors'] = ! empty( $raw['breadcrumb_include_taxonomy_ancestors'] );
		}

		if ( 'types' === $tab ) {
			$clean['enabled_post_types'] = $clean['enabled_post_types'] ?? array();
			$clean['enabled_taxonomies'] = $clean['enabled_taxonomies'] ?? array();
		}

		if ( isset( $raw['sitemap_max_links'] ) ) {
			$clean['sitemap_max_links'] = max( 1, min( 1000, absint( $raw['sitemap_max_links'] ) ) );
		}

		if ( array_key_exists( 'sitemap_enabled', $raw ) ) {
			$clean['sitemap_enabled'] = ! empty( $raw['sitemap_enabled'] );
		}

		if ( 'sitemap' === $tab ) {
			$clean['sitemap_enabled'] = ! empty( $raw['sitemap_enabled'] );

			$checked = isset( $raw['sitemap_families'] ) && is_array( $raw['sitemap_families'] )
				? array_map( 'sanitize_key', $raw['sitemap_families'] )
				: array();

			// Derived from the live registry, never from the posted keys
			// alone: stale keys of vanished providers are pruned on every
			// save, and a posted key outside the registry cannot land in
			// the option.
			$clean['sitemap_disabled_families'] = array_values(
				array_diff( array_keys( $this->toggleable_subtypes() ), $checked )
			);
		}

		return $clean;
	}

	/**
	 * Reduce a pasted file value to the bare token this plugin stores.
	 *
	 * Accepts what the operator is most likely to have on their clipboard: the
	 * whole filename the service handed them, or the token alone.
	 *
	 * @since 1.0.0
	 *
	 * @param string $engine Engine slug.
	 * @param string $raw    Raw submitted value.
	 * @return string Token, '' when nothing valid survives.
	 */
	private static function sanitize_token( string $engine, string $raw ): string {
		$shape = self::TOKEN_SHAPES[ $engine ] ?? null;

		if ( null === $shape ) {
			return '';
		}

		$value = trim( $raw );

		if ( $shape['lowercase'] ) {
			$value = strtolower( $value );
		}

		if ( '' !== $shape['prefix'] && str_starts_with( $value, $shape['prefix'] ) ) {
			$value = substr( $value, strlen( $shape['prefix'] ) );
		}

		if ( '' !== $shape['suffix'] && str_ends_with( $value, $shape['suffix'] ) ) {
			$value = substr( $value, 0, -strlen( $shape['suffix'] ) );
		}

		return 1 === preg_match( $shape['pattern'], $value ) ? $value : '';
	}
}
