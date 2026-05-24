/**
 * Extends wp.media to inject an "ORCA" tab into every media frame
 * (block editor, featured image, classic editor, Elementor). The tab mounts a
 * React picker that proxies through WP REST to ORCA.
 */
/* global orcaDam */
import { mountOrcaTab } from '../picker/mount';

( function attach() {
	if ( typeof window.wp === 'undefined' || ! window.wp.media ) {
		// wp.media not yet available — wait for it
		document.addEventListener( 'DOMContentLoaded', attach, { once: true } );
		return;
	}
	const media = window.wp.media;
	if ( media.__orcaInstalled ) {
		return;
	}
	media.__orcaInstalled = true;

	// 1. Custom router tab
	const OriginalRouter = media.view.MediaFrame.Select.prototype.browseRouter;
	media.view.MediaFrame.Select.prototype.browseRouter = function (
		routerView
	) {
		OriginalRouter.call( this, routerView );
		routerView.set( {
			orca: {
				text: 'ORCA DAM',
				priority: 30,
			},
		} );
	};

	// 2. State + content view for the "orca" tab
	const OrcaState = media.controller.State.extend( {
		defaults: {
			id: 'orca',
			title: 'ORCA DAM',
			menu: 'default',
			toolbar: 'select',
			content: 'orca',
			router: 'browse',
			multiple: false,
		},
	} );

	const OrcaView = media.View.extend( {
		className: 'orca-dam-frame-region',
		initialize() {
			this.$el.css( {
				padding: '16px',
				height: '100%',
				overflow: 'auto',
			} );
		},
		render() {
			mountOrcaTab( this.el, {
				onPick: async ( orcaAssetId ) => {
					const wpAttachment = await importShell( orcaAssetId );
					if ( ! wpAttachment ) {
						return;
					}
					// Hand the attachment to the frame: select it then trigger toolbar's
					// primary action (Insert / Set featured image / etc.).
					const frame = this._frame;
					const attachment =
						media.model.Attachment.create( wpAttachment );
					media.model.Attachments.all.add( attachment );
					frame.state().get( 'selection' ).reset( [ attachment ] );
				},
			} );
			return this;
		},
	} );

	// 3. Register the state on every frame as it's constructed
	const FrameSelect = media.view.MediaFrame.Select;
	const originalCreateStates = FrameSelect.prototype.createStates;
	FrameSelect.prototype.createStates = function () {
		originalCreateStates.apply( this, arguments );
		this.states.add( [ new OrcaState() ] );
	};

	const originalBindHandlers = FrameSelect.prototype.bindHandlers;
	FrameSelect.prototype.bindHandlers = function () {
		originalBindHandlers.apply( this, arguments );
		this.on( 'content:create:orca', ( contentRegion ) => {
			const view = new OrcaView( { controller: this } );
			view._frame = this;
			contentRegion.view = view;
		} );
	};

	async function importShell( orcaAssetId ) {
		const res = await fetch( `${ orcaDam.restUrl }/import`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': orcaDam.nonce,
			},
			body: JSON.stringify( { asset_id: orcaAssetId } ),
		} );
		if ( ! res.ok ) {
			// eslint-disable-next-line no-console
			console.error( '[orca-dam] import failed', await res.text() );
			return null;
		}
		return res.json();
	}
} )();
