/**
 * Meeting wrap-up (v4.20.19, DESIGN §2.271): the dialog's form rules, pure so
 * they are unit-tested in tests/js/meetingWrapUp.test.mjs.
 */

/**
 * The form rows for the action items the server read from the notes.
 *
 * @param {Array<{ title: string, assignee: ?string, due: ?string }>} items
 * @return {Array<{ title: string, assignee: string, due: string }>}
 */
export function wrapUpTasks(items) {
	return (items || [])
		.filter(item => item && String(item.title || '').trim() !== '')
		.map(item => ({
			title: String(item.title).trim(),
			assignee: item.assignee || '',
			due: /^\d{4}-\d{2}-\d{2}$/.test(item.due || '') ? item.due : '',
		}))
}

/**
 * What the dialog posts: rows without a title are left out, as an empty row
 * is the one the form starts with, not a task.
 *
 * @param {Array<{ title: string, assignee: string, due: string }>} tasks
 * @param {number[]} proposalIds
 * @return {{ tasks: Array<{ title: string, assignee: string, due: string }>, proposalIds: number[] }}
 */
export function wrapUpPayload(tasks, proposalIds) {
	return {
		tasks: (tasks || [])
			.map(task => ({
				title: String(task.title || '').trim(),
				assignee: String(task.assignee || ''),
				due: String(task.due || ''),
			}))
			.filter(task => task.title !== ''),
		proposalIds: [...new Set((proposalIds || []).map(Number).filter(id => id > 0))],
	}
}
