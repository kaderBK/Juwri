# Demo credentials — format

`php artisan juwri:demo-seed` writes the demo's logins to `core/storage/app/demo-credentials.md` with
mode `0600` (the path can be moved with the `demo.credentials_path` config key). This file is the
**format** of that file — it holds no real secret: every value below is a placeholder.

The real file is a markdown table with one row per login:

| Label | URL | Login | Secret |
|---|---|---|---|
| Owner dashboard | https://example.test/dashboard | owner@example.test | `<generated password>` |
| Platform admin | https://example.test/admin/dashboard | admin@example.test | `<generated password>` |
| Staff dashboard | https://example.test/staff/login | staff@example.test | `<generated password>` |
| POS register | https://example.test/pos | cashier@example.test | `<six digits>` |
| Reseller panel | https://reseller.example.test/reseller | reseller@example.test | `<generated password>` |
| Reseller storefront | https://reseller.example.test/some-store | reseller@example.test | `<generated password>` |
| Table QR some-store #1 | https://example.test/some-store?qr-id=...&table=... | | |
| Room QR some-hotel #101 | https://example.test/some-hotel?...&rt=... | | |
| Guest folio some-hotel | https://example.test/hotel/some-hotel/folio/<token> | | |

What the reader may rely on:

- **Label** — what the row is for (a login, a table or room QR link, a guest folio link). Link rows have no secret.
- **URL** — where to sign in (the owner dashboard, the admin panel, the till, the reseller's white-label host).
- **Login** — the e-mail address, or `—` when the login is a PIN and has no e-mail.
- **Secret** — the password (28 letters and digits, generated once per run) or the six digit PIN.

Rules the writer follows:

- A secret is generated **once per run** and shared by every seeder that asks for the same key, so
  the file and the seeded rows always agree.
- A secret is never printed to the console, never logged and never written anywhere else. The
  command prints the **path** of the file only.
- The file is written with mode `0600`; the directory is `core/storage/app` (git-ignored).
- Synthetic demo data only (J19): the logins belong to a local demo install and are worthless
  outside it.
