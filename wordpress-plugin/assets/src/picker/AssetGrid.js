export function AssetGrid( { assets, onPick } ) {
	return (
		<ul
			className="orca-dam-grid"
			style={ {
				display: 'grid',
				gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))',
				gap: 12,
				listStyle: 'none',
				margin: 0,
				padding: 0,
			} }
		>
			{ assets.map( ( asset ) => (
				<li key={ asset.id }>
					<button
						type="button"
						onClick={ () => onPick( asset ) }
						title={ asset.filename }
						style={ {
							display: 'block',
							width: '100%',
							padding: 0,
							border: '1px solid #ddd',
							borderRadius: 4,
							background: '#fff',
							cursor: 'pointer',
						} }
					>
						<img
							src={ asset.thumbnail_url || asset.url }
							alt={ asset.alt_text || asset.filename }
							loading="lazy"
							style={ {
								width: '100%',
								height: 140,
								objectFit: 'cover',
								display: 'block',
							} }
						/>
						<div
							style={ {
								padding: 6,
								fontSize: 12,
								overflow: 'hidden',
								textOverflow: 'ellipsis',
								whiteSpace: 'nowrap',
							} }
						>
							{ asset.filename }
						</div>
					</button>
				</li>
			) ) }
		</ul>
	);
}
