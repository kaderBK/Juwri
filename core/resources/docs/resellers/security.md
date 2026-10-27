---
title: "Portal security and two-factor authentication"
audience: reseller
group: Resellers
updated: 2026-07-09
---

# Portal security and two-factor authentication

A reseller owner can enable TOTP-based two-factor authentication on their portal
account. Once enrolled, a per-session challenge is required before portal access
is granted. The feature is handled by `SecurityController` under the
`/reseller/security` and `/reseller/2fa` route groups.

## Enrollment

1. **Enable** (`POST /reseller/security/2fa/enable`, route
   `reseller.security.2fa.enable`) — generates an unconfirmed TOTP secret and a
   set of recovery codes. Returns a QR code SVG for scanning with an
   authenticator app.
2. **Confirm** (`POST /reseller/security/2fa/confirm`, route
   `reseller.security.2fa.confirm`) — validates a TOTP code against the pending
   secret. On success the secret is marked confirmed and the session flag
   `reseller.2fa_passed` is set.

## Challenge

When 2FA is enrolled, the portal requires a per-session challenge before the
owner can proceed:

- `GET /reseller/2fa/challenge` (route `reseller.2fa.challenge`) — renders the
  challenge page.
- `POST /reseller/2fa/challenge` (route `reseller.2fa.challenge.verify`) —
  verifies the code and sets the `reseller.2fa_passed` session flag.

## Recovery codes

- **Regenerate** (`POST /reseller/security/2fa/recovery`, route
  `reseller.security.2fa.recovery`) — issues a fresh set of recovery codes.
  Requires an active enrollment.

## Disabling 2FA

- `POST /reseller/security/2fa/disable` (route `reseller.security.2fa.disable`)
  — disables two-factor authentication. Requires a valid TOTP code or a recovery
  code to confirm.

## Security page

- `GET /reseller/security` (route `reseller.security`) — shows the current
  enrollment state, a QR code SVG if mid-enrollment, and recovery codes.

## Route summary

| Route name | Method | Path |
|---|---|---|
| `reseller.security` | GET | `/reseller/security` |
| `reseller.security.2fa.enable` | POST | `/reseller/security/2fa/enable` |
| `reseller.security.2fa.confirm` | POST | `/reseller/security/2fa/confirm` |
| `reseller.security.2fa.disable` | POST | `/reseller/security/2fa/disable` |
| `reseller.security.2fa.recovery` | POST | `/reseller/security/2fa/recovery` |
| `reseller.2fa.challenge` | GET | `/reseller/2fa/challenge` |
| `reseller.2fa.challenge.verify` | POST | `/reseller/2fa/challenge` |

## Related pages

- [Overview](overview.md) — the onboarding checklist includes a 2FA step.
