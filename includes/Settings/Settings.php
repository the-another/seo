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
	 * Engine slug => settings key for verification meta-tag codes.
	 *
	 * @var array<string, string>
	 */
	private const VERIFICATION_KEYS = array(
		'google'   => 'verify_google',
		'bing'     => 'verify_bing',
		'yandex'   => 'verify_yandex',
		'yahoo'    => 'verify_yahoo',
		'facebook' => 'verify_facebook',
	);

	/**
	 * Engine slug => settings key for verification files. Yahoo retired its
	 * own webmaster tools; Meta does not publish its file body format.
	 *
	 * @var array<string, string>
	 */
	private const VERIFICATION_FILE_KEYS = array(
		'google' => 'verify_google_file',
		'bing'   => 'verify_bing_file',
		'yandex' => 'verify_yandex_file',
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
	 * Sitewide fallback social image URL, overriding the attachment.
	 *
	 * @return string URL, or '' when unset.
	 */
	public function get_default_social_image_url(): string {
		return (string) $this->get( 'default_social_image_url', '' );
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
	 * Logo URL for the Organization node, overriding the attachment.
	 *
	 * @return string URL, or '' when unset.
	 */
	public function get_site_logo_url(): string {
		return (string) $this->get( 'site_logo_url', '' );
	}

	/**
	 * SameAs profile URLs.
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

	/**
	 * Verification meta-tag code for one service.
	 *
	 * @param string $engine Engine slug.
	 * @return string Code, '' when unset or unknown.
	 */
	public function get_verification_code( string $engine ): string {
		$key = self::VERIFICATION_KEYS[ $engine ] ?? '';

		return '' === $key ? '' : (string) $this->get( $key, '' );
	}

	/**
	 * Verification file value for one service. Google and Yandex store the
	 * full filename; Bing stores only the token (its filename is fixed).
	 *
	 * @param string $engine Engine slug.
	 * @return string Value, '' when unset or unknown.
	 */
	public function get_verification_file( string $engine ): string {
		$key = self::VERIFICATION_FILE_KEYS[ $engine ] ?? '';

		return '' === $key ? '' : (string) $this->get( $key, '' );
	}

	/**
	 * GA4 measurement ID.
	 *
	 * @return string ID or ''.
	 */
	public function get_ga4_id(): string {
		return (string) $this->get( 'analytics_ga4_id', '' );
	}

	/**
	 * Google Tag Manager container ID.
	 *
	 * @return string ID or ''.
	 */
	public function get_gtm_id(): string {
		return (string) $this->get( 'analytics_gtm_id', '' );
	}

	/**
	 * Meta Pixel ID. Returned as a string, never cast to int: a leading
	 * zero is significant and casting would silently change the pixel.
	 *
	 * @return string ID or ''.
	 */
	public function get_meta_pixel_id(): string {
		return (string) $this->get( 'meta_pixel_id', '' );
	}
}
