<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\ArabicFormat;

$failures = 0;
function assert_eq($expected, $actual, string $label): void
{
    global $failures;
    if ($expected !== $actual) {
        echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
        $failures++;
    } else {
        echo "OK: {$label}\n";
    }
}

assert_eq('1234567890', ArabicFormat::toArabicDigits('1234567890'), 'display digits western');
assert_eq('1234567', ArabicFormat::toWesternDigits('١٢٣٤٥٦٧'), 'western digits from arabic input');
assert_eq('2:30 م', ArabicFormat::formatTime12('14:30'), 'time 12h pm');
assert_eq('9:00 ص', ArabicFormat::formatTime12('09:00'), 'time 12h am');
assert_eq('12:00 م', ArabicFormat::formatTime12('12:00'), 'noon');
assert_eq('2026-07-16', ArabicFormat::formatDate('2026-07-16'), 'western date');

echo $failures === 0 ? "ALL PASSED\n" : "FAILURES: {$failures}\n";
exit($failures === 0 ? 0 : 1);
