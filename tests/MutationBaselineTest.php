<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MutationBaselineTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function scenarios(): iterable
    {
        yield 'actual timing inputs' => ['pass', true];
        yield 'rounded timings' => ['rounded-time', true];
        yield 'uncovered file remains valid' => ['uncovered-file', true];
        yield 'rounded coverage percent' => ['rounded-coverage', true];
        yield 'zero aggregate times' => ['zero-time', false];
        yield 'zero class time' => ['zero-class-time', false];
        yield 'inconsistent class duration' => ['class-time', false];
        yield 'inconsistent outer duration' => ['suite-time', false];
        yield 'inconsistent dataset duration' => ['dataset-time', false];
        yield 'inconsistent class count' => ['class-count', false];
        yield 'inconsistent nested count' => ['dataset-count', false];
        yield 'missing class suite' => ['missing-class', false];
        yield 'coverage timing mismatch' => ['coverage-time', false];
        yield 'missing index totals' => ['missing-index-totals', false];
        yield 'zero index coverage' => ['zero-index-coverage', false];
        yield 'missing file totals' => ['missing-file-totals', false];
        yield 'invalid coverage percent' => ['coverage-percent', false];
        yield 'out of bounds coverage percent' => ['coverage-percent-range', false];
        yield 'invalid coverage counts' => ['coverage-count', false];
        yield 'index file totals mismatch' => ['index-file-totals', false];
        yield 'directory totals mismatch' => ['directory-totals', false];
        yield 'missing coverage data' => ['missing-coverage-data', false];
        yield 'coverage executed count mismatch' => ['coverage-data-count', false];
        yield 'duplicate covered line' => ['duplicate-line', false];
        yield 'invalid covered line' => ['invalid-line', false];
        yield 'unknown covering test' => ['unknown-test', false];
        yield 'missing covering test' => ['missing-covered', false];
        yield 'invalid method range' => ['method-range', false];
        yield 'invalid method coverage' => ['method-coverage', false];
        yield 'coverage baseline failure' => ['coverage-failure', false];
        yield 'coverage floor failure' => ['floor-failure', false];
        yield 'timing baseline failure' => ['timing-failure', false];
        yield 'missing coverage index' => ['missing-index', false];
        yield 'missing source coverage' => ['missing-source', false];
        yield 'missing timing report' => ['missing-timing', false];
        yield 'different test selection' => ['different-cases', false];
        yield 'invalid nominal time' => ['invalid-time', false];
        yield 'failed timing JUnit' => ['failed-junit', false];
        yield 'empty baseline' => ['empty-baseline', false];
    }

    #[DataProvider('scenarios')]
    public function testMutantsRequireSuccessfulCompleteBaselines(string $scenario, bool $passes): void
    {
        $dir = sys_get_temp_dir().'/mutation-harness-'.bin2hex(random_bytes(8));
        mkdir($dir);
        mkdir($dir.'/reports');
        $script = self::readText(__DIR__.'/../scripts/run-mutation.sh');
        file_put_contents($dir.'/fixture.php', <<<'FIXTURE'
<?php
$mode = getenv('HARNESS_SCENARIO');
$args = array_slice($argv, 1);
$run = getenv('MUTATION_BASELINE_DIR');
if (in_array('scripts/check-coverage.php', $args, true)) {
    exit($mode === 'floor-failure' ? 1 : 0);
}
if (in_array('scripts/check-mutation.php', $args, true)) {
    exit(is_file(dirname($run).'/infection.json') ? 0 : 1);
}
$timing = in_array('--no-coverage', $args, true);
file_put_contents(dirname($run).'/commands.log', getenv('XDEBUG_MODE').' '.implode(' ', $args)."\n", FILE_APPEND);
if (($timing && $mode === 'timing-failure') || (!$timing && $mode === 'coverage-failure')) {
    exit(1);
}
$time = $timing ? '0.25' : '99.125';
$caseTime = $timing ? '0.125' : '49.5625';
if ($mode === 'zero-time') {
    $time = $caseTime = '0';
}
if ($timing && $mode === 'invalid-time') {
    $caseTime = 'NaN';
}
$name = $timing && $mode === 'different-cases' ? 'different' : 'testExample';
$count = $mode === 'empty-baseline' ? 0 : 2;
$status = $timing && $mode === 'failed-junit' ? 1 : 0;
$classTime = $timing && $mode === 'zero-class-time' ? '0' : $time;
if (($timing && $mode === 'class-time') || (!$timing && $mode === 'coverage-time')) {
    $classTime = '1';
}
$outerTime = $timing && $mode === 'suite-time' ? '1' : $time;
$datasetTime = $timing && $mode === 'dataset-time' ? '1' : $time;
if ($timing && $mode === 'rounded-time') {
    $classTime = $outerTime = $datasetTime = '0.250001';
}
$classCount = $timing && $mode === 'class-count' ? 3 : $count;
$datasetCount = $timing && $mode === 'dataset-count' ? 3 : $count;
$class = $mode === 'missing-class' ? 'Other' : 'Example';
$cases = $count ? '<testcase class="Example" name="'.$name.'" time="'.$caseTime.'"/><testcase class="Example" name="testOther" time="'.$caseTime.'"/>' : '';
$suite = static fn ($name, $tests, $duration, $children, $file = '') => '<testsuite name="'.$name.'" file="'.$file.'" tests="'.$tests.'" errors="'.$status.'" failures="0" skipped="0" time="'.$duration.'">'.$children.'</testsuite>';
$junit = '<testsuites>'.$suite('unit', $count, $outerTime,
    $suite($class, $classCount, $classTime, $suite('dataset', $datasetCount, $datasetTime, $cases), __FILE__)).'</testsuites>';
if (!$timing || $mode !== 'missing-timing') {
    file_put_contents($run.($timing ? '/junit.xml' : '/coverage-junit.xml'), $junit);
}
if (!$timing) {
    mkdir($run.'/coverage-xml');
    file_put_contents($run.'/coverage.php', '<?php return null;');
    $totals = static fn ($total, $executable, $executed, $percent = null) => '<totals><lines total="'.$total.'" comments="0" code="'.$total.'" executable="'.$executable.'" executed="'.$executed.'" percent="'.($percent ?? ($executable ? sprintf('%.2F', 100 * $executed / $executable) : '0')).'"/></totals>';
    $files = '';
    $i = $sumTotal = $sumExecutable = $sumExecuted = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src')) as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php' || str_starts_with($file->getPathname(), 'src/Generated/')) {
            continue;
        }
        if ($mode === 'missing-source' && $i === 0) {
            $i++;
            continue;
        }
        $first = $i === 0;
        $href = 'file-'.$i++.'.xml';
        $total = in_array($mode, ['rounded-coverage', 'coverage-data-count'], true) ? 3 : 1;
        $executable = $total;
        $executed = $first && $mode === 'uncovered-file' ? 0 : 1;
        $sumTotal += $total;
        $sumExecutable += $executable;
        $sumExecuted += $executed;
        $fileTotals = $totals($total, $executable, $executed);
        $indexTotals = $first && $mode === 'index-file-totals' ? $totals($total, $executable, 0) : $fileTotals;
        $files .= '<file href="'.$href.'">'.$indexTotals.'</file>';
        if ($first && $mode === 'missing-file-totals') {
            $fileTotals = '';
        } elseif ($first && $mode === 'coverage-percent') {
            $fileTotals = $totals($total, $executable, $executed, '0');
        } elseif ($first && $mode === 'coverage-percent-range') {
            $fileTotals = $totals($total, $executable, $executed, '101');
        } elseif ($first && $mode === 'coverage-count') {
            $fileTotals = $totals($total, $executable, 2);
        }
        $nr = $first && $mode === 'invalid-line' ? 0 : 1;
        $by = $first && $mode === 'unknown-test' ? 'Example::unknown' : 'Example::testExample';
        $covered = $first && $mode === 'missing-covered' ? '' : '<covered by="'.$by.'"/>';
        $line = '<line nr="'.$nr.'">'.$covered.'</line>';
        $data = $executed ? $line : '';
        if ($first && $mode === 'duplicate-line') {
            $data .= $line;
        } elseif ($first && $mode === 'coverage-data-count') {
            $data .= '<line nr="2">'.$covered.'</line>';
        } elseif ($first && $mode === 'missing-coverage-data') {
            $data = '';
        }
        $methodEnd = $first && $mode === 'method-range' ? 0 : 1;
        $methodCoverage = $first && $mode === 'method-coverage' ? 'NaN' : ($executed ? '100' : '0');
        $method = '<class name="Example"><method name="example" start="1" end="'.$methodEnd.'" coverage="'.$methodCoverage.'"/></class>';
        $path = substr($file->getPath(), 3);
        file_put_contents($run.'/coverage-xml/'.$href, '<phpunit xmlns="https://schema.phpunit.de/coverage/1.0"><file name="'.$file->getFilename().'" path="'.$path.'">'.$fileTotals.$method.'<coverage>'.$data.'</coverage></file></phpunit>');
    }
    $directoryTotals = $totals($sumTotal, $sumExecutable, $sumExecuted);
    $rootTotals = match ($mode) {
        'missing-index-totals' => '',
        'zero-index-coverage' => $totals($sumTotal, $sumExecutable, 0),
        'directory-totals' => $totals($sumTotal + 1, $sumExecutable, $sumExecuted),
        default => $directoryTotals,
    };
    $index = '<phpunit xmlns="https://schema.phpunit.de/coverage/1.0"><project source="'.getcwd().'/src"><directory name="/">'.$rootTotals.'<directory name="nested">'.$directoryTotals.$files.'</directory></directory></project></phpunit>';
    if ($mode !== 'missing-index') {
        file_put_contents($run.'/coverage-xml/index.xml', $index);
    }
}
FIXTURE);
        $php = escapeshellarg(PHP_BINARY);
        file_put_contents($dir.'/php', "#!/bin/sh\nif [ \"\$#\" -eq 0 ]; then exec $php; fi\nexec $php ".escapeshellarg($dir.'/fixture.php')." \"\$@\"\n");
        file_put_contents($dir.'/infection', "#!/bin/sh\nprintf '%s\\n' \"\$XDEBUG_MODE \$*\" > ".escapeshellarg($dir.'/mutants.log')."\nprintf '%s' '{\"stats\":{\"killedCount\":80,\"totalMutantsCount\":100}}' > ".escapeshellarg($dir.'/reports/infection.json')."\n");
        chmod($dir.'/php', 0755);
        chmod($dir.'/infection', 0755);
        // Invoke stubs through sh: Docker's /tmp may be mounted noexec.
        $script = str_replace('reports=/reports', 'reports='.escapeshellarg($dir.'/reports'), $script);
        $script = preg_replace('/^((?:XDEBUG_MODE=(?:coverage|off) )?)php\b/m', '$1sh '.escapeshellarg($dir.'/php'), $script);
        self::assertIsString($script);
        $script = preg_replace('/^infection /m', 'sh '.escapeshellarg($dir.'/infection').' ', $script);
        self::assertIsString($script);
        file_put_contents($dir.'/run.sh', $script);
        // A failed new attempt must invalidate a previous score.
        file_put_contents($dir.'/reports/infection.json', 'stale');
        $process = proc_open(
            ['sh', $dir.'/run.sh'],
            [1 => ['file', $dir.'/output.log', 'w'], 2 => ['file', $dir.'/error.log', 'w']],
            $pipes,
            dirname(__DIR__),
            array_merge(getenv(), ['PATH' => $dir.':'.getenv('PATH'), 'HARNESS_SCENARIO' => $scenario]),
        );
        self::assertIsResource($process);
        $status = proc_close($process);
        try {
            self::assertSame($passes ? 0 : 1, $status, self::readText($dir.'/output.log').self::readText($dir.'/error.log'));
            self::assertSame($passes, is_file($dir.'/mutants.log'));
            self::assertSame($passes, is_file($dir.'/reports/infection.json'));
            if ($passes) {
                $run = trim(self::readText($dir.'/reports/latest-baseline.txt'));
                self::assertStringContainsString('time="99.125"', self::readText($run.'/coverage-junit.xml'));
                self::assertStringContainsString($scenario === 'rounded-time' ? 'time="0.250001"' : 'time="0.25"', self::readText($run.'/junit.xml'));
                $command = self::readText($dir.'/mutants.log');
                self::assertStringContainsString('off --coverage='.$run.' --skip-initial-tests --test-framework-options=--no-coverage', $command);
                self::assertStringContainsString('--threads=4 --only-covering-test-cases --with-uncovered --min-msi=80', $command);
                self::assertStringNotContainsString('--timeout', $command);
                $commands = self::readText($dir.'/reports/commands.log');
                self::assertStringContainsString('coverage -d auto_prepend_file=', $commands);
                self::assertStringContainsString('off vendor/bin/phpunit --no-coverage', $commands);
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                self::assertInstanceOf(\SplFileInfo::class, $file);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function providerIdentityScenarios(): iterable
    {
        yield 'real producer named numeric and special keys' => ['pass', true];
        yield 'unknown named key' => ['unknown-named', false];
        yield 'unknown numeric key' => ['unknown-numeric', false];
        yield 'named hash key differs from numeric key' => ['hash-numeric', false];
        yield 'quotes are preserved' => ['unknown-quotes', false];
        yield 'literal dataset description is preserved' => ['unknown-literal', false];
        yield 'JUnit spelling is not a coverage ID' => ['junit-reference', false];
        yield 'duplicate canonical identity' => ['collision', false];
        yield 'distinct names across baselines' => ['different-key', false];
    }

    #[DataProvider('providerIdentityScenarios')]
    public function testRealProviderReportsMatchPinnedConsumerIdentities(string $scenario, bool $passes): void
    {
        $dir = sys_get_temp_dir().'/mutation-identities-'.bin2hex(random_bytes(8));
        mkdir($dir);
        mkdir($dir.'/src');
        file_put_contents($dir.'/src/Example.php', "<?php\nfunction mutationIdentityExample(): int { return 42; }\n");
        file_put_contents($dir.'/ProviderIdentityTest.php', <<<'FIXTURE'
<?php
namespace Mutation\Identity;
require __DIR__.'/src/Example.php';
final class ProviderIdentityTest extends \PHPUnit\Framework\TestCase
{
    public static function identities(): iterable
    {
        yield 'maximum' => [42];
        yield 0 => [42];
        yield 'has "quotes"' => [42];
        yield '#0' => [42];
        yield 'prefix#suffix' => [42];
        yield 'literal with data set "maximum"' => [42];
        yield 'back\slash' => [42];
        yield '' => [42];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('identities')]
    public function testExample(int $expected): void
    {
        self::assertSame($expected, mutationIdentityExample());
    }
}
FIXTURE);
        try {
            $base = [PHP_BINARY, '-d', 'xdebug.mode=coverage', dirname(__DIR__).'/vendor/bin/phpunit',
                '--no-configuration', '--bootstrap', dirname(__DIR__).'/vendor/autoload.php'];
            foreach ([false, true] as $timing) {
                $args = $timing
                    ? ['--no-coverage', '--log-junit', $dir.'/junit.xml']
                    : ['--coverage-filter', $dir.'/src', '--coverage-xml', $dir.'/coverage-xml', '--log-junit', $dir.'/coverage-junit.xml'];
                $status = self::runIdentityCommand(array_merge($base, $args, [$dir.'/ProviderIdentityTest.php']), $dir);
                self::assertSame(0, $status, self::readText($dir.'/identity-output.log'));
            }
            $coverage = new \DOMDocument();
            self::assertTrue($coverage->load($dir.'/coverage-xml/Example.php.xml'));
            $covered = $coverage->getElementsByTagName('covered');
            $ids = [];
            foreach ($covered as $node) {
                $ids[] = $node->getAttribute('by');
            }
            $prefix = 'Mutation\\Identity\\ProviderIdentityTest::testExample';
            $expectedIds = array_map(
                static fn (string $key): string => $prefix.'#'.$key,
                ['maximum', '0', 'has "quotes"', '#0', 'prefix#suffix', 'literal with data set "maximum"', 'back\\slash', ''],
            );
            self::assertEqualsCanonicalizing($expectedIds, array_values(array_unique($ids)));
            if (str_starts_with($scenario, 'unknown-') || in_array($scenario, ['hash-numeric', 'junit-reference'], true)) {
                $replacement = match ($scenario) {
                    'unknown-named' => $prefix.'#missing',
                    'unknown-numeric' => $prefix.'#99',
                    'hash-numeric' => $prefix.'###0',
                    'unknown-quotes' => $prefix.'#has quotes',
                    'unknown-literal' => $prefix.'#literal with data set maximum',
                    default => $prefix.' with data set "maximum"',
                };
                $node = $covered->item(0);
                self::assertInstanceOf(\DOMElement::class, $node);
                $node->setAttribute('by', $replacement);
                $coverage->save($dir.'/coverage-xml/Example.php.xml');
            } elseif (in_array($scenario, ['collision', 'different-key'], true)) {
                $junit = new \DOMDocument();
                self::assertTrue($junit->load($dir.'/junit.xml'));
                $cases = $junit->getElementsByTagName('testcase');
                $first = $cases->item(0);
                $second = $cases->item(1);
                self::assertInstanceOf(\DOMElement::class, $first);
                self::assertInstanceOf(\DOMElement::class, $second);
                $second->setAttribute('name', $scenario === 'collision' ? $first->getAttribute('name') : 'testExample with data set "other"');
                $junit->save($dir.'/junit.xml');
            }
            $script = self::readText(__DIR__.'/../scripts/run-mutation.sh');
            self::assertSame(1, preg_match("/php <<'PHP'[^\\n]*\\n(.*?)\\nPHP\\n/s", $script, $matches));
            file_put_contents($dir.'/validator.php', $matches[1]);
            $status = self::runIdentityCommand([PHP_BINARY, $dir.'/validator.php'], $dir, ['MUTATION_BASELINE_DIR' => $dir]);
            $output = self::readText($dir.'/identity-output.log');
            self::assertSame($passes ? 0 : 1, $status, $output);
            if ($passes) {
                self::assertStringContainsString('Validated 8 matching baseline cases and 1 source coverage reports', $output);
            } elseif ($scenario === 'collision') {
                self::assertStringContainsString('Invalid or duplicate test identity', $output);
            } elseif ($scenario === 'different-key') {
                self::assertStringContainsString('executed different test cases', $output);
            } else {
                self::assertMatchesRegularExpression('/Unknown covering test|Invalid covering test method/', $output);
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                self::assertInstanceOf(\SplFileInfo::class, $file);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function coveringFilterScenarios(): iterable
    {
        yield 'a filter that compiles passes through' => ['subset', 2, ''];
        yield 'an oversized dataset list runs its methods' => ['datasets', 600, 'covering methods'];
        yield 'an oversized method list runs its classes' => ['methods', 701, 'covering classes'];
    }

    #[DataProvider('coveringFilterScenarios')]
    public function testEveryCoveringTestRunsWhateverTheFilterSize(string $scenario, int $tests, string $widened): void
    {
        $dir = sys_get_temp_dir().'/mutation-filter-'.bin2hex(random_bytes(8));
        mkdir($dir);
        mkdir($dir.'/tests');
        // Sized so Infection's pattern is past PCRE's compiled-size limit yet within one 128 KiB argument.
        $methods = $scenario === 'methods' ? 700 : 1;
        $datasets = ['subset' => 3, 'datasets' => 600, 'methods' => 1][$scenario];
        $named = $scenario === 'datasets';
        $method = static fn (int $i): string => $methods === 1 ? 'testCovered' : sprintf('testCoveredByOneOfManyMethodsWhoseLongDescriptiveNamesFillTheFilter%04d', $i);
        $dataset = static fn (int $i): string => $named ? sprintf('"case %04d of a provider whose keys are long descriptions"', $i) : '#'.$i;
        $source = '';
        for ($i = 0; $i < $methods; $i++) {
            $source .= "    #[\\PHPUnit\\Framework\\Attributes\\DataProvider('cases')]\n    public function {$method($i)}(int \$value): void { self::assertSame(1, \$value); }\n";
        }
        file_put_contents($dir.'/tests/CoveredTest.php', <<<FIXTURE
<?php
namespace Mutation\\Filter;
final class CoveredTest extends \\PHPUnit\\Framework\\TestCase
{
    public static function cases(): iterable
    {
        for (\$i = 0; \$i < {$datasets}; \$i++) {
            yield ({$datasets} !== 600 ? \$i : sprintf('case %04d of a provider whose keys are long descriptions', \$i)) => [1];
        }
    }

{$source}    public function testNotCovering(): void { self::assertTrue(true); }
}
FIXTURE);
        file_put_contents($dir.'/tests/OtherTest.php', <<<'FIXTURE'
<?php
namespace Mutation\Filter;
final class OtherTest extends \PHPUnit\Framework\TestCase
{
    public function testCovered(): void { self::assertTrue(true); }
}
FIXTURE);
        // The covering tests as Infection 0.29.14 ArgumentsAndOptionsBuilder::buildForMutant passes
        // them: short class name, PHPUnit's data set spelling, each preg_quote'd, one alternation.
        $covering = [];
        for ($i = 0; $i < $methods; $i++) {
            for ($j = 0; $j < ($scenario === 'subset' ? 2 : $datasets); $j++) {
                $covering[] = preg_quote('CoveredTest::'.$method($i).' with data set '.$dataset($j), '/');
            }
        }
        $filter = '/'.implode('|', $covering).'/';
        try {
            self::assertSame($widened === '', @preg_match($filter, '') !== false);
            $run = static function (string $runner) use ($dir, $filter): string {
                $process = proc_open(
                    [PHP_BINARY, $runner, '--no-configuration', '--do-not-cache-result', '--bootstrap', dirname(__DIR__).'/vendor/autoload.php', '--filter', $filter, $dir.'/tests'],
                    [1 => ['file', $dir.'/stdout.log', 'w'], 2 => ['file', $dir.'/stderr.log', 'w']],
                    $pipes,
                    $dir,
                    array_merge(getenv(), ['XDEBUG_MODE' => 'off']),
                );
                self::assertIsResource($process);
                $status = proc_close($process);
                $output = self::readText($dir.'/stderr.log').self::readText($dir.'/stdout.log');
                self::assertSame(0, $status, $output);

                return $output;
            };
            if ($widened !== '') {
                self::assertStringContainsString('No tests executed!', $run(dirname(__DIR__).'/vendor/bin/phpunit'));
            }
            $output = $run(__DIR__.'/../scripts/infection-phpunit.php');
            self::assertStringContainsString("OK ({$tests} tests, {$tests} assertions)", $output);
            if ($widened === '') {
                self::assertStringNotContainsString('infection-phpunit:', $output);
            } else {
                self::assertStringContainsString("infection-phpunit: the covering-test filter does not compile; running its {$widened}", $output);
            }
        } finally {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                self::assertInstanceOf(\SplFileInfo::class, $file);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }

    /** @param list<string> $command
     * @param array<string, string> $environment
     */
    private static function runIdentityCommand(array $command, string $dir, array $environment = []): int
    {
        $process = proc_open(
            $command,
            [1 => ['file', $dir.'/identity-output.log', 'w'], 2 => ['file', $dir.'/identity-output.log', 'a']],
            $pipes,
            $dir,
            array_merge(getenv(), ['XDEBUG_MODE' => 'coverage'], $environment),
        );
        self::assertIsResource($process);

        return proc_close($process);
    }

    private static function readText(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

}
