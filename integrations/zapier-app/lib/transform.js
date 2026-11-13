'use strict';

/**
 * performList (polling-fallback) row → canonical webhook ENVELOPE.
 *
 * Settled decision (rev/integrations-pack/LEDGER.md): the platform does NOT
 * reshape its list endpoints — the PACKAGE transforms each list row into the
 * exact canonical shape a REST-Hook delivery uses, i.e. the F0-5 fixture
 * envelope `{ id, event, created_at, data }`. So a Zap sees identical fields
 * whether the trigger fired from a live hook or from the polling fallback.
 *
 * The list rows are leaner than the delivery payload (e.g. the orders index has
 * no `currency` / `track_token` / `source` column), so the fields the row cannot
 * supply are emitted as `null` — the KEY is always present (Zapier check
 * T004/T006: a sample's keys must be a subset of the live result's keys), and
 * the live REST-Hook fills the real value.
 *
 * Row shapes (verified against the controllers, F0-4):
 *   orders        {id,status,type,table_number,customer_name,total,is_paid,created_at}
 *   customers     {id,name,phone,email,orders_count,total_spent,last_order_at,tags,created_at}
 *   reservations  {id,name,phone,email,party_size,reserved_at,table_number,status,created_at}
 */

function nullable(value) {
  return value === undefined || value === null ? null : value;
}

// One builder per event → the `data` object, keyed EXACTLY like the fixture.
const DATA_BUILDERS = {
  'order.created': (row, bundle) => ({
    order_id: row.id,
    restaurant_id: Number(bundle.inputData.restaurant_id),
    type: nullable(row.type),
    status: nullable(row.status),
    total: nullable(row.total),
    currency: nullable(row.currency), // not on the orders index row → null on poll
    track_token: nullable(row.track_token), // idem
    source: row.source === undefined || row.source === null ? 'api' : row.source,
  }),
  'order.status_changed': (row) => ({
    order_id: row.id,
    new_status: nullable(row.status),
  }),
  'order.paid': (row) => ({
    order_id: row.id,
    total: nullable(row.total),
    payment_gateway: nullable(row.payment_gateway), // not on the index row → null on poll
  }),
  'customer.created': (row) => ({
    customer_id: row.id,
    name: nullable(row.name),
  }),
  'reservation.created': (row) => ({
    reservation_id: row.id,
    status: nullable(row.status),
    source: nullable(row.source), // reservations index has no source column → null on poll
  }),
};

function hasBuilder(event) {
  return Object.prototype.hasOwnProperty.call(DATA_BUILDERS, event);
}

/**
 * A stable, per-record dedup id for the polling fallback. Zapier dedups on the
 * top-level `id`; deriving it from the record means the same row never
 * re-triggers across polls. (Live hooks carry the platform's `msg_…` id — a
 * different format, harmless: hooks and polling never run at the same time.)
 */
function envelopeId(event, row) {
  return `${event}:${row.id}`;
}

/** Transform ONE list row into the canonical `{id,event,created_at,data}` envelope. */
function toEnvelope(event, row, bundle) {
  if (!hasBuilder(event)) {
    throw new Error(`No performList transform for event "${event}".`);
  }
  return {
    id: envelopeId(event, row),
    event,
    created_at: nullable(row.created_at),
    data: DATA_BUILDERS[event](row, bundle),
  };
}

module.exports = {
  DATA_BUILDERS,
  hasBuilder,
  envelopeId,
  toEnvelope,
};
