<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Policies\Admin;

use Juwri\Core\Policies\Admin\AdminManagedPolicy;

/**
 * Coupons in the superadmin panel: plan coupons (platform billing). The list
 * also shows restaurant coupons from the same table so they can be reviewed.
 */
class CouponPolicy extends AdminManagedPolicy {}
