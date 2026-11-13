<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Staff credentials (S5 / P6-88)
|--------------------------------------------------------------------------
|
| PINs are hash-only: the former pin_keep_plaintext escape hatch is removed.
| Legacy plaintext PINs are hashed on first successful use or by
| `php artisan staff:hash-legacy-pins`.
|
*/

return [];
