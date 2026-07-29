#!/usr/bin/env node
/*
 * Release-package gate. Runs in CI on the assembled `teamhub/` folder, after
 * it is built from the committed release payload and before the tarball is
 * uploaded to the GitHub release and registered with the Nextcloud App Store.
 *
 * Why this exists:
 *   Until 4.5.1 the publish workflow ran `npm install && npm run build` here,
 *   compiling whatever `src/` happened to be committed to this repo. `src/`
 *   is not mirrored by the working repo's scripts/publish-to-release.js, so
 *   it had been frozen since 2026-07-20 and every release from v4.30 onward
 *   shipped a v4.3.0-era frontend under a newer version number. The rebuild
 *   also regenerated appinfo/integrity.json over that stale bundle, so the
 *   runtime Compliance tab reported "Compliant" and hid the problem entirely.
 *
 * Checks (any failure aborts the release):
 *   1. integrity.json's app_version equals appinfo/info.xml's <version>.
 *      A mismatch means the manifest was generated against a different tree
 *      than the one being shipped -- exactly the 4.5.0 failure, where the
 *      manifest said 4.3.0 and info.xml said 4.5.0.
 *   2. Every file listed in the manifest is present and hashes correctly.
 *   3. No file inside a covered directory is absent from the manifest. This
 *      mirrors the runtime "unexpected files" check, so a package that would
 *      fail Compliance on a customer instance fails here instead.
 *
 * The covered-path rules below must stay in sync with the working repo's
 * scripts/generate-integrity.js.
 */

'use strict'

const fs = require('fs')
const path = require('path')
const crypto = require('crypto')

const ROOT = process.argv[2] || 'teamhub'

// Mirrors scripts/generate-integrity.js in the working repo.
const COVERED_DIRS = ['appinfo', 'lib', 'js', 'css', 'templates', 'img', 'l10n', 'sql']
const COVERED_ROOT_FILES = ['composer.json', 'package.json', 'package-lock.json']
const EXCLUDE_RELATIVE_PATHS = new Set(['appinfo/integrity.json'])
const EXCLUDE_FILE_PATTERNS = [
    /^\./,
    /\.map$/,
    /\.mjs\.license$/,
]

const errors = []
const warnings = []

function fail(msg) {
    errors.push(msg)
}

function sha256(filePath) {
    return crypto.createHash('sha256').update(fs.readFileSync(filePath)).digest('hex')
}

function walk(dir, out) {
    let entries
    try {
        entries = fs.readdirSync(dir, { withFileTypes: true })
    } catch (e) {
        return
    }
    for (const entry of entries) {
        const abs = path.join(dir, entry.name)
        const rel = path.relative(ROOT, abs).split(path.sep).join('/')
        if (EXCLUDE_RELATIVE_PATHS.has(rel)) continue
        if (EXCLUDE_FILE_PATTERNS.some(re => re.test(entry.name))) continue
        if (entry.isDirectory()) {
            walk(abs, out)
        } else if (entry.isFile()) {
            out.push(rel)
        }
    }
}

function readInfoVersion() {
    const infoPath = path.join(ROOT, 'appinfo', 'info.xml')
    const xml = fs.readFileSync(infoPath, 'utf8')
    const m = xml.match(/<version>\s*([^<\s]+)\s*<\/version>/)
    if (!m) {
        fail(`Could not read <version> from ${infoPath}`)
        return null
    }
    return m[1]
}

function main() {
    if (!fs.existsSync(ROOT)) {
        console.error(`[verify] app folder not found: ${ROOT}`)
        process.exit(1)
    }

    const infoVersion = readInfoVersion()
    const manifestPath = path.join(ROOT, 'appinfo', 'integrity.json')

    if (!fs.existsSync(manifestPath)) {
        console.error('[verify] appinfo/integrity.json is missing from the release payload.')
        console.error('[verify] Run `npm run build` in the working repo, then re-run publish-to-release.js.')
        process.exit(1)
    }

    const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'))

    // 1. Manifest belongs to this version of the app.
    if (infoVersion && manifest.app_version !== infoVersion) {
        fail(
            `integrity.json was generated for app ${manifest.app_version || '(empty)'} but ` +
            `appinfo/info.xml says ${infoVersion}. The shipped bundle does not belong to this ` +
            `release -- rebuild in the working repo and re-mirror before tagging.`
        )
    }

    // 2. Every manifest entry present and correct.
    const listed = Object.keys(manifest.files || {})
    if (listed.length === 0) {
        fail('integrity.json lists no files.')
    }
    let missing = 0
    let altered = 0
    for (const rel of listed) {
        const abs = path.join(ROOT, rel)
        if (!fs.existsSync(abs)) {
            if (missing < 10) fail(`missing from package: ${rel}`)
            missing++
            continue
        }
        if (sha256(abs) !== String(manifest.files[rel]).toLowerCase()) {
            if (altered < 10) fail(`hash mismatch (file changed after the manifest was built): ${rel}`)
            altered++
        }
    }
    if (missing > 10) fail(`... and ${missing - 10} more missing files`)
    if (altered > 10) fail(`... and ${altered - 10} more altered files`)

    // 3. Nothing shipped inside a covered dir that the manifest doesn't know about.
    const onDisk = []
    for (const dir of COVERED_DIRS) {
        walk(path.join(ROOT, dir), onDisk)
    }
    for (const f of COVERED_ROOT_FILES) {
        const abs = path.join(ROOT, f)
        if (fs.existsSync(abs) && fs.statSync(abs).isFile()) onDisk.push(f)
    }
    const known = new Set(listed)
    const unexpected = onDisk.filter(rel => !known.has(rel))
    for (const rel of unexpected.slice(0, 10)) {
        fail(`unexpected file, not in the manifest (would fail Compliance on install): ${rel}`)
    }
    if (unexpected.length > 10) fail(`... and ${unexpected.length - 10} more unexpected files`)

    // Advisory only: tag naming has been inconsistent historically (v4.30 for
    // 4.3.0), so a mismatch here is reported but does not block the release.
    const tag = process.env.RELEASE_TAG
    if (tag && infoVersion && tag.replace(/^v/, '') !== infoVersion) {
        warnings.push(`release tag "${tag}" does not match app version ${infoVersion}`)
    }

    console.log(`[verify] app version      : ${infoVersion}`)
    console.log(`[verify] manifest version : ${manifest.app_version}`)
    console.log(`[verify] files verified   : ${listed.length}`)

    for (const w of warnings) {
        console.log(`::warning::${w}`)
    }

    if (errors.length) {
        for (const e of errors) {
            console.log(`::error::${e}`)
        }
        console.error(`\n[verify] FAILED with ${errors.length} problem(s). Release aborted.`)
        process.exit(1)
    }

    console.log('[verify] OK -- package matches its integrity manifest.')
}

main()
