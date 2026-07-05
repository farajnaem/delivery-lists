<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = rtrim(config('app_url', ''), '/');
    return $base . ($path === '' ? '' : (str_starts_with($path, '/') ? $path : '/' . $path));
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function view(string $template, array $data = []): void
{
    $data['template'] = $template;
    extract($data, EXTR_SKIP);
    $title = $title ?? config('app_name');
    require dirname(__DIR__) . '/views/partials/layout.php';
}

function warehouse_view(string $template, array $data = []): void
{
    $data['template'] = $template;
    extract($data, EXTR_SKIP);
    $title = $title ?? 'تسليم المخزن';
    require dirname(__DIR__) . '/views/partials/warehouse-layout.php';
}

function partial(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    require dirname(__DIR__) . '/views/' . $template . '.php';
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['old'][$key] ?? $default;
}

function store_old(array $data): void
{
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

function asset(string $path): string
{
    $file = dirname(__DIR__) . '/public' . $path;
    $v = is_file($file) ? filemtime($file) : time();
    return url($path . '?v=' . $v);
}

function format_date(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('Y-m-d', $ts) : $date;
}

function format_time(string $time): string
{
    return substr($time, 0, 5);
}

/** عمليات Excel/توزيع كبيرة — تجاوز حد 30 ثانية الافتراضي. */
function extend_runtime(int $seconds = 600): void
{
    @set_time_limit($seconds);
    @ini_set('max_execution_time', (string) $seconds);
    @ini_set('memory_limit', '512M');
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
