<template>
	<div class="th-policy">

		<!-- v4.8.3 — the page intro was removed at Justin's request. The
		     enforced-vs-reported distinction it carried has not gone with it:
		     it lives on the legend inside the profile editor, next to the
		     fields it applies to, which is where an administrator is actually
		     deciding. DESIGN §2.104 is the reason it has to be somewhere. -->

		<div v-if="loading" class="th-policy__loading">
			<NcLoadingIcon :size="iconToolbar" />
			<span>{{ t('teamhub', 'Loading policy settings…') }}</span>
		</div>

		<!-- Status region. aria-live so a save is announced to a screen
		     reader, not only shown. -->
		<div class="th-policy__status" role="status" aria-live="polite">
			<p v-if="notice" class="th-policy__notice">{{ notice }}</p>
			<p v-if="error" class="th-policy__error">
				<AlertCircleOutlineIcon :size="iconInline" aria-hidden="true" />
				{{ error }}
			</p>
		</div>

		<template v-if="!loading">

			<!-- ── Conflicts ──────────────────────────────────────────────
			     A warning surface, never a save-blocker: blocking would make
			     adding a Restricted profile fail against templates nobody
			     pairs it with. -->
			<section v-if="conflicts.length" class="th-policy__section th-policy__conflicts">
				<h3 class="th-policy__h3">
					<AlertCircleOutlineIcon :size="iconBody" aria-hidden="true" />
					{{ t('teamhub', 'Templates and profiles that disagree') }}
				</h3>
				<p class="th-policy__hint">
					{{ t('teamhub', 'The profile always wins, so a team created from one of these pairs is still correct — it simply does not get everything the template offers. Change one side if that is not what you meant.') }}
				</p>
				<ul class="th-policy__conflict-list">
					<li v-for="(c, i) in conflicts" :key="i" class="th-policy__conflict">
						<strong>{{ templateName(c.templateKey) }} + {{ profileName(c.profileKey) }}</strong>
						<span>{{ conflictLabel(c) }}</span>
					</li>
				</ul>
			</section>

			<!-- ── Templates ──────────────────────────────────────────────────
			     First on the page (v4.8.3): a template decides what a team is
			     made of, which is the thing an administrator sets up before
			     deciding how sensitive any of them are. -->
			<section class="th-policy__section">
				<h3 class="th-policy__h3">{{ t('teamhub', 'Team templates') }}</h3>
				<p class="th-policy__hint">
					{{ t('teamhub', 'A template decides what a new team is created with: its apps, its modules, and whether teams of that kind can be given an expiration date.') }}
				</p>
				<p v-if="!liveAtCreation" class="th-policy__warn">
					<AlertCircleOutlineIcon :size="iconInline" aria-hidden="true" />
					{{ t('teamhub', 'Editing a template here does not change team creation yet. The team creation form still uses the values built into this version.') }}
				</p>

				<table class="th-policy__table">
					<caption class="th-policy__caption">
						{{ t('teamhub', 'Templates, in the order they appear when creating a team.') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Name') }}</th>
							<th scope="col">{{ t('teamhub', 'Apps') }}</th>
							<th scope="col">{{ t('teamhub', 'Modules') }}</th>
							<th scope="col">{{ t('teamhub', 'Default policy') }}</th>
							<th scope="col">{{ t('teamhub', 'Team expiration') }}</th>
							<th scope="col"><span class="th-policy__sr">{{ t('teamhub', 'Actions') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="tpl in templates" :key="tpl.templateKey">
							<td>
								<span class="th-policy__name">{{ templateName(tpl.templateKey, tpl.label) }}</span>
								<code class="th-policy__key">{{ tpl.templateKey }}</code>
							</td>
							<!-- Apps and modules are grouped for display, not by
							     how they are stored: Intravox and Collectives
							     provision a resource, so they read as apps.
							     RESOURCE_MODULES has the reasoning. -->
							<td>{{ templateAppNames(tpl).join(', ') || t('teamhub', 'None') }}</td>
							<td>{{ templateModuleNames(tpl).join(', ') || t('teamhub', 'None') }}</td>
							<td>
								{{ tpl.defaultProfileKey ? profileName(tpl.defaultProfileKey) : t('teamhub', 'None') }}
							</td>
							<td>
								<template v-if="tpl.expiryEnabled">
									{{ t('teamhub', 'Enabled') }}
									<span v-if="tpl.expiryDefaultDays > 0" class="th-policy__desc">
										{{ n('teamhub', 'Default %n day', 'Default %n days', tpl.expiryDefaultDays, { n: tpl.expiryDefaultDays }) }}
									</span>
								</template>
								<template v-else>{{ t('teamhub', 'Disabled') }}</template>
							</td>
							<td class="th-policy__row-actions">
								<NcButton type="tertiary" @click="openEditTemplate(tpl)">
									{{ t('teamhub', 'Edit') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- ── Profiles ───────────────────────────────────────────────── -->
			<section class="th-policy__section">
				<div class="th-policy__section-head">
					<h3 class="th-policy__h3">{{ t('teamhub', 'Profiles') }}</h3>
					<!-- v4.8.5 — the instance-wide "Applied to new teams" that
					     stood here is gone. Which policy a new team starts on
					     is a per-template setting, edited in the template
					     above; the creator can pick a different one. -->

					<NcButton type="primary" @click="openNewProfile">
						{{ t('teamhub', 'New profile') }}
					</NcButton>
				</div>

				<NcEmptyContent
					v-if="!profiles.length"
					:name="t('teamhub', 'No profiles')"
					:description="t('teamhub', 'Create a profile to describe a set of team settings.')">
					<template #icon>
						<ShieldLockOutlineIcon :size="iconHero" />
					</template>
				</NcEmptyContent>

				<table v-else class="th-policy__table">
					<caption class="th-policy__caption">
						{{ t('teamhub', 'Profiles, least sensitive first.') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('teamhub', 'Name') }}</th>
							<th scope="col">{{ t('teamhub', 'Key') }}</th>
							<th scope="col">{{ t('teamhub', 'Settings governed') }}</th>
							<th scope="col">{{ t('teamhub', 'Teams') }}</th>
							<th scope="col"><span class="th-policy__sr">{{ t('teamhub', 'Actions') }}</span></th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="p in profiles" :key="p.profileKey">
							<td>
								<span class="th-policy__name">{{ profileName(p.profileKey, p.label) }}</span>
								<span v-if="p.description" class="th-policy__desc">{{ p.description }}</span>
							</td>
							<td><code class="th-policy__key">{{ p.profileKey }}</code></td>
							<td>{{ n('teamhub', '%n setting', '%n settings', p.fieldCount, { n: p.fieldCount }) }}</td>
							<td>{{ p.teams }}</td>
							<td class="th-policy__row-actions">
								<NcButton type="tertiary" @click="openEditProfile(p.profileKey)">
									{{ t('teamhub', 'Edit') }}
								</NcButton>
								<NcButton
									type="tertiary"
									:disabled="p.teams > 0"
									@click="confirmDelete(p)">
									{{ t('teamhub', 'Delete') }}
								</NcButton>
								<!-- Inline, not a title on the disabled button: a
								     disabled control cannot take focus, so a
								     tooltip on it is unreachable by keyboard. -->
								<span v-if="p.teams > 0" class="th-policy__hint">
									{{ t('teamhub', 'Reassign the teams using this profile before deleting it.') }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>

		<!-- ── Profile editor ─────────────────────────────────────────────── -->
		<NcModal
			v-if="profileEditor"
			:name="profileEditor.isNew ? t('teamhub', 'New profile') : t('teamhub', 'Edit profile')"
			size="large"
			@close="closeProfileEditor">
			<div class="th-policy__modal">
				<h3 class="th-policy__modal-title">
					{{ profileEditor.isNew ? t('teamhub', 'New profile') : t('teamhub', 'Edit profile') }}
				</h3>

				<div class="th-policy__field">
					<NcTextField
						v-model="profileEditor.label"
						:label="t('teamhub', 'Name')"
						:maxlength="64" />
				</div>

				<div v-if="profileEditor.isNew" class="th-policy__field">
					<NcTextField
						v-model="profileEditor.profileKey"
						:label="t('teamhub', 'Key')"
						:helper-text="t('teamhub', 'Lowercase letters, digits and underscores. This is how the profile is identified and it cannot be changed later.')"
						:maxlength="32" />
				</div>

				<!-- v4.8.7 — the description was already here and already
				     reached the wizard; what it did not do was say so. Two rows
				     with a generic label read as an admin note nobody else
				     sees, so it went unwritten. It is the one piece of text a
				     team creator reads about this policy — labelled and sized
				     accordingly. -->
				<div class="th-policy__field">
					<NcTextArea
						v-model="profileEditor.description"
						:label="t('teamhub', 'Description shown when creating a team')"
						:placeholder="t('teamhub', 'Explain what this policy means for the team — who can find it, who can join, what is shared.')"
						:rows="4" />
					<span class="th-policy__hint">
						{{ t('teamhub', 'Shown under the policy dropdown in the team creation wizard, and in the list above. Leave empty to show nothing.') }}
					</span>
				</div>

				<div class="th-policy__field">
					<label class="th-policy__label" :for="sortInputId">{{ t('teamhub', 'Order') }}</label>
					<input
						:id="sortInputId"
						v-model.number="profileEditor.sortIndex"
						type="number"
						min="0"
						max="32000"
						class="th-policy__number">
					<span class="th-policy__hint">{{ t('teamhub', 'Lower numbers are less sensitive and appear first.') }}</span>
				</div>

				<h4 class="th-policy__h4">{{ t('teamhub', 'Settings this profile governs') }}</h4>
				<p class="th-policy__hint">
					{{ t('teamhub', '“Team decides” leaves a setting to each team. “Always on” and “Always off” fix it for every team this profile is applied to, and team administrators see it greyed out.') }}
				</p>

				<!-- The legend, not a per-row tooltip. A `title` attribute is
				     not reachable by keyboard, and this is the distinction the
				     whole feature rests on — it cannot be mouse-only. -->
				<dl class="th-policy__legend">
					<dt><span class="th-policy__tag th-policy__tag--enforced">{{ tagLabel('enforced') }}</span></dt>
					<dd>{{ tagExplanation('enforced') }}</dd>
					<dt><span class="th-policy__tag th-policy__tag--asserted">{{ tagLabel('asserted') }}</span></dt>
					<dd>{{ tagExplanation('asserted') }}</dd>
				</dl>

				<!-- v4.8.17 — a grid, because nine settings × three states is a
				     table and reads as noise laid out any other way. Each row is
				     its own grid sharing one column template with the head, which
				     is the `.maint-grid` pattern HANDOFF records: the columns are
				     declared on `__head` and `__row`, never on the wrapper, so a
				     full-width note can span inside a row without escaping it.

				     The column headings carry the words, so the radios themselves
				     do not repeat them — see the `checkbox-content__text` rule in
				     the styles for how, and why it degrades safely. -->
				<div class="th-policy__grid">
					<div class="th-policy__grid-head">
						<span>{{ t('teamhub', 'Setting') }}</span>
						<span>{{ t('teamhub', 'Team decides') }}</span>
						<span>{{ t('teamhub', 'Always on') }}</span>
						<span>{{ t('teamhub', 'Always off') }}</span>
					</div>

					<div
						v-for="f in fields"
						:key="f.fieldKey"
						class="th-policy__grid-row"
						:class="{ 'th-policy__grid-row--blocked': !dependencyMet(f) || !appRequirementMet(f) }"
						role="radiogroup"
						:aria-labelledby="'thp-field-' + f.fieldKey">
						<div class="th-policy__grid-name">
							<span :id="'thp-field-' + f.fieldKey" class="th-policy__field-name">
								{{ fieldLabel(f.fieldKey) }}
							</span>
							<span class="th-policy__tag" :class="'th-policy__tag--' + f.tag">{{ tagLabel(f.tag) }}</span>
						</div>

						<!-- Greyed out rather than hidden when a dependency is
						     unmet: a control that vanishes reads as a bug, and
						     the note below says what would bring it back. -->
						<div
							v-for="opt in stateOptions(f)"
							:key="opt.value"
							class="th-policy__grid-cell"
							:class="{ 'th-policy__grid-cell--wide': opt.wide }">
							<NcCheckboxRadioSwitch
								:model-value="fieldState(f.fieldKey)"
								:value="opt.value"
								:name="'thp-state-' + f.fieldKey"
								:disabled="optionDisabled(f, opt)"
								type="radio"
								@update:model-value="setFieldState(f.fieldKey, $event)">
								{{ opt.label }}
							</NcCheckboxRadioSwitch>
						</div>

						<p v-if="!dependencyMet(f)" class="th-policy__grid-note th-policy__grid-note--reason">
							<LockOutline :size="iconInline" aria-hidden="true" />
							{{ dependencyNote(f.dependsOn) }}
						</p>
						<p v-else-if="!appRequirementMet(f)" class="th-policy__grid-note th-policy__grid-note--reason">
							<LockOutline :size="iconInline" aria-hidden="true" />
							{{ confidentialAppNote(confidentialFiles) }}
						</p>
						<p v-else-if="fieldHint(f.fieldKey)" class="th-policy__grid-note">
							{{ fieldHint(f.fieldKey) }}
						</p>

						<!-- Only the non-bool types still need a value control of
						     their own; a bool's value IS its state above. The
						     checkboxes sit inside the row's radiogroup, which is
						     valid — a group may hold other content — and keeping
						     them here is what lets them span the full width. -->
						<div
							v-if="isGoverned(f.fieldKey) && dependencyMet(f) && appRequirementMet(f) && f.type !== 'bool'"
							class="th-policy__grid-body">
							<template v-if="f.type === 'int'">
								<label class="th-policy__label" :for="'thp-int-' + f.fieldKey">{{ t('teamhub', 'Value') }}</label>
								<input
									:id="'thp-int-' + f.fieldKey"
									v-model.number="draftValues[f.fieldKey]"
									type="number"
									min="0"
									class="th-policy__number">
							</template>

							<!-- The tag picker. `label`/`track-by` copied from the
							     proven pairing in TeamExportPanel.vue rather than
							     written fresh — HANDOFF lists NcSelect's props as
							     a re-verify-against-9.x item, and a blank option
							     list is its failure mode. -->
							<template v-else-if="f.type === 'string'">
								<label class="th-policy__label" for="thp-tag-confidential">
									{{ t('teamhub', 'Classification tag') }}
								</label>
								<NcSelect
									input-id="thp-tag-confidential"
									:model-value="tagOptionFor(f.fieldKey)"
									:options="confidentialFiles.tags"
									:clearable="false"
									label="name"
									track-by="id"
									:placeholder="t('teamhub', 'Choose a classification tag')"
									:aria-label="t('teamhub', 'Classification tag')"
									@update:model-value="setTagValue(f.fieldKey, $event)" />
								<p v-if="tagOptionFor(f.fieldKey)" class="th-policy__grid-note">
									{{ tagRemovabilityNote(tagOptionFor(f.fieldKey).userAssignable) }}
								</p>
							</template>

							<div v-else class="th-policy__checks">
								<NcCheckboxRadioSwitch
									v-for="app in integrationChoices"
									:key="app"
									:model-value="listHas(f.fieldKey, app)"
									@update:model-value="toggleListItem(f.fieldKey, app, $event)">
									{{ appLabel(app) }}
								</NcCheckboxRadioSwitch>
							</div>
						</div>
					</div>
				</div>

				<div class="th-policy__modal-actions">
					<NcButton type="tertiary" @click="closeProfileEditor">
						{{ t('teamhub', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="saving" @click="saveProfile">
						{{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ── Template editor ────────────────────────────────────────────── -->
		<NcModal
			v-if="templateEditor"
			:name="t('teamhub', 'Edit template')"
			size="normal"
			@close="templateEditor = null">
			<div class="th-policy__modal">
				<h3 class="th-policy__modal-title">{{ t('teamhub', 'Edit template') }}</h3>

				<div class="th-policy__field">
					<NcTextField
						v-model="templateEditor.label"
						:label="t('teamhub', 'Name')"
						:maxlength="64" />
				</div>

				<div class="th-policy__field">
					<NcTextArea
						v-model="templateEditor.description"
						:label="t('teamhub', 'Description')"
						:rows="2" />
				</div>

				<!-- Apps and modules are grouped the way the create-team wizard
				     groups them, not the way they are stored: Intravox and
				     Collectives each provision a resource, so they sit next to
				     Calendar and Deck. `resourceGroupOf()` routes the toggle
				     back to whichever list actually holds the key. -->
				<span class="th-policy__label">{{ t('teamhub', 'Apps created with the team') }}</span>
				<div class="th-policy__checks">
					<NcCheckboxRadioSwitch
						v-for="app in appChoices"
						:key="app"
						:model-value="templateHas(app)"
						@update:model-value="toggleTemplateKey(app, $event)">
						{{ appLabel(app) }}
					</NcCheckboxRadioSwitch>
				</div>

				<span class="th-policy__label">{{ t('teamhub', 'Modules switched on') }}</span>
				<div class="th-policy__checks">
					<NcCheckboxRadioSwitch
						v-for="mod in moduleChoices"
						:key="mod"
						:model-value="templateHas(mod)"
						@update:model-value="toggleTemplateKey(mod, $event)">
						{{ moduleLabel(mod) }}
					</NcCheckboxRadioSwitch>
				</div>

				<!-- v4.8.5 — the policy teams of this kind start on. The
				     person creating the team can pick a different one; this is
				     the preselection, not a lock. -->
				<div class="th-policy__field">
					<label class="th-policy__label" for="thp-tpl-default">{{ t('teamhub', 'Default policy') }}</label>
					<select
						id="thp-tpl-default"
						v-model="templateEditor.defaultProfileKey"
						class="th-policy__select">
						<option value="">{{ t('teamhub', 'None') }}</option>
						<option v-for="p in profiles" :key="p.profileKey" :value="p.profileKey">
							{{ profileName(p.profileKey, p.label) }}
						</option>
					</select>
					<span class="th-policy__hint">
						{{ t('teamhub', 'Preselected when someone creates this kind of team. They can choose a different policy.') }}
					</span>
				</div>

				<NcCheckboxRadioSwitch
					:model-value="templateEditor.expiryEnabled"
					@update:model-value="templateEditor.expiryEnabled = $event">
					{{ t('teamhub', 'Enable team expiration') }}
				</NcCheckboxRadioSwitch>

				<div v-if="templateEditor.expiryEnabled" class="th-policy__field">
					<label class="th-policy__label" for="thp-expiry-days">
						{{ t('teamhub', 'Default expiration period (days)') }}
					</label>
					<input
						id="thp-expiry-days"
						v-model.number="templateEditor.expiryDefaultDays"
						type="number"
						min="0"
						max="3650"
						class="th-policy__number">
					<span class="th-policy__hint">
						{{ t('teamhub', 'The date the picker opens on when someone creates this kind of team. 0 leaves it on the standard six months.') }}
					</span>
				</div>

				<!-- v4.9.6 — the OpenProject template's workspace settings, in
				     the same editor: the apps and modules above decide what the
				     team is connected to; this decides the project, the roles,
				     the files and the dashboard. Saved by the Save button below
				     together with the template. -->
				<OpenProjectBlueprintSection
					v-if="templateEditor.templateKey === 'openproject'"
					ref="blueprintSection"
					:template-key="templateEditor.templateKey" />

				<div class="th-policy__modal-actions">
					<NcButton type="tertiary" @click="templateEditor = null">
						{{ t('teamhub', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="saving" @click="saveTemplate">
						{{ saving ? t('teamhub', 'Saving…') : t('teamhub', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ── Delete confirmation ────────────────────────────────────────── -->
		<NcModal
			v-if="pendingDelete"
			:name="t('teamhub', 'Delete this profile?')"
			size="small"
			@close="pendingDelete = null">
			<div class="th-policy__modal">
				<h3 class="th-policy__modal-title">{{ t('teamhub', 'Delete this profile?') }}</h3>
				<p>
					{{ t('teamhub', '"{name}" will be removed. No team is using it, so nothing else changes.', { name: profileName(pendingDelete.profileKey, pendingDelete.label) }) }}
				</p>
				<div class="th-policy__modal-actions">
					<NcButton type="tertiary" @click="pendingDelete = null">
						{{ t('teamhub', 'Cancel') }}
					</NcButton>
					<NcButton type="error" :disabled="saving" @click="doDelete">
						{{ t('teamhub', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ── Roll the saved change out ──────────────────────────────────── -->
		<!-- v4.8.27 — opens only when the save returned a plan, which needs
		     both a real change and at least one team carrying the definition.
		     The choice is deliberately the administrator's: applying silently
		     would be a bulk state change of the kind §4.3 forbids for one team,
		     and never applying would leave an edited profile meaning nothing. -->
		<NcModal
			v-if="rollout"
			:name="t('teamhub', 'Apply this change to existing teams?')"
			size="normal"
			@close="rollout = null">
			<div class="th-policy__modal">
				<h3 class="th-policy__modal-title">
					{{ rollout.report ? t('teamhub', 'Rollout report') : t('teamhub', 'Apply this change to existing teams?') }}
				</h3>

				<!-- Step 1: the choice. -->
				<template v-if="!rollout.report">
					<p>
						{{ n('teamhub',
							'{eligible} of {total} team still matches what this said before the change.',
							'{eligible} of {total} teams still match what this said before the change.',
							rollout.plan.total,
							{ eligible: rollout.plan.eligible.length, total: rollout.plan.total }) }}
					</p>
					<p v-if="rollout.plan.drifted.length" class="th-policy__hint">
						{{ t('teamhub', 'The others were changed since, so applying would overwrite something somebody did on purpose. They are left alone unless you apply to all.') }}
					</p>

					<!-- Applying really applies: removing an app deletes its
					     resource and everything in it. Said before the click,
					     not in the report afterwards. The team folder is never
					     deleted this way and the second line says so, because
					     an administrator who has just read the first line will
					     assume it is. -->
					<NcNoteCard v-if="rollout.plan.removes && rollout.plan.removes.length" type="warning">
						{{ t('teamhub', 'This deletes {apps} on every team it is applied to, along with everything in them. It cannot be undone.', { apps: rollout.plan.removes.map(appLabel).join(', ') }) }}
						<template v-if="rollout.plan.removes.includes('files')">
							<br>
							{{ t('teamhub', 'The team folder is never deleted by a rollout. Teams that still have one are listed in the report so you can remove it yourself.') }}
						</template>
					</NcNoteCard>

					<p v-if="rollout.plan.adds && rollout.plan.adds.length" class="th-policy__hint">
						{{ t('teamhub', 'Teams that do not have {apps} yet will have it created for them.', { apps: rollout.plan.adds.map(appLabel).join(', ') }) }}
					</p>

					<div v-if="rollout.plan.drifted.length" class="th-policy__rollout-list">
						<span class="th-policy__label">{{ t('teamhub', 'Changed since, and left out') }}</span>
						<ul>
							<li v-for="team in rollout.plan.drifted" :key="team.teamId">
								{{ team.teamName }}
							</li>
						</ul>
					</div>

					<div class="th-policy__modal-actions">
						<NcButton type="tertiary" :disabled="rolloutRunning" @click="rollout = null">
							{{ t('teamhub', 'Not now') }}
						</NcButton>
						<NcButton
							v-if="rollout.plan.drifted.length"
							type="secondary"
							:disabled="rolloutRunning"
							:title="t('teamhub', 'Overwrites the teams that were changed since, as well as the ones that still match.')"
							@click="runRollout(rolloutAllIds())">
							{{ n('teamhub', 'Apply to all {n} team', 'Apply to all {n} teams', rollout.plan.total, { n: rollout.plan.total }) }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="rolloutRunning || !rollout.plan.eligible.length"
							@click="runRollout(rollout.plan.eligible.map(row => row.teamId))">
							{{ n('teamhub', 'Apply to {n} team', 'Apply to {n} teams', rollout.plan.eligible.length, { n: rollout.plan.eligible.length }) }}
						</NcButton>
					</div>
				</template>

				<!-- Step 2: what actually happened, per team. A grid rather than
				     two lists — every row carries the same three facts, and a
				     status mark is what lets an administrator scan for the ones
				     that need them. -->
				<template v-else>
					<p>
						{{ n('teamhub', '{n} team updated.', '{n} teams updated.', rolloutOk.length, { n: rolloutOk.length }) }}
						<span v-if="rolloutAttention.length">
							{{ n('teamhub', '{n} needs attention.', '{n} need attention.', rolloutAttention.length, { n: rolloutAttention.length }) }}
						</span>
					</p>

					<div class="th-policy__report" role="table" :aria-label="t('teamhub', 'Rollout report')">
						<div class="th-policy__report-head" role="row">
							<div role="columnheader"><span class="th-policy__sr">{{ t('teamhub', 'Result') }}</span></div>
							<div role="columnheader">{{ t('teamhub', 'Team') }}</div>
							<div role="columnheader">{{ t('teamhub', 'What changed') }}</div>
						</div>
						<div
							v-for="row in rolloutRows"
							:key="row.teamId"
							class="th-policy__report-row"
							:class="'th-policy__report-row--' + row.status"
							role="row">
							<!-- The mark carries a text alternative, so the
							     outcome is never colour-and-glyph alone. -->
							<div class="th-policy__report-mark" role="cell">
								<CheckIcon v-if="row.status === 'ok'" :size="iconInline" :aria-label="t('teamhub', 'Applied')" />
								<AlertCircleOutlineIcon v-else-if="row.status === 'partial'" :size="iconInline" :aria-label="t('teamhub', 'Needs attention')" />
								<CloseIcon v-else :size="iconInline" :aria-label="t('teamhub', 'Not applied')" />
							</div>
							<div class="th-policy__report-team" role="cell">{{ row.teamName }}</div>
							<div role="cell">
								<span v-if="row.detail">{{ row.detail }}</span>
								<span v-else class="th-policy__hint">{{ t('teamhub', 'Already matched — nothing to change.') }}</span>
							</div>
						</div>
					</div>

					<p v-if="rollout.report.overflow" class="th-policy__hint">
						{{ n('teamhub',
							'{n} more team was not processed in this run. Save the change again to continue.',
							'{n} more teams were not processed in this run. Save the change again to continue.',
							rollout.report.overflow, { n: rollout.report.overflow }) }}
					</p>

					<div class="th-policy__modal-actions">
						<NcButton type="primary" @click="rollout = null">
							{{ t('teamhub', 'Close') }}
						</NcButton>
					</div>
				</template>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import ShieldLockOutlineIcon from 'vue-material-design-icons/ShieldLockOutline.vue'
// v4.9.6 — Phase 2: the OpenProject template's workspace settings, inside
// the template editor (one editor per template).
import OpenProjectBlueprintSection from './OpenProjectBlueprintSection.vue'

import { ICON_INLINE, ICON_BODY, ICON_TOOLBAR, ICON_HERO } from '../../constants/uiTokens.js'
import {
	RESOURCE_MODULES,
	appLabel,
	confidentialAppNote,
	conflictLabel,
	dependencyNote,
	fieldHint,
	fieldLabel,
	integrationLabel,
	moduleLabel,
	profileDisplayName,
	tagExplanation,
	tagLabel,
	tagRemovabilityNote,
	templateDisplayName,
} from '../../constants/policy.js'

/**
 * Templates and classification profiles — admin panel (v4.8.2, Track F2a).
 *
 * Design: `TRACK-F2-DESIGN.md`. Reasoning: `DESIGN.md` §2.104–§2.108.
 *
 * **Definitions only.** Nothing here assigns a profile to a team or changes a
 * team's settings — that is F2b. The panel says so twice, in the lede and on
 * the template table, because an editable form whose edits do not reach team
 * creation is exactly what gets reported as a bug six months later.
 *
 * Its own file because AdminSettings.vue is already ~7 900 lines; the tab there
 * is a four-line shell, matching how the My Work and Presence tabs mount.
 *
 * Three things worth knowing before editing this:
 *
 * - **A field with no row is ungoverned, which is not the same as false.** That
 *   is still true and still the point; what changed in v4.8.17 is how it is
 *   said. One three-way control per setting — *Team decides* / *Always on* /
 *   *Always off* — replaced a pair of identical checkboxes, an outer one for
 *   "governed" and an inner wordless one labelled "On" for the value.
 *
 *   **This reverses the note that stood here**, which recorded that collapsing
 *   the two "was tried on paper and reads as a bug". On paper it did. In use it
 *   was the two checkboxes that read as a bug: Justin's review on 2026-09-06
 *   asked whether the second one was a duplicate, which is the question the
 *   control's own shape invites. Three states in words beat two identical
 *   widgets, one of them wordless. Do not collapse it back to a single checkbox
 *   either — that loses "Always on", which is the only way to express the four
 *   hardening bits (`cfg_invite`, `cfg_request`, `cfg_protected`, `cfg_root`),
 *   where ON is the safe direction and forcing them off is not a policy anyone
 *   wants.
 *
 *   The stored shape is unchanged: absent, `1` and `0` are the three states, so
 *   this was a UI change with no migration and no server edit.
 * - **The tag chip is not decoration.** Enforced and Reported are the two
 *   halves of DESIGN §2.104, and an administrator who cannot see which is which
 *   has been sold a control that does not exist for half the fields.
 * - **Apps and modules are grouped for display, not by how they are stored.**
 *   Intravox (`pages`) and Collectives (`wiki`) live in the `modules` list but
 *   render under Apps, because each provisions a resource rather than toggling
 *   a feature — the call `CreateTeamView.vue` made in v4.4.10. `RESOURCE_MODULES`
 *   is the shared list and `toggleTemplateKey()` routes a toggle back to
 *   whichever array actually holds the key.
 *
 * v4.8.3 removed the per-field mode picker: a setting is governed or it is not,
 * and a governed setting is locked.
 */
export default {
	name: 'PolicyAdminPanel',
	components: {
		NcButton,
		OpenProjectBlueprintSection,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		AlertCircleOutlineIcon,
		CheckIcon,
		CloseIcon,
		LockOutline,
		ShieldLockOutlineIcon,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: null,
			notice: null,

			fields: [],
			// v4.8.24 — arrives with the field catalogue, not from an endpoint
			// of its own, so the panel cannot render the field list and its
			// availability from two reads that disagree. Defaults to
			// unavailable: a catalogue that has not loaded must grey the field
			// out rather than offer an empty picker, the same way the licence
			// gate treats an unloaded licence as inactive.
			confidentialFiles: { appAvailable: false, labelsConfigured: false, tags: [] },
			profiles: [],
			templates: [],
			// v4.8.27 — the rollout choice, and then its report. Null until a
			// save comes back with a plan; `report` stays null until the
			// administrator picks one of the two apply options, so one object
			// drives both steps of the dialog.
			rollout: null,
			rolloutRunning: false,
			conflicts: [],
			apps: [],
			modules: [],
			liveAtCreation: false,

			/** @type {?object} open profile editor, or null */
			profileEditor: null,
			/** Field key => value, for the profile being edited. Absent = ungoverned. */
			draftValues: {},
			/** @type {?object} open template editor, or null */
			templateEditor: null,
			/** @type {?object} profile awaiting delete confirmation */
			pendingDelete: null,

			sortInputId: 'thp-sort-index',
			iconInline: ICON_INLINE,
			iconBody: ICON_BODY,
			iconToolbar: ICON_TOOLBAR,
			iconHero: ICON_HERO,
		}
	},

	computed: {
		/**
		 * The rollout report as one flat, sortable list of rows (v4.8.27b).
		 *
		 * The report arrives as `applied` and `failed`, which is the right
		 * shape for the server and the wrong one for a person: an administrator
		 * scanning it wants the teams needing them at the top, not two lists to
		 * cross-reference. Three statuses — `ok`, `partial`, `fail` — and
		 * anything that is not a clean success sorts first.
		 */
		rolloutRows() {
			const report = this.rollout?.report
			if (!report) return []

			const rows = []

			for (const team of report.applied) {
				const bits = []
				if (team.added?.length) {
					bits.push(t('teamhub', 'added {apps}', { apps: team.added.map(integrationLabel).join(', ') }))
				}
				if (team.removed?.length) {
					bits.push(t('teamhub', 'removed {apps}', { apps: team.removed.map(integrationLabel).join(', ') }))
				}
				if (team.applied?.length) {
					bits.push(n('teamhub', '{n} setting applied', '{n} settings applied', team.applied.length, { n: team.applied.length }))
				}
				// The two that mean "we did not finish", kept distinct: `manual`
				// is something this path will not do, `errors` is something it
				// tried and could not.
				if (team.manual?.length) {
					bits.push(t('teamhub', 'left for you: {apps}', { apps: team.manual.map(integrationLabel).join(', ') }))
				}
				if (team.errors?.length) {
					bits.push(t('teamhub', 'could not remove {apps}', { apps: team.errors.map(integrationLabel).join(', ') }))
				}
				if (team.notApplied?.length) {
					bits.push(t('teamhub', 'not applied: {fields}', { fields: team.notApplied.map(fieldLabel).join(', ') }))
				}

				const needsAttention = !!(team.manual?.length || team.errors?.length || team.notApplied?.length)
				rows.push({
					teamId: team.teamId,
					teamName: team.teamName || team.teamId,
					status: needsAttention ? 'partial' : 'ok',
					detail: bits.join(' · '),
				})
			}

			for (const team of report.failed) {
				rows.push({
					teamId: team.teamId,
					// Falls back to the id only when the circle is gone, which
					// is the one case where there is no name to show.
					teamName: team.teamName || team.teamId,
					status: 'fail',
					detail: team.reason === 'not_carrying'
						? t('teamhub', 'No longer uses this — skipped.')
						: (team.error || t('teamhub', 'Failed.')),
				})
			}

			const rank = { fail: 0, partial: 1, ok: 2 }
			return rows.sort((a, b) => rank[a.status] - rank[b.status] || a.teamName.localeCompare(b.teamName))
		},

		rolloutOk() {
			return this.rolloutRows.filter(r => r.status === 'ok')
		},

		rolloutAttention() {
			return this.rolloutRows.filter(r => r.status !== 'ok')
		},

		/**
		 * What the Apps group offers: the real app keys plus the two modules
		 * that provision a resource. Order matters — apps first, in vocabulary
		 * order, then Intravox and Collectives, matching the wizard.
		 */
		appChoices() {
			return [...this.apps, ...RESOURCE_MODULES.filter(k => this.modules.includes(k))]
		},

		/** Modules minus the two that render as apps. */
		moduleChoices() {
			return this.modules.filter(k => !RESOURCE_MODULES.includes(k))
		},

		/**
		 * What the integration allow-list offers. Same grouping as the template
		 * editor: a profile that allows Deck but not Collectives has to be able
		 * to say so, and the two lists disagreeing would be its own bug.
		 */
		integrationChoices() {
			return this.appChoices
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,
		n,
		appLabel,
		confidentialAppNote,
		conflictLabel,
		dependencyNote,
		fieldHint,
		fieldLabel,
		moduleLabel,
		tagExplanation,
		tagLabel,
		tagRemovabilityNote,

		/**
		 * Whether a field's dependency is satisfied — the named field must
		 * itself be governed AND true. `cfg_request` is the only one today:
		 * without `cfg_open`, Circles treats the team as simply closed, so
		 * offering the control would be offering a setting nothing reads.
		 */
		dependencyMet(field) {
			if (!field.dependsOn) return true
			return this.draftValues[field.dependsOn] === true
		},

		/**
		 * Whether the app a field needs is present and actually usable (v4.8.24).
		 *
		 * `requiresApp` is generic in the catalogue but `confidential_tag` is
		 * its only member, so the availability payload is read directly rather
		 * than keyed by app id. Add a second such field and this becomes a map;
		 * building the map now would be a registry with one row in it.
		 *
		 * **Usable, not merely installed.** An app with no classification labels
		 * offers no tags, and a picker with nothing in it is the shape of the
		 * v4.6.16 Mail bug — a feature that reports itself healthy and cannot do
		 * the thing being asked of it.
		 */
		appRequirementMet(field) {
			if (!field.requiresApp) return true
			return this.confidentialFiles.appAvailable
				&& this.confidentialFiles.labelsConfigured
				&& (this.confidentialFiles.tags || []).length > 0
		},

		/**
		 * Whether one state radio is unavailable.
		 *
		 * An unmet *dependency* disables the whole row: the setting is
		 * meaningless, including the choice to govern it.
		 *
		 * An unmet *app requirement* deliberately leaves "Team decides"
		 * enabled. Disabling every option would trap an administrator on an
		 * instance where the app was removed after a profile was saved — the
		 * server refuses to store a tag it cannot validate, so an unrelated edit
		 * to that profile's name would fail with no way on screen to clear the
		 * field that is blocking it.
		 */
		optionDisabled(field, opt) {
			if (!this.dependencyMet(field)) return true
			if (this.appRequirementMet(field)) return false
			return opt.value !== 'unset'
		},

		/**
		 * The option object for a stored tag id, or null.
		 *
		 * `draftValues` holds the id because that is what is stored and sent;
		 * NcSelect wants the whole option. Null when the tag has been deleted
		 * since the profile was saved — the picker then shows its placeholder
		 * rather than a blank row, and re-saving is what repairs the value.
		 */
		tagOptionFor(fieldKey) {
			const id = this.draftValues[fieldKey]
			if (!id) return null
			return (this.confidentialFiles.tags || []).find(tag => tag.id === id) || null
		},

		/** Store the id, never the option object — the wire format is the id. */
		setTagValue(fieldKey, option) {
			this.draftValues[fieldKey] = option ? option.id : ''
		},

		/** Display names for a template's apps group. */
		templateAppNames(tpl) {
			return [
				...tpl.apps.map(appLabel),
				...RESOURCE_MODULES.filter(k => tpl.modules.includes(k)).map(appLabel),
			]
		},

		/** Display names for a template's modules group. */
		templateModuleNames(tpl) {
			return tpl.modules.filter(k => !RESOURCE_MODULES.includes(k)).map(moduleLabel)
		},

		url(path) {
			return generateUrl('/apps/teamhub/api/v1/admin/policy' + path)
		},

		/**
		 * A seeded profile's label is translated by key; one an admin has
		 * renamed is shown as they typed it. Same for templates.
		 *
		 * v4.8.15 — the rule itself moved to `constants/policy.js` when the
		 * Maintenance grid became a third caller. These two resolve the row from
		 * local state and delegate; the naming rule has one home.
		 */
		profileName(profileKey, storedLabel) {
			const label = storedLabel !== undefined
				? storedLabel
				: this.profiles.find(x => x.profileKey === profileKey)?.label
			return profileDisplayName(profileKey, label, this.isSeededProfile(profileKey))
		},

		templateName(templateKey, storedLabel) {
			const label = storedLabel !== undefined
				? storedLabel
				: this.templates.find(x => x.templateKey === templateKey)?.label
			return templateDisplayName(templateKey, label, this.isSeededTemplate(templateKey))
		},

		isSeededProfile(key) {
			return !!this.profiles.find(x => x.profileKey === key)?.isSeeded
		},

		isSeededTemplate(key) {
			return !!this.templates.find(x => x.templateKey === key)?.isSeeded
		},

		async load() {
			this.loading = true
			this.error = null
			try {
				const [fields, profiles, templates, conflicts] = await Promise.all([
					axios.get(this.url('/fields')),
					axios.get(this.url('/profiles')),
					axios.get(this.url('/templates')),
					axios.get(this.url('/conflicts')),
				])
				this.fields = fields.data.fields || []
			this.confidentialFiles = fields.data.confidentialFiles
				|| { appAvailable: false, labelsConfigured: false, tags: [] }
				this.profiles = profiles.data.profiles || []
				this.templates = templates.data.templates || []
				this.apps = templates.data.apps || []
				this.modules = templates.data.modules || []
				this.liveAtCreation = !!templates.data.liveAtCreation
				this.conflicts = conflicts.data.conflicts || []
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not load policy settings.'))
			} finally {
				this.loading = false
			}
		},

		readError(e, fallback) {
			return e?.response?.data?.error || fallback
		},

		flash(message) {
			this.notice = message
			this.error = null
			window.setTimeout(() => { this.notice = null }, 4000)
		},

		// ── Profile editor ──────────────────────────────────────────────

		openNewProfile() {
			this.profileEditor = {
				isNew: true,
				profileKey: '',
				label: '',
				description: '',
				sortIndex: this.nextSortIndex(),
			}
			this.draftValues = {}
		},

		nextSortIndex() {
			// Gaps of ten, so an admin can insert a level between two existing
			// ones without renumbering.
			const highest = this.profiles.reduce((max, p) => Math.max(max, p.sortIndex), -10)
			return highest + 10
		},

		async openEditProfile(profileKey) {
			this.error = null
			try {
				const { data } = await axios.get(this.url('/profiles/' + encodeURIComponent(profileKey)))
				this.profileEditor = {
					isNew: false,
					profileKey: data.profileKey,
					label: data.label,
					description: data.description || '',
					sortIndex: data.sortIndex,
				}
				// Clone rather than bind: closing without saving must not leave
				// the list showing edits that were never sent.
				this.draftValues = { ...(data.values || {}) }
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not open that profile.'))
			}
		},

		closeProfileEditor() {
			this.profileEditor = null
			this.draftValues = {}
		},

		/**
		 * Is this field governed by the profile being edited?
		 *
		 * **`in`, not `Object.prototype.hasOwnProperty.call()`.** That is not a
		 * style preference — it is the difference between this screen working
		 * and not, and it cost a bug report.
		 *
		 * Vue 3's reactive proxy traps `get`, `set`, `deleteProperty`, `has`
		 * and `ownKeys`. The `in` operator goes through the `has` trap, so
		 * reading it inside a render registers a dependency and adding the key
		 * later re-renders. `Object.prototype.hasOwnProperty.call(proxy, key)`
		 * calls the native function directly: it uses `[[GetOwnProperty]]`,
		 * which Vue does not trap, so **nothing is tracked**. Vue 3.4 added an
		 * instrumented `hasOwnProperty`, but only for `obj.hasOwnProperty(key)`
		 * — reached through the `get` trap — which the `.call()` form skips.
		 *
		 * The symptom was exact and misleading: ticking a setting's checkbox
		 * updated `draftValues` and changed nothing on screen, so the control
		 * looked dead. Opening an existing profile still rendered correctly,
		 * because that path builds `draftValues` before the first render.
		 */
		isGoverned(fieldKey) {
			return fieldKey in this.draftValues
		},

		/**
		 * The states a field can be in, as radio options (v4.8.17).
		 *
		 * A bool gets all three; everything else gets two, because "on" and
		 * "off" are not what a list or a number is choosing between. The
		 * `unset` option is first in both, so the inert answer is the one the
		 * eye lands on — §5's inertness rule reaching the control itself.
		 */
		stateOptions(field) {
			// TRANSLATORS: policy setting state — the profile does not govern this setting, so each team keeps its own value
			const decides = { value: 'unset', label: t('teamhub', 'Team decides') }

			if (field.type === 'bool') {
				return [
					decides,
					// TRANSLATORS: policy setting state — the profile forces this setting on for every team it is applied to
					{ value: 'on', label: t('teamhub', 'Always on') },
					// TRANSLATORS: policy setting state — the profile forces this setting off for every team it is applied to
					{ value: 'off', label: t('teamhub', 'Always off') },
				]
			}

			if (field.type === 'string') {
				return [
					decides,
					// Its own wording rather than the list's. "Restrict to the
					// ticked items" describes an allow-list; this field picks one
					// value and applies it, which is the opposite shape.
					// TRANSLATORS: policy setting state — the profile puts the tag chosen below on every team it is applied to
					{ value: 'set', label: t('teamhub', 'Always apply the tag below'), wide: true },
				]
			}

			return [
				decides,
				// `wide` — this option matches no column heading, so its cell
				// spans the two "Always" columns and keeps its own visible
				// label. Three headings cannot describe a list.
				// TRANSLATORS: policy setting state — the profile limits this setting to the items ticked below it
				{ value: 'set', label: t('teamhub', 'Restrict to the ticked items'), wide: true },
			]
		},

		/**
		 * Which state a field is in now.
		 *
		 * Reads `isGoverned()` first, so the `has` trap registers the dependency
		 * — see that method's docblock, which is load-bearing rather than
		 * explanatory.
		 */
		fieldState(fieldKey) {
			if (!this.isGoverned(fieldKey)) {
				return 'unset'
			}
			const field = this.fields.find(f => f.fieldKey === fieldKey)
			if (field && field.type !== 'bool') {
				return 'set'
			}
			return this.draftValues[fieldKey] === true ? 'on' : 'off'
		},

		/**
		 * Move a field to a state, then re-check what depends on it.
		 *
		 * Switching "Anyone can join" away from "Always on" does not only grey
		 * out "Join requests need approval" — it makes it meaningless, so it
		 * stops being governed.
		 */
		setFieldState(fieldKey, state) {
			if (state === 'unset') {
				delete this.draftValues[fieldKey]
			} else if (state === 'on') {
				this.draftValues[fieldKey] = true
			} else if (state === 'off') {
				this.draftValues[fieldKey] = false
			} else if (!this.isGoverned(fieldKey)) {
				// 'set', for the non-bool types. Guarded on not already being
				// governed: re-selecting the state a field is already in must
				// not wipe an allow-list the admin has just ticked.
				this.draftValues[fieldKey] = this.emptyValueFor(this.fields.find(f => f.fieldKey === fieldKey))
			}
			this.pruneDependents()
		},

		/**
		 * Drop every governed field whose dependency is no longer satisfied.
		 *
		 * The server does the same on save. Doing it here as well is not
		 * belt-and-braces — it is what keeps the settings count in the profile
		 * list, and the row the admin is looking at, agreeing with what will
		 * actually be stored.
		 */
		pruneDependents() {
			for (const f of this.fields) {
				if (f.dependsOn && this.isGoverned(f.fieldKey) && !this.dependencyMet(f)) {
					delete this.draftValues[f.fieldKey]
				}
			}
		},

		emptyValueFor(field) {
			if (!field) return false
			if (field.type === 'int') return 0
			if (field.type === 'list') return []
			// v4.8.24 — a string field starts on the first available tag rather
			// than empty. The server refuses an empty value for this type, so an
			// empty default would let an administrator tick "Always on" and then
			// meet a 400 on save for a field they had not finished filling in.
			// The row is greyed unless at least one tag exists, so the fallback
			// is unreachable in practice and is here only so the expression is
			// total.
			if (field.type === 'string') {
				const tags = this.confidentialFiles.tags || []
				return tags.length ? tags[0].id : ''
			}
			return false
		},

		listHas(fieldKey, item) {
			const value = this.draftValues[fieldKey]
			return Array.isArray(value) && value.includes(item)
		},

		toggleListItem(fieldKey, item, on) {
			const current = Array.isArray(this.draftValues[fieldKey]) ? this.draftValues[fieldKey] : []
			this.draftValues[fieldKey] = on
				? [...current, item]
				: current.filter(x => x !== item)
		},

		async saveProfile() {
			this.saving = true
			this.error = null
			const editor = this.profileEditor
			const body = {
				label: editor.label,
				description: editor.description || null,
				sortIndex: editor.sortIndex,
				values: this.draftValues,
			}
			try {
				let saved = null
				if (editor.isNew) {
					const res = await axios.post(this.url('/profiles'), { ...body, profileKey: editor.profileKey })
					saved = res.data
				} else {
					const res = await axios.put(this.url('/profiles/' + encodeURIComponent(editor.profileKey)), body)
					saved = res.data
				}
				const profileKey = editor.profileKey
				this.closeProfileEditor()
				await this.load()
				this.flash(t('teamhub', 'Profile saved.'))
				// v4.8.27 — the save stores the definition and stops. The plan
				// only comes back when the values changed AND some team carries
				// the profile, so the dialog never opens on a rename or on a
				// profile nobody uses.
				this.openRollout('profile', profileKey, saved?.propagation)
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not save the profile.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Offer the rollout, if the save came back with one (v4.8.27).
		 *
		 * Silent when nothing changed or no team carries the definition — the
		 * server omits `propagation` in both cases, so the common
		 * rename-and-save stays one click and the dialog only appears when
		 * there is a real decision to make.
		 */
		openRollout(kind, key, plan) {
			if (!plan || !plan.total) return
			this.rollout = { kind, key, plan, report: null }
		},

		/**
		 * Run the rollout over `teamIds` and keep the report in the dialog.
		 *
		 * The ids are sent rather than a mode flag, because "compliant" was
		 * decided against the definition's previous values and the server can
		 * no longer recompute it — see PolicyPropagationService. Forcing simply
		 * sends both lists.
		 */
		async runRollout(teamIds) {
			if (!this.rollout || !teamIds.length) return
			this.rolloutRunning = true
			this.error = null
			const { kind, key } = this.rollout
			const path = kind === 'profile'
				? '/profiles/' + encodeURIComponent(key) + '/propagate'
				: '/templates/' + encodeURIComponent(key) + '/propagate'
			try {
				const { data } = await axios.post(this.url(path), { teamIds })
				this.rollout.report = data
				await this.load()
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not roll the change out.'))
				this.rollout = null
			} finally {
				this.rolloutRunning = false
			}
		},

		/** Every team carrying the definition, drifted or not. */
		rolloutAllIds() {
			const plan = this.rollout?.plan
			if (!plan) return []
			return [
				...plan.eligible.map(t2 => t2.teamId),
				...plan.drifted.map(t2 => t2.teamId),
			]
		},

		confirmDelete(profile) {
			this.pendingDelete = profile
		},

		async doDelete() {
			this.saving = true
			this.error = null
			try {
				await axios.delete(this.url('/profiles/' + encodeURIComponent(this.pendingDelete.profileKey)))
				this.pendingDelete = null
				await this.load()
				this.flash(t('teamhub', 'Profile deleted.'))
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not delete the profile.'))
			} finally {
				this.saving = false
			}
		},

		// ── Template editor ─────────────────────────────────────────────

		openEditTemplate(tpl) {
			this.templateEditor = {
				templateKey: tpl.templateKey,
				label: tpl.label,
				description: tpl.description || '',
				apps: [...tpl.apps],
				modules: [...tpl.modules],
				expiryEnabled: tpl.expiryEnabled,
				expiryDefaultDays: tpl.expiryDefaultDays,
				preselectConfig: tpl.preselectConfig,
				sortIndex: tpl.sortIndex,
				// '' rather than null so the <select> binds cleanly; the
				// service turns it back into null.
				defaultProfileKey: tpl.defaultProfileKey || '',
			}
		},

		/**
		 * Which array actually holds a key, whichever group it renders in.
		 * `pages` and `wiki` are shown under Apps and stored under modules.
		 */
		/**
		 * Which of the template's two arrays stores this key (v4.8.32).
		 *
		 * **Answered from the server's vocabulary, not from the display
		 * grouping.** This read `RESOURCE_MODULES.includes(key) ? 'modules' :
		 * 'apps'`, and `RESOURCE_MODULES` is only `pages` and `wiki` — the two
		 * modules that *render* under Apps. Every other module fell through to
		 * `apps`, so ticking Decisions, Presence, Timeline or Messages put it in
		 * the apps array and the save died on `Unknown app: presence`. **No
		 * internal module could be enabled on a template at all.**
		 *
		 * The old rule was a display concern used for storage routing; the two
		 * only ever agreed for `pages` and `wiki` by coincidence.
		 */
		resourceGroupOf(key) {
			if (this.modules.includes(key)) return 'modules'
			if (this.apps.includes(key)) return 'apps'
			// Vocabulary not loaded — the editor cannot open before `load()`,
			// so this is unreachable in practice. Falls back to the display
			// grouping, which is the only thing the old rule got right.
			return RESOURCE_MODULES.includes(key) ? 'modules' : 'apps'
		},

		templateHas(key) {
			return this.templateEditor[this.resourceGroupOf(key)].includes(key)
		},

		toggleTemplateKey(key, on) {
			const which = this.resourceGroupOf(key)
			const current = this.templateEditor[which]
			this.templateEditor[which] = on
				? [...current, key]
				: current.filter(x => x !== key)
		},

		async saveTemplate() {
			this.saving = true
			this.error = null
			const editor = this.templateEditor
			try {
				const { data: saved } = await axios.put(this.url('/templates/' + encodeURIComponent(editor.templateKey)), {
					label: editor.label,
					description: editor.description || null,
					apps: editor.apps,
					modules: editor.modules,
					expiryEnabled: editor.expiryEnabled,
					expiryDefaultDays: editor.expiryDefaultDays || 0,
					// Passed straight back. The server masks it to
					// MANAGED_BITS — this panel does not edit the preselection
					// bits in F2a, and round-tripping the value is how they
					// survive a template edit unchanged.
					preselectConfig: editor.preselectConfig,
					sortIndex: editor.sortIndex,
					defaultProfileKey: editor.defaultProfileKey || '',
				})
				// v4.9.6 — the OpenProject section saves its blueprint under the
				// same button. Its own error shows inside the section; the
				// template is already saved by then, which is the right order:
				// a refused blueprint must not lose the template edit.
				if (this.$refs.blueprintSection) {
					await this.$refs.blueprintSection.save()
				}
				const templateKey = editor.templateKey
				this.templateEditor = null
				await this.load()
				this.flash(t('teamhub', 'Template saved.'))
				this.openRollout('template', templateKey, saved?.propagation)
			} catch (e) {
				this.error = this.readError(e, t('teamhub', 'Could not save the template.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
/* Bounded and padded like every other admin panel — `.team-import` and
   `.team-export` both use this exact width, and this one had no bound at all.
   That is what put the "New profile" button most of a screen away from the
   table it belongs to: the section head stretched to the viewport while the
   grid below it stayed at its content width.
   The inline padding matches the tab bar above (`padding: 0 16px`), so the
   panel's content starts on the same line as the tab labels instead of hard
   against the edge. */
.th-policy {
	display: flex;
	flex-direction: column;
	gap: 24px;
	max-width: 980px;
	padding-inline: 16px;
}

.th-policy__lede {
	max-width: 70ch;
	font-size: var(--th-font-body);
	line-height: var(--th-line-height-body);
	color: var(--color-main-text);
}

.th-policy__lede--muted {
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
}

.th-policy__loading {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-body);
}

.th-policy__status:empty {
	display: none;
}

.th-policy__notice {
	color: var(--color-success-text);
	font-size: var(--th-font-body);
}

.th-policy__error,
.th-policy__warn {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: var(--th-font-body);
}

.th-policy__error {
	color: var(--color-error-text);
}

.th-policy__warn {
	color: var(--color-warning-text);
}

.th-policy__section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.th-policy__section-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.th-policy__h3 {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: var(--th-font-heading);
	font-weight: var(--th-font-weight-semibold);
	margin: 0;
}

.th-policy__h4 {
	font-size: var(--th-font-body);
	font-weight: var(--th-font-weight-semibold);
	margin: 16px 0 0;
}

.th-policy__hint {
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
	max-width: 70ch;
}

/* v4.8.27 — the rollout dialog's team lists. Capped and scrolled rather than
   allowed to grow: the estate can be hundreds of teams and the two action
   buttons must stay reachable without scrolling the modal itself. */
.th-policy__rollout-list {
	margin-block: 12px;
}

.th-policy__rollout-list ul {
	margin: 4px 0 0;
	padding-inline-start: 20px;
	max-height: 220px;
	overflow-y: auto;
}

.th-policy__rollout-list li {
	font-size: var(--th-font-body);
	line-height: var(--th-line-height-body);
}

/* The per-team note sits after the name, so it reads as a clause rather than
   as a second list item. */
.th-policy__rollout-list .th-policy__hint {
	margin-inline-start: 6px;
}

/* v4.8.27b — the report grid. Three columns: a status mark narrow enough to
   scan down, the team name, and what changed. `.maint-grid`'s rule applies here
   too — the columns go on the head and row elements, not the wrapper. */
.th-policy__report {
	margin-block: 12px;
	max-height: 320px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--th-radius-card);
}

.th-policy__report-head,
.th-policy__report-row {
	display: grid;
	grid-template-columns: 28px minmax(140px, 1fr) minmax(180px, 2fr);
	gap: 8px;
	align-items: baseline;
	padding: 6px 10px;
}

.th-policy__report-head {
	position: sticky;
	top: 0;
	z-index: 1;
	background: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	font-size: var(--th-font-meta);
	font-weight: var(--th-font-weight-semibold);
	color: var(--color-text-maxcontrast);
}

.th-policy__report-row {
	font-size: var(--th-font-body);
	line-height: var(--th-line-height-body);
	border-bottom: 1px solid var(--color-border-dark);
}

.th-policy__report-row:last-child {
	border-bottom: none;
}

.th-policy__report-team {
	font-weight: var(--th-font-weight-medium);
	overflow-wrap: anywhere;
}

/* The mark is an icon plus its own aria-label, never colour alone — the row
   text always says what happened as well. */
.th-policy__report-mark {
	display: flex;
	align-items: center;
	justify-content: center;
}

.th-policy__report-row--ok .th-policy__report-mark {
	color: var(--color-success-text, var(--color-success));
}

.th-policy__report-row--partial .th-policy__report-mark {
	/* NC ships no --color-text-warning; the local fallback is the one the
	   HANDOFF note records for .th-time. */
	color: var(--color-warning-text, #b45309);
}

.th-policy__report-row--fail .th-policy__report-mark {
	color: var(--color-error-text, var(--color-error));
}

/* Visible to a screen reader, not on screen — the mark column's heading. */
.th-policy__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

/* `--indent` and `--reason` lived here until v4.8.17. Both belonged to the
   stacked field list the settings grid replaced: the indent aligned a hint under
   a checkbox that no longer exists, and the reason note is now
   `__grid-note--reason`, which has to span the row's columns rather than sit in
   the flow. Removed rather than left behind — a dead rule with a plausible name
   is what the next reader reaches for first. */

.th-policy__conflicts {
	border-inline-start: 3px solid var(--color-warning-text);
	padding-inline-start: 12px;
}

.th-policy__conflict-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.th-policy__conflict {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 4px 0;
	font-size: var(--th-font-body);
}

/* Wide tables scroll inside their own box rather than the page. */
/* A real table box at full width, so the grid's right edge is the panel's
   right edge and the button in the section head above lines up with it.
   Was `display: block` + `overflow-x: auto`. That makes the element a block
   box, and the rows inside it then form an *anonymous* table that shrink-wraps
   to its content — so `width: 100%` sized the invisible block while the
   visible grid stopped wherever the text happened to end. Auto layout wraps
   the long cells at this width, so the horizontal scroller that idiom provides
   is not buying anything at these column counts. */
.th-policy__table {
	width: 100%;
	border-collapse: collapse;
}

.th-policy__caption {
	text-align: start;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
	padding-bottom: 4px;
}

.th-policy__table th,
.th-policy__table td {
	text-align: start;
	padding: 8px 12px 8px 0;
	border-bottom: 1px solid var(--color-border);
	font-size: var(--th-font-body);
	vertical-align: top;
}

.th-policy__table th {
	font-weight: var(--th-font-weight-semibold);
	color: var(--color-text-maxcontrast);
	font-size: var(--th-font-meta);
}

.th-policy__name {
	display: block;
	font-weight: var(--th-font-weight-medium);
}

.th-policy__desc {
	display: block;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
}

.th-policy__key {
	font-size: var(--th-font-micro);
	color: var(--color-text-maxcontrast);
}

.th-policy__sep {
	color: var(--color-text-maxcontrast);
	padding: 0 4px;
}

.th-policy__row-actions {
	display: flex;
	gap: 4px;
}

.th-policy__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
}

.th-policy__modal {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.th-policy__modal-title {
	font-size: var(--th-font-heading-lg);
	font-weight: var(--th-font-weight-semibold);
	margin: 0;
}

.th-policy__modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}

.th-policy__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.th-policy__label {
	font-size: var(--th-font-meta);
	font-weight: var(--th-font-weight-medium);
	color: var(--color-main-text);
}

.th-policy__number,
.th-policy__select {
	max-width: 240px;
	border-radius: var(--th-radius-control);
}

/* NC's own field pattern: no outline, a border-colour change on focus.
   Not grouped with :hover — grouping is what silences a keyboard focus
   ring, and six of those were fixed in v3.101.0. */
.th-policy__number:focus,
.th-policy__select:focus {
	outline: none;
	border-color: var(--color-primary-element);
}

.th-policy__number:focus-visible,
.th-policy__select:focus-visible {
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

/* ── The settings grid (v4.8.17) ────────────────────────────────────────────
   Nine settings against three states is a table. Laid out as stacked blocks it
   was nine little forms, and the eye had nothing to run down.

   Columns are declared on `__head` and `__row`, never on `__grid` — the
   `.maint-grid` pattern HANDOFF records. That is what lets a note or the
   integrations list span the full width *inside* a row: they are extra grid
   lines in that row's own grid, not escapees from the wrapper's. */
.th-policy__grid {
	display: flex;
	flex-direction: column;
}

.th-policy__grid-head,
.th-policy__grid-row {
	display: grid;
	grid-template-columns: minmax(180px, 1.7fr) repeat(3, minmax(84px, 0.55fr));
	align-items: center;
	gap: 2px 8px;
}

.th-policy__grid-head {
	font-size: var(--th-font-micro);
	font-weight: var(--th-font-weight-semibold);
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.02em;
	padding-bottom: 6px;
	border-bottom: 1px solid var(--color-border);
	position: sticky;
	top: 0;
	background: var(--color-main-background);
	z-index: 1;
}

/* Every heading but the first labels a column of radios, so it sits over them. */
.th-policy__grid-head > :not(:first-child) {
	text-align: center;
}

/* v4.8.17 — separators are back, and this reverses the v4.8.3 note that removed
   them ("a rule between each one turned a list into a ledger"). That held for a
   short stacked list. A ledger is exactly what a nine-row grid is, and without
   the rules the eye loses which radio belongs to which setting halfway across. */
.th-policy__grid-row {
	padding: 5px 0;
	border-bottom: 1px solid var(--color-border);
}

.th-policy__grid-row:last-child {
	border-bottom: none;
}

.th-policy__grid-name {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding-inline-end: 8px;
}

/* The field's name. A plain span since v4.8.17 rather than a checkbox label, so
   it carries its own weight — it is what the row's radiogroup is labelled by. */
.th-policy__field-name {
	font-weight: var(--th-font-weight-semibold);
}

.th-policy__grid-cell {
	display: flex;
	justify-content: center;
}

/* The one option with no column of its own — "Restrict to the ticked items",
   which no "Always on / Always off" heading describes. It spans both of those
   columns and keeps its label visible. */
.th-policy__grid-cell--wide {
	grid-column: 3 / -1;
	justify-content: start;
}

/* Greyed out, not hidden.
   The dimming is on the CONTROLS only, never the whole row: the note underneath
   is the reason they are unavailable, and that is the one thing that has to stay
   readable. Dimming it too would drop the explanation below the contrast floor
   exactly when the reader needs it. */
.th-policy__grid-row--blocked .th-policy__grid-cell {
	opacity: 0.55;
	cursor: not-allowed;
}

/* Notes and value controls are extra lines in the row's own grid. */
.th-policy__grid-note,
.th-policy__grid-body {
	grid-column: 1 / -1;
}

.th-policy__grid-note {
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
	max-width: 70ch;
	margin: 0;
}

/* The reason a control is unavailable. Full contrast and an icon, so it does
   not read as ordinary help text the eye skips. */
.th-policy__grid-note--reason {
	display: flex;
	align-items: center;
	gap: 4px;
	color: var(--color-main-text);
}

.th-policy__grid-body {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 4px 0 2px;
}

/* The column headings carry the words, so each radio's own label is hidden from
   sight while staying in the accessibility tree — a bare circle in a labelled
   column is the whole point of a table view, and an unlabelled radio would not
   be.

   `:deep()`, because the span belongs to NcCheckboxRadioSwitch and scoped CSS
   attaches its attribute to the last compound selector only.

   **`checkbox-content__text` is @nextcloud/vue's own class**, read off the
   built component in 9.x rather than assumed — SKILLS.md § NC component
   uncertainty. If a future release renames it this rule stops matching and the
   labels simply become visible again: the layout goes back to being wordy, not
   broken. That is the failure mode this was chosen for.

   Not applied to `--wide`, whose label is the only thing naming its option. */
.th-policy__grid-cell:not(.th-policy__grid-cell--wide) :deep(.checkbox-content__text) {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0 0 0 0);
	white-space: nowrap;
	border: 0;
}

/* Narrow screens: the columns cannot survive, so the table becomes a stack and
   every label comes back — WCAG 1.4.10 reflow. Hidden labels over collapsed
   columns would leave a column of unexplained circles. */
@media (max-width: 680px) {
	.th-policy__grid-head {
		display: none;
	}

	.th-policy__grid-row {
		grid-template-columns: 1fr;
		gap: 2px;
		padding: 10px 0;
	}

	.th-policy__grid-cell,
	.th-policy__grid-cell--wide {
		grid-column: 1 / -1;
		justify-content: start;
	}

	.th-policy__grid-cell :deep(.checkbox-content__text) {
		position: static;
		width: auto;
		height: auto;
		margin: 0;
		overflow: visible;
		clip: auto;
		white-space: normal;
	}
}

.th-policy__checks {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
}

.th-policy__legend {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
	align-items: start;
	margin: 8px 0 0;
	font-size: var(--th-font-meta);
	color: var(--color-text-maxcontrast);
	max-width: 70ch;
}

.th-policy__legend dd {
	margin: 0;
}

/* The tag carries a border and its own text, never colour alone. */
.th-policy__tag {
	font-size: var(--th-font-micro);
	font-weight: var(--th-font-weight-medium);
	padding: 1px 8px;
	border-radius: var(--th-radius-pill);
	border: 1px solid var(--color-border-dark);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.th-policy__tag--enforced {
	border-color: var(--color-success-text);
	color: var(--color-success-text);
}

.th-policy__tag--asserted {
	border-color: var(--color-warning-text);
	color: var(--color-warning-text);
}
</style>
