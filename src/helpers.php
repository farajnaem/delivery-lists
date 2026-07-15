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

/** وقت حالي بصيغة متوافقة مع SQLite وMySQL. */
function db_now(): string
{
    return date('Y-m-d H:i:s');
}

/** عمليات Excel/توزيع كبيرة — تجاوز حد 30 ثانية الافتراضي. */
function extend_runtime(int $seconds = 1800): void
{
    @ignore_user_abort(true);
    @set_time_limit($seconds);
    @ini_set('max_execution_time', (string) $seconds);
    @ini_set('memory_limit', '1024M');
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

/** استخراج بيانات العملية من POST. */
function parse_campaign_post(array $post): array
{
    return [
        'name' => trim($post['name'] ?? ''),
        'pipeline_name' => trim($post['pipeline_name'] ?? ''),
        'parcel_name' => trim($post['parcel_name'] ?? ''),
        'parcel_code' => \App\ParcelCodeHelper::normalizePrefix($post['parcel_code'] ?? \App\ParcelCodeHelper::DEFAULT_PREFIX),
        'parcel_code_suffix' => \App\ParcelCodeHelper::normalizeSuffix($post['parcel_code_suffix'] ?? ''),
        'delivery_start' => $post['delivery_start'] ?? '',
        'delivery_end' => trim((string) ($post['delivery_end'] ?? '')) !== ''
            ? (string) $post['delivery_end']
            : (string) ($post['delivery_start'] ?? ''),
        'warehouse_name' => trim($post['warehouse_name'] ?? ''),
        'warehouse_location' => trim($post['warehouse_location'] ?? ''),
        'num_days' => max(1, (int) ($post['num_days'] ?? 1)),
        'num_windows' => max(1, (int) ($post['num_windows'] ?? 4)),
        'work_start' => $post['work_start'] ?? '09:00',
        'work_end' => $post['work_end'] ?? '15:00',
        'per_window_capacity' => max(1, (int) ($post['per_window_capacity'] ?? 400)),
        'opening_quantity' => max(0, (int) ($post['opening_quantity'] ?? 0)),
    ];
}

/**
 * شريط تنقّل سياقي: مسار + أزرار سريعة.
 *
 * @param list<array{label:string,url?:string}> $crumbs
 * @param list<array{label:string,url:string,primary?:bool}> $actions
 */
function context_nav(array $crumbs, array $actions = []): void
{
    partial('partials/context-nav', ['crumbs' => $crumbs, 'actions' => $actions]);
}

function validate_campaign_data(array $data): ?string
{
    if ($data['name'] === '' || $data['parcel_name'] === '') {
        return 'أكمل اسم العملية واسم الطرد.';
    }
    if (!\App\ParcelCodeHelper::validatePrefix($data['parcel_code'])) {
        return 'أدخل كود الطرد (حرف أو مجموعة حروف مثل SOCI أو REC).';
    }
    if ($data['delivery_start'] === '') {
        return 'حدد تاريخ بدء التسليم.';
    }
    if (($data['num_windows'] ?? 0) < 1) {
        return 'أدخل عدد الشبابيك (1 فأكثر).';
    }
    if ($data['warehouse_name'] === '' || $data['warehouse_location'] === '') {
        return 'أكمل بيانات المخزن.';
    }
    return null;
}
