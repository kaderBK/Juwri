# juwri — Make (Integromat) custom app

JSON blocks for a Make custom app that connects juwri to Make scenarios, reusing the
juwri owner API (`/api/v1`) — the same subscription and REST endpoints the Zapier app
uses. There is no build step; these are source-of-truth JSON blocks you paste into the
Make **Apps builder**. Follow **`README-ASSEMBLY.md`** to assemble it.

## What it does

- **Triggers** (instant, via one shared dedicated webhook): New Order, Order Status
  Changed, Order Paid, New Customer, New Reservation, Refund Completed.
- **Actions**: Update Order Status, Create Menu Item, Update Menu Item, Set Item
  Availability, Create Customer.
- **Searches**: Find Order, Find Customer, List Orders (paginated).
- **Universal**: Make an API Call — an authenticated call to any `/api/v1` path.

## Connection

API-key auth. In juwri go to **Developers → API tokens**, mint a token, and note the
host from **Developers → API** (e.g. `https://app.yourdomain.com`, without `/api/v1`).
The connection is validated and labelled from `GET /api/v1/me`.

## Test

```bash
cd integrations/make-app
npm test               # validates every JSON block + the assembly guide (offline, zero-dep)
npm run build:samples  # re-copy samples/ from the platform fixtures
```

## Publish (OWNER)

Tracked in `rev/integrations-pack/LEDGER-B.md` (B10): share privately via invite link
immediately; for public listing Make runs automatic + manual QA and wants a test-run log
per module plus one deliberate error run (logs free of personal data, run fresh before
submit). Version the JSON here — post-approval edits re-enter QA.

## Frozen contract

Trigger output shapes are the platform's canonical F0-10 payloads (`samples/*.json`,
byte-identical to the platform fixtures). Do not rename or remove output keys after
publishing.
