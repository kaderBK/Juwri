<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Data;

use Juwri\Modules\Tables\Services\Qr\QrRenderService as BaseQrRenderService;

/** Public Data facade for QrRenderService so other modules do not import Tables\Services directly. */
class QrRenderService extends BaseQrRenderService {}
