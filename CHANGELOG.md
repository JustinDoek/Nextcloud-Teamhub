## Changelog 

**If you are going to update to Nextcloud 35 test this release in a test environment first. Not all apps we use support Nextcloud 35 yet** 

### What changed

**Nextcloud 35 support added**
Teams with team folders are automatically converted to team spaces. 
Registered TeamHub in the teams interface. 

**New feature - Service teams** (licensed)
We added a service team template and services catalog. The service catalog acts as a front-end where you requests services for all Service teams. Service teams can create their own service proces with the service builder and publish those in the catalog.  

**Changes**
**Links** Changed all links for docs and website to load our business website at https://teamhub.doekworks.eu 

**My work** We are adding services and workflows to the app that depend on 'My Work'. This was licensed before but is now available to all. What you see depends on the license state of the source. 

**Adopting teams** Teamhub is a swiss knife for fitting as many use-cases into the app related to team based work. We allow various degrees of control. From everyone can create a team to locked down environments. The last one wasn't possible as we adopted teams created in NC teams and collectives. From this update on a Nextcloud admin needs to approve a team from outside of TeamHub to be visible in TeamHub. This can be done on the import/export tab. The only exception is when there is no group that can create teams it is still free for all. 

**User search** You can now set the fields that show up when you search for users from TeamHub interfaces. This prevents you from selecting the wrong person with the same name as the intended person. 

**Fixes**
Fixed issue https://github.com/JustinDoek/Nextcloud-Teamhub/issues/103 where a group wouldn't properly update in the team. 
Fixed issue https://github.com/JustinDoek/Nextcloud-Teamhub/issues/97 about team creation. (see changes)
Fixed an issue where unified searches would lead to the teamhub app, but not deeplinking to the search item. 
Fixed some licensing info and links onr the license tab. 