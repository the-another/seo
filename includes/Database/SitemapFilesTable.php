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
