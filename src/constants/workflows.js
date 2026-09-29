/**
 * WorkflowHub — the frontend half of the workflow vocabulary (v4.10.15).
 *
 * Mirrors `lib/Workflow/WorkflowStatus.php`, `WorkflowStepStatus.php`,
 * `WorkflowActor.php` and `WorkflowEventType.php`. Keep in sync. Every
 * function here is pure so `tests/js/workflows.test.mjs` can run it
 * without a browser.
 *
 * What a workflow row is *not*: an ordinary My Work item. Aggregated
 * items (Deck cards, approvals, file reviews …) keep `MyWorkItemRow`; a
 * workflow instance from `GET /api/v1/workflows` is rendered by
 * `WorkflowItemRow`, and the two never share a list.
 */
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { formatDate, formatTime, formatDateTime, todayIso, shiftIsoDate, fromDateInput, zonedIsoDate } from '../lib/localDate.js'

// ── Statuses ───────────────────────────────────────────────────────────

export const WORKFLOW_STATUS = {
	SUBMITTED: 'submitted',
	IN_PROGRESS: 'in_progress',
	WAITING: 'waiting',
	BLOCKED: 'blocked',
	CANCELLED: 'cancelled',
	REJECTED: 'rejected',
	COMPLETED: 'completed',
}

/** Statuses in which the workflow still has a step to act on. */
export const OPEN_STATUSES = [
	WORKFLOW_STATUS.SUBMITTED,
	WORKFLOW_STATUS.IN_PROGRESS,
	WORKFLOW_STATUS.WAITING,
	WORKFLOW_STATUS.BLOCKED,
]

export function isOpen(workflow) {
	return OPEN_STATUSES.includes(workflow?.status)
}

export function statusLabel(status) {
	switch (status) {
	case WORKFLOW_STATUS.SUBMITTED: return t('teamhub', 'Submitted')
	case WORKFLOW_STATUS.IN_PROGRESS: return t('teamhub', 'In progress')
	case WORKFLOW_STATUS.WAITING: return t('teamhub', 'Waiting for information')
	case WORKFLOW_STATUS.BLOCKED: return t('teamhub', 'Blocked')
	case WORKFLOW_STATUS.CANCELLED: return t('teamhub', 'Cancelled')
	case WORKFLOW_STATUS.REJECTED: return t('teamhub', 'Rejected')
	case WORKFLOW_STATUS.COMPLETED: return t('teamhub', 'Completed')
	default: return status || ''
	}
}

/** NcChip variant for a status pill. */
export function statusTone(status) {
	switch (status) {
	case WORKFLOW_STATUS.COMPLETED: return 'success'
	case WORKFLOW_STATUS.WAITING:
	case WORKFLOW_STATUS.BLOCKED: return 'warning'
	case WORKFLOW_STATUS.REJECTED:
	case WORKFLOW_STATUS.CANCELLED: return 'error'
	default: return 'tertiary'
	}
}

export const STEP_STATUS = {
	PENDING: 'pending',
	AVAILABLE: 'available',
	IN_PROGRESS: 'in_progress',
	WAITING_FOR_INFORMATION: 'waiting_for_information',
	COMPLETED: 'completed',
	SKIPPED: 'skipped',
	REJECTED: 'rejected',
	CANCELLED: 'cancelled',
}

export const ACTIVE_STEP_STATUSES = [
	STEP_STATUS.AVAILABLE,
	STEP_STATUS.IN_PROGRESS,
	STEP_STATUS.WAITING_FOR_INFORMATION,
]

export function stepStatusLabel(status) {
	switch (status) {
	case STEP_STATUS.PENDING: return t('teamhub', 'Not yet')
	case STEP_STATUS.AVAILABLE: return t('teamhub', 'Ready')
	case STEP_STATUS.IN_PROGRESS: return t('teamhub', 'In progress')
	case STEP_STATUS.WAITING_FOR_INFORMATION: return t('teamhub', 'Waiting for information')
	case STEP_STATUS.COMPLETED: return t('teamhub', 'Done')
	case STEP_STATUS.SKIPPED: return t('teamhub', 'Skipped')
	case STEP_STATUS.REJECTED: return t('teamhub', 'Rejected')
	case STEP_STATUS.CANCELLED: return t('teamhub', 'Cancelled')
	default: return status || ''
	}
}

/**
 * The status words for one step of the tracker. v4.10.32 — a step an admin
 * of the service team closed (`closeRequest()`) is stored as *skipped* with
 * `actionTaken: 'close'`; it reads *Closed*, not *Skipped*, because
 * somebody decided it.
 */
export function stepStateLabel(step) {
	if (step?.status === STEP_STATUS.SKIPPED && step?.actionTaken === 'close') {
		// TRANSLATORS: a workflow step an admin of the service team closed the request on
		return t('teamhub', 'Closed')
	}
	return stepStatusLabel(step?.status)
}

/**
 * The role a built service's desk task needs, as the service team typed it
 * (v4.10.32): *Role: Privacy officer* (v4.10.43, was *Needs: …* — Justin,
 * 2026-09-25). The organisation's own words, not translated; '' for the
 * built-in services, which carry none. Shown as a chip where a member
 * decides what to claim.
 */
export function stepRoleText(step) {
	const role = (step?.roleLabel || '').trim()
	// TRANSLATORS: the role a task of a service request needs; {role} is the organisation's own name for it, e.g. "Privacy officer"
	return role ? t('teamhub', 'Role: {role}', { role }) : ''
}

/**
 * The links on a step of a built service (v4.10.36,
 * `docs/service-builder.md` § 6.1), as the server sent them — a desk step's
 * only to the team's own members. Checked again here: only an absolute
 * `https://` address is ever rendered as a link (CLAUDE.md § Vue).
 *
 * @return {Array<{label: string, url: string, kind: string}>}
 */
export function stepLinks(step) {
	return (Array.isArray(step?.links) ? step.links : []).filter(link => httpsOnly(link?.url) !== '')
}

/**
 * v4.11.0 — the one check a stored address passes before it reaches an
 * `href`: an absolute `https://` URL, or '' for anything else (`http:`,
 * `javascript:`, `data:`, relative, unparsable). The server stores
 * `https://` only; the page does not rely on that.
 */
export function httpsOnly(value) {
	const url = String(value || '')
	if (!url.startsWith('https://')) {
		return ''
	}
	try {
		return new URL(url).protocol === 'https:' ? url : ''
	} catch (e) {
		return ''
	}
}

/**
 * What a step link's button says to a screen reader and in its tooltip:
 * the verb of its kind with the team's own name for it. The visible text is
 * the name alone, so several links on one step stay tell-apart.
 */
export function stepLinkDescription(link) {
	const label = String(link?.label || '').trim()
	if (link?.kind === 'form') {
		// TRANSLATORS: a button on a step of a service request that opens a form in a new tab; {label} is the service team's own name for it
		return t('teamhub', 'Fill in the form: {label} (opens in a new tab)', { label })
	}
	// TRANSLATORS: a button on a step of a service request that opens a web page in a new tab; {label} is the service team's own name for it
	return t('teamhub', 'Open {label} (opens in a new tab)', { label })
}

// ── The paperclip (v4.10.38, `docs/service-builder.md` § 7) ─────────────

/** How many files one message may carry; the server says the same. */
export const MAX_ATTACHED_FILES = 10

/**
 * What the paperclip may do on this request: the settings its service gave
 * it when it started (`data.fileSharing`), else the defaults — view only,
 * for 14 days. The server checks it all again.
 *
 * @return {{allowed: boolean, edit: boolean, days: number}}
 */
export function fileSharingOf(workflow) {
	const f = workflow?.data?.fileSharing || {}
	const days = Number(f.days)
	return { allowed: f.allowed !== false, edit: f.edit === true, days: days > 0 ? days : 14 }
}

/**
 * Whether files may go with this verb on this request: only a request a
 * service team handles, only when its service allows files, and only on a
 * verb whose text the other side reads — never a withdrawal or a request for
 * an update.
 */
export function canAttachFiles(workflow, action) {
	const verbs = ['complete', 'reject', 'request_information', 'provide_information', 'close', 'message']
	const handledByDesk = (workflow?.steps || []).some(s => s?.actor?.type === 'service_agent')
	return verbs.includes(action) && handledByDesk && fileSharingOf(workflow).allowed
}

/**
 * What the box says under the paperclip: with whom the files are shared,
 * for how long, and what they may do with them.
 *
 * @param {{edit: boolean, days: number}} settings
 * @param {string} who the other side, already in words ("the service team", a name)
 */
export function shareNote(settings, who) {
	const days = settings?.days || 14
	return settings?.edit
		// TRANSLATORS: under a paperclip button; {who} is the service team or the requester's name
		? n('teamhub', 'Files are shared with {who} for {n} day, to view and edit.', 'Files are shared with {who} for {n} days, to view and edit.', days, { who, n: days })
		// TRANSLATORS: under a paperclip button; {who} is the service team or the requester's name
		: n('teamhub', 'Files are shared with {who} for {n} day, to view only.', 'Files are shared with {who} for {n} days, to view only.', days, { who, n: days })
}

/**
 * The tracker's three-way state for a step, the same vocabulary
 * `MyWorkItemRow`'s tracker uses (`done` / `current` / `pending`).
 */
export function trackerState(step) {
	if (ACTIVE_STEP_STATUSES.includes(step?.status)) {
		return 'current'
	}
	if ([STEP_STATUS.COMPLETED, STEP_STATUS.REJECTED, STEP_STATUS.CANCELLED, STEP_STATUS.SKIPPED].includes(step?.status)) {
		return 'done'
	}
	return 'pending'
}

// ── Steps and progress ─────────────────────────────────────────────────

/**
 * The task this view answers for, or null once the workflow has ended.
 *
 * v4.10.37 — a step may hold several tasks, all open at once. The server
 * says which one a view is about (`viewer.stepKey`: the queue row's task,
 * the viewer's own); without it, the first open one.
 */
export function activeStep(workflow) {
	const steps = workflow?.steps || []
	const key = workflow?.viewer?.stepKey
	if (key) {
		const focused = steps.find(s => s.key === key && ACTIVE_STEP_STATUSES.includes(s.status))
		if (focused) {
			return focused
		}
	}
	return steps.find(s => ACTIVE_STEP_STATUSES.includes(s.status)) || null
}

/**
 * The open tasks this viewer may do themselves (v4.10.39): the server says
 * so per task (`canAct`), and a person's own task is a `user` one — the
 * requester's. What the request form hands the requester straight after
 * sending, when the service starts with them.
 */
export function ownOpenTasks(workflow) {
	return (workflow?.steps || []).filter(s => ACTIVE_STEP_STATUSES.includes(s.status)
		&& s.canAct
		&& s.actor?.type === 'user')
}

/**
 * A request a service team handles (v4.10.44): My Work calls it a
 * *Service*, not a *Workflow* (Justin, 2026-09-25).
 */
export function isServiceRequest(workflow) {
	return workflow?.responsible?.type === 'service_agent'
		|| (workflow?.steps || []).some(s => s?.actor?.type === 'service_agent')
}

/**
 * One list row per workflow **and task** (v4.10.37): the queue and My Work
 * may list the same request once per open task.
 */
export function rowKey(workflow) {
	return String(workflow?.id ?? '') + ':' + String(workflow?.viewer?.stepKey || '')
}

/**
 * The steps of a workflow with their tasks (v4.10.37): the rows grouped by
 * `order`, in order. A step of one task is a group of one, labelled by that
 * task; a step of several is labelled by its own name (`stageLabel`).
 *
 * @return {Array<{order: number, label: string, tasks: object[]}>}
 */
export function stagesOf(workflow) {
	const out = []
	for (const step of workflow?.steps || []) {
		const last = out[out.length - 1]
		if (last && last.order === step.order) {
			last.tasks.push(step)
		} else {
			out.push({ order: step.order, label: '', tasks: [step] })
		}
	}
	for (const stage of out) {
		stage.label = stage.tasks[0].stageLabel || stage.tasks[0].label || ''
	}
	return out
}

/**
 * The task's own words under its step's name, for a row that lists one
 * task: *Assess: Privacy check*. Just the label for a step of one task.
 */
export function taskTitle(step) {
	const stage = String(step?.stageLabel || '').trim()
	const label = String(step?.label || '').trim()
	if (!stage || stage === label) {
		return label
	}
	// TRANSLATORS: {step} is a step of a service request, {task} one task within it, e.g. "Assess: Privacy check"
	return t('teamhub', '{step}: {task}', { step: stage, task: label })
}

/**
 * Whether this view carries the step detail (v4.10.16). An unlicensed
 * instance answers with no step list at all — no current step, no
 * progress — so everything that renders a step or a tracker asks this
 * first rather than assuming the array is there.
 */
export function hasStepDetail(workflow) {
	return (workflow?.steps || []).length > 0
}

/**
 * Who is responsible right now — shown on both tiers (v4.10.16). The
 * server sends `responsible` on every open workflow; the active step's
 * actor is the fallback for a view that predates the field.
 */
export function responsibleActor(workflow) {
	return workflow?.responsible || activeStep(workflow)?.actor || null
}

/**
 * Whether the workflow is waiting for information. Read from the active
 * step where there is one, and from the instance status otherwise — which
 * is what an unlicensed view has (v4.10.16).
 */
export function isWaitingForInformation(workflow) {
	const step = activeStep(workflow)
	return step
		? step.status === STEP_STATUS.WAITING_FOR_INFORMATION
		: workflow?.status === WORKFLOW_STATUS.WAITING
}

/**
 * "Step 2 of 4". Counts from the active step; a finished workflow reads
 * as its last step.
 */
export function progress(workflow) {
	const steps = workflow?.steps || []
	// v4.10.37 — steps, not task rows: the tasks of a step share its order.
	const total = new Set(steps.map(s => s.order)).size
	if (total === 0) {
		return { current: 0, total: 0 }
	}
	const active = activeStep(workflow)
	return { current: active ? active.order : total, total }
}

export function progressLabel(workflow) {
	const { current, total } = progress(workflow)
	// No steps: an unlicensed instance shows no progress at all.
	if (total === 0) {
		return ''
	}
	// TRANSLATORS: which step of a multi-step workflow is active
	return t('teamhub', 'Step {current} of {total}', { current, total })
}

// ── Actors ─────────────────────────────────────────────────────────────

export const ACTOR_TYPE = {
	USER: 'user',
	GROUP: 'group',
	TEAM_OWNER: 'team_owner',
	TEAM_MODERATOR: 'team_moderator',
	TEAM: 'team',
	// v4.10.40 — a service team's task (WorkflowActor::TYPE_SERVICE_AGENT).
	SERVICE_AGENT: 'service_agent',
}

/**
 * Who is responsible, in a form that gives away nothing the viewer is not
 * entitled to. A role is named as a role; a group by its id (the viewer is
 * a participant, so they are meant to know who decides); a person by uid
 * — the row renders that one through `NcUserBubble`, which shows the
 * display name and nothing else.
 *
 * @return {{ label: string, uid: (string|null) }}
 */
export function actorDescription(actor) {
	switch (actor?.type) {
	case ACTOR_TYPE.USER:
		return { label: actor.id, uid: actor.id }
	case ACTOR_TYPE.GROUP:
		// v4.10.17 — name the people, not the record. "Group admin" reads
		// like somebody called Group Admin; every other actor type here
		// names a role a person holds, and a group should too. `admin` is
		// Nextcloud's own administrators group and has a name of its own;
		// any other group is named after itself, because it is configurable
		// (`workflow_team_request_group`) and we cannot know what it means.
		return actor.id === 'admin'
			? { label: t('teamhub', 'Nextcloud administrators'), uid: null }
			// TRANSLATORS: the responsible party for a workflow step is everybody in a Nextcloud group; {group} is the group's name
			: { label: t('teamhub', 'Members of {group}', { group: actor.id }), uid: null }
	case ACTOR_TYPE.TEAM_OWNER:
		return { label: t('teamhub', 'Team owner'), uid: null }
	case ACTOR_TYPE.TEAM_MODERATOR:
		return { label: t('teamhub', 'Team owner or moderator'), uid: null }
	case ACTOR_TYPE.TEAM:
		return { label: t('teamhub', 'Team members'), uid: null }
	case ACTOR_TYPE.SERVICE_AGENT:
		// v4.10.40 — found walking the service builder as Jaap Tel: an
		// unclaimed team task named nobody ("Ready ·").
		return { label: t('teamhub', 'Service team'), uid: null }
	default:
		return { label: '', uid: null }
	}
}

export function participantRoleLabel(role) {
	switch (role) {
	case 'initiator': return t('teamhub', 'Requester')
	case 'responsible': return t('teamhub', 'Responsible for a step')
	case 'actor': return t('teamhub', 'Acted on a step')
	case 'observer': return t('teamhub', 'Observer')
	default: return role || ''
	}
}

// ── Actions ────────────────────────────────────────────────────────────

export const WORKFLOW_ACTION = {
	COMPLETE: 'complete',
	REJECT: 'reject',
	REQUEST_INFORMATION: 'request_information',
	PROVIDE_INFORMATION: 'provide_information',
	CANCEL: 'cancel',
	REQUEST_STATUS: 'request_status',
	// v4.10.31 — an admin of the service team closes the request, at any step.
	CLOSE: 'close',
}

/**
 * The button's words for a workflow verb. v4.10.29: a definition may give the
 * active step its own (`workflow.actionLabels`, translated server-side) —
 * the quota request's desk step reads *Grant* / *Decline*, not *Complete
 * step* / *Reject*. The verb is the same; only the words change.
 */
export function actionLabel(action, workflow = null) {
	const own = workflow?.actionLabels?.[action]
	if (typeof own === 'string' && own !== '') {
		return own
	}
	switch (action) {
	// TRANSLATORS: button — finish the workflow step the viewer is responsible for
	case WORKFLOW_ACTION.COMPLETE: return t('teamhub', 'Complete step')
	// TRANSLATORS: button — refuse the workflow step; the workflow ends
	case WORKFLOW_ACTION.REJECT: return t('teamhub', 'Reject')
	// TRANSLATORS: button — the responsible person asks the requester for something before going on
	case WORKFLOW_ACTION.REQUEST_INFORMATION: return t('teamhub', 'Ask for information')
	// TRANSLATORS: button — answer a request for information on a workflow
	case WORKFLOW_ACTION.PROVIDE_INFORMATION: return t('teamhub', 'Answer')
	// TRANSLATORS: button — withdraw a workflow the viewer started
	case WORKFLOW_ACTION.CANCEL: return t('teamhub', 'Withdraw request')
	// TRANSLATORS: button — ask the responsible person where a workflow stands
	case WORKFLOW_ACTION.REQUEST_STATUS: return t('teamhub', 'Ask for an update')
	// TRANSLATORS: button - a service team admin closes a request for the team, done or not
	case WORKFLOW_ACTION.CLOSE: return t('teamhub', 'Close request')
	default: return action || ''
	}
}

/**
 * What the viewer may do with a workflow, from the server's `viewer`
 * block and the active step's status. A rendering hint only — the server
 * re-checks every call.
 *
 * @return {string[]} in menu order; the first entry is the primary action
 */
export function availableActions(workflow) {
	if (!isOpen(workflow)) {
		return []
	}
	const v = workflow.viewer || {}
	// v4.10.16 — from the status when the tier sends no steps, so the
	// actions are right on an unlicensed instance too.
	const waitingForInfo = isWaitingForInformation(workflow)
	const out = []
	if (v.canAct) {
		if (waitingForInfo) {
			// The holder is waiting on an answer; completing is refused until it comes.
			out.push(WORKFLOW_ACTION.REJECT)
		} else {
			out.push(WORKFLOW_ACTION.COMPLETE, WORKFLOW_ACTION.REJECT, WORKFLOW_ACTION.REQUEST_INFORMATION)
		}
	}
	if (v.isParticipant && waitingForInfo && workflow.status !== WORKFLOW_STATUS.BLOCKED) {
		out.push(WORKFLOW_ACTION.PROVIDE_INFORMATION)
	}
	if (v.canRequestStatus) {
		out.push(WORKFLOW_ACTION.REQUEST_STATUS)
	}
	// v4.10.31 — an admin of the service team, at any step.
	if (v.canClose) {
		out.push(WORKFLOW_ACTION.CLOSE)
	}
	if (v.canCancel) {
		out.push(WORKFLOW_ACTION.CANCEL)
	}
	return out
}

/** Actions that ask for a text before firing: `required` or `optional`. */
export function actionText(action) {
	switch (action) {
	case WORKFLOW_ACTION.REJECT:
	case WORKFLOW_ACTION.REQUEST_INFORMATION:
	case WORKFLOW_ACTION.PROVIDE_INFORMATION:
		return 'required'
	case WORKFLOW_ACTION.COMPLETE:
	case WORKFLOW_ACTION.CANCEL:
	case WORKFLOW_ACTION.REQUEST_STATUS:
	case WORKFLOW_ACTION.CLOSE:
		return 'optional'
	default:
		return 'none'
	}
}

export function actionTextLabel(action) {
	switch (action) {
	case WORKFLOW_ACTION.REJECT: return t('teamhub', 'Reason')
	case WORKFLOW_ACTION.REQUEST_INFORMATION: return t('teamhub', 'What do you need to know?')
	case WORKFLOW_ACTION.PROVIDE_INFORMATION: return t('teamhub', 'Your answer')
	case WORKFLOW_ACTION.COMPLETE:
	case WORKFLOW_ACTION.CLOSE: return t('teamhub', 'Note (optional)')
	case WORKFLOW_ACTION.CANCEL: return t('teamhub', 'Reason (optional)')
	case WORKFLOW_ACTION.REQUEST_STATUS: return t('teamhub', 'Message (optional)')
	default: return ''
	}
}

export const DESTRUCTIVE_WORKFLOW_ACTIONS = [WORKFLOW_ACTION.REJECT, WORKFLOW_ACTION.CANCEL]

// ── Sections ───────────────────────────────────────────────────────────

export const WORKFLOW_SECTION = {
	ACTION_REQUIRED: 'workflow_action_required',
	WAITING: 'workflow_waiting',
	COMPLETED: 'workflow_completed',
}

/**
 * Split the viewer's workflows into the three sections.
 *
 * A workflow the viewer is responsible for goes to Action required; every
 * other open one they take part in goes to Waiting for others; one that
 * has ended goes to Completed.
 *
 * **Completed is new in v4.10.17.** Until then ended workflows went
 * nowhere: the server returned them on every list call, `capabilities`
 * advertised `completed_history`, and the client dropped them — so
 * finishing a request made it vanish without a trace on a licensed
 * instance. On an *unlicensed* instance vanishing is correct, because the
 * server deleted it in the transaction that ended it, and it therefore
 * never reaches this function at all.
 */
export function partition(workflows) {
	const actionRequired = []
	const waiting = []
	const completed = []
	for (const w of workflows || []) {
		if (!isOpen(w)) {
			completed.push(w)
			continue
		}
		// v4.10.31 — "action required" (`viewer.actionRequired`) rather
		// than "you may act": an unclaimed desk step may be acted on by
		// every member of the desk, but it is nobody's own work until
		// somebody claims it. Older payloads carry only isResponsible.
		if (w.viewer?.actionRequired ?? w.viewer?.isResponsible) {
			actionRequired.push(w)
		} else if (w.viewer?.isParticipant) {
			waiting.push(w)
		}
	}
	const byRecent = (a, b) => (b.updatedAt || 0) - (a.updatedAt || 0)
	actionRequired.sort(byRecent)
	waiting.sort(byRecent)
	// Most recently finished first: the one just completed is the one the
	// viewer is looking for.
	completed.sort((a, b) => (b.endedAt || b.updatedAt || 0) - (a.endedAt || a.updatedAt || 0))
	return { actionRequired, waiting, completed }
}

// ── Events ─────────────────────────────────────────────────────────────

export function eventLabel(event) {
	const step = event?.stepLabel || event?.stepKey || ''
	switch (event?.type) {
	case 'created': return t('teamhub', 'Request created')
	case 'step_available': return t('teamhub', 'Step ready: {step}', { step })
	case 'step_started': return t('teamhub', 'Step started: {step}', { step })
	// v4.10.16 — the basic timeline of an unlicensed instance names no
	// step, so these two also have a form without one.
	case 'step_completed': return step
		// TRANSLATORS: timeline entry — a workflow step was completed; %s is the step's name
		? t('teamhub', 'Step completed: {step}', { step })
		// TRANSLATORS: timeline entry — a workflow step was completed, on an instance that does not show which step
		: t('teamhub', 'Step completed')
	case 'step_rejected': return step
		// TRANSLATORS: timeline entry — a workflow step was rejected; %s is the step's name
		? t('teamhub', 'Step rejected: {step}', { step })
		// TRANSLATORS: timeline entry — a workflow step was rejected, on an instance that does not show which step
		: t('teamhub', 'Step rejected')
	case 'step_skipped': return t('teamhub', 'Step skipped: {step}', { step })
	case 'information_requested': return t('teamhub', 'Information requested')
	case 'information_provided': return t('teamhub', 'Information provided')
	case 'status_requested': return t('teamhub', 'Update requested')
	// TRANSLATORS: a message the requester and the service team wrote to each other on a request
	case 'message': return t('teamhub', 'Message')
	case 'participant_added': return t('teamhub', 'Participant added')
	case 'blocked': return t('teamhub', 'Blocked')
	case 'unblocked': return t('teamhub', 'Block lifted')
	case 'completed': return t('teamhub', 'Workflow completed')
	case 'rejected': return t('teamhub', 'Workflow rejected')
	case 'cancelled': return t('teamhub', 'Workflow withdrawn')
	// v4.10.32 — `closeRequest()`: an admin of the service team ended it.
	// TRANSLATORS: timeline entry — an admin of the service team closed the request before it was finished
	case 'closed': return t('teamhub', 'Closed by the service team')
	default: return event?.type || ''
	}
}

/** Events worth a line on the timeline; the bookkeeping ones are not. */
export const TIMELINE_EVENT_TYPES = [
	'created', 'step_completed', 'step_rejected', 'information_requested',
	'information_provided', 'status_requested', 'blocked', 'unblocked',
	'completed', 'rejected', 'cancelled', 'closed',
]

/**
 * The history as the timeline shows it: the meaningful events, newest
 * first, each with the step's label looked up from the workflow.
 *
 * v4.10.16 — an unlicensed instance sends the basic timeline: fewer
 * events and no `stepKey` on any of them, so the lines read "Step
 * completed" without naming which. Nothing here needs changing for that;
 * the lookup simply finds nothing.
 */
export function timeline(workflow, history) {
	const labels = Object.fromEntries((workflow?.steps || []).map(s => [s.key, s.label]))
	return (history || [])
		.filter(e => TIMELINE_EVENT_TYPES.includes(e.type))
		.map(e => ({ ...e, stepLabel: labels[e.stepKey] || e.stepKey || '' }))
		.sort((a, b) => (b.occurredAt || 0) - (a.occurredAt || 0))
}

/**
 * v4.11.0 — what the requester and the service team said to each other:
 * the plain messages, and the questions and answers that held the request
 * up. Oldest first, the order a conversation is read in. Internal notes are
 * not in it — they have their own tab and never reach the requester.
 */
export const CONVERSATION_EVENT_TYPES = ['message', 'information_requested', 'information_provided']

export function conversation(history) {
	return (history || [])
		.filter(e => CONVERSATION_EVENT_TYPES.includes(e?.type))
		.slice()
		.sort((a, b) => (a.occurredAt || 0) - (b.occurredAt || 0))
}

// ── Time ───────────────────────────────────────────────────────────────

/**
 * "Last update" in the short form the feed uses: a clock time today,
 * "Yesterday", a weekday inside the week, a date beyond it. The absolute
 * timestamp belongs on the element's `title`.
 */
export function formatRecent(seconds) {
	if (!seconds) {
		return ''
	}
	const ms = seconds * 1000
	const today = todayIso()
	const todayStart = fromDateInput(today)
	const yesterdayStart = fromDateInput(shiftIsoDate(today, { days: -1 }))
	const weekStart = fromDateInput(shiftIsoDate(today, { days: -6 }))
	if (seconds >= todayStart) {
		return formatTime(ms, { hour: '2-digit', minute: '2-digit' })
	}
	if (seconds >= yesterdayStart) {
		return t('teamhub', 'Yesterday')
	}
	if (seconds >= weekStart) {
		return formatDate(ms, { weekday: 'short' })
	}
	return formatDate(ms, { month: 'short', day: 'numeric' })
}

export function formatAbsolute(seconds) {
	return seconds ? formatDateTime(seconds * 1000) : ''
}

/**
 * v4.10.30 — the timeline's own stamp: date and time together, "24 Sep,
 * 14:05", with the year only when it is not this year. A timeline is read
 * as a sequence, so every line needs both halves; "Yesterday" alone cannot
 * order two events of the same day. The full timestamp stays on `title`.
 */
export function formatStamp(seconds) {
	if (!seconds) {
		return ''
	}
	const ms = seconds * 1000
	const opts = { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }
	if (zonedIsoDate(ms).slice(0, 4) !== todayIso().slice(0, 4)) {
		opts.year = 'numeric'
	}
	return formatDateTime(ms, opts)
}

/** The ISO instant for a `<time datetime>` attribute. */
export function isoInstant(seconds) {
	return seconds ? new Date(seconds * 1000).toISOString() : ''
}

/**
 * v4.10.30 — the colour of an event's dot on the timeline, as in the
 * decisions audit trail: the start, a step or workflow done, one ended
 * badly, one that waits on somebody, and everything else.
 */
export function eventTone(event) {
	switch (event?.type) {
	case 'created':
		return 'start'
	case 'step_completed':
	case 'completed':
		return 'success'
	case 'step_rejected':
	case 'rejected':
		return 'error'
	case 'information_requested':
	case 'blocked':
		return 'warning'
	default:
		return 'neutral'
	}
}
