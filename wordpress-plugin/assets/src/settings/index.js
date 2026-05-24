import { createRoot } from '@wordpress/element';
import { SettingsApp } from './SettingsApp';

const el = document.getElementById( 'orca-dam-settings-root' );
if ( el ) {
	createRoot( el ).render( <SettingsApp /> );
}
