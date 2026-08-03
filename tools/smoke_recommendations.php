<?php
// HTTP smoke test for the /recommendations routes (Phase 6.2),
// the dashboard and its write actions (Phase 6.4), and the Phase 6.5
// production-readiness surface (freshness, admin monitoring page,
// cache flush tool, rate limiting).
// Usage: php tools/smoke_recommendations.php
// Requires the dev server: php -S 127.0.0.1:8123 -t public

const BASE = 'http://127.0.0.1:8123';

$session = curl_init();
$cookieJar = sys_get_temp_dir() . '/bs_smoke_cookies.txt';
@unlink($cookieJar);

function request(string $path, ?array $post = null, array $headers = []): array {
    global $cookieJar;
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, (string) $body];
}

[$status, $body] = request('/login');
if ($status !== 200) {
    fwrite(STDERR, "GET /login -> $status (expected 200)\n");
    exit(1);
}
preg_match('/name="_token" value="([^"]+)"/', $body, $m);
[$loginStatus, $loginBody] = request('/login', [
    'email'    => 'admin@booksphere.test',
    'password' => 'Admin@123',
    '_token'   => $m[1] ?? '',
]);
if ($loginStatus !== 302) {
    fwrite(STDERR, "POST /login -> $loginStatus (expected 302)\n");
    $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($loginBody)));
    fwrite(STDERR, 'Body: ' . substr($snippet, 0, 400) . "\n");
    exit(1);
}

$routes = [
    '/'                           => 200,
    '/recommendations'            => 200,
    '/recommendations/popular'    => 200,
    '/recommendations/top-rated'  => 200,
    '/recommendations/trending'   => 200,
    '/recommendations/recent'     => 200,
    '/recommendations/category/1' => 200,
    '/recommendations/book/15'    => 200,
    '/recommendations/personalized' => 404,
    '/recommendations/book/999'   => 404,
];

$failures = 0;
foreach ($routes as $path => $expected) {
    [$status, $body] = request($path);
    $mark = $status === $expected ? 'ok  ' : 'FAIL';
    if ($status !== $expected) {
        $failures++;
    }
    printf("%s %-32s -> %d (expected %d)\n", $mark, $path, $status, $expected);
}

$guestJar = sys_get_temp_dir() . '/bs_smoke_guest.txt';
@unlink($guestJar);
$ch = curl_init(BASE . '/recommendations');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR      => $guestJar,
    CURLOPT_COOKIEFILE     => $guestJar,
]);
curl_exec($ch);
$guestStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
printf("%s %-32s -> %d (expected 302)\n", $guestStatus === 302 ? 'ok  ' : 'FAIL', '/recommendations (guest)', $guestStatus);
if ($guestStatus !== 302) {
    $failures++;
}

// Phase 6.4: the dashboard page carries the dashboard shell, and
// the two write actions behave like the live app.
[$dashStatus, $dashBody] = request('/recommendations');
$hasDashboard = str_contains($dashBody, 'data-dashboard')
    && str_contains($dashBody, 'Your reading, decoded.')
    && str_contains($dashBody, 'data-refresh-form');
printf("%s %-32s -> %s\n", $dashStatus === 200 && $hasDashboard ? 'ok  ' : 'FAIL', 'dashboard sections render', $dashStatus === 200 && $hasDashboard ? 'yes' : 'no');
if (!($dashStatus === 200 && $hasDashboard)) {
    $failures++;
}

preg_match('/name="_token" value="([^"]+)"/', $dashBody, $dashToken);
if (empty($dashToken[1])) {
    fwrite(STDERR, "No CSRF token found on the dashboard\n");
    exit(1);
}

[$refreshStatus] = request('/recommendations/refresh', ['_token' => $dashToken[1]]);
printf("%s %-32s -> %d (expected 302)\n", $refreshStatus === 302 ? 'ok  ' : 'FAIL', 'POST /recommendations/refresh', $refreshStatus);
if ($refreshStatus !== 302) {
    $failures++;
}

// The wishlist quick action answers JSON on the fetch path.
[$wishStatus, $wishBody] = request('/wishlist/toggle', [
    '_token'  => $dashToken[1],
    'book_id' => '15',
], ['X-Requested-With: fetch']);
$wishJson = json_decode($wishBody, true);
$wishOk = $wishStatus === 200 && is_array($wishJson) && isset($wishJson['saved']);
printf("%s %-32s -> %d (expected 200 + JSON)\n", $wishOk ? 'ok  ' : 'FAIL', 'POST /wishlist/toggle (fetch)', $wishStatus);
if (!$wishOk) {
    $failures++;
}

[$wishStatus2] = request('/wishlist/toggle', [
    '_token'  => $dashToken[1],
    'book_id' => '15',
], ['X-Requested-With: fetch']);
printf("%s %-32s -> %s\n", $wishStatus2 === 200 ? 'ok  ' : 'FAIL', 'POST /wishlist/toggle (untoggle)', $wishStatus2 === 200 ? '200' : $wishStatus2);
if ($wishStatus2 !== 200) {
    $failures++;
}

// --- Phase 6.5: freshness, admin monitoring, cache flush, throttle --

// The dashboard shows the "Updated X ago" freshness phrase.
[$freshStatus, $freshBody] = request('/recommendations');
$hasFreshness = $freshStatus === 200
    && (bool) preg_match('/Updated (just now|\d+ (minute|hour|day)s? ago)/', $freshBody);
printf("%s %-32s -> %s\n", $hasFreshness ? 'ok  ' : 'FAIL', 'dashboard freshness phrase', $hasFreshness ? 'yes' : 'no');
if (!$hasFreshness) {
    $failures++;
}

// The admin-only monitoring page renders for the admin.
[$adminStatus, $adminBody] = request('/admin/recommendations');
$hasMetrics = str_contains($adminBody, 'Recommendation Engine')
    && str_contains($adminBody, 'Cached Shelves')
    && str_contains($adminBody, 'Top categories by signal')
    && str_contains($adminBody, 'Avg Popularity');
printf("%s %-32s -> %d (expected 200)\n", $adminStatus === 200 && $hasMetrics ? 'ok  ' : 'FAIL', 'GET /admin/recommendations (admin)', $adminStatus);
if (!($adminStatus === 200 && $hasMetrics)) {
    $failures++;
}

// A regular user is forbidden (403) from the monitoring page.
$userJar = sys_get_temp_dir() . '/bs_smoke_user.txt';
@unlink($userJar);
$ch = curl_init(BASE . '/login');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $userJar, CURLOPT_COOKIEFILE => $userJar]);
$loginBody = curl_exec($ch);
curl_close($ch);
preg_match('/name="_token" value="([^"]+)"/', (string) $loginBody, $userToken);
$ch = curl_init(BASE . '/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR      => $userJar,
    CURLOPT_COOKIEFILE     => $userJar,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['email' => 'riya@booksphere.test', 'password' => 'User@123', '_token' => $userToken[1] ?? '']),
]);
curl_exec($ch);
curl_close($ch);
$ch = curl_init(BASE . '/admin/recommendations');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $userJar, CURLOPT_COOKIEFILE => $userJar]);
curl_exec($ch);
$userAdminStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
printf("%s %-32s -> %d (expected 403)\n", $userAdminStatus === 403 ? 'ok  ' : 'FAIL', 'GET /admin/recommendations (regular user)', $userAdminStatus);
if ($userAdminStatus !== 403) {
    $failures++;
}

// A guest is redirected to the login page.
$guestJar2 = sys_get_temp_dir() . '/bs_smoke_guest2.txt';
@unlink($guestJar2);
$ch = curl_init(BASE . '/admin/recommendations');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $guestJar2, CURLOPT_COOKIEFILE => $guestJar2]);
curl_exec($ch);
$guestAdminStatus = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
printf("%s %-32s -> %d (expected 302)\n", $guestAdminStatus === 302 ? 'ok  ' : 'FAIL', 'GET /admin/recommendations (guest)', $guestAdminStatus);
if ($guestAdminStatus !== 302) {
    $failures++;
}

// The cache flush tool works (CSRF-protected POST, then redirect).
preg_match('/name="_token" value="([^"]+)"/', $adminBody, $flushToken);
[$flushStatus] = request('/admin/recommendations/cache/flush', ['_token' => $flushToken[1] ?? '']);
printf("%s %-32s -> %d (expected 302)\n", $flushStatus === 302 ? 'ok  ' : 'FAIL', 'POST /admin/recommendations/cache/flush', $flushStatus);
if ($flushStatus !== 302) {
    $failures++;
}

// The rate limiter: the refresh bucket allows 30 hits per minute;
// the 31st is refused with HTTP 429 (the smoke already used one
// refresh above, so 29 more pass and the 31st total is refused).
$refused = false;
for ($i = 0; $i < 30; $i++) {
    [$spamStatus] = request('/recommendations/refresh', ['_token' => $dashToken[1]]);

    if ($spamStatus === 429) {
        $refused = true;
        break;
    }

    if ($spamStatus !== 302) {
        printf("  unexpected status %d during throttle loop\n", $spamStatus);
        $failures++;
        break;
    }
}
printf("%s %-32s -> %s\n", $refused ? 'ok  ' : 'FAIL', 'POST /recommendations/refresh throttle (429)', $refused ? '429 after the limit' : 'never refused');
if (!$refused) {
    $failures++;
}

exit($failures === 0 ? 0 : 1);
