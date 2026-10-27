'use strict';

const { apiRequest } = require('./client');
const { toEnvelope, hasBuilder } = require('./transform');

/**
 * Every juwri trigger is a REST Hook (instant) with a polling `performList`
 * fallback — except `refund.completed`, which has no list endpoint on the
 * platform and so ships instant-only (settled decision, LEDGER.md; flagged in
 * REVIEW-CHECKLIST.md for Zapier check D006).
 *
 * ONE subscription API (F0/A5-6) serves every trigger:
 *   subscribe  → POST /webhook-endpoints { url: bundle.targetUrl, events:[event] }
 *   unsubscribe→ DELETE /webhook-endpoints/{id from bundle.subscribeData}
 *   perform    → return [bundle.cleanedRequest]  (the delivered envelope, ALWAYS an array)
 *   performList→ GET the list endpoint, then TRANSFORM each row into the exact
 *                canonical envelope (= the F0-5 fixture) via lib/transform.
 */

// A dynamic-dropdown restaurant picker, backed by the hidden `restaurant` trigger.
function restaurantField() {
  return {
    key: 'restaurant_id',
    label: 'Restaurant',
    type: 'integer',
    required: true,
    dynamic: 'store.id.title',
    altersDynamicFields: false,
    helpText: 'The restaurant to watch.',
  };
}

function makePerformSubscribe(event) {
  return async (z, bundle) => {
    const response = await apiRequest(z, bundle, {
      method: 'POST',
      path: '/webhook-endpoints',
      body: { url: bundle.targetUrl, events: [event] },
    });
    // { id, url, events, active, created_at, secret } → becomes bundle.subscribeData.
    return response.data;
  };
}

function makePerformUnsubscribe() {
  return async (z, bundle) => {
    const id = bundle.subscribeData && bundle.subscribeData.id;
    // Idempotent: an already-removed endpoint (404) is treated as success so a
    // re-run never fails the Zap. NEEDS-F0(B1-a) asks the platform to return 204
    // here; until then the package absorbs the 404.
    await apiRequest(z, bundle, {
      method: 'DELETE',
      path: `/webhook-endpoints/${id}`,
      okStatuses: [404],
    });
    // Zapier ignores the return value; hand back the id we removed, never the
    // (possibly 404) response body.
    return { id };
  };
}

// Instant delivery: hand Zapier the parsed hook body as a single-element array.
function performHook(z, bundle) {
  return [bundle.cleanedRequest];
}

function makePerformList(event, listPath, listParams, rowFilter) {
  return async (z, bundle) => {
    const response = await apiRequest(z, bundle, {
      path: listPath(bundle),
      // `per_page` is the platform's page-size param (cap 100). There is NO
      // `limit` alias — F0-4 deliberately did not add one.
      params: Object.assign({ per_page: 100 }, listParams || {}),
    });
    let rows = (response.data && response.data.data) || [];
    if (typeof rowFilter === 'function') {
      rows = rows.filter(rowFilter);
    }
    return rows.map((row) => toEnvelope(event, row, bundle));
  };
}

/**
 * @param {object} cfg
 * @param {string} cfg.event                       Canonical event key.
 * @param {function} [cfg.listPath]                 bundle → list path; omit for instant-only.
 * @param {object}   [cfg.listParams]               Extra query params for performList.
 * @param {function} [cfg.rowFilter]                Row predicate applied before transform.
 * @returns {object} a Zapier trigger `operation` (minus inputFields/sample).
 */
function hookOperation(cfg) {
  const op = {
    type: 'hook',
    performSubscribe: makePerformSubscribe(cfg.event),
    performUnsubscribe: makePerformUnsubscribe(),
    perform: performHook,
  };
  if (cfg.listPath && hasBuilder(cfg.event)) {
    op.performList = makePerformList(cfg.event, cfg.listPath, cfg.listParams, cfg.rowFilter);
  }
  return op;
}

module.exports = {
  restaurantField,
  hookOperation,
  performHook,
  makePerformSubscribe,
  makePerformUnsubscribe,
  makePerformList,
};
