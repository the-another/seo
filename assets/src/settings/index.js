/**
 * Mount a chip editing surface over every Titles & Templates input.
 *
 * The inputs mark themselves with data-taseo-template-input, and each one's
 * variables come from the pills the server rendered in the same row — the only
 * serialisation of the registry on the page. Nothing here is localised into
 * the script, so the suggestions can never disagree with the pills next to
 * them.
 */

import { createRoot } from '@wordpress/element';

import TemplateField from './TemplateField';

/**
 * Render a surface next to every template input on the page.
 *
 * @return {void}
 */
function mount() {
	document
		.querySelectorAll( '[data-taseo-template-input]' )
		.forEach( ( input ) => {
			const host = document.createElement( 'div' );

			input.parentNode.insertBefore( host, input.nextSibling );

			// The input is hidden by the field itself, once it has rendered —
			// see TemplateField's own effect. If this script never loads at
			// all, or the surface fails to render, the input stays visible and
			// editable and the tab keeps working.
			createRoot( host ).render( <TemplateField input={ input } /> );
		} );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
