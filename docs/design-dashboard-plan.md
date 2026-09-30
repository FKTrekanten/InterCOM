# Intercom: email design, composer behaviour, and backend dashboard

Status: implemented in development build 0.3.0; actual received-email client checks remain acceptance work. Original plan based on 0.2.0. This request changes presentation and navigation while retaining the existing send, suppression, permission, and audit rules.

## 1. Email design and backend editor

Add **Email design** under the component's backend navigation. Provide a Joomla settings form with a Danish/English preview and reset-to-default action. Administrators can edit branding text per site language, the logo URL and size, heading/body font presets, text sizes, header/background/text/accent colours, content width, and spacing. Communication-specific headings remain in the existing communication-group translations.

Initial design:

- Left: small brand line **Fægteklubben Trekanten**, with the communication heading below it, for example **Club News**. Right: the club logo, vertically aligned with the two lines.
- Heading typography follows the approved sketch's Poppins treatment, with a system sans fallback. Body typography follows its system sans stack. Use consistent sizing and line height in the email and the composer editor.
- Palette: navy `#172534` header, steel `#14181B` text, muted steel `#4A545C`, chalk `#F1F4F4` outer background, divider `#CFD5D9`, and Trekanten blue `#0F6B99` accent.
- Preserve the restrained card layout. At mobile widths, allow headings to wrap and reduce logo/spacing rather than forcing the current unbroken heading onto one line.
- Keep the online-version link, bilingual content, first-name placeholders, reason-for-receiving text, and unsubscribe links.

Use an email-compatible table layout with inline styles and explicit fallback fonts. Poppins availability varies between mail clients; the fallback must remain legible and visually coherent. The header's two-line club identity is the signature element; avoid additional decorative elements.

Store validated design settings and their revision in the component database, separately from connection secrets. Limit font and layout choices to supported presets and validate colours, sizes, and URLs. Use the same renderer for backend preview, frontend preview, tests, and delivered mail. Record design saves/resets in the audit log, reject stale concurrent edits, and include the design revision in the draft test fingerprint. A design change therefore requires affected drafts to be tested again before release.

An upgrade seeds these defaults without overwriting existing communication headings or requiring a live CleverReach connection. Future upgrades preserve administrators' saved design choices.

### Dark-mode compatibility

Treat dark mode as an acceptance requirement for the delivered email, not only the browser preview. Provide paired light/dark colour settings and a Light/Dark preview toggle in the backend design editor and frontend preview. The toggle selects the preview appearance without changing what recipients receive; delivered mail responds to the recipient's mail client.

- Define a coordinated dark palette for the outer background, content surface, header, text, links, and dividers. Check contrast for body text, headings, muted footer copy, and unsubscribe links in both themes.
- Add colour-scheme metadata and targeted `prefers-color-scheme` rules where supported, plus appropriate Outlook-specific overrides. Retain explicit light-mode inline styles as the baseline.
- Keep the club logo visible against both palettes and when a client changes background colours automatically. Use an appropriate logo treatment with a protective background/outline if needed; do not depend solely on CSS image swapping. Provide meaningful alternative text when images are blocked.
- Check automatically inverted colours as well as the authored dark palette. Avoid disappearing text, illegible links, invisible logos, and contrasting seams between table cells.
- Verify that CleverReach preserves the required metadata, CSS, and image URLs through its send pipeline.

Client support differs: some clients allow explicit dark styling, while others transform colours themselves. A browser preview checks the authored palette, but cannot establish Gmail/Outlook rendering compatibility. Use actual received-mail checks in Apple Mail, Gmail, and Outlook, covering light/dark mode and mobile where available. Record any unavailable client checks rather than treating them as passed. Reference: [Litmus dark-mode styling documentation](https://help.litmus.com/article/617-dark-mode-builder) and [email-client dark-mode behaviour](https://www.litmus.com/blog/the-ultimate-guide-to-dark-mode-for-email-marketers).

## 2. Localised sender defaults

Provide editable default sender names for Danish and English in the component settings, initially **Fægteklubben Trekanten** and **Trekanten Fencing** respectively. The English default applies to new drafts opened on an English Joomla page; Danish applies on a Danish page. Use Joomla's configured language/fallback handling.

Preserve the sender on existing drafts and any name the author has already edited. Switching body or preview language must not silently overwrite it. Keep the actual mailing sender and the displayed preview sender consistent.

## 3. Preview behaviour

Split envelope updates from email HTML rendering.

- Subject typing updates the displayed subject immediately, including the communication-group prefix, without calling the HTML render endpoint or replacing the iframe.
- Sender typing updates the displayed sender immediately. Changes still mark the draft dirty and invalidate send confirmation/test eligibility as appropriate.
- Body editing triggers a trailing **800 ms debounce** after the last edit. Retain the previous email while typing and show a discreet updating indicator until the latest render completes.
- Type/design changes render the relevant heading/template. Audience summaries update immediately; HTML refreshes only when the rendered footer/content actually changes.
- Keep response sequencing so an older request cannot overwrite newer content. Only replace the iframe when its rendered HTML changes. Switching preview language shows the correct current language and never a stale response from the other language.
- Saving/testing/releasing reads the editor's latest value directly, independent of the debounce. A delayed visual preview must never mean a delayed saved or tested message.

The existing 300 ms editor polling remains compatible with Joomla editor providers; consolidate detected edits into one render scheduler. Preserve protection for edits made while a save or test request is in flight.

## 4. Dropdowns and recipient validation

Style the gender select with the same font, border, padding, height, background, arrow treatment, and focus state as the surrounding controls. Keep it a native select with the existing All/Male/Female values.

For both the group and membership multi-selects, add a deliberate toggle interaction using the supported Joomla/Choices API: clicking the control opens it; clicking its trigger again closes it. Preserve searching, selecting/removing items, outside-click dismissal, Escape, keyboard navigation, and mobile touch behaviour. Search typing and remove buttons must not accidentally toggle the menu.

Use each communication group's existing **requires a team group** setting to guard every route from Recipients to Content or Test/send. Team news requires at least one currently available, permitted `group.*` selection. A membership selection alone does not satisfy this rule. Display a translated inline explanation and focus the group control when navigation is attempted without one. Apply the same guard to step navigation, Next buttons, and restored drafts; permit returning to Recipients to correct a selection.

Keep the existing server-side policy checks and add regression coverage for direct requests that bypass the browser.

## 5. Backend navigation and dashboard

Make the component's default view a dashboard and add native Joomla component submenus:

| Page | Contents |
| --- | --- |
| Dashboard | Statistics and short lists of recent activity |
| Audit history | Full paginated audit log |
| Filters | Full paginated filter pool and reservation history |
| Communication groups | Suppression words, translations, headings, and group permissions |
| Recipient tags | Visibility/order configuration and CleverReach refresh |
| Audience access | Joomla group audience restrictions |
| Email design | Template appearance, branding, and sender defaults |

Keep operational settings—CleverReach credentials/list, sender address, filter cap, retention, and other connection/delivery settings—in **Options**.

Dashboard content:

- Pool statistics for the current recipient list and mode: created/cap, free, reserved/in use, and uncertain reservations requiring attention.
- Draft counts by workflow state, plus accepted submissions over a clearly labelled period such as the last 30 days.
- Latest 10 audit entries and a short list of recently used/reserved filters, each linking to its full page.
- Last tag refresh and relevant outstanding archive/maintenance issues.

Use local persisted workflow data for the initial dashboard. Label accepted submissions accurately; they are not proof of email delivery. Keep fake-mode and historical-list filters distinct from the active live pool. Avoid introducing a CleverReach request on every dashboard visit.

Full audit history retains its pagination and gains practical actor/event/date filtering. The Filters page supports pagination and state/current-list filtering, with draft links where authorised. Dashboard summaries, underlying pages, and direct routes respect existing audit/admin permissions. Use Joomla date localisation and responsive tables/cards.

## 6. Implementation order

1. Introduce persisted design/default-sender settings, revision handling, migration, and fingerprint integration.
2. Build the renderer/header, light/dark palettes, and backend Email design form with bilingual theme previews.
3. Refactor preview scheduling and envelope updates; align editor typography.
4. Fix dropdown styling/toggling and required-team navigation.
5. Extract audit/filter pages, add Joomla submenus, and build dashboard queries/layout.
6. Complete automated checks and desktop/mobile verification in dev.

## 7. Acceptance and verification

- New Danish and English drafts receive the correct default sender; saved/custom sender names survive reloads and upgrades.
- Both language previews use the configured design, correct group heading, requested left text/right logo layout, and unchanged member-preference links.
- Light and dark previews have readable text/links and a visible logo. Automated checks cover theme tokens and required markup; received-email checks verify representative clients, including automatic colour inversion. Preview appearance alone does not count as mail-client verification.
- Typing a subject produces zero HTML preview requests. A burst of body edits produces one render after the pause; stale responses cannot replace the current preview.
- Group and membership dropdowns toggle open/closed by repeated trigger clicks and remain usable with keyboard, search, and touch. Gender styling matches at desktop and narrow mobile widths.
- Team news cannot advance without an allowed team; other groups follow their own requirement setting. Server validation still rejects invalid direct requests.
- Dashboard counts agree with stored records, distinguish active/historical/fake contexts, and reveal only authorised information. Full lists paginate correctly.
- A design change invalidates the previous test approval. Concurrent settings edits are detected and audited.
- Run the existing local CI checks, unit tests, smoke tests, and native install/upgrade tests. Add targeted tests for design validation/fingerprints, dashboard queries/permissions, sender defaults, and preview/navigation behaviour.
- Verify desktop and mobile rendering in dev. An eventual live email rendering check uses only list **758666**, containing the single authorised test recipient, with archive delivery disabled. This planning request does not send email or modify the live list.

Implementation remains on main. No release/version change is needed for this planning step.

## Development verification — 0.3.0

Full local CI passed on Joomla 6.1.3 / PHP 8.3 / MariaDB 10.6: coding style, lint, 33 unit tests (90 assertions), five frontend timing/interaction tests, tooling tests, native install and upgrade, database workflow/design/statistics checks, scheduler and authenticated HTTP smoke tests. A disposable delegated Manager role verifies that dashboard audit data and direct audit/filter/design routes remain protected.

The verified package is installed in the persistent development site. Desktop and 390 px mobile browser checks covered bilingual light/dark previews, saved editor initialisation, subject edits without iframe reload, delayed body rendering, both dropdown toggles, gender styling and required-team navigation. No live email was sent during this implementation. Received-email client checks remain pending.
