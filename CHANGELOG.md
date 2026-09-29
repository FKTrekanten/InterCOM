# Changelog

## Unreleased

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

This is a development build. Live CleverReach acceptance testing is outstanding.
