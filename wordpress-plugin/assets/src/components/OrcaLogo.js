/**
 * Inline ORCA logo. Mirrors wordpress-plugin/assets/orca-logo.svg so the
 * picker UI doesn't need an extra HTTP request for the brand mark.
 *
 * @param {Object} props        Component props.
 * @param {number} [props.size] Rendered width and height in pixels.
 */
export function OrcaLogo( { size = 24, ...props } ) {
	return (
		<svg
			viewBox="0 0 100 100"
			xmlns="http://www.w3.org/2000/svg"
			width={ size }
			height={ size }
			aria-hidden="true"
			{ ...props }
		>
			<ellipse cx="50" cy="55" rx="35" ry="25" fill="#1a1a1a" />
			<path d="M 15 60 Q 5 50, 8 42 Q 16 48, 16 50 Z" fill="#1a1a1a" />
			<path d="M 15 50 Q 5 60, 8 68 Q 16 62, 16 60 Z" fill="#1a1a1a" />
			<path d="M 44 40 L 42 15 L 48 30 Z" fill="#1a1a1a" />
			<ellipse cx="60" cy="58" rx="15" ry="10" fill="white" />
			<ellipse
				cx="68"
				cy="48"
				rx="8"
				ry="10"
				fill="white"
				transform="rotate(-20 68 48)"
			/>
			<circle cx="68" cy="48" r="3" fill="#1a1a1a" />
			<circle cx="69" cy="47" r="1" fill="white" />
			<path
				d="M 72 55 Q 78 58, 82 55"
				stroke="#1a1a1a"
				strokeWidth="2"
				fill="none"
				strokeLinecap="round"
			/>
			<ellipse
				cx="48"
				cy="70"
				rx="7"
				ry="15"
				fill="#1a1a1a"
				transform="rotate(30 48 70)"
			/>
		</svg>
	);
}
