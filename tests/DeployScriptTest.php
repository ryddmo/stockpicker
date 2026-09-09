<?php

declare(strict_types=1);

namespace Stockpicker\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the contract of `bin/deploy.sh` without touching a real server
 * (mirrors the subprocess pattern in MigrationTest / ShowRunsScriptTest).
 *
 * The live deploy itself is the split-off deferred item for Story 1.10; what
 * is testable here is the script's argument handling, its rsync exclude list,
 * and the order of operations it performs. The end-to-end cases drive the real
 * script with `rsync` / `ssh` / `git` / `composer` replaced by stubs that log
 * their argv to $STOCKPICKER_STUB_LOG, then assert on the recorded call order.
 */
final class DeployScriptTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/..';

    /** rsync excludes the runbook and this test both promise are always present. */
    private const REQUIRED_EXCLUDES = [
        'config.php',
        '.git/',
        'vendor/',
        '_bmad-output/',
        '_bmad/',
        'tests/',
        'docs/',
    ];

    private function script(): string
    {
        return (string) realpath(self::REPO_ROOT . '/bin/deploy.sh');
    }

    private function source(): string
    {
        return (string) file_get_contents($this->script());
    }

    // --- static source-contract checks -------------------------------------

    public function testScriptIsSyntacticallyValidBash(): void
    {
        [$code, $out] = $this->sh('bash', '-n', $this->script());

        self::assertSame(0, $code, $out);
    }

    public function testKeepsStrictBashMode(): void
    {
        self::assertStringContainsString('set -euo pipefail', $this->source());
    }

    public function testRsyncExcludeListContainsEveryRequiredEntry(): void
    {
        $source = $this->source();

        foreach (self::REQUIRED_EXCLUDES as $entry) {
            self::assertStringContainsString(
                "--exclude '" . $entry . "'",
                $source,
                sprintf('bin/deploy.sh must exclude %s from the --delete sync', $entry),
            );
        }

        // --delete is the point (mirror the repo); the exclude list is the safety net.
        self::assertStringContainsString('rsync -az --delete', $source);
    }

    public function testVendorExcludeIsConditionalOnWithLocalVendor(): void
    {
        $source = $this->source();

        // vendor/ is excluded only when it is NOT built locally (--with-local-vendor):
        // the exclude is added inside the `with_local_vendor -eq 0` branch, not in the
        // unconditional exclude list.
        self::assertStringNotContainsString("  --exclude 'vendor/'\n", $source);
        self::assertMatchesRegularExpression(
            '/with_local_vendor -eq 0 \]\];\s*then\s+excludes\+=\(--exclude \'vendor\/\'\)/s',
            $source,
        );
    }

    public function testMigrationsRunOnlyAsTheOneExplicitPhinxStepOverSsh(): void
    {
        $source = $this->source();

        // Exactly one place actually *invokes* a migration, and it is a plain
        // phinx call over SSH (the other mentions are the usage text and an echo).
        self::assertSame(
            1,
            substr_count($source, 'ssh "$SSH_HOST" "cd ${REMOTE_DIR} && vendor/bin/phinx migrate -e production"'),
            'the migrate step is a single explicit phinx call over SSH',
        );
        self::assertStringContainsString('if [[ $run_migrate -eq 1 ]]; then', $source);
        self::assertStringNotContainsString('phinx rollback', $source);
    }

    public function testHasAnSshReachabilityPreflightBeforeRsync(): void
    {
        $source = $this->source();

        $preflight = strpos($source, 'ssh -o BatchMode=yes');
        $rsync = strpos($source, 'rsync -az --delete');

        self::assertNotFalse($preflight, 'a BatchMode ssh probe must exist');
        self::assertNotFalse($rsync);
        self::assertLessThan($rsync, $preflight, 'the ssh probe must precede the --delete sync');
    }

    public function testShellcheckIsCleanWhenInstalled(): void
    {
        $shellcheck = trim((string) shell_exec('command -v shellcheck 2>/dev/null'));
        if ($shellcheck === '') {
            self::markTestSkipped('shellcheck not installed');
        }

        [$code, $out] = $this->sh($shellcheck, $this->script());

        self::assertSame(0, $code, $out);
    }

    // --- I/O & edge-case matrix, executed end-to-end with stubs ------------

    /** Matrix row: "Unknown flag" -> usage error, exit 2, nothing runs. */
    public function testUnknownFlagExitsTwoAndRunsNothing(): void
    {
        $stubDir = $this->makeCommandStubs();

        [$code, $out] = $this->runDeploy($stubDir, '--bogus');

        self::assertSame(2, $code, $out);
        self::assertStringContainsStringIgnoringCase('usage:', $out);
        self::assertSame([], $this->callLog($stubDir), 'a usage error must run nothing');
    }

    public function testHelpFlagExitsZeroWithUsageAndRunsNothing(): void
    {
        $stubDir = $this->makeCommandStubs();

        [$code, $out] = $this->runDeploy($stubDir, '--help');

        self::assertSame(0, $code, $out);
        self::assertStringContainsStringIgnoringCase('usage:', $out);
        self::assertSame([], $this->callLog($stubDir), '--help must run nothing');
    }

    /** Matrix row: "Routine deploy" -> preflight, rsync --delete, server composer, migrate. */
    public function testRoutineDeployRunsPreflightThenSyncThenServerComposerThenMigrate(): void
    {
        $stubDir = $this->makeCommandStubs(sshReachable: true);

        [$code, $out] = $this->runDeploy($stubDir);
        self::assertSame(0, $code, $out);

        $log = $this->callLog($stubDir);

        $probe = $this->firstIndex($log, 'ssh -o BatchMode=yes');
        $rsync = $this->firstIndex($log, 'rsync -az --delete');
        $serverComposer = $this->firstIndex($log, 'composer install --no-dev', onlyLinesStartingWith: 'ssh ');
        $migrate = $this->firstIndex($log, 'vendor/bin/phinx migrate -e production');

        self::assertGreaterThanOrEqual(0, $probe, 'ssh reachability probe was not run');
        self::assertGreaterThan($probe, $rsync, 'rsync must run after the ssh probe');
        self::assertGreaterThan($rsync, $serverComposer, 'server-side composer install must run after rsync');
        self::assertGreaterThan($serverComposer, $migrate, 'migrate must run last');

        self::assertStringContainsString('--delete', $log[$rsync]);
        self::assertSame(
            1,
            $this->countLines($log, 'vendor/bin/phinx migrate -e production'),
            'exactly one migrate call',
        );

        // The actual recorded argv must carry every safety-critical exclude, so a
        // refactor that drops "${excludes[@]}" from the rsync line can't slip past
        // green tests and --delete config.php / vendor/ off the server.
        foreach (['config.php', 'vendor/', '.git/', '.env', '*.pub', 'stockpicker-loopia', '.DS_Store'] as $entry) {
            self::assertStringContainsString(
                '--exclude ' . $entry,
                $log[$rsync],
                sprintf('the rsync that runs must exclude %s', $entry),
            );
        }
    }

    /** Matrix row: "Skip migrations" -> --no-migrate deploys with no phinx step. */
    public function testNoMigrateFlagSkipsThePhinxStep(): void
    {
        $stubDir = $this->makeCommandStubs(sshReachable: true);

        [$code, $out] = $this->runDeploy($stubDir, '--no-migrate');
        self::assertSame(0, $code, $out);

        $log = $this->callLog($stubDir);

        self::assertGreaterThanOrEqual(0, $this->firstIndex($log, 'rsync -az --delete'), 'still deploys the source');
        self::assertSame(
            0,
            $this->countLines($log, 'phinx migrate'),
            '--no-migrate must run no phinx migrate step',
        );
    }

    /** Matrix row: "Local-vendor fallback" -> --with-local-vendor builds vendor/ locally, no server composer. */
    public function testWithLocalVendorBuildsLocallyAndSyncsVendor(): void
    {
        $stubDir = $this->makeCommandStubs(sshReachable: true);

        [$code, $out] = $this->runDeploy($stubDir, '--with-local-vendor');
        self::assertSame(0, $code, $out);

        $log = $this->callLog($stubDir);

        // vendor/ is built locally (a bare `composer …`, not over ssh) …
        self::assertGreaterThanOrEqual(
            0,
            $this->firstIndex($log, 'composer install --no-dev', onlyLinesStartingWith: 'composer '),
            'local composer install was not run',
        );
        // … and NOT built on the server.
        self::assertSame(
            -1,
            $this->firstIndex($log, 'composer install', onlyLinesStartingWith: 'ssh '),
            '--with-local-vendor must not run composer over ssh',
        );

        $rsync = $this->firstIndex($log, 'rsync -az --delete');
        self::assertGreaterThanOrEqual(0, $rsync);
        self::assertStringNotContainsString(
            'vendor/',
            $log[$rsync],
            '--with-local-vendor must sync vendor/ (no --exclude vendor/)',
        );
    }

    /** Matrix row: "SSH unreachable" -> abort before rsync, non-zero exit, no partial deploy. */
    public function testUnreachableSshAbortsBeforeRsync(): void
    {
        $stubDir = $this->makeCommandStubs(sshReachable: false);

        [$code, $out] = $this->runDeploy($stubDir);

        self::assertNotSame(0, $code, $out);

        $log = $this->callLog($stubDir);
        self::assertGreaterThanOrEqual(0, $this->firstIndex($log, 'ssh -o BatchMode=yes'), 'the probe should still be attempted');
        self::assertSame(-1, $this->firstIndex($log, 'rsync'), 'no rsync may run when the ssh probe fails');
    }

    // --- helpers ----------------------------------------------------------

    /**
     * A throwaway directory of stub `rsync` / `ssh` / `git` / `composer` / `php`
     * executables that append `<name> <argv>` to $STOCKPICKER_STUB_LOG when
     * called. With $sshReachable false the `ssh` stub exits non-zero (so the
     * reachability probe fails); every other stub exits 0.
     */
    private function makeCommandStubs(bool $sshReachable = true): string
    {
        $dir = sys_get_temp_dir() . '/deploy-stub-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);

        $exitFor = [
            'rsync' => 0,
            'ssh' => $sshReachable ? 0 : 1,
            'git' => 0,
            'composer' => 0,
            'php' => 0,
        ];

        foreach ($exitFor as $name => $exit) {
            $path = $dir . '/' . $name;
            file_put_contents(
                $path,
                "#!/bin/sh\n"
                . "printf '%s %s\\n' '{$name}' \"\$*\" >> \"\$STOCKPICKER_STUB_LOG\"\n"
                . "exit {$exit}\n",
            );
            chmod($path, 0o755);
        }

        register_shutdown_function(static function () use ($dir): void {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        });

        return $dir;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runDeploy(string $stubDir, string ...$flags): array
    {
        return $this->sh(
            'env',
            'PATH=' . $stubDir . ':' . getenv('PATH'),
            'STOCKPICKER_STUB_LOG=' . $stubDir . '/calls.log',
            'bash',
            $this->script(),
            ...$flags,
        );
    }

    /**
     * @return list<string> the recorded `<name> <argv>` lines, in call order
     */
    private function callLog(string $stubDir): array
    {
        $file = $stubDir . '/calls.log';
        if (!is_file($file)) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", (string) file_get_contents($file)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * Index of the first log line containing $needle (optionally restricted to
     * lines starting with $onlyLinesStartingWith), or -1 if none match.
     *
     * @param list<string> $log
     */
    private function firstIndex(array $log, string $needle, ?string $onlyLinesStartingWith = null): int
    {
        foreach ($log as $i => $line) {
            if ($onlyLinesStartingWith !== null && !str_starts_with($line, $onlyLinesStartingWith)) {
                continue;
            }
            if (str_contains($line, $needle)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param list<string> $log
     */
    private function countLines(array $log, string $needle): int
    {
        return count(array_filter($log, static fn (string $line): bool => str_contains($line, $needle)));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function sh(string ...$args): array
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';

        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg((string) realpath(self::REPO_ROOT)) . ' && ' . $cmd, $output, $code);

        return [$code, implode("\n", $output)];
    }
}
