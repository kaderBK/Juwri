# Zapier integration-checks conformance

Reference: https://docs.zapier.com/platform/publish/integration-checks-reference

Every check below is either enforced by `npm test` (a spec name is cited) or is an
owner action at publish time. Re-run `npm test` before any `zapier push`.

| Check | Requirement | How this app satisfies it |
|-------|-------------|---------------------------|
| **D006** | Hook triggers need a polling `performList` fallback | Every REST-Hook trigger has one **except `refund_completed`** — the platform has no refunds list endpoint (settled decision, `rev/integrations-pack/LEDGER.md`), so it ships instant-only. Enforced by `test/samples.test.js` "D006 …". **Owner note at submission:** declare refund.completed as instant-only in the app's review notes so the reviewer expects no polling sample. |
| **D010** | Each trigger/action sample contains an `id` | Every sample has a top-level `id`. `test/samples.test.js` "D010 …". |
| **D012** | One static sample per operation | Every trigger/create/search declares `operation.sample`. `test/samples.test.js` "D012 …". |
| **D023** | Dates are ISO-8601 with a timezone | All `*_at` fields in samples match `…±hh:mm`/`Z`. `test/samples.test.js` "D023 …". |
| **T004 / T006** | A sample's keys are a subset of the live result's keys | `performList` output is the exact canonical envelope; `test/triggers.test.js` "sample keys are a subset of the live performList result". |
| **C002–C004** | Never remove/rename output keys after publish | Output shapes are the frozen F0-10 canonical payloads (`samples/*.json`, byte-identical to the platform fixtures). `test/samples.test.js` verifies verbatim copy; do not edit sample keys post-publish. |
| **S001 / S002** | A search returns an array, `[]` on no match (never 404) | `find_order` swallows 404 → `[]`; `find_customer` returns `[]` on empty. `test/searches.test.js`. |
| **T001** | A trigger returns an array | `perform` returns `[bundle.cleanedRequest]`; `performList` returns an array. `test/triggers.test.js`. |
| **Copy** | Trigger descriptions start `Triggers when ` and end `.`; labels Title Case; app description 40–140 chars starting `<AppName> is a…` with no "Zapier" | Trigger/create descriptions enforced in `test/triggers.test.js` / `test/creates.test.js`. App description set at publish (see README "Publish"). |
| **M004** | 256px+ square RGBA PNG logo | OWNER (see `LEDGER-B.md` B10). |
| **M005** | Account under the company domain | OWNER (see `LEDGER-B.md` B10). |

## Runtime notes for the reviewer

- **Auth**: API key, header `Authorization: Bearer <token>`; connection test is `GET /api/v1/me`.
- **Unsubscribe idempotency**: a second delete currently returns 404 from the
  platform; the app treats 404 as success so an unsubscribe never fails a Zap
  (see `NEEDS-F0(B1-a)` — the platform will move to 204).
- **Entitlement gates**: `reservation.created` needs `feature:reservations`;
  `customer.created` / `find_customer` need `feature:crm_suite`. An unentitled
  store returns a JSON 403 whose `message` the app surfaces.
- **Create Customer**: depends on a platform endpoint not yet shipped
  (`NEEDS-F0(B4-a)`); it will 4xx until that lands.
