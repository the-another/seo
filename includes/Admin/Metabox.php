<?php
/**
 * SEO Metabox
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

namespace TheAnother\Plugin\SEO\Admin;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Indexable\IndexableRepository;
use TheAnother\Plugin\SEO\Settings\Settings;
use WP_Post;
use WP_Term;

/**
 * Class Metabox
 *
 * Per-object override fields on post-edit and term-edit screens. Values are
 * stored in the indexable row's override columns; blank = "no override".
 */
class Metabox {

	/**
	 * Field => sanitizer map. Order matters for render.
	 *
	 * @var array<string, string>
	 */
	private const FIELDS = array(
		'title'               => 'text',
		'description'         => 'textarea',
		'canonical_url'       => 'url',
		'robots_noindex'      => 'checkbox',
		'robots_nofollow'     => 'checkbox',
		'robots_noarchive'    => 'checkbox',
		'og_title'            => 'text',
		'og_description'      => 'textarea',
		'og_image_id'         => 'image_id',
		'og_image_url'        => 'url',
		'twitter_title'       => 'text',
		'twitter_description' => 'textarea',
		'twitter_image_id'    => 'image_id',
		'twitter_image_url'   => 'url',
		'breadcrumb_title'    => 'text',
		'schema_disabled'     => 'checkbox',
	);

	/**
	 * Admin screens that render image fields.
	 *
	 * Term screens carry the same picker as the post metabox, since
	 * render_term_fields() reuses render_fields().
	 *
	 * @var array<int, string>
	 */
	private const PICKER_SCREENS = array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php' );

	/**
	 * Constructor.
	 *
	 * @param IndexableRepository $repository Repository.
	 * @param Settings            $settings   Settings.
	 */
	public function __construct(
		private readonly IndexableRepository $repository,
		private readonly Settings $settings
	) {
	}

	/**
	 * Register hooks.
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'add_meta_boxes', array( $this, 'register_post_metabox' ) );
		$hook_manager->register_action( 'save_post', array( $this, 'handle_save_post' ), 20 );
		$hook_manager->register_action( 'edited_term', array( $this, 'handle_save_term' ), 20, 3 );
		$hook_manager->register_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media_picker' ) );

		$hook_manager->register_action(
			'admin_init',
			function () use ( $hook_manager ): void {
				foreach ( $this->settings->get_enabled_taxonomies() as $taxonomy ) {
					$hook_manager->register_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_term_fields' ) );
				}
			}
		);
	}

	/**
	 * Register the metabox for enabled post types.
	 *
	 * @return void
	 */
	public function register_post_metabox(): void {
		add_meta_box(
			'taseo_meta',
			__( 'SEO', 'the-another-seo' ),
			array( $this, 'render_post_metabox' ),
			$this->settings->get_enabled_post_types(),
			'normal',
			'default'
		);
	}

	/**
	 * Enqueue the image picker on screens that render image fields.
	 *
	 * @param string $hook_suffix Current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_media_picker( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, self::PICKER_SCREENS, true ) ) {
			return;
		}

		ImageField::enqueue();
	}

	/**
	 * Render fields on the post edit screen.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	public function render_post_metabox( WP_Post $post ): void {
		$row = $this->repository->find( 'post', $post->post_type, (int) $post->ID );

		$this->render_fields( $row );
	}

	/**
	 * Render fields on the term edit screen.
	 *
	 * @param WP_Term $term Term.
	 * @return void
	 */
	public function render_term_fields( WP_Term $term ): void {
		$row = $this->repository->find( 'term', $term->taxonomy, (int) $term->term_id );

		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'SEO', 'the-another-seo' ) . '</th><td>';
		$this->render_fields( $row );
		echo '</td></tr>';
	}

	/**
	 * Shared field markup.
	 *
	 * @param array<string, mixed>|null $row Indexable row or null.
	 * @return void
	 */
	private function render_fields( ?array $row ): void {
		wp_nonce_field( 'taseo_save_meta', 'taseo_meta_nonce' );

		foreach ( self::FIELDS as $field => $type ) {
			$value = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
			$label = ucwords( str_replace( '_', ' ', $field ) );
			$name  = 'taseo_meta[' . $field . ']';

			// A url field whose _id sibling is an image_id gets its input
			// rendered by ImageField alongside that attachment ID, so it
			// must not also render on its own here — derived from FIELDS
			// rather than naming og_image_url/twitter_image_url, so a future
			// image slot is covered without touching this loop again.
			$id_sibling = str_replace( '_url', '_id', $field );

			if ( 'url' === $type && 'image_id' === ( self::FIELDS[ $id_sibling ] ?? '' ) ) {
				continue;
			}

			echo '<p>';

			if ( 'image_id' === $type ) {
				$url_field = str_replace( '_id', '_url', $field );

				echo '<label>' . esc_html( $label ) . '</label><br />';
				ImageField::render(
					$name,
					(int) $value,
					'taseo_meta[' . $url_field . ']',
					isset( $row[ $url_field ] ) ? (string) $row[ $url_field ] : '',
					'taseo-meta-' . str_replace( '_', '-', $field )
				);
			} elseif ( 'checkbox' === $type ) {
				echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( '1', $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
			} elseif ( 'textarea' === $type ) {
				echo '<label>' . esc_html( $label ) . '<br /><textarea name="' . esc_attr( $name ) . '" rows="2" class="large-text">' . esc_textarea( $value ) . '</textarea></label>';
			} else {
				echo '<label>' . esc_html( $label ) . '<br /><input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="large-text" /></label>';
			}

			echo '</p>';
		}
	}

	/**
	 * Saves post overrides.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_save_post( int $post_id ): void {
		if ( ! isset( $_POST['taseo_meta_nonce'], $_POST['taseo_meta'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['taseo_meta_nonce'] ) ), 'taseo_save_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return;
		}

		$clean = $this->sanitize_submission( (array) wp_unslash( $_POST['taseo_meta'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.

		$this->repository->save_overrides( 'post', $post->post_type, $post_id, $clean );
	}

	/**
	 * Saves term overrides.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 * @return void
	 */
	public function handle_save_term( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! isset( $_POST['taseo_meta_nonce'], $_POST['taseo_meta'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['taseo_meta_nonce'] ) ), 'taseo_save_meta' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$clean = $this->sanitize_submission( (array) wp_unslash( $_POST['taseo_meta'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.

		$this->repository->save_overrides( 'term', $taxonomy, $term_id, $clean );
	}

	/**
	 * Sanitize a raw taseo_meta submission. Every known field is present in
	 * the result; blanks/unchecked boxes come back as '' (which
	 * save_overrides stores as NULL = "no override").
	 *
	 * @param array<string, mixed> $raw Raw submission.
	 * @return array<string, mixed> Clean columns.
	 */
	public function sanitize_submission( array $raw ): array {
		$clean = array();

		foreach ( self::FIELDS as $field => $type ) {
			$value = $raw[ $field ] ?? '';

			$clean[ $field ] = match ( $type ) {
				'text'     => sanitize_text_field( (string) $value ),
				'textarea' => sanitize_textarea_field( (string) $value ),
				'url'      => esc_url_raw( (string) $value ),
				'image_id' => '' === $value ? '' : absint( $value ),
				'checkbox' => '' === $value ? '' : '1',
			};
		}

		return $clean;
	}
}
