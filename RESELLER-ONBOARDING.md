# Reseller Onboarding

Operational guide for white-label resellers.

## Reseller API (v1, read-only)

Programmatic read access to your own reseller data. **Read-only** — v1 exposes no
write endpoints. Every response is scoped to your reseller; you can never see another
reseller's data.

### Authentication

Generate a token in the portal: **Settings → API token → Generate**. The plaintext
token (format `rsk_…`) is shown **once** — only its hash is stored, so copy it
immediately. Regenerating replaces the old token.

Send it as a Bearer token:

```
Authorization: Bearer rsk_your_token_here
```

- `401` — missing or invalid token.
- `403` — your reseller account is suspended (settle your wholesale invoice).
- Rate limit: **60 requests/minute** per token.

> Already generated a token before the hashing upgrade shipped? It keeps working and
> is upgraded to a hash automatically on first use. Regenerating from the portal is
> still recommended.

### Endpoints

Base URL: `https://<your-brand-host>/api/v1/reseller`

| Method | Path | Returns |
|---|---|---|
| GET | `/stats` | customers, active seats, included seats, billing status, next-invoice estimate |
| GET | `/customers?per_page=25` | paginated list of your customers (id, name, email, status, created_at) |
| GET | `/invoices` | your wholesale invoices (id, number, total, status, created_at) |

### Example

```bash
curl -s https://acme.example.com/api/v1/reseller/stats \
  -H "Authorization: Bearer rsk_your_token_here"
```

```json
{
  "customers": 42,
  "seats": 51,
  "included_seats": 25,
  "billing_status": "active",
  "next_invoice_estimate": 287.00
}
```
