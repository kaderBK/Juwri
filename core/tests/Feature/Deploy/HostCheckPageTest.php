<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Deploy;

use PHPUnit\Framework\TestCase;

/**
 * P6-151: the pre-install host check page (repo-root juwri-check.php). It is plain PHP, so the test serves a copy of it
 * with `php -S` from a scratch folder laid out like an upload (juwri-check.php next to core/) and talks HTTP to it.
 */
class HostCheckPageTest extends TestCase
{
    private const TOKEN = 'k3Jx9-test-token-0123456789abcdef';

    private string $dir;

    /** @var resource|null */
    private $server;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/juwri-check-'.bin2hex(random_bytes(6));
        foreach (['core/storage/app', 'core/storage/framework/cache', 'core/storage/framework/sessions', 'core/storage/framework/views', 'core/storage/logs', 'core/bootstrap/cache'] as $d) {
            mkdir($this->dir.'/'.$d, 0755, true);
        }
        $repo = dirname(__DIR__, 4);
        copy($repo.'/juwri-check.php', $this->dir.'/juwri-check.php');
        copy($repo.'/router.php', $this->dir.'/router.php');
        file_put_contents($this->dir.'/index.php', '<?php echo "front controller";');

        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($sock);
        $this->port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$this->port, '-t', $this->dir, $this->dir.'/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, $this->dir);
        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $c = stream_socket_client('tcp://127.0.0.1:'.$this->port, $e, $s, 0.1);
                if ($c !== false) {
                    fclose($c);
                    break;
                }
                usleep(50000);
            }
        } finally {
            restore_error_handler();
        }
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @param  array<string, string>|null  $store
     * @return array{0: int, 1: string}
     */
    private function request(string $path, ?array $store = null): array
    {
        $opts = ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 15];
        if ($store !== null) {
            $opts = ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 15,
                'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => http_build_query($store)];
        }
        $body = (string) @file_get_contents('http://127.0.0.1:'.$this->port.$path, false, stream_context_create(['http' => $opts]));
        /** @var list<string> $http_response_header */
        $status = isset($http_response_header[0]) && preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $m) === 1 ? (int) $m[1] : 0;

        return [$status, $body];
    }

    private function writeToken(string $token = self::TOKEN): void
    {
        file_put_contents($this->dir.'/juwri-check.token', $token."\n");
    }

    private function assertNoPaths(string $body): void
    {
        $real = (string) realpath($this->dir);
        foreach ([$this->dir, $real, sys_get_temp_dir(), '/home/', '/var/www', '/private/', 'DOCUMENT_ROOT', 'phpinfo', '<table class="h"'] as $needle) {
            if ($needle !== '' && $needle !== '/') {
                $this->assertStringNotContainsString($needle, $body, "the page leaked {$needle}");
            }
        }
    }

    public function test_without_a_token_file_it_refuses_and_shows_no_form(): void
    {
        [$status, $body] = $this->request('/juwri-check.php');
        $this->assertSame(403, $status);
        $this->assertStringContainsString('juwri-check.token', $body);
        $this->assertStringNotContainsString('<form', $body);

        [$status, $body] = $this->request('/juwri-check.php', ['token' => self::TOKEN]);
        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('Required extensions', $body);
        $this->assertNoPaths($body);
    }

    public function test_a_too_short_token_file_keeps_it_locked(): void
    {
        $this->writeToken('short');
        [$status, $body] = $this->request('/juwri-check.php', ['token' => 'short']);
        $this->assertSame(403, $status);
        $this->assertStringNotContainsString('Required extensions', $body);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->writeToken();
        [$status, $body] = $this->request('/juwri-check.php');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<form method="post"', $body);

        foreach (['', 'nope', self::TOKEN.'x', substr(self::TOKEN, 0, -1)] as $wrong) {
            [$status, $body] = $this->request('/juwri-check.php', ['token' => $wrong]);
            $this->assertSame(403, $status, "token '{$wrong}' must be refused");
            $this->assertStringNotContainsString('Required extensions', $body);
        }
        // a GET with the token in the query string is never accepted (the token must not land in access logs)
        [, $body] = $this->request('/juwri-check.php?token='.self::TOKEN);
        $this->assertStringNotContainsString('Required extensions', $body);
    }

    public function test_the_right_token_shows_the_report_without_paths(): void
    {
        $this->writeToken();
        [$status, $body] = $this->request('/juwri-check.php', ['token' => self::TOKEN]);
        $this->assertSame(200, $status);
        foreach (['PHP version (web)', PHP_VERSION, 'Required extensions', 'sodium', 'Disabled functions', 'putenv', 'memory_limit', 'max_execution_time', 'Writable folders', 'core/storage/', 'Delete this page after use'] as $want) {
            $this->assertStringContainsString($want, $body);
        }
        $this->assertStringNotContainsString(self::TOKEN, $body, 'the token is never echoed');
        $this->assertStringNotContainsString('Network', $body, 'network checks run only when ticked');
        $this->assertNoPaths($body);
    }

    public function test_it_refuses_once_juwri_is_installed(): void
    {
        $this->writeToken();
        file_put_contents($this->dir.'/core/.env', "APP_NAME=Juwri\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n");
        [$status, $body] = $this->request('/juwri-check.php', ['token' => self::TOKEN]);
        $this->assertSame(410, $status);
        $this->assertStringNotContainsString('Required extensions', $body);
        $this->assertNoPaths($body);

        // an .env without a key yet (copied from .env.example, key:generate not run) is not "installed"
        file_put_contents($this->dir.'/core/.env', "APP_NAME=Juwri\nAPP_KEY=\n");
        [$status] = $this->request('/juwri-check.php', ['token' => self::TOKEN]);
        $this->assertSame(200, $status);
    }

    public function test_posts_are_rate_limited_by_the_file_counter(): void
    {
        $this->writeToken();
        for ($i = 1; $i <= 10; $i++) {
            [$status] = $this->request('/juwri-check.php', ['token' => 'wrong-'.$i]);
            $this->assertSame(403, $status);
        }
        [$status, $body] = $this->request('/juwri-check.php', ['token' => self::TOKEN]);
        $this->assertSame(429, $status, 'the 11th post in the hour is refused, even with the right token');
        $this->assertStringNotContainsString('Required extensions', $body);
    }

    public function test_the_rewrite_probe_does_not_answer_by_file_name_and_the_token_is_never_served(): void
    {
        $this->writeToken();
        [, $body] = $this->request('/juwri-check.php?juwri_probe=1');
        $this->assertStringNotContainsString('juwri-rewrite-ok', $body);

        $this->request('/juwri-check.php', ['token' => 'wrong']);
        $this->assertFileExists($this->dir.'/juwri-check.attempts');
        foreach (['/juwri-check.token', '/juwri-check.attempts'] as $path) {
            [$status, $body] = $this->request($path);
            $this->assertSame(404, $status, "{$path} must not be served");
            $this->assertStringNotContainsString(self::TOKEN, $body);
        }
    }

    public function test_every_front_door_denies_the_token_file(): void
    {
        $repo = dirname(__DIR__, 4);
        $this->assertMatchesRegularExpression('/<FilesMatch "\^juwri-check\\\\\.\(token\|attempts\)\$">\s*Require all denied/', (string) file_get_contents($repo.'/.htaccess'));
        $this->assertStringContainsString('RewriteRule ^juwri-check\.(token|attempts)$ - [F,L]', (string) file_get_contents($repo.'/.htaccess'));
        $this->assertStringContainsString('Block host check token', (string) file_get_contents($repo.'/web.config'));
        $this->assertStringContainsString('juwri-check\.(token|attempts)', (string) file_get_contents($repo.'/router.php'));
        $this->assertStringContainsString("'juwri-check.php'", (string) file_get_contents($repo.'/distribution/release/src/ReleaseTree.php'));
    }
}
