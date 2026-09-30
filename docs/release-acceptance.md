# One-recipient release acceptance

Live member sends require a server-side acceptance record. The former editable
`release_verified` option is ignored and reset during upgrade. Simulation never
verifies live delivery.

1. In Options choose the verified CleverReach account, recipient list, sender,
   unsubscribe form and an explicitly approved test recipient on that list.
2. Open **Delivery acceptance** as a component administrator. Prepare a test.
   Intercom adds an exact email rule to its own reserved filter, verifies the
   remote rules and active count, reads the sole recipient and checks activation,
   bounce status and global/list blacklists. Any missing scope, malformed response
   or count other than one blocks preparation before a remote mailing is created.
3. Inspect both Joomla test messages. Local development captures these in Mailpit
   at http://localhost:8025. This proves SMTP capture, not a real inbox delivery.
4. Explicitly approve the single CleverReach send. The recipient checks repeat
   before release, including sender, category, static mailing, list and linked
   unsubscribe form. No normal member release is authorized by this exception.
5. After the actual message arrives, inspect the sender, layout, member profile
   links and unsubscribe form. Open the form without confirming an unsubscribe.
   Confirm receipt in the administrator screen. Intercom also requires positive
   CleverReach completion before saving approval.

Approval binds the verified customer account, mode, list, sender name/address,
unsubscribe form, approved test recipient, template version, email design,
footer settings and communication definitions/translations. Changing these
requires acceptance again. Token renewal for the same account preserves approval.
The approved address is a non-secret option; audit events contain hashes/counts,
not recipient addresses. The private authored acceptance draft contains its exact
email targeting rule, subject to draft/history ACL and content retention.

An ambiguous release retains its reservation and never retries automatically.
An administrator can confirm the original delivery only after actual receipt and
positive remote completion. Failed preparation before a remote mailing frees its
safe reservation; failed or interrupted remote preparation retains it for review.
Do not create replacement tests to bypass unresolved operations. Resolve or retire
an obsolete acceptance record explicitly after reconciling it in CleverReach.

Automated tests use disposable databases, API fixtures and local SMTP. They prove
eligibility checks, release gates, owner isolation and configuration invalidation,
not external inbox delivery. Production still needs its own acceptance, real
SMTP and delegated-role checks, scheduler/cron, backup/restore and email-client
rendering tests. An acceptance performed on dev is not a production approval.
