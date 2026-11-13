<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
| Password hashing overrides; everything else is the framework default.
| bcrypt keeps a cost of 10 unless BCRYPT_ROUNDS says otherwise, and the
| algorithm check stays off so hashes created by older installs still verify.
*/

return [

    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 10),
    ],

    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
    ],

];
