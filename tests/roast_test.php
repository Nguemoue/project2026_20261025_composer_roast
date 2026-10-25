<?php

declare(strict_types=1);

require dirname(__DIR__) . '/composer-roast.php';

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "ok  {$message}\n");
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL {$message}\n");
}

function pkg(string $name, ?string $time, $license = ['MIT'], $abandoned = null, string $version = 'v1.0.0'): array
{
    $row = ['name' => $name, 'version' => $version];
    if ($time !== null) {
        $row['time'] = $time;
    }
    if ($license !== null) {
        $row['license'] = $license;
    }
    if ($abandoned !== null) {
        $row['abandoned'] = $abandoned;
    }
    return $row;
}

function analyze_rows(array $prod, array $dev, string $now, int $stale = 730, int $ancient = 1460): array
{
    $instant = roast_parse_instant($now);
    if ($instant === null) {
        throw new RuntimeException('bad now');
    }
    return roast_analyze(
        ['packages' => $prod, 'packages-dev' => $dev],
        $instant,
        $stale,
        $ancient
    );
}

/**
 * @return array{0:int,1:string,2:string}
 */
function run_cli(array $args, ?string $cwd = null): array
{
    $script = dirname(__DIR__) . '/composer-roast.php';
    $command = [PHP_BINARY, '-n', $script];
    foreach ($args as $arg) {
        $command[] = $arg;
    }
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($escaped, $descriptors, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('proc_open failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [$code, (string) $stdout, (string) $stderr];
}

$now = '2026-10-25T00:00:00Z';
$root = dirname(__DIR__);

$scored = analyze_rows(
    [
        pkg('fresh/alpha', '2026-09-01T00:00:00+00:00', ['MIT'], null, 'v1.2.0'),
        pkg('edge/stale', '2024-10-25T00:00:00+00:00', 'MIT', null, 'v0.9.0'),
        pkg('old/thing', '2023-01-01T00:00:00+00:00', ['MIT'], null, 'v2.0.0'),
        pkg('fossil/lib', '2022-10-25T00:00:00+00:00', ['MIT'], null, 'v1.0.0'),
    ],
    [
        pkg('dead/pkg', '2026-08-01T00:00:00+00:00', null, true, 'v3.1.0'),
        pkg('mystery/x', null, ['MIT'], '  new/pkg  ', 'v0.0.1'),
    ],
    $now
);

check($scored['packages'] === 6 && $scored['prod'] === 4 && $scored['dev'] === 2, 'prod/dev counts');
check($scored['abandoned'] === 2, 'two abandoned');
check($scored['ancient'] === 1, 'one ancient (1461d boundary)');
check($scored['stale'] === 2, 'two stale, including the 730d edge');
check($scored['undated'] === 1, 'one undated');
check($scored['unlicensed'] === 1, 'one package with no license');
check($scored['score'] === 52, 'score is 52, got ' . $scored['score']);
check($scored['grade'] === 'compost', 'grade compost');
check($scored['penalty'] === 48, 'penalty 48');
check($scored['median_age_days'] === 730, 'median age 730');
check($scored['oldest']['name'] === 'fossil/lib' && $scored['oldest']['age_days'] === 1461, 'oldest fossil');
check($scored['abandoned_list'][0]['name'] === 'dead/pkg' && $scored['abandoned_list'][0]['replacement'] === null, 'abandoned true has no replacement');
check($scored['abandoned_list'][1]['replacement'] === 'new/pkg', 'replacement string is trimmed');
check(
    roast_share_line($scored) === 'composer-roast 52/100 (compost). 2 abandoned, 1 ancient, oldest: fossil/lib v1.0.0 (4.0y).',
    'share line'
);

$edge = analyze_rows(
    [
        pkg('just-ancient', '2022-10-26T00:00:00+00:00'),
        pkg('just-stale', '2022-10-27T00:00:00+00:00'),
        pkg('just-fresh', '2024-10-26T00:00:00+00:00'),
        pkg('zoneless', '2024-10-25T00:00:00'),
        pkg('offset', '2024-10-25T02:00:00+02:00'),
        pkg('future', '2027-01-01T00:00:00+00:00'),
        pkg('garbage-time', 'not-a-date'),
        pkg('license-spaces', '2026-10-01T00:00:00Z', '  MIT  '),
        pkg('license-array', '2026-10-01T00:00:00Z', ['', 'Apache-2.0']),
        pkg('empty-license', '2026-10-01T00:00:00Z', []),
        pkg('false-abandoned', '2026-10-01T00:00:00Z', ['MIT'], false),
        pkg('empty-abandoned', '2026-10-01T00:00:00Z', ['MIT'], ''),
        pkg('numeric-abandoned', '2026-10-01T00:00:00Z', ['MIT'], 1),
    ],
    [],
    $now
);
$byName = [];
foreach ($edge['fossils'] as $fossil) {
    $byName[$fossil['name']] = $fossil;
}
check($edge['ancient'] === 1, '2022-10-26 is ancient (1460d), got ancient=' . $edge['ancient']);
check($edge['stale'] === 3, '1459d, zoneless 730d and +02 offset 730d are stale, got ' . $edge['stale']);
check($edge['undated'] === 1, 'unparseable time is undated');
check($edge['unlicensed'] === 1, 'empty license array counts, spaced string and mixed array do not');
check($edge['abandoned'] === 0, 'false, empty string and integer 1 are not abandoned');

$half = analyze_rows(
    [
        pkg('old', '2020-01-01T00:00:00Z'),
        pkg('new', '2026-10-24T00:00:00Z'),
    ],
    [],
    $now
);
check($half['ancient'] === 1 && $half['score'] === 87 && $half['grade'] === 'healthy', 'round half up: 25*0.5 => 13, score 87');

$capAbandoned = analyze_rows(
    [
        pkg('a', '2026-10-01T00:00:00Z', ['MIT'], true),
        pkg('b', '2026-10-01T00:00:00Z', ['MIT'], true),
        pkg('c', '2026-10-01T00:00:00Z', ['MIT'], true),
        pkg('d', '2026-10-01T00:00:00Z', ['MIT'], 'next/thing'),
    ],
    [],
    $now
);
check($capAbandoned['penalty'] === 45 && $capAbandoned['score'] === 55 && $capAbandoned['grade'] === 'rusty', 'abandoned penalty caps at 45');

$capLicense = analyze_rows(
    [
        pkg('a', '2026-10-01T00:00:00Z', null),
        pkg('b', '2026-10-01T00:00:00Z', null),
        pkg('c', '2026-10-01T00:00:00Z', null),
        pkg('d', '2026-10-01T00:00:00Z', null),
        pkg('e', '2026-10-01T00:00:00Z', null),
        pkg('f', '2026-10-01T00:00:00Z', null),
    ],
    [],
    $now
);
check($capLicense['penalty'] === 10 && $capLicense['score'] === 90 && $capLicense['grade'] === 'pristine', 'unlicensed penalty caps at 10');

$empty = analyze_rows([], [], $now);
check($empty['score'] === 100 && $empty['grade'] === 'pristine' && $empty['median_age_days'] === null && $empty['oldest'] === null, 'empty lock is pristine');
check(roast_share_line($empty) === 'composer-roast 100/100 (pristine). 0 abandoned, 0 ancient, oldest: none.', 'empty share line');

check(roast_median([]) === null, 'median of nothing');
check(roast_median([4, 1, 9]) === 4, 'odd median');
check(roast_median([10, 21]) === 15.5, 'even median keeps the half day');
check(roast_median([10, 20]) === 15, 'even whole median becomes int');

$tie = analyze_rows(
    [
        pkg('zeta', '2020-01-01T00:00:00Z', ['MIT'], null, 'v2'),
        pkg('alpha', '2020-01-01T00:00:00Z', ['MIT'], null, 'v2'),
        pkg('alpha', '2020-01-01T00:00:00Z', ['MIT'], null, 'v1'),
    ],
    [],
    $now
);
check(
    $tie['fossils'][0]['name'] === 'alpha' && $tie['fossils'][0]['version'] === 'v1'
    && $tie['fossils'][1]['version'] === 'v2' && $tie['fossils'][2]['name'] === 'zeta',
    'fossil ties break by name then version'
);

$unnamed = analyze_rows([['time' => '2026-10-01T00:00:00Z', 'license' => ['MIT']]], [], $now);
check($unnamed['oldest']['name'] === '(unnamed)' && $unnamed['oldest']['version'] === '', 'missing name and version');
check($unnamed['oldest']['version'] === '', 'missing version stays empty in the data');

$threw = false;
try {
    analyze_rows(['nope'], [], $now);
} catch (InvalidArgumentException) {
    $threw = true;
}
check($threw, 'a non-object package is rejected');

$rendered = roast_render_text([
    'lock' => 'x',
    'now' => '2026-10-25T00:00:00Z',
    'packages' => 1,
    'prod' => 1,
    'dev' => 0,
    'score' => 20,
    'grade' => 'archaeological',
    'abandoned' => 0,
    'ancient' => 1,
    'stale' => 0,
    'undated' => 0,
    'unlicensed' => 0,
    'stale_days' => 730,
    'ancient_days' => 1460,
    'median_age_days' => 2000,
    'oldest' => ['name' => 'old/lib', 'version' => 'v0', 'age_days' => 2000, 'abandoned' => false, 'replacement' => null],
    'fossils' => [[
        'name' => 'old/lib', 'version' => 'v0', 'section' => 'prod', 'age_days' => 2000,
        'bucket' => 'ancient', 'abandoned' => false, 'replacement' => null,
    ]],
    'abandoned_list' => [],
    'share' => 'composer-roast 20/100 (archaeological). 0 abandoned, 1 ancient, oldest: old/lib v0 (5.5y).',
], true);
check(str_contains($rendered, "\033[31m20\033[0m"), 'low scores render in red when color is on');
check(!str_contains(roast_render_text([
    'lock' => 'x', 'now' => 't', 'packages' => 0, 'prod' => 0, 'dev' => 0,
    'score' => 100, 'grade' => 'pristine', 'abandoned' => 0, 'ancient' => 0,
    'stale' => 0, 'undated' => 0, 'unlicensed' => 0, 'stale_days' => 730,
    'ancient_days' => 1460, 'median_age_days' => null, 'oldest' => null,
    'fossils' => [], 'abandoned_list' => [], 'share' => 's',
], false), "\033["), 'color off stays plain');

$expectedText = <<<TXT
composer-roast
lock: {$root}/fixtures/scored.lock
as of: 2026-10-25T00:00:00Z
scored versions pinned in the lock, not upstream popularity

packages: 6 (4 prod / 2 dev)
score: 52/100  compost

abandoned      2
ancient        1    >= 1460d
stale          2    >= 730d
undated        1
no license     1
median age     730d (2.0y)

oldest         fossil/lib  v1.0.0  1461d (4.0y)

fossils
   1461d    4.0y  fossil/lib                    v1.0.0
   1393d    3.8y  old/thing                     v2.0.0
    730d    2.0y  edge/stale                    v0.9.0
     85d    0.2y  dead/pkg                      v3.1.0  abandoned
     54d    0.1y  fresh/alpha                   v1.2.0

abandoned
  dead/pkg@v3.1.0  no replacement
  mystery/x@v0.0.1  -> new/pkg

share this
  composer-roast 52/100 (compost). 2 abandoned, 1 ancient, oldest: fossil/lib v1.0.0 (4.0y).

TXT;

[$code, $stdout, $stderr] = run_cli([
    $root . '/fixtures/scored.lock',
    '--now=2026-10-25',
    '--no-color',
]);
check($code === 0 && $stderr === '' && $stdout === $expectedText, 'text report matches the scored fixture');
if ($stdout !== $expectedText) {
    fwrite(STDERR, "---- actual ----\n{$stdout}---- expected ----\n{$expectedText}");
}

[$code, $stdout, $stderr] = run_cli([
    $root . '/fixtures/scored.lock',
    '--now',
    '2026-10-25',
    '--json',
    '--fail-under=53',
]);
$json = json_decode($stdout, true);
check($code === 1 && is_array($json) && $json['score'] === 52 && $json['failed'] === true && $json['fail_under'] === 53, 'json fail-under exits 1');
check(($json['share'] ?? '') === 'composer-roast 52/100 (compost). 2 abandoned, 1 ancient, oldest: fossil/lib v1.0.0 (4.0y).', 'json share line');
check(!str_contains($stdout, "\033"), 'json has no color');

[$code, $stdout] = array_slice(run_cli([
    $root . '/fixtures/scored.lock',
    '--now=2026-10-25',
    '--fail-under=52',
    '--no-color',
]), 0, 2);
check($code === 0 && str_contains($stdout, 'fail-under: 52 (ok)'), 'score equal to fail-under passes');

[$code] = run_cli([$root . '/fixtures/healthy.lock', '--now=2026-10-25', '--fail-under=100', '--json']);
check($code === 0, 'healthy lock meets 100');

[$code, $stdout, $stderr] = run_cli(['--help']);
check($code === 0 && str_contains($stdout, 'Usage:') && $stderr === '', 'help exits 0');

[$code, , $stderr] = run_cli(['/no/such/composer.lock']);
check($code === 2 && str_contains($stderr, 'not found'), 'missing file exits 2');

$tmp = sys_get_temp_dir() . '/composer-roast-tests-' . getmypid();
mkdir($tmp);
file_put_contents($tmp . '/broken.lock', '{');
file_put_contents($tmp . '/not-lock.json', '{"name":"nope"}');
file_put_contents($tmp . '/bad-dev.lock', '{"packages":[],"packages-dev":{"name":"oops"}}');
file_put_contents($tmp . '/bom.lock', "\xEF\xBB\xBF" . file_get_contents($root . '/fixtures/empty.lock'));
file_put_contents($tmp . '/my lock.lock', file_get_contents($root . '/fixtures/empty.lock'));

[$code, , $stderr] = run_cli([$tmp . '/broken.lock']);
check($code === 2 && str_contains($stderr, 'invalid JSON'), 'broken json exits 2');
[$code, , $stderr] = run_cli([$tmp . '/not-lock.json']);
check($code === 2 && str_contains($stderr, 'not a composer.lock'), 'json without packages is rejected');
[$code] = run_cli([$tmp . '/bad-dev.lock', '--json']);
check($code === 2, 'packages-dev must be an array');
[$code, $stdout] = array_slice(run_cli([$tmp . '/bom.lock', '--now=2026-10-25', '--json']), 0, 2);
$bom = json_decode($stdout, true);
check($code === 0 && ($bom['score'] ?? null) === 100, 'UTF-8 BOM is ignored');
[$code, $stdout] = array_slice(run_cli([$tmp . '/my lock.lock', '--now=2026-10-25', '--json']), 0, 2);
check($code === 0 && str_contains($stdout, 'my lock.lock'), 'paths with spaces work');

[$code] = run_cli(['--stale-days=100', '--ancient-days=100']);
check($code === 2, 'ancient must be greater than stale');
[$code] = run_cli(['--fail-under=101']);
check($code === 2, 'fail-under above 100 is rejected');
[$code] = run_cli(['--nope']);
check($code === 2, 'unknown option exits 2');
[$code] = run_cli(['a.lock', 'b.lock']);
check($code === 2, 'two paths are rejected');
[$code] = run_cli(['--now=yesterday']);
check($code === 2, 'bad --now exits 2');

[$code, $stdout] = array_slice(run_cli([
    $root . '/fixtures/demo.lock',
    '--now=2026-10-25',
    '--json',
]), 0, 2);
$demo = json_decode($stdout, true);
check(
    $code === 0
    && is_array($demo)
    && $demo['score'] >= 0
    && $demo['score'] <= 100
    && $demo['abandoned'] === 4
    && $demo['packages'] === 12
    && str_starts_with($demo['share'], 'composer-roast '),
    'demo lock is readable and counts 4 abandoned flags'
);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}
fwrite(STDOUT, "all tests passed\n");
exit(0);
