# List-bound unsubscribe forms

The new CleverReach forms are flows. The catalogue uses `GET https://rest.cleverreach.com/flow/flow` (Flow API base `/flow`), with `playbook=unsubscribe`. Each selected flow must have `playbook: unsubscribe` and `setup.groupid` matching the configured recipient list. A token needs the `forms` scope.

The mailing API continues to use `settings.unsubscribe_form_id`, accepting the full flow UUID as a string. Never cast it to an integer. This behavior was verified against the live dev account; the published v3 examples still show numeric legacy IDs. Intercom verifies the persisted mailing's top-level `unsubscribe_form_id` before requesting preview or release. The HTML and text retain CleverReach's `{UNSUBSCRIBE}` directive. The mailing-level `unsubscribe_link` is a CleverReach routing URL, not proof of the final form destination.

Native Options reloads forms when the list changes. The server independently validates changed live selections, preventing a stale dropdown or forged POST from choosing another list's form. Unchanged selections survive unrelated Options edits during an outage; preparing/sending remains blocked when form validation fails. Existing numeric IDs survive updates and are checked against the legacy list-forms endpoint until migrated. Test approvals are invalidated on update or selection changes. Submitted mailings and live reservations are preserved.

## Development acceptance, 30 September 2026

- List 758666 has one total and one active recipient, zero bounces.
- Its existing `Unsubscribes` flow is `06b3cdcb-acd5-4987-8d4f-47673cd418ab`; the API confirmed its unsubscribe playbook and exact list binding.
- Native Options successfully selected and saved it. Credentials, list, mode and existing submitted reservation were preserved. Ordinary live release remains disabled.
- Acceptance mailing 17448403 was created unsent, verified to contain only this list, updated with the current HTML/text template, and released only after repeating the one-recipient check. CleverReach subsequently reported it finished. Creation/release are audited locally without recipient data or credentials.
- Inbox receipt and opening the new destination require recipient confirmation. Do not submit an unsubscribe as part of this check. Keep issue #4 open and the legacy form intact until that is confirmed. Never delete a legacy form used by another list or historical mailing.

## Sources

- [Flow API schema](https://rest.cleverreach.com/flow/swagger.json)
- [Mailing API schema](https://rest.cleverreach.com/v3/explorer/swagger.json)
- [Create and edit unsubscribe forms](https://support.cleverreach.com/hc/en-us/articles/18262234284818-Create-and-edit-unsubscribe-forms)
- [Select the new form in a mailing](https://support.cleverreach.de/hc/en-us/articles/18694972107666-Newsletter-Editor-Insert-unsubscribe-form-beta-in-newsletter)
