# Changelog

## 0.3.21

- Fix low-contrast selected text in the composer’s training-group, discipline and membership controls. Selected chips explicitly use white text on the existing navy background, including highlighted/hovered selections. Joomla’s remove buttons retain their own transparent styling and inherit the readable chip text color.
- Translate the Danish discipline heading as **Discipliner** and use consistent discipline terminology in composer guidance, validation messages and audience-access help.

Validation: a browser preview using Joomla Cassiopeia’s actual Choices styles confirmed white text and remove icons on navy chips for all three controls, including a highlighted discipline chip. The color pair has approximately 15.5:1 contrast. PHP syntax and Danish/English language-key parity checks passed. This change affects presentation and translations only; audience rules, stored tags and permissions retain their existing behavior.

## 0.3.20

- Match age and gender through one exact-tag rule containing `member.<gender>_<age>` combinations. Both choices must match the same member, rather than the mailbox owner's receiver birthdate/gender attributes. Missing age bounds use 0 and 120; missing gender includes female, male and other. With neither choice, no member-tag rule is emitted. Age/gender validation limits are unchanged.
- Add separate discipline choices beside training groups and memberships in the composer, recipient catalogue and audience access. The owner-approved matching rule is **any selected training group AND any selected discipline**; values within each choice match either. For example, adult groups plus sabre and foil match mailboxes in any chosen adult group with either discipline. Membership, suppression and controlled-acceptance email rules retain their existing behavior.
- Preserve the visibility, order, language labels and audience-access grants of `group.epee`, `group.foil` and `group.sabre` by migrating them to `discipline.epee`, `discipline.foil` and `discipline.sabre` across all saved list catalogues. Reusable drafts and browser recovery move these choices into the discipline selection without dropping them. An existing mixed training-group/discipline audience now requires both choices, as approved by the owner.
- Explain that age/gender selections reach households with a matching member, including guardians who pay from another address. Summaries, history and board archives show readable criteria such as ages 8-12 and women, never the generated member-tag list. The internal `member.*` family remains excluded from recipient administration, composer choices and audience-access grants.
- Require a new estimate and send test for drafts with age, gender or discipline choices. The upgrade clears their cached audience rules, fingerprints, counts and approvals and increments their revision. Deleted/cancelled reusable drafts retain their state and require review and a new test when restored. Sent/scheduled/uncertain or in-progress operations, frozen historical revisions and archive evidence retain their original audience; upgrading does not send mail or release filter reservations.

**Rollout dependency:** do not publish or install this release until Sixte's matching release is deployed, its sync has completed, and the new `member.*` and `discipline.epee` / `discipline.foil` / `discipline.sabre` tags are confirmed on production Members list **609312**. Install InterCOM immediately after that confirmation: the new rules match nobody before Sixte's sync, while old discipline rules match nobody after the old group tags disappear. Refresh **Recipient tags** after installation and review migrated visibility, labels, grants and saved audiences before testing them again. InterCOM only reads tags and never changes receivers.

The development list **758666** does not receive Sixte's production sync. To exercise provider filters, the operator must add suitable member and discipline tags to its approved test receiver by hand. Do not use production member data or credentials in automated CI. Production tag readiness was verified directly on list 609312 on 8 October 2026 after Sixte's sync: all 313 mailboxes carried member tags, all three new discipline tags were present on receivers, and the three old group discipline tags were absent.

This release has an idempotent installer data migration, with no SQL schema change. Joomla's database version remains **0.3.18**. Existing connection credentials, filter reservations and sent-mail history are preserved. Resolve in-progress/uncertain unsent operations through the existing recovery procedure and review any recovered legacy discipline audience before reusing it.

Validation: PHP unit tests cover age only, gender only, combined choices, both open-ended ranges, neither choice, exact tag matching, discipline-only and combined training-group/discipline rules, migrated references, readable summaries and the complete 363-tag / 5,840-character provider read-back rule. Browser tests cover recovery of legacy discipline choices. Disposable Joomla coverage verifies catalogue settings and grants across all list contexts, affected test invalidation, repeat migration, historical preservation, supported tag-family refresh and member-family exclusion. Full local repository CI passed, including 147 PHP unit tests, 21 frontend tests, authenticated HTTP and permission checks, scheduler execution, database maintenance, fresh installation, and synthetic and checksum-verified public-package upgrades. The public-package upgrade also verifies migration of a saved discipline/age audience, catalogue labels and access grants. No live receiver, provider filter or mailing was changed by these checks. A separate pre-deployment live check passed all 12 audience cases against production list 609312, comparing the actual active recipient sets and counts with independent tag evaluation and verifying saved-rule read-back, including the full 363-tag condition. Adult groups AND either foil or sabre matched 7 active mailboxes. The temporary test segment was removed; no receiver or mailing was changed and no email was sent. See [production validation](https://github.com/FKTrekanten/InterCOM/blob/main/docs/audience-targeting-validation.md). Publication, installation and received-email acceptance remain separate steps.

## 0.3.19

- Fix **Inspect and begin abandonment** incorrectly reporting **Retirement unverified; reservation retained** for eligible unsent mailings. CleverReach returns single-state mailing catalogues wrapped by state, such as `{"draft": [...]}`; InterCOM now parses that response correctly.
- Fix the same response mismatch in retirement verification across the draft, waiting, running, automation and finished catalogues. Validate the exact requested state and every page; wrong-state, mixed-state and malformed results retain the reservation. Existing identity, audience, timeout and pagination limits remain enforced, and flat catalogue responses remain supported.
- Add regression coverage using the observed provider response shape, including wrapped pagination, a verified empty final page, wrong-state/malformed responses and flat-list compatibility.

After upgrading from 0.3.18, reopen **InterCOM → Filters → Abandon test mailing** and retry **Inspect and begin abandonment**. Keep the exact mailing in CleverReach until **Unsent draft verified** appears. Then permanently remove that mailing in CleverReach, keep the segment, confirm that the mailing cannot be restored or sent, and choose **Verify retirement and release reservation**. A failed initial inspection has retained the reservation and restored the original draft state; the parser fix allows a fresh inspection.

This release has no database migration. Joomla's database version remains **0.3.18** while the extension manifest becomes **0.3.19**. Existing settings, encrypted credentials, drafts, reservations, abandonment records, acceptance and scheduled tasks are preserved. Upgrading alone does not delete a mailing or release a reservation. Permanent removal still requires administrator confirmation alongside successful provider absence checks.

Validation includes full local CI, 140 PHP unit tests, 20 frontend tests, native Joomla abandonment and interruption recovery, authenticated HTTP confirmation and permission checks, scheduler execution, database maintenance, fresh installation, and synthetic and checksum-verified public-package upgrades. A read-only check against the actual CleverReach draft-catalogue response reproduced the 0.3.18 failure and passed with the corrected parser. No email was sent and no provider mailing, segment or production reservation was modified.

## 0.3.18

- Add an administrator-only **Abandon test mailing** flow on **InterCOM → Filters**, displaying the exact CleverReach mailing ID and requiring a reason and explicit confirmation.
- Verify the current account, list and segment, matching prepared history and the exact static, unsent provider draft before showing manual deletion instructions. Scheduled, active, submitted, uncertain and completed mailings remain protected.
- Require confirmation of permanent removal in CleverReach, an absent individual mailing and successful checks of the draft, waiting, running, automation and finished catalogues before releasing the reservation. Missing permissions, timeouts, incomplete results and identity mismatches retain it.
- Preserve durable abandonment claims across interrupted verification, block concurrent editing, testing, sending and restoration, and protect account/list changes. Failed initial inspections restore the original draft state while retaining its reservation; repeated successful verification cannot release a reused filter.
- Preserve deleted drafts as deleted and cancel other abandoned drafts. Clear their operational provider references and test approval, requiring a fresh audience, mailing and test after restoration. Label history and filter status as abandonment, with no invented delivery or completion event.
- Include original provider references, requesting/verifying operators, verification time and reason in protected operation records and history details. Expire finished operations' reason text using the configured retention period. Retire incomplete acceptance tests without granting acceptance or changing unrelated valid approval.
- Add Danish and English labels, an operator guide, migration `0.3.18.sql`, provider-contract tests, native Joomla lifecycle and recovery checks, and authenticated HTTP confirmation, permission and history checks.

After upgrading, keep the original live CleverReach account and list selected. Open **InterCOM → Filters → Abandon test mailing**, check the exact IDs, enter a reason and choose **Inspect and begin abandonment**. Only after **Unsent draft verified** appears, delete that exact mailing in CleverReach, empty any trash if present and confirm that it cannot be restored or sent. Keep the segment itself. Return to InterCOM, confirm permanent removal and choose **Verify retirement and release reservation**. Begin the InterCOM inspection before deleting the provider mailing; a missing record cannot establish the required unsent baseline.

CleverReach's published API has no documented mailing DELETE endpoint or permanent-deletion/trash contract. This flow makes read-only provider requests and relies on the administrator's explicit permanent-removal confirmation alongside the absence checks. API absence alone does not prove permanence. If restoration remains possible or unclear, retain the reservation. Catalogue checks are bounded to ten pages of 100 records per state and a cooperative 40-second provider budget; reaching a bound retains the reservation. The provider response identifies the mailing's list; its filter association is established from matching managed local draft/history references and a successful read of that exact filter.

Use **Continue abandonment** after a failed or interrupted verification. Scheduled maintenance does not initiate abandonment or automatically release its claims. Completed member mailings continue to use **Reconcile with CleverReach**. Once all other live reservations and unresolved creation requests are clear, changing the recipient list remains possible and requires fresh delivery acceptance for the new configuration. A list can contain the full membership: acceptance restricts its test segment to the approved address.

This release adds the durable abandonment-operation table. Joomla's database and manifest versions become **0.3.18**. Existing settings, encrypted credentials, communications, permissions, drafts, scheduled tasks and unrelated verified acceptance are preserved. Upgrading alone does not abandon or release any production reservation.

Validation includes full local CI, 130 PHP unit tests, 20 frontend tests, native Joomla abandonment and concurrent-action guards, provider failure/interruption recovery, acceptance preservation, authenticated HTTP forms and permission checks, scheduler execution, fresh installation, database maintenance, and synthetic and checksum-verified public-package upgrades. Automated checks use disposable installations and provider/mail fixtures; no live member mailing is sent or production provider record modified.

## 0.3.17

- Fix the literal `%s` in Joomla's fallback error after a rejected InterCOM Options save. Preserve the specific validation or reservation error, with Danish and English translations.
- Restore the saved Options values after a rejected save instead of displaying simulation mode and blank sender or approved-recipient fields. The rejected save preserves stored settings, the delivery-acceptance fingerprint, verified acceptance and live reservations.
- Explain that active CleverReach reservations block account, delivery-mode and recipient-list changes, and direct administrators to **InterCOM → Filters → Reconcile with CleverReach**.
- Document how to check completed mailings and reserved segments before switching lists, why test-only mailings remain protected, and how to renew acceptance using one approved recipient inside the new full recipient list.
- Add native Joomla and authenticated HTTP regression checks for rejected saves, persisted settings, acceptance preservation, correct form values after redirect/reload, translated fallback errors and isolation from other components' form sessions.

After upgrading, rejected Options saves reload the last saved values. If an earlier failed save left an already-open editor showing blank/default fields, click **Cancel** and reopen Options before making further changes. The release fixes the form display and error handling; it does not bypass live-reservation protections or add a force-release action.

A successful recipient-list change still requires delivery acceptance for the new configuration. Keep your full recipient list selected, ensure the **Approved test recipient** is active and eligible on that list, and prepare a test in **Delivery acceptance**. InterCOM restricts its acceptance segment to that exact address. Select **Send to one approved recipient**, inspect the delivery and confirm acceptance. Preparation previews sent to the logged-in administrator do not establish acceptance. Existing acceptance tests and verification history are retained.

There are no database migrations. Joomla's database version remains **0.3.8** while the extension manifest becomes **0.3.17**. Existing settings, credentials, permissions, drafts and scheduled tasks are preserved.

Validation includes full local CI, 108 PHP unit tests, 20 frontend tests, native Joomla rejected-save and acceptance checks, authenticated HTTP form checks, installation, scheduler execution, database maintenance, and synthetic and checksum-verified public-package upgrades. Automated checks use disposable installations and provider/mail fixtures; no live member mailing is sent.

## 0.3.16

- Fix scheduled maintenance failures escaping Joomla's scheduler: catch `Throwable` during component/runtime initialization and separately around retention/recovery, filter reconciliation and archive delivery. A failing phase no longer prevents the remaining phases from running within the available time budget.
- Keep failures visible with Joomla's normal `KNOCKOUT` status while allowing the scheduler to advance the next execution and release the task lock. Successful runs return `OK`. A broken task logger also cannot prevent cleanup.
- Add a cooperative 60-second maintenance budget shared across phases. Keep reconciliation's 20/40-second limits, add a 20-second archive budget, and cap archive SMTP connection and command waits at 10 seconds or the remaining budget. Work that has not started remains available for later runs.
- Preserve transaction rollback and the existing protection against automatically retrying uncertain member mailings or archive sends. Failure warnings include the phase, exception class and source file/line, without exposing raw exception messages, recipient addresses, SQL or credentials.
- Add native Joomla queue regression tests for initialization errors, a `TypeError` in each maintenance phase, ordinary exceptions and a broken logger. Each verifies failure status, lock release, advanced execution and successful execution of the following task. Add budget, SMTP-limit and uncertain-send recovery checks to CI.

Existing scheduled tasks, intervals and plugin settings are preserved; no task recreation or database migration is required. Joomla's database version remains **0.3.8** while the extension manifest becomes **0.3.16**. This release does not change delivery acceptance or authorize a member mailing.

The time budgets are cooperative: they prevent starting additional phases or rows after the deadline, while an in-flight database, provider or SMTP operation can finish later. Guards cover catchable PHP errors and exceptions; a process termination or memory exhaustion still requires Joomla's stale-lock recovery. SMTP limits apply to the optional board archive transport.

Validation includes full local CI, PHP unit and frontend tests, native Joomla scheduler failure recovery, secret-free failure warnings, cooperative budget exhaustion, actual archive transport timeout configuration, uncertain SMTP outcomes without automatic retry, installation, database maintenance, and synthetic and checksum-verified public-package upgrades. All failure injection runs in disposable installations with synthetic data and provider/mail fixtures.

## 0.3.15

- Restore configured legacy Joomla editors, including JCE, in both composer languages. Empty user editor preferences now use Joomla's global editor, and saved HTML is safely encoded when reopening an editor.
- Read live content from modern and legacy editor APIs when saving, testing, inserting first names and recovering drafts. TinyMCE retains the existing email-formatting controls.
- Keep the exact submitted editor values as the baseline after saving or testing. A later editor polling tick no longer invalidates content that was already tested; subsequent edits still require another successful test.
- Explain send-confirmation blockers beside the checkbox, including outdated delivery acceptance, unavailable or empty recipient estimates, and changed content or audience. Handle recipient counts returned as strings and keep confirmation disabled while recipient checks run.
- Label historical acceptance verified for earlier settings clearly and explain how to renew it after changes to email design or delivery settings. A successful composer test does not renew delivery acceptance.
- Remove the duplicate heading inside **Your drafts**.
- Add Joomla-native XML changelogs for the package, component and bundled plugins, with links in the extension manifests and update feed. The release process generates these from the written release notes, retains published history and verifies their public URLs before publication.

After upgrading, if sending is blocked because the email design or delivery settings changed after acceptance, open **InterCOM → Delivery acceptance**, prepare a new one-recipient test, select **Send to one approved recipient**, inspect the received email and select **Confirm delivery acceptance**. Then reload the composer. Existing newsletter drafts remain available, and sending still requires a positive recipient estimate and a successful test of the current content.

There are no database migrations in this release. Joomla's database version can remain **0.3.8** while the manifest version becomes **0.3.15**. Existing settings, encrypted credentials, communications, permissions and drafts are preserved.

Validation includes full local CI, 105 PHP unit tests, 20 frontend interaction tests, native Joomla legacy-editor and TinyMCE rendering, saved-HTML round trips, acceptance invalidation after an English-brand change, native changelog parsing and extension-manager rendering, authenticated HTTP guards, scheduled maintenance, and synthetic and public-package upgrade checks. Automated checks use disposable installations and provider fixtures; no live member mailing is sent.

## 0.3.14

- Fix the false **Maintenance → Database** warning that `intercom_tags` does not have a column named `IF`. Joomla's checker misread `ADD COLUMN IF NOT EXISTS` in migration `0.3.1.sql`; the migration now uses the supported `ADD COLUMN` syntax for the actual `labels` column.
- Fix the persistent warning after **Update Structure**, which previously reran the statement without satisfying the incorrectly parsed check.
- Add native Database maintenance and Update Structure checks to CI for fresh installations, synthetic migrations and upgrades from the verified public package. These checks also verify that repair preserves tags, communications, drafts, encrypted credentials, settings and permissions.
- Document the requirement to write and review a version-specific changelog for every release.

After upgrading, Joomla's Database maintenance should report **No problems** for InterCOM. The database version can remain **0.3.8**, the latest SQL migration, while the manifest version is **0.3.14**. These versions track different things; no artificial migration-version bump is needed.

Validation includes full local CI, the native Joomla maintenance model and both upgrade paths. Existing data and permission rules are preserved.

## 0.3.13

# InterCOM 0.3.13

- Preserve **Inherited** when saving communication-group permissions. Empty inherited selections no longer become explicit **Denied** rules that lock descendant user groups despite an **Allowed** selection.
- Fix the live calculated-settings display and stuck spinner for new and existing communication groups. Calculations include descendant groups and preserve Joomla's explicit-denial and Super User behavior.
- Preview permission selections without saving them. **Save changes** applies the permissions through the existing audited, revision-checked save.

Existing permission rules are preserved during upgrades. After updating, open each affected communication group and review **Public** and the target user's other ancestor Joomla groups. Change unintended **Denied** settings back to **Inherited**, retain **Allowed** for the intended user group, and select **Save changes**. Intentional denials still override child grants; component-wide Options permissions remain prerequisites.

Validation includes full browser-form submissions against native Joomla, permission inheritance and deliberate-denial regressions, read-only previews, CSRF and restricted-role checks, stale revisions, spinner/error handling, and upgrades from the checksum-verified public package.

## 0.3.12

# InterCOM 0.3.12

- Use **InterCOM** consistently in Danish and English component, package, plugin, scheduled-task and branded email labels. Internal extension identifiers remain unchanged.
- Translate the main package name and description so Joomla displays **InterCOM** instead of `PKG_INTERCOM`.
- Include release dates for the package and its three extensions, replacing “Unknown” in Joomla's extension list. Release preparation keeps these dates current.
- Enable the maintenance plugin automatically on fresh installs. Updates preserve its existing enabled/disabled setting.

On existing installations, enable **Task – InterCOM** and create an enabled **InterCOM** task in Joomla Scheduled Tasks, preferably every five minutes with a working trigger. The plugin applies retention, marks interrupted operations for review, reconciles filter reservations and sends optional archive copies. Enabling the plugin also requires configuring a scheduled task.

Validation covers the native Joomla extension list, fresh installation, scheduler dispatch and upgrades from the checksum-verified public 0.3.11 package, preserving drafts, revisions, settings, encrypted credentials, audit history, communication groups and permissions.

## 0.3.11

# Intercom 0.3.11

First public package for Joomla 6.1 and PHP 8.3 or newer. The package installs the native Intercom component, its scheduled-maintenance plugin and its configuration-validation plugin.

- Compose Danish and English member emails with Joomla's editor, themed light/dark previews, first-name insertion, saved drafts and browser recovery. Start a new communication without deleting the current draft; save unfinished content or save it automatically before testing.
- Configure CleverReach lists and list-bound unsubscribe forms, translated communication groups and suppression words, friendly team/membership tags, and group-based audience and communication permissions.
- Use Intercom-managed filters with a configurable pool cap, early recipient estimates and protected reservations for uncertain or unfinished provider operations. Sends require a successful current-content test and server-side delivery acceptance.
- Review the latest five confirmed sends on the dashboard, paginated sent-mail history, frozen message snapshots, audit history and filter reconciliation. Scheduled maintenance handles cleanup, reconciliation and optional archive copies.
- Store credentials in authenticated Sodium encryption derived from Joomla's site secret. Configure email design, sender, address, social/member-profile links and retention; the default audit/content retention is 30 days.
- Clarify all-member audience grants and separate the account-verification button from the OAuth fields in Options.

## Installation and live delivery

Install `pkg_intercom-0.3.11.zip` using Joomla's extension installer. New installations start in simulation. Existing installations retain their settings and encrypted credentials.

Before enabling live member delivery, configure the production account/list, verified sender, unsubscribe form, Joomla mail, roles/audience permissions, tags/suppression, footer and native site languages. Schedule Intercom maintenance, preferably every five minutes with a reliable trigger. Complete **Delivery acceptance** on that installation using exactly one approved recipient, inspect the received email and open its unsubscribe form without confirming an unsubscribe. Dev acceptance does not transfer to production.

Back up the database and Joomla configuration, preserving the site secret. Validate installation/update and restore on staging, check delegated Danish/English workflows, and inspect received light/dark emails in the clients used by members. These are deployment checks; publishing this package does not record their completion on the production site.

## Validation

The release process runs local coding-style, unit, frontend, smoke, native Joomla install/upgrade, ACL/CSRF, scheduler, retention and SMTP-capture checks, then requires GitHub CI for the release commit. The authorized dev CleverReach send completed and its recipient confirmed receipt and the unsubscribe-form destination. Automated CI uses disposable databases and provider fixtures, never a live member send.

## 0.3.10 — 2026-09-30

- Explain all-member audience access, combined/inherited grants and the remaining permission and delivery restrictions in Danish and English.

- Clear the native TinyMCE unsaved-content warning only after the current content is saved successfully, so New communication works after saving or testing. Failed saves and later edits retain navigation protection.

## 0.3.9 — 2026-09-30

- Add a visible New communication link when viewing any saved draft, preserving the existing draft.
- Keep writing available during recipient checks and persist the server draft identity before provider requests. Avoid duplicate count refresh requests.
- Recover unsaved bilingual content from a user/account/list-specific browser cache; clear recovery after saving, deletion or terminal message states.
- Save incomplete drafts and let Send test save and validate current content automatically. Explain read-only messages and required test/acceptance checks.


## 0.3.8 — 2026-09-30

- Enforce administrator one-recipient CleverReach delivery acceptance instead of an editable release checkbox. Recheck eligibility, blacklists, list, sender and unsubscribe form before sending.
- Bind approval to the verified account and current email configuration; require actual receipt confirmation and provider completion. Keep ambiguous operations reserved without automatic retries.
- Add bilingual acceptance screens and secure native POST actions, plus eligibility and native database regression checks.
- Fix the Verify account Options button submission and align acceptance/history language labels.
- Preserve saved content and frozen history for pending live submissions beyond the retention window; purge only completed/terminal content or simulation.


## 0.3.7

Deliver bilingual composer tests through Joomla's native mail service so delegated users do not need CleverReach account access. Add a pinned local Mailpit inbox and real SMTP regression coverage. Keep provider unsubscribe acceptance separate from sample test links, and use native timezone settings for recipient estimate timestamps.

## 0.3.6

Save and estimate recipient audiences when entering Content, with safe mailing-free lease expiry and fresh pre-send count confirmation. Add frozen bilingual sent-mail history with a dedicated permission, pagination and five latest confirmed sends on the dashboard. Preserve older reservations and label reconstructed/expired history. Correct inclusive maximum-age boundaries.

## 0.3.5

Verify CleverReach customer identity before token replacement; allow safe same-account renewal with live reservations. Add legacy identity verification, account-switch cache invalidation and native Joomla regression coverage.

## 0.3.4

- Use translated, readable and capitalized automatic group labels in email previews and HTML/text mailings, preserving raw audience tags.
- Align backend language labels above inputs and reorder recipient tags with drag handles or accessible up/down buttons.
- Reconcile completed static CleverReach mailings before reusing their filters, automatically through scheduled maintenance or manually in the backend.
- Recover uncertain filter creations by their unique remote name; keep missing, ambiguous and failed checks blocked and show their status to administrators.
- Audit reconciliation transitions and protect unresolved creation slots from account/list changes.

## 0.3.3

- Replace the numeric unsubscribe input with a CleverReach list-bound form selector; reload forms when the recipient list changes and preserve existing legacy selections during upgrades or provider outages.
- Use the separate Flow API catalogue and pass new form UUIDs losslessly in the mailing API's existing unsubscribe setting. Validate list/type before preparation and verify the saved mailing selection before preview or release.
- Verify one-recipient dev delivery and the new unsubscribe destination with the recipient. Add contract, selector race/error, permission, settings fingerprint and native upgrade coverage for issue #4. Fix native browser textarea line-break validation in footer Options.

## 0.3.2

- Add audited deletion and restoration of owned drafts. Preserve live reservations and operational IDs, reject active/submitted/scheduled/uncertain messages, clear old test approvals, and purge deleted content under configured retention.
- Add a subtle contrasting email footer with a thin blue divider, social links, club address and contact details while preserving member preferences, recipient information, online view and CleverReach unsubscribe links.
- Configure contact/social details and Danish/English profile URLs in native Joomla Options. Snapshot footer settings into drafts and invalidate previous test approvals when they change.
- Extend footer validation/rendering, draft ownership/state/CSRF/retention, native install/upgrade and HTTP coverage.

## 0.3.1

- Move the single default sender name to Joomla Options beside the sender email, preserving existing draft names and migrating the previous English default.
- Add independent check/uncheck-all controls for team and membership visibility, with partial-selection state and unavailable-tag protection.
- Format tag labels and trailing times, support optional translated display names, and preserve original CleverReach filter/permission identities and labels during refreshes.
- Track same-account manual token renewal blocked by live reservations in issue #5; reservations and credentials remain protected.

## 0.3.0

- Add editable, versioned email design and localised sender defaults in the component backend, with Danish/English light/dark previews and contrast validation.
- Align mail typography with the approved design and restore the two-line club/communication heading, with the logo on the right. Preserve bilingual content and preference links.
- Snapshot email design in drafts; audit changes and require saving/retesting after design changes.
- Update subjects/senders without reloading email HTML; debounce body rendering and reject stale responses. Refresh saved content when Joomla editors finish initialising.
- Match gender styling, toggle recipient dropdowns closed on repeated clicks, disable arbitrary tags, and require a permitted team before advancing Team news.
- Add a dashboard and native Joomla submenus, separate paginated audit/filter pages, scoped pool statistics, audit filters, and backend permission checks.
- Extend frontend timing/interaction, unit, database, native install/upgrade and HTTP tests. Mail-client dark-mode and existing production acceptance work remain open; no public release is published by this change.

## 0.2.0

- Add administrator-managed communication groups with translated names, descriptions, subject prefixes, headings, suppression words, publication/order, categories and native record permissions.
- Migrate existing categories and type grants, preserving explicit denials, drafts, reservations and encrypted credentials. Retain used group identities; reject stale edits.
- Add list-specific recipient tag visibility and structured Joomla-group audience grants. Show one searchable team dropdown and one membership dropdown with server-side validation.
- Restore Joomla rich-text editing, first-name insertion, the branded bilingual email template, safe HTML/plain-text rendering and a matching isolated browser preview.
- Bind tests to current group/tag/template/settings revisions. Changes require review and a new test before release.
- Add an optional separately audited board archive outbox through Joomla mail after provider completion. SMTP uncertainty never triggers automatic resend.
- Extend unit, database, authenticated HTTP, install, upgrade and scheduler checks. Existing production acceptance issues remain open; no public release is published by this change.

## Unreleased

- 0.1.6 development: retire unused manual filter IDs, remove their Options control, and reserve only Intercom-owned filters. Clarify CleverReach preview eligibility and the transition to list-bound forms.

- 0.1.5 development: select the recipient list from CleverReach and create Intercom-owned filters on demand within a configurable cap.

- 0.1.4 development: restrict draft reservations to filter IDs approved in Options and retire unused, unlisted pool IDs when settings are saved. Preserve historical reservations for audit.

- 0.1.3 development: direct signed-out frontend visitors to native Joomla login and preserve their return URL, while retaining permission checks for signed-in users.

- 0.1.2 development: move configuration and connection controls to native Joomla Options, with transactional validation through the bundled extension plugin.
- Add encrypted manual access/refresh token import with explicit lifetime, access-only support and secret-free audit events.
- Paginate audit history with selectable page sizes and native Joomla controls.

- 0.1.1 development: distinguish simulated and live reservations so test mailings cannot block OAuth credential setup. Protect drafts against delivery-mode changes.
- Replace the initial continuous form with the approved three-step composer, communication cards, language controls and branded preview.
- Preserve special characters in OAuth credentials, reject the synthetic fixture on Connect, and guard disposable test data against accidental use on the persistent development site.

- Native Joomla 6.1 component and Scheduled Tasks plugin.
- English and Danish interfaces, group permissions and audience scoping.
- Versioned drafts, test-before-release workflow and audit retention.
- Site-secret-derived authenticated encryption for CleverReach credentials.
- Local MariaDB/Joomla stack, CI and gated release tooling.

This remains a development build. One isolated CleverReach mailing was delivered, but delegated-user previews and automated release preflight remain open.
