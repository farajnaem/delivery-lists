<?php
$appName = (string) config('app_name');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? $appName) ?> — <?= e($appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
    <style>
        body { background: #fff; }
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            padding: 0.75rem 1.25rem;
            background: #f7f8f8;
            border-bottom: 1px solid #e2e8f0;
        }
        .print-sheet {
            max-width: 920px;
            margin: 1.25rem auto 2rem;
            padding: 0 1rem;
        }
        .print-title { font-size: 1.35rem; font-weight: 700; margin: 0 0 0.35rem; }
        .print-meta { color: #64748b; margin: 0 0 1rem; }
        .print-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .print-table th,
        .print-table td {
            border: 1px solid #cbd5e1;
            padding: 0.4rem 0.5rem;
            text-align: right;
            vertical-align: top;
        }
        .print-table th {
            background: #eef2f7;
            font-weight: 600;
        }
        .print-table tbody tr:nth-child(even) td { background: #f8fafc; }
        .print-shelter-head td {
            background: #e6f4f2 !important;
            font-weight: 700;
            font-size: 0.95rem;
        }
        @media print {
            .print-toolbar { display: none !important; }
            .print-sheet { max-width: none; margin: 0; padding: 0; }
            .print-table { font-size: 11px; }
            .print-table th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .print-shelter-head td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { size: A4 portrait; margin: 12mm; }
        }
    </style>
</head>
<body>
    <?php require dirname(__DIR__) . '/' . $template . '.php'; ?>
</body>
</html>
