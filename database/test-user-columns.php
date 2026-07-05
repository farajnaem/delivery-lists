<?php
require dirname(__DIR__) . '/src/bootstrap.php';

use App\ExcelImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$s = new Spreadsheet();
$s->getActiveSheet()->fromArray([
    ['اسم رب الأسرة', 'رقم الهوية', 'رقم التواصل', 'مركز الإيواء', 'حالة الاستلام', 'تاريخ الترشيح'],
    ['أحمد علي', '401111111', '0599111111', 'مركز 1', 'قيد التسليم', '2026-01-01'],
], null, 'A1');
$path = dirname(__DIR__) . '/storage/test-user-columns.xlsx';
(new Xlsx($s))->save($path);

$items = ExcelImportService::parse($path);
echo 'OK: ' . count($items) . ' rows — ' . $items[0]['name'] . PHP_EOL;
