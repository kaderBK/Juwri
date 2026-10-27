// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';

const authentication = require('./authentication');

// Triggers
const orderCreated = require('./triggers/order_created');
const orderStatusChanged = require('./triggers/order_status_changed');
const orderPaid = require('./triggers/order_paid');
const customerCreated = require('./triggers/customer_created');
const reservationCreated = require('./triggers/reservation_created');
const refundCompleted = require('./triggers/refund_completed');
const restaurant = require('./triggers/restaurant'); // hidden dropdown source

// Creates
const updateOrderStatus = require('./creates/update_order_status');
const createMenuItem = require('./creates/create_menu_item');
const updateMenuItem = require('./creates/update_menu_item');
const setItemAvailability = require('./creates/set_item_availability');
const createCustomer = require('./creates/create_customer');

// Searches
const findOrder = require('./searches/find_order');
const findCustomer = require('./searches/find_customer');

function byKey(list) {
  return list.reduce((acc, def) => {
    acc[def.key] = def;
    return acc;
  }, {});
}

const App = {
  version: require('./package.json').version,
  // Must match the installed zapier-platform-core (package.json dependency). The
  // owner runs `npm install` before `zapier push`; kept as a literal so the
  // package requires cleanly with no node_modules for the offline test suite.
  platformVersion: '15.5.1',

  authentication,

  triggers: byKey([
    orderCreated,
    orderStatusChanged,
    orderPaid,
    customerCreated,
    reservationCreated,
    refundCompleted,
    restaurant,
  ]),

  creates: byKey([
    updateOrderStatus,
    createMenuItem,
    updateMenuItem,
    setItemAvailability,
    createCustomer,
  ]),

  searches: byKey([findOrder, findCustomer]),
};

module.exports = App;
