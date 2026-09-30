# CleverReach account identity and renewal

Intercom verifies tokens using the read-only `GET /v3/debug/whoami` endpoint. Its `id` is the CleverReach customer identity; the OAuth application's client ID is not an account identity. Tokens remain encrypted with the Joomla site secret. The verified customer ID is non-secret metadata on the connection row. Provider responses, tokens and personal account details are never included in audit events.

Manual imports and OAuth authorization verify the candidate identity before storing credentials. A replacement for the verified account is allowed with submitted, scheduled or uncertain filter reservations and uncertain filter creations. Reservations and existing test approvals are preserved. Access-only import clears any previous refresh token. A different account is rejected while reservations exist; changing application credentials remains blocked as well. Automatic refresh cannot switch an established account even without reservations.

On upgrade, the identity starts empty. Intercom pins it when using a valid existing token, when clicking **Verify existing account** in Options, or by independently checking the previous token during renewal. Verify the existing connection before expiry. If that token is already invalid and live reservations exist, a candidate token alone is insufficient evidence of ownership. Restore previously verified connection data, or reconcile the old account through the supported operator procedure before an account switch. There is no operator checkbox that bypasses this check.

When an actual account switch is permitted, Intercom retires unused remote filter IDs, disables the old live tag catalogue, invalidates old live test approvals and disables live-release verification. Reconfigure and refresh the catalogue, then complete acceptance for the new account. Merely renewing credentials does not perform any of these operations.

Identity errors, including missing endpoint scope or unavailable CleverReach, preserve the old credentials. The administrator must retry after correcting the connection; Intercom does not assume that the new token belongs to the same account.

CI uses explicit injected provider transports on a disposable native Joomla database. Coverage includes old installation pinning, expired previous tokens, submitted/uncertain reservations, pending creations, mismatched/malformed/unavailable identity, OAuth and automatic refresh, encryption and secret-free audit. HTTP checks enforce admin/POST/CSRF boundaries without contacting CleverReach.

API reference: https://rest.cleverreach.com/v3/explorer/swagger.json
