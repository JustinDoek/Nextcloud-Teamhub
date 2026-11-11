## [4.20.0] — 2026-11-11

This is a long-term support release.

### Shifts that follow you

- **Add my shifts to my calendar** puts your published shifts for the week into a Nextcloud calendar you choose, as busy or free. Run it again and the same events update. Come off a shift and it leaves your calendar.
- Nobody can be put on two shifts at the same time any more, across all your teams. The staffing list says *Already working at this time*.
- You get a notification when a week you are on is published or withdrawn, when you are added or taken off a shift, and when somebody takes over a shift you offered.
- The staffing list puts whoever has worked least first, with their hours or weekend shifts beside their name, so a fair choice is the obvious one.
- *My shift statistics* counts your shifts in all your teams, with a per team breakdown.
- The weekly shift pattern now lives only with the team. Administrators keep defining the shift types; the separate instance-wide pattern is gone.

### Teams across servers

- A team owned by another Nextcloud server can now appear in TeamHub, with its members and the server that owns it. Your administrator turns this on; it is off by default.
- Member counts include people on other servers, and changing their role or removing them now reaches their own server.

### Licensing

- Request a quote, request a trial key or contact us directly from the License tab. Every request falls back to a prefilled email if the licensing service cannot be reached.

### Smaller things

- An empty half-day in My workplace now reads *Not working* instead of telling you something is missing.
- Polls on the team dashboard are sized like the widgets around them, and avatars have one size everywhere.
- Some People and workplace labels showed in English for every language. They are translated now.
- Refused actions now say they were refused, instead of looking like a mistake in what you typed.

## [4.14.0] — 2026-11-02

### Write your team's week down once

- A team admin can now build **roster templates** in Manage team, Module settings: one week, and on each day the role you need somebody for and the shift it runs on. Two receptionists on the early shift and a barista beside them is one template, not three screens.
- Draft a new week from a template on the Roster tab and every place already says what it is for, so the only thing left is putting people in it. An empty week offers the button.
- Editing a template never changes a week you have already drafted or published from it, so you can correct next month without rewriting last week.
- Your Nextcloud administrator still defines the shifts themselves (Early, 07:00 to 15:00) for the whole server; the week built out of them belongs to the team.
- A shift roster can now hold two different roles on the same shift and the same day. Until now the second one was refused as a duplicate.

### Inviting several people at once

- Pick as many colleagues as you want from one search: the list stays open and each name becomes a chip, so inviting five people is five clicks rather than five searches.
- Inviting more than one person used to add the first and refuse the rest. All of them are invited now.
- The confirmation says what actually happened. A batch where somebody could not be added no longer reports everybody as invited, and the dialog stays open with just that person so you can try again.

### Administration settings

- The first tab is now called **Getting started**, and the switch for Nextcloud's own Teams app has moved onto it from Policy. It is one of the first things you decide when setting TeamHub up, not a standing rule you revisit.

### Smaller things

- Settings, Personal, TeamHub now lines up with the administration pages instead of starting under Nextcloud's menu button.
- Adding a meeting with a room no longer promises a RoomVox booking when the room comes from the Calendar Resource Management app. It now says what will actually happen to it.

### Nextcloud versions

- TeamHub now supports **Nextcloud 34 and 35**. Nextcloud 33 is no longer supported.

## [4.13.57] — 2026-10-31

### Formatting buttons for the welcome message
- The Welcome page message now has buttons for heading, bold, italic, bullet list, numbered list and link, so you can format it without knowing markdown. Select some text and press one, or press one and type.

### Numbered lists work in messages
- A numbered list in a team message is now drawn as a numbered list. The button has always been in the toolbar, but what it wrote came out as plain text with the numbers in it. Lists your teams already typed will start rendering properly.

## [4.13.56] — 2026-10-31

### Every team has team admins
- A team no longer talks about an owner. The person who holds the team is shown as a team admin, like the others, and the Team info card lists all of them instead of naming one.
- Manage team, Transfer ownership is now Hand over the team, and it says what happens: the person you choose takes over deleting the team and handing it over, and you become a regular member of it.
- Handing a team over used to leave you a moderator while the screen promised something else. You now become a plain member, and the Manage team screen closes as it should instead of staying open on a team you no longer administer.
- Leaving the Approvers field of a decision category empty now means every team admin can finalize decisions in it, rather than one person.

### A welcome message you write yourself
- Administration settings, TeamHub, Team creation has a new Welcome page message. What you write there is shown under Welcome to TeamHub on the TeamHub start page, to everybody on the server.
- It takes the same markdown as a team message, so you can point people at your intranet or a policy with a link.
- Leave it empty and the standard line is shown.

### Messages to administrators are harder to miss
- A TeamHub message addressed to Nextcloud administrators now opens in front of you instead of waiting behind the envelope in the sidebar. Messages to everybody are unchanged.
- You read it to the end before it can be closed, and Do not show this message again becomes available once you reach the bottom. Closing it without ticking that box brings it back at your next sign in.
- Each message is tied to one TeamHub version, so dismissing one never hides the next.

### Setting up templates and policies
- Editing a team template, a policy profile or a default home layout now fills the Policy tab instead of opening a dialog, with a back control that returns you to the list.
- Changing a template's home layout no longer opens a second dialog on top of the first. It is a page of its own, and going back lands you on the template you were editing.

## [4.13.52] — 2026-10-30

### Your collaboration profile is on your Nextcloud profile
- What you fill in under Personal settings, TeamHub, My collaboration profile now appears on your Nextcloud profile page, where colleagues already look for you.
- It shows only the fields you filled in, and only the ones you chose to share. Nothing new is published.
- **It no longer depends on teams.** Before, you and the person looking had to share a department team with People switched on, which on an upgraded server was never true, so nobody ever saw it. Now it needs only the People module, which your administrator switches on once for the whole server.
- You still have to be signed in to see somebody's profile details.
- The separate Collaboration profile link has gone from the profile page, because the details are on the page itself. The direct address still works if you have it.

## [4.13.51] — 2026-10-30

### Things that were not working
- Decision categories are visible again under Manage team. The list drew an empty outline for each category and showed none of their names, descriptions or approvers.
- The team calendar's week view works for ordinary members again. It had been admitting only Nextcloud administrators.
- The groups list under Manage team shows its group icon instead of an empty circle.
- Declining a directory group from the admin screen now declines the group you picked.
- Changing a team description records the description it replaced, so the audit trail shows what changed.
- A calendar event with no title no longer breaks the team timeline.
- Saving an unrecognised value for what happens to a team whose directory group disappeared no longer fails. It falls back to archiving, which is what the setting already documented.

### Reading a meeting suggestion
- The availability line under each suggested half-day and time, such as 2 of 2 available, is no longer bold. It reads as the detail under the heading rather than a second heading.

## [4.13.50] — 2026-10-30

### Presence is now People
- The Presence module is called People. Your schedules, status types, rooms and holidays carry on exactly as they were.
- People is on for new teams. Every team that had it switched off keeps it switched off, and a team administrator turns it on in Manage team, Modules and integrations.

### My collaboration profile
- Your personal TeamHub settings now hold a collaboration profile: your department, your usual workplace, what you can help with, what to ask you about, your languages, how you prefer to be contacted, and a fun fact.
- Colleagues who share a department with you can open it from your Nextcloud profile, under Collaboration profile.
- You choose what is shared. Birthday, work anniversary and hobbies each have their own switch.
- Your account date and the date you joined a department are filled in for you and cannot be edited, because they come from Nextcloud and from your team membership. Where neither is known, the profile says Not available rather than guessing.

### Birthdays and work anniversaries
- A department team can show a Birthdays card with the next three birthdays. It shows the day and the month, never the year and never an age.
- On your birthday, TeamHub greets you once. Being in several departments still means one greeting.
- Work anniversaries mark 1, 5, 10 and 25 years of membership of a department. A team administrator can switch the announcements off under People.
- Birthdays come from your Nextcloud account. If you set your date of birth to private there, nothing in TeamHub shows it.

### Time off and where you work
- Book vacation, sickness or a non working day for a morning, an afternoon or a run of up to 120 days at once.
- Restore usual workplace puts any day back to your normal weekly pattern, including the room you usually sit in.
- A public holiday your administrator set is left alone inside a range, and TeamHub tells you how many days it skipped.
- Colleagues see only that you are unavailable. They are not shown the reason.
- Changing the room on a day you had already marked as office now saves on its own, without setting the day to home and back again.
- Time off in TeamHub is for TeamHub. It does not create calendar entries and does not change your Nextcloud absence setting, so set that separately when you need it.

### Finding a time that suits everybody
- Meeting suggestions now read availability from Nextcloud Calendar instead of guessing from presence alone.
- Time off you booked in TeamHub rules out a slot even when your calendar looks free.
- Where availability cannot be worked out, TeamHub says so rather than treating the person as free.

### The team's home layout
- Publishing a layout for your team now locks it by default, so everybody sees the arrangement you published. Unlock it from the edit banner when you want members to arrange their own again.
- Members can always reorder their own tabs, including on a locked team.
- Save as my type default now applies to your other teams of the same template only. A project team's cards can no longer turn up on a collaboration team.
- Reset to template puts a home back to the layout your administrator set for that kind of team.
- Deleting a team now clears its saved layouts, including those of its sub-teams.

### Also
- Integration cards show a general icon on mobile instead of a blank space.
- Creating a team now opens its home page straight away. The completion screen is still shown when a step needs your attention.

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


### The team's home page
- A team's home now arranges itself around the widgets the team actually has. Switch the message stream off, or hide a few cards, and the rest fill the space instead of leaving a column of empty page.
- Widgets that show a list all open the same height, tall enough for five rows. Members, Upcoming tasks, Calendar and the rest no longer open with a scrollbar on a team of four people.
- Your own arrangement is still yours: drag or resize a card once and that is what you see from then on.
- Reset to the default layout now really resets. Before, it froze the layout as it was at that moment, so a later change to your default never reached that team again.

### Setting the layout for other people
- Arrange a team's home the way you like it, then save that arrangement as the starting point for one team, for every team of a kind, or for every team on the server. The layout menu in edit mode offers whichever of those you are allowed to set.
- Each kind of team can have its own home. A service desk, a project and a department no longer have to open the same way.
- A team admin can suggest their team's layout, apply it, or enforce it. Suggested, members are offered it and can ignore it; applied, it is what they see until they rearrange that team; enforced, it is what everyone sees.
- Enforcing a layout never throws away what somebody arranged. Their own home is kept and comes back if you stop enforcing.
- Setting or changing one of these layouts reaches everybody straight away, including people who had already arranged their home. Rearrange a team afterwards and your own arrangement wins again. Nothing has to be rolled out.
- Your Nextcloud admin sets the server-wide and per-template layouts under Settings, in a list: the order the cards appear in and how much of a row each one takes. Every team arranges them around the widgets it actually has.

### Apps that plug into TeamHub
- A widget another app adds to a team's home now sits in the dashboard properly: you can drag it, resize it and arrange it with every other card.
- A tab another app adds to a team now appears in the tab bar and stays there, instead of disappearing as the page finished loading.
- An app can now fill a widget with its own design — a chart, a board, a small form — rather than only a list of rows.
- A widget or tab another app adds also shows up on phones and tablets, and in the Default tab picker.

### Team templates
- Your Nextcloud administrator can create team templates of their own. Until now the five that come with TeamHub were the only kinds of team available.
- A template you created can be deleted. Teams already created from it keep every app, module and setting they were created with.
- Which integrations a kind of team takes part in is now set where the rest of the template is set, under Policy → Team templates → Edit, instead of on a separate tab — as a row of checkboxes beside the template's apps and modules.
- A template you create is offered straight away when somebody makes a team, and the templates appear in the order you put them in.
