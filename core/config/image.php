<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// intervention/image: process uploads with the GD extension (Imagick is
// optional on shared hosts, GD is always there).
return [
    'driver' => 'gd',
];
