# Changelog

All notable changes to TeamHub are documented in this file.

## [4.13.0] — 2026-10-13

### Sub-teams
- A team administrator can now create sub-teams inside their team — a smaller room for a workstream, a client or a project phase, without starting a whole new team.
- Each sub-team has its own name, description, members and files, and appears in the sidebar underneath its parent.
- You choose who can see it: only its own members, or everyone in the main team (who see the name and a padlock, and cannot open it).
- Members come from the main team in one click, and you can invite somebody from outside as a guest.
- A sub-team can have its own template, its own policy and its own expiration date, all set from the Sub-teams tab.
- Every administrator of the main team administers its sub-teams — not only the person who created them.
- Deleting a team deletes its sub-teams with it, and tells you if one cannot go rather than leaving an orphan behind.
- A sub-team stays a sub-team: it cannot be handed to another owner, added to another team, or turned into a service desk.
- Your Nextcloud administrator can switch sub-teams off for the whole instance. When they do, the tab explains why instead of disappearing.

### Team folders on Nextcloud 35
- On Nextcloud 35, a team's folder is no longer converted into a Nextcloud team space. The conversion changed who manages a folder's advanced permissions, so it is paused while that is worked out.
- Teams that already have a team space keep it, with their quota, their files and a working disconnect.
- A new team on Nextcloud 35 now gets an ordinary team folder.

