// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { hookOperation } = require('../lib/triggerFactory');
const sample = require('../samples/refund.completed.json');

const EVENT = 'refund.completed';

/**
 * INSTANT-ONLY. The platform has no refunds list endpoint (settled decision,
 * LEDGER.md), so this trigger ships as a REST Hook with NO performList fallback.
 * Documented for Zapier check D006 in REVIEW-CHECKLIST.md. It needs no
 * restaurant picker: the subscription is owner-scoped and refunds fire for the
 * whole account.
 */
module.exports = {
  key: 'refund_completed',
  noun: 'Refund',
  display: {
    label: 'Refund Completed',
    description: 'Triggers when a refund is completed.',
  },
  operation: Object.assign(
    {
      inputFields: [],
      sample,
    },
    hookOperation({ event: EVENT }) // no listPath → instant-only
  ),
};
