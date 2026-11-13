<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Modules\Fiscal\Services\Zatca\Sdk\JavaDetector;
use Juwri\Modules\Fiscal\Services\Zatca\Sdk\SdkOutputParser;
use Juwri\Modules\Fiscal\Services\Zatca\Sdk\SdkSampleSet;
use Symfony\Component\Process\Process;

/**
 * P6-150: run the official ZATCA (Fatoora) SDK validator over sample documents made by the app's own UBL builder.
 * The owner downloads the SDK from the Fatoora portal; this command never downloads anything. No network, no database,
 * and the sample key is a throw-away TEST key: no private key is read, printed or logged. It only reports what the SDK
 * says; it changes no fiscal code.
 */
class ZatcaSdkValidate extends Command
{
    protected $signature = 'fiscal:zatca-sdk-validate
        {--sdk= : folder of the unzipped ZATCA SDK (default: ZATCA_SDK_PATH)}
        {--java=java : Java program to use}
        {--keep= : also save the sample XML files in this folder}';

    protected $description = 'Validate sample invoices from our UBL builder with the ZATCA SDK you downloaded (needs Java 11-14)';

    public function handle(): int
    {
        $java = JavaDetector::detect((string) $this->option('java'));
        $this->line('Java: '.($java['found'] ? 'version '.$java['major'] : 'not found'));
        if ($java['advice'] !== '') {
            $this->warn($java['advice']);
        }
        if (! $java['ok']) {
            return self::FAILURE;
        }

        $launcher = $this->launcher();
        if ($launcher === null) {
            return self::FAILURE;
        }

        $keep = (string) $this->option('keep');
        if ($keep !== '' && ! is_dir($keep) && ! @mkdir($keep, 0700, true)) {
            $this->error("Cannot create {$keep}.");

            return self::FAILURE;
        }
        $tmp = sys_get_temp_dir().'/juwri-zatca-sdk-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0700);

        $rows = [];
        $failed = false;
        try {
            foreach ((new SdkSampleSet)->build() as $sample) {
                $file = $tmp.'/'.$sample['name'].'.xml';
                file_put_contents($file, $sample['xml']);
                if ($keep !== '') {
                    copy($file, rtrim($keep, '/').'/'.$sample['name'].'.xml');
                }
                $res = $this->validate($launcher, $file, (string) $this->option('java'));
                $failed = $failed || $res['status'] !== 'PASS';
                $rows[] = [$sample['name'], $res['status'], $res['messages'] === [] ? '-' : implode("\n", $res['messages'])];
            }
        } finally {
            foreach (glob($tmp.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmp);
        }

        $this->table(['Sample', 'Result', 'SDK messages'], $rows);
        $this->line('The sample is signed with a throw-away TEST certificate, so a certificate message from the SDK is expected. Send every other message to the developer; do not change fiscal code on a guess.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{script:string, home:string, dir:string}|null */
    private function launcher(): ?array
    {
        $sdk = (string) ($this->option('sdk') ?: config('services.zatca.sdk_path'));
        if ($sdk === '') {
            $this->error('Tell me where the ZATCA SDK is: --sdk=/path/to/zatca-einvoicing-sdk or set ZATCA_SDK_PATH in .env. Download it from the Fatoora portal (fatoora.zatca.gov.sa > Developer Portal > SDK) and unzip it.');

            return null;
        }
        $sdk = rtrim($sdk, '/');
        $home = is_file($sdk.'/Apps/fatoora') ? $sdk.'/Apps' : (is_file($sdk.'/fatoora') ? $sdk : null);
        if ($home === null) {
            $this->error("No Apps/fatoora script in {$sdk}. Point --sdk at the folder you unzipped (it contains the Apps folder).");

            return null;
        }

        return ['script' => $home.'/fatoora', 'home' => $home, 'dir' => dirname($home) === $sdk ? $sdk : $home];
    }

    /**
     * @param  array{script:string, home:string, dir:string}  $l
     * @return array{status:string, messages:list<string>}
     */
    private function validate(array $l, string $file, string $java): array
    {
        // The SDK script calls plain `java`: when --java names a path, put its folder first on PATH.
        $path = (string) getenv('PATH');
        if (str_contains($java, '/')) {
            $path = dirname($java).PATH_SEPARATOR.$path;
        }
        $p = new Process(['bash', $l['script'], '-validate', '-invoice', $file], $l['dir'], [
            'FATOORA_HOME' => $l['home'],
            'PATH' => $path,
            'HOME' => (string) getenv('HOME'),
        ]);
        $p->setTimeout(180);
        $p->run();

        return SdkOutputParser::parse($p->getOutput()."\n".$p->getErrorOutput());
    }
}
