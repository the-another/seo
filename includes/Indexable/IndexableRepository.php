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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Custom table, prepared below.
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
