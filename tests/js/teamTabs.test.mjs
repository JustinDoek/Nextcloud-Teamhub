/**
 * src/lib/teamTabs.js — which tabs a team shows. v4.10.41: the Services tab.
 */
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { buildAllTabDescriptors } from '../../src/lib/teamTabs.js'

const keys = ctx => buildAllTabDescriptors(ctx).map(tab => tab.key)

test('a Service-template team has a Services tab; any other team does not (v4.10.41)', () => {
    assert.ok(keys({ serviceDeskConfig: { isServiceTeam: true } }).includes('services'))
    assert.ok(!keys({ serviceDeskConfig: { isServiceTeam: false } }).includes('services'))
    assert.ok(!keys({}).includes('services'), 'before the layout bundle has said')
})
