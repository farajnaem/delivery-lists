<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\CampaignService;
use App\ExcelExportService;
use App\PhoneHelper;
use PhpOffice\PhpSpreadsheet\IOFactory;

$campaigns = CampaignService::all();
if (!$campaigns) {
    echo "No campaigns.\n";
    exit(1);
}

$id = (int) $campaigns[0]['id'];
$path = ExcelExportService::export($id);
echo "EXPORT={$path}\n";

$book = IOFactory::load($path);
$names = $book->getSheetNames();
echo 'SHEETS=' . implode(',', $names) . "\n";

$required = ['رسائل_جوال', 'رسائل_أوريدو'];
foreach ($required as $sheetName) {
    if (!in_array($sheetName, $names, true)) {
        echo "MISSING_SHEET={$sheetName}\n";
        exit(1);
    }
}

$jawwal = $book->getSheetByName('رسائل_جوال');
$ooredoo = $book->getSheetByName('رسائل_أوريدو');

$jawwalCount = max(0, $jawwal->getHighestRow() - 1);
$ooredooCount = max(0, $ooredoo->getHighestRow() - 1);
echo "JAWWAL_ROWS={$jawwalCount} OOREDOO_ROWS={$ooredooCount}\n";

$sampleJ = (string) $jawwal->getCell('B2')->getValue();
$sampleO = (string) $ooredoo->getCell('B2')->getValue();
if ($jawwalCount > 0 && str_starts_with($sampleJ, '56')) {
    echo "FAIL jawwal sheet contains ooredoo-like number: {$sampleJ}\n";
    exit(1);
}
if ($ooredooCount > 0 && !str_starts_with($sampleO, '97256')) {
    echo "FAIL ooredoo sheet missing 972 prefix: {$sampleO}\n";
    exit(1);
}

$all = CampaignService::beneficiariesDetailed($id);
$expectedJ = 0;
$expectedO = 0;
foreach ($all as $b) {
    $carrier = PhoneHelper::carrier((string) ($b['mobile'] ?? ''));
    if ($carrier === PhoneHelper::CARRIER_JAWWAL) {
        $expectedJ++;
    } elseif ($carrier === PhoneHelper::CARRIER_OOREDOO) {
        $expectedO++;
    }
}
echo "EXPECTED_JAWWAL={$expectedJ} EXPECTED_OOREDOO={$expectedO}\n";

if ($jawwalCount !== $expectedJ || $ooredooCount !== $expectedO) {
    echo "FAIL sheet row counts do not match classification\n";
    exit(1);
}

echo "OK carrier sheets verified\n";
