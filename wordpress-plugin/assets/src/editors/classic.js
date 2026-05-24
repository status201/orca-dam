/**
 * Classic editor: the "Insert from ORCA" button opens a media frame whose
 * router defaults to the ORCA tab. wp.media is already extended by gutenberg.js
 * so this is just the click handler.
 */
/* global Element */
document.addEventListener( 'click', ( event ) => {
	const target =
		event.target instanceof Element
			? event.target.closest( '[data-orca-classic]' )
			: null;
	if ( ! target ) {
		return;
	}
	event.preventDefault();
	const frame = wp.media( {
		title: 'ORCA DAM',
		button: { text: 'Insert' },
		multiple: false,
	} );
	frame.on( 'open', () => frame.setState( 'orca' ) );
	frame.on( 'select', () => {
		const attachment = frame.state().get( 'selection' ).first().toJSON();
		wp.media.editor.insert(
			`<img src="${ attachment.url }" alt="${
				attachment.alt || ''
			}" class="wp-image-${ attachment.id }" data-orca-asset-id="${
				attachment.orca?.asset_id || ''
			}" />`
		);
	} );
	frame.open();
} );
