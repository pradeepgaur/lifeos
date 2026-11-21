# LifeOS — PHP/MySQL edition

A buildless personal workspace for goals, tasks, recurring routines, and habit tracking. Upload the files, complete one setup form, and start using it.

## Install on HostGator / shared hosting

1. In cPanel, create a MySQL database and database user. Assign that user to the database with table privileges (SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, and DROP for setup rollback). Use the full cPanel-prefixed names.
2. Extract `lifeos-hosting.zip` into your chosen web folder, including the hidden `.htaccess` files. Enable HTTPS for the domain in your hosting panel.
3. Visit that folder in your browser. Enter the database name, username, password, and your owner name/email/password. The host defaults to `localhost`, port `3306`; expand Connection settings if your hosting account uses different values.
4. Click **Install & open my workspace**. LifeOS creates the tables, writes `private/config.php`, signs you in, and locks the installer automatically.

No SQL import, Composer, Node.js, build step, cron job, WebSocket service, or SMTP configuration is needed on hosting. The script creates tables within the database; shared hosting normally requires the database itself to be created in cPanel first. The `private` folder must be writable by PHP during installation. Do not grant world-writable permissions. After setup, it only needs to remain readable by PHP unless you plan to change configuration.

Requires PHP 7.0+ syntax/runtime features, PDO MySQL, sessions, and MySQL 5.6+ or compatible MariaDB with InnoDB and utf8mb4. PHP 8.x works too. PHP ZIP is only needed locally to build the distribution, not to run the app. Use a current browser. Apache 2.4-compatible `.htaccess` files are included; other servers must deny HTTP access to `private`, `tests`, and `tools` and serve `index.php` as the directory index.

For XAMPP, open http://localhost/trello_clone/ with Apache and MySQL running. The real database has deliberately not been configured with the test credentials. The install screen is ready for your database and owner account.

## Everyday use

- Responsive layout: phones/tablets use the menu button to open full workspace navigation and a board selector above the page. Board columns swipe sideways inside their panel. On phones, List view becomes stacked task cards, weekly habits become checklist cards, and month calendars become agendas of scheduled days. Full month habit history and weekly time grids scroll within their own panels. Dialog forms stack vertically and remain scrollable. Desktop retains its sidebar and denser layouts. Large font settings remain supported. Phone landscape can scroll vertically when its screen is too short for the workspace.
- **Top-right Settings → Appearance · all boards → Font size** controls every font proportionally and saves automatically to your account. The new default is 112% (roughly 1–2 pixels larger for most interface text); 100% restores the original sizes. Options range from 90% to 140%. Existing installations add the preference automatically on update.
- Refresh restores your last selected sidebar page, board, and Board/List/Calendar layout in the same browser. This preference is saved separately per account and installation. If a remembered board was deleted or your access was removed, LifeOS selects the first board you can still access.
- Add another board using **+ beside YOUR BOARDS** in the sidebar, or **+ Board** on mobile. For an image background, a board owner opens **Settings → Board background**, chooses an image, and clicks **Upload background**. JPEG/PNG/WebP images up to 2 MB are stored in MySQL and served only to board members. Use Remove background to return to the theme. Back up your database before updating an installed copy; upload the new application files while keeping `private/config.php`. The background table is created automatically on the next request; do not reinstall.
- Add tasks inline in any column: type and press Enter. Open a card for descriptions, priorities, due dates, goals, assignments, checklists, and comments. Changes save per item; there is no whole-workspace overwrite.
- Drag cards between columns. **Settings** lets owners rename/delete boards and editors maintain column names, order, and which columns count as Done. Deleting a column first moves its tasks to another column.
- Type goals directly in the top strip. Open a goal to edit its description and priority. Goal percentages are based on linked tasks in columns marked as Done; habit percentages are separate personal measures.
- **Life goals** and **Overview** show compact goal accordions with tasks and routines nested as clickable list items. Small pills show task status/priority or routine times/repeat days; the board appears once in the goal heading instead of repeating on every row. All goals open by default on a fresh page, and multiple goals can stay open together. Collapsing one leaves the others unchanged, and collapsed sections stay closed during live refreshes. Completed tasks are included; routine definitions appear once rather than repeating for each calendar occurrence. Goals without linked items remain visible with a short empty-state message. Daily and weekday routines use compact repeat labels.
- List view has bold, non-actionable board-column heading rows. Calendar combines due-dated tasks with recurring timetable entries. Search, high-priority filtering, My tasks, and My day are backed by the current authenticated workspace.
- Creation timestamps are recorded automatically. Imports without recorded creation dates retain Age unknown.
- All nine themes save to your account and work on other devices.

## Timetable and weekly/monthly habits

Choose **Timetable → Add routine**. Give it a title, start/end time, weekdays, a start date, optional end date, and optional connected goal. Leave **Track as a habit** checked to include it in the habit tracker. Routines belong to your personal timetable, independently of boards, and can optionally link to one of your workspace goals. Only you can view, maintain, or check off your routines.

The Timetable menu offers **Day / Week / Month** calendar buttons, with **Week** selected by default. Day and Week position routine blocks at their scheduled minute with heights proportional to their duration; overlapping entries appear side by side. The grid has all 24 hours, opens near the earliest scheduled routine, and preserves its scroll position during live updates. Month shows routines with start/end times and dated tasks in calendar cells. Previous/Next moves by the selected day, week, or month; Today returns to the current period. Click an entry to edit it. Board tasks only have due dates, so they appear in the all-day row in timed views instead of receiving invented start/end times.

Choose **Habit tracker** to check off your own routines, one checkbox per scheduled day. Switch between Week and Month, use the month picker, or move backward/forward through history. Future checkboxes are disabled in the UI. Your timetable and completion history stay private, including when you collaborate on boards. The progress denominator counts scheduled days through today in the selected period, and excludes future days.

Routine times are local wall-clock values, not timezone-converted meeting invitations. For an overnight routine, create two entries. Editing the recurrence changes the timetable for all dates. Existing check-ins remain recorded and are shown in habit history even if that date is no longer part of the edited recurrence. Deleting a routine deletes its check-ins after confirmation. Turn habit tracking off to keep the routine but hide it from the tracker.

## Friends and family

Open **People**, enter their email, choose a role, and create an invitation link. Copy and share that link yourself. No email is sent. Links expire in seven days, are stored hashed, are bound to the invited email, and can be redeemed once.

A new invitee chooses Create an invited account. Someone who already has an account signs in and accepts the invitation. Public sign-up is disabled. Owners can change roles, revoke links, and remove members.

- Owner: full board control and membership management.
- Editor: tasks, checklists, goals, columns, routines, and comments.
- Reviewer: read and comment on tasks.
- Viewer: read the board.

All roles can manage their own habit check-ins. Permissions are enforced on the server for every request. Removing access takes effect on the next request, including an already-open editor.

## Existing prototype data and backups

Background uploads now optimize larger source photos in the browser (up to 20 MB source) to a smaller JPEG before sending them. Images already below 384 KB retain their original format. The server still validates the received image and applies a 2 MB limit. Background Settings keeps detailed errors visible for PHP upload size/temp-folder failures, database permission/packet problems, and incomplete file updates. Errors include a reference for the hosting PHP error log without exposing connection credentials. Upload all files in the updated ZIP, including `background-client.js` and `private/background-tools.php`, while retaining the installed `private/config.php`.

The page's image policy explicitly allows `blob:` URLs so the browser can decode a selected local JPEG while optimizing it. If an older installation says the browser cannot open a valid JPEG, update `private/bootstrap.php` and reload the page. This does not relax the script policy or make uploaded backgrounds public.

The old `lifeos-v1` browser data is left untouched. After installation, use **Profile → Import browser prototype data** in the same browser/origin to copy it into new private boards. Existing server boards are not changed. Prototype goals were global, so they are copied into each imported board. Prototype assignees become you; imported comments are clearly marked and attributed to the importing account. Importing again creates another copy.

**Profile → Download JSON backup** exports the workspace data visible to your account, including routines and your personal habit check-ins. This JSON export is a portable reference, not an automatic database restore. For full disaster recovery, back up the MySQL database and `private/config.php` using cPanel. Keep backups outside the public web folder. Restoring both preserves accounts, password hashes, permissions, invitations, and every member's check-ins.

## Account and deployment notes

Click your profile to change your name/password or sign out. Password changes invalidate other sessions. There is no email password-reset service in this version; account recovery requires the hosting/database administrator. Keep owner credentials in your password manager.

Sign-in now lasts for a rolling **7 days of inactivity**, including across browser restarts. Authenticated use renews it; saves renew immediately, while background refreshes coalesce renewal writes/cookie updates to once every five minutes. A random HttpOnly/SameSite cookie and a hashed MySQL login record restore authentication if shared hosting cleans PHP's session files early. Only hashed persistent secrets are stored in the database. Logout revokes this browser's persistent credential and its recovered PHP sessions; password changes revoke other browsers. Clearing browser cookies still requires signing in again. Existing logged-in sessions acquire persistent sign-in automatically while they are still valid; otherwise sign in once after updating. No cron or PHP hosting-limit changes are needed. Upload `private/sessions.php` together with the updated bootstrap/schema/API files; the login table is added automatically.

Goals now live in one personal workspace list. Use **Link goals** above a board (also available in Board settings) to choose any number of existing goals for that board. The same goal can connect to several boards. Board tasks can choose from that board's linked goals; the task editor preselects a goal when only one is linked, and quick-add uses that goal too. Everyday tasks can remain unlinked. Creating a goal from a board adds it to the workspace and links it to that board; creating one from Life goals leaves it independent until selected on a board. A goal with linked board tasks must be cleared from those tasks before detaching it from that board.

Life goals and Overview show each goal with its linked tasks across accessible boards and your personal routines. Task completion and 28-day habit consistency are separate; use **Goal achieved** in the goal editor to explicitly mark success. Board members can see attached goals and use them on tasks according to their board role; only the goal's owner edits or deletes the goal. Private goals and personal routines are never exposed by board membership. My Day includes today's routines alongside due-dated tasks across boards. Timetable Day/Week/Month includes all your routines and due-dated tasks from accessible boards; overlaps are displayed side by side with a warning marker.

Schema version 7 automatically preserves old goals and their board links, assigning each to the former board owner. Existing board routines move into that owner's personal timetable; other former members (and users with historical check-ins) receive personal copies, retaining their own check-ins. Those copies do not retain goals owned by another person. Existing similarly named goals are not merged automatically. Deleting a board keeps goals, routines, and habit history. Upload the entire updated package, including `private/workspace-migration.php`, while preserving `private/config.php`.

Configuration contains database credentials and is excluded from the distribution ZIP and Git. Never upload test folders or development utilities to production. `tools/package.php` produces an explicit allowlist archive, so an existing installation's configuration and database credentials cannot accidentally ship.

Schema updates run automatically on the first request when a newer schema is required, using a short database migration lock. Keep the original configuration and back up the database before replacing application files. Do not rerun setup against an installed database or delete its configuration. Setup refuses databases already containing `lifeos_` tables. On initial setup failure, only tables created during that setup attempt are rolled back/removed.

## Performance and concurrency

The client saves individual changes and polls every 12 seconds while the tab is active. Unchanged polling responses use an indexed revision query and do not load the whole workspace. Private boards belonging to unrelated users do not invalidate your workspace. Hidden tabs pause polling; returning to the tab checks again. This is near-live collaboration, not a WebSocket push service.

Indexed board membership, task board/column, assignee/date, goal, comment, and habit-date lookups avoid per-card queries. Shared changes are transactional. Tasks, goals, routines, boards, and columns have versions: stale writes receive a conflict instead of silently overwriting someone else's work. Task dialogs provide Reload latest version; reopen a goal/settings/routine dialog after a conflict to get its newest version. No offline writes are queued: a failed save stays open with an error so you can retry.

The snapshot loads all boards accessible to the account. This is intended for personal/family/small-team workspaces, not an unlimited enterprise archive. Historical habit check-ins are loaded for the signed-in user to support month navigation. Consider pagination and date-window endpoints if the dataset grows very large.

## Development verification

Run `node verify.cjs` for client rendering/recurrence/date/escaping/theme/HTML checks.

Run `node tests/integration.cjs` with local MySQL available. Set `LIFEOS_PHP` if PHP is elsewhere, and optionally `LIFEOS_TEST_DB_USER` / `LIFEOS_TEST_DB_PASSWORD` for a local test user with permission to create a disposable database. The default fixture uses local XAMPP root with an empty password. These defaults are test-only. Tests create a uniquely named `lifeos_test_*` database, run HTTP integration flows, then drop only that database. Test PHP sessions/configuration live inside `tests/.runtime` and are removed afterward.

Verified against PHP 8.2.12 and MariaDB 10.4.32 on XAMPP. Code uses PHP 7.0-compatible syntax and version-aware cookie/JSON handling; a PHP 7 runtime was not available for execution testing. Browser checks covered login, goal/task/checklist saves, list layout, column settings, routine creation, weekly/monthly check-ins, and calendar inclusion. Server tests cover installation lock, CSRF, validation, role boundaries, invitation registration/acceptance, conflicts, revocation, persistence, password/session invalidation, migration, and prototype import.

Build the hosting ZIP locally with `php tools/package.php`.


Board columns can be reordered directly: drag a column header on desktop, or use its grip on touch screens. Click a column name to rename it; Enter saves, Escape cancels, and clicking away saves a nonempty name. Owners/editors can use these controls. Board settings still provides keyboard-accessible ordering controls.

## Updating an existing live installation by FTP

Use the contents of `lifeos-hosting.zip`, rather than copying your entire development folder. Upload/overwrite all packaged files in the existing application directory, including the `private` folder and hidden `.htaccess` files. Preserve the live `private/config.php`; it contains that server's database credentials and installation key and is deliberately excluded from the ZIP. Do not replace the database, import SQL, or rerun the installer. After the upload finishes, open the application. Pending schema upgrades run automatically against the existing database and preserve its records. Back up that database before upgrading. Versioned asset URLs load the new styles and scripts.

For a fresh domain/installation, upload the same package to an empty application directory, create an empty MySQL database and assign its user through hosting/cPanel, then open the site and complete setup once. The installer creates the latest schema directly, including shared workspace goals, board goal links, personal routines, habit history, and persistent-login tables. Domain-specific database configuration is generated during setup; no local credentials are shipped.

## Overview dashboard and timed tasks

Overview now summarizes goal age, current task completion, explicit goal achievement, habit consistency, scheduled hours, and completed-time estimates. Choose Last 7 days, Last 28 days, or This month for time and habit metrics. Each goal shows its own allocation, routines, and next unfinished tasks. The dashboard includes daily effort bars, overdue tasks, upcoming activities, and per-habit consistency. Its calculation cheat sheet explains period boundaries, all-day exclusions, shared-board scope, overlapping allocations, and historical recalculation. These are estimates based on scheduled durations and current completion/check-in data, not stopwatch measurements.

Tasks optionally accept a start and end time alongside their due date. Supply both times, with end after start on the same day; leave both blank for all-day events. Day/Week calendars place timed tasks and routines together using duration and overlap lanes. Month calendars display the time range. Schema version 8 adds nullable task-time fields automatically after an FTP update; existing tasks remain all-day. Fresh installations create these fields directly. No installer rerun or SQL import is required for an existing site. Include `overview.js` and `overview.css` when uploading the complete package.

## Insights charts

Insights is a separate sidebar/mobile-menu page; Overview remains available. Filter by goal and Last 7 days, Last 28 days, Last 12 weeks, or This month. Charts include daily habit consistency, weekly allocated time split into completed estimates and remaining scheduled time, a goal-allocation donut with scheduled/completed measures, and a daily habit heatmap. Goal pulse cards show current task progress, goal age, and the latest dated creation/comment/check-in signal. A goal at least 14 days old with linked work and no signal in the last 14 days needs attention. This does not imply a recorded task-completion date. Calculation notes and daily data are available below the charts. Charts use local SVG/CSS, with no external chart libraries or additional database migration. Upload insights.js and insights.css as part of the full hosting ZIP.
