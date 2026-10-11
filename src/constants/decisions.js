/**
 * A decision's status, in words and in a colour (v4.11.12).
 *
 * The same two maps `DecisionsList.vue` and `TeamDecisionsView.vue` each
 * carry as private methods. They were written before a decision was rendered
 * anywhere outside the Decisions module; now the service desk renders one in
 * its request grid, so the vocabulary is shared rather than copied a third
 * time. The two components keep their own copies for now — swapping them over
 * is a change to two large files with no behaviour in it, and belongs in its
 * own pass.
 *
 * Legacy values: rows written before the lifecycle was renamed still say
 * `proposed` (now `open`) and `decided` (now `approved`). Both maps take them,
 * because the database still holds them and the reader should not have to
 * know that.
 */
import { translate as t } from '@nextcloud/l10n'

export function decisionStatusLabel(status) {
	switch (status) {
	// TRANSLATORS: status pill — a decision that is open for discussion
	case 'open': return t('teamhub', 'Open')
	case 'proposed': return t('teamhub', 'Open')
	// TRANSLATORS: status pill — the proposer finalized it; it now awaits an approver
	case 'finalized': return t('teamhub', 'Awaits approval')
	// TRANSLATORS: status pill — an approver accepted the finalized proposal
	case 'approved': return t('teamhub', 'Approved')
	case 'decided': return t('teamhub', 'Approved')
	// TRANSLATORS: status pill — an approver rejected the finalized proposal
	case 'denied': return t('teamhub', 'Denied')
	// TRANSLATORS: status pill — the proposer cancelled it before finalizing
	case 'withdrawn': return t('teamhub', 'Withdrawn')
	// TRANSLATORS: status pill — a newer decision overrules this one
	case 'superseded': return t('teamhub', 'Superseded')
	// TRANSLATORS: status pill — a reviewer retired the decision into the team archive
	case 'archived': return t('teamhub', 'Archived')
	default: return status || ''
	}
}

/** The `NcChip` variant for a status — one colour vocabulary across the app. */
export function decisionStatusTone(status) {
	switch (status) {
	case 'open':
	case 'proposed': return 'primary'
	case 'finalized': return 'warning'
	case 'approved':
	case 'decided': return 'success'
	case 'denied': return 'error'
	// v4.11.18 — both retirement states are `tertiary`, which is also the
	// default. Written out rather than left to fall through: a retired
	// decision is a record and not a problem, so it must not pick up
	// `error`'s red if somebody later reorders this switch.
	case 'superseded':
	case 'archived': return 'tertiary'
	default: return 'tertiary'
	}
}

/**
 * v4.11.18 — a decision that no longer governs: a newer one took over, or a
 * reviewer retired it. Read-only, in the team's archive category, and it
 * shows what replaced it.
 *
 * The server sends `isRetired` on every decision and that is what the UI
 * should read; this exists for the places that only hold a status string
 * (a list row hydrated from a feed, a search result).
 */
export function isRetiredStatus(status) {
	return status === 'superseded' || status === 'archived'
}

/**
 * The Actions menu for one decision (v4.11.19).
 *
 * Everything you can *do* to a decision used to be a button in whichever
 * block of the detail panel happened to own it — Edit and Finalize in the
 * drafting block, Request review and Supersede below it, Keep and Archive
 * inside the review note, Link task and Create task in one section, Link
 * decision and Link external in another, Copy link in the header. Eleven
 * buttons in six places, and which of them appeared depended on the status
 * *and* on the viewer's role, so the panel's shape moved under you.
 *
 * They are one list now. It lives here rather than in `TeamDecisionsView`
 * for the reason `src/constants/serviceTeams.js` exists: the interesting part
 * is the **gates**, and a pure function over a decision and a set of
 * capabilities can be read side by side and tested without a browser. The
 * component supplies the capabilities — it is the only thing that knows the
 * viewer's level, the team's Deck boards and the action-level setting — and
 * renders what comes back.
 *
 * Order is fixed, so the menu never reshuffles as a decision moves through
 * its lifecycle: what a status changes is which items are *present*, never
 * where they sit. Three groups, separated:
 *
 *   1. the decision's own lifecycle — whatever its next step is
 *   2. what it points at — the four *Link …* verbs
 *   3. the record itself — Copy link, and whether it is public
 *
 * Separators are rows (`sep1`, `sep2`) rather than markup decisions, so a
 * group that is empty for this viewer cannot leave a stray line behind.
 *
 * **Not here: Approve and Deny.** They submit the approval form and its
 * mandatory reason, and a submit button belongs to its form — a menu item
 * that only scrolls you to a field is worse than the field. The same
 * reasoning keeps Save/Cancel in the proposal editor and Link/Cancel in the
 * inline link forms; Back and Close are chrome rather than actions.
 *
 * @param {object} decision the serialised decision, or null
 * @param {object} caps what this viewer may do:
 *   `canActOn` — proposer or team admin, so may edit and finalize;
 *   `canApprove` — an approver of this decision's category;
 *   `canPerformDecisionActions` — meets the team's action minimum level;
 *   `inArchiveCategory` — the decision sits in the team's archive category,
 *     which is team-only and offers no publishing (v4.11.31);
 *   `canScheduleApproverMeeting` — the team has Calendar and the viewer approves;
 *   `hasDeckBoard` — the team has somewhere to create a card;
 *   `hasOpenReview` — a review of this decision is running;
 *   `editingProposal` — the inline editor is already open;
 *   `finalizing` — a finalize call is in flight;
 *   `talkHref` — where the discussion lives, or null.
 * @return {Array<object>} menu rows: { key, label?, icon?, href? }
 */
export function decisionPanelActions(decision, caps = {}) {
	if (!decision) return []

	const isOpen = decision.status === 'open' || decision.status === 'proposed'
	const isApproved = decision.status === 'approved'
	// A retired decision is a read-only record, and a record that can still
	// grow attachments is not read-only.
	const mayAttach = !!caps.canPerformDecisionActions && !decision.isRetired

	// ── 1. Lifecycle ──────────────────────────────────────────────────
	const lifecycle = []

	if (isOpen && caps.canActOn && !caps.editingProposal) {
		// TRANSLATORS: menu item — reopen the proposal's subject and text for editing
		lifecycle.push({ key: 'edit', label: t('teamhub', 'Edit proposal'), icon: 'PencilIcon' })
		lifecycle.push({
			key: 'finalize',
			// TRANSLATORS: menu item — lock the proposal's wording and send it to the category approvers
			label: caps.finalizing ? t('teamhub', 'Finalizing') : t('teamhub', 'Finalize'),
			icon: 'GavelIcon',
		})
	}

	// The only item that leaves the app, so the only one rendered as a link.
	// Absent on an `immediate` proposal, which has no conversation at all.
	//
	// v4.11.23 — and absent once the decision is settled. The conversation
	// is the *proposal's*: it is where the wording was argued out before
	// anybody approved anything. On an approved decision — and most visibly
	// on one with a review running — offering it points at a thread that
	// closed months ago and has nothing to do with the question now being
	// asked. Justin: it should not be active on a review request.
	if (caps.talkHref && (isOpen || decision.status === 'finalized')) {
		lifecycle.push({
			key: 'discussion',
			// TRANSLATORS: menu item — opens the Talk conversation or thread where the proposal is being discussed
			label: t('teamhub', 'Discussion'),
			icon: 'ForumIcon',
			href: caps.talkHref,
		})
	}

	if (decision.status === 'finalized' && caps.canScheduleApproverMeeting) {
		lifecycle.push({
			key: 'schedule-meeting',
			label: t('teamhub', 'Schedule meeting'),
			icon: 'CalendarPlusIcon',
		})
	}

	// Asking is not deciding, so any team member may — the one item in this
	// menu gated on no role at all.
	if (isApproved && !caps.hasOpenReview) {
		lifecycle.push({
			key: 'review-request',
			// TRANSLATORS: menu item — ask the category's approvers to look at an approved decision again
			label: t('teamhub', 'Request review'),
			icon: 'RefreshIcon',
		})
	}

	// v4.11.23 — *Keep decision* and *Archive decision* are **not** here.
	// Justin's rule, and it is the one that decides what this menu is for:
	// **permanent actions live in the menu, temporary ones live on the
	// page.** A review is a thing in front of you right now, and answering
	// it needs a mandatory reason — so it gets a block in the panel's right
	// column, exactly like the Approval block a finalized proposal gets, and
	// for the same reason: the buttons belong with the field they submit.
	//
	// The menu keeps what is true of the decision whatever is happening to
	// it. The page carries what is happening to it.

	// Retiring a decision is the mirror of approving it, so the same hands.
	if (isApproved && caps.canApprove) {
		lifecycle.push({ key: 'supersede', label: t('teamhub', 'Supersede'), icon: 'SwapHorizontalIcon' })
		// v4.11.24 — Justin: *archive decision is something we want
		// approvers to do on their periodic review or from the action
		// menu.* This is the second half. Unlike Keep and Accept it is not
		// an answer to a question somebody asked — it is an approver
		// deciding, unprompted, that a decision has served its purpose — so
		// it belongs with the permanent verbs and not in the Review block.
		//
		// Absent while a review is running: that review is where this gets
		// decided, and the service refuses it there too.
		if (!caps.hasOpenReview) {
			lifecycle.push({ key: 'archive', label: t('teamhub', 'Archive decision'), icon: 'ArchiveOutlineIcon' })
		}
	}

	// v4.11.30 — Justin: *an approver for a category can move a proposal
	// that is in the wrong category to the proper category from the actions
	// menu on the decision page.*
	//
	// A permanent verb, so §2.159 puts it in the menu: where a decision is
	// filed is true of it whatever is happening to it. It sits at the end of
	// the lifecycle group because it is a correction of how the decision was
	// *filed* rather than a step in what the decision is doing.
	//
	// **The gate is `canApprove`, which reads the category it is in now** —
	// the approvers who find a decision in their queue that is not theirs
	// are the ones who need to push it out. And it is offered on every live
	// status, not just the two proposal ones: a miscategorised decision that
	// has already been approved is under the wrong approvers for every review
	// it will ever have, which is the more expensive half of the mistake.
	//
	// Absent on a retired decision (`archived_from_category` names where it
	// came from, and moving it would make that a lie) and absent while a
	// review is open, where the service refuses it for the reason archive is
	// refused: the review is with the current approvers.
	if (!decision.isRetired && caps.canApprove && !caps.hasOpenReview) {
		lifecycle.push({
			key: 'move-category',
			// TRANSLATORS: menu item — refile this decision under a different decision category
			label: t('teamhub', 'Move to another category'),
			icon: 'FolderMoveOutlineIcon',
		})
	}

	// ── 2. What it points at ──────────────────────────────────────────
	const links = []

	if (mayAttach) {
		links.push({
			key: 'link-decision',
			// TRANSLATORS: menu item — pick another decision in this team to relate to this one
			label: t('teamhub', 'Link decision'),
			icon: 'LinkVariantIcon',
		})
		links.push({
			key: 'link-task',
			// TRANSLATORS: menu item — paste the URL of a task to attach it to this decision
			label: t('teamhub', 'Link task'),
			icon: 'FormatListBulletedIcon',
		})
		// Nowhere to create a card without a board.
		if (caps.hasDeckBoard) {
			links.push({
				key: 'create-task',
				// TRANSLATORS: menu item — create a Deck card already linked to this decision
				label: t('teamhub', 'Create task'),
				icon: 'PlusIcon',
			})
		}
		links.push({
			key: 'link-external',
			// TRANSLATORS: menu item — attach a URL pointing at a decision held in a different tool
			label: t('teamhub', 'Link external decision'),
			icon: 'OpenInNewIcon',
		})
	}

	// ── 3. The record itself ──────────────────────────────────────────
	// Copy link is unconditional: somebody who can read a decision can share
	// its address, and doing so writes nothing.
	const record = [{ key: 'copy-link', label: t('teamhub', 'Copy link'), icon: 'LinkVariantIcon' }]
	// v4.11.31 — Justin: *only a category approver can set a decision in the
	// category as public.* So the gate is `canApprove` and no longer
	// `canPerformDecisionActions`, which is the team's action minimum level
	// and defaults to 1.
	//
	// Publishing is not an attachment. *Link decision* and *Link task* add
	// to the team's own record; publishing moves the record out of the team,
	// to every logged-in account on the instance. That is a statement about
	// what the decision is, and those are the category approvers' to make.
	//
	// Hidden rather than shown-and-refused, per CLAUDE.md: a member who
	// cannot publish does not get a checkbox that answers 403.
	//
	// And absent in the archive, which is team-only (`inArchiveCategory`) —
	// Justin: *there is also no option to make it public for that category.*
	// The checkbox is gone rather than unticked-and-disabled: a disabled
	// checkbox says "not now", and the answer here is "not ever, not here".
	//
	// `!decision.isRetired` as well as `!caps.inArchiveCategory`, and not
	// instead of it: every retired decision is in the archive — `retire()`
	// is the only way in and `moveToCategory()` refuses it as a target — so
	// the status answers the same question the cap does, from the row itself,
	// and this function cannot be made to offer the checkbox by a caller
	// that forgets to pass the cap.
	if (caps.canApprove && !caps.inArchiveCategory && !decision.isRetired) {
		// A state rather than a verb, so the template renders it as a
		// checkbox and it carries no icon.
		record.push({ key: 'public', label: t('teamhub', 'Public decision') })
	}

	return joinWithSeparators([lifecycle, links, record])
}

/**
 * Join groups of menu items with separators, dropping the ones that would
 * read as nothing.
 *
 * A separator between two groups is only a separator when both sides have
 * something in them. Building the menu as groups and joining them here means
 * a viewer who can see no lifecycle action and no link action still gets a
 * clean menu instead of one that opens on a horizontal line.
 */
export function joinWithSeparators(groups) {
	const out = []
	let n = 0
	for (const group of groups) {
		if (!group.length) continue
		if (out.length) out.push({ key: `sep${++n}` })
		out.push(...group)
	}
	return out
}
