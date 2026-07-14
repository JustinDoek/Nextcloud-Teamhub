import { createApp } from 'vue'
import AdminSettings from './components/AdminSettings.vue'

// Shared design tokens (typography, radii, colour assignments).
// Same import as main.js — each entry point owns its own CSS chunk
// (see vite.config.mjs), so the token block has to be imported once
// per entry to land in that entry's stylesheet.
import './styles/widget-tokens.css'

// [TeamHub][admin] debug: remove at session end

const el = document.getElementById('teamhub-admin-settings')
if (el) {
	const app = createApp(AdminSettings)

	// AdminSettings uses an inline t/n pattern (window.t fallback per SKILLS.md).
	// We expose the same shim via globalProperties so the component keeps working
	// regardless of whether it reads from this.t or the imported l10n function.
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
