<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\ParcelCodeHelper;

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

$code = ParcelCodeHelper::buildDisbursementCode('REC', '', 482);
assert_eq('REC00482', $code, 'build code with pad');

assert_eq('482', ParcelCodeHelper::displayForBeneficiary($code, null, 'REC'), 'beneficiary pin no zeros');
assert_eq('REC00482', ParcelCodeHelper::displayFull($code, null, 'REC'), 'full code with letters');

$used = [];
$p1 = ParcelCodeHelper::generateRandomPin($used);
$p2 = ParcelCodeHelper::generateRandomPin($used);
assert_eq(true, $p1 !== $p2, 'unique pins');
assert_eq(5, strlen(ParcelCodeHelper::padPin($p1)), 'pad width 5');

$candidates = ParcelCodeHelper::matchSearchCandidates('482', 'REC', '');
assert_eq(true, in_array('REC00482', $candidates, true), 'search by digits finds padded code');

echo $failures === 0 ? "ALL PASSED\n" : "FAILURES: {$failures}\n";
exit($failures === 0 ? 0 : 1);
