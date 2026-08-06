<?php
/**
 * Sitemap Storage
 *
 * @package TheAnotherSEO
 * @since 0.3.0
 */

declare(strict_types=1);

namespace TheAnother\Plugin\SEO\Sitemap;

/**
 * Class SitemapStorage
 *
 * The single owner of every filesystem touch in the sitemap module. Paths
 * are resolved through wp_upload_dir() on every call — never cached across
 * requests — so an offload plugin that filters upload_dir to a stream
 * wrapper (s3://…) transparently relocates every write, read, exists-check,
 * and delete to object storage. PHP's fopen-family functions (which
 * WP_Filesystem_Direct, file_exists() and readfile() use) all support
 * registered stream wrappers.
 *
 * Mirror-style offloaders that only sync attachments do not filter
 * upload_dir; there the files simply stay local and behave exactly as
 * before — correct in both worlds, no special-casing.
 *
 * @since 0.3.0
 */
class SitemapStorage {

	/**
	 * Directory under uploads/ holding all chunk files.
	 *
	 * @var string
	 */
	public const DIRECTORY = 'taseo-sitemaps';

	/**
	 * Absolute path of the sitemap directory.
	 *
	 * @since 0.3.0
	 * @return string Path without trailing slash.
	 */
	public function get_directory_path(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( (string) $uploads['basedir'] ) . self::DIRECTORY;
	}

	/**
	 * File name for a chunk: {subtype}-sitemap-{n}.xml.
	 *
	 * @since 0.3.0
	 * @param array<string, mixed> $chunk Registry row (object_subtype, chunk_number).
	 * @return string File name.
	 */
	public function get_file_name( array $chunk ): string {
		return sprintf( '%s-sitemap-%d.xml', (string) $chunk['object_subtype'], (int) $chunk['chunk_number'] );
	}

	/**
	 * Absolute path of a chunk's file.
	 *
	 * @since 0.3.0
	 * @param array<string, mixed> $chunk Registry row.
	 * @return string Path.
	 */
	public function get_file_path( array $chunk ): string {
		return trailingslashit( $this->get_directory_path() ) . $this->get_file_name( $chunk );
	}

	/**
	 * Whether sitemap files can be written at all.
	 *
	 * @since 0.3.0
	 * @return bool Uploads dir resolved and writable.
	 */
	public function is_writable(): bool {
		$uploads = wp_upload_dir();

		return empty( $uploads['error'] ) && wp_is_writable( (string) $uploads['basedir'] );
	}

	/**
	 * Whether uploads resolve to a PHP stream wrapper (offloaded storage).
	 *
	 * The Apache static-serve rules are suppressed in this mode: a rewrite
	 * target that cannot exist on the local disk is dead configuration.
	 *
	 * @since 0.3.0
	 * @return bool Stream-wrapped.
	 */
	public function is_stream_wrapped(): bool {
		$uploads = wp_upload_dir();

		return str_contains( (string) $uploads['basedir'], '://' );
	}

	/**
	 * Write one chunk's file (creates the directory as needed).
	 *
	 * @since 0.3.0
	 * @param array<string, mixed> $chunk    Registry row.
	 * @param string               $contents File contents.
	 * @return bool Success.
	 */
	public function write( array $chunk, string $contents ): bool {
		if ( ! wp_mkdir_p( $this->get_directory_path() ) ) {
			return false;
		}

		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->put_contents(
			$this->get_file_path( $chunk ),
			$contents,
			defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644
		);
	}

	/**
	 * Remove a chunk's file if present.
	 *
	 * @since 0.3.0
	 * @param array<string, mixed> $chunk Registry row.
	 * @return void
	 */
	public function delete( array $chunk ): void {
		$path = $this->get_file_path( $chunk );

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Whether a chunk's file exists.
	 *
	 * @since 0.3.0
	 * @param array<string, mixed> $chunk Registry row.
	 * @return bool Exists.
	 */
	public function exists( array $chunk ): bool {
		return file_exists( $this->get_file_path( $chunk ) );
	}

	/**
	 * Stream a chunk's file to the output buffer.
	 *
	 * @since 0.3.0
	 * @param array<string, mixed> $chunk Registry row.
	 * @return bool True when the file existed and was streamed.
	 */
	public function stream( array $chunk ): bool {
		$path = $this->get_file_path( $chunk );

		if ( ! file_exists( $path ) ) {
			return false;
		}

		// Never generated here — only reads what the sweep already built.
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- streaming a plugin-generated file (local or stream-wrapped) is the designed fallback path.

		return true;
	}
}
