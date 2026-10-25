#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * composer-roast — score the health of a composer.lock.
 *
 * Scores the versions you actually pinned: abandonment, age, missing
 * licenses. No network. No dependencies. Does not replace `composer audit`.
 */

const ROAST_STALE_DAYS = 730;
const ROAST_ANCIENT_DAYS = 1460;
const ROAST_EXIT_OK = 0;
const ROAST_EXIT_FAIL = 1;
const ROAST_EXIT_USAGE = 2;

function roast_main(array $argv): int
{
    $parsed = roast_parse_args(array_slice($argv, 1));
    if ($parsed['error'] !== null) {
        fwrite(STDERR, "composer-roast: {$parsed['error']}\n");
        fwrite(STDERR, "Run with --help.\n");
        return ROAST_EXIT_USAGE;
    }
    if ($parsed['help']) {
        fwrite(STDOUT, roast_help());
        return ROAST_EXIT_OK;
    }

    try {
        $loaded = roast_load_lock($parsed['path']);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'composer-roast: ' . $e->getMessage() . "\n");
        return ROAST_EXIT_USAGE;
    }
    if ($loaded['error'] !== null) {
        fwrite(STDERR, "composer-roast: {$loaded['error']}\n");
        return ROAST_EXIT_USAGE;
    }

    $report = roast_analyze(
        $loaded['data'],
        $parsed['now'],
        $parsed['stale_days'],
        $parsed['ancient_days']
    );
    $report['lock'] = $parsed['path'];
    $report['now'] = $parsed['now']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $report['share'] = roast_share_line($report);

    if ($parsed['fail_under'] !== null) {
        $report['fail_under'] = $parsed['fail_under'];
        $report['failed'] = $report['score'] < $parsed['fail_under'];
    }

    if ($parsed['json']) {
        fwrite(STDOUT, json_encode(
            $report,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n");
    } else {
        $color = $parsed['color'] && roast_stdout_is_tty();
        fwrite(STDOUT, roast_render_text($report, $color));
    }

    return ($report['failed'] ?? false) === true ? ROAST_EXIT_FAIL : ROAST_EXIT_OK;
}

/**
 * @param list<string> $args
 * @return array{
 *   error: ?string,
 *   help: bool,
 *   json: bool,
 *   color: bool,
 *   path: string,
 *   now: DateTimeImmutable,
 *   stale_days: int,
 *   ancient_days: int,
 *   fail_under: ?int
 * }
 */
function roast_parse_args(array $args): array
{
    $result = [
        'error' => null,
        'help' => false,
        'json' => false,
        'color' => true,
        'path' => 'composer.lock',
        'now' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
        'stale_days' => ROAST_STALE_DAYS,
        'ancient_days' => ROAST_ANCIENT_DAYS,
        'fail_under' => null,
    ];

    $positionals = [];
    $count = count($args);
    for ($i = 0; $i < $count; $i++) {
        $arg = $args[$i];
        if ($arg === '--') {
            for ($j = $i + 1; $j < $count; $j++) {
                $positionals[] = $args[$j];
            }
            break;
        }
        if ($arg === '--help' || $arg === '-h') {
            $result['help'] = true;
            continue;
        }
        if ($arg === '--json') {
            $result['json'] = true;
            continue;
        }
        if ($arg === '--no-color') {
            $result['color'] = false;
            continue;
        }
        if (!str_starts_with($arg, '-')) {
            $positionals[] = $arg;
            continue;
        }
        if (!str_starts_with($arg, '--')) {
            $result['error'] = "unknown option: {$arg}";
            return $result;
        }

        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
        } else {
            $key = $arg;
            $next = $args[$i + 1] ?? null;
            if ($next === null || str_starts_with($next, '-')) {
                $result['error'] = "option {$key} expects a value";
                return $result;
            }
            $value = $next;
            $i++;
        }

        if ($key === '--now') {
            $now = roast_parse_instant($value);
            if ($now === null) {
                $result['error'] = "invalid --now date: {$value}";
                return $result;
            }
            $result['now'] = $now;
            continue;
        }
        if ($key === '--stale-days' || $key === '--ancient-days' || $key === '--fail-under') {
            if (preg_match('/^\d{1,6}$/', $value) !== 1) {
                $result['error'] = "integer expected for {$key}: {$value}";
                return $result;
            }
            $number = (int) $value;
            if ($key === '--fail-under' && $number > 100) {
                $result['error'] = '--fail-under must be between 0 and 100';
                return $result;
            }
            if ($key === '--stale-days') {
                $result['stale_days'] = $number;
            } elseif ($key === '--ancient-days') {
                $result['ancient_days'] = $number;
            } else {
                $result['fail_under'] = $number;
            }
            continue;
        }
        $result['error'] = "unknown option: {$key}";
        return $result;
    }

    if (count($positionals) > 1) {
        $result['error'] = 'only one composer.lock path is accepted';
        return $result;
    }
    if (count($positionals) === 1) {
        $result['path'] = $positionals[0];
    }
    if ($result['ancient_days'] <= $result['stale_days']) {
        $result['error'] = '--ancient-days must be greater than --stale-days';
        return $result;
    }
    $noColor = getenv('NO_COLOR');
    if ($noColor !== false && $noColor !== '') {
        $result['color'] = false;
    }
    return $result;
}

function roast_help(): string
{
    return <<<TXT
composer-roast — score the versions pinned in a composer.lock

Usage:
  php composer-roast.php [composer.lock] [options]

Options:
  --json                 Print one JSON object on stdout
  --now=YYYY-MM-DD       Freeze "today" (UTC). Accepts a full ISO-8601 instant
  --stale-days=N         Stale at N days old (default 730, about 2 years)
  --ancient-days=N       Ancient at N days old (default 1460, about 4 years)
  --fail-under=N         Exit 1 when the score is below N (0-100)
  --no-color             Disable ANSI colors
  -h, --help             Show this help

Exit codes:
  0  report printed, score is acceptable
  1  score is below --fail-under
  2  usage error, unreadable file, or invalid JSON

The score describes the lockfile you have, not whether upstream is popular.
A maintained package pinned to a 2019 release is still ancient here.
Abandoned follows Packagist's "abandoned" flag stored in composer.lock.
This is not a CVE scan. Use `composer audit` for known vulnerabilities.

TXT;
}

/**
 * @return array{error: ?string, data: ?array<string, mixed>}
 */
function roast_load_lock(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return ['error' => "file not found or unreadable: {$path}", 'data' => null];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return ['error' => "unable to read: {$path}", 'data' => null];
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    try {
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['error' => "invalid JSON ({$path}): " . $e->getMessage(), 'data' => null];
    }
    if (!is_array($data) || !array_key_exists('packages', $data) || !is_array($data['packages']) || !array_is_list($data['packages'])) {
        return ['error' => "not a composer.lock (missing or invalid \"packages\"): {$path}", 'data' => null];
    }
    if (array_key_exists('packages-dev', $data) && (!is_array($data['packages-dev']) || !array_is_list($data['packages-dev']))) {
        return ['error' => "invalid \"packages-dev\": {$path}", 'data' => null];
    }
    return ['error' => null, 'data' => $data];
}

/**
 * @param array<string, mixed> $lock
 * @return array<string, mixed>
 */
function roast_analyze(
    array $lock,
    DateTimeImmutable $now,
    int $staleDays,
    int $ancientDays
): array {
    $now = $now->setTimezone(new DateTimeZone('UTC'));
    $prod = roast_normalize_packages($lock['packages'] ?? [], 'prod', $now);
    $dev = roast_normalize_packages($lock['packages-dev'] ?? [], 'dev', $now);
    $packages = array_merge($prod, $dev);

    $abandoned = 0;
    $ancient = 0;
    $stale = 0;
    $undated = 0;
    $unlicensed = 0;
    $ages = [];
    $dated = [];
    $abandonedList = [];

    foreach ($packages as $package) {
        if ($package['abandoned']) {
            $abandoned++;
            $abandonedList[] = [
                'name' => $package['name'],
                'version' => $package['version'],
                'section' => $package['section'],
                'replacement' => $package['replacement'],
            ];
        }
        if (!$package['licensed']) {
            $unlicensed++;
        }
        if ($package['age_days'] === null) {
            $undated++;
            continue;
        }
        $age = $package['age_days'];
        $ages[] = $age;
        $bucket = 'fresh';
        if ($age >= $ancientDays) {
            $ancient++;
            $bucket = 'ancient';
        } elseif ($age >= $staleDays) {
            $stale++;
            $bucket = 'stale';
        }
        $dated[] = $package + ['bucket' => $bucket];
    }

    $total = count($packages);
    $penalty = 0;
    if ($total > 0) {
        $penalty += min(45, $abandoned * 15);
        $penalty += (int) round(30 * $stale / $total);
        $penalty += (int) round(25 * $ancient / $total);
        $penalty += (int) round(10 * $undated / $total);
        $penalty += min(10, $unlicensed * 2);
    }
    $score = max(0, 100 - $penalty);

    usort($dated, static function (array $a, array $b): int {
        if ($a['age_days'] !== $b['age_days']) {
            return $b['age_days'] <=> $a['age_days'];
        }
        $byName = $a['name'] <=> $b['name'];
        return $byName !== 0 ? $byName : $a['version'] <=> $b['version'];
    });
    usort($abandonedList, static function (array $a, array $b): int {
        $byName = $a['name'] <=> $b['name'];
        return $byName !== 0 ? $byName : $a['version'] <=> $b['version'];
    });

    $fossils = [];
    foreach (array_slice($dated, 0, 5) as $package) {
        $fossils[] = [
            'name' => $package['name'],
            'version' => $package['version'],
            'section' => $package['section'],
            'age_days' => $package['age_days'],
            'bucket' => $package['bucket'],
            'abandoned' => $package['abandoned'],
            'replacement' => $package['replacement'],
        ];
    }

    $oldest = $fossils[0] ?? null;

    return [
        'packages' => $total,
        'prod' => count($prod),
        'dev' => count($dev),
        'score' => $score,
        'grade' => roast_grade($score),
        'abandoned' => $abandoned,
        'ancient' => $ancient,
        'stale' => $stale,
        'undated' => $undated,
        'unlicensed' => $unlicensed,
        'stale_days' => $staleDays,
        'ancient_days' => $ancientDays,
        'median_age_days' => roast_median($ages),
        'oldest' => $oldest === null ? null : [
            'name' => $oldest['name'],
            'version' => $oldest['version'],
            'age_days' => $oldest['age_days'],
            'abandoned' => $oldest['abandoned'],
            'replacement' => $oldest['replacement'],
        ],
        'fossils' => $fossils,
        'abandoned_list' => $abandonedList,
        'penalty' => $penalty,
    ];
}

/**
 * @param mixed $rows
 * @return list<array{
 *   name: string,
 *   version: string,
 *   section: string,
 *   age_days: ?int,
 *   abandoned: bool,
 *   replacement: ?string,
 *   licensed: bool
 * }>
 */
function roast_normalize_packages($rows, string $section, DateTimeImmutable $now): array
{
    if (!is_array($rows)) {
        throw new InvalidArgumentException("invalid packages list ({$section})");
    }
    $out = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            $label = is_int($index) ? (string) $index : $index;
            throw new InvalidArgumentException("package #{$label} ({$section}) is not an object");
        }
        $name = $row['name'] ?? null;
        $version = $row['version'] ?? null;
        $time = $row['time'] ?? null;
        $instant = is_string($time) ? roast_parse_instant($time) : null;
        [$isAbandoned, $replacement] = roast_abandoned($row['abandoned'] ?? null);
        $out[] = [
            'name' => is_string($name) && $name !== '' ? $name : '(unnamed)',
            'version' => is_string($version) ? $version : '',
            'section' => $section,
            'age_days' => $instant === null ? null : roast_age_days($instant, $now),
            'abandoned' => $isAbandoned,
            'replacement' => $replacement,
            'licensed' => roast_has_license($row['license'] ?? null),
        ];
    }
    return $out;
}

/**
 * @return array{0: bool, 1: ?string}
 */
function roast_abandoned($value): array
{
    if ($value === true) {
        return [true, null];
    }
    if (is_string($value) && trim($value) !== '') {
        return [true, trim($value)];
    }
    return [false, null];
}

function roast_has_license($license): bool
{
    if (is_string($license)) {
        return trim($license) !== '';
    }
    if (!is_array($license)) {
        return false;
    }
    foreach ($license as $entry) {
        if (is_string($entry) && trim($entry) !== '') {
            return true;
        }
    }
    return false;
}

function roast_parse_instant(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $hasZone = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
    try {
        $instant = $hasZone
            ? new DateTimeImmutable($value)
            : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Exception) {
        return null;
    }
    return $instant->setTimezone(new DateTimeZone('UTC'));
}

function roast_age_days(DateTimeImmutable $released, DateTimeImmutable $now): int
{
    $delta = (int) floor(($now->getTimestamp() - $released->getTimestamp()) / 86400);
    return max(0, $delta);
}

/**
 * @param list<int> $ages
 */
function roast_median(array $ages): int|float|null
{
    $count = count($ages);
    if ($count === 0) {
        return null;
    }
    sort($ages, SORT_NUMERIC);
    if ($count % 2 === 1) {
        return $ages[intdiv($count, 2)];
    }
    $average = ($ages[$count / 2 - 1] + $ages[$count / 2]) / 2;
    if (abs($average - round($average)) < 1e-9) {
        return (int) round($average);
    }
    return $average;
}

function roast_grade(int $score): string
{
    if ($score >= 90) {
        return 'pristine';
    }
    if ($score >= 75) {
        return 'healthy';
    }
    if ($score >= 55) {
        return 'rusty';
    }
    if ($score >= 30) {
        return 'compost';
    }
    return 'archaeological';
}

function roast_years(int|float $days): string
{
    return number_format($days / 365.25, 1, '.', '') . 'y';
}

function roast_format_days(int|float $days): string
{
    if (abs($days - round($days)) < 1e-9) {
        return (string) (int) round($days);
    }
    return number_format($days, 1, '.', '');
}

/**
 * @param array<string, mixed> $report
 */
function roast_share_line(array $report): string
{
    $oldest = $report['oldest'];
    if (!is_array($oldest)) {
        $old = 'oldest: none';
    } else {
        $version = $oldest['version'] !== '' ? $oldest['version'] : 'unknown';
        $old = sprintf(
            'oldest: %s %s (%s)',
            $oldest['name'],
            $version,
            roast_years((int) $oldest['age_days'])
        );
    }
    return sprintf(
        'composer-roast %d/100 (%s). %d abandoned, %d ancient, %s.',
        $report['score'],
        $report['grade'],
        $report['abandoned'],
        $report['ancient'],
        $old
    );
}

/**
 * @param array<string, mixed> $report
 */
function roast_render_text(array $report, bool $color): string
{
    $paint = static function (string $text, string $code) use ($color): string {
        if (!$color) {
            return $text;
        }
        return "\033[{$code}m{$text}\033[0m";
    };
    $score = (int) $report['score'];
    $scoreColor = $score >= 75 ? '32' : ($score >= 55 ? '33' : '31');
    $lines = [];
    $lines[] = $paint('composer-roast', '1');
    $lines[] = 'lock: ' . $report['lock'];
    $lines[] = 'as of: ' . $report['now'];
    $lines[] = 'scored versions pinned in the lock, not upstream popularity';
    $lines[] = '';
    $lines[] = sprintf(
        'packages: %d (%d prod / %d dev)',
        $report['packages'],
        $report['prod'],
        $report['dev']
    );
    $lines[] = 'score: ' . $paint((string) $score, $scoreColor)
        . '/100  ' . $paint((string) $report['grade'], $scoreColor);
    $lines[] = '';
    $lines[] = sprintf('abandoned      %d', $report['abandoned']);
    $lines[] = sprintf('ancient        %d    >= %dd', $report['ancient'], $report['ancient_days']);
    $lines[] = sprintf('stale          %d    >= %dd', $report['stale'], $report['stale_days']);
    $lines[] = sprintf('undated        %d', $report['undated']);
    $lines[] = sprintf('no license     %d', $report['unlicensed']);
    if ($report['median_age_days'] === null) {
        $lines[] = 'median age     n/a';
    } else {
        $median = $report['median_age_days'];
        $lines[] = sprintf(
            'median age     %sd (%s)',
            roast_format_days($median),
            roast_years($median)
        );
    }
    $lines[] = '';
    if (!is_array($report['oldest'])) {
        $lines[] = 'oldest         none';
    } else {
        $oldest = $report['oldest'];
        $version = $oldest['version'] !== '' ? $oldest['version'] : 'unknown';
        $lines[] = sprintf(
            'oldest         %s  %s  %dd (%s)',
            $oldest['name'],
            $version,
            $oldest['age_days'],
            roast_years((int) $oldest['age_days'])
        );
    }
    $lines[] = '';
    $lines[] = 'fossils';
    if ($report['fossils'] === []) {
        $lines[] = '  (none dated)';
    } else {
        foreach ($report['fossils'] as $fossil) {
            $flag = '';
            if ($fossil['abandoned']) {
                $flag = $fossil['replacement'] !== null
                    ? '  abandoned -> ' . $fossil['replacement']
                    : '  abandoned';
            }
            $version = $fossil['version'] !== '' ? $fossil['version'] : 'unknown';
            $lines[] = sprintf(
                '  %5dd  %6s  %-28s  %s%s',
                $fossil['age_days'],
                roast_years((int) $fossil['age_days']),
                $fossil['name'],
                $version,
                $flag
            );
        }
    }
    if ($report['abandoned_list'] !== []) {
        $lines[] = '';
        $lines[] = 'abandoned';
        foreach ($report['abandoned_list'] as $item) {
            $version = $item['version'] !== '' ? $item['version'] : 'unknown';
            $note = $item['replacement'] !== null ? '-> ' . $item['replacement'] : 'no replacement';
            $lines[] = sprintf('  %s@%s  %s', $item['name'], $version, $note);
        }
    }
    $lines[] = '';
    $lines[] = 'share this';
    $lines[] = '  ' . $report['share'];
    if (array_key_exists('fail_under', $report)) {
        $lines[] = '';
        $lines[] = sprintf(
            'fail-under: %d (%s)',
            $report['fail_under'],
            $report['failed'] ? 'failed' : 'ok'
        );
    }
    return implode("\n", $lines) . "\n";
}

function roast_stdout_is_tty(): bool
{
    return function_exists('stream_isatty') && defined('STDOUT') && @stream_isatty(STDOUT);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === __FILE__) {
    try {
        exit(roast_main($argv));
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, 'composer-roast: ' . $e->getMessage() . "\n");
        exit(ROAST_EXIT_USAGE);
    } catch (JsonException $e) {
        fwrite(STDERR, 'composer-roast: JSON: ' . $e->getMessage() . "\n");
        exit(ROAST_EXIT_USAGE);
    }
}
