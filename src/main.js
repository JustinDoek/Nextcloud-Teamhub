// appName and appVersion are injected as bare compile-time globals by webpack
// DefinePlugin (see webpack.config.js). That satisfies @nextcloud/vue which reads
// them via try { Ve = appName } at module evaluation time.
// We also set window.* here as a runtime fallback for any code that reads them
// from the global object directly.
window.appName = 'teamhub'
window.appVersion = '3.15.0'

// Ensure OC.requestToken is populated before @nextcloud/axios is imported.
// Nextcloud writes the token into two places; prefer the DOM attribute because
// it is written synchronously by the server before any JS runs.
if (typeof window.OC === 'undefined') {
    window.OC = {}
}
if (!window.OC.requestToken) {
    const head = document.querySelector('head[data-requesttoken]')
    if (head) {
        window.OC.requestToken = head.getAttribute('data-requesttoken')
    }
}

import Vue from 'vue'
import axios from '@nextcloud/axios'
import App from './App.vue'
import store from './store/index.js'

// Belt-and-suspenders: also set the header on the shared axios instance so
// every request carries it regardless of when/how the instance was created.
if (window.OC && window.OC.requestToken) {
    axios.defaults.headers.common['requesttoken'] = window.OC.requestToken
}

Vue.config.productionTip = false

const app = new Vue({
    el: '#teamhub-app',
    store,
    render: h => h(App),
})

window.TeamHubVueApp = app
