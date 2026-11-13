<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Data;

use Juwri\Modules\Content\Support\ContentLocales as BaseContentLocales;

/** Public Data facade for ContentLocales so other modules do not import Content\Support directly. */
class ContentLocales extends BaseContentLocales {}
