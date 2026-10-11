# Changelog

All notable changes to TeamHub are documented in this file.

## [4.12.1] — 2026-10-11

### Introduced many changes to the decisions module
- Show the approver of a category on the category overview.
- Fixed supersede decisions.
- Merged all actions on a decision page in an action menu.
- Added an action to archive a decision.
- Added an action to move a decision to another category.
- Added an option to review a decision.
- Added an option to create a proposal from the file actions menu.
- Added an option to make decisions public so you can link them to users outside the team.

### Fixed creating a task from the upcoming tasks widget and added the option to assign it to others.
- Fixed tasks not reaching My Work on PostgreSQL.

### Revised the team creation tab in the admin settings.
- Fixed the Create team dialog text not showing.
- You can now select if you want to (automatic/manual or don't) accept teams created outside of TeamHub and apply a template/policy.
- You can now select if you want to (automatic/manual or don't) create a team for new groups and apply a template/policy.
- Every decision about what joins TeamHub is recorded in the Compliance audit log, and a group waiting for one appears in the administrators' My Work.

### Security
- The app ships with the latest patched versions of the libraries it bundles, closing every high-severity advisory reported against 4.12.0.
- Updated Vue, DOMPurify and Axios, and the build toolchain behind them. Nothing changes in how the app works.
