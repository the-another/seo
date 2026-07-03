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
	public const DB_VERSION = '1.1.0';

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
			sitemap_file_id BIGINT UNSIGNED NULL,
			last_modified DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY object_lookup (object_type, object_subtype, object_id),
			KEY object_lookup_by_id (object_type, object_id),
			KEY is_indexable (is_indexable),
			KEY sitemap_file_id (sitemap_file_id)
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

	/**
	 * Version currently recorded in the database, '0' when never installed.
	 *
	 * @return string Installed schema version.
	 */
	public static function get_installed_version(): string {
		return (string) get_option( self::DB_VERSION_OPTION, '0' );
	}
}
