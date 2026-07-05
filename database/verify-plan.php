<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\DistributionService;

$plan = DistributionService::plan(10000, 5, 500);

echo "Total: {$plan['total']}\n";
echo "Daily: {$plan['daily_counts'][0]}\n";
echo "Windows/day: {$plan['days'][0]['windows']}\n";
echo "Per window: " . implode(',', $plan['days'][0]['per_window']) . "\n";
echo "Total sheets: {$plan['total_delivery_sheets']}\n";

assert($plan['daily_counts'][0] === 2000);
assert($plan['days'][0]['windows'] === 4);
assert($plan['days'][0]['per_window'] === [500, 500, 500, 500]);
assert($plan['total_delivery_sheets'] === 20);
echo "OK — 10000/5/500 = 20 sheets\n";
