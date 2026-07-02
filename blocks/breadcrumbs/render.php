<?php
/**
 * Breadcrumbs block render callback.
 *
 * @package TheAnotherSEO
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$taseo_renderer = \TheAnother\Plugin\SEO\Container::get_instance()->get( 'breadcrumb_renderer' );
$taseo_html     = $taseo_renderer->render();

if ( '' === $taseo_html ) {
	return;
}

?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated attributes. ?>>
	<?php echo $taseo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes internally. ?>
</div>
<?php
