import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

function Edit() {
	const blockProps = useBlockProps();

	return (
		<nav
			{ ...blockProps }
			className="taseo-breadcrumbs"
			aria-label="Breadcrumb"
		>
			<a href="#home" onClick={ ( e ) => e.preventDefault() }>
				{ __( 'Home', 'the-another-seo' ) }
			</a>
			{ ' › ' }
			<a href="#section" onClick={ ( e ) => e.preventDefault() }>
				{ __( 'Section', 'the-another-seo' ) }
			</a>
			{ ' › ' }
			<span aria-current="page">
				{ __( 'Current page', 'the-another-seo' ) }
			</span>
		</nav>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
