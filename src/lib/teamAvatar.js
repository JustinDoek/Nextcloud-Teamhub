/**
 * Nextcloud Teams (Circles) per-team avatar helpers — write path only.
 *
 * Circles exposes a per-circle avatar via OCS routes that first shipped in
 * Circles / Nextcloud 34:
 *   POST   /ocs/v2.php/apps/circles/circles/{circleId}/avatar  (field "file")
 *   DELETE …/avatar
 *
 * Both require circle admin; callers gate on that, and Circles enforces it
 * server-side. On NC 32/33 these routes do not exist, so callers gate on the
 * team payload's `nc_avatar_supported` flag and write TeamHub's own app-data
 * image instead.
 *
 * **Reading does not happen here (v4.6.25).** There used to be a matching
 * `fetchTeamsAvatarObjectUrl()` that pulled the avatar over the GET route and
 * exposed it as an object URL, because an `<img>` cannot call an OCS endpoint
 * itself. It had to be called speculatively — one request per team, per page
 * load — and the route answers 404 for a team with no avatar and 403 for a
 * team you are not in, so most of those requests failed and the browser logged
 * every one. TeamHub's own `/api/v1/teams/{id}/image` route now serves the
 * Teams avatar as well as the legacy image, so display sites render
 * `team.image_url` in a plain `<img>` and nothing is asked speculatively.
 * See DESIGN §2.95.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

const OCS_HEADERS = { 'OCS-APIRequest': 'true' }

function avatarRoute(teamId) {
    return generateOcsUrl('apps/circles/circles/{circleId}/avatar', { circleId: teamId })
}

/**
 * Upload a new Nextcloud Teams avatar. Requires circle-admin level — the caller
 * must gate on that (Circles enforces it server-side and returns 403 otherwise).
 *
 * @param {string} teamId circle id
 * @param {Blob|File} file image bytes
 */
export async function uploadTeamsAvatar(teamId, file) {
    const formData = new FormData()
    formData.append('file', file, file.name || 'team-image')
    await axios.post(avatarRoute(teamId), formData, {
        headers: { ...OCS_HEADERS, 'Content-Type': 'multipart/form-data' },
    })
}

/**
 * Remove a team's Nextcloud Teams avatar. Requires circle-admin level.
 *
 * @param {string} teamId circle id
 */
export async function removeTeamsAvatar(teamId) {
    await axios.delete(avatarRoute(teamId), { headers: OCS_HEADERS })
}

/**
 * The TeamHub route that serves a team's picture, whichever storage holds it.
 *
 * Takes a cache-buster because the browser is told to cache the image for a
 * day: after an upload or a removal the same URL has different bytes behind
 * it, and without this the user sees the old picture until the cache expires.
 *
 * @param {string} teamId circle id
 * @return {string} serve URL with a cache-busting query parameter
 */
export function teamImageUrl(teamId) {
    return generateUrl(`/apps/teamhub/api/v1/teams/${teamId}/image`) + '?t=' + Date.now()
}
