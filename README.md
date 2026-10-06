# InterCOM

Native Joomla 6.1 / PHP 8.3+ member communications. All development is on `main`.

## Development

Requires PHP 8.3+, Composer, Python 3, Node.js (syntax and composer interaction tests), and Docker Compose (Rancher Desktop/Moby).

```sh
composer install
python3 scripts/build.py
bash scripts/stack.sh up
docker compose exec -T joomla php /workspace/tests/joomla/install.php /workspace/dist/pkg_intercom-0.3.4.zip
```

Local site: http://localhost:8088. Administrator: `/administrator`. Synthetic local login: `intercom` / `Intercom-local-2026!`. These credentials are only for the localhost-only development stack. Do not deploy this Compose file to production.

Create an InterCOM frontend menu item. Configure component-wide Joomla permissions and delivery settings in **InterCOM → Options**. InterCOM opens a dashboard with recent activity and pool statistics. Use its submenus for communication groups, per-group permissions, recipient tags, audience access, email design, the full audit history, and filters. Refresh recipient tags and explicitly select the ones composers may use; new tags start hidden. Super Users have all-audience access; other groups start without access. Default delivery is an in-process fake gateway: **no emails leave the system**. Simulation creates local filters on demand within the same configured cap. `legacy_base/` is ignored and excluded from packages.

## Tests

```sh
bash scripts/ci.sh
```

Runs Composer validation, PHP lint, language key parity, PSR-12, PHPUnit, package build, isolated Joomla/MariaDB installations, database workflow tests, synthetic SQL migration and checksum-verified public 0.3.11 package upgrades with data preservation, native Database maintenance/Update Structure checks, native scheduler dispatch, and authenticated HTTP/CSRF/send-confirmation checks. CI uses a separate Compose project and deletes only that project's test volumes. Production data and CR credentials must never be used in CI.

Joomla's Database maintenance lists the latest SQL migration version separately from the extension manifest version. These can differ when a release contains no schema changes. The native checker must report no problems, and **Update Structure** must preserve existing data.

## Release

Every release must include written, version-specific changelog notes. Write `docs/releases/<version>.md` with the changes, relevant upgrade steps and validation before invoking the release script. The script adds those notes to `CHANGELOG.md` and generates versioned Joomla XML changelogs for the package, component and plugins. Review both formats as part of release preparation before publishing. Joomla links the installed changelog through the version number in **Manage → Extensions** and shows available-release notes in **Update → Extensions**.

```sh
python3 scripts/release.py 0.1.0 --notes /absolute/path/release-notes.md
# Publication is explicit:
python3 scripts/release.py 0.1.0 --notes /absolute/path/release-notes.md --publish
```

Requires clean `main` matching origin. Preparation runs local CI, updates version/changelog, reruns CI on the exact package, and leaves reviewable edits. Preparation can be resumed with the same version and notes; do not manually commit its generated edits between the two commands. Publishing additionally commits, tags, pushes, creates a draft release, waits for matching GitHub Actions, verifies the uploaded checksum, publishes the release, verifies the public download, and only then publishes and verifies the Joomla update feed. Errors stop the script. Before publication, a failed remote check leaves the release as a draft. A failure after publication can leave a public release with the previous update feed; inspect the failing step before recovery. Never overwrite a published version/tag.

## Credentials and operations

XChaCha20-Poly1305 envelopes use fresh nonces, HKDF-SHA-256 from Joomla's site secret, and InterCOM/provider-specific authenticated context. Changing the Joomla secret requires re-encryption or reconnecting. Backups containing both database and site configuration can decrypt secrets. Live OAuth redirects must be registered in CleverReach. Credentials and tokens are never returned to forms or audit logs.

The **Task – InterCOM** plugin applies retention, marks interrupted operations for review, reconciles CleverReach filter reservations, and sends optional board archive copies after provider-confirmed completion. It should be enabled in production. Fresh installations enable it automatically; updates preserve its existing enabled/disabled setting. On installations made with older packages, enable it under **System → Manage → Plugins**, then create and enable an **InterCOM** task under **System → Manage → Scheduled Tasks** with a working scheduler trigger. Enabling the plugin makes the routine available; a scheduled task is also required. Every five minutes is recommended for recovery, reconciliation and archive copies; daily is sufficient for retention alone. Default audit/content retention is 30 days, configurable 1–3650. Operational IDs and active/uncertain drafts are retained for reconciliation. Maintenance never blindly retries a member mailing. Uninstall intentionally retains data; explicit database removal is a separate operator action.

Maintenance isolates retention/recovery, filter reconciliation and archive delivery with separate `Throwable` guards. A failed phase logs its label, exception class and source location without raw exception contents; remaining phases can continue. Initialization and log-destination errors are guarded too. The task returns Joomla's normal failure status (`KNOCKOUT`) when a phase fails, so Joomla records the failure, advances the next execution and releases the task lock. Successful runs return `OK`. A cooperative 60-second run budget defers work between phases and rows; reconciliation retains its 20/40-second limits, and archives have a 20-second phase budget. Archive SMTP connection and command waits are capped at 10 seconds and reduced to the remaining budget. An in-flight call may overrun the cooperative budget, and these guards cannot recover a killed process, memory exhaustion or a stalled database. Uncertain SMTP outcomes remain protected from automatic retry.

Live delivery requires a controlled acceptance send to exactly one approved recipient (CleverReach has no sandbox), a verified sender and list-bound unsubscribe form, and exclusively InterCOM-managed filters. Submitted/uncertain/cancelled remote mailings keep their filter reservations until reconciled. No time-based automatic recycling, automatic cross-provider failover or blind send retries.

## Current limitations

The component includes Joomla rich-text composition, a branded bilingual email template, first-name insertion, an isolated matching preview, translated communication groups and structured tag/access administration. Audit history and filters have separate paginated pages. Email design is configurable, with light/dark previews and a single sender default in Options. Component and communication permissions saved through InterCOM are audited; changes through Joomla user administration still rely on Joomla core action logs.

Verified same-account token renewal (#5), safe filter reconciliation (#2), list-bound unsubscribe forms (#4), delegated Joomla test delivery (#3), and enforced one-recipient acceptance (#1) are implemented. The controlled dev delivery completed and its human receipt/form confirmation is recorded; the current dev configuration passes the server-side acceptance gate. Local SMTP capture does not prove production inbox delivery.

Production still requires deployment-specific staging install/update, backup/restore preserving Joomla's secret, a working scheduler and SMTP, delegated role and native Danish/English site checks, and received-email light/dark rendering. A production configuration needs its own controlled acceptance; dev approval does not transfer. See [the production handoff](docs/recipient-estimates-production-plan.md#current-production-handoff) and [acceptance procedure](docs/release-acceptance.md).

See [implementation plan](docs/implementation-plan.md) and [design sketch](docs/intercom-sketch.html).

## Release recovery

The script never force-pushes, replaces a tag, rolls back commits, or discards source changes. If a local check fails before preparation completes, inspect `git diff` and fix the cause before retrying. A completed preparation has an ignored `dist/release-state.json` fingerprint and can be resumed unchanged with `--publish`. If publication fails after tagging, inspect the GitHub Actions run, draft/public release, asset checksums, and `updates/intercom.xml`. Recover that published state explicitly; the script deliberately refuses to overwrite an existing tag. CI includes a checksum-pinned public 0.3.11 upgrade fixture alongside the synthetic migration test; keep public-package upgrade coverage current for future releases.

## First development milestone

Local verification: Joomla 6.1.3, PHP 8.3.35, MariaDB 10.6; unit tests also pass on host PHP 8.5.7. The component boots with compatibility plugins disabled. Browser checks cover saving/testing/submitting through the fake provider, the administrator configuration screen, and a 390px mobile layout without horizontal overflow. The initial local milestone used no live credentials or member data. Later acceptance testing used a dedicated one-recipient CleverReach list. Joomla's full Danish language pack and multilingual menu setup are still needed for end-to-end locale-switch testing. No public release has been made.


## Simulation and CleverReach credentials

Drafts record their delivery mode. Simulated reservations do not block saving or connecting real OAuth credentials; live reservations still protect the associated account. Changing delivery mode clears simulated leases and invalidates pending simulated drafts. Drafts cannot be reused in another mode. Save real credentials under Options → CleverReach connection before selecting Connect CleverReach. The callback shown there uses the current site host: use the same host consistently (localhost and 127.0.0.1 have separate browser sessions). The composer now follows the approved three-step layout with selection cards, language controls and an illustrative email preview. The editor now uses Joomla’s editor provider and sanitises email HTML on the server. Old plain-text drafts remain readable and become rich-text drafts when saved.

Integration fixtures can only run with INTERCOM_CI=1 in disposable CI. They must not be run on the persistent development site.

## Manual token import

In InterCOM → Options → CleverReach connection, paste a freshly issued access token, an optional refresh token, and its **remaining** lifetime in seconds, then select **Import tokens**. Do not paste tokens into chat, source files, or Git. Import writes the encrypted database envelope and an audit event without contacting CleverReach or changing the delivery mode. An access-only token works until expiry; automatic renewal requires a refresh token and the matching saved OAuth client ID/secret. Save client credentials **before** importing tokens: replacing client credentials clears existing tokens. A blank refresh token clears any previous refresh token. Use tokens for the configured CR account/test list.

Use the Options toolbar for ordinary settings and permissions; use the separate buttons for credentials/token import. Secret fields always render blank, stay outside native configuration parameters and are never included in audit metadata. The bundled Extension - InterCOM plugin validates and audits configuration saves and must remain enabled. Existing credentials and settings survive installation/update. Audit history and filter reservations are available in their component submenus.

Manual filter IDs are retired on upgrade. New drafts use only filters created by InterCOM. Reserved historical rows remain visible on the dashboard for audit and cannot be acquired by new drafts.

## Managed CleverReach filter pool

Choose the recipient list from CleverReach in Options and set **Maximum InterCOM filters per list** (default 5, range 1–20). New live previews reserve an InterCOM-owned filter or create one with an initially empty audience when no owned filter is free. The created filter is then updated with the draft's audience rules. The cap includes filters tied to submitted/uncertain mailings and creation requests whose remote outcome is uncertain. Scheduled maintenance reconciles these slots, and administrators can run **Filters → Reconcile with CleverReach**. A filter becomes reusable only after the provider confirms a completed static mailing on the selected list and confirms the filter still exists. Scheduled, missing, dynamic/campaign and failed checks retain their reservations. The component never overwrites unrelated CleverReach filters. Simulation uses local filters and makes no remote creation request. Changing the selected list or connection is blocked while live reservations or unresolved creation requests exist.

The Filters page shows the last check and reason for each retained reservation, plus unresolved creation requests. Recovery adopts exactly one filter matching the unique name recorded before its creation POST; zero or multiple matches remain blocked and still count towards the cap. It never retries an uncertain POST or frees a slot based on age. Recent creation requests are not inspected while they could still be in progress. Checks are bounded per maintenance run and serialized with account/lease changes; repeated runs do not release a filter acquired by a newer draft. All state transitions are audited. Historical unmanaged reservations require separate operator review.

## Preview and unsubscribe forms

Composer tests are delivered through Joomla's native mail service to the signed-in user's account email, with separate Danish and English messages and inactive sample unsubscribe/online links. Operators must inspect both tests; SMTP acceptance alone does not prove inbox delivery. The separate controlled CleverReach acceptance verifies real member delivery and its list-bound unsubscribe flow.

CleverReach has deprecated the v3 forms endpoints. New unsubscribe forms are configured in the CleverReach UI for a recipient list. Options lists new unsubscribe flows belonging to the selected recipient list, using the Flow API and the token’s **forms** scope. Changing the list reloads the selector. New UUIDs and existing numeric IDs use the same mailing API `settings.unsubscribe_form_id` field; InterCOM preserves the full identifier. It validates the selected form’s list/type before preparing a mailing and checks the saved mailing selection before preview or release. An unavailable API preserves the configured option during unrelated settings edits, but sending fails closed. Legacy IDs remain available for migration and are validated against the old list-forms endpoint. The dev list now selects its new Unsubscribes flow. The dev recipient confirmed delivery and the new destination; details are recorded in [the integration notes](docs/cleverreach-unsubscribe.md). The old form stays intact for historical mailings.


## Communication groups and recipient tags

In **Component settings → Communication groups**, add or edit a group’s stable key, suppression word, category, publication, order and team-selection requirement. Language tabs offer name, description, subject prefix and email heading for Danish, English and configured content languages. A name in the default site language is required; missing translations fall back to it. Additional interface languages do not add email-body languages.

In each record’s native Permissions section, grant composition and sending separately to Joomla groups. Component-wide access/compose/send permissions are still prerequisites. New records have no delegated grants. An explicit denial overrides inherited grants. Used groups can be archived/unpublished but their identities cannot be deleted. Upgrades copy the former fixed-type permissions and category IDs and preserve operational history.

Calculated settings preview the current selections without saving; use **Save changes** to apply and audit permissions. If a descendant shows **Not Allowed (Locked)** despite an Allowed selection, check explicit Denied settings on its ancestor Joomla groups and parent assets. Earlier InterCOM saves could turn Inherited selections into Denied. After installing the fix, review those settings and change unintended ancestor denials back to Inherited, then save. Existing denials are preserved during upgrades because intentional restrictions cannot be distinguished from affected saves.

**Recipient tags** refreshes account-wide CleverReach tags without altering member data. Select the tags that should appear, set their order and save. **Audience access** restricts which `group.*` tags each Joomla group may target, or grants all-member access. The composer provides exactly one searchable multi-select for `group.*` and one for `membership.*`; deeper prefixes stay within these controls. Matching uses OR within each selection and AND between selections, age, gender and suppression. A refresh preserves visibility, hides vanished tags, and leaves new tags hidden. A failed refresh preserves the old catalogue. Simulation and each recipient list have separate catalogues.

Drafts snapshot their communication definition. Changes to group settings, tag visibility, list/sender/form/archive settings, or the email template require a new test. Changed definitions require review and saving first. Stale administrator revisions are rejected. Hidden/unavailable tags cannot silently disappear and broaden a saved audience.

## Board archive copies

Options contains an optional **Board archive email**. Leave it empty to disable. After CleverReach confirms a mailing has finished, scheduled maintenance sends one bilingual archive copy through Joomla’s configured mail transport. This separate operation records the approved revision, sender and audience criteria; it never sends ordinary previews to the board and never repeats the member mailing. The v3 contract does not provide a verified `bcc_email` integration, so InterCOM does not depend on that legacy field.

The archive outbox records pending, sending, submitted or uncertain status in the database and audit log. Provider status failures leave copies pending; interrupted or failed SMTP calls require operator review and are never automatically retried. Retention applies to terminal archive content. The developer archive address remains empty, and automated tests inject fake SMTP/provider completion to avoid real delivery.

Recipient tags have automatically formatted and capitalized names, with optional per-language overrides. Drag rows within each tag section or use the arrow buttons (including on mobile); save to keep the order. Language labels sit above their inputs. The email footer and plain-text alternative use the same trusted language labels as the composer; raw CleverReach tags still determine the audience. Existing drafts need saving and a new test to pick up label/design changes.

## Email design and composer behaviour (0.3.1)

**InterCOM → Email design** controls branding, the logo, typography, spacing, and paired light/dark colours. The header places the club name and communication heading on the left and the logo on the right. Communication headings still come from each group’s language tabs. Poppins is requested where supported, with a sans fallback; the body uses the configured email-safe font stack. Text/link contrast is validated in both palettes. The logo has a protective navy background.

New drafts use one sender default from Options for both Danish and English. Existing drafts and edited names retain their value; changing body/preview language does not overwrite the sender. Upgrades preserve the previous English sender default when consolidating the setting.

Design saves/resets are audited and revision checked. Drafts snapshot the design. A design change requires review, saving, and a new test before release; scheduled/submitted mailings keep their approved design. Preview-only changes in the backend are not persisted.

Subject and sender edits update the envelope without requesting or replacing the email HTML. Body edits render after an 800 ms pause, plus the Joomla editor polling interval where applicable. The last email remains visible with an updating indicator. Stale requests are ignored; save/test actions always read the current editor value. Team-required communications cannot progress without an available permitted team. Group and membership controls close on repeated trigger clicks and do not allow arbitrary tags.

The dashboard reports the current mode/list pool, uncertain creation slots/reservations, draft states, accepted submissions within retained audit history, and maintenance/archives. Full audit history supports actor/event/UTC date filtering; filters support current/historical scope and state filtering. Audit-derived data and underlying routes require audit permission; management pages require component admin permission.

Dark-mode markup includes colour-scheme metadata, targeted media rules, and Outlook overrides. Browser light/dark previews validate the authored themes; they cannot reproduce every mail client’s colour inversion. Actual received-email checks in Apple Mail, Gmail, and Outlook, including CleverReach’s processing, remain a release acceptance requirement. No live mailing is sent by automated CI.

The default sender name is shared by both message languages and configured beside the sender email in Options. Existing draft sender names are retained. Recipient tags and memberships each provide bulk visibility selection and optional per-language display names; blank names use automatic underscore/time formatting. CleverReach tag identifiers and audience permissions retain their original values.

Use **New communication** in the composer header to start a fresh message while keeping the current draft. **Save draft** accepts unfinished subjects and bodies; **Send test** validates and saves the current bilingual content before testing it. Unsaved changes recover automatically from this browser for the same user, account, list and server revision (up to the configured retention period). Browser recovery is local to that browser; save the draft to store it on the site. A newer server revision is never overwritten by recovery.

Saved drafts can be deleted and restored through **Deleted drafts**. Only the owner with compose permission can delete them; testing, releasing, submitted, scheduled and uncertain states are protected. Deletion preserves audit and provider identifiers. Live reservations remain blocked pending reconciliation; simulated reservations can be freed. Restored drafts require review, saving and a new test. Scheduled maintenance purges deleted message content under the configured retention period.

Options → **Email footer** configures the address, phone, contact email, website, social links, and Danish/English member-profile URLs. Blank optional fields are hidden. HTTPS links and plain-text contact details are validated. Email design provides paired footer background colours with readable contrast. The supplied Postmark footer inspired the appearance; InterCOM retains CleverReach personalisation and unsubscribe directives. Footer changes invalidate previous test approval, and already submitted mailings retain their approved content.
