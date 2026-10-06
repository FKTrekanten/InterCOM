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

## Operator steps before switching recipient lists

Keep InterCOM connected to the original account and recipient list while checking reservations. In Joomla, open **InterCOM → Filters**, use the current scope to find reserved filters, and note their draft IDs, states and last reconciliation results. Locate the associated mailing through InterCOM's draft/history details and match it to the same mailing in CleverReach; checking a different newsletter is not completion evidence.

In CleverReach, inspect the corresponding email and its report under **Reports & Analytics**. Confirm the intended mailing has finished sending to the original list. For a scheduled or active mailing, retain the reservation while it is pending or running. If a mailing was never intended to be sent, leave it unsent; do not send a newsletter solely to clear a reservation.

Open the original recipient list's **Segments** tab and check the InterCOM filter/segment still exists. CleverReach documentation calls these segments; the API and InterCOM use filter IDs. Do not delete, recreate, rename or repurpose a reserved segment as a workaround: a missing or mismatched filter is retained, and an unresolved creation is recovered only by its original unique name.

Return to Joomla and click **InterCOM → Filters → Reconcile with CleverReach**. This performs read-only provider checks, releases only eligible owned filters whose static mailing has verifiably completed on the configured list, and recovers uniquely matched creation requests. It does not send email, cancel a mailing or offer a force-unlock. Retry changing the list only when the blocking reservations and unresolved creation requests have cleared.

If a reservation remains, use its result rather than guessing:

- **Waiting for mailing completion**: the provider has not reported completion; wait for an intended mailing to finish and reconcile again.
- **Provider check failed**: check the account connection and permissions, then retry reconciliation.
- **Remote record not found**, **Provider identity or list mismatch**, **Ambiguous filter match**, or **Completion cannot be verified**: match the exact account, list, mailing and segment before further action. Deleting records or clearing database flags does not establish safety.
- **Draft/test-only mailing**: the current reconciliation service does not release a tested draft merely because its test emails arrived or because the draft was deleted. A prepared, unsent mailing has no completed-send evidence. These cases need a separate operator review and safe local reservation-release procedure; the current UI has no force-release action.
- **Unsent audience estimate only**: maintenance can release expired draft/cancelled/deleted leases when no mailing preparation was attempted. The lease must expire; an open composer can renew it.
- **Historical/unmanaged reservation**: automated reconciliation handles owned filters on the currently configured list. Historical or unmanaged reservations may require separate review.

Changing the list invalidates delivery acceptance. After a permitted change, refresh the list's recipient catalogue and establish fresh one-recipient delivery acceptance before member sends.

A successful list change changes the delivery-acceptance fingerprint; it does not delete historical proofs or acceptance tests. A rejected save rolls back the settings and must preserve the existing fingerprint and valid acceptance.

The new recipient list does not need to contain only one person. Add/activate the approved test address in that same list, set **Approved test recipient** in Options, and open **InterCOM → Delivery acceptance**. Preparing the test creates an InterCOM-managed segment with an exact email-address condition. InterCOM checks that this segment, rather than the whole list, contains exactly the approved active recipient without a bounce or blacklist entry. Select **Send to one approved recipient**, inspect that delivery and confirm acceptance. Preparation previews go to the logged-in administrator's email; those previews do not establish acceptance. A missing/inactive/blocked test address stops the operation before an acceptance mailing is prepared.

If an older installation shows default/blank Options fields after a rejected save, cancel the Options editor and reopen it before making further changes. Do not save the displayed defaults. The rejection rolls back stored settings; the malformed native form session can make them appear empty.
