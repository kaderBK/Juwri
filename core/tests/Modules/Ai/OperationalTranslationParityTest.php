<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use PHPUnit\Framework\TestCase;

class OperationalTranslationParityTest extends TestCase
{
    private const LOCALES = ['ar', 'de', 'en', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    private const KEYS = [
        'failed_jobs',
        'platform_alerts',
        'no_alerts',
        'queue',
        'exception',
        'failed_at',
        'no_failed_jobs',
        'job_deleted',
        'failed_jobs_cleared',
        'we_sent_you_a_verification_code',
        'thanks_for_the_order',
    ];

    public function test_operational_messages_exist_in_every_supported_locale(): void
    {
        $basePath = dirname(__DIR__, 3).'/lang';
        $english = require $basePath.'/en/lang.php';
        $englishMessages = array_intersect_key($english, array_flip(self::KEYS));

        foreach (self::LOCALES as $locale) {
            $messages = require $basePath.'/'.$locale.'/lang.php';

            foreach (self::KEYS as $key) {
                $this->assertArrayHasKey($key, $messages, "{$locale} is missing {$key}");
                $this->assertNotSame('', trim((string) $messages[$key]), "{$locale}.{$key} is empty");
            }

            if ($locale !== 'en') {
                $localizedMessages = array_intersect_key($messages, array_flip(self::KEYS));
                $this->assertNotSame(
                    $englishMessages,
                    $localizedMessages,
                    "{$locale} still contains the complete English fallback block",
                );
            }
        }
    }
}
