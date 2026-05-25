/* global orcaDam */
import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	SearchControl,
	Spinner,
	SelectControl,
	FlexBlock,
	Flex,
	Button,
} from '@wordpress/components';
import { AssetGrid } from './AssetGrid';
import { OrcaLogo } from '../components/OrcaLogo';

const SORT_OPTIONS = [
	{ label: __( 'Newest', 'orca-dam-picker' ), value: 'date_desc' },
	{ label: __( 'Oldest', 'orca-dam-picker' ), value: 'date_asc' },
	{
		label: __( 'Recently uploaded', 'orca-dam-picker' ),
		value: 'upload_desc',
	},
	{
		label: __( 'Earliest uploaded', 'orca-dam-picker' ),
		value: 'upload_asc',
	},
	{ label: __( 'Name A → Z', 'orca-dam-picker' ), value: 'name_asc' },
	{ label: __( 'Name Z → A', 'orca-dam-picker' ), value: 'name_desc' },
	{ label: __( 'Largest first', 'orca-dam-picker' ), value: 'size_desc' },
	{ label: __( 'Smallest first', 'orca-dam-picker' ), value: 'size_asc' },
	{ label: __( 'Path A → Z', 'orca-dam-picker' ), value: 's3key_asc' },
	{ label: __( 'Path Z → A', 'orca-dam-picker' ), value: 's3key_desc' },
];

export function Picker( { onPick } ) {
	const [ query, setQuery ] = useState( '' );
	const [ debounced, setDebounced ] = useState( '' );
	const [ sort, setSort ] = useState( 'date_desc' );
	const [ folder, setFolder ] = useState( '' );
	const [ folders, setFolders ] = useState( [] );
	const [ pages, setPages ] = useState( [] );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ hasMore, setHasMore ] = useState( false );
	const [ total, setTotal ] = useState( 0 );
	const [ selectedId, setSelectedId ] = useState( null );

	useEffect( () => {
		const id = setTimeout( () => setDebounced( query ), 250 );
		return () => clearTimeout( id );
	}, [ query ] );

	useEffect( () => {
		setPages( [] );
		setPage( 1 );
		setSelectedId( null );
	}, [ debounced, sort, folder ] );

	useEffect( () => {
		fetch( `${ orcaDam.restUrl }/folders`, {
			headers: { 'X-WP-Nonce': orcaDam.nonce },
		} )
			.then( ( r ) => r.json() )
			.then( ( body ) => {
				const list = body?.folders || [];
				setFolders( Array.isArray( list ) ? list : [] );
			} )
			.catch( () => setFolders( [] ) );
	}, [] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		const params = new URLSearchParams( {
			q: debounced,
			sort,
			page: String( page ),
			per_page: '24',
			type: 'image',
		} );
		if ( folder ) {
			params.set( 'folder', folder );
		}

		fetch( `${ orcaDam.restUrl }/assets/search?${ params }`, {
			headers: { 'X-WP-Nonce': orcaDam.nonce },
		} )
			.then( ( r ) => r.json() )
			.then( ( body ) => {
				if ( cancelled ) {
					return;
				}
				const data = Array.isArray( body?.data ) ? body.data : [];
				setPages( ( prev ) =>
					page === 1 ? [ data ] : [ ...prev, data ]
				);
				const meta = body?.meta || {};
				setHasMore(
					( meta.current_page || page ) < ( meta.last_page || page )
				);
				setTotal( meta.total || 0 );
			} )
			.catch( () => {
				setHasMore( false );
				setTotal( 0 );
			} )
			.finally( () => ! cancelled && setLoading( false ) );

		return () => {
			cancelled = true;
		};
	}, [ debounced, sort, folder, page ] );

	const flat = pages.flat();
	const handlePick = useCallback(
		( asset ) => {
			setSelectedId( asset.id );
			if ( onPick ) {
				onPick( asset.id );
			}
		},
		[ onPick ]
	);

	const folderOptions = [
		{ label: __( 'All folders', 'orca-dam-picker' ), value: '' },
		...folders.map( ( f ) => ( { label: f, value: f } ) ),
	];

	return (
		<div className="orca-dam-picker">
			<Flex align="center" gap={ 3 } style={ { marginBottom: 12 } }>
				<OrcaLogo size={ 28 } style={ { flexShrink: 0 } } />
				<FlexBlock>
					<SearchControl
						value={ query }
						onChange={ setQuery }
						placeholder={ __(
							'Search ORCA assets…',
							'orca-dam-picker'
						) }
						__nextHasNoMarginBottom
					/>
				</FlexBlock>
				<SelectControl
					value={ folder }
					onChange={ setFolder }
					options={ folderOptions }
					__nextHasNoMarginBottom
				/>
				<SelectControl
					value={ sort }
					onChange={ setSort }
					options={ SORT_OPTIONS }
					__nextHasNoMarginBottom
				/>
			</Flex>
			<AssetGrid
				assets={ flat }
				onPick={ handlePick }
				selectedId={ selectedId }
			/>
			{ loading && (
				<div style={ { textAlign: 'center', padding: 12 } }>
					<Spinner />
				</div>
			) }
			{ ! loading && flat.length > 0 && (
				<div
					style={ {
						textAlign: 'center',
						padding: 12,
						display: 'flex',
						flexDirection: 'column',
						alignItems: 'center',
						gap: 8,
					} }
				>
					<span style={ { fontSize: 12, opacity: 0.7 } }>
						{ sprintf(
							/* translators: 1: number of assets shown, 2: total matching assets */
							__(
								'Showing %1$d of %2$d assets',
								'orca-dam-picker'
							),
							flat.length,
							total
						) }
					</span>
					{ hasMore && (
						<Button
							variant="secondary"
							onClick={ () => setPage( ( p ) => p + 1 ) }
						>
							{ __( 'Load more', 'orca-dam-picker' ) }
						</Button>
					) }
				</div>
			) }
			{ ! loading && flat.length === 0 && (
				<p style={ { textAlign: 'center', opacity: 0.7 } }>
					{ __( 'No assets found.', 'orca-dam-picker' ) }
				</p>
			) }
		</div>
	);
}
