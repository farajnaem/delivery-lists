<?php

declare(strict_types=1);

$base = $argv[1] ?? 'https://delivery.rec-soc.org';
$email = $argv[2] ?? '';
$password = $argv[3] ?? '';

function req(string $method, string $url, ?array $json = null, array $headers = []): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    if ($json !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body === false ? '' : $body];
}

echo "BASE=$base\n";

$health = req('GET', "$base/api/mobile/health");
echo "health={$health['code']} {$health['body']}\n";

if ($email === '' || $password === '') {
    echo "Usage: php test-live-mobile-auth.php [base_url] email password\n";
    exit(0);
}

$login = req('POST', "$base/api/mobile/login", ['email' => $email, 'password' => $password]);
echo "login={$login['code']} {$login['body']}\n";
$data = json_decode($login['body'], true);
if (!is_array($data) || empty($data['token'])) {
    exit(1);
}
$token = $data['token'];

$campaignsAuth = req('GET', "$base/api/mobile/campaigns", null, [
    "Authorization: Bearer $token",
]);
echo "campaigns_auth={$campaignsAuth['code']} {$campaignsAuth['body']}\n";

$campaignsX = req('GET', "$base/api/mobile/campaigns", null, [
    "X-Mobile-Token: $token",
]);
echo "campaigns_x_token={$campaignsX['code']} {$campaignsX['body']}\n";

$campaignsQuery = req('GET', "$base/api/mobile/campaigns?mobile_token=" . urlencode($token));
echo "campaigns_query={$campaignsQuery['code']} {$campaignsQuery['body']}\n";
