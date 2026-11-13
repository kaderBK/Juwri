// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const { hookOperation, restaurantField } = require('../lib/triggerFactory');
const sample = require('../samples/reservation.created.json');

const EVENT = 'reservation.created';

module.exports = {
  key: 'reservation_created',
  noun: 'Reservation',
  display: {
    label: 'New Reservation',
    description: 'Triggers when a new reservation is created.',
  },
  operation: Object.assign(
    {
      inputFields: [restaurantField()],
      sample,
    },
    hookOperation({
      event: EVENT,
      // Gated `feature:reservations` at the route — an unentitled store gets a
      // JSON 403 the error mapping surfaces to the Zap author.
      listPath: (b) => `/stores/${b.inputData.restaurant_id}/reservations`,
    })
  ),
};
