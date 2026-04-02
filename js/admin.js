/* TeamHub admin settings — plain JS, no ES modules */
(function () {
    'use strict';

    var baseUrl     = OC.generateUrl('/apps/teamhub/api/v1/admin/settings');
    var initialized = false;   // guard: loadSettings only runs once per page load

    function getEl(id) { return document.getElementById(id); }

    function loadSettings() {
        fetch(baseUrl, {
            headers: { 'Accept': 'application/json', 'requesttoken': OC.requestToken }
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject('HTTP ' + r.status); })
        .then(function (data) {
            if (!data) return;

            var desc        = getEl('teamhub-wizard-description');
            var createGrp   = getEl('teamhub-create-team-group');
            var grp         = getEl('teamhub-invite-group');
            var email       = getEl('teamhub-invite-email');
            var fed         = getEl('teamhub-invite-federated');
            var pinLevel    = getEl('teamhub-pin-min-level');

            if (desc && typeof data.wizardDescription === 'string') {
                desc.value = data.wizardDescription;
            }

            if (createGrp && typeof data.createTeamGroup === 'string') {
                createGrp.value = data.createTeamGroup;
            }

            var types = typeof data.inviteTypes === 'string'
                ? data.inviteTypes.split(',').map(function (t) { return t.trim(); })
                : ['user', 'group'];
            if (grp)   grp.checked   = types.indexOf('group')     !== -1;
            if (email) email.checked = types.indexOf('email')     !== -1;
            if (fed)   fed.checked   = types.indexOf('federated') !== -1;

            if (pinLevel && typeof data.pinMinLevel === 'string') {
                pinLevel.value = data.pinMinLevel;
            }
        })
        .catch(function (e) { console.warn('TeamHub admin: load failed', e); });
    }

    function saveSettings() {
        var saveBtn   = getEl('teamhub-save-btn');
        var okEl      = getEl('teamhub-save-ok');
        var errEl     = getEl('teamhub-save-err');
        var desc      = getEl('teamhub-wizard-description');
        var createGrp = getEl('teamhub-create-team-group');
        var grp       = getEl('teamhub-invite-group');
        var email     = getEl('teamhub-invite-email');
        var fed       = getEl('teamhub-invite-federated');
        var pinLevel  = getEl('teamhub-pin-min-level');
        if (!saveBtn) return;

        saveBtn.disabled = true;
        if (okEl)  okEl.style.display  = 'none';
        if (errEl) errEl.style.display = 'none';

        var types = ['user'];
        if (grp   && grp.checked)   types.push('group');
        if (email && email.checked) types.push('email');
        if (fed   && fed.checked)   types.push('federated');

        // Use URLSearchParams so NC's getParams() can parse the body directly.
        // (getContent() is protected in OC\AppFramework\Http\Request in NC32.)
        var params = new URLSearchParams();
        params.set('wizardDescription', desc      ? desc.value      : '');
        params.set('createTeamGroup',   createGrp ? createGrp.value : '');
        params.set('inviteTypes',        types.join(','));
        params.set('pinMinLevel',        pinLevel  ? pinLevel.value  : 'moderator');

        fetch(baseUrl, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept':       'application/json',
                'requesttoken': OC.requestToken,
            },
            body: params.toString(),
        })
        .then(function (r) {
            if (!r.ok) return r.text().then(function (t) { throw new Error('HTTP ' + r.status + ': ' + t); });
            if (okEl) {
                okEl.style.display = 'inline';
                setTimeout(function () { okEl.style.display = 'none'; }, 3000);
            }
        })
        .catch(function (e) {
            if (errEl) {
                errEl.textContent   = String(e);
                errEl.style.display = 'inline';
            }
        })
        .finally(function () {
            var btn = getEl('teamhub-save-btn');
            if (btn) btn.disabled = false;
        });
    }

    function attachSaveListener() {
        var saveBtn = getEl('teamhub-save-btn');
        if (saveBtn && !saveBtn._teamhubBound) {
            saveBtn._teamhubBound = true;
            saveBtn.addEventListener('click', saveSettings);
        }
    }

    function initSection() {
        if (!getEl('teamhub-admin-settings')) return false;
        // Only load settings once — guard prevents MutationObserver from
        // firing a second time (NC admin panel re-renders sections on tab switch)
        attachSaveListener();
        if (!initialized) {
            initialized = true;
            loadSettings();
        }
        return true;
    }

    function observeForSection() {
        if (initSection()) return;

        var observer = new MutationObserver(function () {
            if (initSection()) {
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeForSection);
    } else {
        observeForSection();
    }
}());
