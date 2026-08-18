<?php

declare(strict_types=1);

namespace App;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExcelExportService
{
    private const HEADER_FILL = 'D9E2F3';
    private const SECTION_FILL = 'EEF2F7';
    private const META_LABEL_FILL = 'F5F7FA';

    /** مستفيد معتمد في يوم توزيع (لديه كود ويوم). */
    private static function isAssigned(array $b): bool
    {
        return (int) ($b['day_index'] ?? 0) > 0
            && trim((string) ($b['disbursement_code'] ?? '')) !== '';
    }

    /**
     * @param list<array<string, mixed>> $all
     * @return list<array<string, mixed>>
     */
    private static function assignedOnly(array $all): array
    {
        return array_values(array_filter($all, [self::class, 'isAssigned']));
    }

    /** @param list<array<string, mixed>> $all */
    private static function assertHasAssigned(array $all): void
    {
        if (self::assignedOnly($all) === []) {
            throw new \RuntimeException('يجب اعتماد يوم توزيع أو توليد الكشوف أولاً قبل التصدير.');
        }
    }

    public static function export(int $campaignId): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $all = self::assignedOnly(CampaignService::beneficiariesDetailed($campaignId));
        self::assertHasAssigned($all);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        self::buildMasterSheet($spreadsheet, $campaign, $all);
        self::buildDeliverySheets($spreadsheet, $campaign, $all);
        // الرسائل تُصدَّر يوم بيوم عبر exportMessagesForDay

        $spreadsheet->setActiveSheetIndex(0);

        return self::saveSpreadsheet($spreadsheet, $campaign, '');
    }

    /**
     * كشف المرشحين بالكامل كما في قاعدة البيانات (كل الحالات) — ليس كشوف التسليم المعتمدة.
     */
    public static function exportCandidates(int $campaignId): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $all = CampaignService::beneficiariesDetailed($campaignId);
        if ($all === []) {
            throw new \RuntimeException('لا يوجد مرشحون في هذه العملية.');
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('كشف_المرشحين');
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', 'كشف المرشحين بالكامل — ' . $campaign['name']);
        $sheet->mergeCells('A1:K1');
        self::styleSectionTitle($sheet, 'A1:K1');

        $headers = [
            '#', 'الاسم', 'رقم الهوية', 'رقم الجوال', 'مركز الإيواء', 'الحالة',
            'كود الصرف', 'يوم التوزيع', 'تاريخ الموعد', 'شباك', 'تاريخ الاستلام الفعلي',
        ];
        self::writeHeaderRow($sheet, 3, $headers);

        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $row = 4;
        $n = 0;
        foreach ($all as $b) {
            $n++;
            $assigned = self::isAssigned($b);
            $status = DeliveryService::isDeliveredStatus($b['receipt_status'] ?? '')
                ? 'مستلم'
                : ($assigned ? 'قيد التسليم' : 'غير معيّن');
            $code = ParcelCodeHelper::displayForBeneficiary(
                (string) ($b['disbursement_code'] ?? ''),
                $codeSuffix !== '' ? $codeSuffix : null,
                $codePrefix !== '' ? $codePrefix : null
            );
            $sheet->setCellValueExplicit('A' . $row, (string) $n, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $row, (string) ($b['name'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $row, ArabicFormat::toWesternDigits((string) ($b['national_id'] ?? '')), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D' . $row, ArabicFormat::toWesternDigits((string) ($b['mobile'] ?? '')), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E' . $row, trim((string) ($b['shelter_name'] ?? '')) !== '' ? (string) $b['shelter_name'] : '—', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('F' . $row, $status, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('G' . $row, $code !== '' ? $code : '—', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H' . $row, $assigned ? (string) (int) ($b['day_index'] ?? 0) : '—', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('I' . $row, (string) ($b['delivery_date'] ?? '—'), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('J' . $row, $assigned ? (string) (int) ($b['window_num'] ?? 0) : '—', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('K' . $row, (string) ($b['actual_delivery_date'] ?? $b['delivered_at'] ?? '—'), DataType::TYPE_STRING);
            $row++;
        }

        $last = max(3, $row - 1);
        self::borderAll($sheet, 'A3:K' . $last);
        foreach (['A' => 5, 'B' => 28, 'C' => 16, 'D' => 14, 'E' => 22, 'F' => 12, 'G' => 14, 'H' => 10, 'I' => 12, 'J' => 8, 'K' => 16] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        return self::saveSpreadsheet($spreadsheet, $campaign, 'مرشحين_كامل');
    }

    /**
     * كشف غير المعيّنين فقط — للطباعة/المراجعة، مع مركز الإيواء.
     */
    public static function exportUnassigned(int $campaignId): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $rows = CampaignService::unassignedPendingDetailed($campaignId);
        if ($rows === []) {
            throw new \RuntimeException('لا يوجد غير معيّنين في هذه العملية.');
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('غير_المعيّنين');
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', 'كشف غير المعيّنين — ' . $campaign['name']);
        $sheet->mergeCells('A1:F1');
        self::styleSectionTitle($sheet, 'A1:F1');
        $sheet->setCellValue('A2', 'العدد: ' . count($rows) . ' — مرتّب حسب مركز الإيواء ثم الاسم');
        $sheet->mergeCells('A2:F2');

        $headers = ['#', 'الاسم', 'رقم الهوية', 'رقم الجوال', 'مركز الإيواء', 'الحالة'];
        self::writeHeaderRow($sheet, 4, $headers);

        $row = 5;
        $n = 0;
        foreach ($rows as $b) {
            $n++;
            $shelter = trim((string) ($b['shelter_name'] ?? ''));
            $sheet->setCellValueExplicit('A' . $row, (string) $n, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B' . $row, (string) ($b['name'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('C' . $row, ArabicFormat::toWesternDigits((string) ($b['national_id'] ?? '')), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('D' . $row, ArabicFormat::toWesternDigits((string) ($b['mobile'] ?? '')), DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('E' . $row, $shelter !== '' ? $shelter : '—', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('F' . $row, 'غير معيّن', DataType::TYPE_STRING);
            $row++;
        }

        $last = max(4, $row - 1);
        self::borderAll($sheet, 'A4:F' . $last);
        foreach (['A' => 6, 'B' => 30, 'C' => 16, 'D' => 14, 'E' => 28, 'F' => 12] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        self::applyPortraitPrint($sheet, 4, $last, 'F');

        return self::saveSpreadsheet($spreadsheet, $campaign, 'غير_المعينين');
    }

    /** تصدير كشوف التسليم ليوم واحد فقط (شبابيك ذلك اليوم). */
    public static function exportDeliveryDay(int $campaignId, int $dayIndex): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $dayIndex = max(1, $dayIndex);
        $all = self::assignedOnly(CampaignService::beneficiariesDetailed($campaignId));
        self::assertHasAssigned($all);

        $dayRows = array_values(array_filter(
            $all,
            static fn (array $b): bool => (int) ($b['day_index'] ?? 0) === $dayIndex
        ));
        if ($dayRows === []) {
            throw new \RuntimeException('لا يوجد مستفيدون لليوم المحدد.');
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        // ورقة فارغة تُستبدل بأول شباك — buildDeliverySheets ينشئ أوراقاً جديدة
        $spreadsheet->removeSheetByIndex(0);
        self::buildDeliverySheets($spreadsheet, $campaign, $dayRows, $dayIndex);

        if ($spreadsheet->getSheetCount() === 0) {
            throw new \RuntimeException('تعذّر بناء كشوف التسليم لهذا اليوم.');
        }
        $spreadsheet->setActiveSheetIndex(0);

        $date = (string) ($dayRows[0]['delivery_date'] ?? '');
        $suffix = 'يوم' . $dayIndex . ($date !== '' ? '_' . $date : '');

        return self::saveSpreadsheet($spreadsheet, $campaign, $suffix);
    }

    /**
     * تصدير رسائل يوم واحد.
     * $carrier = jawwal | ooredoo | other → ملف Excel واحد.
     * $carrier = null → ZIP فيه ملف لكل شبكة موجودة.
     */
    public static function exportMessagesForDay(int $campaignId, int $dayIndex, ?string $carrier = null): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $dayIndex = max(1, $dayIndex);
        $all = self::assignedOnly(CampaignService::beneficiariesDetailed($campaignId));
        self::assertHasAssigned($all);

        $dayRows = array_values(array_filter(
            $all,
            static fn (array $b): bool => (int) ($b['day_index'] ?? 0) === $dayIndex
        ));
        if ($dayRows === []) {
            throw new \RuntimeException('لا يوجد مستفيدون لليوم المحدد.');
        }

        $groups = self::groupByCarrier($dayRows);
        $date = (string) ($dayRows[0]['delivery_date'] ?? '');
        $carrier = $carrier !== null ? strtolower(trim($carrier)) : null;

        if ($carrier !== null) {
            $label = match ($carrier) {
                PhoneHelper::CARRIER_JAWWAL => 'جوال',
                PhoneHelper::CARRIER_OOREDOO => 'أوريدو',
                PhoneHelper::CARRIER_OTHER => 'غير_مصنفة',
                default => throw new \RuntimeException('شبكة غير معروفة.'),
            };
            $items = $groups[$carrier] ?? [];
            if ($items === []) {
                throw new \RuntimeException("لا توجد رسائل لشبكة {$label} في هذا اليوم.");
            }
            $suffix = 'رسائل_' . $label . '_يوم' . $dayIndex . ($date !== '' ? '_' . $date : '');
            return self::savePlainMessagesSpreadsheet($campaign, $items, $suffix);
        }

        $files = [];
        foreach (
            [
                PhoneHelper::CARRIER_JAWWAL => 'جوال',
                PhoneHelper::CARRIER_OOREDOO => 'أوريدو',
                PhoneHelper::CARRIER_OTHER => 'غير_مصنفة',
            ] as $key => $label
        ) {
            $items = $groups[$key] ?? [];
            if ($items === []) {
                continue;
            }
            $suffix = 'رسائل_' . $label . '_يوم' . $dayIndex . ($date !== '' ? '_' . $date : '');
            $files[$label . '.xlsx'] = self::savePlainMessagesSpreadsheet($campaign, $items, $suffix);
        }

        if ($files === []) {
            throw new \RuntimeException('لا توجد رسائل لهذا اليوم.');
        }
        if (count($files) === 1) {
            return array_values($files)[0];
        }

        return self::zipFiles(
            $campaign,
            $files,
            'رسائل_يوم' . $dayIndex . ($date !== '' ? '_' . $date : '')
        );
    }

    /**
     * @param list<array<string,mixed>> $beneficiaries
     * @return array{jawwal:list<array<string,mixed>>,ooredoo:list<array<string,mixed>>,other:list<array<string,mixed>>}
     */
    private static function groupByCarrier(array $beneficiaries): array
    {
        $groups = [
            PhoneHelper::CARRIER_JAWWAL => [],
            PhoneHelper::CARRIER_OOREDOO => [],
            PhoneHelper::CARRIER_OTHER => [],
        ];
        foreach ($beneficiaries as $beneficiary) {
            $carrier = PhoneHelper::carrier((string) ($beneficiary['mobile'] ?? ''));
            if (!isset($groups[$carrier])) {
                $carrier = PhoneHelper::CARRIER_OTHER;
            }
            $groups[$carrier][] = $beneficiary;
        }
        foreach ($groups as &$items) {
            self::sortByName($items);
        }
        unset($items);
        return $groups;
    }

    /**
     * ملف رسائل بسيط: ترويسة (الجوال | الرسالة) ثم صفوف البيانات فقط.
     *
     * @param list<array<string,mixed>> $beneficiaries
     */
    private static function savePlainMessagesSpreadsheet(array $campaign, array $beneficiaries, string $nameSuffix): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('رسائل');
        $sheet->setRightToLeft(true);

        $sheet->setCellValueExplicit('A1', 'الجوال', DataType::TYPE_STRING);
        $sheet->setCellValueExplicit('B1', 'الرسالة', DataType::TYPE_STRING);
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);
        $sheet->getStyle('A1:B1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB(self::HEADER_FILL);

        $row = 2;
        foreach ($beneficiaries as $b) {
            $mobile = PhoneHelper::messageRecipient((string) ($b['mobile'] ?? ''));
            if ($mobile === '' || $mobile === '0') {
                continue;
            }
            $sheet->setCellValueExplicit(
                'A' . $row,
                ArabicFormat::toWesternDigits($mobile),
                DataType::TYPE_STRING
            );
            $sheet->setCellValueExplicit(
                'B' . $row,
                MessageTemplates::appointmentFromBeneficiary($campaign, $b),
                DataType::TYPE_STRING
            );
            $sheet->getStyle('A' . $row . ':B' . $row)->getNumberFormat()->setFormatCode('@');
            $sheet->getStyle('B' . $row)->getAlignment()->setWrapText(true);
            $row++;
        }

        if ($row === 2) {
            throw new \RuntimeException('لا توجد أرقام صالحة في هذه المجموعة.');
        }

        $sheet->getColumnDimension('A')->setWidth(16);
        $sheet->getColumnDimension('B')->setWidth(90);

        return self::saveSpreadsheet($spreadsheet, $campaign, $nameSuffix);
    }

    /**
     * @param array<string, string> $files map of zipEntryName => absolutePath
     */
    private static function zipFiles(array $campaign, array $files, string $nameSuffix): string
    {
        $dir = dirname(__DIR__) . '/storage/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $safeName = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $campaign['name']) ?: 'campaign';
        if ($nameSuffix !== '') {
            $safeName .= '_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $nameSuffix);
        }
        $path = $dir . '/' . $safeName . '_' . date('Y-m-d_His') . '.zip';

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('تعذّر إنشاء ملف ZIP للرسائل.');
        }
        foreach ($files as $entry => $filePath) {
            $zip->addFile($filePath, $entry);
        }
        $zip->close();

        return $path;
    }

    /** @param Spreadsheet $spreadsheet */
    private static function saveSpreadsheet(Spreadsheet $spreadsheet, array $campaign, string $nameSuffix): string
    {
        $dir = dirname(__DIR__) . '/storage/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $safeName = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $campaign['name']) ?: 'campaign';
        if ($nameSuffix !== '') {
            $safeName .= '_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $nameSuffix);
        }
        $path = $dir . '/' . $safeName . '_' . date('Y-m-d_His') . '.xlsx';

        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    public static function exportDeliveries(int $campaignId): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $all = self::assignedOnly(CampaignService::beneficiariesDetailed($campaignId));
        self::assertHasAssigned($all);

        $stats = DeliveryService::stockStats($campaignId);
        $today = date('Y-m-d');

        $delivered = array_values(array_filter($all, fn ($b) => ($b['receipt_status'] ?? '') === DeliveryService::STATUS_DELIVERED));
        $pending = array_values(array_filter($all, fn ($b) => ($b['receipt_status'] ?? '') !== DeliveryService::STATUS_DELIVERED));
        // متأخرون لم يستلموا: موعدهم قبل اليوم وما زالوا بانتظار التسليم (ليسوا مستلمين)
        $latePending = array_values(array_filter(
            $pending,
            static function (array $b) use ($today): bool {
                $planned = trim((string) ($b['delivery_date'] ?? ''));
                return $planned !== '' && $planned < $today;
            }
        ));

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        self::buildDeliverySummarySheet($spreadsheet, $campaign, $stats, $all);
        self::buildDeliveryDetailSheet($spreadsheet, 'كشف_التسليمات', $all, $campaign);
        self::buildDeliveryDetailSheet($spreadsheet, 'مستلم', $delivered, $campaign);
        self::buildDeliveryDetailSheet($spreadsheet, 'بانتظار_التسليم', $pending, $campaign);
        self::buildDeliveryDetailSheet($spreadsheet, 'متأخر_لم_يستلم', $latePending, $campaign);
        self::buildSmsOutboxSheet(
            $spreadsheet,
            SmsService::outbox($campaignId),
            (string) ($campaign['parcel_code'] ?? ''),
            (string) ($campaign['parcel_code_suffix'] ?? '')
        );

        $spreadsheet->setActiveSheetIndex(0);

        return self::saveSpreadsheet($spreadsheet, $campaign, 'deliveries');
    }

    /** @param list<array<string,mixed>> $all */
    private static function buildDeliverySummarySheet(Spreadsheet $spreadsheet, array $campaign, array $stats, array $all): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ملخص_المخزن');
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', 'ملخص التسليم — ' . $campaign['name']);
        $sheet->mergeCells('A1:G1');
        self::styleSectionTitle($sheet, 'A1:G1');

        $rows = [
            ['تاريخ التقرير', self::arDateTime(date('Y-m-d H:i:s'))],
            ['اسم الطرد', $campaign['parcel_name']],
            ['المخزن', $campaign['warehouse_name']],
            ['فترة التسليم', self::arDate((string) $campaign['delivery_start']) . ' — ' . self::arDate((string) $campaign['delivery_end'])],
            ['الكمية الافتتاحية (مخزون)', self::ar((int) ($stats['opening_quantity'] ?? 0))],
            ['إجمالي المستفيدين (كشف)', self::ar((int) ($stats['total_beneficiaries'] ?? 0))],
            ['مُسلَّم', self::ar((int) ($stats['delivered'] ?? 0))],
            ['بانتظار التسليم (كشف)', self::ar((int) ($stats['pending'] ?? 0))],
            ['رصيد المخزون المتبقي', self::ar((int) ($stats['balance'] ?? 0))],
            ['في الموعد', self::ar((int) ($stats['on_time'] ?? 0))],
            ['متأخر (استلم بعد الموعد/الشباك)', self::ar((int) ($stats['late'] ?? 0))],
            ['مخطط اليوم', self::ar((int) ($stats['planned_today'] ?? 0))],
            ['استلم من مخطط اليوم', self::ar((int) ($stats['planned_today_delivered'] ?? 0))],
            ['متبقٍ من مخطط اليوم', self::ar((int) ($stats['planned_today_pending'] ?? 0))],
            ['مستلمو اليوم (الكل = أمناء المخزن)', self::ar((int) ($stats['today_delivered'] ?? 0))],
            ['منهم من مخطط اليوم', self::ar((int) ($stats['today_delivered_of_plan'] ?? 0))],
            ['متأخرون من أيام سابقة استلموا اليوم', self::ar((int) ($stats['today_delivered_late'] ?? 0))],
            ['مستلمو اليوم بلا موعد/موعد لاحق', self::ar((int) ($stats['today_delivered_other'] ?? 0))],
            ['غير معيّنين بانتظار الاستلام', self::ar((int) ($stats['unassigned_pending'] ?? 0))],
            ['متأخرون لم يستلموا (بانتظار)', self::ar((int) ($stats['late_pending'] ?? 0))],
            ['رسائل SMS معلّقة', self::ar(SmsService::pendingCount((int) $campaign['id']))],
        ];

        $r = 3;
        foreach ($rows as $line) {
            $sheet->fromArray($line, null, 'A' . $r);
            $r++;
        }
        $summaryEnd = $r - 1;
        self::styleMetaBlock($sheet, 'A3:B' . $summaryEnd);
        self::borderAll($sheet, 'A3:B' . $summaryEnd);

        // ── ملخص يومي ──
        // مُسلَّم فعلياً = من استلموا في تاريخ اليوم (من مخطط اليوم + متأخرون من أيام سابقة)
        // بانتظار التسليم = من مخطط اليوم وما استلموا (للمطابقة، لا يدخل في عدد التسليم الفعلي)
        $dailyStart = $summaryEnd + 2;
        $sheet->setCellValue('A' . $dailyStart, 'ملخص يومي لعمليات التسليم (العدد الفعلي حسب تاريخ الاستلام)');
        $sheet->mergeCells('A' . $dailyStart . ':H' . $dailyStart);
        self::styleSectionTitle($sheet, 'A' . $dailyStart . ':H' . $dailyStart);

        $headerRow = $dailyStart + 1;
        $dailyHeaders = [
            'اليوم',
            'تاريخ التسليم',
            'المخطط',
            'مُسلَّم من المخطط',
            'متأخرون استلموا',
            'مُسلَّم فعلياً',
            'بانتظار التسليم',
            'في الموعد',
        ];
        self::writeHeaderRow($sheet, $headerRow, $dailyHeaders);

        $daily = self::buildDailyDeliverySummary($all);
        $row = $headerRow + 1;
        $sumPending = 0;
        $sumDeliveredTotal = 0;
        foreach ($daily as $day) {
            $sumPending += (int) $day['pending'];
            $sumDeliveredTotal += (int) $day['delivered'];
            $sheet->fromArray([
                self::ar($day['day_index']),
                self::arDate($day['date']),
                self::ar($day['planned']),
                self::ar($day['delivered_of_plan']),
                self::ar($day['delivered_late']),
                self::ar($day['delivered']),
                self::ar($day['pending']),
                self::ar($day['on_time']),
            ], null, 'A' . $row);
            $row++;
        }

        $dailyLastRow = max($headerRow, $row - 1);
        if ($row > $headerRow + 1) {
            $sheet->fromArray([
                'المجموع',
                '',
                '',
                '',
                '',
                self::ar($sumDeliveredTotal),
                self::ar($sumPending),
                '',
            ], null, 'A' . $row);
            $sheet->getStyle('A' . $row . ':H' . $row)->getFont()->setBold(true);
            $dailyLastRow = $row;
            self::borderAll($sheet, 'A' . $headerRow . ':H' . $dailyLastRow);
            self::styleDataRows($sheet, 'A' . ($headerRow + 1) . ':H' . $dailyLastRow);
        } else {
            $sheet->setCellValue('A' . $row, 'لا توجد بيانات يومية — يجب توليد الكشوف أولاً.');
            $sheet->mergeCells('A' . $row . ':H' . $row);
            $dailyLastRow = $row;
        }

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(16);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->getColumnDimension('G')->setWidth(16);
        $sheet->getColumnDimension('H')->setWidth(10);
        self::applyPortraitPrint($sheet, $headerRow, $dailyLastRow, 'H');
    }

    /**
     * ملخص يومي حسب تاريخ الاستلام الفعلي.
     * delivered = ما سُلِّم فعلياً في ذلك اليوم (من المخطط + متأخرون من أيام سابقة).
     * pending = من مخطط ذلك اليوم وما استلموا بعد (للمطابقة فقط).
     *
     * @param list<array<string,mixed>> $all
     * @return list<array{day_index:int,date:string,planned:int,delivered:int,delivered_of_plan:int,delivered_late:int,pending:int,on_time:int}>
     */
    private static function buildDailyDeliverySummary(array $all): array
    {
        $byDate = [];
        foreach ($all as $b) {
            $date = trim((string) ($b['delivery_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'day_index' => (int) ($b['day_index'] ?? 0),
                    'date' => $date,
                    'planned' => 0,
                    'delivered' => 0,
                    'delivered_of_plan' => 0,
                    'delivered_late' => 0,
                    'pending' => 0,
                    'on_time' => 0,
                ];
            }
            $byDate[$date]['planned']++;
            if (($b['receipt_status'] ?? '') !== DeliveryService::STATUS_DELIVERED) {
                $byDate[$date]['pending']++;
            }
        }

        foreach ($all as $b) {
            if (($b['receipt_status'] ?? '') !== DeliveryService::STATUS_DELIVERED) {
                continue;
            }

            $planned = trim((string) ($b['delivery_date'] ?? ''));
            $actual = trim((string) ($b['actual_delivery_date'] ?? ''));
            if ($actual === '') {
                // بيانات قديمة بلا تاريخ استلام فعلي: نعدّها في يوم الموعد إن وُجد
                $actual = $planned;
            }
            if ($actual === '') {
                continue;
            }

            if (!isset($byDate[$actual])) {
                $byDate[$actual] = [
                    'day_index' => (int) ($b['day_index'] ?? 0),
                    'date' => $actual,
                    'planned' => 0,
                    'delivered' => 0,
                    'delivered_of_plan' => 0,
                    'delivered_late' => 0,
                    'pending' => 0,
                    'on_time' => 0,
                ];
            }

            $byDate[$actual]['delivered']++;

            $type = (string) ($b['delivery_type'] ?? '');
            $isLateFromPrevious = $planned !== '' && $actual > $planned;
            if ($isLateFromPrevious) {
                $byDate[$actual]['delivered_late']++;
            } else {
                $byDate[$actual]['delivered_of_plan']++;
                if ($type === 'on_time' || $type === '') {
                    $byDate[$actual]['on_time']++;
                }
            }
        }

        ksort($byDate);
        return array_values($byDate);
    }

    /** تنسيق حالة الاستلام للتصدير — المستلم يبقى «مستلم»، والباقي «بانتظار التسليم». */
    private static function formatReceiptStatusForExport(array $b): string
    {
        if (($b['receipt_status'] ?? '') === DeliveryService::STATUS_DELIVERED) {
            return DeliveryService::STATUS_DELIVERED;
        }

        $raw = trim((string) ($b['receipt_status'] ?? ''));
        if ($raw === '' || $raw === 'تم التسليم' || $raw === DeliveryService::STATUS_PENDING) {
            return 'بانتظار التسليم';
        }

        return 'بانتظار التسليم';
    }

    /** @param list<array<string,mixed>> $items */
    private static function buildDeliveryDetailSheet(Spreadsheet $spreadsheet, string $title, array $items, array $campaign): void
    {
        if (strlen($title) > 31) {
            $title = substr($title, 0, 31);
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', $title . ' — ' . $campaign['name']);
        $sheet->mergeCells('A1:P1');
        self::styleSectionTitle($sheet, 'A1:P1');

        $headerRow = 3;
        $headers = [
            '#', 'الاسم', 'رقم الهوية', 'رقم الجوال', 'كود الصرف', 'حالة الاستلام',
            'موعد التسليم', 'شباك', 'من', 'إلى',
            'تاريخ التسليم', 'نوع التسليم', 'وقت التسجيل', 'أمين المخزن',
            'طريقة الاستلام', 'اسم المستلم',
        ];
        self::writeHeaderRow($sheet, $headerRow, $headers);

        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $row = $headerRow + 1;
        foreach ($items as $i => $b) {
            $typeLabel = match ($b['delivery_type'] ?? '') {
                'on_time' => 'في الموعد',
                'late' => 'متأخر',
                default => '',
            };
            $recvMode = (string) ($b['received_by_mode'] ?? '');
            $recvModeLabel = match ($recvMode) {
                DeliveryService::RECEIVED_BY_SELF => 'بنفسه',
                DeliveryService::RECEIVED_BY_PROXY => 'غيره',
                default => '',
            };
            $sheet->fromArray([
                self::ar($i + 1),
                $b['name'],
                self::ar($b['national_id']),
                null,
                null,
                self::formatReceiptStatusForExport($b),
                self::arDate((string) $b['delivery_date']),
                self::ar($b['window_num']),
                self::arTime((string) $b['time_from']),
                self::arTime((string) $b['time_to']),
                self::arDate((string) ($b['actual_delivery_date'] ?? '')),
                $typeLabel,
                self::arDateTime((string) ($b['delivered_at'] ?? '')),
                $b['delivered_by_name'] ?? '',
                $recvModeLabel,
                $recvMode === DeliveryService::RECEIVED_BY_PROXY ? (string) ($b['received_by_name'] ?? '') : '',
            ], null, 'A' . $row);
            self::setFullCodeCell($sheet, 'E' . $row, (string) ($b['disbursement_code'] ?? ''), $codePrefix, $codeSuffix);
            self::setMobileCell($sheet, 'D' . $row, (string) $b['mobile']);
            $row++;
        }

        $lastRow = max($headerRow, $row - 1);
        if ($row > $headerRow + 1) {
            self::borderAll($sheet, 'A' . $headerRow . ':P' . $lastRow);
            self::styleDataRows($sheet, 'A' . ($headerRow + 1) . ':P' . $lastRow);
        }

        $widths = ['A' => 5, 'B' => 22, 'C' => 14, 'D' => 12, 'E' => 13, 'F' => 12, 'G' => 12, 'H' => 6, 'I' => 7, 'J' => 7, 'K' => 12, 'L' => 10, 'M' => 18, 'N' => 16, 'O' => 12, 'P' => 18];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        self::applyPortraitPrint($sheet, $headerRow, $lastRow, 'P');
    }

    /** @param list<array<string,mixed>> $messages */
    private static function buildSmsOutboxSheet(
        Spreadsheet $spreadsheet,
        array $messages,
        string $codePrefix = '',
        string $codeSuffix = ''
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('رسائل_التأكيد');
        $sheet->setRightToLeft(true);

        $headerRow = 1;
        $headers = ['#', 'الكود', 'الاسم', 'رقم الجوال', 'نص الرسالة', 'الحالة', 'وقت الإنشاء', 'وقت الإرسال'];
        self::writeHeaderRow($sheet, $headerRow, $headers);

        $row = 2;
        foreach ($messages as $i => $m) {
            $status = match ($m['status'] ?? '') {
                'sent' => 'مُرسَل',
                'failed' => 'فشل',
                default => 'معلّق',
            };
            $sheet->fromArray([
                ArabicFormat::protectWesternDigits((string) ($i + 1)),
                null,
                $m['beneficiary_name'] ?? '',
                null,
                ArabicFormat::protectWesternDigits((string) ($m['message_text'] ?? '')),
                $status,
                self::arDateTime((string) ($m['created_at'] ?? '')),
                self::arDateTime((string) ($m['sent_at'] ?? '')),
            ], null, 'A' . $row);
            self::setFullCodeCell(
                $sheet,
                'B' . $row,
                (string) ($m['disbursement_code'] ?? ''),
                $codePrefix,
                $codeSuffix
            );
            self::setMobileCell($sheet, 'D' . $row, (string) ($m['mobile'] ?? ''));
            $sheet->getStyle('E' . $row)->getAlignment()->setWrapText(true);
            $row++;
        }

        $lastRow = max(1, $row - 1);
        self::borderAll($sheet, 'A1:H' . $lastRow);
        if ($row > 2) {
            self::styleDataRows($sheet, 'A2:H' . $lastRow);
        }

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(70);
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->getColumnDimension('G')->setWidth(18);
        $sheet->getColumnDimension('H')->setWidth(18);
        self::applyPortraitPrint($sheet, $headerRow, $lastRow, 'H');
    }

    private static function buildMasterSheet(Spreadsheet $spreadsheet, array $campaign, array $all): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('الكشف_الإجمالي');
        $sheet->setRightToLeft(true);

        $sheet->setCellValue('A1', 'الكشف الإجمالي — ' . $campaign['name']);
        $sheet->mergeCells('A1:N1');
        self::styleSectionTitle($sheet, 'A1:N1');

        $parcelLabel = CampaignService::parcelLabel($campaign);
        $meta = [
            ['اسم الطرد', $campaign['parcel_name'], 'كود الطرد', $parcelLabel, '', ''],
            ['عدد المستفيدين', self::ar(count($all)), 'اسم المخزن', $campaign['warehouse_name'], 'من', self::arDate((string) $campaign['delivery_start'])],
            ['إلى', self::arDate((string) $campaign['delivery_end']), 'أيام العمل', self::ar((int) $campaign['num_days']), 'شبابيك/يوم', self::ar((int) ($campaign['num_windows'] ?? 0))],
            ['موقع المخزن', $campaign['warehouse_location'], 'مستفيد/شباك', self::ar((int) $campaign['per_window_capacity']), '', ''],
        ];
        $r = 2;
        foreach ($meta as $line) {
            $sheet->fromArray($line, null, 'A' . $r);
            $r++;
        }
        self::styleMetaBlock($sheet, 'A2:F5');

        $headerRow = 7;
        $headers = [
            '#', 'الاسم', 'رقم الهوية', 'رقم الجوال', 'حالة الاستلام', 'كود الصرف',
            'يوم التسليم', 'شباك', 'من', 'إلى',
            'تاريخ التسليم', 'نوع التسليم', 'وقت التسجيل',
        ];
        self::writeHeaderRow($sheet, $headerRow, $headers);

        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $row = $headerRow + 1;
        foreach ($all as $i => $b) {
            $typeLabel = match ($b['delivery_type'] ?? '') {
                'on_time' => 'في الموعد',
                'late' => 'متأخر',
                default => '',
            };
            $sheet->fromArray([
                self::ar($i + 1),
                $b['name'],
                self::ar($b['national_id']),
                null,
                self::formatReceiptStatusForExport($b),
                null,
                self::arDate((string) $b['delivery_date']),
                self::ar($b['window_num']),
                self::arTime((string) $b['time_from']),
                self::arTime((string) $b['time_to']),
                self::arDate((string) ($b['actual_delivery_date'] ?? '')),
                $typeLabel,
                self::arDateTime((string) ($b['delivered_at'] ?? '')),
            ], null, 'A' . $row);
            self::setFullCodeCell($sheet, 'F' . $row, (string) ($b['disbursement_code'] ?? ''), $codePrefix, $codeSuffix);
            self::setMobileCell($sheet, 'D' . $row, (string) $b['mobile']);
            $sheet->getStyle('A' . $row . ':M' . $row)->getFont()->setSize(9);
            $row++;
        }

        $lastRow = $row - 1;
        self::borderAll($sheet, 'A' . $headerRow . ':M' . $lastRow);
        self::styleDataRows($sheet, 'A' . ($headerRow + 1) . ':M' . $lastRow);

        self::setMasterColumnWidths($sheet);
        self::applyPortraitPrint($sheet, $headerRow, $lastRow, 'M');
    }

    /**
     * @param list<array<string,mixed>> $all
     * @param int|null $onlyDay إن وُجد يُصدَّر هذا اليوم فقط
     */
    private static function buildDeliverySheets(
        Spreadsheet $spreadsheet,
        array $campaign,
        array $all,
        ?int $onlyDay = null
    ): void {
        $byDayWindow = [];
        foreach ($all as $b) {
            $d = (int) ($b['day_index'] ?? 0);
            $w = (int) ($b['window_num'] ?? 0);
            if ($onlyDay !== null && $d !== $onlyDay) {
                continue;
            }
            $byDayWindow[$d][$w][] = $b;
        }

        $codePrefix = (string) ($campaign['parcel_code'] ?? '');
        $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
        $daysToBuild = $onlyDay !== null
            ? [$onlyDay]
            : array_keys($byDayWindow);
        sort($daysToBuild, SORT_NUMERIC);

        foreach ($daysToBuild as $d) {
            $dayItems = $byDayWindow[$d] ?? [];
            if ($dayItems === []) {
                continue;
            }

            $windows = array_keys($dayItems);
            sort($windows, SORT_NUMERIC);

            foreach ($windows as $w) {
                $items = $dayItems[$w] ?? [];
                if ($items === []) {
                    continue;
                }

                usort($items, static function ($a, $b) {
                    $nameCmp = DistributionService::compareNames(
                        (string) ($a['name'] ?? ''),
                        (string) ($b['name'] ?? '')
                    );
                    if ($nameCmp !== 0) {
                        return $nameCmp;
                    }
                    return ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
                });

                $first = $items[0];
                $title = 'يوم' . $d . '_شباك' . $w;
                if (strlen($title) > 31) {
                    $title = substr($title, 0, 31);
                }

                $sheet = $spreadsheet->createSheet();
                $sheet->setTitle($title);
                $sheet->setRightToLeft(true);

                $sheet->setCellValue('A1', 'بيانات الطرد');
                $sheet->mergeCells('A1:F1');
                self::styleSectionTitle($sheet, 'A1:F1');

                $parcelMeta = [
                    ['اسم الطرد', $campaign['parcel_name'], 'كود الطرد', CampaignService::parcelLabel($campaign), '', ''],
                    ['تاريخ البداية', self::arDate((string) $campaign['delivery_start']), 'تاريخ النهاية', self::arDate((string) $campaign['delivery_end']), 'اسم المخزن', $campaign['warehouse_name']],
                    ['موقع المخزن', $campaign['warehouse_location'], '', '', '', ''],
                ];
                $r = 2;
                foreach ($parcelMeta as $line) {
                    $sheet->fromArray($line, null, 'A' . $r);
                    $r++;
                }
                self::styleMetaBlock($sheet, 'A2:D4');

                $r = 6;
                $sheet->setCellValue('A' . $r, 'بيانات الكشف (الشباك)');
                $sheet->mergeCells('A' . $r . ':F' . $r);
                self::styleSectionTitle($sheet, 'A' . $r . ':F' . $r);
                $r++;

                $windowMeta = [
                    ['يوم التسليم', self::arDate((string) $first['delivery_date']), 'رقم الشباك', self::ar($w)],
                    ['عدد المستفيدين', self::ar(count($items)), 'ساعات العمل', self::arTime((string) $campaign['work_start']) . ' — ' . self::arTime((string) $campaign['work_end'])],
                ];
                $windowStart = $r;
                foreach ($windowMeta as $line) {
                    $sheet->fromArray($line, null, 'A' . $r);
                    $r++;
                }
                self::styleMetaBlock($sheet, 'A' . $windowStart . ':D' . ($r - 1));

                $headerRow = $r + 1;
                $headers = ['#', 'رقم الهوية', 'الاسم', 'رقم الجوال', 'كود الصرف', 'التوقيع على الاستلام'];
                self::writeHeaderRow($sheet, $headerRow, $headers);

                $row = $headerRow + 1;
                foreach ($items as $i => $b) {
                    $sheet->fromArray([
                        self::ar($i + 1),
                        self::ar($b['national_id']),
                        $b['name'],
                        null,
                        null,
                        '',
                    ], null, 'A' . $row);
                    self::setFullCodeCell(
                        $sheet,
                        'E' . $row,
                        (string) ($b['disbursement_code'] ?? ''),
                        $codePrefix,
                        $codeSuffix
                    );
                    self::setMobileCell($sheet, 'D' . $row, (string) $b['mobile']);
                    $sheet->getStyle('F' . $row)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
                    $row++;
                }

                $lastRow = $row - 1;
                self::borderAll($sheet, 'A' . $headerRow . ':F' . $lastRow);
                self::styleDataRows($sheet, 'A' . ($headerRow + 1) . ':F' . $lastRow);

                self::setDeliveryColumnWidths($sheet);
                self::applyPortraitPrint($sheet, $headerRow, $lastRow, 'F');
            }
        }
    }

    /** @param list<array<string,mixed>> $items */
    private static function sortByName(array &$items): void
    {
        usort($items, static function ($a, $b) {
            return DistributionService::compareNames(
                (string) ($a['name'] ?? ''),
                (string) ($b['name'] ?? '')
            );
        });
    }

    private static function ar(mixed $value): string
    {
        return ArabicFormat::toArabicDigits((string) $value);
    }

    private static function arDate(?string $date): string
    {
        return $date === null || $date === '' ? '' : ArabicFormat::formatDate($date);
    }

    private static function arTime(?string $time): string
    {
        return $time === null || $time === '' ? '' : ArabicFormat::formatTime12($time);
    }

    private static function arDateTime(?string $datetime): string
    {
        return $datetime === null || $datetime === '' ? '' : ArabicFormat::formatDateTime($datetime);
    }

    private static function setMobileCell(Worksheet $sheet, string $cell, string $mobile): void
    {
        $normalized = PhoneHelper::normalize($mobile);
        if ($normalized === '' || $normalized === '0') {
            return;
        }

        $sheet->setCellValueExplicit($cell, self::ar($normalized), DataType::TYPE_STRING);
    }

    private static function setFullCodeCell(
        Worksheet $sheet,
        string $cell,
        string $disbursementCode,
        string $codePrefix = '',
        string $codeSuffix = ''
    ): void {
        if ($disbursementCode === '') {
            return;
        }

        $sheet->setCellValueExplicit(
            $cell,
            self::ar(
                ParcelCodeHelper::displayFull(
                    $disbursementCode,
                    $codeSuffix !== '' ? $codeSuffix : null,
                    $codePrefix !== '' ? $codePrefix : null
                )
            ),
            DataType::TYPE_STRING
        );
    }

    private static function writeHeaderRow(Worksheet $sheet, int $row, array $headers): void
    {
        $lastCol = self::colLetter(count($headers) - 1);
        $sheet->fromArray($headers, null, 'A' . $row);
        $range = 'A' . $row . ':' . $lastCol . $row;
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::HEADER_FILL);
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
    }

    private static function styleSectionTitle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SECTION_FILL);
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        self::borderAll($sheet, $range);
    }

    /** تظليل وحدود لكتلة بيانات (طرد / شباك) — تسمية | قيمة | تسمية | قيمة */
    private static function styleMetaBlock(Worksheet $sheet, string $range): void
    {
        self::borderAll($sheet, $range);

        [$start, $end] = explode(':', $range);
        preg_match('/(\d+)$/', $start, $mStart);
        preg_match('/(\d+)$/', $end, $mEnd);
        $rowStart = (int) ($mStart[1] ?? 1);
        $rowEnd = (int) ($mEnd[1] ?? $rowStart);

        for ($r = $rowStart; $r <= $rowEnd; $r++) {
            $sheet->getStyle('A' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::META_LABEL_FILL);
            $sheet->getStyle('C' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::META_LABEL_FILL);
            $sheet->getStyle('A' . $r . ':D' . $r)->getFont()->setBold(false)->setSize(10);
            $sheet->getStyle('A' . $r . ':D' . $r)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A' . $r)->getFont()->setBold(true);
            $sheet->getStyle('C' . $r)->getFont()->setBold(true);
        }
    }

    private static function styleDataRows(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(false);
        $sheet->getStyle($range)->getFont()->setSize(9);
    }

    private static function borderAll(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
    }

    private static function applyPortraitPrint(Worksheet $sheet, int $headerRow, int $lastRow, string $lastCol): void
    {
        $pageSetup = $sheet->getPageSetup();
        $pageSetup->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
        $pageSetup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $pageSetup->setFitToPage(true);
        $pageSetup->setFitToWidth(1);
        $pageSetup->setFitToHeight(0);
        $pageSetup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $pageSetup->setPrintArea('A1:' . $lastCol . $lastRow);
        $pageSetup->setHorizontalCentered(true);

        $margins = $sheet->getPageMargins();
        $margins->setTop(0.5);
        $margins->setBottom(0.5);
        $margins->setLeft(0.4);
        $margins->setRight(0.4);
        $margins->setHeader(0.3);
        $margins->setFooter(0.3);

        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);

        $sheet->getHeaderFooter()->setOddFooter('&L&9كشوفات التسليم&R&9صفحة &P من &N');
    }

    private static function setMasterColumnWidths(Worksheet $sheet): void
    {
        $widths = [
            'A' => 5, 'B' => 22, 'C' => 14, 'D' => 12, 'E' => 14,
            'F' => 13, 'G' => 12, 'H' => 6, 'I' => 7, 'J' => 7,
            'K' => 12, 'L' => 10, 'M' => 18,
        ];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    private static function setDeliveryColumnWidths(Worksheet $sheet): void
    {
        $widths = [
            'A' => 5, 'B' => 14, 'C' => 24, 'D' => 12, 'E' => 16, 'F' => 20,
        ];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
    }

    private static function colLetter(int $index): string
    {
        $index++;
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = intdiv($index, 26);
        }
        return $letters;
    }
}
