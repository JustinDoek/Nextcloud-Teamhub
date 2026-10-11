<?php
declare(strict_types=1);

namespace OCA\TeamHub\Workflow;

/**
 * Which My Work source chip a definition's requests belong under
 * (v4.11.41). Optional.
 *
 * ## What this revises
 *
 * v4.10.18 gave a workflow no source at all, on the reasoning that *"a
 * workflow belongs to no source: it is a request travelling between people,
 * not a Deck card or a Decision"* — so any source chip hid every request and
 * *All* was the only place to find one. Justin, 2026-10-10, walking the
 * directory-group task: *on the my work page we have a few views that filter
 * tasks. One of those views is teams. We can add this type of action to the
 * teams view.* Asked how far that went, he chose **every workflow gets a
 * home**.
 *
 * The 4.10.18 reasoning was half right. A *request* travels between people,
 * but it is still about something — a team, a quota, a group — and the
 * person filtering for team work means to find it. What it is not is a
 * *provider*: nothing aggregates it, and the source chips are built from
 * provider ids (`SourceGroup::MEMBERS`). So a definition names a **group**,
 * never a provider id, and the client matches rows to the active chip on
 * that name.
 *
 * ## Who returns what
 *
 *   - The two system-started definitions — the team adoption and the
 *     directory group — are `SourceGroup::ADMINISTRATION`. They are a
 *     Nextcloud administrator's instance-wide housekeeping, which is what
 *     that group is for in its own words, and they sit beside the expiry and
 *     team-space admin providers already there. Justin, 2026-10-09: *in the
 *     my work page under administration just like we do with expiry dates.*
 *   - The team, quota and expiry requests are `SourceGroup::TEAMS`: each is
 *     about one team, asked by somebody who administers it.
 *   - The two generic service definitions return `''`. A catalogue service
 *     or one a team built can be about anything, so no group is honest —
 *     and they already have a home that cuts across the sources, the
 *     **Services** chip (v4.11.5), which catches every request a desk
 *     handles. `''` means *All and Services only*, which is where they were.
 *
 * A definition that does not implement this interface is treated as `''`.
 */
interface IWorkflowDefinitionSource {

    /**
     * A `SourceGroup::*` constant, or `''` for no source chip.
     *
     * A provider id is **not** valid here: a workflow is not aggregated by
     * a provider, and a chip for a single provider lists that provider's
     * rows only.
     */
    public function sourceGroup(): string;
}
