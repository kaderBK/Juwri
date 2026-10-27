// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

'use strict';
/* M16 — the ONE implementation of storefront money math, shared by all 17 kept
   templates. Loaded by store_templates/layout.blade.php before the theme bundle,
   exactly like window.mbWhatsAppHandoff and window.mbCarousel.

   Every function here is a mirror of a named PHP authority. If you change one,
   change the PHP first and re-run StorefrontMoneyCoreTest:
     round()    <- PHP round($x, $decimals)
     pct()      <- round($base * ($rate / 100), $decimals)
     tax()      <- App\Models\Order::computeTax()          core/app/Models/Order.php:414
     discount() <- App\Services\CouponService::resolve()   core/app/Services/CouponService.php:88

   WHY toPrecision(15): PHP's round() pre-rounds to 15 significant digits, so
   round(4.10 * 0.15, 2) is 0.62. Plain Math.round(4.10*0.15*100)/100 is 0.61,
   because 0.615 is really 0.61499999999999999. Sixteen of the seventeen themes
   used one of three approximations of this and each was a cent low on some
   baskets. Measured against PHP on 160,000 (subtotal, rate) pairs: this idiom
   0 mismatches, the three it replaces thousands. Do not "simplify" it. */
(function (w) {
  function num(v) { var n = Number(v); return isFinite(n) ? n : 0; }

  var mbMoney = {
    /** PHP round($value, $decimals). */
    round: function (value, decimals) {
      var dp = decimals == null ? 2 : Math.max(0, decimals | 0);
      var q = Math.pow(10, dp);
      return Math.round(Number((num(value) * q).toPrecision(15))) / q;
    },

    /** round($base * ($rate / 100), $decimals). */
    pct: function (base, rate, decimals) {
      return mbMoney.round(num(base) * (num(rate) / 100), decimals);
    },

    /**
     * Order::computeTax($subtotal, $postOptions).
     * cfg: { taxCharge, taxType ('percentage'|'fixed'), decimals }
     */
    tax: function (subtotal, cfg) {
      cfg = cfg || {};
      var charge = num(cfg.taxCharge), sub = num(subtotal);
      if (charge <= 0 || sub <= 0) return 0;
      var dp = cfg.decimals == null ? 2 : cfg.decimals;
      return String(cfg.taxType || 'percentage') === 'fixed'
        ? mbMoney.round(charge, dp)
        : mbMoney.pct(sub, charge, dp);
    },

    /**
     * CouponService::resolve() discount, for the food_order surface.
     * NOTE the 2: the server rounds the discount to 2 decimals REGARDLESS of the
     * store's currency decimals (CouponService.php:92 and :96). That is a
     * server quirk, not an omission here — plate's comment at
     * assets/store_templates/plate/js/script.js records it too.
     * coupon: { coupon_type, percentage } — the applyCoupon response shape.
     */
    discount: function (subtotal, coupon) {
      if (!coupon) return 0;
      var sub = num(subtotal), d = 0;
      if (String(coupon.coupon_type) === 'percentage') d = mbMoney.round(sub * (num(coupon.percentage) / 100), 2);
      else if (String(coupon.coupon_type) === 'free_delivery') d = 0;
      else d = mbMoney.round(num(coupon.percentage), 2);
      return d > sub ? sub : d;
    }
  };

  w.mbMoney = mbMoney;
})(window);
