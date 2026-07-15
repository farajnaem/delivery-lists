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

    public static function export(int $campaignId): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $all = CampaignService::beneficiariesDetailed($campaignId);
        if ($all === [] || empty($all[0]['disbursement_code'])) {
            throw new \RuntimeException('يجب توليد الكشوف أولاً قبل التصدير.');
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        self::buildMasterSheet($spreadsheet, $campaign, $all);
        self::buildDeliverySheets($spreadsheet, $campaign, $all);
        // الرسائل تُصدَّر يوم بيوم عبر exportMessagesForDay

        $spreadsheet->setActiveSheetIndex(0);

        return self::saveSpreadsheet($spreadsheet, $campaign, '');
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
        $all = CampaignService::beneficiariesDetailed($campaignId);
        if ($all === [] || empty($all[0]['disbursement_code'])) {
            throw new \RuntimeException('يجب توليد الكشوف أولاً قبل التصدير.');
        }

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

    /** تصدير كشوف الرسائل ليوم واحد فقط. */
    public static function exportMessagesForDay(int $campaignId, int $dayIndex): string
    {
        extend_runtime();

        $campaign = CampaignService::find($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('العملية غير موجودة.');
        }

        $dayIndex = max(1, $dayIndex);
        $all = CampaignService::beneficiariesDetailed($campaignId);
        if ($all === [] || empty($all[0]['disbursement_code'])) {
            throw new \RuntimeException('يجب توليد الكشوف أولاً قبل التصدير.');
        }

        $dayRows = array_values(array_filter(
            $all,
            static fn (array $b): bool => (int) ($b['day_index'] ?? 0) === $dayIndex
        ));
        if ($dayRows === []) {
            throw new \RuntimeException('لا يوجد مستفيدون لليوم المحدد.');
        }

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $spreadsheet->removeSheetByIndex(0);
        self::buildMessagesSheets($spreadsheet, $dayRows);

        if ($spreadsheet->getSheetCount() === 0) {
            throw new \RuntimeException('لا توجد رسائل لهذا اليوم.');
        }
        $spreadsheet->setActiveSheetIndex(0);

        $date = (string) ($dayRows[0]['delivery_date'] ?? '');
        $suffix = 'رسائل_يوم' . $dayIndex . ($date !== '' ? '_' . $date : '');

        return self::saveSpreadsheet($spreadsheet, $campaign, $suffix);
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

        $all = CampaignService::beneficiariesDetailed($campaignId);
        if ($all === [] || empty($all[0]['disbursement_code'])) {
            throw new \RuntimeException('يجب توليد الكشوف أولاً.');
        }

        $stats = DeliveryService::stockStats($campaignId);
        $today = date('Y-m-d');

        $delivered = array_values(array_filter($all, fn ($b) => ($b['receipt_status'] ?? '') === DeliveryService::STATUS_DELIVERED));
        $pending = array_values(array_filter($all, fn ($b) => ($b['receipt_status'] ?? '') !== DeliveryService::STATUS_DELIVERED));
        $latePending = array_values(array_filter($pending, fn ($b) => ($b['delivery_date'] ?? '') < $today));

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        self::buildDeliverySummarySheet($spreadsheet, $campaign, $stats, $all);
        self::buildDeliveryDetailSheet($spreadsheet, 'كشف_التسليمات', $all, $campaign);
        self::buildDeliveryDetailSheet($spreadsheet, 'مستلم', $delivered, $campaign);
        self::buildDeliveryDetailSheet($spreadsheet, 'بانتظار_التسليم', $pending, $campaign);
        self::buildDeliveryDetailSheet($spreadsheet, 'متأخر_عن_الموعد', $latePending, $campaign);
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
            ['تاريخ التقرير', date('Y-m-d H:i')],
            ['اسم الطرد', $campaign['parcel_name']],
            ['المخزن', $campaign['warehouse_name']],
            ['فترة التسليم', $campaign['delivery_start'] . ' — ' . $campaign['delivery_end']],
            ['الكمية الافتتاحية', (int) ($stats['opening_quantity'] ?? 0)],
            ['إجمالي المستفيدين', (int) ($stats['total_beneficiaries'] ?? 0)],
            ['مُسلَّم', (int) ($stats['delivered'] ?? 0)],
            ['بانتظار التسليم', (int) ($stats['pending'] ?? 0)],
            ['الرصيد المتبقي', (int) ($stats['balance'] ?? 0)],
            ['في الموعد', (int) ($stats['on_time'] ?? 0)],
            ['متأخر', (int) ($stats['late'] ?? 0)],
            ['تسليم اليوم', (int) ($stats['today_delivered'] ?? 0) . ' / ' . (int) ($stats['planned_today'] ?? 0)],
            ['رسائل SMS معلّقة', SmsService::pendingCount((int) $campaign['id'])],
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
        $dailyStart = $summaryEnd + 2;
        $sheet->setCellValue('A' . $dailyStart, 'ملخص يومي لعمليات التسليم');
        $sheet->mergeCells('A' . $dailyStart . ':G' . $dailyStart);
        self::styleSectionTitle($sheet, 'A' . $dailyStart . ':G' . $dailyStart);

        $headerRow = $dailyStart + 1;
        $dailyHeaders = ['اليوم', 'تاريخ التسليم', 'المخطط', 'مُسلَّم', 'بانتظار التسليم', 'في الموعد', 'متأخر'];
        self::writeHeaderRow($sheet, $headerRow, $dailyHeaders);

        $daily = self::buildDailyDeliverySummary($all);
        $row = $headerRow + 1;
        foreach ($daily as $day) {
            $sheet->fromArray([
                $day['day_index'],
                $day['date'],
                $day['planned'],
                $day['delivered'],
                $day['pending'],
                $day['on_time'],
                $day['late'],
            ], null, 'A' . $row);
            $row++;
        }

        $dailyLastRow = max($headerRow, $row - 1);
        if ($row > $headerRow + 1) {
            self::borderAll($sheet, 'A' . $headerRow . ':G' . $dailyLastRow);
            self::styleDataRows($sheet, 'A' . ($headerRow + 1) . ':G' . $dailyLastRow);
        } else {
            $sheet->setCellValue('A' . $row, 'لا توجد بيانات يومية — يجب توليد الكشوف أولاً.');
            $sheet->mergeCells('A' . $row . ':G' . $row);
            $dailyLastRow = $row;
        }

        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(10);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->getColumnDimension('G')->setWidth(10);
        self::applyPortraitPrint($sheet, $headerRow, $dailyLastRow, 'G');
    }

    /**
     * @param list<array<string,mixed>> $all
     * @return list<array{day_index:int,date:string,planned:int,delivered:int,pending:int,on_time:int,late:int}>
     */
    private static function buildDailyDeliverySummary(array $all): array
    {
        $byDate = [];
        foreach ($all as $b) {
            $date = (string) ($b['delivery_date'] ?? '');
            if ($date === '') {
                continue;
            }
            if (!isset($byDate[$date])) {
                $byDate[$date] = [
                    'day_index' => (int) ($b['day_index'] ?? 0),
                    'date' => $date,
                    'planned' => 0,
                    'delivered' => 0,
                    'pending' => 0,
                    'on_time' => 0,
                    'late' => 0,
                ];
            }
            $byDate[$date]['planned']++;
            if (($b['receipt_status'] ?? '') === DeliveryService::STATUS_DELIVERED) {
                $byDate[$date]['delivered']++;
                if (($b['delivery_type'] ?? '') === 'late') {
                    $byDate[$date]['late']++;
                } elseif (($b['delivery_type'] ?? '') === 'on_time') {
                    $byDate[$date]['on_time']++;
                }
            } else {
                $byDate[$date]['pending']++;
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
        $sheet->mergeCells('A1:N1');
        self::styleSectionTitle($sheet, 'A1:N1');

        $headerRow = 3;
        $headers = [
            '#', 'الاسم', 'رقم الهوية', 'رقم الجوال', 'كود الصرف', 'حالة الاستلام',
            'موعد التسليم', 'شباك', 'من', 'إلى',
            'تاريخ التسليم', 'نوع التسليم', 'وقت التسجيل', 'أمين المخزن',
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
            $sheet->fromArray([
                $i + 1,
                $b['name'],
                $b['national_id'],
                null,
                null,
                self::formatReceiptStatusForExport($b),
                $b['delivery_date'],
                $b['window_num'],
                $b['time_from'],
                $b['time_to'],
                $b['actual_delivery_date'] ?? '',
                $typeLabel,
                $b['delivered_at'] ?? '',
                $b['delivered_by_name'] ?? '',
            ], null, 'A' . $row);
            self::setFullCodeCell($sheet, 'E' . $row, (string) ($b['disbursement_code'] ?? ''), $codePrefix, $codeSuffix);
            self::setMobileCell($sheet, 'D' . $row, (string) $b['mobile']);
            $row++;
        }

        $lastRow = max($headerRow, $row - 1);
        if ($row > $headerRow + 1) {
            self::borderAll($sheet, 'A' . $headerRow . ':N' . $lastRow);
            self::styleDataRows($sheet, 'A' . ($headerRow + 1) . ':N' . $lastRow);
        }

        $widths = ['A' => 5, 'B' => 22, 'C' => 14, 'D' => 12, 'E' => 13, 'F' => 12, 'G' => 12, 'H' => 6, 'I' => 7, 'J' => 7, 'K' => 12, 'L' => 10, 'M' => 18, 'N' => 16];
        foreach ($widths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        self::applyPortraitPrint($sheet, $headerRow, $lastRow, 'N');
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
                $i + 1,
                null,
                $m['beneficiary_name'] ?? '',
                null,
                $m['message_text'] ?? '',
                $status,
                $m['created_at'] ?? '',
                $m['sent_at'] ?? '',
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
            ['عدد المستفيدين', count($all), 'اسم المخزن', $campaign['warehouse_name'], 'من', $campaign['delivery_start']],
            ['إلى', $campaign['delivery_end'], 'أيام العمل', (int) $campaign['num_days'], 'شبابيك/يوم', (int) ($campaign['num_windows'] ?? 0)],
            ['موقع المخزن', $campaign['warehouse_location'], 'مستفيد/شباك', (int) $campaign['per_window_capacity'], '', ''],
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
                $i + 1,
                $b['name'],
                $b['national_id'],
                null,
                self::formatReceiptStatusForExport($b),
                null,
                $b['delivery_date'],
                $b['window_num'],
                $b['time_from'],
                $b['time_to'],
                $b['actual_delivery_date'] ?? '',
                $typeLabel,
                $b['delivered_at'] ?? '',
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
                    $codeCmp = strcmp(
                        (string) ($a['disbursement_code'] ?? ''),
                        (string) ($b['disbursement_code'] ?? '')
                    );
                    if ($codeCmp !== 0) {
                        return $codeCmp;
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
                    ['تاريخ البداية', $campaign['delivery_start'], 'تاريخ النهاية', $campaign['delivery_end'], 'اسم المخزن', $campaign['warehouse_name']],
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
                    ['يوم التسليم', $first['delivery_date'], 'رقم الشباك', $w],
                    ['عدد المستفيدين', count($items), 'ساعات العمل', $campaign['work_start'] . ' — ' . $campaign['work_end']],
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
                        $i + 1,
                        $b['national_id'],
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

    private static function buildMessagesSheets(Spreadsheet $spreadsheet, array $all): void
    {
        $jawwal = [];
        $ooredoo = [];
        $other = [];

        foreach ($all as $beneficiary) {
            $carrier = PhoneHelper::carrier((string) ($beneficiary['mobile'] ?? ''));
            if ($carrier === PhoneHelper::CARRIER_JAWWAL) {
                $jawwal[] = $beneficiary;
            } elseif ($carrier === PhoneHelper::CARRIER_OOREDOO) {
                $ooredoo[] = $beneficiary;
            } else {
                $other[] = $beneficiary;
            }
        }

        self::sortByDisbursementCode($jawwal);
        self::sortByDisbursementCode($ooredoo);
        self::sortByDisbursementCode($other);

        self::buildCarrierMessagesSheet($spreadsheet, 'رسائل_جوال', $jawwal);
        self::buildCarrierMessagesSheet($spreadsheet, 'رسائل_أوريدو', $ooredoo);

        // لا نهمل أي رقم غير متوقع؛ نظهره في كشف مستقل للمراجعة.
        if ($other !== []) {
            self::buildCarrierMessagesSheet($spreadsheet, 'رسائل_غير_مصنفة', $other);
        }
    }

    /** @param list<array<string,mixed>> $items */
    private static function sortByDisbursementCode(array &$items): void
    {
        usort($items, static function ($a, $b) {
            return strcmp(
                (string) ($a['disbursement_code'] ?? ''),
                (string) ($b['disbursement_code'] ?? '')
            );
        });
    }

    /** @param list<array<string,mixed>> $beneficiaries */
    private static function buildCarrierMessagesSheet(
        Spreadsheet $spreadsheet,
        string $title,
        array $beneficiaries
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        $sheet->setRightToLeft(true);

        $headerRow = 1;
        $headers = ['#', 'رقم الجوال', 'نص الرسالة'];
        self::writeHeaderRow($sheet, $headerRow, $headers);

        $row = 2;
        foreach ($beneficiaries as $i => $b) {
            $sheet->setCellValue('A' . $row, $i + 1);
            self::setMobileCell(
                $sheet,
                'B' . $row,
                PhoneHelper::messageRecipient((string) ($b['mobile'] ?? ''))
            );
            $sheet->setCellValue('C' . $row, $b['message_text']);
            $sheet->getStyle('C' . $row)->getAlignment()->setWrapText(true);
            $row++;
        }

        $lastRow = max($headerRow, $row - 1);
        self::borderAll($sheet, 'A1:C' . $lastRow);
        if ($beneficiaries !== []) {
            self::styleDataRows($sheet, 'A2:C' . $lastRow);
        }

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(90);

        self::applyPortraitPrint($sheet, $headerRow, $lastRow, 'C');
    }

    private static function setMobileCell(Worksheet $sheet, string $cell, string $mobile): void
    {
        $normalized = PhoneHelper::normalize($mobile);
        if ($normalized !== '' && $normalized !== '0' && ctype_digit($normalized)) {
            $sheet->setCellValueExplicit($cell, (int) $normalized, DataType::TYPE_NUMERIC);
        } else {
            $sheet->setCellValue($cell, $normalized);
        }
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
            ParcelCodeHelper::displayFull(
                $disbursementCode,
                $codeSuffix !== '' ? $codeSuffix : null,
                $codePrefix !== '' ? $codePrefix : null
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
