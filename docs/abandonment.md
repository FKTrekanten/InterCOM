# Abandon an unsent test mailing

This flow applies to an unwanted, prepared test-only mailing on the current live CleverReach account and recipient list. It does not send email or delete anything in CleverReach. Scheduled, submitted, active, uncertain and completed mailings remain protected. For completed sends, use normal reconciliation instead.

1. Keep the original account and list selected. Open **InterCOM → Filters** and choose **Abandon test mailing** on the reserved row. An administrator with component management and audit access is required.
2. Check the displayed draft, list, filter and mailing IDs. Enter a reason, confirm abandonment and select **Inspect and begin abandonment**. InterCOM verifies the provider account, list and segment, and requires that the exact static mailing is present among CleverReach's drafts with no ready, scheduled, started or finished status. Further testing, restoration and sending are blocked during the operation.
3. Only after **Unsent draft verified** appears, delete that exact mailing in CleverReach. Do not delete or repurpose the segment. Empty any trash if present. Check that the mailing cannot be restored or sent; if that is unclear, retain the reservation and ask CleverReach to confirm permanent removal.
4. Return to the confirmation page, select **I have permanently removed this exact mailing…**, then **Verify retirement and release reservation**. InterCOM rechecks the same account, list and segment, requires the individual mailing to be absent, and checks all draft, waiting, running, automation and finished catalogues successfully before releasing the reservation.
5. The row becomes **Available** with **Abandoned; reservation released**. Repeat for other unwanted tests. Changing the list is possible when all remaining live reservations and unresolved creation requests have cleared, and requires fresh delivery acceptance for the new configuration.

Start the InterCOM inspection **before** deleting the CleverReach mailing. A mailing that was already missing cannot establish the required unsent baseline. Test-email receipt and local draft deletion do not establish either completion or permanent retirement.

## Verification limits

The [published CleverReach v3 API](https://rest.cleverreach.com/v3/explorer/swagger.json) has no documented mailing DELETE endpoint or permanent-deletion/trash contract. InterCOM therefore uses read-only provider requests and your explicit confirmation of permanent removal. Successful API absence checks support that confirmation; they do not independently prove that CleverReach has no restore mechanism. No guessed deletion endpoint is used.

The mailing response identifies its recipient list but does not expose the filter reference used here. InterCOM requires matching managed local draft and prepared-history references, plus a successful read of the exact filter on that list. Reconstructed legacy history or any recorded release attempt is ineligible.

Catalogue checks are bounded to ten pages of 100 records per state and a cooperative 40-second provider budget. Reaching a bound, a timeout, missing permissions, a malformed result or an account/list mismatch retains the reservation. Very large catalogues may require provider assistance rather than bypassing the check.

## Recovery and history

If initial inspection fails, the original draft state is restored and its reservation remains. Retry inspection after resolving the problem. Once the unsent baseline has been verified, a failed or interrupted retirement check keeps the claim; use **Continue abandonment** to retry verification. Scheduled maintenance never initiates abandonment or automatically releases these claims. A process killed before initial inspection finishes can resume through the same action.

Deleted drafts remain deleted after release. Other abandoned drafts become cancelled. Their operational mailing/filter IDs and test approval are cleared, so restoration starts with a fresh audience, mailing and test. History records **Abandoned**, with no invented send/completion timestamps or archive delivery. Original provider IDs, account/list, requesting and verifying operators and verification time remain in durable operation records. History details show completed abandonment records even after a later send replaces the draft's current snapshot; the old message snapshot is not separately versioned. Finished operations' reason text expires with the configured retention period.

An incomplete acceptance test is retired consistently; abandonment cannot grant acceptance or invalidate an unrelated current verified proof. A verified acceptance mailing cannot be abandoned through this flow.
