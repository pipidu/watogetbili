<?php
declare(strict_types=1);

$base = getenv('BILI_SYNC_BASE') ?: 'http://127.0.0.1:8765/sync.php';
$failed = 0;
$cookies = [];

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            remove_tree($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

remove_tree(dirname(__DIR__) . '/bili-sync-private');

function expect(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        echo "ok {$message}\n";
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$message}\n");
}

function http(string $method, string $url, ?array $form = null, ?array $json = null): array
{
    global $cookies;
    $header = "Accept: text/html,application/json\r\n";
    if ($cookies) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $header .= 'Cookie: ' . implode('; ', $pairs) . "\r\n";
    }
    $content = '';
    if ($json !== null) {
        $content = json_encode($json, JSON_UNESCAPED_UNICODE);
        $header .= "Content-Type: application/json\r\n";
    } elseif ($form !== null) {
        $content = http_build_query($form);
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
    }
    if ($content !== '') {
        $header .= 'Content-Length: ' . strlen($content) . "\r\n";
    }
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $header,
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'timeout' => 15,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $raw = $http_response_header ?? [];
    $status = 0;
    if (isset($raw[0]) && preg_match('/\s(\d{3})\s/', $raw[0], $match)) {
        $status = (int) $match[1];
    }
    $location = '';
    foreach ($raw as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $pair = explode(';', trim(substr($line, 11)), 2)[0];
            [$name, $value] = explode('=', $pair, 2);
            $cookies[$name] = $value;
        }
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    return [
        'status' => $status,
        'body' => $body === false ? '' : $body,
        'location' => $location,
    ];
}

function resolve_url(string $base, string $location): string
{
    if (preg_match('#^https?://#', $location)) {
        return $location;
    }
    $parts = parse_url($base);
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($location, '/')) {
        return $origin . $location;
    }
    return $origin . '/' . $location;
}

function follow(array $response): array
{
    global $base;
    if ($response['status'] >= 300 && $response['status'] < 400 && $response['location'] !== '') {
        return http('GET', resolve_url($base, $response['location']));
    }
    return $response;
}

function csrf_from(string $html): string
{
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match)) {
        throw new RuntimeException('csrf missing');
    }
    return $match[1];
}

function api(string $action, array $payload): array
{
    global $base;
    $response = http('POST', $base . '?action=' . rawurlencode($action), null, $payload);
    $data = json_decode($response['body'], true);
    return ['status' => $response['status'], 'json' => is_array($data) ? $data : [], 'raw' => $response['body']];
}

function video(string $bvid, int $page = 1): array
{
    return [
        'url' => "https://www.bilibili.com/video/{$bvid}?p={$page}&spm_id_from=test",
        'title' => '测试视频',
    ];
}

$server = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'bili', 'bili_pass_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$server->exec('DROP DATABASE IF EXISTS bili_sync_test');

$health = http('GET', $base . '?action=health');
$healthJson = json_decode($health['body'], true);
expect($health['status'] === 200 && ($healthJson['configured'] ?? true) === false, 'health before setup');

$unconfigured = api('create', ['name' => '甲']);
expect($unconfigured['status'] === 503, 'create blocked before setup');

$page = http('GET', $base);
expect($page['status'] === 200 && str_contains($page['body'], '初始化'), 'install page');
$csrf = csrf_from($page['body']);
$installed = follow(http('POST', $base, [
    'csrf' => $csrf,
    'form' => 'install',
    'back' => 'overview',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'bili_sync_test',
    'db_user' => 'bili',
    'db_pass' => 'bili_pass_test',
    'db_prefix' => 'bs_',
    'admin_user' => 'admin',
    'admin_pass' => 'test-pass-123',
    'admin_pass2' => 'test-pass-123',
]));
expect($installed['status'] === 200 && str_contains($installed['body'], '接口地址'), 'install lands on overview');
expect(str_contains($installed['body'], '已连接'), 'overview shows database connected');
expect(is_file(__DIR__ . '/../bili-sync-private/.htaccess'), 'private dir is denied by htaccess');

$ready = json_decode(http('GET', $base . '?action=health')['body'], true);
expect(($ready['configured'] ?? false) === true && ($ready['db'] ?? false) === true, 'health after setup');

$badCsrf = follow(http('POST', $base . '?page=options', [
    'csrf' => 'nope',
    'form' => 'save-options',
    'back' => 'options',
    'max_members' => '2',
]));
expect(str_contains($badCsrf['body'], '页面已过期'), 'reject bad csrf');

$created = api('create', [
    'name' => '房主',
    'video' => video('BV1xx411c7mD', 2),
    'playback' => ['time' => 12.5, 'playing' => true, 'rate' => 1.25],
]);
expect($created['status'] === 200 && ($created['json']['ok'] ?? false) === true, 'create room');
$roomId = $created['json']['room']['id'] ?? '';
$hostId = $created['json']['you']['id'] ?? '';
$hostToken = $created['json']['token'] ?? '';
expect(preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $roomId) === 1, 'room code alphabet');
expect(($created['json']['room']['video']['key'] ?? '') === 'BV1xx411c7mD#p2', 'server canonicalizes video');
expect(($created['json']['you']['isHost'] ?? false) === true, 'creator is host');
expect(!str_contains($created['raw'], 'token_hash'), 'token hash is not exposed');

$guest = api('join', ['roomId' => strtolower($roomId), 'name' => '成员乙']);
expect($guest['status'] === 200, 'join room');
$guestId = $guest['json']['you']['id'] ?? '';
$guestToken = $guest['json']['token'] ?? '';
expect(($guest['json']['you']['canControl'] ?? true) === false, 'guest cannot control by default');
expect(($guest['json']['room']['playback']['driverId'] ?? '') === $hostId, 'host is the driver');

$denied = api('sync', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'mode' => 'user',
    'playback' => ['time' => 30, 'playing' => false, 'rate' => 1],
]);
expect($denied['status'] === 403, 'guest playback rejected');

$deniedVideo = api('sync', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'video' => video('BV1aaaaaaaaa', 1),
]);
expect($deniedVideo['status'] === 403, 'guest video change rejected');

$evil = api('sync', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'video' => ['url' => 'https://evil.example/video/BV1xx411c7mD', 'title' => 'x'],
]);
expect($evil['status'] === 400, 'reject non-bilibili url');

$hostBeat = api('sync', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'mode' => 'heartbeat',
    'playback' => ['time' => 40, 'playing' => true, 'rate' => 1.5],
]);
expect(
    $hostBeat['status'] === 200
    && abs(($hostBeat['json']['room']['playback']['time'] ?? 0) - 40) < 0.01
    && ($hostBeat['json']['room']['playback']['rate'] ?? 0) === 1.5,
    'host heartbeat updates progress'
);

$guestBeat = api('sync', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'mode' => 'heartbeat',
    'playback' => ['time' => 1, 'playing' => false, 'rate' => 1],
]);
expect(
    $guestBeat['status'] === 200 && ($guestBeat['json']['room']['playback']['driverId'] ?? '') === $hostId,
    'guest heartbeat does not take control'
);

$permit = api('permit', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'targetId' => $guestId,
    'canControl' => true,
    'canChangeVideo' => false,
]);
expect($permit['status'] === 200, 'host grants progress control');
$guestView = null;
foreach ($permit['json']['room']['members'] ?? [] as $member) {
    if ($member['id'] === $guestId) {
        $guestView = $member;
    }
}
expect(($guestView['canControl'] ?? false) === true && ($guestView['grantControl'] ?? false) === true, 'grant is visible');

$guestSeek = api('sync', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'mode' => 'user',
    'playback' => ['time' => 88, 'playing' => false, 'rate' => 1],
]);
expect(
    ($guestSeek['json']['room']['playback']['driverId'] ?? '') === $guestId
    && abs(($guestSeek['json']['room']['playback']['time'] ?? 0) - 88) < 0.01
    && ($guestSeek['json']['room']['playback']['playing'] ?? true) === false,
    'permitted guest becomes the driver'
);

$hostTakeback = api('sync', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'mode' => 'user',
    'playback' => ['time' => 10, 'playing' => true, 'rate' => 1],
]);
expect(($hostTakeback['json']['room']['playback']['driverId'] ?? '') === $hostId, 'host gesture takes control back');

$guestSeekAgain = api('sync', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'mode' => 'user',
    'playback' => ['time' => 15, 'playing' => true, 'rate' => 1],
]);
expect(($guestSeekAgain['json']['room']['playback']['driverId'] ?? '') === $guestId, 'guest can drive again');

$revoke = api('permit', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'targetId' => $guestId,
    'canControl' => false,
    'canChangeVideo' => true,
]);
expect(
    ($revoke['json']['room']['playback']['driverId'] ?? '') === $hostId,
    'revoking progress returns control to host'
);

$guestVideo = api('sync', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'video' => ['url' => 'https://www.bilibili.com/bangumi/play/ep123456', 'title' => '番剧'],
    'playback' => ['time' => 3, 'playing' => true, 'rate' => 1],
]);
expect(
    $guestVideo['status'] === 200
    && ($guestVideo['json']['room']['video']['key'] ?? '') === 'ep123456'
    && ($guestVideo['json']['room']['playback']['driverId'] ?? '') === $hostId
    && ($guestVideo['json']['room']['playback']['time'] ?? -1) === 0,
    'video permission changes the video without granting progress control'
);

$allow = api('permit', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'allowAllControl' => true,
]);
expect($allow['status'] === 403, 'guest cannot change permissions');

$allowAll = api('permit', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'allowAllControl' => true,
    'allowAllVideo' => true,
]);
expect(($allowAll['json']['room']['allowAllControl'] ?? false) === true, 'host enables room-wide permissions');

$third = api('join', ['roomId' => $roomId, 'name' => '成员丙']);
$thirdId = $third['json']['you']['id'] ?? '';
expect(($third['json']['you']['canControl'] ?? false) === true, 'allow-all applies to a new member');

$transfer = api('transfer', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'targetId' => $guestId,
]);
expect(($transfer['json']['you']['isHost'] ?? true) === false, 'old host is no longer host');
expect(($transfer['json']['room']['hostId'] ?? '') === $guestId, 'guest becomes host');
expect(($transfer['json']['room']['playback']['driverId'] ?? '') === $guestId, 'new host becomes driver');

$oldPermit = api('permit', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
    'targetId' => $thirdId,
    'canControl' => true,
]);
expect($oldPermit['status'] === 403, 'former host cannot grant permissions');

$kick = api('kick', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => $guestToken,
    'targetId' => $hostId,
]);
expect($kick['status'] === 200, 'new host kicks the former host');
$ids = array_column($kick['json']['room']['members'] ?? [], 'id');
expect(!in_array($hostId, $ids, true), 'kicked member is gone');

$kicked = api('sync', [
    'roomId' => $roomId,
    'memberId' => $hostId,
    'token' => $hostToken,
]);
expect($kicked['status'] === 401, 'kicked token is rejected');

$badToken = api('sync', [
    'roomId' => $roomId,
    'memberId' => $guestId,
    'token' => str_repeat('a', 32),
]);
expect($badToken['status'] === 401, 'wrong token is rejected');

$optionsPage = http('GET', $base . '?page=options');
$optionCsrf = csrf_from($optionsPage['body']);
$savedOptions = follow(http('POST', $base . '?page=options', [
    'csrf' => $optionCsrf,
    'form' => 'save-options',
    'back' => 'options',
    'max_members' => '2',
    'offline_seconds' => '12',
    'member_timeout_seconds' => '30',
    'room_idle_minutes' => '360',
    'driver_timeout_seconds' => '8',
]));
expect(str_contains($savedOptions['body'], '参数已保存'), 'save options from gui');

$limited = api('create', [
    'name' => '限额房主',
    'video' => video('BV1xx411c7mD', 1),
    'playback' => ['time' => 1, 'playing' => false, 'rate' => 1],
]);
$limitedId = $limited['json']['room']['id'] ?? '';
api('join', ['roomId' => $limitedId, 'name' => '一号']);
$overflow = api('join', ['roomId' => $limitedId, 'name' => '二号']);
expect($overflow['status'] === 403, 'max members is enforced');

$roomsPage = http('GET', $base . '?page=rooms');
expect(str_contains($roomsPage['body'], $limitedId), 'admin room list shows the room');
$roomCsrf = csrf_from($roomsPage['body']);
$deleted = follow(http('POST', $base . '?page=rooms', [
    'csrf' => $roomCsrf,
    'form' => 'delete-room',
    'back' => 'rooms',
    'room_id' => $limitedId,
]));
expect(str_contains($deleted['body'], '已删除房间'), 'admin deletes a room');
$afterDelete = api('sync', [
    'roomId' => $limitedId,
    'memberId' => $limited['json']['you']['id'],
    'token' => $limited['json']['token'],
]);
expect($afterDelete['status'] === 404, 'deleted room rejects sync');

$gcRoom = api('create', [
    'name' => '清理房主',
    'video' => video('BV1xx411c7mD', 1),
    'playback' => ['time' => 1, 'playing' => true, 'rate' => 1],
]);
$gcGuest = api('join', ['roomId' => $gcRoom['json']['room']['id'], 'name' => '会掉线']);
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=bili_sync_test;charset=utf8mb4', 'bili', 'bili_pass_test', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->prepare('UPDATE bs_members SET last_seen = ? WHERE id = ?')->execute([time() * 1000 - 120000, $gcGuest['json']['you']['id']]);
$pdo->prepare("UPDATE bs_meta SET v = '0' WHERE k = 'last_gc'")->execute();
$afterGc = api('sync', [
    'roomId' => $gcRoom['json']['room']['id'],
    'memberId' => $gcRoom['json']['you']['id'],
    'token' => $gcRoom['json']['token'],
    'mode' => 'heartbeat',
    'playback' => ['time' => 2, 'playing' => true, 'rate' => 1],
]);
$leftIds = array_column($afterGc['json']['room']['members'] ?? [], 'id');
expect(!in_array($gcGuest['json']['you']['id'], $leftIds, true), 'stale member is removed');
$staleSync = api('sync', [
    'roomId' => $gcRoom['json']['room']['id'],
    'memberId' => $gcGuest['json']['you']['id'],
    'token' => $gcGuest['json']['token'],
]);
expect($staleSync['status'] === 401, 'removed member cannot sync');

$leave = api('leave', [
    'roomId' => $gcRoom['json']['room']['id'],
    'memberId' => $gcRoom['json']['you']['id'],
    'token' => $gcRoom['json']['token'],
]);
expect(($leave['json']['left'] ?? false) === true, 'last member leaving closes the room');

$hostLeave = api('create', [
    'name' => '要离开的房主',
    'video' => video('BV1xx411c7mD', 1),
]);
$heir = api('join', ['roomId' => $hostLeave['json']['room']['id'], 'name' => '继承人']);
$left = api('leave', [
    'roomId' => $hostLeave['json']['room']['id'],
    'memberId' => $hostLeave['json']['you']['id'],
    'token' => $hostLeave['json']['token'],
]);
expect(($left['json']['left'] ?? false) === true, 'host can leave');
$heirView = api('sync', [
    'roomId' => $hostLeave['json']['room']['id'],
    'memberId' => $heir['json']['you']['id'],
    'token' => $heir['json']['token'],
]);
expect(($heirView['json']['you']['isHost'] ?? false) === true, 'host leaving promotes another member');

$logout = follow(http('POST', $base, [
    'csrf' => csrf_from(http('GET', $base)['body']),
    'form' => 'logout',
    'back' => 'login',
]));
expect(str_contains($logout['body'], '登录') && !str_contains($logout['body'], '修复数据表'), 'logout hides the admin panel');

$loginPage = $logout;
$loggedIn = follow(http('POST', $base . '?page=login', [
    'csrf' => csrf_from($loginPage['body']),
    'form' => 'login',
    'back' => 'login',
    'admin_user' => 'admin',
    'admin_pass' => 'test-pass-123',
]));
expect(str_contains($loggedIn['body'], '修复数据表'), 'admin can log back in');

$dbPage = http('GET', $base . '?page=database');
$kept = follow(http('POST', $base . '?page=database', [
    'csrf' => csrf_from($dbPage['body']),
    'form' => 'test-db',
    'back' => 'database',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'bili_sync_test',
    'db_user' => 'bili',
    'db_pass' => '',
    'db_prefix' => 'bs_',
]));
expect(str_contains($kept['body'], '已连上 MySQL'), 'database test keeps the saved password');

if ($failed > 0) {
    fwrite(STDERR, "{$failed} failed\n");
    exit(1);
}
echo "api ok\n";
