# CleverReach filter reconciliation (0.3.4)

Issue: https://github.com/FKTrekanten/InterCOM/issues/2
API reference: https://rest.cleverreach.com/v3/explorer/swagger.json

## Contract

The service uses read-only GETs for mailing, list filters and individual filters. Completion requires the expected mailing ID, exactly the configured recipient list, `is_mailing=true`, `is_campaign=false`, `is_dynamic=false`, and positive, ordered `started`/`finished` timestamps no later than the current time. This uses explicit provider completion, not elapsed time. The dev API returned this shape on 2026-09-30. Malformed, missing, recurring/dynamic, scheduled and failed results remain blocked. A completed mailing also requires a matching filter ID from the selected list before removing its local lease.

Current owned filters are eligible; unrelated or historical unmanaged filters are never adopted or overwritten. Historical IDs stay on the draft. Successful submitted/scheduled/uncertain records become completed; deleted and cancelled records retain their state. The connection lock and locked current draft/filter prevent a stale result from freeing a new owner's lease. A newly acquired lease clears its predecessor's check metadata.

Uncertain creation recovery matches the original unique name. Exactly one unowned filter with a valid ID is adopted into the pool and the creation becomes created. Absent, duplicate or already-owned matches do not free the cap slot. A stale pending request becomes uncertain, but age never proves absence or completion. No uncertain POST is retried. Filter rule updates preserve the original name. A delayed successful create response is idempotent if recovery already adopted the same ID.

## Operations and tests

Joomla's Intercom maintenance task and the administrator-only, POST/CSRF protected Filters action use the same service. Checks are bounded by time and row count, prioritizing the oldest check. Last status and UTC check time appear on Filters; all state transitions are audited without credentials or raw provider responses. Unresolved creation slots also protect account, mode and list changes.

Local CI includes PHP 8.3, unit status/label cases, actual Joomla install and migration, stubbed terminal/pending/missing/provider-error/duplicate recovery cases, idempotency and acquisition by a newer draft. Dev browser checks cover saved tag ordering, actual drag and drop, keyboard movement, phone width and friendly labels in the native composer preview.

On 2026-09-30, the native dev action reconciled mailing 17445219 on list 758666: provider-confirmed completion released owned filter 783289, retained historical draft 5 as completed, and recorded the transition. No provider mailing or recipient was modified and no email was sent by reconciliation.
