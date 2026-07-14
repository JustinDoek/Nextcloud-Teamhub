import { createApp } from 'vue'
import MyPresencePanel from './components/MyPresencePanel.vue'

// Shared design tokens (typography, radii, colour assignments).
// Same import as main.js — each entry point owns its own CSS chunk
// (see vite.config.mjs), so the token block has to be imported once
// per entry to land in that entry's stylesheet.
import './styles/widget-tokens.css'

// [TeamHub][personal] debug: remove at session end

const el = document.getElementById('teamhub-personal-settings')
if (el) {
	// Only mount when the NC admin has enabled the presence module.
	const enabled = el.dataset.presenceModuleEnabled === '1'
	if (enabled) {
		const app = createApp(MyPresencePanel)

		// Match the t/n shim applied in admin.js.
		app.config.globalProperties.t = (appId, str, vars) => {
			if (vars) {
				return str.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)
			}
			return str
		}
		app.config.globalProperties.n = (appId, singular, plural, count, vars) => {
			const str = count === 1 ? singular : plural
			if (vars) {
				return str.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)
			}
			return str
		}

		app.mount(el)
	}
	// When disabled: the div stays empty — the NC settings section
	// still appears in the sidebar but shows nothing. NC doesn't provide
	// a way to hide a registered ISettings section dynamically.
}
