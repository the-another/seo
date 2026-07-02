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
		'general'   => 'General',
		'types'     => 'Post Types & Taxonomies',
		'templates' => 'Titles & Templates',
		'social'    => 'Social Networks',
		'schema'    => 'Schema & Breadcrumbs',
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

		$this->settings->update( $this->sanitize_settings( $raw ) );

		// phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit -- conditional exit based on testability flag.
		wp_safe_redirect( admin_url( 'options-general.php?page=taseo&updated=1' ) );

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
