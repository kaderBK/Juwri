---
title: Staff two-factor authentication
audience: owner
group: Your team
updated: 2026-08-20
---

# Staff two-factor authentication

Staff who sign in to the [staff app](staff-app.md) with an email and password
can add **two-factor authentication (2FA)** using a TOTP authenticator app —
Google Authenticator, Authy, 1Password and the like — for a second layer of
security on their account.

## Enrolling

From the staff app, a staff member starts 2FA setup. The app shows a QR code
(and a secret key for manual entry) to scan into their authenticator. They then
enter the 6-digit code the authenticator generates to confirm enrolment. On
success they receive a set of **single-use recovery codes** — these are shown
once, so they should be saved somewhere safe. A recovery code lets a staff
member back in if they lose their phone.

Once enrolled, the authenticator's rotating 6-digit code is required in addition
to the password at sign-in.

## Notes for owners

- 2FA is an option for **email/password** staff logins. Quick-PIN sign-in on a
  shared device is a separate, device-bound flow and is unaffected.
- Recovery codes are single-use; encourage staff to regenerate them if they run
  low.
- Two-factor protects the individual staff account. It complements — it does not
  replace — good role and permission hygiene (see [Staff and roles](staff.md)).

## Related pages

- [Staff and roles](staff.md)
- [Staff app](staff-app.md)
