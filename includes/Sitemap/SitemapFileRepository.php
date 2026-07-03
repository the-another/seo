<?php
/**
 * Sitemap File Repository
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Sitemap;

use TheAnother\Plugin\SEO\Database\SitemapFilesTable;

/**
 * Class SitemapFileRepository
 *
 * All reads/writes against wp_taseo_sitemap_files. Slot claims and releases
 * are single conditional UPDATEs so concurrent saves can never overshoot the
 * cap or underflow the counter — a claim that affects zero rows means
 * another process took the last slot and the caller re-runs its search.
 */
class SitemapFileRepository {

	/**
	 * Fetch one registry row.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return array<string, mixed>|null Row or null.
	 */
	public function get( int $chunk_id ): ?array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $chunk_id ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Lowest-numbered chunk for a subtype that still has room.
	 *
	 * @param string $object_subtype Post type or taxonomy slug.
	 * @param int    $cap            Configured links-per-file cap.
	 * @return array<string, mixed>|null Row or null when every chunk is full.
	 */
	public function find_lowest_open_chunk( string $object_subtype, int $cap ): ?array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_subtype = %s AND link_count < %d ORDER BY chunk_number ASC LIMIT 1",
				$object_subtype,
				$cap
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomically claim one slot in a chunk.
	 *
	 * The conditional WHERE makes the read-then-write race impossible: if a
	 * concurrent save took the last slot after our search, zero rows are
	 * affected and the caller re-runs the search instead of overshooting.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @param int $cap      Configured links-per-file cap.
	 * @return bool True when the slot was claimed.
	 */
	public function claim_slot( int $chunk_id, int $cap ): bool {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET link_count = link_count + 1, is_dirty = 1 WHERE id = %d AND link_count < %d",
				$chunk_id,
				$cap
			)
		);
		// phpcs:enable

		return (int) $affected > 0;
	}

	/**
	 * Create the next chunk for a subtype with its first slot pre-claimed.
	 *
	 * Two processes can race to create the same chunk_number; the unique key
	 * on (object_subtype, chunk_number) rejects the loser, who gets null and
	 * re-runs the search (the winner's chunk now has room).
	 *
	 * @param string $object_subtype Post type or taxonomy slug.
	 * @return array<string, mixed>|null New row, or null on a lost creation race.
	 */
	public function create_chunk( string $object_subtype ): ?array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(MAX(chunk_number), 0) + 1 FROM {$table} WHERE object_subtype = %s",
				$object_subtype
			)
		);

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (object_subtype, chunk_number, link_count, is_dirty) VALUES (%s, %d, 1, 1)",
				$object_subtype,
				$next
			)
		);
		// phpcs:enable

		if ( false === $inserted || 0 === (int) $inserted ) {
			return null;
		}

		return $this->get( (int) $wpdb->insert_id );
	}

	/**
	 * Give one slot back and report how many links remain.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return int Remaining link_count (0 means the chunk should be deleted).
	 */
	public function release_slot( int $chunk_id ): int {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET link_count = link_count - 1, is_dirty = 1 WHERE id = %d AND link_count > 0",
				$chunk_id
			)
		);

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT link_count FROM {$table} WHERE id = %d", $chunk_id )
		);
		// phpcs:enable
	}

	/**
	 * Delete a registry row (the physical file is the writer's problem).
	 *
	 * Conditioned on link_count = 0: a concurrent assign() can reclaim this
	 * chunk (bump it back to 1 link) between the caller's zero-link read and
	 * this delete. Without the guard we would delete a row a live object now
	 * points at, orphaning it from the sitemap forever. With the guard, that
	 * race just makes the delete affect zero rows — the claimer's chunk
	 * survives, and the caller is expected to treat a false return as "leave
	 * it alone", not as an error.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return bool True when the row was deleted, false when a concurrent
	 *              claim reclaimed the chunk first.
	 */
	public function delete_chunk( int $chunk_id ): bool {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND link_count = 0", $chunk_id )
		);
		// phpcs:enable

		return (int) $affected > 0;
	}

	/**
	 * Flag one chunk for rebuild.
	 *
	 * @param int $chunk_id Chunk row ID.
	 * @return void
	 */
	public function mark_dirty( int $chunk_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( SitemapFilesTable::get_table_name(), array( 'is_dirty' => 1 ), array( 'id' => $chunk_id ) );
	}

	/**
	 * Flag every chunk for rebuild (permalink structure changed).
	 *
	 * @return void
	 */
	public function mark_all_dirty(): void {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "UPDATE {$table} SET is_dirty = 1" );
	}

	/**
	 * A bounded batch of chunks awaiting rebuild.
	 *
	 * @param int $limit Batch size.
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function get_dirty_chunks( int $limit ): array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE is_dirty = 1 ORDER BY id ASC LIMIT %d", $limit ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many chunks still await rebuild.
	 *
	 * @return int Dirty count.
	 */
	public function count_dirty(): int {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_dirty = 1" );
	}

	/**
	 * Every registry row — the whole table is small by design (~4,000 rows
	 * at 4M objects), so reading it in full is the intended access pattern.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function get_all_chunks(): array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY object_subtype ASC, chunk_number ASC", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Clear the dirty flag after a successful file write.
	 *
	 * @param int         $chunk_id      Chunk row ID.
	 * @param string|null $last_modified MAX(last_modified) across members, or null when empty.
	 * @return void
	 */
	public function update_after_rebuild( int $chunk_id, ?string $last_modified ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			SitemapFilesTable::get_table_name(),
			array(
				'is_dirty'      => 0,
				'generated_at'  => gmdate( 'Y-m-d H:i:s' ),
				'last_modified' => $last_modified,
			),
			array( 'id' => $chunk_id )
		);
	}

	/**
	 * Operational summary for the settings status panel.
	 *
	 * @return array{subtypes: array<string, array{chunks: int, links: int}>, dirty: int, last_generated: ?string} Summary.
	 */
	public function get_status_summary(): array {
		global $wpdb;

		$table = SitemapFilesTable::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT object_subtype, COUNT(*) AS chunks, COALESCE(SUM(link_count), 0) AS links
			FROM {$table} GROUP BY object_subtype ORDER BY object_subtype ASC",
			ARRAY_A
		);

		$last_generated = $wpdb->get_var( "SELECT MAX(generated_at) FROM {$table}" );
		// phpcs:enable

		$subtypes = array();

		foreach ( ( is_array( $rows ) ? $rows : array() ) as $row ) {
			$subtypes[ (string) $row['object_subtype'] ] = array(
				'chunks' => (int) $row['chunks'],
				'links'  => (int) $row['links'],
			);
		}

		return array(
			'subtypes'       => $subtypes,
			'dirty'          => $this->count_dirty(),
			'last_generated' => is_string( $last_generated ) && '' !== $last_generated ? $last_generated : null,
		);
	}
}
