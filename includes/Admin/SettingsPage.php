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
use TheAnother\Plugin\SEO\Meta\TemplateResolver;
use TheAnother\Plugin\SEO\Meta\TemplateVariables;
use TheAnother\Plugin\SEO\Settings\Settings;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileRepository;
use TheAnother\Plugin\SEO\Sitemap\SitemapFileWriter;
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
	 * Verification meta-tag settings keys.
	 *
	 * @var array<int, string>
	 */
	private const VERIFICATION_CODE_KEYS = array(
		'verify_google',
		'verify_bing',
		'verify_yandex',
		'verify_yahoo',
		'verify_facebook',
	);

	/**
	 * Verification file settings keys => validation pattern.
	 *
	 * Anchored, and allowing no slash or dot beyond the single extension:
	 * these values are compared against an incoming request path.
	 *
	 * @var array<string, string>
	 */
	private const VERIFICATION_FILE_PATTERNS = array(
		'verify_google_file' => '/^google[a-z0-9]+\.html$/',
		'verify_bing_file'   => '/^[A-Za-z0-9]+$/',
		'verify_yandex_file' => '/^yandex_[a-z0-9]+\.html$/',
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
	 * @param SitemapFileWriter     $sitemap_writer     Sitemap writer (writability probe).
	 * @param SitemapSweeper        $sitemap_sweeper    Sitemap sweeper (regenerate action).
	 * @param TemplateVariables     $template_variables Template variables registry (per-row pills).
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly IndexableBackfill $backfill,
		private readonly SitemapFileRepository $sitemap_files,
		private readonly SitemapFileWriter $sitemap_writer,
		private readonly SitemapSweeper $sitemap_sweeper,
		private readonly TemplateVariables $template_variables
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
	 * Depends on core's bundled jquery-ui-autocomplete — the same component
	 * wp-admin/js/user-suggest.js and the link modal use — so nothing
	 * reaches the page that WordPress does not already ship, and core's
	 * existing .ui-autocomplete styles apply without a stylesheet of ours.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'taseo-settings',
			THE_ANOTHER_SEO_PLUGIN_URL . 'assets/js/settings.js',
			array( 'jquery-ui-autocomplete' ),
			THE_ANOTHER_SEO_VERSION,
			true
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
		echo '<table class="form-table">';

		foreach ( $this->settings->get_enabled_post_types() as $type ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>
					<input type="text" name="taseo_settings[title_templates][post:%2$s]" value="%3$s" class="%7$s" placeholder="%4$s" data-taseo-template-input />
					<input type="text" name="taseo_settings[description_templates][post:%2$s]" value="%5$s" class="%8$s" placeholder="%6$s" data-taseo-template-input />',
				esc_html( $type ),
				esc_attr( $type ),
				esc_attr( $this->settings->get_title_template( 'post', $type ) ),
				esc_attr__( 'Title template', 'the-another-seo' ),
				esc_attr( $this->settings->get_description_template( 'post', $type ) ),
				esc_attr__( 'Description template', 'the-another-seo' ),
				esc_attr( $this->template_input_class( 'title_templates', 'post:' . $type ) ),
				esc_attr( $this->template_input_class( 'description_templates', 'post:' . $type ) )
			);
			$this->render_variable_pills( 'post', $type );
			echo '</td></tr>';
		}

		foreach ( $this->settings->get_enabled_taxonomies() as $tax ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>
					<input type="text" name="taseo_settings[title_templates][term:%2$s]" value="%3$s" class="%5$s" data-taseo-template-input />
					<input type="text" name="taseo_settings[description_templates][term:%2$s]" value="%4$s" class="%6$s" data-taseo-template-input />',
				esc_html( $tax ),
				esc_attr( $tax ),
				esc_attr( $this->settings->get_title_template( 'term', $tax ) ),
				esc_attr( $this->settings->get_description_template( 'term', $tax ) ),
				esc_attr( $this->template_input_class( 'title_templates', 'term:' . $tax ) ),
				esc_attr( $this->template_input_class( 'description_templates', 'term:' . $tax ) )
			);
			$this->render_variable_pills( 'term', $tax );
			echo '</td></tr>';
		}

		// System pages.
		foreach ( array( 'home', 'search', '404' ) as $system ) {
			printf(
				'<tr><th scope="row">%1$s</th><td><input type="text" name="taseo_settings[title_templates][system_page:%2$s]" value="%3$s" class="%4$s" data-taseo-template-input />',
				esc_html( $system ),
				esc_attr( $system ),
				esc_attr( $this->settings->get_title_template( 'system_page', $system ) ),
				esc_attr( $this->template_input_class( 'title_templates', 'system_page:' . $system ) )
			);
			$this->render_variable_pills( 'system_page', $system );
			echo '</td></tr>';
		}

		echo '</table>';
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
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Object subtype.
	 * @return void
	 */
	private function render_variable_pills( string $object_type, string $object_subtype ): void {
		echo '<p class="description">';

		foreach ( array_keys( $this->template_variables->get_for( $object_type, $object_subtype ) ) as $slug ) {
			$token = '%%' . $slug . '%%';

			printf(
				'<button type="button" class="button button-small" data-taseo-template-var="%1$s">%1$s</button> ',
				esc_attr( $token )
			);
		}

		echo '</p>';
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

	/**
	 * Webmaster Tools tab: site verification and tracking snippets.
	 *
	 * @return void
	 */
	private function render_webmaster_tab(): void {
		$services = array(
			'google'   => array( __( 'Google Search Console', 'the-another-seo' ), 'verify_google', 'verify_google_file', __( 'File name, e.g. google1a2b3c.html', 'the-another-seo' ) ),
			'bing'     => array( __( 'Bing Webmaster Tools', 'the-another-seo' ), 'verify_bing', 'verify_bing_file', __( 'Token from BingSiteAuth.xml', 'the-another-seo' ) ),
			'yandex'   => array( __( 'Yandex Webmaster', 'the-another-seo' ), 'verify_yandex', 'verify_yandex_file', __( 'File name, e.g. yandex_9f8e7d.html', 'the-another-seo' ) ),
			'yahoo'    => array( __( 'Yahoo', 'the-another-seo' ), 'verify_yahoo', '', '' ),
			'facebook' => array( __( 'Meta Business Manager', 'the-another-seo' ), 'verify_facebook', '', '' ),
		);

		echo '<h2>' . esc_html__( 'Site verification', 'the-another-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste the verification code or the whole meta tag — either works. Verification tags are printed on the front page only.', 'the-another-seo' ) . '</p>';
		echo '<table class="form-table">';

		foreach ( $services as $engine => $service ) {
			list( $label, $code_key, $file_key, $file_hint ) = $service;

			printf(
				'<tr><th scope="row">%1$s</th><td><input type="text" name="taseo_settings[%2$s]" value="%3$s" class="regular-text" placeholder="%4$s" />',
				esc_html( $label ),
				esc_attr( $code_key ),
				esc_attr( $this->settings->get_verification_code( $engine ) ),
				esc_attr__( 'Verification code', 'the-another-seo' )
			);

			if ( '' !== $file_key ) {
				$file_value = $this->settings->get_verification_file( $engine );

				printf(
					'<br /><input type="text" name="taseo_settings[%1$s]" value="%2$s" class="regular-text" placeholder="%3$s" />',
					esc_attr( $file_key ),
					esc_attr( $file_value ),
					esc_attr( $file_hint )
				);

				if ( '' !== $file_value ) {
					$filename = 'bing' === $engine ? VerificationFileServer::BING_FILENAME : $file_value;

					printf(
						' <a href="%1$s" target="_blank" rel="noreferrer noopener">%1$s</a>',
						esc_url( home_url( '/' . $filename ) )
					);
				}
			}

			echo '</td></tr>';
		}

		echo '</table>';

		echo '<h2>' . esc_html__( 'Tracking', 'the-another-seo' ) . '</h2>';
		echo '<table class="form-table">';
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[analytics_ga4_id]" value="%s" placeholder="G-XXXXXXXXXX" /></td></tr>',
			esc_html__( 'GA4 Measurement ID', 'the-another-seo' ),
			esc_attr( $this->settings->get_ga4_id() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[analytics_gtm_id]" value="%s" placeholder="GTM-XXXXXXX" /></td></tr>',
			esc_html__( 'Tag Manager Container ID', 'the-another-seo' ),
			esc_attr( $this->settings->get_gtm_id() )
		);
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" name="taseo_settings[meta_pixel_id]" value="%s" placeholder="123456789012345" /></td></tr>',
			esc_html__( 'Meta Pixel ID', 'the-another-seo' ),
			esc_attr( $this->settings->get_meta_pixel_id() )
		);
		echo '</table>';

		if ( '' !== $this->settings->get_ga4_id() && '' !== $this->settings->get_gtm_id() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Both a GA4 Measurement ID and a Tag Manager Container ID are set. If your Tag Manager container already fires a GA4 tag, pageviews will be counted twice.', 'the-another-seo' )
			);
		}
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

		$raw = isset( $_POST['taseo_settings'] ) ? (array) wp_unslash( $_POST['taseo_settings'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via verify_request(); sanitized in sanitize_settings().
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via verify_request().

		$this->settings->update( $this->sanitize_settings( $raw, $tab ) );

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
		if ( ! $this->settings->is_sitemap_enabled() || $this->sitemap_writer->is_writable() ) {
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
	 * Sanitize a raw settings submission.
	 *
	 * Boolean and checkbox-list keys owned by the submitted tab are force-set
	 * from $raw (so unchecking the last box in a tab actually clears it);
	 * keys belonging to other tabs are merge-preserved by Settings::update(),
	 * since a tab's form never submits fields it doesn't own.
	 *
	 * @param array<string, mixed> $raw Raw values.
	 * @param string               $tab Active tab slug (from the posted 'tab' field).
	 * @return array<string, mixed> Clean values.
	 */
	public function sanitize_settings( array $raw, string $tab = '' ): array {
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
				$template = sanitize_text_field( (string) $template );
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
					add_settings_error(
						'taseo_messages',
						self::INVALID_TEMPLATE_CODE . $tpl_key . '__' . $row_key,
						sprintf(
							/* translators: 1: row label such as post:product, 2: comma-separated variable tokens. */
							esc_html__( '%1$s: %2$s is not available for this content type. That field was not saved; the others were.', 'the-another-seo' ),
							esc_html( $row_key ),
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

		foreach ( self::VERIFICATION_CODE_KEYS as $code_key ) {
			if ( isset( $raw[ $code_key ] ) ) {
				$clean[ $code_key ] = VerificationOutput::sanitize_code( (string) $raw[ $code_key ] );
			}
		}

		foreach ( self::VERIFICATION_FILE_PATTERNS as $file_key => $pattern ) {
			if ( ! isset( $raw[ $file_key ] ) ) {
				continue;
			}

			$value = trim( (string) $raw[ $file_key ] );
			$value = 'verify_bing_file' === $file_key ? $value : strtolower( $value );

			$clean[ $file_key ] = 1 === preg_match( $pattern, $value ) ? $value : '';
		}

		foreach ( self::TRACKING_ID_PATTERNS as $id_key => $pattern ) {
			if ( ! isset( $raw[ $id_key ] ) ) {
				continue;
			}

			$value = trim( (string) $raw[ $id_key ] );
			$value = 'meta_pixel_id' === $id_key ? $value : strtoupper( $value );

			$clean[ $id_key ] = 1 === preg_match( $pattern, $value ) ? $value : '';
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
		}

		return $clean;
	}
}
