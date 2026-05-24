import { createRoot } from '@wordpress/element';
import { Picker } from './Picker';

export function mountOrcaTab( el, { onPick } ) {
	const root = createRoot( el );
	root.render( <Picker onPick={ onPick } /> );
	return () => root.unmount();
}
