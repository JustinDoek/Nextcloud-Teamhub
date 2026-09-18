/**
 * TeamHub — @mention parsing (v4.5.27).
 *
 * One definition of "what a mention looks like", shared by everything that has
 * to recognise one. Before this there were three copies of
 * `/@([a-zA-Z0-9._-]+)/` — in the feed filter, in the message renderer, and in
 * PHP's notifier — and all three were wrong in the same way.
 *
 * **The bug.** `NcRichContenteditable` serialises a mention as bare `@<id>`
 * whenever the id contains no space, colon or slash, and quotes it
 * (`@"<id>"`) otherwise. On an instance whose usernames are e-mail addresses
 * — `JDoek@aaenhunze.nl` — the bare form lands in the body verbatim, '@' and
 * all. A `[a-zA-Z0-9._-]+` character class stops at the second '@' and yields
 * `JDoek` plus `aaenhunze.nl`, neither of which is anybody. Mentions matched
 * nobody, mention notifications never fired, and the renderer drew two broken
 * mention spans where there should have been one name.
 *
 * Keep this in sync with `MessageService::parseMentionCandidates()`.
 */

/**
 * Trailing characters that may end a bare mention rather than belong to it.
 * '.' is in here *and* is legal inside a uid, which is why the candidate list
 * below offers progressively shorter prefixes instead of picking one reading:
 * `@jdoek.` ending a sentence and `@jdoek.nl` as a whole uid are
 * indistinguishable without knowing which uid you are looking for.
 */
const TRAILING = '.,;:!?)]}>\''

/**
 * The bare-mention grammar, copied from `@nextcloud/vue`'s own
 * `mixins/richEditor/index.js` so the reader and the writer cannot disagree:
 *
 *   MENTION_START  = /(?=[a-z0-9_\-@.'])\B/
 *   MENTION_SIMPLE = /(@[a-z0-9_\-@.']+)/
 *
 * Two things it buys us that a hand-rolled pattern did not:
 *
 *  - **The character class stops at `/`, `)`, `,` and the rest.** v4.5.26–31
 *    used `[^\s"]+`, which swallowed everything up to the next space — so a
 *    link containing an `@` produced candidates like
 *    `aaenhunze.nl/GF-sugar/Attachments/deelbestand-…md)`. Harmless, since no
 *    uid looks like that, but it meant the parser was matching URLs.
 *  - **`\B` refuses a mid-word `@`,** which is what stops a plain e-mail
 *    address in prose (`mail jdoek@example.com`) from reading as a mention of
 *    `example.com`. The position before the `@` there sits between a word
 *    character and a non-word one — a word boundary — so `\B` fails.
 */
const MENTION_BARE = /(?=[a-z0-9_\-@.'])\B(@[a-z0-9_\-@.']+)/gi

/**
 * Every uid a body could be mentioning, longest reading first.
 *
 * @param {string} body raw message text
 * @return {string[]} candidate uids, deduplicated, in order of appearance
 */
export function parseMentionCandidates(body) {
	if (!body) return []

	let text = String(body)
	const out = new Set()

	// Quoted form first, then removed so the bare pass cannot re-read the same
	// mention without its quotes.
	for (const match of text.match(/@"([^"]+)"/g) || []) {
		out.add(match.slice(2, -1))
	}
	text = text.replace(/@"[^"]+"/g, ' ')

	for (const raw of text.match(MENTION_BARE) || []) {
		let candidate = raw.slice(1)
		while (candidate) {
			out.add(candidate)
			if (!TRAILING.includes(candidate.slice(-1))) break
			candidate = candidate.slice(0, -1)
		}
	}

	return [...out]
}

/**
 * Does `body` mention `uid`?
 *
 * **Case-insensitive on purpose.** The id that reaches the message body comes
 * from the mention picker, which is fed from `circles_member.user_id`; the id
 * being compared against comes from Nextcloud's own session. Those are not
 * guaranteed to agree on case, and two uids differing only in case are never
 * two different people on any instance that allows both to exist.
 *
 * @param {string} body raw message text
 * @param {string} uid the account to test for
 * @return {boolean} true when the body mentions that account
 */
export function mentionsUser(body, uid) {
	if (!body || !uid) return false
	const needle = String(uid).toLowerCase()
	return parseMentionCandidates(body).some(c => c.toLowerCase() === needle)
}

/**
 * Resolve a bare mention token against a set of known ids, longest match wins.
 *
 * Used by the message renderer, which has the extra job of deciding where the
 * mention *ends* so it can wrap only that part: given `@jdoek.nl,` and a
 * members map containing `jdoek.nl`, it must wrap `jdoek.nl` and leave the
 * comma as text.
 *
 * @param {string} token the run of characters after '@'
 * @param {Object} knownIds map keyed by uid (values unused)
 * @return {string|null} the matched uid as it appears in knownIds, or null
 */
export function resolveMentionToken(token, knownIds) {
	if (!token || !knownIds) return null

	// Lower-cased index, built once per call — the map's own keys are returned
	// so the caller gets the canonical spelling, not the typed one.
	const index = new Map()
	for (const id of Object.keys(knownIds)) {
		index.set(id.toLowerCase(), id)
	}

	let candidate = token
	while (candidate) {
		const hit = index.get(candidate.toLowerCase())
		if (hit) return hit
		if (!TRAILING.includes(candidate.slice(-1))) break
		candidate = candidate.slice(0, -1)
	}
	return null
}
