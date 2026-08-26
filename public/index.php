<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\ArabicFormat;
use App\MobileAuth;
use App\MobileSyncService;
use App\Auth;
use App\CampaignMergeService;
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
            flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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

    if ($uri === '/api/mobile/deliver' && $method === 'POST') {
        $body = read_json_body();
        $campaignId = (int) ($body['campaign_id'] ?? 0);
        $beneficiaryId = (int) ($body['beneficiary_id'] ?? 0);
        $clientId = isset($body['client_id']) ? (string) $body['client_id'] : null;
        $receivedByMode = isset($body['received_by_mode']) ? (string) $body['received_by_mode'] : null;
        $receivedByName = isset($body['received_by_name']) ? (string) $body['received_by_name'] : null;
        $deliveredAt = isset($body['delivered_at']) ? (string) $body['delivered_at'] : null;
        try {
            $result = MobileSyncService::deliver(
                $campaignId,
                $beneficiaryId,
                MobileAuth::userId() ?? 0,
                $clientId,
                $receivedByMode,
                $receivedByName,
                $deliveredAt
            );
            $status = (!empty($result['ok']) || !empty($result['already'])) ? 200 : 400;
            json_response($result, $status);
        } catch (\Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    json_response(['ok' => false, 'error' => 'غير موجود'], 404);
}

// ——— API: تكامل نظام التوزيع المتكامل (Bearer token) ———
if (str_starts_with($uri, '/api/integration')) {
    $expected = trim((string) env('INTEGRATION_API_TOKEN', ''));
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $token = '';
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        $token = $m[1];
    }
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        json_response(['ok' => false, 'error' => 'غير مصرّح'], 401);
    }

    if ($uri === '/api/integration/health' && $method === 'GET') {
        json_response(['ok' => true, 'service' => 'delivery-lists-integration', 'time' => db_now()]);
    }

    if ($uri === '/api/integration/campaigns/from-plan' && $method === 'POST') {
        $body = read_json_body();
        $campaignData = is_array($body['campaign'] ?? null) ? $body['campaign'] : [];
        $beneficiaries = is_array($body['beneficiaries'] ?? null) ? $body['beneficiaries'] : [];
        $existingId = (int) ($body['existing_campaign_id'] ?? 0);
        $replace = (bool) ($body['replace_beneficiaries'] ?? true);

        if (($campaignData['name'] ?? '') === '' || $beneficiaries === []) {
            json_response(['ok' => false, 'error' => 'اسم العملية والمستفيدون مطلوبان'], 422);
        }

        $adminId = (int) (UserService::all()[0]['id'] ?? Auth::id() ?? 1);
        // Prefer admin role if available
        foreach (UserService::all() as $u) {
            if (($u['role'] ?? '') === 'admin') {
                $adminId = (int) $u['id'];
                break;
            }
        }

        $created = false;
        $campaignId = $existingId;
        if ($campaignId > 0 && !CampaignService::find($campaignId)) {
            $campaignId = 0;
        }

        // ابحث بالرابط الخارجي
        if ($campaignId <= 0 && !empty($campaignData['pipeline_name'])) {
            $pdo = \App\Database::getConnection();
            $stmt = $pdo->prepare('SELECT id FROM campaigns WHERE pipeline_name = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([(string) $campaignData['pipeline_name']]);
            $campaignId = (int) ($stmt->fetchColumn() ?: 0);
        }

        $fields = [
            'name' => (string) $campaignData['name'],
            'pipeline_name' => (string) ($campaignData['pipeline_name'] ?? ''),
            'parcel_name' => (string) ($campaignData['parcel_name'] ?? 'طرد'),
            'parcel_code' => (string) ($campaignData['parcel_code'] ?? 'PKG'),
            'parcel_code_suffix' => (string) ($campaignData['parcel_code_suffix'] ?? ''),
            'delivery_start' => (string) ($campaignData['delivery_start'] ?? date('Y-m-d')),
            'delivery_end' => (string) ($campaignData['delivery_end'] ?? date('Y-m-d')),
            'warehouse_name' => (string) ($campaignData['warehouse_name'] ?? 'مخزن'),
            'warehouse_location' => (string) ($campaignData['warehouse_location'] ?? ''),
            'num_days' => max(1, (int) ($campaignData['num_days'] ?? 1)),
            'work_start' => (string) ($campaignData['work_start'] ?? '08:00'),
            'work_end' => (string) ($campaignData['work_end'] ?? '16:00'),
            'per_window_capacity' => max(1, (int) ($campaignData['per_window_capacity'] ?? 50)),
            'num_windows' => max(1, (int) ($campaignData['num_windows'] ?? 4)),
            'opening_quantity' => max(0, (int) ($campaignData['opening_quantity'] ?? count($beneficiaries))),
            'message_extra' => CampaignService::normalizeMessageExtra((string) ($campaignData['message_extra'] ?? '')),
        ];

        try {
            if ($campaignId > 0) {
                CampaignService::update($campaignId, $fields);
            } else {
                $campaignId = CampaignService::create($fields, $adminId);
                $created = true;
            }

            $normalized = [];
            foreach ($beneficiaries as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $nid = ArabicFormat::normalizeNationalId((string) ($row['national_id'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($nid === '' || $name === '') {
                    continue;
                }
                $normalized[] = [
                    'name' => $name,
                    'national_id' => $nid,
                    'mobile' => (string) ($row['mobile'] ?? ''),
                    'shelter_name' => trim((string) ($row['shelter_name'] ?? '')),
                    'receipt_status' => (string) ($row['receipt_status'] ?? DeliveryService::STATUS_PENDING),
                ];
            }

            if ($replace) {
                $count = ExcelImportService::saveBeneficiaries($campaignId, $normalized);
            } else {
                $result = ExcelImportService::appendBeneficiaries($campaignId, $normalized);
                $count = (int) ($result['added'] ?? 0);
            }

            json_response([
                'ok' => true,
                'campaign_id' => $campaignId,
                'beneficiaries' => $count,
                'created' => $created,
            ]);
        } catch (\Throwable $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    if (preg_match('#^/api/integration/campaigns/(\d+)/receipts$#', $uri, $m) && $method === 'GET') {
        $campaignId = (int) $m[1];
        $campaign = CampaignService::find($campaignId);
        if (! $campaign) {
            json_response(['ok' => false, 'error' => 'العملية غير موجودة'], 404);
        }

        $pdo = \App\Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT national_id, name, receipt_status, delivered_at, actual_delivery_date
             FROM beneficiaries WHERE campaign_id = ?'
        );
        $stmt->execute([$campaignId]);

        $receipts = [];
        $deliveredCount = 0;
        $pendingCount = 0;
        $total = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $total++;
            if (DeliveryService::isDeliveredStatus($row['receipt_status'] ?? '')) {
                $deliveredCount++;
                $receipts[] = [
                    'national_id' => ArabicFormat::normalizeNationalId($row['national_id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'status' => 'received',
                    'receipt_status' => DeliveryService::STATUS_DELIVERED,
                    'delivered_at' => $row['delivered_at'] ?: ($row['actual_delivery_date'] ?: null),
                ];
            } else {
                $pendingCount++;
            }
        }

        json_response([
            'ok' => true,
            'campaign_id' => $campaignId,
            'campaign_name' => (string) ($campaign['name'] ?? ''),
            'total' => $total,
            'delivered' => $deliveredCount,
            'pending' => $pendingCount,
            'receipts' => $receipts,
        ]);
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
        $receivedByMode = isset($body['received_by_mode']) ? (string) $body['received_by_mode'] : null;
        $receivedByName = isset($body['received_by_name']) ? (string) $body['received_by_name'] : null;
        $deliveredAt = isset($body['delivered_at']) ? (string) $body['delivered_at'] : null;
        $result = DeliveryService::markDelivered(
            $campaignId,
            $beneficiaryId,
            Auth::id() ?? 0,
            $clientId,
            $receivedByMode,
            $receivedByName,
            true,
            $deliveredAt
        );
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
    $canCloseDelivery = RoleHelper::canCloseDelivery(Auth::role() ?? '');
    $canStartDelivery = RoleHelper::canStartDelivery(Auth::role() ?? '');
    view('warehouse/dashboard', [
        'title' => 'متابعة المخزن',
        'campaign' => $campaign,
        'stock' => DeliveryService::stockStats($id),
        'deliveryGate' => CampaignService::deliveryGateStatus($campaign),
        'deliveredList' => DeliveryService::deliveredBeneficiaries($id, 100),
        'deliveredTotal' => $deliveredTotal,
        'lateList' => DeliveryService::pendingLate($id, 50),
        'keeperStats' => $canCloseDelivery ? DeliveryService::deliveriesByKeeper($id) : null,
        'canEdit' => RoleHelper::canEditCampaign(Auth::role() ?? ''),
        'canCloseDelivery' => $canCloseDelivery,
        'canStartDelivery' => $canStartDelivery,
        'canDeliver' => RoleHelper::canDeliver(Auth::role() ?? ''),
        'canExport' => RoleHelper::canViewStock(Auth::role() ?? ''),
        'canCancelDeliveries' => RoleHelper::canCancelDeliveries(Auth::role() ?? ''),
        'canBulkDeliver' => RoleHelper::canBulkDeliver(Auth::role() ?? ''),
        'smsPending' => SmsService::pendingCount($id),
        'smsEnabled' => SmsService::isEnabled(),
        'reviewCounts' => RoleHelper::canBulkDeliver(Auth::role() ?? '')
            ? CampaignService::reviewCounts($id)
            : null,
    ]);
    exit;
}

if ($uri === '/campaigns/opening-quantity' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/stock?id=' . $id);
    }
    CampaignService::reopenDelivery($id);
    flash('success', 'تم إعادة فتح عملية التسليم.');
    redirect('/campaigns/stock?id=' . $id);
}

if ($uri === '/campaigns/start-delivery' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canStartDelivery($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/stock?id=' . $id);
    }
    try {
        $opensAt = trim((string) ($_POST['opens_at'] ?? ''));
        CampaignService::startDelivery($id, $opensAt !== '' ? $opensAt : null);
        flash(
            'success',
            $opensAt !== '' && strtotime(str_replace('T', ' ', $opensAt)) > time()
                ? 'تم جدولة بدء التسليم.'
                : 'تم بدء التسليم — يمكن لأمناء المخزن التسجيل الآن.'
        );
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/campaigns/stock?id=' . $id);
}

if ($uri === '/campaigns/lock-delivery' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canStartDelivery($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/stock?id=' . $id);
    }
    try {
        CampaignService::lockDelivery($id);
        flash('success', 'تم قفل التسليم مؤقتاً — لن يُقبل تسجيل جديد حتى إعادة البدء.');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/campaigns/stock?id=' . $id);
}

if ($uri === '/campaigns/bulk-delivery' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canBulkDeliver($r));
    $id = (int) ($_GET['id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign || ($campaign['status'] ?? '') !== 'generated') {
        flash('error', 'العملية غير جاهزة.');
        redirect('/');
    }
    $tab = (string) ($_GET['tab'] ?? 'bulk');
    if (!in_array($tab, ['bulk', 'correct', 'batches'], true)) {
        $tab = 'bulk';
    }
    view('campaigns/bulk-delivery', [
        'title' => 'تسليم جماعي وتصحيح',
        'campaign' => $campaign,
        'stock' => DeliveryService::stockStats($id),
        'pendingList' => DeliveryService::pendingBeneficiariesForAdmin($id),
        'deliveredList' => DeliveryService::deliveredBeneficiariesForAdmin($id),
        'batches' => DeliveryService::listDeliveryBatches($id),
        'tab' => $tab,
    ]);
    exit;
}

if ($uri === '/campaigns/bulk-delivery/confirm' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canBulkDeliver($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/bulk-delivery?id=' . $id);
    }

    $mode = (string) ($_POST['deliver_mode'] ?? 'selected');
    $ids = [];
    if ($mode === 'all_except') {
        // الكشف كامل محدَّد — نرسل فقط المستبعدين (من لم يستلموا) لتفادي حد PHP
        $excludeRaw = $_POST['exclude_ids'] ?? [];
        if (!is_array($excludeRaw)) {
            $excludeRaw = [];
        }
        $exclude = array_fill_keys(
            array_map('intval', array_filter($excludeRaw, static fn ($v) => (int) $v > 0)),
            true
        );
        foreach (DeliveryService::pendingBeneficiariesForAdmin($id) as $row) {
            $bid = (int) ($row['id'] ?? 0);
            if ($bid > 0 && !isset($exclude[$bid])) {
                $ids[] = $bid;
            }
        }
    } else {
        $ids = $_POST['beneficiary_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
    }

    $result = DeliveryService::bulkMarkDelivered(
        $id,
        (int) (Auth::id() ?? 0),
        $ids,
        (string) ($_POST['reason'] ?? '')
    );
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'فشل التسليم الجماعي.');
        redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=bulk');
    }
    flash(
        'success',
        'تم تسليم ' . (int) ($result['delivered'] ?? 0) . ' مستفيد في الدفعة #' . (int) ($result['batch_id'] ?? 0)
        . (!empty($result['skipped']) ? ' (تخطي ' . (int) $result['skipped'] . ')' : '')
    );
    redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=batches');
}

if ($uri === '/campaigns/bulk-delivery/undo' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canBulkDeliver($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $batchId = (int) ($_POST['batch_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=batches');
    }
    $result = DeliveryService::undoDeliveryBatch(
        $id,
        $batchId,
        (int) (Auth::id() ?? 0),
        (string) ($_POST['reason'] ?? '')
    );
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'فشل التراجع.');
        redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=batches');
    }
    flash('success', 'تم التراجع عن الدفعة — أُرجع ' . (int) ($result['undone'] ?? 0) . ' مستفيد.');
    redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=batches');
}

if ($uri === '/campaigns/bulk-delivery/correct' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canBulkDeliver($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=correct');
    }
    $toDeliver = $_POST['to_deliver'] ?? [];
    $toUndeliver = $_POST['to_undeliver'] ?? [];
    if (!is_array($toDeliver)) {
        $toDeliver = [];
    }
    if (!is_array($toUndeliver)) {
        $toUndeliver = [];
    }
    $result = DeliveryService::correctDeliveryStatuses(
        $id,
        (int) (Auth::id() ?? 0),
        $toDeliver,
        $toUndeliver,
        (string) ($_POST['reason'] ?? '')
    );
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'فشل التصحيح.');
        redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=correct');
    }
    flash(
        'success',
        'تم التصحيح — مستلم جديد: ' . (int) ($result['delivered'] ?? 0)
        . ' | إرجاع: ' . (int) ($result['undelivered'] ?? 0)
    );
    redirect('/campaigns/bulk-delivery?id=' . $id . '&tab=correct');
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
        flash('error', Csrf::failureMessage());
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
        'canMerge' => RoleHelper::canMergeCampaigns(Auth::role() ?? ''),
    ]);
    exit;
}

if ($uri === '/campaigns/edit' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? $_GET['id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
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

if ($uri === '/campaigns/merge' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canMergeCampaigns($r));
    $fromId = (int) ($_GET['from'] ?? 0);
    $toId = (int) ($_GET['to'] ?? 0);
    $source = CampaignService::find($fromId);
    if (!$source) {
        flash('error', 'العملية المصدر غير موجودة.');
        redirect('/');
    }
    $preview = null;
    $target = null;
    if ($toId > 0) {
        $target = CampaignService::find($toId);
        $preview = CampaignMergeService::preview($fromId, $toId);
        unset($preview['decisions']);
    }
    $others = array_values(array_filter(
        CampaignService::all(),
        static function (array $c) use ($fromId): bool {
            if ((int) $c['id'] === $fromId) {
                return false;
            }
            $name = (string) ($c['name'] ?? '');
            if (str_starts_with($name, 'نسخة احتياط')) {
                return false;
            }
            if (str_contains($name, 'فارغة بعد الدمج')) {
                return false;
            }
            return true;
        }
    ));
    view('campaigns/merge', [
        'title' => 'دمج عملية',
        'source' => $source,
        'target' => $target,
        'toId' => $toId,
        'preview' => $preview,
        'campaigns' => $others,
    ]);
    exit;
}

if ($uri === '/campaigns/merge' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canMergeCampaigns($r));
    $fromId = (int) ($_POST['from'] ?? 0);
    $toId = (int) ($_POST['to'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/merge?from=' . $fromId . ($toId > 0 ? '&to=' . $toId : ''));
    }
    $source = CampaignService::find($fromId);
    if (!$source) {
        flash('error', 'العملية المصدر غير موجودة.');
        redirect('/');
    }
    $confirm = trim((string) ($_POST['confirm_name_input'] ?? ''));
    if ($confirm !== (string) ($source['name'] ?? '')) {
        flash('error', 'اسم التأكيد غير مطابق — لم يُدمج شيء.');
        redirect('/campaigns/merge?from=' . $fromId . '&to=' . $toId);
    }
    $result = CampaignMergeService::merge($fromId, $toId, (int) (Auth::id() ?? 0));
    if (empty($result['ok'])) {
        flash('error', (string) ($result['error'] ?? 'فشل الدمج.'));
        redirect('/campaigns/merge?from=' . $fromId . '&to=' . $toId);
    }
    $moved = (int) ($result['moved'] ?? 0);
    $dups = (int) ($result['duplicates'] ?? 0);
    $backupName = (string) ($result['backup_name'] ?? '');
    $msg = 'تم الدمج: نُقل ' . $moved . ' مستفيد، وعُولج ' . $dups . ' تكرار هوية.';
    if ($backupName !== '') {
        $msg .= ' النسخة الاحتياط: «' . $backupName . '».';
    }
    if (!empty($result['db_backup'])) {
        $msg .= ' ونسخة قاعدة بيانات: ' . $result['db_backup'] . '.';
    }
    $msg .= ' العملية المصدر أصبحت فارغة ويمكن حذفها بعد المراجعة.';
    flash('success', $msg);
    redirect('/campaigns/view?id=' . $toId);
}

if ($uri === '/campaigns/delete' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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

if ($uri === '/admin/database/download' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageDatabase($r));
    try {
        $file = DatabaseBackupService::resolveDownload($_GET['filename'] ?? '');
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . (string) $file['size']);
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($file['path']);
        exit;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/admin/database');
    }
}

if ($uri === '/admin/database/import' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageDatabase($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/admin/database');
    }
    try {
        $result = DatabaseBackupService::importUploaded($_FILES['backup_file'] ?? []);
        flash('success', 'تم استيراد الملف: ' . $result['filename'] . ' — يمكنك الآن الضغط على «استعادة».');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/database');
}

if ($uri === '/admin/database/restore' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageDatabase($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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

if ($uri === '/campaigns/beneficiaries/search' && $method === 'POST') {
    $id = (int) ($_POST['id'] ?? $_POST['campaign_id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/beneficiaries?id=' . $id);
    }
    $q = trim((string) ($_POST['q'] ?? ''));
    $filter = trim((string) ($_POST['filter'] ?? ''));
    $_SESSION['ben_search'][$id] = [
        'q' => $q,
        'filter' => $filter,
        'at' => time(),
    ];
    $back = '/campaigns/beneficiaries?id=' . $id . '&ids=1';
    if ($filter !== '') {
        $back .= '&filter=' . rawurlencode($filter);
    }
    redirect($back);
}

if ($uri === '/campaigns/beneficiaries' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }

    $useSessionIds = isset($_GET['ids']) && (string) $_GET['ids'] === '1';
    $clearSearch = isset($_GET['clear']) && (string) $_GET['clear'] === '1';
    if ($clearSearch) {
        unset($_SESSION['ben_search'][$id]);
        redirect('/campaigns/beneficiaries?id=' . $id);
    }

    $sessionSearch = is_array($_SESSION['ben_search'][$id] ?? null) ? $_SESSION['ben_search'][$id] : null;
    $q = '';
    $filter = trim((string) ($_GET['filter'] ?? ''));
    if ($useSessionIds && $sessionSearch) {
        $q = trim((string) ($sessionSearch['q'] ?? ''));
        if ($filter === '' && isset($sessionSearch['filter'])) {
            $filter = trim((string) $sessionSearch['filter']);
        }
        // حدّث الفلتر المحفوظ إذا تغيّر من الروابط القصيرة
        $_SESSION['ben_search'][$id]['filter'] = $filter;
    } else {
        $q = trim((string) ($_GET['q'] ?? ''));
        // بحث قصير عبر GET: لا تعتمد على جلسة الهويات
        if ($q !== '' && isset($_SESSION['ben_search'][$id])) {
            unset($_SESSION['ben_search'][$id]);
        }
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    // بدون بحث أو فلتر: لا نحمّل آلاف الصفوف — الصفحة للبحث فقط
    $shouldList = $q !== '' || $filter !== '';
    $result = $shouldList
        ? CampaignService::searchAllBeneficiaries($id, $q, $page, 50, $filter)
        : ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'filter' => '', 'id_list_count' => 0];
    $codePrefix = (string) ($campaign['parcel_code'] ?? '');
    $codeSuffix = (string) ($campaign['parcel_code_suffix'] ?? '');
    $rows = array_map(
        static fn (array $b): array => ArabicFormat::localizeBeneficiary($b, $codePrefix, $codeSuffix),
        $result['rows']
    );
    $canManual = RoleHelper::canBulkDeliver(Auth::role() ?? '');
    $canDeleteBeneficiary = RoleHelper::canEditCampaign(Auth::role() ?? '');
    // العدّادات الثقيلة فقط عند طلب فلتر مراجعة
    $review = ($canManual || $canDeleteBeneficiary) && ($filter !== '' || isset($_GET['filters']))
        ? CampaignService::reviewCounts($id)
        : [
            'today' => 0,
            'anomaly' => 0,
            'arabic_id' => 0,
            'delivered_no_mobile' => 0,
            'no_mobile' => 0,
            'unassigned' => 0,
            'duplicates' => 0,
        ];
    $idListCount = (int) ($result['id_list_count'] ?? 0);
    $useIdsFlag = $useSessionIds || $idListCount >= 2;
    view('campaigns/beneficiaries', [
        'campaign' => $campaign,
        'rows' => $rows,
        'total' => $result['total'],
        'page' => $result['page'],
        'perPage' => $result['per_page'],
        'q' => $q,
        'filter' => $result['filter'] ?? $filter,
        'canManualDeliver' => $canManual,
        'canDeleteBeneficiary' => $canDeleteBeneficiary,
        'reviewCounts' => $review,
        'searched' => $shouldList,
        'idListCount' => $idListCount,
        'useIdsFlag' => $useIdsFlag,
        'canExport' => RoleHelper::canExport(Auth::role() ?? ''),
    ]);
    exit;
}

if ($uri === '/campaigns/beneficiaries/update' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $beneficiaryId = (int) ($_POST['beneficiary_id'] ?? 0);
    $q = trim((string) ($_POST['q'] ?? ''));
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $filter = trim((string) ($_POST['filter'] ?? ''));
    $back = '/campaigns/beneficiaries?id=' . $id;
    $useIds = !empty($_POST['use_ids']) || strlen($q) > 180 || substr_count($q, "\n") >= 1;
    if ($useIds && $q !== '') {
        $_SESSION['ben_search'][$id] = [
            'q' => $q,
            'filter' => $filter,
            'at' => time(),
        ];
        $back .= '&ids=1';
    } elseif ($q !== '') {
        $back .= '&q=' . rawurlencode($q);
    }
    if ($filter !== '') {
        $back .= '&filter=' . rawurlencode($filter);
    }
    if ($page > 1) {
        $back .= '&page=' . $page;
    }
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect($back);
    }
    $result = CampaignService::updateBeneficiary($id, $beneficiaryId, [
        'name' => (string) ($_POST['name'] ?? ''),
        'national_id' => (string) ($_POST['national_id'] ?? ''),
        'mobile' => (string) ($_POST['mobile'] ?? ''),
    ]);
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'تعذّر التعديل.');
        redirect($back);
    }
    flash('success', 'تم تحديث بيانات المرشح.');
    redirect($back);
}

if ($uri === '/campaigns/beneficiaries/delete' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $beneficiaryId = (int) ($_POST['beneficiary_id'] ?? 0);
    $q = trim((string) ($_POST['q'] ?? ''));
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $filter = trim((string) ($_POST['filter'] ?? ''));
    $back = '/campaigns/beneficiaries?id=' . $id;
    $useIds = !empty($_POST['use_ids']) || strlen($q) > 180 || substr_count($q, "\n") >= 1;
    if ($useIds && $q !== '') {
        $_SESSION['ben_search'][$id] = [
            'q' => $q,
            'filter' => $filter,
            'at' => time(),
        ];
        $back .= '&ids=1';
    } elseif ($q !== '') {
        $back .= '&q=' . rawurlencode($q);
    }
    if ($filter !== '') {
        $back .= '&filter=' . rawurlencode($filter);
    }
    if ($page > 1) {
        $back .= '&page=' . $page;
    }
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect($back);
    }
    $result = CampaignService::deleteBeneficiary($id, $beneficiaryId);
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'تعذّر الحذف.');
        redirect($back);
    }
    flash('success', 'تم حذف المستفيد' . (!empty($result['name']) ? ': ' . $result['name'] : '') . '.');
    redirect($back);
}

if ($uri === '/campaigns/beneficiaries/delete-many' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $q = trim((string) ($_POST['q'] ?? ''));
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $filter = trim((string) ($_POST['filter'] ?? ''));
    $useIds = !empty($_POST['use_ids']) || strlen($q) > 180 || substr_count($q, "\n") >= 1;
    if ($useIds && $q !== '') {
        $_SESSION['ben_search'][$id] = [
            'q' => $q,
            'filter' => $filter,
            'at' => time(),
        ];
    }
    $back = '/campaigns/beneficiaries?id=' . $id;
    if ($useIds && $q !== '') {
        $back .= '&ids=1';
    } elseif ($q !== '') {
        $back .= '&q=' . rawurlencode($q);
    }
    if ($filter !== '') {
        $back .= '&filter=' . rawurlencode($filter);
    }
    if ($page > 1) {
        $back .= '&page=' . $page;
    }
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect($back);
    }
    $ids = $_POST['beneficiary_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $result = CampaignService::deleteBeneficiariesMany($id, $ids);
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'تعذّر الحذف الجماعي.');
        redirect($back);
    }
    $msg = 'تم حذف ' . (int) ($result['deleted'] ?? 0) . ' مستفيد.';
    if ((int) ($result['skipped'] ?? 0) > 0) {
        $msg .= ' تُخطّي ' . (int) $result['skipped'] . ' (معيّن أو مستلم).';
    }
    flash('success', $msg);
    redirect($back);
}

if ($uri === '/campaigns/beneficiaries/delete-unassigned-today' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $back = '/campaigns/beneficiaries?id=' . $id . '&filter=unassigned_today';
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect($back);
    }
    $result = CampaignService::deleteUnassignedAddedOnDate($id);
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'تعذّر الحذف.');
        redirect($back);
    }
    $msg = 'تم حذف ' . (int) ($result['deleted'] ?? 0) . ' غير معيّن من آخر دفعة مضافة.';
    if ((int) ($result['skipped'] ?? 0) > 0) {
        $msg .= ' تُخطّي ' . (int) $result['skipped'] . ' (معيّن أو مستلم).';
    }
    flash('success', $msg);
    redirect($back);
}

if ($uri === '/campaigns/beneficiaries/delete-by-excel' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $back = '/campaigns/beneficiaries?id=' . $id . '&filter=unassigned';
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect($back);
    }
    try {
        $ext = strtolower(pathinfo($_FILES['excel_file']['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            throw new RuntimeException('صيغة الملف يجب أن تكون Excel (xlsx/xls).');
        }
        $tmp = dirname(__DIR__) . '/storage/uploads/' . uniqid('del_', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES['excel_file']['tmp_name'] ?? '', $tmp)) {
            throw new RuntimeException('تعذّر رفع الملف.');
        }
        $items = ExcelImportService::parse($tmp);
        $result = ExcelImportService::deleteUnassignedMatchingFile($id, $items);
        @unlink($tmp);
        if (!$result['ok']) {
            flash('error', $result['error'] ?? 'تعذّر الحذف من الملف.');
            redirect($back);
        }
        $msg = 'تم حذف ' . (int) ($result['deleted'] ?? 0) . ' غير معيّن مطابق لملف الإكسل.';
        if ((int) ($result['skipped'] ?? 0) > 0) {
            $msg .= ' تُخطّي ' . (int) $result['skipped'] . ' (معيّن أو مستلم).';
        }
        flash('success', $msg);
        redirect($back);
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($back);
    }
}

if ($uri === '/campaigns/beneficiaries/mark-delivered' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canBulkDeliver($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/beneficiaries?id=' . $id);
    }
    $ids = $_POST['beneficiary_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $single = (int) ($_POST['beneficiary_id'] ?? 0);
    if ($single > 0) {
        $ids[] = $single;
    }
    $q = trim((string) ($_POST['q'] ?? ''));
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $filter = trim((string) ($_POST['filter'] ?? ''));
    $useIds = !empty($_POST['use_ids']) || strlen($q) > 180 || substr_count($q, "\n") >= 1;
    if ($useIds && $q !== '') {
        $_SESSION['ben_search'][$id] = [
            'q' => $q,
            'filter' => $filter,
            'at' => time(),
        ];
    }
    $result = DeliveryService::adminMarkDeliveredMany(
        $id,
        (int) (Auth::id() ?? 0),
        $ids,
        (string) ($_POST['reason'] ?? '')
    );
    $back = '/campaigns/beneficiaries?id=' . $id;
    if ($useIds && $q !== '') {
        $back .= '&ids=1';
    } elseif ($q !== '') {
        $back .= '&q=' . rawurlencode($q);
    }
    if ($filter !== '') {
        $back .= '&filter=' . rawurlencode($filter);
    }
    if ($page > 1) {
        $back .= '&page=' . $page;
    }
    if (!$result['ok']) {
        flash('error', $result['error'] ?? 'فشل الاستلام اليدوي.');
        redirect($back);
    }
    $msg = 'تم تسجيل استلام يدوي لـ ' . (int) ($result['delivered'] ?? 0) . ' مستفيد.';
    if (!empty($result['failed'])) {
        $msg .= ' (فشل ' . (int) $result['failed'] . ')';
    }
    flash('success', $msg);
    redirect($back);
}

if ($uri === '/campaigns/view' && $method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    $stats = CampaignService::stats($id);
    $panel = strtolower(trim((string) ($_GET['panel'] ?? '')));
    if ($panel === 'downloads') {
        $panel = 'days';
    }
    if (!in_array($panel, ['', 'search', 'days', 'candidates'], true)) {
        $panel = '';
    }
    if ($panel === '') {
        $panel = 'search';
    }
    $plan = null;
    $suggestedDayDate = DistributionService::suggestNextDayDate($campaign);
    // خطة المعاينة فقط عند باب الأيام ووجود غير معيّنين بلا أيام بعد
    if (
        $panel === 'days'
        && (int) ($stats['unassigned'] ?? 0) > 0
        && (int) ($stats['assigned'] ?? 0) === 0
    ) {
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
    view('campaigns/view', [
        'title' => $campaign['name'],
        'campaign' => ArabicFormat::localizeCampaignTimes($campaign),
        'stats' => $stats,
        'plan' => $plan,
        'panel' => $panel,
        'suggestedDayDate' => $suggestedDayDate,
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
        flash('error', Csrf::failureMessage());
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

/** إضافة مجموعة مرشحين جدد كغير معيّنين فقط — بدون مسح الكشف الحالي وبدون تكرار هوية. */
if ($uri === '/campaigns/append-import' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    $back = '/campaigns/view?id=' . $id . '&panel=candidates';
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect($back);
    }
    try {
        $ext = strtolower(pathinfo($_FILES['excel_file']['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            throw new RuntimeException('صيغة الملف يجب أن تكون Excel (xlsx/xls).');
        }
        $tmp = dirname(__DIR__) . '/storage/uploads/' . uniqid('append_', true) . '.' . $ext;
        if (!move_uploaded_file($_FILES['excel_file']['tmp_name'] ?? '', $tmp)) {
            throw new RuntimeException('تعذّر رفع الملف.');
        }
        $items = ExcelImportService::parse($tmp);
        $result = ExcelImportService::appendBeneficiaries($id, $items);
        @unlink($tmp);
        $msg = "أُضيف {$result['added']} مرشحاً جديداً كغير معيّنين (لأيام التسليم القادمة).";
        if ((int) $result['skipped_duplicates'] > 0) {
            $msg .= " تُجاهل {$result['skipped_duplicates']} صفاً لتكرار رقم الهوية أو الاسم بالكامل.";
        }
        flash('success', $msg);
        redirect('/campaigns/beneficiaries?id=' . $id . '&filter=unassigned_today');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect($back);
    }
}

if ($uri === '/campaigns/generate' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
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

if ($uri === '/campaigns/generate-day' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/view?id=' . $id);
    }
    try {
        $count = (int) ($_POST['day_beneficiaries'] ?? 0);
        $windows = (int) ($_POST['day_windows'] ?? 0);
        $date = trim((string) ($_POST['day_date'] ?? ''));
        $workStart = trim((string) ($_POST['day_work_start'] ?? ''));
        $workEnd = trim((string) ($_POST['day_work_end'] ?? ''));
        $selectionMode = DistributionService::normalizeSelectionMode(
            (string) ($_POST['day_selection_mode'] ?? 'registration')
        );
        $summary = DistributionService::generateDay(
            $id,
            $count,
            $windows,
            $date !== '' ? $date : null,
            $workStart !== '' ? $workStart : null,
            $workEnd !== '' ? $workEnd : null,
            $selectionMode
        );
        $perWin = implode('، ', array_map('strval', $summary['per_window'] ?? []));
        $selectionLabel = ($summary['selection_mode'] ?? '') === 'random'
            ? 'اختيار عشوائي'
            : 'حسب ترتيب التسجيل';
        flash(
            'success',
            "تم اعتماد اليوم {$summary['day_index']} ({$summary['date']}): {$summary['beneficiaries']} مستفيد على {$summary['windows']} شباك ({$perWin})"
            . " — دوام {$summary['work_start']}–{$summary['work_end']}."
            . " ({$selectionLabel})"
            . " المتبقي غير معيّن: {$summary['unassigned_remaining']}."
        );
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/campaigns/view?id=' . $id . '&panel=days');
}

if ($uri === '/campaigns/cancel-last-day' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canEditCampaign($r));
    $id = (int) ($_POST['campaign_id'] ?? 0);
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/campaigns/view?id=' . $id);
    }
    try {
        $summary = DistributionService::cancelLastDay($id);
        flash(
            'success',
            "تم إلغاء اليوم {$summary['day_index']}"
            . ($summary['date'] !== '' ? " ({$summary['date']})" : '')
            . ": عاد {$summary['beneficiaries']} مستفيد لغير المعيّنين."
            . " المتبقي غير معيّن: {$summary['unassigned_remaining']}."
            . " الأيام المتبقية: {$summary['remaining_days']}."
        );
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/campaigns/view?id=' . $id . '&panel=days');
}

if ($uri === '/campaigns/export' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $path = ExcelExportService::export($id);
        $campaign = CampaignService::find($id);
        $filename = ($campaign['name'] ?? 'export') . '_كشوف_التسليم.xlsx';
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

if ($uri === '/campaigns/export-candidates' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $path = ExcelExportService::exportCandidates($id);
        $campaign = CampaignService::find($id);
        $filename = ($campaign['name'] ?? 'candidates') . '_كشف_المرشحين_بالكامل.xlsx';
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

if ($uri === '/campaigns/export-unassigned' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    try {
        $path = ExcelExportService::exportUnassigned($id);
        $campaign = CampaignService::find($id);
        $filename = ($campaign['name'] ?? 'unassigned') . '_غير_المعينين.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/campaigns/view?id=' . $id . '&panel=days');
    }
}

if ($uri === '/campaigns/print-unassigned' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    $campaign = CampaignService::find($id);
    if (!$campaign) {
        flash('error', 'العملية غير موجودة.');
        redirect('/');
    }
    $rows = CampaignService::unassignedPendingDetailed($id);
    if ($rows === []) {
        flash('error', 'لا يوجد غير معيّنين في هذه العملية.');
        redirect('/campaigns/view?id=' . $id . '&panel=days');
    }
    print_view('campaigns/print-unassigned', [
        'title' => 'كشف غير المعيّنين — ' . (string) $campaign['name'],
        'campaign' => $campaign,
        'rows' => $rows,
    ]);
    exit;
}

if ($uri === '/campaigns/export-messages' && $method === 'GET') {
    Auth::requireRole(fn ($r) => RoleHelper::canExport($r));
    $id = (int) ($_GET['id'] ?? 0);
    $day = (int) ($_GET['day'] ?? 0);
    $network = strtolower(trim((string) ($_GET['network'] ?? '')));
    if (!in_array($network, ['jawwal', 'ooredoo', 'other'], true)) {
        $network = '';
    }
    try {
        $path = ExcelExportService::exportMessagesForDay(
            $id,
            $day,
            $network !== '' ? $network : null
        );
        $campaign = CampaignService::find($id);
        $isZip = str_ends_with(strtolower($path), '.zip');
        $label = match ($network) {
            'jawwal' => 'جوال',
            'ooredoo' => 'أوريدو',
            'other' => 'غير_مصنفة',
            default => 'شبكات',
        };
        $filename = ($campaign['name'] ?? 'messages') . '_رسائل_' . $label . '_يوم' . $day . ($isZip ? '.zip' : '.xlsx');
        header('Content-Type: ' . ($isZip
            ? 'application/zip'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . (string) filesize($path));
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
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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
        flash('error', Csrf::failureMessage());
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

if ($uri === '/users/delete' && $method === 'POST') {
    Auth::requireRole(fn ($r) => RoleHelper::canManageUsers($r));
    if (!Csrf::verify($_POST['_csrf'] ?? null)) {
        flash('error', Csrf::failureMessage());
        redirect('/users');
    }
    $id = (int) ($_POST['user_id'] ?? 0);
    if ($id === (int) (Auth::id() ?? 0)) {
        flash('error', 'لا يمكنك حذف حسابك.');
        redirect('/users');
    }
    $user = UserService::find($id);
    if (!$user) {
        flash('error', 'المستخدم غير موجود.');
        redirect('/users');
    }
    if ($user['role'] === 'admin' && UserService::adminCount() <= 1) {
        flash('error', 'لا يمكن حذف آخر مدير نشط في النظام.');
        redirect('/users');
    }
    UserService::delete($id);
    flash('success', 'تم حذف المستخدم.');
    redirect('/users');
}

http_response_code(404);
view('errors/notfound', ['title' => 'غير موجود']);
