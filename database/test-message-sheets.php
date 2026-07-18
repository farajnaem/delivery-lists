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
$all = CampaignService::beneficiariesDetailed($id);
if ($all === [] || empty($all[0]['disbursement_code'])) {
    echo "No generated beneficiaries.\n";
    exit(1);
}

$dayIndex = (int) ($all[0]['day_index'] ?? 1);
$path = ExcelExportService::exportMessagesForDay($id, $dayIndex);
echo "EXPORT={$path}\n";

$book = IOFactory::load($path);
$names = $book->getSheetNames();
echo 'SHEETS=' . implode(',', $names) . "\n";

$dayRows = array_values(array_filter(
    $all,
    static fn (array $b): bool => (int) ($b['day_index'] ?? 0) === $dayIndex
));

$expectedJ = 0;
$expectedO = 0;
$expectedOther = 0;
foreach ($dayRows as $b) {
    $carrier = PhoneHelper::carrier((string) ($b['mobile'] ?? ''));
    if ($carrier === PhoneHelper::CARRIER_JAWWAL) {
        $expectedJ++;
    } elseif ($carrier === PhoneHelper::CARRIER_OOREDOO) {
        $expectedO++;
    } else {
        $expectedOther++;
    }
}
echo "EXPECTED_JAWWAL={$expectedJ} EXPECTED_OOREDOO={$expectedO} EXPECTED_OTHER={$expectedOther}\n";

$checkSheet = static function (string $sheetName, int $expectedCount) use ($book): void {
    if ($expectedCount === 0) {
        return;
    }
    if (!in_array($sheetName, $book->getSheetNames(), true)) {
        echo "MISSING_SHEET={$sheetName}\n";
        exit(1);
    }
    $sheet = $book->getSheetByName($sheetName);
    $count = max(0, $sheet->getHighestRow() - 2);
    echo strtoupper($sheetName) . "_ROWS={$count}\n";
    if ($count !== $expectedCount) {
        echo "FAIL {$sheetName} row count mismatch expected={$expectedCount} got={$count}\n";
        exit(1);
    }

    $msg = (string) $sheet->getCell('C3')->getValue();
    if ($msg !== '' && preg_match('/[٠-٩]/u', $msg)) {
        echo "FAIL {$sheetName} message has eastern arabic digits\n";
        exit(1);
    }
    if ($msg !== '' && !preg_match('/\d/', $msg)) {
        echo "FAIL {$sheetName} message missing western digits\n";
        exit(1);
    }

    $mobile = (string) $sheet->getCell('B3')->getValue();
    $mobileDigits = preg_replace('/\D/u', '', $mobile) ?? '';
    if ($sheetName === 'رسائل_جوال' && $mobileDigits !== '' && str_starts_with($mobileDigits, '56')) {
        echo "FAIL jawwal sheet contains ooredoo-like number: {$mobile}\n";
        exit(1);
    }
    if ($sheetName === 'رسائل_أوريدو' && $mobileDigits !== '' && !str_starts_with($mobileDigits, '97256')) {
        echo "FAIL ooredoo sheet missing 972 prefix: {$mobile}\n";
        exit(1);
    }
};

$checkSheet('رسائل_جوال', $expectedJ);
$checkSheet('رسائل_أوريدو', $expectedO);
$checkSheet('رسائل_غير_مصنفة', $expectedOther);

echo "OK carrier sheets verified (western digits)\n";
