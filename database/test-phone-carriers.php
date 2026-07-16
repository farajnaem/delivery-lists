<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\PhoneHelper;

$tests = [
    ['0599123456', PhoneHelper::CARRIER_JAWWAL, '599123456'],
    ['599123456', PhoneHelper::CARRIER_JAWWAL, '599123456'],
    ['0569123456', PhoneHelper::CARRIER_OOREDOO, '972569123456'],
    ['569123456', PhoneHelper::CARRIER_OOREDOO, '972569123456'],
    ['972569123456', PhoneHelper::CARRIER_OOREDOO, '972569123456'],
    ['0512345678', PhoneHelper::CARRIER_OTHER, '512345678'],
];

$ok = true;
foreach ($tests as [$mobile, $expectedCarrier, $expectedRecipient]) {
    $carrier = PhoneHelper::carrier($mobile);
    $recipient = PhoneHelper::messageRecipient($mobile);
    $pass = $carrier === $expectedCarrier && $recipient === $expectedRecipient;
    $ok = $ok && $pass;
    echo ($pass ? 'OK' : 'FAIL') . " mobile={$mobile} carrier={$carrier} recipient={$recipient}\n";
}

exit($ok ? 0 : 1);
