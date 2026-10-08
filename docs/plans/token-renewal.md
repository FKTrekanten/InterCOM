# Scheduled CleverReach token renewal

Status: implemented in the working tree. Automated verification covers idle renewal, token rotation/recovery and backend details. Controlled CleverReach acceptance and deployment remain separate steps; no production token renewal has been performed.

## Objective and assumptions

Keep InterCOM connected through months without newsletter activity by renewing credentials through server maintenance. Access tokens still expire; scheduled renewal replaces them before that happens.

The operational lifetime supplied for this installation is 30 days. Previous tests suggest the refresh token remains usable for less than 14 days after access-token expiry. Treat that observation as an uncertain recovery window, never as a scheduling allowance. Each successful renewal returns a new refresh token, which must become the credential used for the next renewal.

[CleverReach's authentication documentation](https://developers.cleverreach.com/docs/guides/authentication/) describes renewal with refresh tokens and says they have a longer lifetime than access tokens. It does not guarantee a specific grace period. Before rollout, verify that this installation accepts renewal while the access token is still valid and that the returned pair has the expected lifetime.

## Renewal policy

- Run the existing InterCOM maintenance task regularly through a real server scheduler, independent of website visits and newsletter activity. Prefer an hourly run, with token checks normally gated to once every 24 hours.
- Renew when seven days or less remain. For a 30-day token, the first renewal is approximately day 23; the next renewal is approximately 23 days after the successful exchange.
- Calculate expiry from the provider's returned `expires_in`, using the configured import lifetime for manually imported credentials. Store a renewal deadline when each pair is accepted. Bound the advance window for shorter lifetimes so a short-lived token does not trigger continuous renewal.
- On installation or upgrade, check the existing expiry immediately. Renew an already-due token on the first maintenance run, including an expired token if its refresh token still works.
- Share renewal coordination with the existing on-demand token path. Newsletter actions remain a fallback if maintenance has missed a run; they cannot start a second exchange while renewal is in progress.
- Missing refresh credentials should produce an actionable connection status before expiry. Fake mode and an unconfigured connection must not make provider requests.

The normal idle sequence is: daily local check, renewal around day 23, store the new pair and expiry, repeat. Two or more months without a mailing therefore do not interrupt renewal.

## Safe refresh-token rotation

The existing `Connection::token()` holds a database transaction across the exchange and account validation, then saves credentials. A successful provider exchange followed by validation failure, rollback, or a crash can lose the newly rotated refresh token. Scheduling alone does not fix this.

Implement a small, durable renewal state machine:

1. Claim a renewal attempt under the connection lock, recording the credential generation and attempt identity. Commit the claim before contacting CleverReach. Scheduled renewal, manual credential changes, and on-demand renewal must share this coordination.
2. Exchange the current refresh token once. Use bounded network timeouts and classify confirmed rejection separately from an unknown outcome, such as a timeout after the request may have reached the provider.
3. On a successful response, immediately persist the complete new access/refresh pair and expiry together in an encrypted pending envelope, conditional on the claimed generation. Commit this before making further provider requests.
4. Validate the new access token against the already-pinned CleverReach account. Promote the pending pair atomically after validation. Never silently change the connected account during renewal.
5. If validation temporarily fails, retain the pending pair and resume validation later. Do not repeat the refresh exchange using the previous token. If the account differs, quarantine the pair and require administrator intervention.
6. Record success and the next renewal deadline, then release the claim. Never put tokens, client secrets, or raw provider responses into logs or public status.

An interrupted claim must not simply expire and permit replay of the old refresh token. Resume from a saved pending pair when available; otherwise mark the outcome uncertain and require recovery. Manual reconnect/import must explicitly supersede renewal state so a delayed response cannot overwrite newer credentials.

There is an unavoidable gap between provider rotation and receiving/durably saving its response. A lost response or database failure in that gap can require reconnecting unless CleverReach supplies a documented recovery mechanism. The design must report this honestly rather than assume the previous refresh token remains usable.

## Scheduler and failure handling

Add a dedicated connection-maintenance phase to `Workflow::maintain()`, wired through `Runtime`, that runs even when there are no pending mailings, archives, or filter jobs. Give it a bounded share of the existing maintenance budget and apply the remaining budget to network calls. Its failure must be logged through the existing task failure mechanism while allowing the other maintenance phases to proceed.

Persist non-secret health and scheduling metadata with the connection, rather than component settings that an options save could overwrite. Track last check, last successful renewal, next check, next renewal deadline, access expiry, current state, and a sanitized error category.

Retry failures that are confirmed safe to retry, with an initial target of the next hourly run and a maximum six-hour backoff while renewal remains due. A response that might have rotated the token needs recovery handling, not automatic replay. Rejected/revoked refresh credentials require reconnecting. Preserve a still-valid active token during recoverable failures, but never extend its expiry locally.

Expose administrator-only status on the dashboard/settings: connected, renewal due, renewal in progress, retry pending, or reconnect required, with relevant dates. Warn when maintenance has not checked the connection for more than 48 hours. Document server monitoring for missed task runs and renewal failures; a dashboard warning alone cannot notify an administrator who never visits the site.

## Backend options connection details

Show connection details in InterCOM's backend options alongside the CleverReach configuration. Display the configured client ID in readable form and the verified, pinned CleverReach account ID. Keep client secrets and access/refresh tokens hidden. Read these details from the existing connection credentials and identity, without requiring another provider request just to open options.

For a healthy connection with automatic renewal configured and maintenance running, show localized text equivalent to:

> Connected to CleverReach account xxxxxx. The token is valid until Saturday, 07 November 2026 10:25 and is renewed automatically before then.

Use the actual stored `expires_at` value, formatted with the administrator's Joomla language and configured timezone. Label the timezone beside the date so the displayed expiry is unambiguous. Provide both English and Danish translations, including localized weekday/month names.

The automatic-renewal wording must reflect the actual status. If refresh credentials are missing, maintenance is disabled or stale, renewal has failed, or reconnection is required, replace that assurance with the relevant status and action. An expired token must be described as expired, and an unconfigured or unverified connection must not be described as connected. Show the last successful renewal and next planned renewal as supporting details when available.

After successful renewal, opening or refreshing options must show the newly stored expiry and renewal dates. Saving unrelated options must preserve credentials and renewal metadata.

## Implementation scope

- `Service/Connection.php`: shared renewal policy, durable coordination, encrypted pending credentials, promotion/recovery, and structured exchange outcomes.
- Connection database migration: renewal state and health metadata; preserve the existing encrypted credentials and account pin.
- `Service/Runtime.php` and `Service/Workflow.php`: independent connection phase and budget integration.
- Task integration, administrator connection status, backend options client ID/account/expiry details, Danish/English strings, and operational documentation.
- Focused connection and scheduler tests, followed by the repository's required CI and release checks.

## Verification and rollout

Use a controllable clock and a fake provider that rotates refresh tokens and accepts only the newest one. Cover at least 90 days without sending a mailing: renewals happen before expiry and every exchange uses the pair saved by the previous one.

Also verify concurrent scheduler/newsletter requests produce one exchange; validation failure recovers from the saved pending pair; interrupted/ambiguous exchanges do not replay stale refresh tokens; manual reconnect supersedes an attempt safely; account mismatch remains blocked; rejected refresh credentials become actionable; and fake/unconfigured mode makes no provider calls. Scheduler failure tests must show other maintenance work continues within the shared time budget.

Verify backend options displays the actual client ID and pinned account, formats expiry correctly in English/Danish and the configured timezone, updates after renewal, and replaces the automatic-renewal assurance for unhealthy/disabled states. Confirm rendered options and status payloads contain no client secret or token values, and unrelated options saves preserve renewal state.

Before production deployment, perform one controlled renewal against the intended CleverReach connection to confirm pre-expiry renewal, returned lifetime, refresh-token rotation, and account identity. Do not infer refresh-token lifetime from an example response. Confirm InterCOM exclusively controls its credential pair so another integration cannot independently rotate the same refresh token.

For production deployment, install the migration and code, confirm the server scheduler is enabled, run maintenance once, and verify the recorded expiry, next renewal date, and healthy task result. Publish the package through the normal release process after automated verification; production acceptance remains a deployment step.
