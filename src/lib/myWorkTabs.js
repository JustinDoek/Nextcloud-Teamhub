/**
 * The three tabs of My Work — v4.20.31.
 *
 * Justin, 2026-11-18: *it gets crazy if you don't do the work. Action
 * required and Upcoming on the standard page; Waiting for others and
 * Completed on their own tabs.* One long list gave a record (Completed) and
 * somebody else's work (Waiting for others) the same weight as your own, and
 * the server's 50-row page ran across all of them, so a long Action required
 * pushed the rest onto page two where nobody looked.
 *
 * A tab is a set of categories. The server already filters by category
 * before it pages and counts every category without that filter
 * (`MyWorkService::applyFilters` / `countByCategory`), so each tab asks for
 * its own categories, pages on its own, and every tab's count stays right
 * whichever one is open.
 *
 * Pure, so it runs under `node --test`. Category keys are written out rather
 * than imported from `constants/myWork.js`, which pulls in `@nextcloud/l10n`.
 */

export const MY_WORK_TAB = Object.freeze({
    TODO: 'todo',
    WAITING: 'waiting',
    COMPLETED: 'completed',
})

/** In display order. */
export const MY_WORK_TABS = Object.freeze([MY_WORK_TAB.TODO, MY_WORK_TAB.WAITING, MY_WORK_TAB.COMPLETED])

/**
 * The categories each tab shows. `today` is in To do although nothing assigns
 * it any more: the server derives the Today group from Action required and
 * Upcoming, and a provider that still sets it directly must not vanish.
 */
export const TAB_CATEGORIES = Object.freeze({
    [MY_WORK_TAB.TODO]: Object.freeze(['action_required', 'today', 'upcoming']),
    [MY_WORK_TAB.WAITING]: Object.freeze(['waiting_for_others']),
    [MY_WORK_TAB.COMPLETED]: Object.freeze(['completed']),
})

/**
 * A tab key, or To do for anything else (an old link, a typo).
 *
 * @param {unknown} value
 * @return {string}
 */
export function normaliseTab(value) {
    return MY_WORK_TABS.includes(value) ? value : MY_WORK_TAB.TODO
}

/**
 * Whether a group (a category key) belongs on a tab.
 *
 * @param {string} tab
 * @param {string} category
 * @return {boolean}
 */
export function tabShowsCategory(tab, category) {
    return TAB_CATEGORIES[normaliseTab(tab)].includes(category)
}

/**
 * The `categories` query parameter for a tab.
 *
 * @param {string} tab
 * @return {string}
 */
export function categoriesParam(tab) {
    return TAB_CATEGORIES[normaliseTab(tab)].join(',')
}

/**
 * The number on each tab: the queue's own counts (taken before the category
 * filter, so they do not move when the tab does) plus the workflow steps in
 * the matching section. Today is not added: the server counts it inside
 * Action required and Upcoming already, as an overlay.
 *
 * @param {object|null} counts   the payload's `counts`, or null when the queue did not answer
 * @param {object} workflows     `{ actionRequired, waiting, completed }` arrays
 * @return {{todo: number, waiting: number, completed: number}}
 */
export function tabCounts(counts, workflows = {}) {
    const c = counts || {}
    const len = (list) => (Array.isArray(list) ? list.length : 0)
    const num = (value) => Number(value) || 0
    return {
        [MY_WORK_TAB.TODO]: num(c.action_required) + num(c.upcoming) + len(workflows.actionRequired),
        [MY_WORK_TAB.WAITING]: num(c.waiting_for_others) + len(workflows.waiting),
        [MY_WORK_TAB.COMPLETED]: num(c.completed) + len(workflows.completed),
    }
}

/**
 * The two bands To do adds inside its categories — v4.20.32.
 *
 * Both answer the same complaint as the tabs: To do grows when work is left
 * undone. **Overdue** pulls the late rows out of Action required into a band
 * of their own at the top, folded after the first few like every section, so
 * ten late items no longer push "due tomorrow" out of sight. **Team backlog**
 * takes the team tasks nobody was given and nobody dated out of Upcoming and
 * folds them away by default: every member sees each one, and without a date
 * they never age out, which is what made Upcoming endless.
 *
 * Group keys, not categories: nothing on the server knows them. The rows keep
 * their category; only the page's sectioning changes.
 */
export const TODO_BAND = Object.freeze({
    OVERDUE: 'overdue',
    TEAM_BACKLOG: 'team_backlog',
})

/**
 * Sections that start folded. `collapsedGroups` lists the sections a person
 * *closed*; for these it holds `<key>:open` once they open one, so a fresh
 * page still arrives with the backlog shut.
 */
export const FOLDED_BY_DEFAULT = Object.freeze([TODO_BAND.TEAM_BACKLOG])

/** @param {object} item a My Work item */
export function isOverdueRow(item) {
    return !!item?.metadata?.overdue
}

/**
 * A team task nobody was given (`informational`, `TaskWorkProvider`) with no
 * due date. Written on the shape, not the source, so the next source with
 * undated work for the whole team lands here too.
 *
 * @param {object} item a My Work item
 */
export function isTeamBacklogRow(item) {
    return !!item?.metadata?.informational && !item?.dueAt
}

/**
 * To do's sections with the two bands split out, in reading order:
 * Overdue, Action required, Upcoming, Team backlog. A section left with
 * nothing in it is dropped. Workflows stay with Action required: a request
 * waiting on you is not late or early, it is waiting.
 *
 * @param {Array<object>} groups  `{ key, label, itemIds, workflows }`, category-keyed
 * @param {Map<string, object>} itemsById the payload's items
 * @param {{overdue: string, teamBacklog: string}} labels translated band names
 * @return {Array<object>}
 */
export function splitTodoGroups(groups, itemsById, labels) {
    const out = []
    for (const group of groups || []) {
        const ids = group.itemIds || []
        const item = (id) => itemsById.get(id)
        if (group.key === 'action_required') {
            const late = ids.filter((id) => isOverdueRow(item(id)))
            out.push(
                { key: TODO_BAND.OVERDUE, label: labels.overdue, itemIds: late, workflows: [] },
                { ...group, itemIds: ids.filter((id) => !isOverdueRow(item(id))) },
            )
        } else if (group.key === 'upcoming') {
            const backlog = ids.filter((id) => isTeamBacklogRow(item(id)))
            out.push(
                { ...group, itemIds: ids.filter((id) => !isTeamBacklogRow(item(id))) },
                { key: TODO_BAND.TEAM_BACKLOG, label: labels.teamBacklog, itemIds: backlog, workflows: [] },
            )
        } else {
            out.push(group)
        }
    }
    return out.filter((g) => (g.itemIds || []).length + (g.workflows || []).length > 0)
}
