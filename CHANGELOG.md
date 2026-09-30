# Changelog

## 0.3.3

- Replace the numeric unsubscribe input with a CleverReach list-bound form selector; reload forms when the recipient list changes and preserve existing legacy selections during upgrades or provider outages.
- Use the separate Flow API catalogue and pass new form UUIDs losslessly in the mailing API's existing unsubscribe setting. Validate list/type before preparation and verify the saved mailing selection before preview or release.
- Add contract, selector race/error, permission, settings fingerprint and native upgrade coverage for issue #4. Fix native browser textarea line-break validation in footer Options.

## 0.3.2

- Add audited deletion and restoration of owned drafts. Preserve live reservations and operational IDs, reject active/submitted/scheduled/uncertain messages, clear old test approvals, and purge deleted content under configured retention.
- Add a subtle contrasting email footer with a thin blue divider, social links, club address and contact details while preserving member preferences, recipient information, online view and CleverReach unsubscribe links.
- Configure contact/social details and Danish/English profile URLs in native Joomla Options. Snapshot footer settings into drafts and invalidate previous test approvals when they change.
- Extend footer validation/rendering, draft ownership/state/CSRF/retention, native install/upgrade and HTTP coverage.

## 0.3.1

- Move the single default sender name to Joomla Options beside the sender email, preserving existing draft names and migrating the previous English default.
- Add independent check/uncheck-all controls for team and membership visibility, with partial-selection state and unavailable-tag protection.
- Format tag labels and trailing times, support optional translated display names, and preserve original CleverReach filter/permission identities and labels during refreshes.
- Track same-account manual token renewal blocked by live reservations in issue #5; reservations and credentials remain protected.

## 0.3.0

- Add editable, versioned email design and localised sender defaults in the component backend, with Danish/English light/dark previews and contrast validation.
- Align mail typography with the approved design and restore the two-line club/communication heading, with the logo on the right. Preserve bilingual content and preference links.
- Snapshot email design in drafts; audit changes and require saving/retesting after design changes.
- Update subjects/senders without reloading email HTML; debounce body rendering and reject stale responses. Refresh saved content when Joomla editors finish initialising.
- Match gender styling, toggle recipient dropdowns closed on repeated clicks, disable arbitrary tags, and require a permitted team before advancing Team news.
- Add a dashboard and native Joomla submenus, separate paginated audit/filter pages, scoped pool statistics, audit filters, and backend permission checks.
- Extend frontend timing/interaction, unit, database, native install/upgrade and HTTP tests. Mail-client dark-mode and existing production acceptance work remain open; no public release is published by this change.

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
