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
		'og_image_url',
		'twitter_title',
		'twitter_description',
		'twitter_image_id',
		'twitter_image_url',
		'breadcrumb_title',
		'schema_disabled',
	);

	/**
	 * Maximum images stored per URL. Google's protocol allows 1,000; 50
	 * bounds row size well under the TEXT column limit and covers any real
	 * page.
	 *
	 * @var int
	 */
	public const SITEMAP_IMAGES_CAP = 50;

	/**
	 * Insert the row or update its synced columns only.
	 *
	 * @param string $object_type    'post', 'term', 'system_page', or 'custom_page'.
	 * @param string $object_subtype Post type / taxonomy / system page key / custom page key.
	 * @param int    $object_id      Post or term ID; 0 for system pages and custom pages.
	 * @param array  $fields         permalink?, is_indexable?, last_modified?, images?.
	 * @return void
	 */
	public function upsert_synced_fields( string $object_type, string $object_subtype, int $object_id, array $fields ): void {
		global $wpdb;

		$table = IndexablesTable::get_table_name();

		/**
		 * Filters the image URLs stored for a sitemap entry.
		 *
		 * Runs on every synced write — post/term sync, backfill, and the
		 * push API — so one hook covers all sources. The base value is the
		 * caller-supplied list: the featured image for posts, the `images`
		 * argument for pushed URLs, empty for terms.
		 *
		 * @since 0.3.0
		 *
		 * @param array<int, string> $images         Absolute image URLs.
		 * @param string             $object_type    'post', 'term', 'system_page', or 'custom_page'.
		 * @param string             $object_subtype Post type / taxonomy / page key / family key.
		 * @param int                $object_id      Post or term ID; family URL ID; 0 otherwise.
		 */
		$images = apply_filters(
			'taseo_sitemap_images',
			isset( $fields['images'] ) && is_array( $fields['images'] ) ? $fields['images'] : array(),
			$object_type,
			$object_subtype,
			$object_id
		);

		$images      = $this->sanitize_sitemap_images( $images );
		$images_json = array() === $images ? null : wp_json_encode( $images );

		$args = array(
			$object_type,
			$object_subtype,
			$object_id,
			$fields['permalink'] ?? '',
			empty( $fields['is_indexable'] ) ? 0 : 1,
			$fields['last_modified'] ?? gmdate( 'Y-m-d H:i:s' ),
		);

		// wpdb::prepare() has no NULL placeholder, so an empty image list
		// inlines the literal; VALUES(sitemap_images) carries either through
		// the duplicate-key branch.
		$images_placeholder = null === $images_json ? 'NULL' : '%s';

		if ( null !== $images_json ) {
			$args[] = $images_json;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Custom table, prepared below; images placeholder is a literal 'NULL' or '%s', so the sniff can't see it as a 7th placeholder, and the spread args count varies with it.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table}
				(object_type, object_subtype, object_id, permalink, is_indexable, last_modified, sitemap_images)
			VALUES (%s, %s, %d, %s, %d, %s, {$images_placeholder})
			ON DUPLICATE KEY UPDATE
				permalink = VALUES(permalink),
				is_indexable = VALUES(is_indexable),
				last_modified = VALUES(last_modified),
				sitemap_images = VALUES(sitemap_images)",
			...$args
		);
		$wpdb->query( $sql );
		// phpcs:enable

		$this->purge_stale_subtypes( $object_type, $object_subtype, $object_id );

		/**
		 * Fires after an indexable row's synced columns are written.
		 *
		 * The sitemap module reconciles chunk assignment on this: assign when
		 * newly indexable, mark the chunk dirty on edits, release the slot
		 * when the row stops being indexable.
		 *
		 * @since 1.0.0
		 *
		 * @param string $object_type    'post', 'term', 'system_page', or 'custom_page'.
		 * @param string $object_subtype Post type / taxonomy / system page key / custom page key.
		 * @param int    $object_id      Post or term ID; 0 for system pages and custom pages.
		 */
		do_action( 'taseo_indexable_synced', $object_type, $object_subtype, $object_id );
	}

	/**
	 * Drop rows for the same object left behind under a different subtype.
	 *
	 * The unique key is (object_type, object_subtype, object_id), so a post
	 * whose subtype changes does not update its old row — it inserts a second
	 * one. The stale row keeps is_indexable = 1 and keeps its chunk slot, so
	 * the URL would be published from two sitemap files at once. Installing a
	 * plugin that declares subtypes does exactly this, to every post of that
	 * type, on the first rescan.
	 *
	 * Deleting through delete() rather than a bulk DELETE is deliberate: the
	 * chunk slot is released by the `taseo_indexable_deleting` listener, and a
	 * row removed behind its back would leak the slot and leave a permanently
	 * over-counted chunk.
	 *
	 * Scoped to posts. A term cannot change taxonomy, and custom_page ids are
	 * provider-chosen and collide across families by design — vendor_store:42
	 * and vendor_items:42 are one vendor's two URLs, and purging by
	 * (object_type, object_id) there would make each push delete the other.
	 *
	 * @param string $object_type    Object type.
	 * @param string $object_subtype Subtype just written.
	 * @param int    $object_id      Object ID.
	 * @return void
	 */
	private function purge_stale_subtypes( string $object_type, string $object_subtype, int $object_id ): void {
		if ( 'post' !== $object_type ) {
			return;
		}

		global $wpdb;

		$table = IndexablesTable::get_table_name();

		// Served by the object_lookup_by_id key.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_subtype FROM {$table}
				WHERE object_type = %s AND object_id = %d AND object_subtype != %s",
				$object_type,
				$object_id,
				$object_subtype
			)
		);
		// phpcs:enable

		if ( ! is_array( $stale ) ) {
			return;
		}

		foreach ( $stale as $subtype ) {
			$this->delete( $object_type, (string) $subtype, $object_id );
		}
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
	 * and is stored as NULL. Unknown keys are dropped. schema_disabled is a
	 * NOT NULL TINYINT(1) column, so it is coerced to 0/1 instead — writing
	 * NULL there fails the UPDATE outright under MySQL strict mode.
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

			if ( 'schema_disabled' === $column ) {
				$data[ $column ] = empty( $value ) ? 0 : 1;
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

	/**
	 * Keep only absolute http(s) URLs, capped at SITEMAP_IMAGES_CAP.
	 *
	 * @param mixed $images Filter output; anything non-array counts as none.
	 * @return array<int, string> Clean URLs.
	 */
	private function sanitize_sitemap_images( $images ): array {
		if ( ! is_array( $images ) ) {
			return array();
		}

		$clean = array();

		foreach ( $images as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}

			if ( ! str_starts_with( $url, 'http://' ) && ! str_starts_with( $url, 'https://' ) ) {
				continue;
			}

			$url = esc_url_raw( $url, array( 'http', 'https' ) );

			if ( '' === $url ) {
				continue;
			}

			$clean[] = $url;

			if ( self::SITEMAP_IMAGES_CAP === count( $clean ) ) {
				break;
			}
		}

		return $clean;
	}
}
