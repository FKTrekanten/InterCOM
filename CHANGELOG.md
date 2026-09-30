# Changelog

## 0.2.0

- Add administrator-managed communication groups with translated names, descriptions, subject prefixes, headings, suppression words, publication/order, categories and native record permissions.
- Migrate existing categories and type grants, preserving explicit denials, drafts, reservations and encrypted credentials. Retain used group identities; reject stale edits.
- Add list-specific recipient tag visibility and structured Joomla-group audience grants. Show one searchable team dropdown and one membership dropdown with server-side validation.
- Restore Joomla rich-text editing, first-name insertion, the branded bilingual email template, safe HTML/plain-text rendering and a matching isolated browser preview.
- Bind tests to current group/tag/template/settings revisions. Changes require review and a new test before release.
- Add an optional separately audited board archive outbox through Joomla mail after provider completion. SMTP uncertainty never triggers automatic resend.
- Extend unit, database, authenticated HTTP, install, upgrade and scheduler checks. Existing production acceptance issues remain open; no public release is published by this change.

## Unreleased

- 0.1.6 development: retire unused manual filter IDs, remove their Options control, and reserve only Intercom-owned filters. Clarify CleverReach preview eligibility and the transition to list-bound forms.

- 0.1.5 development: select the recipient list from CleverReach and create Intercom-owned filters on demand within a configurable cap.

- 0.1.4 development: restrict draft reservations to filter IDs approved in Options and retire unused, unlisted pool IDs when settings are saved. Preserve historical reservations for audit.

- 0.1.3 development: direct signed-out frontend visitors to native Joomla login and preserve their return URL, while retaining permission checks for signed-in users.

- 0.1.2 development: move configuration and connection controls to native Joomla Options, with transactional validation through the bundled extension plugin.
- Add encrypted manual access/refresh token import with explicit lifetime, access-only support and secret-free audit events.
- Paginate audit history with selectable page sizes and native Joomla controls.

- 0.1.1 development: distinguish simulated and live reservations so test mailings cannot block OAuth credential setup. Protect drafts against delivery-mode changes.
- Replace the initial continuous form with the approved three-step composer, communication cards, language controls and branded preview.
- Preserve special characters in OAuth credentials, reject the synthetic fixture on Connect, and guard disposable test data against accidental use on the persistent development site.

- Native Joomla 6.1 component and Scheduled Tasks plugin.
- English and Danish interfaces, group permissions and audience scoping.
- Versioned drafts, test-before-release workflow and audit retention.
- Site-secret-derived authenticated encryption for CleverReach credentials.
- Local MariaDB/Joomla stack, CI and gated release tooling.

This remains a development build. One isolated CleverReach mailing was delivered, but delegated-user previews and automated release preflight remain open.
