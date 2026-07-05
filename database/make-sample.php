<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray([
    ['اسم المستفيد', 'رقم الهوية', 'رقم الجوال', 'حالة الاستلام'],
    ['أحمد محمد', '401234567', '0599123456', 'قيد التسليم'],
    ['سارة علي', '402345678', '0599234567', 'قيد التسليم'],
    ['محمد خالد', '403456789', '0599345678', 'قيد التسليم'],
    ['فاطمة يوسف', '404567890', '0599456789', 'قيد التسليم'],
    ['يوسف إبراهيم', '405678901', '0599567890', 'قيد التسليم'],
], null, 'A1');

$path = dirname(__DIR__) . '/storage/sample-beneficiaries.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($path);
echo "Sample: {$path}\n";
