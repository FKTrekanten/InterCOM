# Integrate delivery status and email management with the composer

The interface serves club staff composing bilingual member emails. Its primary task remains choosing recipients, writing and testing; saved-email management belongs alongside that workflow.

## Review

The 0.3.25 additions introduced tinted status surfaces, a heavy status rail, filled navigation tiles and repeated email cards. Their scale and colour competed with the existing composition steps. Placing management controls under the preview also made the list feel attached to the email illustration rather than to the workspace. Operational references occupied the same visual level as user-facing guidance.

## Design decisions

Reuse the existing palette: steel `#14181B`, muted steel `#4A545C`, navy `#172534`, chalk `#F1F4F4`, divider `#CFD5D9`, with the existing error red `#A12D24` reserved for failures. Keep white surfaces and the current 3–4px corner radii. Continue the system sans headings/body and monospace utility text for IDs and counts; introduce no fonts or decorative motifs.

Keep the status directly above the steps. Use a compact heading/action row, the same thin border as the composer and its existing three-pixel notice rail. Errors remain explicit in text and red; their explanation and next step stay visible. Place the technical reference under a native Delivery details disclosure.

Make Your emails a full-width sibling below the composer/preview pair. Use the workflow's underline navigation treatment for the list views, one aligned search row and flat divider-separated records. Align subject/audience, state/date and copy action on desktop; stack them deliberately on mobile. Keep each date/time together when wrapping.

This retains the branded email preview as the distinctive visual focus. The new features use the established control hierarchy rather than adding another visual system.

## Verification

The isolated CI suite passed. Desktop and 390px browser checks verified the compact error state, collapsed/expanded delivery details, list navigation, aligned rows and no horizontal page overflow. Existing send, recovery, copy and permission logic is unchanged. Prepared for release 0.3.26.
