export function AssetGrid( { assets, onPick, selectedId = null } ) {
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
			{ assets.map( ( asset ) => {
				const isSelected = asset.id === selectedId;
				return (
					<li key={ asset.id }>
						<button
							type="button"
							onClick={ () => onPick( asset ) }
							title={ asset.filename }
							aria-pressed={ isSelected }
							style={ {
								display: 'block',
								width: '100%',
								padding: 0,
								border: isSelected
									? '2px solid var(--wp-admin-theme-color, #007cba)'
									: '1px solid #ddd',
								borderRadius: 4,
								background: '#fff',
								cursor: 'pointer',
								boxShadow: isSelected
									? '0 0 0 2px rgba(0, 124, 186, 0.25)'
									: 'none',
								transition:
									'border-color 120ms ease, box-shadow 120ms ease',
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
				);
			} ) }
		</ul>
	);
}
