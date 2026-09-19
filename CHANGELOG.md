## [4.10.0] — 2026-09-15

### What's new

**New feature - OpenProject integration** (licensed)
We build upon the official OpenProject integration to add OpenProject support in our app. In this version you can: 
- You can enable/disable the module as a Nextcloud admin.
- You can select the OpenProject template from the create team wizard. 
- You can Create a team & project from this wizard or create a team and connect an existing project.
- OpenProject news, meetings and work packages are shown in the team Dashboard.
- We show a project info widget with all relevant project info.  
- You can create a new work package from the upcomming tasks widget. 
- Never miss an OP news item or work package due date anymore as they show in 'What's new' and 'My Work'.    

**Fixes**
Fixed issue https://github.com/JustinDoek/Nextcloud-Teamhub/issues/101 where opening a file also directly opened the chat and thus crowding Talk with new chats. The chat tab now shows but you now have to click to participate in the chat. 
Fixed issue https://github.com/JustinDoek/Nextcloud-Teamhub/issues/100 where we accidentaly hardcoded a oc_ prefix in the database table name.
