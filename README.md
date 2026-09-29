# Intercom

Native Joomla 6.1 / PHP 8.3+ member communications. All development is on `main`.

## Development

Requires PHP 8.3+, Composer, Python 3, Node.js (syntax checks), and Docker Compose (Rancher Desktop/Moby).

```sh
composer install
python3 scripts/build.py
bash scripts/stack.sh up
docker compose exec -T joomla php /workspace/tests/joomla/install.php /workspace/dist/pkg_intercom-0.1.3.zip
```

Local site: http://localhost:8088. Administrator: `/administrator`. Synthetic local login: `intercom` / `Intercom-local-2026!`. These credentials are only for the localhost-only development stack. Do not deploy this Compose file to production.

Create an Intercom frontend menu item. Configure native Joomla group/type permissions in Intercom → Options. Configure allowed recipient tags and delivery settings in the same Options page. Super Users have all-audience access; other groups start without access. Default delivery is an in-process fake gateway: **no emails leave the system**. Seed fake filter IDs through settings (e.g. 9001,9002) for manual testing. `legacy_base/` is ignored and excluded from packages.

## Tests

```sh
bash scripts/ci.sh
```

Runs Composer validation, PHP lint, language key parity, PSR-12, PHPUnit, package build, an isolated Joomla/MariaDB installation, database workflow tests, a synthetic pre-release SQL upgrade with data preservation, native scheduler dispatch, and authenticated HTTP/CSRF/send-confirmation checks. CI uses a separate Compose project and deletes only that project's test volumes. Production data and CR credentials must never be used in CI.

## Release

```sh
python3 scripts/release.py 0.1.0 --notes /absolute/path/release-notes.md
# Publication is explicit:
python3 scripts/release.py 0.1.0 --notes /absolute/path/release-notes.md --publish
```

Requires clean `main` matching origin. Preparation runs local CI, updates version/changelog, reruns CI on the exact package, and leaves reviewable edits. Preparation can be resumed with the same version and notes; do not manually commit its generated edits between the two commands. Publishing additionally commits, tags, pushes, creates a draft release, waits for matching GitHub Actions, verifies the uploaded checksum, publishes the release, verifies the public download, and only then publishes and verifies the Joomla update feed. Errors stop the script. Before publication, a failed remote check leaves the release as a draft. A failure after publication can leave a public release with the previous update feed; inspect the failing step before recovery. Never overwrite a published version/tag.

## Credentials and operations

XChaCha20-Poly1305 envelopes use fresh nonces, HKDF-SHA-256 from Joomla's site secret, and Intercom/provider-specific authenticated context. Changing the Joomla secret requires re-encryption or reconnecting. Backups containing both database and site configuration can decrypt secrets. Live OAuth redirects must be registered in CleverReach. Credentials and tokens are never returned to forms or audit logs.

Enable the task plugin and schedule `Intercom` maintenance daily in Joomla Scheduled Tasks. Default audit/revision retention is 30 days, configurable 1–3650. Operational IDs and active/uncertain drafts are retained for reconciliation. Uninstall intentionally retains data; explicit database removal is a separate operator action.

Live delivery requires a controlled one-recipient CR test list (CR has no sandbox), approved preview address, verified sender, category/form IDs and exclusively allocated filters. Submitted/uncertain/cancelled remote mailings keep their filter reservations until reconciled. No time-based automatic recycling, automatic cross-provider failover or blind send retries.

## Current limitations

First development version uses plain-text composition converted to safe HTML; rich text and the exact branded email template are follow-up work. Native locale resources are included; installing Joomla's Danish language pack and full multilingual menu-switch/browser coverage remain setup/validation work. The admin audience-rule editor is JSON in this initial version. Live release, recipient evaluation timing and scheduled cancellation need one-recipient acceptance testing before production. Permission changes made through Joomla core Options/user administration still need dedicated Intercom audit integration; core action logs are separate. Audit history is paginated, with 20 events by default and choices of 10, 20, 50 or 100. No automatic remote filter reconciliation yet; maintenance flags interrupted operations for review. The system must not be represented as production-ready.

See [implementation plan](docs/implementation-plan.md) and [design sketch](docs/intercom-sketch.html).

## Release recovery

The script never force-pushes, replaces a tag, rolls back commits, or discards source changes. If a local check fails before preparation completes, inspect `git diff` and fix the cause before retrying. A completed preparation has an ignored `dist/release-state.json` fingerprint and can be resumed unchanged with `--publish`. If publication fails after tagging, inspect the GitHub Actions run, draft/public release, asset checksums, and `updates/intercom.xml`. Recover that published state explicitly; the script deliberately refuses to overwrite an existing tag. Future releases must add a previous-public-package upgrade fixture alongside the current synthetic migration test.

## First development milestone

Local verification: Joomla 6.1.3, PHP 8.3.35, MariaDB 10.6; unit tests also pass on host PHP 8.5.7. The component boots with compatibility plugins disabled. Browser checks cover saving/testing/submitting through the fake provider, the administrator configuration screen, and a 390px mobile layout without horizontal overflow. No live credentials or member data were used, and no emails were sent. Joomla's full Danish language pack and multilingual menu setup are still needed for end-to-end locale-switch testing. No public release has been made.


## Simulation and CleverReach credentials

Drafts record their delivery mode. Simulated reservations do not block saving or connecting real OAuth credentials; live reservations still protect the associated account. Changing delivery mode clears simulated leases and invalidates pending simulated drafts. Drafts cannot be reused in another mode. Save real credentials under Options → CleverReach connection before selecting Connect CleverReach. The callback shown there uses the current site host: use the same host consistently (localhost and 127.0.0.1 have separate browser sessions). The composer now follows the approved three-step layout with selection cards, language controls and an illustrative email preview. The actual email editor is still plain text.

Integration fixtures can only run with INTERCOM_CI=1 in disposable CI. They must not be run on the persistent development site.

## Manual token import

In Intercom → Options → CleverReach connection, paste a freshly issued access token, an optional refresh token, and its **remaining** lifetime in seconds, then select **Import tokens**. Do not paste tokens into chat, source files, or Git. Import writes the encrypted database envelope and an audit event without contacting CleverReach or changing the delivery mode. An access-only token works until expiry; automatic renewal requires a refresh token and the matching saved OAuth client ID/secret. Save client credentials **before** importing tokens: replacing client credentials clears existing tokens. A blank refresh token clears any previous refresh token. Use tokens for the configured CR account/test list.

Use the Options toolbar for ordinary settings and permissions; use the separate buttons for credentials/token import. Secret fields always render blank, stay outside native configuration parameters and are never included in audit metadata. The bundled Extension - Intercom plugin validates and audits configuration saves and must remain enabled. Existing credentials and settings survive installation/update. Audit history and filter reservations remain on the component dashboard.
