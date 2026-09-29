/**
 * v4.10.45 — the icons a service or a category may be given. The server
 * refuses a name it does not list (`lib/Constants/ServiceIcons.php`), the
 * client renders only the names it imports: the two lists must be one.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { SERVICE_ICON_NAMES, DEFAULT_SERVICE_ICON, serviceIconLabel, serviceIconName } from '../../src/constants/serviceIcons.js'

const root = fileURLToPath(new URL('../../', import.meta.url))

test('the client offers exactly the icons the server accepts', () => {
	const php = readFileSync(root + 'lib/Constants/ServiceIcons.php', 'utf8')
	const block = php.slice(php.indexOf('ALLOWED = ['), php.indexOf('];', php.indexOf('ALLOWED = [')))
	const server = [...block.matchAll(/'([A-Za-z]+)'/g)].map(m => m[1])
	assert.deepEqual(SERVICE_ICON_NAMES, server)
	assert.match(php, new RegExp(`DEFAULT = '${DEFAULT_SERVICE_ICON}'`))
})

test('every icon exists, is imported once and has a name of its own', () => {
	const component = readFileSync(root + 'src/components/services/ServiceIcon.vue', 'utf8')
	const labelSource = readFileSync(root + 'src/constants/serviceIcons.js', 'utf8')
	const labels = new Set()
	for (const name of SERVICE_ICON_NAMES) {
		assert.ok(existsSync(root + `node_modules/vue-material-design-icons/${name}.vue`), name + ' is not an MDI icon')
		assert.ok(component.includes(`from 'vue-material-design-icons/${name}.vue'`), name + ' is not imported')
		const label = serviceIconLabel(name)
		assert.ok(labelSource.includes(`case '${name}':`) && label, name + ' has no name')
		labels.add(label)
	}
	assert.equal(labels.size, SERVICE_ICON_NAMES.length, 'two icons read the same')
	assert.equal(new Set(SERVICE_ICON_NAMES).size, SERVICE_ICON_NAMES.length)
})

test('a name nobody offers renders the default', () => {
	assert.equal(serviceIconName('Printer'), 'Printer')
	assert.equal(serviceIconName('../App'), DEFAULT_SERVICE_ICON)
	assert.equal(serviceIconName(''), DEFAULT_SERVICE_ICON)
})
