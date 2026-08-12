<?php
/**
 * Verification Migration Notice
 *
 * @package TheAnotherSEO
 * @since 0.5.0
 */

namespace TheAnother\Plugin\SEO\Admin;

use TheAnother\Plugin\SEO\HookManager;
use TheAnother\Plugin\SEO\Verification\MethodMigration;

/**
 * Class MigrationNotice
 *
 * Reports what the verification-method migration had to discard. Collapsing a
 * service to one credential can only lose a value for Google, whose file
 * method issues a credential unrelated to its meta tag — and losing one
 * silently is how an operator discovers it months later from a de-verified
 * property.
 *
 * @since 0.5.0
 */
class MigrationNotice {

	/**
	 * Register hooks.
	 *
	 * @since 0.5.0
	 *
	 * @param HookManager $hook_manager Hook manager.
	 * @return void
	 */
	public function init( HookManager $hook_manager ): void {
		$hook_manager->register_action( 'admin_notices', array( $this, 'render' ) );
		$hook_manager->register_action( 'admin_post_taseo_dismiss_migration_notice', array( $this, 'dismiss' ), 10, 0 );
	}

	/**
	 * Print the notice when the migration recorded a loss.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dropped = get_option( MethodMigration::NOTICE_OPTION, array() );

		if ( ! is_array( $dropped ) || array() === $dropped ) {
			return;
		}

		$lines = array();

		foreach ( $dropped as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['engine'] ) ) {
				continue;
			}

			$label  = $this->label_for( (string) $entry['engine'] );
			$domain = isset( $entry['domain'] ) && '' !== $entry['domain']
				? (string) $entry['domain']
				: __( 'the default domain', 'the-another-seo' );

			/* translators: 1: service name, 2: domain. */
			$lines[] = sprintf( __( '%1$s on %2$s kept its file; its meta tag code was removed.', 'the-another-seo' ), $label, $domain );
		}

		if ( array() === $lines ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'The Another SEO: verification now uses one method per service.', 'the-another-seo' );
		echo '</p><ul>';

		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}

		echo '</ul><p>';
		echo esc_html__( 'Re-add a code from the service if you would rather verify by meta tag.', 'the-another-seo' );
		printf(
			' <a href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=taseo_dismiss_migration_notice' ), 'taseo_save_settings', 'taseo_settings_nonce' ) ),
			esc_html__( 'Dismiss', 'the-another-seo' )
		);
		echo '</p></div>';
	}

	/**
	 * Clear the stored notice.
	 *
	 * Mirrors SettingsPage::verify_request(): the same taseo_settings_nonce,
	 * verified against the same taseo_save_settings action, then the same
	 * manage_options capability check. The link render() builds carries that
	 * nonce via wp_nonce_url().
	 *
	 * @since 0.5.0
	 *
	 * @param bool $do_exit Exit after redirect (false in tests).
	 * @return void
	 */
	public function dismiss( bool $do_exit = true ): void {
		$nonce = isset( $_GET['taseo_settings_nonce'] )
			? sanitize_text_field( wp_unslash( $_GET['taseo_settings_nonce'] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'taseo_save_settings' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( MethodMigration::NOTICE_OPTION );

		// phpcs:ignore WordPressVIPMinimum.Security.ExitAfterRedirect.NoExit -- conditional exit based on testability flag.
		wp_safe_redirect( admin_url( 'options-general.php?page=taseo&tab=webmaster' ) );

		if ( $do_exit ) {
			exit;
		}
	}

	/**
	 * Human label for one engine slug.
	 *
	 * A class constant cannot hold these — PHP const expressions cannot call
	 * __() — so the lookup lives here instead, matching the same three labels
	 * SettingsPage's Webmaster Tools tab already shows for these engines.
	 *
	 * @since 0.5.0
	 *
	 * @param string $engine Engine slug.
	 * @return string Translated label, or the raw slug when the engine is
	 *                unrecognized (a stale or hand-edited option).
	 */
	private function label_for( string $engine ): string {
		return match ( $engine ) {
			'google' => __( 'Google Search Console', 'the-another-seo' ),
			'bing'   => __( 'Bing Webmaster Tools', 'the-another-seo' ),
			'yandex' => __( 'Yandex Webmaster', 'the-another-seo' ),
			default  => $engine,
		};
	}
}
