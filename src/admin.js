import Vue from 'vue'
import AdminSettings from './components/AdminSettings.vue'

Vue.prototype.t = (app, str, vars) => {
    if (vars) {
        return str.replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`)
    }
    return str
}

const el = document.getElementById('teamhub-admin-settings')
if (el) {
    new Vue({ render: h => h(AdminSettings) }).$mount(el)
}
