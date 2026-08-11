/**
 * Tailwind config for IFS Deploy — see DESIGN.md.
 *
 * Built with the STANDALONE CLI (no Node, no npm):
 *
 *   tools/tailwindcss.exe -i assets/css/src/admin.src.css -o assets/css/admin.css --watch
 *   tools/tailwindcss.exe -i assets/css/src/admin.src.css -o assets/css/admin.css --minify
 *
 * Three settings below are load-bearing. Changing any of them can break wp-admin
 * itself, not just this plugin:
 *
 *   prefix      — wp-admin already owns .button, .card, .notice, .hidden, .active.
 *                 Without a prefix our utilities would silently restyle other
 *                 plugins' screens.
 *   preflight   — Tailwind's global reset restyles bare h1/table/button/img. Inside
 *                 wp-admin that wrecks the admin menu, notices and every other
 *                 plugin's UI. It stays OFF, forever.
 *   content     — Tailwind only emits classes it can SEE. A file missing from this
 *                 list means its classes silently do not exist.
 *
 * ── "warn - No utility classes were detected in your source files" ─────────────
 *
 * EXPECTED HERE. Ignore it. Every utility in this plugin is applied through `@apply`
 * inside admin.src.css, and `@apply` does not go through the content scanner — so a
 * correct build genuinely finds no `tw-` classes in the PHP. There are zero `.tw-`
 * rules in the built stylesheet by design.
 *
 * Do NOT "fix" it by widening `content`, and do not read it as a failed build. To
 * check a build actually worked, look for a rule you know the source declares, e.g.
 * `.ifs-deploy .dp-env-card.is-self`. A build that silently produced nothing is
 * exactly the failure `tests/render-test.php` now pins.
 */
module.exports = {
	prefix: 'tw-',

	corePlugins: {
		preflight: false,
	},

	content: [
		// Every class we emit from PHP.
		'./src/**/*.php',
		// Classes injected by JS at runtime (chips, notices, loading states).
		'./assets/js/*.js',
	],

	// Classes only ever produced by string concatenation, which the scanner cannot
	// see. Keep this list as short as possible — prefer literal class strings.
	safelist: [],

	theme: {
		extend: {
			colors: {
				// Palette from DESIGN.md §3. Every pair is asserted in
				// tests/contrast.php — add a colour there before using it.
				grey: {
					// Zebra needs to be LIGHTER than hover, or the stripe and the hover
					// tint are the same colour and the row you are pointing at stops
					// standing out. Three levels: white / 25 zebra / 50 hover.
					25: '#F7F8F9',  // table zebra
					50: '#EDEFF0',  // page background, hover, disabled fills
					200: '#C2C9CC', // decorative dividers ONLY (1.68:1)
					400: '#9BA3A8', // decorative marks ONLY  (2.56:1)
					500: '#7B8285', // control borders          (3.91:1)
					600: '#5A6063', // secondary text           (6.39:1)
					800: '#383B3D', // body text, headings     (11.28:1)
					900: '#191B1C', // primary buttons, title  (17.29:1)
				},
				success: {
					text: '#2F6B4F',
					bg: '#EAF2ED',
					border: '#C3DACB',
				},
				danger: {
					text: '#9B3A38',
					bg: '#FBEDEC',
					border: '#EFC9C7',
				},
				warning: {
					text: '#7A5F31',
					bg: '#FAF3E6',
					border: '#EADFC4',
				},
				diff: {
					add: '#F0F6F2',
					addmark: '#CDE7D6',
					remove: '#FBF0EF',
					removemark: '#F2D2CF',
				},
			},

			// Inter first, bundled locally — see the @font-face note at the top of
			// admin.src.css for why it is not loaded from a CDN. The system stack stays
			// behind it as the fallback `font-display: swap` paints with, and as the
			// permanent answer if the woff2 ever fails to load.
			fontFamily: {
				sans: [
					'Inter',
					'-apple-system',
					'BlinkMacSystemFont',
					'"Segoe UI"',
					'Roboto',
					'"Helvetica Neue"',
					'Arial',
					'sans-serif',
				],
				mono: [ 'ui-monospace', '"SFMono-Regular"', 'Consolas', 'monospace' ],
			},

			// DESIGN.md §4. Named to match the doc rather than Tailwind's defaults,
			// because 13px base is smaller than Tailwind's and the scale is bespoke.
			fontSize: {
				xs: [ '11px', { lineHeight: '1.45' } ],
				sm: [ '12px', { lineHeight: '1.5' } ],
				base: [ '13px', { lineHeight: '1.6' } ],
				md: [ '14px', { lineHeight: '1.5' } ],
				lg: [ '16px', { lineHeight: '1.4' } ],
				xl: [ '20px', { lineHeight: '1.3' } ],
				'2xl': [ '24px', { lineHeight: '1.25' } ],
			},

			borderRadius: {
				sm: '4px',
				md: '6px',
				lg: '8px',
			},

			boxShadow: {
				xs: '0 1px 2px rgba(25, 27, 28, .05)',
				sm: '0 2px 6px rgba(25, 27, 28, .07)',
				lg: '0 12px 32px rgba(25, 27, 28, .18)',
				focus: '0 0 0 3px rgba(25, 27, 28, .15)',
			},
		},

		// Single breakpoint, matching wp-admin's own mobile cutover.
		screens: {
			md: '783px',
		},
	},

	plugins: [],
};
