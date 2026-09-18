/**
 * TeamHub — ISO/IEC 27001:2022 Annex A controls in scope for the app.
 *
 * The Compliance tab's checks each declare which control(s) they evidence,
 * and the printable report renders a coverage table from that declaration —
 * including the controls nothing evidences yet. See
 * `buildComplianceReportChecks()` and `openComplianceReport()` in
 * `AdminSettings.vue`.
 *
 * ── Why the titles are not translated ───────────────────────────────────
 *
 * A control's identifier (`A.5.12`) is language-neutral; its title is the
 * standard's own wording, which auditors quote and cross-reference against
 * their Statement of Applicability. Translating "Classification of
 * information" into a locale's own phrasing would make the report harder to
 * check against the standard, not easier. The same reasoning already governs
 * the report as a whole — see the English-only note on
 * `openComplianceReport()`. Surrounding UI chrome (headings, the "Not
 * evidenced" state) IS translated; only these titles are canonical.
 *
 * Scope note: this is the subset relevant to what TeamHub can observe about
 * itself, not the whole of Annex A. Extending it is deliberate work — a
 * control listed here with no check behind it is reported as a gap, so
 * adding one makes a claim the app then has to answer.
 */

/**
 * Controls in scope, in standard order.
 *
 * @type {ReadonlyArray<{id: string, title: string}>}
 */
export const ISO_CONTROLS = Object.freeze([
	{ id: 'A.5.3',  title: 'Segregation of duties' },
	{ id: 'A.5.9',  title: 'Inventory of information and other associated assets' },
	{ id: 'A.5.10', title: 'Acceptable use of information and other associated assets' },
	{ id: 'A.5.12', title: 'Classification of information' },
	{ id: 'A.5.13', title: 'Labelling of information' },
	{ id: 'A.5.15', title: 'Access control' },
	{ id: 'A.5.18', title: 'Access rights' },
	{ id: 'A.5.33', title: 'Protection of records' },
	{ id: 'A.5.34', title: 'Privacy and protection of PII' },
	{ id: 'A.8.15', title: 'Logging' },
	{ id: 'A.8.16', title: 'Monitoring activities' },
])

/** @type {Readonly<Record<string, string>>} */
export const ISO_CONTROL_TITLES = Object.freeze(
	ISO_CONTROLS.reduce((acc, c) => {
		acc[c.id] = c.title
		return acc
	}, {}),
)

/**
 * `A.5.12 Classification of information` — id and title in one string, the
 * form used in the report's control column and in the tab's info popovers.
 *
 * An unknown id degrades to the id alone rather than throwing, so a check
 * can name a control before it is added to the list above.
 *
 * @param {string} id control identifier, e.g. 'A.5.12'
 * @return {string}
 */
export function isoControlLabel(id) {
	const title = ISO_CONTROL_TITLES[id]
	return title ? `${id} ${title}` : id
}

/**
 * Invert a list of checks into per-control coverage.
 *
 * Every control in `ISO_CONTROLS` appears in the result, including those no
 * check references — `checks: []` is what the report renders as "Not
 * evidenced", and naming the gap is the point of the table.
 *
 * @param {Array<{name: string, controls?: string[]}>} checks rows from buildComplianceReportChecks()
 * @return {Array<{id: string, title: string, checks: string[]}>}
 */
export function buildControlCoverage(checks) {
	return ISO_CONTROLS.map(({ id, title }) => ({
		id,
		title,
		checks: checks
			.filter((c) => Array.isArray(c.controls) && c.controls.includes(id))
			.map((c) => c.name),
	}))
}
