<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\DistributionService;

// 8000 ÷ (4×400) = 5 أيام عمل × 4 شبابيك = 20 كشف
$plan = DistributionService::plan(8000, 4, 400);

echo "Total: {$plan['total']}\n";
echo "Daily capacity: {$plan['daily_capacity']}\n";
echo "Days: {$plan['num_days']}\n";
echo "Windows/day: {$plan['days'][0]['windows']}\n";
echo "Per window day1: " . implode(',', $plan['days'][0]['per_window']) . "\n";
echo "Total sheets: {$plan['total_delivery_sheets']}\n";

assert($plan['daily_capacity'] === 1600);
assert($plan['num_days'] === 5);
assert($plan['days'][0]['windows'] === 4);
assert($plan['days'][0]['per_window'] === [400, 400, 400, 400]);
assert($plan['total_delivery_sheets'] === 20);

$dates = DistributionService::buildWorkDates('2026-07-01', 5); // Wed
foreach ($dates as $d) {
    assert((int) date('N', strtotime($d)) !== 5, "Friday in dates: {$d}");
}
assert(count($dates) === 5);
echo "Work dates: " . implode(', ', $dates) . "\n";

echo "OK — fixed windows + Friday skip\n";
