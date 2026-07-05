<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth;
use App\CampaignService;
use App\Csrf;
use App\DeliveryService;
use App\DistributionService;
use App\ExcelExportService;
use App\ExcelImportService;
use App\RoleHelper;
use App\SmsService;
use App\UserService;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Strip base path if needed
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
    $uri = substr($uri, strlen($scriptDir)) ?: '/';
}

if ($uri === '/setup' || $uri === '/setup.php') {
    if (UserService::count() > 0) {
        redirect('/login');
    }
    if ($method === 'POST') {
        if (!Csrf::verify($_POST['_csrf'] ?? null)) {
            flash('error', 'انتهت صلاحية النموذج.');
            redirect('/setup');
        }
        require dirname(__DIR__) . '/database/install.php';
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($name === '' || $email === '' || strlen($password) < 8) {
            store_old($_POST);
            flash('error', 'أكمل جميع الحقول — كلمة المرور 8 أحرف على الأقل.');
            redirect('/setup');
        }
        UserService::create($name, $email, $password, 'admin');
        flash('success', 'تم إنشاء حساب المدير — سجّل الدخول.');
        redirect('/login');
    }
    view('auth/setup', ['title' => 'إعداد النظام']);
    exit;
}

if ($uri === '/login' && $method === 'GET') {
    if (UserService::count() === 0) {
        redirect('/setup');
    }
    if (Auth::check()) {
        redirect(RoleHelper::homePath(Auth::role() ?? ''));
    }
    view('auth/login', ['title' => 'تسجيل الدخول']);
    exit;
}

if ($uri === '/login' && $method === 'POST') {
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/login');
    }
    if (Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) {
        redirect(RoleHelper::homePath(Auth::role() ?? ''));
    }
    flash('error', 'بريد أو كلمة مرور غير صحيحة.');
    redirect('/login');
}

if ($uri === '/logout') {
    Auth::logout();
    redirect('/login');
}

Auth::requireLogin();

Auth::requireLogin();

$role = Auth::role() ?? '';
if ($role === 'warehouse_keeper') {
    $keeperAllowed = in_array($uri, ['/warehouse', '/warehouse/deliver', '/logout'], true)
        || str_starts_with($uri, '/api/warehouse');
    if (!$keeperAllowed) {
        redirect('/warehouse');
    }
}

// ——— API: تسليم المخزن ———
if (str_starts_with($uri, '/api/warehouse')) {
    Auth::requireRole(fn ($r) => RoleHelper::canDeliver($r));

    if ($uri === '/api/warehouse/search' && $method === 'GET') {
        $campaignId = (int) ($_GET['campaign_id'] ?? 0);
        $q = trim($_GET['q'] ?? '');
        $beneficiary = DeliveryService::search($campaignId, $q);
        if (!$beneficiary) {
            json_response(['ok' => false, 'error' => 'لم يُعثر على مستفيد'], 404);
        }
        json_response(['ok' => true, 'beneficiary' => $beneficiary]);
    }

    if ($uri === '/api/warehouse/deliver' && $method === 'POST') {
        $body = read_json_body();
        if (!Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            json_response(['ok' => false, 'error' => 'انتهت صلاحية الجلسة'], 403);
        }
        $campaignId = (int) ($body['campaign_id'] ?? 0);
        $beneficiaryId = (int) ($body['beneficiary_id'] ?? 0);
        $clientId = isset($body['client_id']) ? (string) $body['client_id'] : null;
        $result = DeliveryService::markDelivered($campaignId, $beneficiaryId, Auth::id() ?? 0, $clientId);
        if (!$result['ok']) {
            json_response($result, 400);
        }
        $result['stock'] = DeliveryService::stockStats($campaignId);
        json_response($result);
    }

    if ($uri === '/api/warehouse/sync' && $method === 'POST') {
        $body = read_json_body();
        if (!Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            json_response(['ok' => false, 'error' => 'انتهت صلاحية الجلسة'], 403);
        }
        $campaignId = (int) ($body['campaign_id'] ?? 0);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $result = DeliveryService::syncBatch($campaignId, Auth::id() ?? 0, $items);
        $result['stock'] = DeliveryService::stockStats($campaignId);
        json_response($result);
    }

    if ($uri === '/api/warehouse/stats' && $method === 'GET') {
        $campaignId = (int) ($_GET['campaign_id'] ?? 0);
        json_response(['ok' => true, 'stock' => DeliveryService::stockStats($campaignId)]);
    }

    json_response(['ok' => false, 'error' => 'غير موجود'], 404);
}

// ——— صفحة أمين المخزن (PWA) ———
if ($uri === '/warehouse' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canDeliver($r));
    warehouse_view('warehouse/select', [
        'title' => 'تسليم المخزن',
        'campaigns' => DeliveryService::activeCampaigns(),
    ]);
    exit;
}

if ($uri === '/warehouse/deliver' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canDeliver($r));
    $campaignId = (int) ($_GET['campaign_id'] ?? 0);
    $campaign = CampaignService::find($campaignId);
    if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
        flash('error', 'العملية غير جاهزة للتسليم.');
        redirect('/warehouse');
    }
    warehouse_view('warehouse/deliver', [
        'title' => $campaign['name'],
        'campaign' => $campaign,
        'stock' => DeliveryService::stockStats($campaignId),
        'recent' => DeliveryService::recentDeliveries($campaignId, 15),
    ]);
    exit;
}

if ($uri === '/campaigns/stock' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canViewStock($r));
    $id = (int) ($_GET['id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    view('warehouse/dashboard', [
        'title' => 'متابعة المخزن',
        'campaign' => $campaign,
        'stock' => DeliveryService::stockStats($id),
        'recent' => DeliveryService::recentDeliveries($id, 30),
        'lateList' => DeliveryService::pendingLate($id, 50),
        'canEdit' => RoleHelper::canEditCampaign(Auth::role() ?? ''),
        'canDeliver' => RoleHelper::canDeliver(Auth::role() ?? ''),
        'canExport' => RoleHelper::canViewStock(Auth::role() ?? ''),
        'smsPending' => SmsService::pendingCount($id),
        'smsEnabled' => SmsService::isEnabled(),
    ]);
    exit;
}

if ($uri === '/campaigns/opening-quantity' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/stock?id=' . $id);
    }
    CampaignService::updateOpeningQuantity($id, (int) ($_POST['opening_quantity'] ?? 0));
    flash('success', 'تم حفظ الكمية الافتتاحية.');
    redirect('/campaigns/stock?id=' . $id);
}

if ($uri === '/' || $uri === '/campaigns') {
    if ((Auth::role() ?? '') === 'warehouse_keeper') {
        redirect('/warehouse');
    }
    view('campaigns/index', [
        'title' => 'عمليات التوزيع',
        'campaigns' => CampaignService::all(),
        'canCreate' => RoleHelper::canCreateCampaign(Auth::role() ?? ''),
    ]);
    exit;
}

if ($uri === '/campaigns/create' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canCreateCampaign($r));
    view('campaigns/create', ['title' => 'عملية توزيع جديدة']);
    exit;
}

if ($uri === '/campaigns/create' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canCreateCampaign($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/create');
    }

    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'parcel_name' => trim($_POST['parcel_name'] ?? ''),
        'parcel_code' => strtoupper(trim($_POST['parcel_code'] ?? '')),
        'delivery_start' => $_POST['delivery_start'] ?? '',
        'delivery_end' => $_POST['delivery_end'] ?? '',
        'warehouse_name' => trim($_POST['warehouse_name'] ?? ''),
        'warehouse_location' => trim($_POST['warehouse_location'] ?? ''),
        'num_days' => (int) ($_POST['num_days'] ?? 1),
        'work_start' => $_POST['work_start'] ?? '09:00',
        'work_end' => $_POST['work_end'] ?? '15:00',
        'per_window_capacity' => max(1, (int) ($_POST['per_window_capacity'] ?? 500)),
        'opening_quantity' => max(0, (int) ($_POST['opening_quantity'] ?? 0)),
    ];

    if ($data['name'] === '' || $data['parcel_name'] === '' || !str_starts_with($data['parcel_code'], 'SOCI')) {
        store_old($_POST);
        flash('error', 'أكمل البيانات — كود الطرد يبدأ بـ SOCI.');
        redirect('/campaigns/create');
    }

    $id = CampaignService::create($data, Auth::id() ?? 0);

    if (!empty($_FILES['excel_file']['tmp_name'])) {
        try {
            $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                throw new RuntimeException('صيغة الملف غير مدعومة — استخدم xlsx.');
            }
            $tmp = dirname(__DIR__) . '/storage/uploads/' . uniqid('import_', true) . '.' . $ext;
            move_uploaded_file($_FILES['excel_file']['tmp_name'], $tmp);
            $items = ExcelImportService::parse($tmp);
            $count = ExcelImportService::saveBeneficiaries($id, $items);
            @unlink($tmp);
            flash('success', "تم إنشاء العملية واستيراد {$count} مستفيد.");
        } catch (Throwable $e) {
            flash('error', 'خطأ في Excel: ' . $e->getMessage());
        }
    } else {
        flash('success', 'تم إنشاء العملية — ارفع Excel من صفحة التفاصيل.');
    }

    redirect('/campaigns/view?id=' . $id);
}

if ($uri === '/campaigns/view' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    $stats = CampaignService::stats($id);
    $preview = CampaignService::beneficiaries($id);
    $plan = null;
    if (($stats['total'] ?? 0) > 0) {
        $plan = DistributionService::plan(
            (int) $stats['total'],
            (int) $campaign['num_days'],
            max(1, (int) $campaign['per_window_capacity'])
        );
    }
    view('campaigns/view', [
        'title' => $campaign['name'],
        'campaign' => $campaign,
        'stats' => $stats,
        'plan' => $plan,
        'preview' => array_slice($preview, 0, 20),
        'canEdit' => RoleHelper::canEditCampaign(Auth::role() ?? ''),
        'canExport' => RoleHelper::canExport(Auth::role() ?? ''),
        'canViewStock' => RoleHelper::canViewStock(Auth::role() ?? ''),
        'canDeliver' => RoleHelper::canDeliver(Auth::role() ?? ''),
        'deliveryStats' => ($campaign['status'] ?? '') === 'generated'
            ? DeliveryService::stockStats($id)
            : null,
    ]);
    exit;
}

if ($uri === '/campaigns/import' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/view?id=' . $id);
    }
    try {
        $ext = strtolower(pathinfo($_FILES['excel_file']['name'] ?? '', PATHINFO_EXTENSION));
        $tmp = dirname(__DIR__) . '/storage/uploads/' . uniqid('import_', true) . '.' . $ext;
        move_uploaded_file($_FILES['excel_file']['tmp_name'], $tmp);
        $items = ExcelImportService::parse($tmp);
        $count = ExcelImportService::saveBeneficiaries($id, $items);
        @unlink($tmp);
        flash('success', "تم استيراد {$count} مستفيد.");
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/campaigns/view?id=' . $id);
}

if ($uri === '/campaigns/generate' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/view?id=' . $id);
    }
    try {
        $summary = DistributionService::generate($id);
        $daily = $summary['daily_counts'][0] ?? 0;
        $windows = $summary['days'][0]['windows'] ?? 0;
        $sheets = $summary['total_delivery_sheets'] ?? 0;
        flash('success', "تم التوليد: {$summary['total']} مستفيد → {$daily} / يوم → {$windows} شبابيك → {$sheets} كشف تسليم.");
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/campaigns/view?id=' . $id);
}

if ($uri === '/campaigns/export' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $path = ExcelExportService::export($id);
        $campaign = CampaignService::find($id);
        $filename = ($campaign['name'] ?? 'export') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/campaigns/view?id=' . $id);
    }
}

if ($uri === '/campaigns/export-deliveries' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canViewStock($r));
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $path = ExcelExportService::exportDeliveries($id);
        $campaign = CampaignService::find($id);
        $filename = ($campaign['name'] ?? 'deliveries') . '_تسليمات.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/campaigns/stock?id=' . $id);
    }
}

if ($uri === '/campaigns/sms-send' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/stock?id=' . $id);
    }
    if (!SmsService::isEnabled()) {
        flash('error', 'إرسال SMS غير مفعّل — راجع إعدادات .env');
        redirect('/campaigns/stock?id=' . $id);
    }
    $result = SmsService::sendPendingBatch($id);
    flash('success', "تم إرسال {$result['sent']} رسالة — فشل {$result['failed']}.");
    redirect('/campaigns/stock?id=' . $id);
}

if ($uri === '/users' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageUsers($r));
    view('auth/users', [
        'title' => 'إدارة المستخدمين',
        'users' => UserService::all(),
        'roles' => RoleHelper::ROLES,
    ]);
    exit;
}

if ($uri === '/users/create' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageUsers($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/users');
    }
    UserService::create(
        trim($_POST['name'] ?? ''),
        trim($_POST['email'] ?? ''),
        $_POST['password'] ?? '',
        $_POST['role'] ?? 'viewer'
    );
    flash('success', 'تم إضافة المستخدم.');
    redirect('/users');
}

http_response_code(404);
view('errors/notfound', ['title' => 'غير موجود']);
