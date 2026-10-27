<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Legal\LegalPage;
use Juwri\Core\Legal\LegalSettings;
use Juwri\Core\Legal\SecurityTxt;
use Juwri\Core\Legal\TermsChangeNotice;
use Throwable;

/**
 * P1-23 release gate: the website legal pages may be published. Fails (exit 1) while any {{FILL}} owner fact is
 * empty or any {{VERIFY}} promise is unconfirmed (config/legal.php, .env LEGAL_*), when a text is missing, when the
 * English and Arabic texts use different placeholders, when a text's version is not the newest configured version,
 * when a brand-scrub term appears, when security.txt has expired, or when an announced material Terms change gives
 * less than 30 days' notice (Terms 14.2). Prints keys only, never values.
 */
class LegalCheck extends Command
{
    protected $signature = 'legal:check';

    protected $description = 'Fail while the website legal pages still hold unresolved {{FILL}} / {{VERIFY}} placeholders (release gate)';

    /** Owner decision 2026-10-04: brand scrub term list (case-insensitive); concatenated so this file does not spell them. */
    private const SCRUB = '/menu'.'[\s_-]?bar[\s_-]?code|quick'.'[\s_-]?qr|quick'.'[\s_-]?cms|by'.'lancer/i';

    public function handle(): int
    {
        LegalSettings::applyToConfig();

        $errors = [];
        $rows = [];
        foreach (array_keys(LegalDocuments::all()) as $document) {
            $markers = [];
            foreach (LegalPage::LOCALES as $locale) {
                try {
                    $page = LegalPage::load($document, $locale);
                } catch (Throwable $e) {
                    $errors[] = "{$document}/{$locale}: ".$e->getMessage();

                    continue;
                }
                $markers[$locale] = LegalPage::markers($document, $locale);
                $latest = LegalDocuments::latestVersion($document);
                if ($page->textVersion !== $latest) {
                    $errors[] = "{$document}/{$locale}: text is version '{$page->textVersion}', config/legal.php's newest is '{$latest}'";
                }
                if (preg_match(self::SCRUB, (string) file_get_contents(LegalPage::path($document, $locale))) === 1) {
                    $errors[] = "{$document}/{$locale}: brand-scrub term in the text";
                }
                foreach ($page->unresolved as $marker) {
                    $errors[] = "{$document}/{$locale}: unresolved {$marker}";
                }
                $rows[] = [$document, $locale, $page->textVersion, count($page->unresolved) === 0 ? 'ok' : count($page->unresolved).' unresolved'];
            }
            if (isset($markers['en'], $markers['ar']) && $markers['en'] !== $markers['ar']) {
                $errors[] = "{$document}: English and Arabic texts use different placeholders";
            }
        }

        // Terms 14.2: an announced material change needs 30 days' notice (TermsChangeNotice refuses to send it).
        if (($announced = TermsChangeNotice::announced()) !== null
            && ($reason = TermsChangeNotice::problem($announced['effective'], $announced['material'])) !== null) {
            $errors[] = 'terms '.$announced['version'].': '.$reason;
        }

        try {
            $expires = SecurityTxt::expires();
            if ($expires->isPast()) {
                $errors[] = 'security.txt: Expires '.$expires->toIso8601String().' has passed (LEGAL_SECURITY_TXT_EXPIRES)';
            } elseif ($expires->gt(now()->addYear()->addDay())) {
                $this->warn('security.txt: Expires is more than a year away (RFC 9116 recommends less).');
            }
        } catch (Throwable) {
            $errors[] = 'security.txt: LEGAL_SECURITY_TXT_EXPIRES is not a date';
        }

        $this->table(['Document', 'Text', 'Version', 'Placeholders'], $rows);
        if (! LegalDocuments::enabled()) {
            $this->line('Note: LEGAL_PAGES is off — this instance does not publish the pages.');
        }
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error(count($errors).' problem(s): the legal pages are not ready to publish.');

            return self::FAILURE;
        }
        $this->info('Legal pages ready to publish: no unresolved placeholders.');

        return self::SUCCESS;
    }
}
