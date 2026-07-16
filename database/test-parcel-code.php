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

$code = ParcelCodeHelper::buildDisbursementCode('REC', '', 4829103);
assert_eq('REC4829103', $code, 'build code with pad');

assert_eq('4829103', ParcelCodeHelper::displayForBeneficiary($code, null, 'REC'), 'beneficiary pin');
assert_eq('REC4829103', ParcelCodeHelper::displayFull($code, null, 'REC'), 'full code with letters');

$used = [];
$p1 = ParcelCodeHelper::generateRandomPin($used);
$p2 = ParcelCodeHelper::generateRandomPin($used);
assert_eq(true, $p1 !== $p2, 'unique pins');
assert_eq(7, strlen(ParcelCodeHelper::padPin($p1)), 'pad width 7');
assert_eq(true, $p1 >= ParcelCodeHelper::PIN_MIN, 'pin min 7 digits');
assert_eq(true, ParcelCodeHelper::isGuessablePin(1234567), 'sequential detected');
assert_eq(true, ParcelCodeHelper::isGuessablePin(1111111), 'repeated detected');

$candidates = ParcelCodeHelper::matchSearchCandidates('4829103', 'REC', '');
assert_eq(true, in_array('REC4829103', $candidates, true), 'search by digits finds padded code');

echo $failures === 0 ? "ALL PASSED\n" : "FAILURES: {$failures}\n";
exit($failures === 0 ? 0 : 1);
