<?php

declare(strict_types=1);

namespace App;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;

final class ExcelImportService
{
    private const BATCH_SIZE = 500;

    /** @return list<string> */
    public static function acceptedColumnLabels(): array
    {
        return [
            'name' => 'اسم رب الأسرة (أو: اسم المستفيد، الاسم)',
            'national_id' => 'رقم الهوية',
            'mobile' => 'رقم التواصل (أو: رقم الجوال)',
            'status' => 'حالة الاستلام (اختياري — افتراضي: قيد التسليم)',
        ];
    }

    /** @return list<array{name:string,national_id:string,mobile:string,receipt_status:string}> */
    public static function parse(string $filePath): array
    {
        extend_runtime();

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $reader);

        if (count($rows) < 2) {
            throw new \RuntimeException('الملف فارغ أو لا يحتوي على بيانات.');
        }

        $header = array_map(fn ($h) => self::normalizeHeader((string) $h), $rows[0]);
        $map = self::mapColumns($header);

        $items = [];
        for ($i = 1, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            $name = trim((string) ($row[$map['name']] ?? ''));
            $nationalId = trim((string) ($row[$map['national_id']] ?? ''));
            $mobile = trim((string) ($row[$map['mobile']] ?? ''));

            if ($name === '' && $nationalId === '' && $mobile === '') {
                continue;
            }
            if ($name === '' || $nationalId === '') {
                throw new \RuntimeException('صف ' . ($i + 1) . ': الاسم ورقم الهوية مطلوبان.');
            }

            $statusCol = $map['status'];
            $status = $statusCol !== null
                ? trim((string) ($row[$statusCol] ?? ''))
                : DeliveryService::STATUS_PENDING;
            $status = DeliveryService::normalizeReceiptStatus($status);

            $items[] = [
                'name' => $name,
                'national_id' => $nationalId,
                'mobile' => PhoneHelper::normalize($mobile),
                'receipt_status' => $status,
            ];
        }

        if ($items === []) {
            throw new \RuntimeException('لم يُعثر على مستفيدين في الملف.');
        }

        return $items;
    }

    public static function saveBeneficiaries(int $campaignId, array $items): int
    {
        extend_runtime();

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM beneficiaries WHERE campaign_id = ?')->execute([$campaignId]);

            foreach (array_chunk($items, self::BATCH_SIZE) as $chunk) {
                self::insertBatch($pdo, $campaignId, $chunk);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return count($items);
    }

    /** @param list<array{name:string,national_id:string,mobile:string,receipt_status:string}> $chunk */
    private static function insertBatch(PDO $pdo, int $campaignId, array $chunk): void
    {
        if ($chunk === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
        $sql = '
            INSERT INTO beneficiaries (campaign_id, name, national_id, mobile, receipt_status)
            VALUES ' . $placeholders;

        $params = [];
        foreach ($chunk as $item) {
            $params[] = $campaignId;
            $params[] = $item['name'];
            $params[] = $item['national_id'];
            $params[] = $item['mobile'];
            $params[] = $item['receipt_status'];
        }

        $pdo->prepare($sql)->execute($params);
    }

    private static function normalizeHeader(string $h): string
    {
        $h = trim($h);
        $h = preg_replace('/\s+/u', ' ', $h) ?? $h;
        return mb_strtolower($h);
    }

    /** @param list<string> $header */
    private static function mapColumns(array $header): array
    {
        $aliases = [
            'name' => [
                'اسم رب الأسرة',
                'اسم المستفيد',
                'الاسم',
                'اسم',
                'name',
                'beneficiary',
            ],
            'national_id' => [
                'رقم الهوية',
                'الهوية',
                'هوية',
                'national id',
            ],
            'mobile' => [
                'رقم التواصل',
                'رقم الجوال',
                'التواصل',
                'الجوال',
                'جوال',
                'mobile',
                'phone',
                'tel',
            ],
            'status' => [
                'حالة الاستلام',
                'الحالة',
                'status',
            ],
        ];

        $requiredLabels = [
            'name' => 'اسم رب الأسرة',
            'national_id' => 'رقم الهوية',
            'mobile' => 'رقم التواصل',
        ];

        $map = [];
        foreach ($aliases as $key => $options) {
            foreach ($header as $idx => $col) {
                foreach ($options as $opt) {
                    if ($col === self::normalizeHeader($opt) || str_contains($col, self::normalizeHeader($opt))) {
                        $map[$key] = $idx;
                        break 2;
                    }
                }
            }
        }

        foreach (['name', 'national_id', 'mobile'] as $required) {
            if (!isset($map[$required])) {
                throw new \RuntimeException(
                    'عمود مطلوب غير موجود: ' . ($requiredLabels[$required] ?? $required)
                    . '. الأعمدة المعتمدة: اسم رب الأسرة، رقم الهوية، رقم التواصل، حالة الاستلام (اختياري).'
                );
            }
        }
        if (!isset($map['status'])) {
            $map['status'] = null;
        }

        return $map;
    }
}
