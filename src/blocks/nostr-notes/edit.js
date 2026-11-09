/**
 * Retrieves the translation of text.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-i18n/
 */
import { __ } from '@wordpress/i18n';

/**
 * React hook that is used to mark the block wrapper element.
 * It provides all the necessary props like the class name.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-block-editor/#useblockprops
 */
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl } from '@wordpress/components';

/**
 * Lets webpack process CSS, SASS or SCSS files referenced in JavaScript files.
 * Those files can contain any CSS code that gets applied to the editor.
 *
 * @see https://www.npmjs.com/package/@wordpress/scripts#using-css
 */
import './editor.scss';

/**
 * The edit function describes the structure of your block in the context of the
 * editor. This represents what the editor will render when the block is used.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-edit-save/#edit
 *
 * @return {Element} Element to render.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { limit, orderby, order } = attributes;
	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Note Settings', 'burrowsync-for-nostr' ) }>
					<RangeControl
						label={ __( 'Number of Notes', 'burrowsync-for-nostr' ) }
						value={ limit }
						onChange={ ( value ) =>
							setAttributes( { limit: value } )
						}
						min={ 1 }
						max={ 50 }
					/>
					<SelectControl
						label={ __( 'Order By', 'burrowsync-for-nostr' ) }
						value={ orderby }
						options={ [
							{
								label: __( 'Date', 'burrowsync-for-nostr' ),
								value: 'date',
							},
							{
								label: __( 'Title', 'burrowsync-for-nostr' ),
								value: 'title',
							},
							{
								label: __( 'Modified', 'burrowsync-for-nostr' ),
								value: 'modified',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { orderby: value } )
						}
					/>
					<SelectControl
						label={ __( 'Order', 'burrowsync-for-nostr' ) }
						value={ order }
						options={ [
							{
								label: __( 'Descending', 'burrowsync-for-nostr' ),
								value: 'DESC',
							},
							{
								label: __( 'Ascending', 'burrowsync-for-nostr' ),
								value: 'ASC',
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { order: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div className="nostr-notes-block-placeholder">
				<p>{ __( 'Nostr Notes', 'burrowsync-for-nostr' ) }</p>
				<p className="description">
					{ __( 'Displaying', 'burrowsync-for-nostr' ) } { limit }{ ' ' }
					{ __( 'notes', 'burrowsync-for-nostr' ) }
				</p>
			</div>
		</div>
	);
}

