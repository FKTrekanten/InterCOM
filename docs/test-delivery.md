# Test delivery for delegated composers

Live-mode composer tests use Joomla's native mail service. They no longer require the recipient to be a CleverReach account user. The authenticated composer's Joomla account email is the only target; no request field can supply another address. Danish and English arrive as separate multipart HTML/text tests, using frozen language renderings, example member details and inactive personal unsubscribe/online-view links. The HTML preserves the authored light/dark themes.

CleverReach still prepares the real mailing, validates the list-bound unsubscribe flow and retains the managed filter. The test mailer's SMTP acceptance does not establish inbox delivery. Both actual tests must be inspected before confirming the member send. A partial/failed SMTP attempt invalidates test approval and has no automatic retry or member release. Errors/audits omit mail credentials and recipient addresses.

Production needs a working Joomla mail configuration and a verified sender for that service, in addition to CleverReach broadcast credentials/sender. Native tests are identified as Intercom tests and sent from Joomla's configured site sender. They prove component formatting; a controlled CleverReach acceptance send checks provider personalisation and the real unsubscribe flow. Simulation continues to send no email.

The local Docker stack includes pinned Mailpit at http://localhost:8025. Its SMTP endpoint stays inside the Docker network; only the inbox UI is bound to host loopback. Default PHP mail is redirected to the local capture service. A pre-existing custom SMTP/sendmail configuration is preserved. This is a development capture inbox, not proof of delivery to a real external inbox. Production must use its own configured mail service. Credentials remain in Joomla's normal configuration, not Intercom Options.

CI verifies both language messages through the real native Joomla mailer and local SMTP capture, including a delegated composer without send permission, plain-text/dark-mode content, account-user independence, SMTP failure and no provider/member release. Unit tests check fixed-target delivery and safe errors.
