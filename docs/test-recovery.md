# Test failures, recovery and email lists

The composer shows saved status above its steps and repeats test feedback beside Send test. A successful transport result means the site mail service accepted the messages; it does not prove inbox receipt. Check both language versions before confirming a member send.

Selecting only a type and age/gender criteria is supported when the communication type and your permissions allow an audience without a team. Empty optional tag selections do not trigger provider tag lookups. Required-team and delegated audience restrictions still apply.

## Failure handling

Preliminary lookup/render failures keep a draft editable. Review the error and retry Send test manually. Known mailing preparation followed by a failed test keeps the mailing and its reservation, clears approval, and permits another explicit test. Partial or uncertain SMTP delivery may result in duplicate test messages; check the inbox before retrying.

An uncertain filter update or mailing preparation remains protected. Its status explains which boundary failed and shows the operation reference. An administrator must investigate the provider state. Refresh status only reads the current result; it never repeats a test or member submission. Interrupted tests use the last durable stage; unknown writes and member-send uncertainty are never automatically retried.

Diagnostics record stage, operation reference and sanitized error classifications. They do not contain recipient addresses, email content, credentials or raw provider response/exception text.

## Recovering an older pre-preparation failure

An administrator opening their own eligible legacy email sees **Recover editable draft** above the composer. The server requires a live, uncertain record with no known mailing, prepared history, release attempt, acceptance or abandonment record; intact current-list filter ownership; and a complete, unchanged audit trail from initial save through the first failed preview. Missing IDs alone are insufficient. The administrator must also verify and attest that the failed test ran under the 0.3.24 workflow, whose prepared history precedes remote mailing preparation.

Take a current backup, inspect the specific record and version evidence, and then use the recovery control. It preserves the filter, invalidates old estimates/approval, advances the revision/generation and audits the operator action. A fresh recipient check and test are required. It does not send email. If the control is absent or evidence is incomplete, investigate rather than editing status directly in SQL. This path is separate from abandonment of a known prepared mailing.

For production #10, the supplied dump matches the expected pre-history pattern. Reassess the current production record after installation; this implementation does not modify production or assume the dump is still current.

## Reusing and finding emails

**Copy to new draft** copies saved bilingual content, type and recipient criteria into a new owner-scoped draft. It uses current design/settings and rechecks current targeting permissions and tag availability. Unavailable targeting blocks copying rather than silently broadening recipients. The new draft has no remote mailing/filter, estimate, schedule, test approval, archive or acceptance proof. Copying never changes the original or resolves its reservation. If content has expired under retention, copying is unavailable.

Drafts contains editable drafts and tested drafts. Sent / Scheduled contains scheduled, submitted and completion-confirmed communications with distinct badges. In progress / Needs review contains active and uncertain operations. Cancelled / Deleted retains inactive records and the existing restore path. Search by subject or ID, filter by state, and page through the list. All lists and actions remain scoped to the signed-in user's emails.
