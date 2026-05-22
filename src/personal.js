import Vue from 'vue'
import MyPresencePanel from './components/MyPresencePanel.vue'

Vue.prototype.t = (app, str, vars) => {
    if (vars) {
        return str.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)
    }
    return str
}

const el = document.getElementById('teamhub-personal-settings')
if (el) {
    // Only mount when the NC admin has enabled the presence module.
    const enabled = el.dataset.presenceModuleEnabled === '1'
    if (enabled) {
        new Vue({ render: h => h(MyPresencePanel) }).$mount(el)
    }
    // When disabled: the div stays empty — the NC settings section
    // still appears in the sidebar but shows nothing. NC doesn't provide
    // a way to hide a registered ISettings section dynamically.
}
