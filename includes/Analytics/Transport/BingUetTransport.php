<?php
/**
 * Bing UET Transport
 *
 * @package TheAnotherSEO
 * @since 1.5.0
 */

namespace TheAnother\Plugin\SEO\Analytics\Transport;

/**
 * Class BingUetTransport
 *
 * Microsoft's Universal Event Tracking tag, with two deviations from the
 * published snippet, both deliberate:
 *
 * - bat.js is requested over https rather than protocol-relative. The plugin
 *   serves no HTTP pages, and a protocol-relative URL here is a downgrade path
 *   and nothing else.
 * - The queue variable is uetq for the first tag and uetq_<id> for each
 *   subsequent one. The snippet assigns w[u] = new UET(o), so a second copy
 *   sharing uetq would clobber the first tag's queue; a distinct variable name
 *   per tag is Microsoft's own multi-tag guidance. bat.js is requested once and
 *   served from cache for the rest.
 *
 * Microsoft publishes no no-JS fallback, so none is emitted.
 *
 * @since 1.5.0
 */
class BingUetTransport implements TagTransport {

	use FlattensTags;

	/**
	 * Print one UET tag per ID.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => validated IDs.
	 * @return void
	 */
	public function emit_primary( array $tags ): void {
		foreach ( $this->ids( $tags ) as $index => $id ) {
			$queue = 0 === $index ? 'uetq' : 'uetq_' . $id;

			wp_print_inline_script_tag(
				'(function(w,d,t,r,u){var f,n,i;w[u]=w[u]||[],f=function(){'
				. 'var o={ti:"' . $id . '"};o.q=w[u],w[u]=new UET(o),w[u].push("pageLoad")},'
				. 'n=d.createElement(t),n.src=r,n.async=1,'
				. 'n.onload=n.onreadystatechange=function(){var s=this.readyState;'
				. 's&&s!=="loaded"&&s!=="complete"||(f(),n.onload=n.onreadystatechange=null,'
				. 'i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i))},'
				. 'i=d.getElementsByTagName(t)[0],i.parentNode.insertBefore(n,i)})'
				. '(window,document,"script","https://bat.bing.com/bat.js","' . $queue . '");'
			);
		}
	}

	/**
	 * No no-JS half: Microsoft publishes none.
	 *
	 * @since 1.5.0
	 *
	 * @param array<string, array<int, string>> $tags Vendor key => validated IDs.
	 * @return void
	 */
	public function emit_noscript( array $tags ): void {
	}
}
