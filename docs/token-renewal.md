# CleverReach connection renewal

InterCOM's scheduled maintenance checks the connection daily even when there are no newsletter jobs. For a 30-day access token, renewal is due seven days before expiry, approximately day 23. Renewal uses the provider's returned lifetime and saves the new access and refresh tokens together. Tokens with shorter lifetimes use an advance window of at most one quarter of their lifetime.

## Enable and verify scheduling

1. Enable **Task – InterCOM** under **System → Manage → Plugins**.
2. Create and enable an **InterCOM** maintenance task under **System → Manage → Scheduled Tasks**. Every five minutes is recommended for InterCOM's other recovery/archive work; hourly is sufficient for connection checks and renewal retries.
3. Configure the server to run Joomla's scheduler independently of website visits. For example, invoke the following command from server cron every five minutes, using the site's PHP executable and absolute Joomla path:

   ```sh
   php /path/to/joomla/cli/joomla.php scheduler:run --all
   ```

4. Run maintenance once and open InterCOM's backend Options. Confirm the account and client ID, access expiry, last scheduled connection check, and planned renewal date. Simulation pauses automatic renewal.
5. Monitor Joomla task failures and server cron execution. Backend warnings cannot reach an administrator who never visits Joomla.

The automatic-renewal sentence appears only for a verified, valid connection with refresh credentials, live mode, an enabled task/plugin, a healthy renewal state and a connection check within the last 48 hours. This establishes recent operation; it cannot guarantee future server availability. Dates use the administrator's configured Joomla timezone and language, with the timezone shown explicitly. Client secrets and token values are never included in these details.

## Recovery

- **Retry pending:** A confirmed connection failure or rate-limit rejection can retry safely. Maintenance backs off from one hour to a maximum six hours. The previous access token remains available until its actual expiry.
- **Account validation pending:** The new pair has already been encrypted and committed. Maintenance retries account validation using that pair, without another refresh exchange.
- **Reconnect required / uncertain outcome:** A rejected refresh token, a lost response, or an interrupted exchange can require manual reconnection. InterCOM does not replay the previous refresh token when the provider may already have consumed it. Use **Connect to CleverReach** or import a new complete token pair.
- **Account mismatch:** Automatic renewal cannot change the pinned account. Verify the OAuth application and reconnect deliberately; normal account-change reservation guards continue to apply.
- **Scheduler disabled or stale:** Enable the plugin/task, repair server cron, and run maintenance. Checks older than 48 hours suppress the automatic-renewal assurance.

A successful renewal replaces both tokens and the expiry. A manual reconnect/import supersedes an in-flight attempt, so its late response cannot overwrite the replacement. Renewal commits outside mailing transactions; operations preflight authentication before taking mailing/filter locks. If a token expires after those locks have been acquired, the operation defers renewal rather than risk losing rotated credentials to a later rollback.

Do not share the same refresh-token pair with another integration that independently renews it. Back up the database and preserve Joomla's secret: both active and pending credentials depend on it for decryption.

## Deployment acceptance

Before production deployment, perform a controlled renewal on the intended connection to confirm that CleverReach accepts pre-expiry refresh, returns a fresh refresh token and the expected lifetime, and reports the same account. CI uses synthetic rotating credentials and cannot establish those provider-specific facts. This implementation does not rely on an undocumented refresh-token grace period.
