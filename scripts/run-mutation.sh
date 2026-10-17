#!/bin/sh
set -eu

# The Compose timeout covers both baselines, validation, and all mutant processes.
reports=/reports
run=$(mktemp -d "$reports/baseline-XXXXXX")
export MUTATION_BASELINE_DIR="$run"
printf '%s\n' "$run" > "$reports/latest-baseline.txt"
# A failed attempt must not leave a previous mutation score looking current.
rm -f "$reports/infection.json" "$reports/infection.log"

XDEBUG_MODE=coverage php -d auto_prepend_file=/work/scripts/coverage-filter.php \
    vendor/bin/phpunit --coverage-xml "$run/coverage-xml" \
    --coverage-php "$run/coverage.php" --log-junit "$run/coverage-junit.xml" \
    > "$run/coverage-output.log" 2>&1 || { cat "$run/coverage-output.log"; exit 1; }
cat "$run/coverage-output.log"
php scripts/check-coverage.php "$run/coverage.php" 90 85 \
    > "$run/coverage-gate.log" 2>&1 || { cat "$run/coverage-gate.log"; exit 1; }
cat "$run/coverage-gate.log"

XDEBUG_MODE=off php vendor/bin/phpunit --no-coverage --log-junit "$run/junit.xml" \
    > "$run/timing-output.log" 2>&1 || { cat "$run/timing-output.log"; exit 1; }
cat "$run/timing-output.log"

# Validate the actual reports before opting out of Infection's initial test run.
XDEBUG_MODE=off php <<'PHP' > "$run/input-validation.log" 2>&1
<?php
function fail(string $message): never {
    file_put_contents("php://stderr", $message."\n");
    exit(1);
}
function xml(string $path): DOMXPath {
    $dom = new DOMDocument();
    if (!is_file($path) || !$dom->load($path, LIBXML_NONET)) {
        fail("Missing or invalid XML: $path");
    }
    // Infection also consumes coverage XML without its default namespace.
    $plain = preg_replace('/ xmlns="[^"]*"/', '', $dom->saveXML());
    $dom->loadXML($plain, LIBXML_NONET);
    return new DOMXPath($dom);
}
function integer(DOMElement $node, string $attribute): int {
    $value = $node->getAttribute($attribute);
    if (!ctype_digit($value) || (float)$value > PHP_INT_MAX) {
        fail("Invalid $attribute on ".$node->tagName);
    }
    return (int)$value;
}
function duration(DOMElement $node): float {
    $value = $node->getAttribute('time');
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) {
        fail('Invalid baseline time');
    }
    return (float)$value;
}
function testIdentity(string $class, string $name): string {
    // Escape only the FQN, preserving dataset backslashes and every other byte.
    return str_replace('\\', '\\\\', $class).'::'.$name;
}
function coveringTestIdentity(string $id): string {
    $parts = explode('::', $id, 2);
    if (count($parts) !== 2) {
        fail('Invalid covering test identity');
    }
    [$class, $method] = $parts;
    // Infection 0.29.14 ArgumentsAndOptionsBuilder maps PHPUnit >=10 IDs
    // to JUnit/filter names, splitting only at the first dataset separator.
    $dataset = explode('#', $method, 2);
    if (count($dataset) === 2) {
        [$method, $key] = $dataset;
    }
    if (!preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/', $method)) {
        fail('Invalid covering test method');
    }
    if (count($dataset) === 2) {
        $method = is_numeric($key)
            ? sprintf('%s with data set #%s', $method, $key)
            : sprintf('%s with data set "%s"', $method, $key);
    }
    return testIdentity($class, $method);
}
function cases(string $path): array {
    $xpath = xml($path);
    if ($xpath->query('/testsuites/testsuite')->length === 0 || $xpath->query('//failure|//error|//skipped')->length !== 0) {
        fail("Baseline did not pass: $path");
    }
    $cases = [];
    $classes = [];
    foreach ($xpath->query('//testcase') as $case) {
        duration($case);
        $class = $case->getAttribute('class');
        $id = testIdentity($class, $case->getAttribute('name'));
        if ($class === '' || $case->getAttribute('name') === '' || isset($cases[$id])) {
            fail("Invalid or duplicate test identity: $path");
        }
        $cases[$id] = true;
        $classes[$class] = true;
    }
    foreach ($xpath->query('//testsuite') as $suite) {
        foreach (['failures', 'errors', 'skipped'] as $attribute) {
            if (!$suite->hasAttribute($attribute) || $suite->getAttribute($attribute) !== '0') {
                fail("Invalid baseline status: $path");
            }
        }
        $count = $xpath->query('.//testcase', $suite)->length;
        $time = duration($suite);
        if (integer($suite, 'tests') !== $count || ($count > 0 && $time <= 0)) {
            fail("Invalid suite count or aggregate time: $path");
        }
        $children = $xpath->query('./testsuite|./testcase', $suite);
        $sum = 0.0;
        foreach ($children as $child) {
            $sum += duration($child);
        }
        // PHPUnit writes %F (six decimal places): each rounded child and parent
        // contributes at most half a microsecond, plus floating-point arithmetic.
        $tolerance = ($children->length + 1) * 0.0000005 + PHP_FLOAT_EPSILON * max(1.0, $sum, $time) * ($children->length + 1);
        if (($count > 0 && $sum <= 0) || abs($time - $sum) > $tolerance) {
            fail("Inconsistent suite/case duration: $path");
        }
    }
    foreach ($classes as $class => $_) {
        $matches = [];
        foreach ($xpath->query('//testsuite') as $suite) {
            if ($suite->getAttribute('name') === $class) {
                $matches[] = $suite;
            }
        }
        if (count($matches) !== 1 || $matches[0]->getAttribute('file') === '' || duration($matches[0]) <= 0) {
            fail("Missing or invalid class suite: $class in $path");
        }
        foreach ($xpath->query('.//testcase', $matches[0]) as $case) {
            if ($case->getAttribute('class') !== $class) {
                fail("Inconsistent class suite: $path");
            }
        }
        foreach ($xpath->query('//testcase') as $case) {
            if ($case->getAttribute('class') === $class) {
                $parent = $case->parentNode;
                while ($parent !== null && $parent !== $matches[0]) {
                    $parent = $parent->parentNode;
                }
                if ($parent === null) {
                    fail("Test outside its class suite: $path");
                }
            }
        }
    }
    if ($cases === []) {
        fail("Empty baseline JUnit: $path");
    }
    ksort($cases);
    return $cases;
}
function lines(DOMXPath $xpath, DOMElement $node): array {
    $nodes = $xpath->query('./totals/lines', $node);
    if ($nodes->length !== 1) {
        fail('Missing or duplicate coverage line totals');
    }
    $line = $nodes[0];
    $values = [];
    foreach (['total', 'comments', 'code', 'executable', 'executed'] as $attribute) {
        $values[$attribute] = integer($line, $attribute);
    }
    $percent = $line->getAttribute('percent');
    $expected = $values['executable'] === 0 ? 0 : 100 * $values['executed'] / $values['executable'];
    // php-code-coverage Totals emits percentages rounded to two decimal places.
    if ($values['comments'] + $values['code'] !== $values['total'] || $values['executed'] > $values['executable'] || $values['executable'] > $values['total']
        || !is_numeric($percent) || !is_finite((float)$percent) || (float)$percent < 0 || (float)$percent > 100 || abs((float)$percent - $expected) > 0.005000001) {
        fail('Inconsistent coverage line totals');
    }
    return $values;
}
$run = getenv('MUTATION_BASELINE_DIR');
$coverageCases = cases("$run/coverage-junit.xml");
$timingCases = cases("$run/junit.xml");
if ($coverageCases !== $timingCases) {
    fail('Coverage and timing baselines executed different test cases');
}
$index = xml("$run/coverage-xml/index.xml");
$source = $index->evaluate('string(/phpunit/project/@source)');
$root = $index->query('/phpunit/project/directory');
if ($root->length !== 1 || !is_dir($source) || lines($index, $root[0])['executed'] === 0) {
    fail('Missing source or executed index coverage');
}
$coveredFiles = [];
foreach ($index->query('//file') as $file) {
    $href = $file->getAttribute('href');
    if ($href === '') {
        fail('Missing coverage report reference');
    }
    $report = xml("$run/coverage-xml/".$href);
    $nodes = $report->query('/phpunit/file');
    if ($nodes->length !== 1) {
        fail('Missing or duplicate coverage file');
    }
    $node = $nodes[0];
    $name = $node->getAttribute('name');
    $path = $node->getAttribute('path');
    $real = realpath($source.'/'.trim($path, '/').'/'.$name);
    if ($name === '' || $real === false || !is_file($real) || isset($coveredFiles[$real])) {
        fail('Invalid or duplicate coverage source');
    }
    $totals = lines($report, $node);
    if ($totals !== lines($index, $file)) {
        fail('Index/file coverage totals differ');
    }
    $seen = [];
    foreach ($report->query('./coverage/line', $node) as $line) {
        $nr = integer($line, 'nr');
        if ($nr < 1 || $nr > $totals['total'] || isset($seen[$nr]) || $report->query('./covered', $line)->length === 0) {
            fail('Invalid covered source line');
        }
        foreach ($report->query('./covered', $line) as $covered) {
            if (!isset($coverageCases[coveringTestIdentity($covered->getAttribute('by'))])) {
                fail('Unknown covering test');
            }
        }
        $seen[$nr] = true;
    }
    if (count($seen) !== $totals['executed']) {
        fail('Executed line totals disagree with coverage data');
    }
    foreach ($report->query('./class/method|./trait/method', $node) as $method) {
        $coverage = $method->getAttribute('coverage');
        if (!is_numeric($coverage) || !is_finite((float)$coverage) || (float)$coverage < 0 || (float)$coverage > 100
            || $method->getAttribute('name') === '' || integer($method, 'start') < 1 || integer($method, 'end') < integer($method, 'start') || integer($method, 'end') > $totals['total']) {
            fail('Invalid coverage method range');
        }
    }
    $coveredFiles[$real] = true;
}
foreach ($index->query('//directory') as $directory) {
    $totals = lines($index, $directory);
    $sum = array_fill_keys(array_keys($totals), 0);
    foreach ($index->query('./directory|./file', $directory) as $child) {
        foreach (lines($index, $child) as $attribute => $value) {
            $sum[$attribute] += $value;
        }
    }
    if ($sum !== $totals) {
        fail('Directory/file coverage totals differ');
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('src')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && !str_starts_with($file->getPathname(), 'src/Generated/')) {
        if (!isset($coveredFiles[$file->getRealPath()])) {
            fail('Incomplete XML coverage: '.$file->getPathname());
        }
    }
}
printf("Validated %d matching baseline cases and %d source coverage reports\n", count($timingCases), count($coveredFiles));
PHP
cat "$run/input-validation.log"

export XDEBUG_MODE=off
result=0
infection --coverage="$run" --skip-initial-tests --test-framework-options=--no-coverage \
    --threads=4 --only-covering-test-cases --with-uncovered --min-msi=80 --no-progress \
    > "$run/mutation-output.log" 2>&1 || result=$?
cat "$run/mutation-output.log"
php scripts/check-mutation.php "$reports/infection.json" 80 || exit 1
exit "$result"
