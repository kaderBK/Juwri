# juwri Make app — assembly guide

This guide builds the juwri **custom app** in Make, one JSON block at a time. No
coding needed — you paste each file into the Make **Apps builder** in order, then run
the one validation check at the end of each section before moving on.

- Where to build: sign in to Make → your profile menu → **Custom apps** → **Create a
  new app** (give it the name "juwri", theme colour and language). Open your new app;
  you'll see the left-hand sections **Base**, **Connections**, **Webhooks**, **Modules**.
- Each numbered step below = exactly one JSON file in this folder. Open the file, copy
  ALL of it, and paste it into the matching Make editor, then **Save** (the disk icon).
- You will need one thing from your juwri dashboard first: go to **Developers → API**,
  copy the **Base URL host** (e.g. `https://app.yourdomain.com` — WITHOUT the `/api/v1`
  part), and under **Developers → API tokens** create a token. Keep both handy.

---

## Section 1 — Base

1. **`base.json`** → open the app's **Base** tab, select all the existing content,
   delete it, paste `base.json`, **Save**.

**Validation:** the Base tab saves with no red error banner. It sets the API host, the
`Authorization: Bearer` header, and turns juwri's error messages into readable Make
errors.

## Section 2 — Connection

2. **`connection.json`** → **Connections** → **Add** (type **API key**) → open the new
   connection's **Communication** editor, paste `connection.json`, **Save**.

**Validation:** click **Add** on a test connection in any module later and enter your
host + token; Make should show the connection labelled with your **store name**. That
label comes from `GET /api/v1/me` — if it fails, re-check the host (no `/api/v1`) and the
token.

## Section 3 — Webhook

3. **`webhook.json`** → **Webhooks** → **Create webhook** (type **Dedicated**), open its
   editor, paste `webhook.json`, **Save**.

**Validation:** the webhook lists an **Event** dropdown with the six juwri events. This
one shared webhook is what every "Watch …" trigger attaches to; it subscribes to the
event you pick and stores the subscription id so it can detach cleanly.

## Section 4 — Modules

Add each module under **Modules → Add module**, choose the module type shown, open its
**Communication** editor, paste the file, and **Save**. Do them in this order.

### Triggers (instant — pick type "Instant trigger" and attach the webhook)

4. **`modules/trigger-order-created.json`** — Watch New Orders
5. **`modules/trigger-order-status-changed.json`** — Watch Order Status Changes
6. **`modules/trigger-order-paid.json`** — Watch Paid Orders
7. **`modules/trigger-customer-created.json`** — Watch New Customers
8. **`modules/trigger-reservation-created.json`** — Watch New Reservations
9. **`modules/trigger-refund-completed.json`** — Watch Completed Refunds

**Validation:** each trigger shows an output bundle preview matching its sample (an
Order, Customer, Reservation or Refund). In a real scenario, adding the trigger creates
a webhook subscription in juwri; removing it deletes the subscription.

### Actions (pick type "Action")

10. **`modules/action-update-order-status.json`** — Update Order Status
11. **`modules/action-create-menu-item.json`** — Create Menu Item
12. **`modules/action-update-menu-item.json`** — Update Menu Item
13. **`modules/action-set-item-availability.json`** — Set Item Availability
14. **`modules/action-create-customer.json`** — Create Customer
    (note: needs a juwri endpoint not yet released — it will error until then.)

**Validation:** run each action once in a test scenario; the output is the FULL updated
record (an order or menu item), not just a success flag.

### Searches (pick type "Search")

15. **`modules/search-find-order.json`** — Find Order (by number)
16. **`modules/search-find-customer.json`** — Find Customer (by phone/email)
17. **`modules/search-list-orders.json`** — List Orders (newest first)

**Validation:** run List Orders on a busy restaurant — it should keep loading pages until
all orders are returned (that's the pagination working via `per_page` + `page`). Find
Customer with an unknown phone should return **0 bundles**, not an error.

### Universal

18. **`modules/universal-api-call.json`** — Make an API Call (advanced)

**Validation:** call `GET /stores` through it; you should get your restaurant list.
This module is required for Make's review — leave it in.

---

## Final checks before publishing

- Every module saved with no red errors.
- The connection labels itself with your store name.
- One trigger, one action and one search each ran successfully in a test scenario, and
  you kept the execution logs (Make review requires a log per module + one deliberate
  error run, e.g. a bad token). Make sure the logs contain **no** personal data.
- Re-run these steps fresh right before you submit (post-approval changes re-enter QA —
  this repo's JSON is the source of truth; edit here, re-paste, re-test).

See `README.md` for what the app does and `REVIEW-NOTES.md`-style guidance is folded into
the OWNER checklist in `rev/integrations-pack/LEDGER-B.md` (B10).
