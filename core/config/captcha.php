<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

// anhskohbo/no-captcha (Google reCAPTCHA). Keys come from the environment;
// requests to Google time out after 30 seconds.
return [
    'sitekey' => env('NOCAPTCHA_SITEKEY'),
    'secret' => env('NOCAPTCHA_SECRET'),
    'options' => ['timeout' => 30],
];
