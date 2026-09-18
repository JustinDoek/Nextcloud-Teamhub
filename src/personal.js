import { createApp } from 'vue'
import PersonalSettingsPanel from './components/PersonalSettingsPanel.vue'

// Shared design tokens (typography, radii, colour assignments).
// Same import as main.js — each entry point owns its own CSS chunk
// (see vite.config.mjs), so the token block has to be imported once
// per entry to land in that entry's stylesheet.
import './styles/widget-tokens.css'

const el = document.getElementById('teamhub-personal-settings')
if (el) {
	// v4.4.12 — mount unconditionally. Previously this only mounted when the
	// Presence module was enabled instance-wide, which left Settings →
	// Personal → TeamHub as a blank page on every instance with Presence off.
	// The presence panel is now gated inside PersonalSettingsPanel instead, so
	// the user-scoped preferences above it always render.
	const app = createApp(PersonalSettingsPanel, {
		presenceModuleEnabled: el.dataset.presenceModuleEnabled === '1',
		initialGettingStartedHint: el.dataset.gettingStartedHint !== '0',
	})

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
