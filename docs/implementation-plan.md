# Intercom — first frontend sketch

Status note, 30 September 2026: the dated planning notes below include historical proposals. Development version 0.1.6 is built and installed in dev. The current proposed next milestone is [editable communication groups and remaining legacy features](legacy-feature-plan.md), including administrator-managed suppression words/translations, selected-tag dropdowns, rich-text email templates, and board copies. See README for implemented behavior and current limitations.

## Confirmed scope

- Joomla 6 native component; PHP 8.3+.
- Preserve current suppression and opt-out behaviour, including club information. The membership-use notice informs senders; it does not override member preferences. Revisit wording/behaviour in a future version.
- Use Joomla identity, session, ACL and CSRF directly. No cmsAPI bridge.
- Initial work covered analysis, planning and a visual sketch. A development component is now implemented; production acceptance remains incomplete.

## Design evidence

Inspected https://trekanten.org/ in the browser on 28 September 2026. Its rendered styles use Poppins for body and headings, primary blue rgb(15,107,153), charcoal headings rgb(64,64,66), white navigation, an uppercase heading treatment and the triangular club logo. The existing newsletter uses #172534 for its header.

The standalone sketch references the observed public club logo and Google Fonts. These require a network connection; production should inherit the site's typography and use Joomla-managed assets.

## Design plan

Audience: selected club staff and board members. Job: compose, test and send a member communication to the correct audience.

Tokens: club blue #0F6B99; charcoal #404042; email navy #172534; mist #F3F6F8; divider #DCE4E9; white #FFFFFF.

Typography: Poppins 800, 40px for the restrained two-line page title; Poppins 400–600 for controls, labels and interface text; Arial for the email preview, consistent with the existing email template. Production should inherit the site font rather than load another copy.

Layout candidates:

```
A. Continuous form             B. Three-step composer + persistent preview
[audience] [preview]            [recipients | content | test & send]
[content ] [       ]            [current task]       [email preview]
[send    ] [       ]            [next action ]       [audience summary]
```

Selected B: three real steps reflect the existing recipient → compose → test/release workflow and reduce the long legacy form. The preview preserves context across the steps. On mobile it follows the current step in normal document order.

Signature: one blue triangular fold in the preview header echoes the club's logo. Everything else is quiet and functional.

Critique before implementation: rejected a separate SaaS dashboard shell and extra display font. Both would compete with the host site's identity. The sketch instead inherits Trekanten's typography, heading treatment and palette. The sample header represents site context; the component must not duplicate the Joomla template's header or footer.

## Prototype behaviour

- Three navigable stages, communication types and example recipient filters.
- Danish and English sample content with independently switchable preview.
- A simulated test must precede release confirmation. Editing content or audience invalidates the test.
- Immediate and scheduled confirmation flows; no email, account writes or CleverReach calls.
- Draft state exists in memory only. The save button explains this limitation.
- Board role is the sample state; production visibility and permissions must come from Joomla ACL.
- Uses plain text editing in the sketch. Production needs an approved editor and server-side HTML sanitisation compatible with the email template and supported placeholders.
- No invented recipient totals: live counts must come from validated recipient evaluation.
- The preview is illustrative, not a verified rendering of CleverReach output. Actual email-client checks belong to implementation validation.

## Implementation stages

1. Native package, frontend view, administrator configuration, Docker environment and CI style/install checks.
2. Injected CleverReach transport and OAuth services, coordinated token refresh and fake API.
3. Persisted drafts/revisions, ownership-aware atomic filter reservations and audit records.
4. Bilingual editor, recipient selection, test, release, scheduling and recovery of uncertain remote outcomes.
5. CI regression coverage for concurrent reservations, stale owners, malformed responses, edited drafts, duplicate release, and filter retention; upgrade seeded prior packages and compare upgraded/fresh schemas.

Scope boundary: recipient synchronisation methods in the legacy client are not used by the composer and are excluded from the first release. Existing member preferences remain authoritative.


## Revision 2 — Sixte design guide

Reference supplied by the user: https://claude.ai/artifact/K4EmNPnJxyzSRhfTPtFZfi (Sixte Design System), inspected in the browser on 28 September 2026. This revision supersedes the original application palette/type choices above; the Trekanten site and existing email remain brand references.

Design boundary: the Joomla host retains Trekanten branding. Intercom's working interface adopts Sixte neutrals and compact controls. The email preview keeps the club's existing newsletter identity. No Tailwind dependency is implied by the guide's example configuration; the tokens can be implemented in scoped component CSS.

Application tokens: steel-950 #14181B; steel-800 #262D32; steel-600 #4A545C; steel-400 #8B959D; steel-200 #CFD5D9; chalk #F1F4F4. Status treatments use green #4FA845 / ink #2C6B27 / wash #EAF4E9 for completed tests, and amber #C98A12 / ink #8A5D06 / wash #FBF2DF for a test that needs repeating. Red is reserved for actual failures, not decorative or ordinary send actions. Statuses have text as well as colour.

Typography: system sans stack for the application, tight sentence-case headings and global tabular figures. Monospace for numeric/date fields. Poppins remains confined to the site-context brand and email heading in this standalone sketch. A self-hosted production sans face can be selected with the broader Sixte application; the sketch does not add an external application font.

Critique and revision: removed the promotional headline and ornamental triangular fold. Replaced blue action/selection styling with neutral steel. The recognisable element is now the persistent club email preview beside the compact workflow. Brand identity stays in the message being sent rather than competing with task controls.

Small control radii (3px), panel radii (4px), 8px-based principal spacing, explicit blue keyboard focus, short feedback-only motion, and reduced-motion support follow the guide. The existing three-step task structure remains appropriate to this workflow. Ledger-specific presentation is not added to a composer.

Localisation implementation requirement: English source keys and fallback copy, complete Danish translation, full-sentence keys with named placeholders, locale-aware dates and space for longer translations. Interface language and recipient-content language are distinct. The current sketch is still Danish at interface level; DA/EN switches email content only. Full interface translation is a production milestone, not claimed as completed in this prototype.

Confirmed PHP target and suppression decisions are unchanged. This is a frontend sketch, not a Joomla installation or live email tool.

## Confirmed access, audit and operational requirements

The user approved the visual direction and added these requirements:

- Delegate access through selected Joomla user groups.
- Configure which communication types each group may use: for example, SoMe managers may send newsletters, coaches may send team news, and the board may send every type including club information.
- Support restricting recipient groups. Proposed interpretation: configure allowed CleverReach group tags separately from the Joomla groups granting access. Coaches can thereby be restricted to their own teams. This is configurable rather than an assumed restriction on every coach.
- Log system activity for audit purposes.
- Make all workflows usable on mobile devices.
- Configure CleverReach authentication in administrator options and store secrets securely.

### Proposed permission model

Use native Joomla ACL for component access, composing, sending, each communication type, connection management, permission management and audit viewing. Add an explicit audience-scope policy mapping Joomla groups to allowed CleverReach recipient tags; this is separate from Joomla ACL. No hard-coded group IDs. Default to no access until configured.

Recheck permissions and audience scope server-side when saving, testing and releasing; UI visibility alone is insufficient. Revalidate a queued send before dispatch if it has not already been handed to CleverReach. Already released/scheduled remote mailings require a separate cancellation policy based on supported API behaviour.

Multiple-group rule proposed for implementation: use Joomla's effective ACL result, including explicit denies. Union applicable permitted recipient scopes only after the action/type has passed ACL. All-audience access is an explicit grant, never inferred from an empty configuration. An omitted audience selection must never expand a restricted user's scope. Decide whether a restricted coach may select all their permitted teams through an explicit UI action.

### Audit coverage

Record actor identity and effective action, UTC timestamp, draft/revision ID, recipient criteria, communication type, remote identifiers, schedule, result and correlation ID. Events cover draft creation/edits/deletion, test attempts, confirmations, release attempts/results, scheduling, cancellation, permission denials, configuration/permission changes, OAuth connection/reconnection/refresh outcomes, filter reservations and recovery actions. Keep prior message revisions so reviewers can reconstruct what was approved and sent; revisions are sensitive records and require restricted access.

Never record passwords, client secrets, access/refresh tokens, Authorization headers or unredacted provider responses. Audit configuration changes by field and redacted before/after values. Routine audit records should not contain full recipient address lists. Audit events should be append-only through the application, with audit viewing/export separately authorised. Database administrators can still alter database records; this is not claimed to provide cryptographic tamper-proofing. Retention, deletion rights and any external tamper-evident archive need an explicit deployment policy.

Persist the send intent and audit record before the remote release call, then record the outcome. If recording the intent fails, do not release. If the provider accepts but local completion recording fails, preserve an uncertain state and reconcile; do not blindly retry. Application events and background recovery must both participate in auditing.

### Secure backend connection options

Administrator configuration exposes client ID, client secret, OAuth connection state and related CleverReach account settings only to authorised administrators. Secret controls accept replacements and show only that a value is configured; do not render stored secrets back into HTML or JSON. Empty replacement fields mean unchanged; disconnect/clear is explicit.

Store sensitive credentials and OAuth tokens encrypted at rest using authenticated encryption and a maintained cryptographic library. Keep the encryption key outside the database and repository, supplied through an environment secret or protected server file. Key provisioning, backup and rotation are deployment requirements. Fail closed if the key is unavailable. Joomla component parameters alone are not secure secret storage: they may hold non-secret settings or references, not plaintext credentials.

Use HTTPS with certificate verification, bounded timeouts, OAuth state validation, coordinated token refresh, atomic token-pair replacement, and redacted operational errors. No credential values in the browser sketch, source control, build artefacts or CI fixtures. A new installation starts disconnected; setup must not contact or configure a production account automatically.

### Mobile acceptance

Composer, filtering, both language editors, testing, scheduling, confirmation and draft recovery must work at 320–390px widths, portrait and landscape, keyboard and touch. Maintain visible labels/focus, adequate tap targets, local scrolling for wide tables and no page-wide horizontal overflow. Preview follows the active task on narrow screens; important controls must remain reachable with the software keyboard open. Backend settings and audit views must also remain usable within Joomla's responsive administrator template.

### Added CI acceptance cases

- SoMe group can use newsletters but cannot invoke club/team sending through direct requests unless separately granted.
- Coach group can use team news and only configured recipient scopes; manipulated and omitted tag selections cannot broaden access.
- Board group can use all configured types and explicitly permitted all-member scope.
- Multiple memberships, explicit denies, revoked access, another user's draft, and edit-own versus edit-any permissions.
- Audit success, failure, denial and configuration events; verify credentials and tokens never appear in audit/error output.
- Encrypted secret storage, masked backend responses, missing-key failure, OAuth state validation and concurrent refresh handling.
- Mobile end-to-end compose/test/schedule flows alongside installation and seeded-version upgrade checks.

These are planned requirements, not implemented capabilities of the static prototype.

## CleverReach API reference supplied by user

Primary reference: https://developers.cleverreach.com/docs/api-categories/introduction/ (reviewed 28 September 2026).

Reviewed endpoint/guide pages:
- Authentication: https://developers.cleverreach.com/docs/guides/authentication/
- Create mailing: https://developers.cleverreach.com/docs/api/main/createmailing/
- Preview: https://developers.cleverreach.com/docs/api/main/mailing-send-preview/
- Filter updates: https://developers.cleverreach.com/docs/api/main/filter-update-filter/

The current API overview documents core REST v3 with OAuth 2.0. The creation example supports HTML/text content and a filter-based audience, consistent with the planned workflow.

Important delegation constraint confirmed by the preview reference: preview recipients must be users of the connected CleverReach client/account. Joomla membership or Intercom permission alone does not make a user's address eligible. Backend setup and onboarding must expose this requirement; a denied preview must keep final release blocked. Do not automatically create CleverReach account users or expand permissions as part of ordinary Intercom access delegation.

Use documented response contracts for the client and fake API, not the legacy convention that every null response is success. The pages reviewed do not establish safe filter-reuse timing or the legacy release endpoint's complete contract. Release/scheduling, cancellation, BCC support and recipient-evaluation timing remain explicit integration-verification items. Do not infer unsupported behaviour from absence in the documentation navigation.

The authentication guide recommends an official PHP SDK. Evaluate its PHP 8.3/Joomla compatibility, dependencies, error handling and endpoint coverage before selecting it; keep application services behind an interface regardless of transport choice.

## Native multilingual, repository and CI decisions

Confirmed: native Joomla multilingual sites with complete en-GB and da-DK interfaces; Git repository; GitHub Actions for CI; Joomla Scheduled Tasks is available.

### Joomla languages

Ship frontend, administrator, installer/system and task-plugin language resources for en-GB and da-DK using Joomla language INI files and translation APIs. English is the source/fallback; Danish has matching keys and placeholders. JavaScript messages are supplied through Joomla's script-language mechanism. No private language cookie or session switch replaces Joomla language selection.

Respect the active site language, native language switcher, language-filtered menu items and routes; respect the administrator's own language in backend views. Both language-specific menu entries open the same authorised draft and preserve its ownership/revision. Interface language is independent of the two email-content variants and CleverReach recipient language. Changing the UI language must not discard unsaved edits: persist them or prompt before navigation. Store audit events as stable codes plus structured data and translate their display.

CI checks INI validity, key/placeholder parity, absence of untranslated keys, both frontend/backend locales and native language-switcher navigation on a bilingual Joomla installation. Test longer labels and both locales on mobile. Reference: https://manual.joomla.org/docs/next/general-concepts/multilingual/language-files/

### Package and background work

Plan a Joomla package containing com_intercom and plg_task_intercom. The task plugin delegates to the component services for bounded, resumable reconciliation and reservation maintenance. Jobs must be idempotent and safe against overlapping execution; audit background activity and surface failures. Installation/upgrade tests cover the complete package and plugin discovery.

The presence of Scheduled Tasks does not establish its invocation frequency or exact-time guarantees. Document the production trigger method and frequency during deployment. Keep CleverReach scheduling versus Joomla-owned dispatch as an explicit integration decision; do not build two independent senders for the same message.

### GitHub Actions plan

Run on pull requests and pushes. Use minimal read-only job permissions and pinned action revisions; no production CR credentials or emails in ordinary CI. Composer lockfile and repeatable build scripts define the tested dependencies.

Stages: PHP lint/PSR-12/static analysis; unit tests with fake API; database/concurrency integration tests; install built package into clean Joomla; upgrade seeded prior release and compare schemas/data; multilingual and responsive browser tests; publish ZIP and diagnostic reports as workflow artefacts. Start with PHP 8.3 and add newer PHP versions supported by the selected Joomla release. Include compatibility plugins disabled. Baseline database/version and prior-release fixtures still need selection. Before a prior release exists, use a clearly labelled migration fixture; do not claim released-version upgrade coverage prematurely.

Local Git is separate from a GitHub remote. Initial setup does not create/push a GitHub repository or activate Actions; that follows when the remote is chosen and real build/test commands exist. Do not add a placeholder green pipeline that claims unimplemented tests.

## GitHub remote, release distribution and credential-envelope review

Connected local origin to https://github.com/FKTrekanten/InterCOM.git. Remote HEAD lookup succeeded but returned no advertised HEAD. No commits, pushes or releases were made in this step.

Confirmed distribution: GitHub Releases in FKTrekanten/InterCOM host the built Joomla package ZIP. Plan a stable public update XML URL in this repository, with version-specific release-asset download URLs, package identity, supported Joomla versions, PHP minimum 8.3 and package checksum. Joomla needs this XML feed registered in the manifest; a GitHub repository or releases page alone is not an update server. Only update the feed after the matching tested release asset is available. Never point installation at GitHub's automatically generated source-code ZIP. Validate the complete update discovery/download/install path in CI. The exact stable feed URL is to be set when its branch/path exists.

Credential approach described by the user: one provider row with a versioned encrypted envelope, separate non-secret configuration, XChaCha20-Poly1305 with random nonce, context-derived site-secret key, provider name as additional authenticated data. This is a sound design for encryption at rest against database-only disclosure, subject to reviewing the actual derivation and envelope code. It is not a reviewed implementation yet.

Recommended Intercom adaptation: use Sodium with 32-byte keys and fresh 24-byte random nonces; derive with HKDF-SHA-256 and a stable Intercom-specific context (never reuse LedgerLane's context). Authenticate an unambiguously encoded component/purpose, envelope version, expected provider and immutable connection identifier, deriving the expected identity from the selected record rather than trusting envelope-supplied identity. Allow only supported versions/algorithms and validate decoded field lengths; fail closed on any authentication/decode error. Encrypt tokens as well as the client secret, keep token-pair writes atomic, and retain masked backend fields/redacted audit rules.

Site-secret tradeoff: protects a database-only export while configuration.php remains private. A full backup containing both the database and site secret, or PHP execution compromise, exposes the credentials. Site-secret change breaks decryption without migration; cloned sites carrying the same secret and database can decrypt production credentials. A separate deployment key permits independent rotation and backup separation but does not defeat compromise of the running PHP application. Keep separate-key storage as the stronger deployment option; adoption of site-secret derivation is under discussion, not silently selected.

Additional authenticated data prevents moving an envelope between differently bound identities, but does not prevent restoring an older valid envelope for the same identity. Key version/rotation and restore/reconnect procedures must be documented. Require ext-sodium at installation and never use a plaintext fallback.

Sources: https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php ; https://www.php.net/manual/en/function.hash-hkdf.php ; https://manual.joomla.org/docs/4.4/building-extensions/install-update/update-server/

## Joomla 6.1 reference and future integrations

User-supplied implementation reference: https://manual.joomla.org/docs/ (Joomla 6.1 documentation). Use the version-specific 6.1 documentation when implementing APIs rather than inadvertently following next/unreleased documentation. PHP minimum remains 8.3. Minimum installable Joomla version must be declared explicitly and covered in CI; using 6.1 as the implementation baseline avoids claiming untested 6.0 compatibility.

Future scope: integrate the club's customer/member system to retrieve member data, and Postmark for delivery. These may complement or replace CleverReach. Neither integration is included in v1.

Architectural adjustment:
- Keep the canonical communication model independent of CleverReach: bilingual content, communication type, audience criteria, user/role scope, tested revision and send intent.
- Separate member/audience data access from delivery. The current CleverReach adapter may fulfil both roles; a later member-system adapter supplies identities, attributes and preference data while a Postmark adapter supplies delivery.
- Separate local audience authorisation from provider-specific filter syntax. Local team/group identifiers will need explicit mapping to provider tags or member-system identifiers; avoid leaking CleverReach filter IDs into permission definitions.
- Keep provider-specific remote IDs, filter leases, template variables, schedules and response handling in integration code. Shared services enforce ACL, validation, revision approval and auditing.
- Model provider capabilities explicitly where needed: provider-side segmentation, previews, scheduling, status reconciliation and cancellation are not assumed to be universal. Do not force a future provider to emulate unsupported CleverReach features or silently weaken safeguards.
- Bind each prepared/tested revision and send attempt to its selected provider and audience source. Changing either requires revalidation and retesting. Uncertain delivery through one provider must never trigger automatic failover through another: that risks duplicate messages.
- Keep local event IDs and provider message IDs together in audit records. A later integration can add per-recipient delivery events without replacing the communication history.
- Provider-specific credentials use the same envelope storage service with distinct authenticated identities; never reuse CleverReach credentials for another provider.

V1 remains a single CleverReach workflow with injected audience and delivery interfaces and fake adapters for tests. Do not add unused Postmark SDKs, configuration panels, database integrations or a generic workflow engine now. Verify that the interfaces can be exercised without CleverReach-shaped domain objects through tests.

Before the later integrations: establish the member-system API and stable IDs, authoritative communication preferences, identity/email deduplication, bounce/unsubscribe synchronisation, template rendering and personalisation ownership, scheduling ownership, and rules for selecting a delivery provider. A switch away from CleverReach must preserve existing opt-outs; retrieve member data without interpreting it as permission to reactivate subscribers. Postmark capabilities and account suitability will be checked against its current official documentation when that version is scoped.

## Local development platform

Confirmed: Rancher Desktop is available for the local development stack. Plan a Compose-based environment for Joomla 6.1/PHP 8.3+, the selected database, and a fake CleverReach service. Keep the environment reproducible through versioned configuration, seeded synthetic data and documented build/install/test commands. CI should reuse the container definitions and scripts where practical rather than depend on Rancher Desktop itself.

Before bringing up the stack, inspect Rancher Desktop's configured container engine and available Compose tooling; do not silently change its engine or global settings. Choose container images compatible with the developer machine's architecture and GitHub Actions runners. Pin tested image versions. Database engine/version remains to be confirmed against production.

Bind development services to localhost, keep volumes for local persistence with an explicit reset command, and exclude environment secrets and database dumps from Git. Default to the fake CleverReach integration and synthetic accounts. Configure Joomla Scheduled Tasks in the local setup and provide a deterministic way to invoke jobs during tests. Document frontend/administrator URLs, language setup, sample permission groups and package installation/upgrade commands. No containers were started or Rancher Desktop settings changed in this planning step.


## Development implementation — 29 September 2026

The first executable component, maintenance plugin, isolated Compose stack, local CI and GitHub Actions workflow are implemented. PHP minimum is 8.3. MariaDB 10.6 is the test database. Credential encryption uses the Joomla secret; retention defaults to 30 days. All automated delivery tests use an in-process fake provider. CI includes actual Joomla install and schema migration, preservation of audit/draft/credential data, scheduler dispatch and authenticated HTTP boundaries. The pre-release migration fixture is synthetic, not a claim of upgrading a previously released package.

The release script prepares reviewable version/changelog changes and only publishes with --publish. It checks local CI, remote CI, downloaded asset checksums and public update-feed content. Initial milestone remains a development build: live one-recipient CR acceptance, remote reservation reconciliation, complete core permission-change audit integration, richer audience management, branded rich-text email composition and complete multilingual-site browser coverage remain before production readiness. See README for reproducible commands and operational limitations.
