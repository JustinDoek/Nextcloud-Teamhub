import { createAppConfig } from '@nextcloud/vite-config'
import { defineConfig } from 'vite'

/**
 * TeamHub Vite configuration.
 *
 * Nextcloud automatically adds type="module" to any script whose filename
 * ends in .mjs (see lib/private/Template/functions.php emit_script_loading_tags).
 * So ESM output works fine — we just need .mjs file extensions.
 *
 * Util::addScript references must match WITHOUT the extension:
 *   Util::addScript('teamhub', 'teamhub')  → js/teamhub.mjs
 *   Util::addScript('teamhub', 'admin')    → js/admin.mjs
 *   Util::addScript('teamhub', 'personal') → js/personal.mjs
 *
 * ── CSS DELIVERY (the 3.53 fix) ───────────────────────────────────────────
 * The old Vue 2 / webpack build used `vue-style-loader`, which injected all
 * SFC <style scoped> blocks (and third-party lib CSS such as the widget grid)
 * into the DOM as runtime <style> tags embedded in the JS bundle. No separate
 * .css files were emitted and nothing needed to load them.
 *
 * `@nextcloud/vite-config` defaults to EXTRACTING CSS into js/css/*.css, which
 * nothing on the PHP side loaded (no Util::addStyle) and which no JS entry
 * imported — so the entire scoped-style layer silently disappeared after the
 * Vite migration (broken tab bar, overlapping widget grid, etc.).
 *
 * `inlineCSS: true` restores the webpack behaviour: vite-config injects styles
 * via JS instead of extracting them, so styling rides with the bundle exactly
 * as it did under `vue-style-loader`. This fixes scoped component styles AND
 * third-party library CSS in one move, with no template/controller changes.
 *
 * ── WHY WE NO LONGER USE inlineCSS (the 3.55.2 fix) ───────────────────────
 * `@nextcloud/vite-config` (2.x) only attaches each component's inlined-style
 * side-effect to the MAIN entry's copy of that component. With three entries
 * (teamhub / admin / personal), all CSS ended up injected from `teamhub.mjs`
 * only; `admin.mjs` and `personal.mjs` shipped with NO style injection at all.
 * The team view (which loads teamhub.mjs) looked fine, but the admin and
 * personal settings pages — which load only their own bundle — rendered as
 * unstyled HTML (broken tab bar, grids collapsing to stacked text). This is a
 * known multi-entry limitation; nothing in the 2.x line fixes it and there is
 * no 3.x.
 *
 * The supported fix for a multi-entry app is to EXTRACT CSS per entry (the
 * vite-config default) and load each entry's stylesheet from its own template
 * with \OCP\Util::addStyle(). CSS is emitted into the app css/ dir (NC serves
 * styles from there; the js/ subtree is not a style root and 404s). Names are
 * deterministic and unhashed so the PHP references are stable across builds:
 *   css/vite-teamhub.chunk.css + css/vite-index.chunk.css ← templates/main.php
 *   css/vite-admin.chunk.css   + css/vite-index.chunk.css ← templates/admin.php
 *   css/vite-personal.chunk.css+ css/vite-index.chunk.css ← templates/personal.php
 *
 * vite-config's css-entry-points-plugin splits each entry into a small @import
 * stub plus chunk files; we load the .chunk.css files directly (the @import stub
 * is bypassed, since NC does not reliably resolve relative @imports). index is
 * the shared NC-component chunk imported by every entry.
 */
export default createAppConfig(
	{
		teamhub: 'src/main.js',
		admin: 'src/admin.js',
		personal: 'src/personal.js',
	},
	{
		// Extract CSS to files (vite-config default — inlineCSS removed). Each
		// entry's CSS is loaded from its template via Util::addStyle. See the
		// note above for why inlineCSS could not work across our three entries.
		config: defineConfig({
			build: {
				// outDir is the APP ROOT (not js/). Rollup forbids '../' in
				// assetFileNames, so we cannot emit css from within outDir 'js'
				// up into css/. Instead we root the build at '.' and route each
				// output type into its own dir via the filename patterns below:
				//   entry/chunk JS → js/        (matches Util::addScript IDs)
				//   extracted CSS  → css/       (where NC reliably serves styles)
				// emptyOutDir is false so the build does NOT wipe the app root.
				outDir: '.',
				emptyOutDir: false,
				cssCodeSplit: true,
				rollupOptions: {
					output: {
						// JS entries keep their .mjs names under js/ (NC adds
						// type="module" for .mjs; Util::addScript references the
						// bare name, e.g. 'admin' → js/admin.mjs).
						entryFileNames: 'js/[name].mjs',
						chunkFileNames: 'js/chunks/[name]-[hash].mjs',
						// Extracted CSS goes to css/ with deterministic, unhashed
						// names so the Util::addStyle references in templates/*.php
						// stay valid across builds. NC serves app styles from css/
						// (where the hand-written css/main.css already lives); the
						// previous js/css/ location 404'd because NC does not serve
						// the js/ subtree as a style root. The 'vite-' prefix avoids
						// colliding with css/main.css. Non-CSS assets keep a hash.
						assetFileNames: (assetInfo) => {
							const name = assetInfo.name || ''
							if (name.endsWith('.css')) {
								return 'css/vite-[name][extname]'
							}
							return 'js/assets/[name]-[hash][extname]'
						},
					},
				},
			},
		}),
	},
)
