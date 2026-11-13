<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Policies\Admin;

use Juwri\Core\Policies\Admin\AdminManagedPolicy;

/** Allergens in the platform catalogue (restaurants also add their own; those are managed from the owner dashboard). */
class AllergyPolicy extends AdminManagedPolicy {}
