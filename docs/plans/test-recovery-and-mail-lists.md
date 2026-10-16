# Reliable test delivery, visible status and email reuse

## Outcome and scope

Make the user's existing workflow reliable: select a communication type, set an age filter without selecting optional teams/disciplines/memberships, write both language versions, and send a test. A preliminary failure must preserve an editable draft and explain what happened immediately and after reload. Truly uncertain remote writes and member sends must remain protected.

Also add copying an existing email into a fresh draft and move sent/scheduled communications out of Drafts into clearly labelled lists. This document is an implementation plan; it does not authorize or perform production database changes or publication.

## Evidence and current limitations

Production 0.3.24, draft #10, 16 October 2026:

- 11:19:30 Europe/Copenhagen: revision 3 audience estimate succeeded with 39 recipients; `preview.intent` followed.
- 11:19:56: `preview.failed` and an HTTP 500 were recorded. Later actions returned 409.
- The dump has state `uncertain`, mailing ID 0, `mailing_attempted=1`, filter 786624 reserved, and no history row for #10. There is no release intent.
- `Workflow::preview()` checks all three optional tag catalogues even for empty selections, then writes the prepared history, then calls provider preparation, then sends the Joomla tests.
- Its broad catch uses only whether `$mailing` was assigned to choose `draft` versus `uncertain`. This treats preliminary read/render failures as uncertain remote writes.
- Missing history places this observed failure before successful history preparation, consistent with failure during tag lookup. The exact exception is absent; a CleverReach lookup failure/timeout is a hypothesis, not a confirmed diagnosis. Missing history or mailing ID alone must not become a general automatic-unlock rule.
- API errors normally omit refreshed draft state, leaving the browser unaware of the lock until reload. Audit entries omit the operation stage and safe error category.
- `#ic-status` appears after the step panels with subdued styling. A generic read-only notice does not distinguish a sent message from a failed preparation.
- The sidebar's Drafts query includes every non-deleted state and lists only ID/state, capped at 30 rows.

Relevant code: `Service/Workflow.php`, `Infrastructure/CleverReachGateway.php`, `Service/TestDelivery.php`, `Service/History.php`, `Service/Reconciliation.php`, `site/src/Controller/ApiController.php`, `site/tmpl/composer/default.php`, and `media/js/app.js` / `media/css/app.css`.

## 1. Separate safe failures from uncertain outcomes

Introduce a durable operation record with operation ID, draft/revision, lease generation, operation kind, stage, timestamps and a sanitized outcome. Pin the connection/account/list identity needed for recovery. Use a migration and retain operational evidence while unresolved.

Record stages before crossing boundaries: local/read-only validation, provider preparation write intent, confirmed provider preparation with mailing ID, Danish test, English test, and completion. Provider preparation itself must distinguish read-only checks, filter writes and mailing writes; one catch around the entire method is insufficient. Persist a returned mailing ID immediately before any further fallible work. A durable write intent may remain uncertain if the process dies before recording confirmation.

Use short transactions to claim and finalize operations. Guard every update with operation ID, revision and lease generation so stale responses cannot overwrite newer work. Serialize competing test/send/recovery/copy-related source reads as appropriate; do not hold database transactions over network calls.

Failure handling:

| Failure point | Result and allowed next step |
| --- | --- |
| Validation, tag lookup or rendering before any remote mutation | Editable draft; invalidate test approval; explain failure; allow manual test retry. |
| Confirmed rejected remote write | Return to editable draft only when the adapter can establish the outcome; otherwise retain review state. |
| Filter update outcome unclear | Require audience verification before another operation; do not describe this as a possibly sent member mailing. |
| Mailing creation/update outcome unclear | Keep reservation and block blind preparation retry; offer administrator investigation with the recorded operation reference. |
| Provider preparation confirmed; Joomla test fails | Keep known mailing ID/reservation, invalidate approval, return to editable draft and allow explicit test retry. |
| Test transport outcome unclear or only one language accepted | State which language outcomes are known; do not claim receipt or both tests succeeded; permit an explicit test-only retry with a duplicate-test warning where appropriate. Never retry automatically. |
| Member submission outcome unclear | Preserve existing protection and reconciliation; no automatic resend or unlock. |

Do not use `mailing_attempted` as the sole record of whether a remote write could have occurred. Retain its conservative legacy meaning until operation evidence safely supports changing it. Maintenance must apply the same stage-aware rules to interrupted operations instead of turning every stale test into generic uncertainty.

For optional tag validation, skip lookups for empty selections. When selections exist, fetch the catalogue once per operation and validate the selected origins against it. Keep all type-required group, audience access and server-side policy checks. Age-only targeting must remain valid for types/roles that allow it, without silently widening recipients or requiring a team.

Add bounded diagnostic metadata: stage, operation ID, safe error category, HTTP status/cURL error code where applicable, exception class and source location. Exclude raw provider responses, tokens, mail content, recipient addresses and raw exception messages.

## 2. Recover #10 and equivalent historical cases

Provide an administrator-only, POST/CSRF-protected recovery assessment with a concrete explanation and recorded evidence. Do not run an unconditional `uncertain -> draft` migration.

For legacy cases, validate the installed workflow/version and complete available history/audit evidence. For #10, verify the absence of prepared history and any release attempt against that workflow's ordering; confirm no later attempt or active operation exists. If historical evidence is incomplete or contradictory, require provider/operator investigation rather than assuming ID 0 means nothing exists remotely.

After a positively established pre-write failure, recheck ownership, connection/list, revision, filter and generation atomically; restore an editable draft, clear stale test approval and increment the operation generation. Conservatively invalidate the audience estimate and require fresh verification before testing. Keep the existing managed reservation only if its ownership is intact; do not release/reassign a potentially used filter. Record who recovered it, why, and the evidence used. Repeated recovery must be idempotent.

The ordinary abandonment flow cannot resolve #10 as-is because it requires a known mailing and prepared history. Keep that contract separate from pre-write recovery.

## 3. Make status visible and actionable

Place a persistent, prominent status panel directly below the page heading, before the step navigation. Show the email subject/ID, current state, latest operation result/time, and the next available action. Repeat the current test result beside Send test. Keep the result visible after reload and when moving between steps.

Use text and icons with high contrast, not colour alone. Announce progress politely and failures through an accessible alert; move focus to actionable errors when needed. On small screens, avoid sticky panels that cover editor controls. Routine save/preview/estimate messages must not overwrite a test failure or uncertain-send warning.

Examples of intended language (translate into Danish and English):

- **Ready for test** — Your changes are saved. The current estimate is 39 recipients. Test emails go only to your account address.
- **Preparing test…** — Checking recipients and preparing the email. Disable duplicate actions while running; do not invent percentages.
- **Test could not be prepared** — We could not check the recipient data. No test email was sent. Your email is saved and can be edited. **Try test again**.
- **Both tests accepted by the mail service** — Check the Danish and English messages in your inbox before confirming. This does not confirm inbox delivery.
- **Only the Danish test was accepted** — The English test failed. Check your inbox; retrying may create another Danish test. Member sending remains blocked.
- **Preparation needs administrator review** — We could not confirm whether CleverReach created the mailing. Your email is saved. Show an operation reference and a permission-appropriate next step.
- **Scheduled for [localized date/time and timezone]** / **Submitted; completion pending** / **Sent; completion confirmed**.

Show the actual signed-in test recipient near the button. Explain disabled actions in place. Replace the generic read-only notice with state-specific explanations; preserve access to the message and safe Copy to new draft action.

Return a stable machine-readable error category, permitted authoritative draft/operation status and available actions on relevant API errors. On network loss or invalid responses, use a permission-checked read-only status refresh before enabling actions. Refreshing status must never repeat the failed operation. Reload must reconstruct status from server evidence, including persisted test outcomes.

## 4. Copy an existing email

Add **Copy to new draft** to the opened email and its list row, including sent/scheduled and review-needed emails the user may read. Make this a server-side action with ownership/access checks, POST and CSRF protection; copying does not cancel or resolve the source.

Copy editable content: both subjects/bodies, sender display name, communication type and audience criteria. Create a new owner-scoped draft with a new ID/revision and source provenance. Do not copy provider/filter IDs, reservations, tests, approvals, estimates, send dates, delivery state, archive tasks or acceptance proofs. Use current mode, type definitions, footer and design.

Revalidate all copied targeting against current type/tag availability and permissions. If anything is unavailable, show exactly what requires review and block estimation/testing/sending until resolved; never silently remove restrictions or broaden recipients. Preserve the source and any unresolved reservation. Show **Copied from #N — review recipients and send a new test**. Add request deduplication so a double click does not create two copies. Ensure browser draft-cache recovery cannot substitute the source or another draft's content.

Copying a sent email uses its preserved message content/frozen history where available, not a newly rendered approximation. If retention removed the source content, explain that copying is unavailable. Existing permissions remain unchanged; this does not introduce cross-user browsing.

## 5. Separate Drafts from Sent / Scheduled

Replace the collapsed mixed sidebar list with visible list navigation, keeping New email prominent:

| View | Included records |
| --- | --- |
| Drafts | Editable drafts and successfully tested drafts. |
| Sent / Scheduled | Scheduled, submitted/awaiting completion, and provider-confirmed completed communications, each with its precise badge. |
| Needs attention | Uncertain operations and operations in progress that need a status view; distinguish normal progress from stalled/review-required work. |
| Cancelled / Deleted | Cancelled/abandoned records and the existing deleted/restorable view with retention guidance. |

Use one server-side classification shared by queries, counts and row badges, covering every existing and newly introduced state. Preserve access to all non-deleted messages; never hide uncertainty inside Sent. Normal in-progress operations should show “In progress”, without implying failure. Lists update after successful submission/scheduling without requiring a page reload.

Rows show subject, ID, clear state, audience summary/count with its meaning, and the relevant localized saved/scheduled/submitted/completion time. Use frozen historical estimates for sent items and never invent delivered-recipient counts. Include Open and Copy actions. Paginate and provide subject/ID search and useful status filtering, replacing the current silent 30-item cutoff. Scope all queries/counts/actions to the user's authorized records. Moving views is classification only; never delete, resend or change provider state.

## 6. Verification and delivery order

1. Implement operation evidence, phase-aware failure handling and sanitized diagnostics, with a guarded recovery path for #10. Add focused service/gateway tests for each mutation boundary and interruption point.
2. Implement authoritative status responses and the persistent status UI. Verify the user's exact type + age-only + content + Send test flow, including failures and reload.
3. Implement safe copying and the separated lists using shared server-side status semantics.
4. Run the repository's isolated CI, upgrade/database-maintenance checks and authenticated HTTP coverage. Validate desktop/mobile and Danish/English UI, keyboard focus, live regions, message persistence and cache interactions.
5. Document operator recovery and write version-specific release notes. Prepare a reviewable release through the existing release workflow when implementation is requested. Production recovery is a separate explicit operator action using a current backup and reviewed assessment.

Required regression cases include empty optional selections, required-team policies, selected inaccessible/stale tags, failed catalogue GETs, local rendering/history failure, filter write ambiguity, lost mailing POST response, known mailing followed by SMTP failure, partial bilingual delivery, interrupted operations, concurrent/stale replies, unauthorized status/copy/recovery requests, copy permission changes and retention, all list states and pagination, and uncertain member sends remaining protected. Use synthetic fixtures only; production data and live sends do not enter CI.

Acceptance: the exact age-only flow either produces two accepted test messages or a prominent accurate explanation; a proven preliminary failure remains editable across reload; #10 has an evidence-based recovery route; copying creates an independent untested draft; and submitted/scheduled/completed emails no longer appear under Drafts.

## Implementation record

Implemented in the workspace with SQL migration `0.3.25.sql`; no production changes or publication were performed. Durable stage records protect remote writes and persist bilingual transport results. Recipient preflight failures also retain a revision-scoped audit reference and clear old test approval. Legacy recovery is deliberately limited to complete 0.3.24 pre-history evidence with explicit administrator version attestation; unknown remote writes still require investigation. Copying blocks when current targeting is unavailable, with an explicit explanation and no partial copy.

Full isolated CI passed, including public 0.3.11 and synthetic upgrades, database maintenance, scheduler and HTTP checks, 157 PHP unit tests, 24 frontend tests and the new synthetic recovery/mailbox regression workflow. English browser checks verified persistent failure guidance, copying from a submitted email, the age-only simulated test flow, and no horizontal overflow at 390px. See the [operator guide](../test-recovery.md) and [release notes](../releases/0.3.25.md).
