/**
 * TeamHub JS unit tests — module hook registration (v4.9.3).
 *
 * `node --import ./tests/js/register.mjs --test tests/js/` runs the pure
 * helpers under src/lib without a bundler. The only reason a loader is
 * needed at all is that those helpers import `@nextcloud/l10n` and
 * `@nextcloud/router`, which expect a browser; the loader maps both to the
 * stubs in tests/js/stubs/ and leaves every other import alone.
 */
import { register } from 'node:module'

register('./loader.mjs', import.meta.url)
