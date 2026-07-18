<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#0F766E">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="<?= e(url('/manifest.json')) ?>">
    <title><?= e($title ?? 'تسليم المخزن') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/warehouse.css')) ?>">
</head>
<body class="warehouse-app">
<?php require dirname(__DIR__) . '/' . $template . '.php'; ?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= e(url('/sw.js')) ?>').catch(function () {});
}
</script>
</body>
</html>
