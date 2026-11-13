<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support\Csp;

/**
 * CSP directive names (P1-00a: own CSP layer, replaces spatie/laravel-csp — J18).
 */
final class Directive
{
    public const BASE = 'base-uri';

    public const BLOCK_ALL_MIXED_CONTENT = 'block-all-mixed-content';

    public const CHILD = 'child-src';

    public const CONNECT = 'connect-src';

    public const DEFAULT = 'default-src';

    public const FONT = 'font-src';

    public const FORM_ACTION = 'form-action';

    public const FRAME = 'frame-src';

    public const FRAME_ANCESTORS = 'frame-ancestors';

    public const IMG = 'img-src';

    public const MANIFEST = 'manifest-src';

    public const MEDIA = 'media-src';

    public const OBJECT = 'object-src';

    public const REPORT = 'report-uri';

    public const REPORT_TO = 'report-to';

    public const REQUIRE_TRUSTED_TYPES_FOR = 'require-trusted-types-for';

    public const SANDBOX = 'sandbox';

    public const SCRIPT = 'script-src';

    public const SCRIPT_ATTR = 'script-src-attr';

    public const SCRIPT_ELEM = 'script-src-elem';

    public const STYLE = 'style-src';

    public const STYLE_ATTR = 'style-src-attr';

    public const STYLE_ELEM = 'style-src-elem';

    public const UPGRADE_INSECURE_REQUESTS = 'upgrade-insecure-requests';

    public const WORKER = 'worker-src';

    public static function isValid(string $directive): bool
    {
        return in_array($directive, new \ReflectionClass(self::class)->getConstants(), true);
    }
}
