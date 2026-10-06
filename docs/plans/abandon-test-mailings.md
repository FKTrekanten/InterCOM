# Abandon test-only mailings from the administrator UI

## Outcome

An InterCOM administrator can abandon an unwanted, prepared test mailing and release its filter reservation after verifying that the corresponding CleverReach mailing can no longer use the filter. No newsletter is sent as part of abandonment. Deleted drafts remain deleted, and history remains distinguishable from completed delivery.

The production-dump examples are draft 2 / mailing 17459483 / filter 783416 and draft 3 / mailing 17459494 / filter 783415 on list 758666. Both are deleted with prepared history and no recorded release event. Filter 783417 is already available and needs no action. These IDs are regression examples, never implementation constants.

## 1. Establish the provider retirement contract

Inspect the supported CleverReach retirement mechanism before implementing a destructive gateway call. The published v3 specification documents GET and PUT for `/mailings/{id}`, but does not document DELETE there. Do not invent an endpoint or treat local draft deletion as provider retirement.

Verify with a disposable, unsent provider fixture whether deletion is permanent or moves the mailing to recoverable trash, how scheduled/running mailings are distinguished, and what evidence establishes that a retired mailing cannot reference the filter again. A 404 by itself is insufficient: wrong account, missing read scope and recoverable deletion must not authorize reuse.

Use an automated retirement action only if the provider contract supports it. Otherwise provide explicit instructions to retire the exact mailing in CleverReach followed by **Verify retirement and release reservation**. If permanent retirement cannot be established, retain the reservation with an actionable explanation. This provider contract is a prerequisite for enabling successful abandonment, not a permission bypass.

Reference: https://rest.cleverreach.com/v3/explorer/swagger.json

## 2. Add a clear per-row UI

On **InterCOM → Filters**, display the associated CleverReach mailing ID, a concise reservation explanation and an administrator-only **Abandon test mailing** action for locally eligible draft/tested/cancelled/deleted records with prepared mailings. Never offer it for submitted, scheduled, releasing, uncertain or completed sends, historical/unmanaged filters, or available rows.

Use a dedicated confirmation page to avoid nested forms in the existing filter/search form. Show draft, filter, list, mailing and provider status, and explain that abandonment prevents further use of this prepared mailing. If provider retirement is manual, show the required CleverReach step and its verification result. Provide a reason field and an explicit confirmation. Show an exact blocking reason when eligibility or verification fails.

Use **Abandoned; reservation released** for success. Keep **Completion verified; filter reusable** exclusively for actual completed sends. Provide Danish and English translations. Share status labels with dashboard filter summaries, but keep destructive actions on the dedicated Filters page.

## 3. Implement a guarded service and POST action

Add a dedicated abandonment service, provider-verification adapter and controller action. Reuse the existing administrator, management and audit permission requirements; require POST and CSRF. Do not trust eligibility rendered by the browser.

Before any remote mutation, lock and validate the pinned account/current live list, managed filter, owning draft, revision and reservation generation. Check prepared history and audit evidence; reject any send attempt or uncertain preparation/release. Validate exact provider identity, mailing, static audience/list/filter relationship and genuinely unscheduled, unsent status. Local `send_at=0`, local absence of release events or remote `finished=0` alone is insufficient.

Persist a bounded abandonment operation before provider work. Its claim must prevent concurrent test, restore, release, maintenance reuse and account/list changes. Use short database transactions around claiming and committing; do not hold a database transaction across network calls. Apply bounded provider timeouts.

Only after provider retirement is positively established, lock and recheck the same account/list/draft/filter/generation, then atomically release the lease and invalidate the draft's test and audience approval. Preserve a deleted state; mark an active abandoned draft cancelled. Keep original operational IDs and the operator's reason in protected history/audit. Detach active provider pointers so a later restoration starts with a fresh filter, mailing and test. Never label abandonment as a completed send or enqueue an archive copy.

For acceptance-test drafts, retire any incomplete proof consistently so preparation of a replacement is possible. Retain verified historical proofs. Abandonment itself must neither grant acceptance nor invalidate an unrelated currently valid proof.

## 4. Handle failures and recovery

Timeouts, permission failures, malformed responses, account/list mismatches and unclear retirement results keep the reservation. Persist the operation and show **Retirement unverified; reservation retained** with a safe next step. An interrupted operation can recheck evidence without automatically repeating a destructive request. Repeated verification is idempotent; a stale operation must never release a filter acquired by another draft.

Keep normal completed-mailing reconciliation read-only. Recovery uses an explicit administrator retry; scheduled maintenance must never initiate abandonment, automatically release its claims or loosen uncertain-send protections. A failed initial read-only inspection restores the original draft state without releasing its reservation, allowing normal reconciliation to continue.

## 5. Test the complete behaviour

- Native Joomla tests using synthetic equivalents of both production examples: deleted/test-only drafts remain blocked until verified retirement, then become available; an already available filter remains unchanged.
- Test tested/cancelled/deleted drafts, restored drafts, incomplete acceptance tests and unrelated valid acceptance.
- Reject scheduled/running/submitted/uncertain mailings, automation/dynamic audiences, wrong account/list/filter, insufficient scope, recoverable deletion, arbitrary 404, provider errors and timeouts.
- Test interruption after remote retirement but before local commit, safe verification recovery, double submission, concurrent send/restore and stale reservation generations.
- Authenticated HTTP tests for confirmation, authorization, POST/CSRF, translated messages, row status and reservation release. Use provider fixtures; CI must not modify production CleverReach records or send member mail.
- Run full isolated CI, installation and upgrade checks. No production repair runs during implementation or testing.

## 6. Document and release

Update the reconciliation/acceptance operator guides with abandonment steps and the provider retirement requirements. If durable operation storage requires a migration, add and test it through Joomla's Database maintenance checker. Record the change in written release notes and all four Joomla extension changelogs when releasing. Implementation, commit and release are authorized; use the normal verified release workflow.

## Implementation decision

The published API has no documented mailing deletion or trash/permanent-retirement contract. The implemented fallback combines a verified unsent baseline, explicit administrator attestation of permanent removal, individual-mailing absence and successful bounded catalogue checks. API absence alone cannot prove permanence. The user can delete drafts and reports no visible restore function; the UI still requires confirmation that the exact mailing cannot be restored or sent. See [the operator guide](../abandonment.md) for verification limits, including the locally recorded filter relationship and the single current history snapshot.

Acceptance criterion: after an administrator completes verified abandonment for drafts 2 and 3, filters 783415 and 783416 are available, history still shows abandonment rather than delivery, and a list change succeeds provided no other reservations or creation requests remain. A successful list change still requires fresh delivery acceptance.
