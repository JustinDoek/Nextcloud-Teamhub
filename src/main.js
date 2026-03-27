// CRITICAL: Set globals BEFORE any other imports.
// @nextcloud/vue and @nextcloud/axios read OC.requestToken at import time.
// Guarantee the token is present by reading it from the DOM attribute that
// Nextcloud core always writes onto <head data-requesttoken="...">.
window.appName = 'teamhub'
window.appVersion = '2.6.3'

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
