<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ArabicFormat;
use App\MobileAuth;
use App\MobileSyncService;
use App\Auth;
use App\CampaignService;
use App\Csrf;
use App\DatabaseBackupService;
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
        if (UserService::emailExists($email)) {
            store_old($_POST);
            flash('error', 'البريد الإلكتروني مستخدم مسبقاً — سجّل الدخول أو استخدم بريداً آخر.');
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

// ——— API: تطبيق الموبايل (Bearer token — بدون جلسة ويب) ———
if (str_starts_with($uri, '/api/mobile')) {
    if ($uri === '/api/mobile/health' && $method === 'GET') {
        json_response([
            'ok' => true,
            'service' => 'delivery-lists-mobile',
            'time' => db_now(),
            'app_key_configured' => trim((string) env('APP_KEY', '')) !== '',
        ]);
    }

    if ($uri === '/api/mobile/login' && $method === 'POST') {
        $body = read_json_body();
        $result = MobileAuth::login($body['email'] ?? '', $body['password'] ?? '');
        if ($result === null) {
            json_response(['ok' => false, 'error' => 'بريد أو كلمة مرور غير صحيحة — أمين مخزن فقط'], 401);
        }
        json_response(['ok' => true] + $result);
    }

    MobileAuth::requireAuth();

    if ($uri === '/api/mobile/logout' && $method === 'POST') {
        $body = read_json_body();
        if (!empty($body['token'])) {
            MobileAuth::logout((string) $body['token']);
        }
        json_response(['ok' => true]);
    }

    if ($uri === '/api/mobile/campaigns' && $method === 'GET') {
        json_response(['ok' => true] + MobileSyncService::campaignsPayload());
    }

    if (preg_match('#^/api/mobile/campaigns/(\d+)/snapshot$#', $uri, $m) && $method === 'GET') {
        $campaignId = (int) $m[1];
        try {
            json_response(['ok' => true] + MobileSyncService::snapshot($campaignId));
        } catch (\Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    if ($uri === '/api/mobile/sync' && $method === 'POST') {
        $body = read_json_body();
        $campaignId = (int) ($body['campaign_id'] ?? 0);
        $pending = is_array($body['pending_deliveries'] ?? null) ? $body['pending_deliveries'] : [];
        $lastSync = isset($body['last_sync_token']) ? (string) $body['last_sync_token'] : null;
        try {
            $result = MobileSyncService::sync(
                $campaignId,
                MobileAuth::userId() ?? 0,
                $lastSync,
                $pending
            );
            json_response($result);
        } catch (\Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    json_response(['ok' => false, 'error' => 'غير موجود'], 404);
}

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

    if ($uri === '/api/warehouse/csrf' && $method === 'GET') {
        json_response(['ok' => true, 'csrf' => Csrf::token()]);
    }

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
            json_response(['ok' => false, 'error' => 'انتهت صلاحية النموذج — حدّث الصفحة أو انتظر لحظة ثم أعد المحاولة', 'csrf_expired' => true], 403);
        }
        $campaignId = (int) ($body['campaign_id'] ?? 0);
        $beneficiaryId = (int) ($body['beneficiary_id'] ?? 0);
        $clientId = isset($body['client_id']) ? (string) $body['client_id'] : null;
        $result = DeliveryService::markDelivered($campaignId, $beneficiaryId, Auth::id() ?? 0, $clientId);
        if (!$result['ok']) {
            json_response($result, 400);
        }
        $result['stock'] = DeliveryService::stockStatsForDisplay($campaignId);
        json_response($result);
    }

    if ($uri === '/api/warehouse/sync' && $method === 'POST') {
        $body = read_json_body();
        if (!Csrf::verify($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            json_response(['ok' => false, 'error' => 'انتهت صلاحية النموذج — حدّث الصفحة أو انتظر لحظة ثم أعد المحاولة', 'csrf_expired' => true], 403);
        }
        $campaignId = (int) ($body['campaign_id'] ?? 0);
        $items = is_array($body['items'] ?? null) ? $body['items'] : [];
        $result = DeliveryService::syncBatch($campaignId, Auth::id() ?? 0, $items);
        $result['stock'] = DeliveryService::stockStatsForDisplay($campaignId);
        json_response($result);
    }

    if ($uri === '/api/warehouse/stats' && $method === 'GET') {
        $campaignId = (int) ($_GET['campaign_id'] ?? 0);
        json_response(['ok' => true, 'stock' => DeliveryService::stockStatsForDisplay($campaignId)]);
    }

    if ($uri === '/api/warehouse/delivered' && $method === 'GET') {
        $campaignId = (int) ($_GET['campaign_id'] ?? 0);
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
        json_response([
            'ok' => true,
            'delivered' => DeliveryService::deliveredBeneficiaries($campaignId, $limit),
            'total' => ArabicFormat::toArabicDigits((string) DeliveryService::deliveredCount($campaignId)),
        ]);
    }

    if ($uri === '/api/warehouse/snapshot' && $method === 'GET') {
        $campaignId = (int) ($_GET['campaign_id'] ?? 0);
        try {
            $snapshot = MobileSyncService::snapshot($campaignId);
            json_response(['ok' => true] + $snapshot);
        } catch (\Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    json_response(['ok' => false, 'error' => 'غير موجود'], 404);
}

// ——— صفحة أمين المخزن (PWA) ———
if ($uri === '/warehouse' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canDeliver($r));
    $campaigns = DeliveryService::warehouseCampaigns();
    foreach ($campaigns as &$c) {
        $stats = DeliveryService::stockStats((int) $c['id']);
        $c['balance'] = (int) ($stats['balance'] ?? 0);
        $c['campaign_active'] = DeliveryService::isCampaignActive($c);
    }
    unset($c);
    warehouse_view('warehouse/select', [
        'title' => 'تسليم المخزن',
        'campaigns' => $campaigns,
        'canViewOperations' => (Auth::role() ?? '') !== 'warehouse_keeper',
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
        'stock' => DeliveryService::stockStatsForDisplay($campaignId),
        'recent' => DeliveryService::deliveredBeneficiaries($campaignId, 50),
        'canViewStock' => RoleHelper::canViewStock(Auth::role() ?? ''),
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
    $deliveredTotal = DeliveryService::deliveredCount($id);
    view('warehouse/dashboard', [
        'title' => 'متابعة المخزن',
        'campaign' => $campaign,
        'stock' => DeliveryService::stockStats($id),
        'deliveredList' => DeliveryService::deliveredBeneficiaries($id, 100),
        'deliveredTotal' => $deliveredTotal,
        'lateList' => DeliveryService::pendingLate($id, 50),
        'canEdit' => RoleHelper::canEditCampaign(Auth::role() ?? ''),
        'canCloseDelivery' => RoleHelper::canCloseDelivery(Auth::role() ?? ''),
        'canDeliver' => RoleHelper::canDeliver(Auth::role() ?? ''),
        'canExport' => RoleHelper::canViewStock(Auth::role() ?? ''),
        'canCancelDeliveries' => RoleHelper::canCancelDeliveries(Auth::role() ?? ''),
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

if ($uri === '/campaigns/close-delivery' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canCloseDelivery($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/stock?id=' . $id);
    }
    CampaignService::closeDelivery($id);
    flash('success', 'تم إنهاء عملية التسليم.');
    redirect('/campaigns/stock?id=' . $id);
}

if ($uri === '/campaigns/reopen-delivery' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canCloseDelivery($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/stock?id=' . $id);
    }
    CampaignService::reopenDelivery($id);
    flash('success', 'تم إعادة فتح عملية التسليم.');
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
        'canEdit' => RoleHelper::canEditCampaign(Auth::role() ?? ''),
        'canDeliver' => RoleHelper::canDeliver(Auth::role() ?? ''),
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

    $data = parse_campaign_post($_POST);

    $error = validate_campaign_data($data);
    if ($error !== null) {
        store_old($_POST);
        flash('error', $error);
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

if ($uri === '/campaigns/edit' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_GET['id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    view('campaigns/edit', [
        'title' => 'تعديل العملية',
        'campaign' => $campaign,
        'stats' => CampaignService::stats($id),
        'canEdit' => true,
        'canCancelDeliveries' => RoleHelper::canCancelDeliveries(Auth::role() ?? ''),
    ]);
    exit;
}

if ($uri === '/campaigns/edit' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? $_GET['id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/edit?id=' . $id);
    }
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    $data = parse_campaign_post($_POST);
    $error = validate_campaign_data($data);
    if ($error !== null) {
        store_old($_POST);
        flash('error', $error);
        redirect('/campaigns/edit?id=' . $id);
    }
    CampaignService::update($id, $data);
    flash('success', 'تم حفظ التعديلات.');
    redirect('/campaigns/view?id=' . $id);
}

if ($uri === '/campaigns/delete' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/view?id=' . $id);
    }
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    if (CampaignService::deliveredCount($id) > 0) {
        flash('error', 'لا يمكن حذف عملية فيها تسليمات مسجّلة.');
        redirect('/campaigns/edit?id=' . $id);
    }
    $confirm = trim($_POST['confirm_name_input'] ?? '');
    if ($confirm !== ($campaign['name'] ?? '')) {
        flash('error', 'اسم التأكيد غير مطابق — لم يُحذف شيء.');
        redirect('/campaigns/edit?id=' . $id);
    }
    CampaignService::delete($id);
    flash('success', 'تم حذف العملية نهائياً.');
    redirect('/');
}

if ($uri === '/campaigns/undo-deliveries' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canCancelDeliveries($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/edit?id=' . $id);
    }
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    $count = DeliveryService::undoAllDeliveries($id);
    if ($count === 0) {
        flash('error', 'لا توجد تسليمات لإلغائها.');
    } else {
        flash('success', "تم إلغاء {$count} تسليم — يمكنك الآن حذف أو تنظيف العملية.");
    }
    redirect('/campaigns/edit?id=' . $id);
}

if ($uri === '/campaigns/clear-beneficiaries' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/campaigns/view?id=' . $id);
    }
    if (CampaignService::deliveredCount($id) > 0) {
        flash('error', 'لا يمكن تنظيف عملية فيها تسليمات مسجّلة.');
        redirect('/campaigns/edit?id=' . $id);
    }
    $count = CampaignService::clearBeneficiaries($id);
    flash('success', "تم حذف {$count} مستفيد وإعادة العملية لمسودة.");
    redirect('/campaigns/view?id=' . $id);
}

if ($uri === '/admin/database' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageDatabase($r));
    view('admin/database', [
        'title' => 'نسخ احتياطي',
        'backups' => DatabaseBackupService::list(),
    ]);
    exit;
}

if ($uri === '/admin/database/backup' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageDatabase($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/admin/database');
    }
    try {
        $result = DatabaseBackupService::create();
        flash('success', 'تم إنشاء النسخة: ' . $result['filename']);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/database');
}

if ($uri === '/admin/database/restore' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageDatabase($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/admin/database');
    }
    try {
        DatabaseBackupService::restore($_POST['filename'] ?? '');
        flash('success', 'تمت استعادة قاعدة البيانات بنجاح.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/database');
}

if ($uri === '/admin/database/delete' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageDatabase($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/admin/database');
    }
    try {
        DatabaseBackupService::delete($_POST['filename'] ?? '');
        flash('success', 'تم حذف النسخة الاحتياطية.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/database');
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
        $perWindow = max(1, (int) $campaign['per_window_capacity']);
        $numWindows = \App\DistributionService::resolveNumWindows(
            $campaign,
            (int) $stats['total'],
            $perWindow
        );
        $plan = DistributionService::plan(
            (int) $stats['total'],
            $numWindows,
            $perWindow
        );
        if (!empty($campaign['delivery_start'])) {
            $plan['dates'] = DistributionService::buildWorkDates(
                (string) $campaign['delivery_start'],
                (int) $plan['num_days']
            );
        }
    }
    $previewRows = array_slice($preview, 0, 20);
    $codePrefix = (string) ($campaign['parcel_code'] ?? '');
    $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
    if (($campaign['status'] ?? '') === 'generated') {
        $previewRows = array_map(
            static fn (array $b): array => ArabicFormat::localizeBeneficiary($b, $codePrefix, $codeSuffix),
            $previewRows
        );
    }
    view('campaigns/view', [
        'title' => $campaign['name'],
        'campaign' => ArabicFormat::localizeCampaignTimes($campaign),
        'stats' => array_map(
            static fn ($v) => is_int($v) || is_float($v) ? ArabicFormat::toArabicDigits((string) $v) : $v,
            $stats
        ),
        'plan' => $plan,
        'preview' => $previewRows,
        'canEdit' => RoleHelper::canEditCampaign(Auth::role() ?? ''),
        'canExport' => RoleHelper::canExport(Auth::role() ?? ''),
        'canViewStock' => RoleHelper::canViewStock(Auth::role() ?? ''),
        'canDeliver' => RoleHelper::canDeliver(Auth::role() ?? ''),
        'deliveryStats' => ($campaign['status'] ?? '') === 'generated'
            ? DeliveryService::stockStats($id)
            : null,
        'deliveredList' => ($campaign['status'] ?? '') === 'generated'
            ? DeliveryService::deliveredBeneficiaries($id, 50)
            : [],
        'deliveredTotal' => ($campaign['status'] ?? '') === 'generated'
            ? ArabicFormat::toArabicDigits((string) DeliveryService::deliveredCount($id))
            : '٠',
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
        $daily = $summary['daily_capacity'] ?? ($summary['daily_counts'][0] ?? 0);
        $windows = $summary['num_windows'] ?? ($summary['days'][0]['windows'] ?? 0);
        $sheets = $summary['total_delivery_sheets'] ?? 0;
        $days = $summary['num_days'] ?? 0;
        flash('success', "تم التوليد: {$summary['total']} مستفيد → طاقة يومية {$daily} ({$windows} شبابيك) → {$days} أيام عمل (بدون جمعة) → {$sheets} كشف.");
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

if ($uri === '/campaigns/export-messages' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    $day = (int) ($_GET['day'] ?? 0);
    try {
        $path = ExcelExportService::exportMessagesForDay($id, $day);
        $campaign = CampaignService::find($id);
        $filename = ($campaign['name'] ?? 'messages') . '_رسائل_يوم' . $day . '.xlsx';
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

if ($uri === '/campaigns/export-day' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    $day = (int) ($_GET['day'] ?? 0);
    try {
        $path = ExcelExportService::exportDeliveryDay($id, $day);
        $campaign = CampaignService::find($id);
        $filename = ($campaign['name'] ?? 'delivery') . '_يوم' . $day . '.xlsx';
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
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'viewer';
    if ($name === '' || $email === '' || strlen($password) < 8) {
        flash('error', 'أكمل جميع الحقول — كلمة المرور 8 أحرف على الأقل.');
        redirect('/users');
    }
    if (!array_key_exists($role, RoleHelper::ROLES)) {
        flash('error', 'الدور غير صالح.');
        redirect('/users');
    }
    if (UserService::emailExists($email)) {
        flash('error', 'البريد الإلكتروني مستخدم مسبقاً.');
        redirect('/users');
    }
    UserService::create($name, $email, $password, $role);
    flash('success', 'تم إضافة المستخدم.');
    redirect('/users');
}

if ($uri === '/users/update' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageUsers($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/users');
    }
    $id = (int) ($_POST['user_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role = $_POST['role'] ?? 'viewer';
    $isActive = isset($_POST['is_active']);
    $user = UserService::find($id);
    if (!$user) {
        flash('error', 'المستخدم غير موجود.');
        redirect('/users');
    }
    if ($name === '' || $email === '' || !array_key_exists($role, RoleHelper::ROLES)) {
        flash('error', 'تحقق من البيانات والدور.');
        redirect('/users');
    }
    if (UserService::emailExists($email, $id)) {
        flash('error', 'البريد الإلكتروني مستخدم مسبقاً.');
        redirect('/users');
    }
    if ($user['role'] === 'admin' && $role !== 'admin' && UserService::adminCount() <= 1) {
        flash('error', 'لا يمكن تغيير دور آخر مدير نشط في النظام.');
        redirect('/users');
    }
    if (!$isActive && $user['role'] === 'admin' && UserService::adminCount() <= 1) {
        flash('error', 'لا يمكن تعطيل آخر مدير نشط في النظام.');
        redirect('/users');
    }
    UserService::update($id, $name, $email, $role, $isActive, $password !== '' ? $password : null);
    flash('success', 'تم تحديث المستخدم.');
    redirect('/users');
}

if ($uri === '/users/deactivate' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageUsers($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', 'انتهت صلاحية النموذج.');
        redirect('/users');
    }
    $id = (int) ($_POST['user_id'] ?? 0);
    if ($id === (int) (Auth::id() ?? 0)) {
        flash('error', 'لا يمكنك تعطيل حسابك.');
        redirect('/users');
    }
    $user = UserService::find($id);
    if (!$user) {
        flash('error', 'المستخدم غير موجود.');
        redirect('/users');
    }
    if ($user['role'] === 'admin' && UserService::adminCount() <= 1) {
        flash('error', 'لا يمكن تعطيل آخر مدير نشط في النظام.');
        redirect('/users');
    }
    UserService::deactivate($id);
    flash('success', 'تم تعطيل المستخدم.');
    redirect('/users');
}

http_response_code(404);
view('errors/notfound', ['title' => 'غير موجود']);
