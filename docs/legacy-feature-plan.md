# Intercom: editable communication groups and remaining legacy features

Plan prepared 30 September 2026 against development version 0.1.6. Implemented in development version 0.2.0; acceptance results and remaining limitations are recorded in README.md.

## Outcome

Restore the legacy rich-text email composition, branded bilingual template, first-name placeholders, and board archive copy. Replace fixed communication types with administrator-managed communication groups. Let administrators choose which CleverReach audience tags appear in searchable frontend dropdowns.

Use “communication groups” in the component UI for Club information, Team news, License information, Newsletter, and additional types. These are distinct from Joomla user groups, CleverReach recipient lists, and recipient tags.

## 1. Component administration and Options

Add native Joomla component views for:

- **Communication groups:** paginated list, New/Edit, publish/unpublish, order, archive, and delete unused records.
- **Recipient tags:** refresh the CleverReach tag catalogue, choose visible tags, and edit their presentation/order by parent prefix.
- **Audience access:** replace the current JSON input with Joomla-group and allowed-tag selectors, preserving the existing rules.
- **Audit history:** keep the existing paginated audit and reservation views.

Options continues to contain operational settings: CleverReach connection/tokens, recipient-list selection, sender, filter cap, retention, board archive address, release verification, and component-wide Joomla permissions. Move the current per-type CleverReach category fields into their communication-group records.

Only authorised administrators can manage these records. Opening the audit dashboard alone must not grant settings access. Record creation, changes, publication, deletion, permission changes, and tag refresh results in the audit log.

## 2. Editable communication groups

Store a communication group as a database record with:

| Field | Purpose |
| --- | --- |
| ID and immutable key | Stable identity for drafts, upgrades, and audit |
| Published state and ordering | Availability and frontend order |
| Suppression word | Value excluded by the CleverReach suppression filter |
| CleverReach category ID | Existing mailing/report categorisation |
| Require a recipient group | Preserve the Team news requirement without hard-coding its key |
| Joomla asset ID | Permissions for composing and sending this group |
| Definition revision | Detect changes that require a new test |

Store translations separately by Joomla language tag. Provide a language tab for each configured site content language, initially Danish and English. Each translation includes the requested **name and description**, plus the legacy **subject prefix and email heading** so those can also be edited rather than hard-coded. Show missing translations clearly; require a name in the site's default language and use that as the documented fallback.

The interface language determines the displayed group name and description. The email remains Danish/English in this milestone, with recipient language deciding which version CleverReach displays. Additional interface languages do not implicitly add new email-body variants.

Require a non-empty suppression word and reject comma-separated values, control characters, and ambiguous duplicates. Use the existing `suppression` custom field and `NOCONTAINS` rule with the configured word. Club information continues to respect opt-outs as agreed. Editing a display name does not rename member preference values in CleverReach; changing the suppression word warns that member preferences may need a separate migration.

Use native Joomla permissions on each record, with component-wide access/compose/send as prerequisites. Register generic record actions for composing/sending a communication group, distinct from the component prerequisites; each record supplies their grants rather than generating new action names for every type. This prevents a global compose grant from automatically granting newly added types. Newly added groups have no delegated access until configured. Preserve explicit denies and multiple-group inheritance. Never infer permission from a matching name or suppression word.

Removing a used group means unpublishing/archiving it. Retain its historical identity and snapshots. Permit permanent deletion only for unused records. Unpublishing blocks new previews/releases; mailings already released or scheduled in CleverReach require separate reconciliation/cancellation.

## 3. Tag catalogue and frontend dropdowns

Fetch CleverReach tags into a local catalogue associated with the configured provider/list context. Keep canonical values such as `group.Youth` and `membership.Active` intact. The refresh reads available tags; it does not create, rename, or remove member tags in CleverReach. If the API supplies account-wide tags, do not represent them or their counts as a verified audience for the selected list.

Administrators choose which individual tags are shown. New tags discovered later start hidden. Mark vanished tags as unavailable, retain their historical references, and require the sender to revise/retest a draft using them. A failed refresh preserves the previous catalogue with a visible timestamp/error; live preview still validates current tag availability.

Confirmed layout is one searchable multi-select for **group.*** and another for **membership.***, separated by the prefix before the first dot. Deeper values stay in the corresponding dropdown; they do not create extra controls or filtering constraints.

Dropdowns support searching, multiple selections, removal, native keyboard operation, mobile interaction, and restoring saved selections. Use Joomla's supported select enhancement where it provides the required behavior; use Joomla Web Asset Manager for all scripts/styles. Show friendly suffix labels while storing the complete canonical tag.

The visible group choices are the intersection of administrator-enabled tags and the signed-in user's permitted recipient scope. Validate the same restrictions server-side on save, preview, and release. Enabling a tag for display does not grant access to it. Membership choices are also validated against the configured visible catalogue.

Preserve legacy filtering: OR within selected group tags, OR within selected membership tags, AND between those sets and age/gender/suppression. An empty group selection is allowed only for explicit all-member access and communication groups that do not require a group. Disabling a tag must never silently broaden a stored draft to all recipients.

## 4. Rich-text composition and the email template

Use Joomla's editor integration with a constrained email toolbar: headings, bold/italic/underline, lists, links, and clear formatting. Add the legacy first-name insertion with Danish/English fallback values. Avoid loading the old editor and dropdown libraries from external CDNs.

Sanitise HTML on the server with an email-specific allowlist independent of the editor or user's Joomla filtering exemption. Remove scripts, event handlers, unsafe URL schemes, unsupported styles, and arbitrary provider directives. Support explicitly approved placeholders, including the first-name tokens. Store sanitised HTML and generate a readable plain-text alternative; retain support for existing plain-text drafts.

Port the existing newsletter template into packaged component assets: club header/logo, bilingual body, group-specific email heading and subject prefix, why-you-received-this footer, online version, and unsubscribe links. Review template URLs and email-client styling during the port. Resolve names/footers safely and preserve member opt-outs.

Use one renderer for mailing content and the composer preview so their layout agrees. The browser preview uses sanitised content in an isolated frame and remains illustrative for provider personalisation. Verify actual Danish/English output, placeholders, unsubscribe links, mobile rendering, and dark-mode behavior through the isolated CleverReach test list.

Keep the approved three-step composer and Sixte styling: steel `#14181B`, `#262D32`, `#4A545C`, divider `#CFD5D9`, chalk `#F1F4F4`, and club email navy `#172534`. The application uses inherited/system typography; the email uses Arial. The persistent club email preview is the identifying feature. On mobile it follows the active form panel, with dropdowns and editor toolbars wrapping within the page.

## 5. Board archive copy

Add an optional, validated board archive address in Options. Restore the legacy intent of copying final communications to the board, including scheduled sends, without adding the board to ordinary previews.

First verify whether the current CleverReach API supports the legacy `bcc_email` payload and its delivery/scheduling semantics. If supported, use that contract. Otherwise implement a separately audited archive-copy operation with clear retry/status handling; a failed archive copy must never trigger a second member send. Match the archive content to the approved draft revision and identify the sender/type/audience criteria without including recipient address lists.

Automatic CI uses fixtures. Controlled live acceptance keeps the archive option disabled to preserve the one-recipient test boundary; any real board delivery requires explicit authorisation.

## 6. Upgrade, draft integrity, and audit

Create native SQL migrations for communication groups/translations and the tag catalogue/settings. Seed the current five types, preserving `club`, `class`, `license`, `newsletter`, and `offer`. “News” can be the editable display name for the existing `class` key. The user's four examples are not treated as an instruction to delete the existing Offers type.

Copy existing per-type category IDs and audience rules into the new records. Migrate existing type permissions into record assets, accounting for inherited grants and explicit denies; test effective access before/after rather than only comparing raw rules. New types must not inherit an accidental broad delegated grant.

No network calls during package installation. The first administrator catalogue refresh proposes available tags; an explicit save enables the choices. Preserve existing drafts and operational reservations. Historic revisions remain readable even if the referenced group/tag is archived.

Snapshot the communication definition, translations needed by the renderer, suppression word, audience criteria, and template version with each tested revision. A relevant change invalidates the test and requires review/retest before release; recheck current publication, permissions, and tag restrictions as well. Use revision/conflict checks for administrator edits so concurrent changes do not overwrite one another silently.

Audit all new management and workflow transitions using stable event names and structured changes. Record secret-free before/after metadata and configuration revisions. Keep the configured retention policy.

## 7. Implementation order and acceptance

1. **Data and upgrade foundation:** schema, translated group records, Joomla assets/permissions, backward-compatible draft lookup, preservation tests.
2. **Administration:** communication-group CRUD, translation tabs, category fields, audience access editor, audit. Remove fixed type/category controls only after migration succeeds.
3. **Recipient selection:** catalogue refresh/visibility, searchable dropdowns, saved-selection restore, server policy checks, mobile behavior.
4. **Composition:** Joomla editor, sanitiser, placeholders, branded renderer, plain-text output, matching isolated preview.
5. **Archive copy:** verify provider contract and implement the validated delivery path.
6. **Acceptance and packaging:** full local CI, GitHub Actions on main, dev install/upgrade, controlled live tests, update changelog/version, and release preparation through the existing script.

Meaningful tests cover permission preservation and explicit denies; new/archived groups; translations/fallback; suppression words independent of display names; hidden/stale/manipulated tags; OR/AND filter behavior; edited configuration requiring retest; unsafe HTML; placeholder and template output; archive failures without duplicate sends; and install/upgrade preservation of credentials, drafts, audit, and reservations. Browser checks cover Danish/English Joomla language switching, dropdown keyboard use, and 320–390px mobile layouts.

The existing issues remain dependencies for full production acceptance: [release preflight #1](https://github.com/FKTrekanten/InterCOM/issues/1), [filter reconciliation #2](https://github.com/FKTrekanten/InterCOM/issues/2), [delegated-user preview #3](https://github.com/FKTrekanten/InterCOM/issues/3), and [list-bound forms #4](https://github.com/FKTrekanten/InterCOM/issues/4). A browser preview and rich-text editor do not resolve the missing delivered-preview problem. Member-system integration, recipient synchronisation, and Postmark remain later-version work.

## References

- Existing component and legacy source were inspected locally; historical source comments are migration evidence, not new user requirements.
- [Joomla record assets and native permission forms](https://manual.joomla.org/docs/next/general-concepts/table/advanced-table/) describe the record permission model. The current manual points to an upcoming version; implementation must verify against the installed Joomla 6.1.3 source and CI.
- [Joomla list field](https://manual.joomla.org/docs/next/general-concepts/forms-fields/standard-fields/list/) and [standard fields](https://manual.joomla.org/docs/next/general-concepts/forms-fields/standard-fields/) describe native list enhancement, language selection, and editor fields.
- [CleverReach preview issue](https://github.com/FKTrekanten/InterCOM/issues/3) and [forms migration issue](https://github.com/FKTrekanten/InterCOM/issues/4) capture verified integration gaps from the dev acceptance run.
