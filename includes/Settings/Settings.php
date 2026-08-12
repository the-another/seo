<?php
/**
 * Settings Service
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Settings;

use TheAnother\Plugin\SEO\Domains\DomainRegistry;

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
	 * Per-domain records, keyed by normalized host.
	 *
	 * The default domain is deliberately absent: it keeps the flat keys that
	 * predate this map, which is what lets the feature ship without a
	 * migration.
	 *
	 * @since 0.5.0
	 *
	 * @var string
	 */
	public const DOMAINS_KEY = 'verification_domains';

	/**
	 * Verification by meta tag.
	 *
	 * @since 0.5.0
	 *
	 * @var string
	 */
	public const METHOD_META = 'meta';

	/**
	 * Verification by served file.
	 *
	 * @since 0.5.0
	 *
	 * @var string
	 */
	public const METHOD_FILE = 'file';

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
	 * Engine slug => settings key for the chosen verification method. Only the
	 * three services that offer both a tag and a file appear here; Yahoo and
	 * Meta publish no file method, so they have nothing to choose.
	 *
	 * @since 0.5.0
	 *
	 * @var array<string, string>
	 */
	private const VERIFICATION_METHOD_KEYS = array(
		'google' => 'verify_google_method',
		'bing'   => 'verify_bing_method',
		'yandex' => 'verify_yandex_method',
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

		if ( is_array( $templates ) && ! empty( $templates[ $key ] ) ) {
			return (string) $templates[ $key ];
		}

		// %%excerpt%% is only ever guaranteed resolvable for post and term
		// rows (TemplateVariables::get_for() adds it there and nowhere
		// else). A custom_page has no built-in source of an excerpt-like
		// field — the registering plugin decides what, if anything, its
		// page's description is — so handing back that token as a default
		// would be a guess the type can't back up, and the validator would
		// reject it on every save until the plugin author overrides it. An
		// empty template means "no meta description", which MetaOutput
		// already handles.
		return 'custom_page' === $object_type ? '' : '%%excerpt%%';
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
	 * @param string $object_subtype Post type, taxonomy, or post subtype name.
	 * @param string $inherits_from  Owning post type, whose default applies
	 *                               when the subtype has none of its own.
	 * @return string Schema type.
	 */
	public function get_schema_type( string $object_subtype, string $inherits_from = '' ): string {
		$stored = $this->get( 'schema_types', array() );

		if ( is_array( $stored ) && ! empty( $stored[ $object_subtype ] ) ) {
			return (string) $stored[ $object_subtype ];
		}

		if ( isset( self::SCHEMA_TYPE_DEFAULTS[ $object_subtype ] ) ) {
			return self::SCHEMA_TYPE_DEFAULTS[ $object_subtype ];
		}

		// A subtype is not in the defaults table — only post types are — so it
		// inherits its owner's. Auctions and items split out of `product` are
		// still products, and defaulting them to WebPage would quietly drop
		// the Product markup the post type had before it was split.
		return self::SCHEMA_TYPE_DEFAULTS[ $inherits_from ] ?? 'WebPage';
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
	 * Family keys excluded from sitemap output.
	 *
	 * Stored as a disabled-list so absence means included — a newly
	 * registered family is on without a migration or a save.
	 *
	 * @since 0.3.0
	 *
	 * @return array<int, string> Disabled family keys.
	 */
	public function get_disabled_sitemap_families(): array {
		$stored = $this->get( 'sitemap_disabled_families', array() );

		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}

	/**
	 * Whether one external URL family is included in sitemap output.
	 *
	 * @since 0.3.0
	 *
	 * @param string $family Family key.
	 * @return bool Included.
	 */
	public function is_sitemap_family_enabled( string $family ): bool {
		return ! in_array( $family, $this->get_disabled_sitemap_families(), true );
	}

	/**
	 * Verification meta-tag code for one service on one domain.
	 *
	 * @since 0.5.0 Added the $host parameter.
	 *
	 * @param string $engine Engine slug.
	 * @param string $host   Normalized host, '' for the default domain.
	 * @return string Code, '' when unset or unknown.
	 */
	public function get_verification_code( string $engine, string $host = '' ): string {
		$key = self::VERIFICATION_KEYS[ $engine ] ?? '';

		return '' === $key ? '' : $this->get_domain_value( $key, $host );
	}

	/**
	 * Verification file value for one service on one domain. Google and Yandex
	 * store the full filename; Bing stores only the token (its filename is
	 * fixed).
	 *
	 * @since 0.5.0 Added the $host parameter.
	 *
	 * @param string $engine Engine slug.
	 * @param string $host   Normalized host, '' for the default domain.
	 * @return string Value, '' when unset or unknown.
	 */
	public function get_verification_file( string $engine, string $host = '' ): string {
		$key = self::VERIFICATION_FILE_KEYS[ $engine ] ?? '';

		return '' === $key ? '' : $this->get_domain_value( $key, $host );
	}

	/**
	 * How one service verifies on one domain.
	 *
	 * Anything other than an explicit `file` resolves to `meta`: an engine with
	 * no file method, an unmigrated record, and a corrupted value all mean the
	 * same thing here, and printing a tag is the safe reading of "unknown" —
	 * serving an unexpected file at a guessable path is not.
	 *
	 * Does not inherit the default domain, matching codes and files: a domain's
	 * method is its own.
	 *
	 * @since 0.5.0
	 *
	 * @param string $engine Engine slug.
	 * @param string $host   Normalized host, '' for the default domain.
	 * @return string Settings::METHOD_META or Settings::METHOD_FILE.
	 */
	public function get_verification_method( string $engine, string $host = '' ): string {
		$key = self::VERIFICATION_METHOD_KEYS[ $engine ] ?? '';

		if ( '' === $key ) {
			return self::METHOD_META;
		}

		return self::METHOD_FILE === $this->get_domain_value( $key, $host )
			? self::METHOD_FILE
			: self::METHOD_META;
	}

	/**
	 * One domain's stored record.
	 *
	 * The argument is normalized first, like every other public host-taking
	 * method here: records are keyed on normalized hosts, so a caller passing
	 * `WWW.Example.com` must resolve the same record as `example.com`.
	 *
	 * @since 0.5.0
	 *
	 * @param string $host Host, normalized or not.
	 * @return array<string, string> Record, empty when the domain has none.
	 */
	public function get_domain_record( string $host ): array {
		$host = DomainRegistry::normalize_host( $host );
		$all  = $this->get( self::DOMAINS_KEY, array() );

		if ( ! is_array( $all ) || ! isset( $all[ $host ] ) || ! is_array( $all[ $host ] ) ) {
			return array();
		}

		return array_map( 'strval', $all[ $host ] );
	}

	/**
	 * Read one key for one domain.
	 *
	 * Verification codes and files never inherit: a webmaster property is
	 * verified on its own, and handing a brand domain the default's code would
	 * quietly guarantee a failed verification rather than an obvious blank
	 * field. Tracking IDs do inherit, because brands commonly share one
	 * analytics property and re-typing it per domain is how they drift apart.
	 *
	 * @since 0.5.0
	 *
	 * @param string $key     Settings key.
	 * @param string $host    Normalized host, '' for the default domain.
	 * @param bool   $inherit Fall back to the default domain when blank.
	 * @return string Value.
	 */
	private function get_domain_value( string $key, string $host, bool $inherit = false ): string {
		$host = DomainRegistry::normalize_host( $host );

		if ( '' === $host || DomainRegistry::default_host() === $host ) {
			return (string) $this->get( $key, '' );
		}

		$record = $this->get_domain_record( $host );
		$value  = isset( $record[ $key ] ) ? (string) $record[ $key ] : '';

		if ( '' !== $value || ! $inherit ) {
			return $value;
		}

		return (string) $this->get( $key, '' );
	}

	/**
	 * GA4 measurement ID for one domain.
	 *
	 * @since 0.5.0 Added the $host parameter.
	 *
	 * @param string $host Normalized host, '' for the default domain.
	 * @return string ID or ''.
	 */
	public function get_ga4_id( string $host = '' ): string {
		return $this->get_domain_value( 'analytics_ga4_id', $host, true );
	}

	/**
	 * Google Tag Manager container ID for one domain.
	 *
	 * @since 0.5.0 Added the $host parameter.
	 *
	 * @param string $host Normalized host, '' for the default domain.
	 * @return string ID or ''.
	 */
	public function get_gtm_id( string $host = '' ): string {
		return $this->get_domain_value( 'analytics_gtm_id', $host, true );
	}

	/**
	 * Meta Pixel ID for one domain. Returned as a string, never cast to int: a
	 * leading zero is significant and casting would silently change the pixel.
	 *
	 * @since 0.5.0 Added the $host parameter.
	 *
	 * @param string $host Normalized host, '' for the default domain.
	 * @return string ID or ''.
	 */
	public function get_meta_pixel_id( string $host = '' ): string {
		return $this->get_domain_value( 'meta_pixel_id', $host, true );
	}
}
