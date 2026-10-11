/**
 * Upload one file into the signed-in user's own Nextcloud files (v4.11.30).
 *
 * Written for the review-request dialog, where the requester may either pick
 * a file they already have or hand over one they do not. The picker half is
 * Nextcloud's own (`getFilePickerBuilder`); this is the other half, and all
 * either of them has to produce is a **file id the server can read as this
 * user** — the server takes it from there, copying the bytes into the
 * decision's proposal folder where the approvers can reach them.
 *
 * **Not a copy of `PostMessageForm`'s uploader**, which is a different job:
 * that one also shares the file with the team circle, builds a view URL per
 * mime type and appends markdown to the message body, because a message
 * attachment has to be readable and renderable by every team member straight
 * from the stream. Here nothing but the id leaves the function — the server
 * makes the readable copy, so there is no share to create and no link to
 * build, and ~140 lines of that method have nothing to say about this one.
 *
 * Destination: the user's personal `TeamHub Attachments/` folder. Not the
 * team folder, deliberately — the file is an input to a request, the server
 * decides whether it ever becomes part of the record, and writing straight
 * into the team's own folder would publish it before anybody had agreed to
 * it. It also sidesteps the Team Folders ACL problem that made
 * `PostMessageForm` fall back to personal files anyway (MKCOL answers 405
 * for ordinary members on a groupfolders-backed team folder).
 */
import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'

/** The personal folder uploads land in. */
export const UPLOAD_FOLDER = 'TeamHub Attachments'

/**
 * PUT a file into the user's own files and return its id and final name.
 *
 * The name is deduplicated with a timestamp rather than overwriting: the
 * user picked this file to put forward, and silently replacing a file of the
 * same name they uploaded last month is not something a dialog should do.
 *
 * @param {File} file the browser File to upload
 * @return {Promise<{fileId: number, name: string}>}
 * @throws {Error} with a message fit to show, when there is no session or the
 *                 upload fails
 */
export async function uploadToPersonalFiles(file) {
	const uid = getCurrentUser()?.uid
	if (!uid) {
		throw new Error(t('teamhub', 'You are not signed in, so the file cannot be uploaded.'))
	}

	const folder = '/' + UPLOAD_FOLDER
	const folderUrl = generateRemoteUrl(`dav/files/${uid}${folder}`)

	// MKCOL on an existing collection answers 405, which is the ordinary case
	// after the first upload and not a failure.
	try {
		await axios({ method: 'MKCOL', url: folderUrl })
	} catch (e) {
		if (e.response?.status !== 405) {
			throw e
		}
	}

	let name = file.name
	let url = generateRemoteUrl(`dav/files/${uid}${folder}/${encodeURIComponent(name)}`)
	try {
		await axios.head(url)
		// It answered, so the name is taken — suffix the base, keep the
		// extension, because the extension is what decides how it opens.
		const ext = name.includes('.') ? '.' + name.split('.').pop() : ''
		const base = ext ? name.slice(0, -ext.length) : name
		name = `${base}-${Date.now()}${ext}`
		url = generateRemoteUrl(`dav/files/${uid}${folder}/${encodeURIComponent(name)}`)
	} catch (e) {
		// 404 is the good case: nothing of that name is there.
	}

	const response = await axios.put(url, file, {
		headers: { 'Content-Type': file.type || 'application/octet-stream' },
	})

	// NC returns the new numeric id in OC-FileId on a successful PUT. The
	// header may read `<id>oc<instanceid>`, so take the leading digits.
	const raw = String(response.headers?.['oc-fileid'] || '')
	const fileId = Number((raw.match(/^(\d+)/) || [])[1] || 0)
	if (!(fileId > 0)) {
		// Without an id the server has nothing to copy, so this is a failure
		// even though the bytes are safely in the user's files.
		throw new Error(t('teamhub', 'The file was uploaded but Nextcloud did not return its id, so it could not be attached.'))
	}

	return { fileId, name }
}
