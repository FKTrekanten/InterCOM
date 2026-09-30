# Recipient estimates and production readiness

Proposed plan, 30 September 2026. Baseline: Intercom 0.3.4 on main. Progress: #5 is completed in 0.3.5; estimates/history in 0.3.6; delegated Joomla test delivery (#3) in 0.3.7. All three passed local and GitHub CI and dev checks. One-recipient acceptance (#1) is implemented in 0.3.8 and passed local/GitHub CI. Its controlled send positively completed in CleverReach; human receipt/form confirmation and production checks remain pending.

## Intended behaviour

Moving from Recipients to Content saves the selected audience, reserves an owned CleverReach filter, updates its rules and retrieves an estimate. Subjects and bodies can remain empty. This operation creates no CleverReach mailing and sends no email. Content and Test/send show the estimate with its last-check time. The existing explicit save/test/send flow remains.

Returning to Recipients and changing communication type, team tags, membership tags, age or gender makes the estimate stale. Changes to sender, subject, body, language preview or email styling do not require a new recipient count. Audience changes still invalidate any previous send-test approval.

An API failure or full filter pool displays an unavailable/waiting state, never zero. Writing and saving remain possible. Live release requires a current successful count and the existing test/permission/confirmation checks. A confirmed zero blocks live release. Counts are estimates, because membership, opt-outs and delivery exclusions can change before CleverReach actually sends.

## 1. Separate audience validation from message validation

Introduce an audience validator shared by the current full Message validator and a new authenticated POST/CSRF audience endpoint. It validates communication type, tags, memberships, ages and gender without requiring subjects/bodies. Validate ownership, current revision, type permissions, visibility and permitted team scope on the server. Keep the team-selection requirement and all-member permission boundary.

Create or update an incomplete local draft on step 1 -> 2. Preserve any existing body/subject content, use revision conflict checks and record the audience revision. Full content validation remains required when saving a complete message and preparing a test/release. Do not persist preview guidance as invented message content.

Canonicalize the selected raw IDs and compile the rules once. An audience fingerprint includes provider account identity, mode, recipient list, communication/suppression definition, compiled rules and relevant configuration. Display translations are independent of audience identity. Date-derived age rules need a defined evaluation date: if changing the date changes the compiled audience after a test, require a fresh test rather than silently changing the filter.

## 2. Persist filter safety and estimate metadata

Add native install/update migrations for estimate metadata and reservation lifecycle. Store the estimate, checked time, audience fingerprint/version, compiled rules, filter lease generation and provider-operation status. Persist a durable indication that mailing preparation has been attempted before any provider mailing POST.

Distinguish audience-only reservations from mailing-associated or uncertain reservations. Absence of mailing_id is not evidence of safety: a timed-out mailing POST may have succeeded. Treat existing live leased drafts conservatively as potentially mailing-associated; do not infer a new safe state from their missing IDs. Preserve existing credentials, drafts, tests, filters and audit history.

Use account identity from issue #5 so filters and cached estimates cannot be carried across accounts. New installations establish identity when connecting; existing installations need the verified identity-pinning path from that issue.

## 3. Split provider operations

Separate filter rule updates and recipient statistics from mailing creation in CleverReachGateway. Reuse the existing managed pool and cap; do not use unrelated filters. Estimate calculation must use the same compiler and suppression rules as sending.

The dev API has been verified to return total_count, active_count, inactive_count and bounce_count from the filter statistics endpoint. Its count endpoint returned a simple numeric string. Use active matches as the starting estimate, after verifying how bounce and blocklist exclusions relate to those counters; do not subtract overlapping counters without evidence. Validate numeric values and reject malformed responses. Return aggregate counts, never a recipient list, to the browser.

Persist each update intent before the provider request. Use draft/filter locks, revisions and lease generations to prevent overlapping tabs or a late request from updating a newer draft's filter. A stale result must never overwrite current count metadata. Retry failed read-only counts in a bounded way; an uncertain filter creation/update or mailing write needs recovery/review, rather than a blind retry or filter reuse.

The provider abstraction should expose this as an optional audience-estimate capability. Simulation returns explicit simulated counts. A future provider without counts must report unavailable, not pretend to have queried CleverReach.

## 4. Manage audience-only leases

Proposed defaults: cached estimates valid for five minutes; unused audience-only leases expire after thirty minutes, both configurable in Options. Refresh on step transition when stale, on explicit Refresh and before a test or live release. Avoid provider calls for every input character. While someone is actively writing, a bounded authenticated local keepalive can extend the audience-only lease without another CleverReach request.

Deletion/cancellation or expiry can release a lease only when the database proves it is audience-only, no mailing preparation has ever been attempted, and no provider write is running or uncertain. Retain the remote owned filter for pool reuse; do not delete it remotely. Elapsed time only triggers consideration of cleanup and never proves a provider outcome. Expiry invalidates count/lease metadata; an old tab must acquire a current lease before proceeding.

Reservations attached to mailings continue to use issue #2 reconciliation. Submitted, scheduled or uncertain mailings cannot be freed through the new timeout. Historical unresolved reservations remain visible for operator review.

## 5. Add the UI and send checks

Show Estimated recipients and Checked at beside the audience summary in Content and Test/send, with Danish/English translations and accessible loading, stale, unavailable, zero and pool-busy states. Preserve the current mobile layout and disable duplicate transition requests while estimation is running.

Entering Content attempts the estimate but provider downtime does not prevent writing. Returning to Recipients invalidates it immediately when audience choices change. Provide an explicit refresh control and clear feedback when a saved draft needs a new test.

Preparing a test updates/verifies the filter rules and records the tested audience fingerprint. Before release, read the existing filter statistics again and check current account/list/rules/permission identity. A preflight failure occurs before any send request and must not be recorded as an uncertain send. If rules change, require another test; if only the count changes, show the new count and require renewed send confirmation. Never silently expand or alter the approved filter immediately before release. Scheduled delivery still uses an estimate at scheduling time, not a guarantee of the future audience size.

## 6. Add backend sent-mail history

Current storage: the drafts table retains Danish/English subjects and bodies, sender, audience criteria, communication/design/footer snapshots and provider IDs/status. Revisions and audit events are also stored. An optional board archive outbox holds a separate payload when enabled. This is message-level storage, not a stored personalised copy for every recipient. Terminal message content and revisions are subject to the configurable retention period, currently thirty days; active/scheduled/uncertain operational records remain protected where needed.

Replace the dashboard's recent-audit-log panel with the five latest provider-confirmed sent emails, ordered by the recorded actual sending time descending. Show subject, communication type, sender/submitting user, sending time and a link to the read-only message detail. If fewer than five exist, show those available with an appropriate empty state. Drafts, test emails, merely accepted/submitted requests, scheduled future messages and uncertain attempts must not be presented as emails that have gone out. Provider-confirmed sending completion is not proof that every recipient received the email. Keep the dashboard's existing filter usage/statistics; audit logging and the separate Audit history submenu continue.

Add a native component submenu called Sent-mail history and a View all history link from the dashboard panel. List all outbound history with pagination (default twenty rows, matching the existing backend page-size choices), filterable by date range, communication type, actor and sending status. This page can expose scheduled/submitted/uncertain attempts with explicit status filters as well as completed sends; incomplete drafts and test-only messages are outside the sent-mail history. Show subjects, sender, submitting user, submission/scheduled/actual-send/completion timestamps, audience summary and estimated count with its check time. Missing historic counts must display Not recorded, never zero. Distinguish simulation, provider acceptance, scheduled, provider-completed and uncertain results; completed sending is not proof of inbox delivery.

The detail page is read-only, with Danish/English HTML and plain-text views, audience criteria and friendly label snapshots, estimate information, provider references and relevant retained audit events. Render previews in a restricted iframe using example personalisation; prevent scripts, tracking resources and personalised unsubscribe actions from running in the history view. Opening a history record must not make a send or provider mutation request.

Persist an immutable message-level snapshot of the exact component-authored HTML/text prepared for CleverReach, together with its submitted revision, subject/sender values, audience/rule fingerprint, account/list, template version and estimate. The history write must be durable before requesting provider release. Record accepted, scheduled, uncertain and completed outcomes against the same record, including provider start/finish timestamps when verified, with idempotent identifiers so recovery does not duplicate history. Display unknown historic send times as unavailable; do not substitute the latest draft update or reconciliation-check timestamp as the actual send time. Do not expose a Resend action in this scope. History is independent of whether a board archive email address is configured.

For older mailings, use retained content and metadata where available and label reconstructed views accordingly; do not present today's template rendering as an exact historic sent copy. If old content has already expired, show Content expired and the available operational metadata. Do not fetch or reconstruct purged content from CleverReach automatically.

Use a dedicated native Joomla permission for viewing sent-message content, together with backend component access. Grant no additional content access to existing delegated users implicitly. Apply the same checks to the five latest dashboard entries, full paginated history, detail/deep links and HTML/text endpoints; audit metadata access alone should not automatically expose message bodies. All UI strings and dates follow the native Danish/English site/backend language and timezone configuration. The list and detail view must support mobile use.

Apply the existing configurable retention policy to immutable history content as well as draft/revision/archive copies, defaulting to thirty days. Keep the minimal provider/operation identifiers needed for reconciliation, show when content has expired, and do not retain subjects or bodies indefinitely through an alternate history table. Pending/scheduled/uncertain operations follow the protected operational lifecycle. History retention is explicit and tested; changing the retention setting affects scheduled cleanup consistently.

## 7. Verify and deliver

Unit and provider-stub tests cover validation without content, all-member/team restrictions, mixed tag semantics, suppression, age boundaries and calendar changes, counter parsing, zero/unavailable states, cache identity and explicit simulation behaviour.

Native Joomla database/HTTP tests cover first audience-only draft creation, existing-body preservation, revisions, ownership/CSRF, filter cap, overlapping requests/tabs, delayed provider replies, ambiguous writes, lease cleanup while editing, crash recovery, deletion/restoration, migration of old reservations and no automatic retries of uncertain sends. Assert that estimation never invokes mailing creation, preview delivery or release. History tests cover immutable HTML/text snapshots, durable pre-release writes, uncertain outcomes and recovery without duplicate records, older/expired records, pagination/filtering, exactly five latest confirmed sends in correct order with pending/test/uncertain records excluded, permission enforcement on every content route, restricted previews and retention of all content copies.

Use the isolated one-recipient dev list to check known matching and nonmatching selections and compare the displayed result with CleverReach. Validate multiple-team and multiple-membership OR logic combined by AND; the existing tests primarily verify generated rules, so real provider behaviour also needs acceptance. Test opted-out/inactive/bounced/blocklisted states using approved disposable fixtures or provider-confirmed semantics. Do not modify a real member's opt-out state merely to create a fixture.

Verify step transitions, saved-draft reopening, error recovery and mobile/keyboard behaviour in the browser. Verify the Sent-mail history submenu, paginated full history, replacement of the dashboard audit panel by the five latest sent emails, Danish/English message views and expired-content states; opening history must not send an email. Run local CI and GitHub Actions, including PHP 8.3+, native install/update, preserved encrypted credentials/audit and scheduled-task dispatch. Work stays on main. Prepare a release and changelog only after these checks pass.

## Production blockers and acceptance

Installing a reviewed package with live sending disabled is possible once the production environment and backup are checked. The table records completed implementation and the remaining production acceptance evidence.

| Item | Outcome and current status |
| --- | --- |
| #5 — verified same-account token renewal | Renew expired/expanded-scope tokens without dropping reservations. Pin account identity and reject different-account credentials. **Completed and closed:** verified same-account renewal in dev and native CI. |
| #3 — reliable tests for delegated Joomla users | A SoMe manager or coach must receive and inspect a real test through a supported route. **Completed and closed:** Joomla multipart tests for both languages reached local SMTP for delegated users. Real production SMTP/inbox acceptance remains required. |
| #1 — enforced one-recipient acceptance preflight | Enforce an approved isolated test audience with exactly one eligible, approved recipient and valid sender/list/unsubscribe setup. Evidence must not be an unchecked operator checkbox. The restriction applies to acceptance testing. **Implemented and CI/dev-verified:** one real send completed; awaiting human inbox/form confirmation before approval. |
| Count semantics and age boundaries | Confirm exclusions and boolean tag matching. **Implemented:** inclusive maximum-age boundary corrected and birthday boundaries tested; active-match counts are estimates. Tested rules must match remote rules before release. |
| Crash/race/lease handling | Prove an abandoned audience-only draft is reclaimable while any uncertain provider write or potentially created mailing stays protected. **Implemented and CI-verified:** existing reservations remain conservatively protected; safe audience-only leases can be reclaimed. |
| Production environment and scheduler | Confirm actual Joomla/PHP/database versions, required PHP extensions and an install/update on a staging copy. Configure reliable scheduled execution, preferably a server cron invocation, and verify maintenance/cleanup/reconciliation in that environment. |
| Configuration and acceptance | Verify production account/list, tag visibility, suppression fields/words, sender authorization, list-bound unsubscribe flow and profile/footer URLs. Exercise actual SoMe/coach/board roles and denied direct API requests; test native Danish/English menu switching, mobile layout and received Gmail/Outlook light/dark emails. Verify Joomla core audit coverage for group/member permission changes and close any logging gaps. Test Joomla SMTP if board archive copies are enabled. |
| Sent-mail history | Verify frozen message snapshots, the dashboard's five latest confirmed sends, paginated full history, historical status wording, content-view permissions and consistent thirty-day/configurable cleanup across all local message copies. **Implemented and CI/dev-verified:** frozen snapshots, protected history ACL, real completion timestamps and safe content retention; production retention execution still needs its scheduler check. |
| Release and recovery | Back up database and Joomla configuration, preserving the site secret needed to decrypt credentials. Perform staging install/update and a restore rehearsal. The update feed is currently empty: publish the verified GitHub release/package/checksum and update feed, then verify a Joomla update before relying on automatic updates. |

Issues #2 and #4 are completed. New reservations must continue to preserve their safeguards. Future member-system/Postmark integrations and the optional Gmail list display-name enhancement are not dependencies for this release.

Recommended order: #5, audience-estimate and backend history implementation, #3, #1 using the shared count service, then production staging/role/language/email acceptance and the verified release. The estimate feature can be developed with the current dev token; it does not require a new external service.

References:
- https://github.com/FKTrekanten/InterCOM/issues/5
- https://github.com/FKTrekanten/InterCOM/issues/3
- https://github.com/FKTrekanten/InterCOM/issues/1
- https://github.com/FKTrekanten/InterCOM/blob/main/docs/reconciliation.md
- https://rest.cleverreach.com/v3/explorer/swagger.json

## Current production handoff

The dev stack is upgraded to 0.3.8. The controlled acceptance uses the authorized
one-recipient dev list; ordinary sends are disabled until actual receipt is
confirmed in Delivery acceptance. Joomla tests go to local Mailpit, not the
recipient's external inbox. See [acceptance procedure](release-acceptance.md).

Before production installation/release, obtain and verify:

- A backup and demonstrated restore, preserving Joomla's encryption secret.
- Production PHP 8.3+, Sodium/cURL and the agreed MariaDB/InnoDB database;
  production sender/domain and Joomla SMTP with both-language inbox tests.
- Native Danish/English menus and delegated SoMe/coach/board role tests on the
  production configuration, including denied team/all-member operations.
- A configured Joomla scheduler/cron that actually runs catalog refresh, audit
  retention, reservation reconciliation and optional archive delivery.
- A separate one-approved-recipient CleverReach acceptance on production:
  correct headers, member profile links, selected unsubscribe form, HTML/text
  and desktop/mobile dark-mode rendering in real clients. Never unsubscribe
  the test member merely to test that the form opens.
- Reviewed release notes and a clean main branch with exact-commit GitHub CI,
  then run the release script and verify the package/update-feed URL and checksum.
  The public update feed must not be described as ready while no release exists.

CI proves isolated install/upgrade, migrations, native sessions/ACL/CSRF, provider
fixtures, unit/style and SMTP capture. It cannot prove the production environment
or receipt in a real client. No production deployment or public release is claimed.
