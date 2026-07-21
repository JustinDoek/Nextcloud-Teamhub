#!/usr/bin/env node
/*
 * Build-time integrity-manifest generator.
 *
 * Walks a curated set of directories that constitute the "released" TeamHub app
 * (PHP code, built JS/CSS, templates, schema, l10n, icons, appinfo) and writes
 * appinfo/integrity.json with a SHA-256 for every shipped file.
 *
 * The runtime Compliance tab reads that manifest, re-hashes on disk, and
 * reports altered / missing / unexpected files. Anything not covered here is
 * treated by the runtime as "not part of the release" and does not affect the
 * compliance verdict.
 *
 * Run automatically at the tail of `npm run build`. Keep the covered-paths
 * list in sync with what actually ships in the app tarball.
 */

'use strict'

const fs = require('fs')
const path = require('path')
const crypto = require('crypto')

const ROOT = path.resolve(__dirname, '..')
const OUTPUT = path.join(ROOT, 'appinfo', 'integrity.json')
const MANIFEST_VERSION = 1

/** Directories whose contents form the released code. Everything under each
 *  path is included recursively (subject to EXCLUDE_FILE_PATTERNS below). */
const COVERED_DIRS = [
    'appinfo',
    'lib',
    'js',
    'css',
    'templates',
    'img',
    'l10n',
    'sql',
]

/** Individual root-level files that ship with the app. */
const COVERED_ROOT_FILES = [
    'composer.json',
]

/** Filenames or relative paths to skip even if they live inside a covered dir.
 *  The integrity manifest itself is excluded — chicken-and-egg. */
const EXCLUDE_RELATIVE_PATHS = new Set([
    'appinfo/integrity.json',
])

/** Regex applied to the file's basename to reject dev-only files.
 *  `.mjs.license` sidecars are Rollup's extracted license comments — pure
 *  metadata that some packagers strip in transit, which would cause the
 *  runtime check to false-positive on a clean install. Skipping them keeps
 *  the manifest to files whose absence would actually break the app. */
const EXCLUDE_FILE_PATTERNS = [
    /^\./,             // .DS_Store, .gitkeep, etc.
    /\.map$/,          // sourcemaps (dev artifact, not shipped by NC apps)
    /\.mjs\.license$/, // Rollup license-comment sidecars
]

function sha256(filePath) {
    const hash = crypto.createHash('sha256')
    const data = fs.readFileSync(filePath)
    hash.update(data)
    return hash.digest('hex')
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

function readAppVersion() {
    try {
        const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'))
        return String(pkg.version || '')
    } catch (e) {
        return ''
    }
}

function main() {
    const paths = []
    for (const dir of COVERED_DIRS) {
        walk(path.join(ROOT, dir), paths)
    }
    for (const f of COVERED_ROOT_FILES) {
        const abs = path.join(ROOT, f)
        if (fs.existsSync(abs) && fs.statSync(abs).isFile()) {
            paths.push(f)
        }
    }
    paths.sort()

    const files = {}
    for (const rel of paths) {
        files[rel] = sha256(path.join(ROOT, rel))
    }

    const manifest = {
        manifest_version: MANIFEST_VERSION,
        app_version: readAppVersion(),
        generated_at: new Date().toISOString(),
        algorithm: 'sha256',
        files,
    }

    fs.writeFileSync(OUTPUT, JSON.stringify(manifest, null, 2) + '\n', 'utf8')
    // eslint-disable-next-line no-console
    console.log(`[integrity] wrote ${OUTPUT} (${paths.length} files, app ${manifest.app_version})`)
}

main()
