<?php
/**
 * 哔哩哔哩进度同步后端（单文件）
 *
 * 浏览器打开本文件即可进入管理页，配置 MySQL 并建表。
 * 油猴脚本把后端地址填成这个文件的 URL，例如 https://example.com/sync.php
 *
 * 需要 PHP 8.0+ 与 pdo_mysql，MySQL 5.7+ 或 MariaDB 10.3+。
 */
declare(strict_types=1);

const BILI_SYNC_VERSION = '1.0.0';

final class BiliSyncException extends RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}

function bili_now(): int
{
    return (int) round(microtime(true) * 1000);
}

function bili_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bili_cut(string $value, int $max): string
{
    if ($max <= 0) {
        return '';
    }
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || count($chars) <= $max) {
        return $value;
    }
    return implode('', array_slice($chars, 0, $max));
}

function bili_strlen(string $value): int
{
    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return is_array($chars) ? count($chars) : 0;
}

function bili_flag(mixed $value): bool
{
    return $value === true || $value === 1 || $value === '1';
}

function bili_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value === 1;
    }
    if (is_string($value)) {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
    return false;
}

function bili_private_dir(): string
{
    return __DIR__ . '/bili-sync-private';
}

function bili_config_path(): string
{
    return bili_private_dir() . '/config.php';
}

function bili_ensure_private(): void
{
    $dir = bili_private_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建配置目录：' . $dir);
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    $webConfig = $dir . '/web.config';
    if (!is_file($webConfig)) {
        file_put_contents(
            $webConfig,
            "<configuration><system.webServer><authorization><deny users=\"*\"/></authorization></system.webServer></configuration>\n"
        );
    }
}

function bili_default_options(): array
{
    return [
        'max_members' => 20,
        'offline_seconds' => 12,
        'member_timeout_seconds' => 180,
        'room_idle_minutes' => 360,
        'driver_timeout_seconds' => 8,
    ];
}

function bili_opt(array $cfg, string $key, int $default, int $min, int $max): int
{
    $value = (int) ($cfg['options'][$key] ?? $default);
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function bili_normalize_config(array $cfg): array
{
    $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
    $admin = is_array($cfg['admin'] ?? null) ? $cfg['admin'] : [];
    $options = bili_default_options();
    $incoming = is_array($cfg['options'] ?? null) ? $cfg['options'] : [];
    foreach ($options as $key => $default) {
        if (array_key_exists($key, $incoming)) {
            $options[$key] = (int) $incoming[$key];
        }
    }
    $normalized = [
        'installed' => !empty($cfg['installed']),
        'db' => [
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => (int) ($db['port'] ?? 3306),
            'name' => (string) ($db['name'] ?? 'bili_sync'),
            'user' => (string) ($db['user'] ?? ''),
            'pass' => (string) ($db['pass'] ?? ''),
            'prefix' => (string) ($db['prefix'] ?? 'bili_'),
        ],
        'admin' => [
            'user' => (string) ($admin['user'] ?? 'admin'),
            'pass_hash' => (string) ($admin['pass_hash'] ?? ''),
        ],
        'options' => $options,
    ];
    bili_validate_db($normalized['db']);
    return $normalized;
}

function bili_validate_db(array $db): void
{
    $host = (string) ($db['host'] ?? '');
    if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $host)) {
        throw new InvalidArgumentException('数据库主机只允许字母、数字、点、冒号、下划线和短横线');
    }
    $port = (int) ($db['port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        throw new InvalidArgumentException('数据库端口无效');
    }
    $name = (string) ($db['name'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        throw new InvalidArgumentException('数据库名只允许字母、数字和下划线');
    }
    $user = (string) ($db['user'] ?? '');
    if ($user === '' || strlen($user) > 128) {
        throw new InvalidArgumentException('请填写数据库用户名');
    }
    $prefix = (string) ($db['prefix'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_]{0,12}$/', $prefix)) {
        throw new InvalidArgumentException('表前缀只允许字母、数字和下划线，最长 12 位');
    }
}

function bili_load_config(): ?array
{
    $path = bili_config_path();
    if (!is_file($path)) {
        return null;
    }
    $loaded = include $path;
    if (!is_array($loaded)) {
        return null;
    }
    try {
        return bili_normalize_config($loaded);
    } catch (InvalidArgumentException) {
        return $loaded;
    }
}

function bili_save_config(array $cfg): void
{
    bili_ensure_private();
    $normalized = bili_normalize_config($cfg);
    $path = bili_config_path();
    $tmp = $path . '.tmp';
    $php = "<?php\nreturn " . var_export($normalized, true) . ";\n";
    if (file_put_contents($tmp, $php, LOCK_EX) === false) {
        throw new RuntimeException('无法写入配置文件，请检查目录权限：' . bili_private_dir());
    }
    @chmod($tmp, 0640);
    if (!rename($tmp, $path)) {
        throw new RuntimeException('无法保存配置文件');
    }
}

function bili_prefix(array $cfg): string
{
    $prefix = (string) ($cfg['db']['prefix'] ?? 'bili_');
    if (!preg_match('/^[A-Za-z0-9_]{0,12}$/', $prefix)) {
        throw new InvalidArgumentException('表前缀无效');
    }
    return $prefix;
}

function bili_table(array $cfg, string $suffix): string
{
    if (!preg_match('/^[a-z_]+$/', $suffix)) {
        throw new InvalidArgumentException('数据表名无效');
    }
    return '`' . bili_prefix($cfg) . $suffix . '`';
}

function bili_pdo_options(): array
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
    return $options;
}

function bili_server_dsn(array $db): string
{
    return sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db['host'], (int) $db['port']);
}

function bili_connect(array $cfg): PDO
{
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('未安装 pdo_mysql 扩展');
    }
    $db = $cfg['db'];
    bili_validate_db($db);
    $options = bili_pdo_options();
    try {
        $server = new PDO(bili_server_dsn($db), $db['user'], $db['pass'], $options);
        $server->exec(
            'CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    } catch (PDOException $e) {
        // 没有建库权限时，继续尝试连接已经存在的数据库。
        error_log('[bili-sync] create database: ' . $e->getMessage());
    }
    return new PDO(bili_server_dsn($db) . ';dbname=' . $db['name'], $db['user'], $db['pass'], $options);
}

function bili_ensure_schema(PDO $pdo, array $cfg): void
{
    $prefix = bili_prefix($cfg);
    $rooms = bili_table($cfg, 'rooms');
    $members = bili_table($cfg, 'members');
    $meta = bili_table($cfg, 'meta');
    $fk = '`' . $prefix . 'member_room_fk`';
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$rooms} (
            id VARCHAR(8) NOT NULL,
            host_member_id CHAR(32) NOT NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            video_key VARCHAR(80) NOT NULL DEFAULT '',
            video_url VARCHAR(500) NOT NULL DEFAULT '',
            video_title VARCHAR(200) NOT NULL DEFAULT '',
            play_time DOUBLE NOT NULL DEFAULT 0,
            playing TINYINT(1) NOT NULL DEFAULT 0,
            play_rate DOUBLE NOT NULL DEFAULT 1,
            playback_updated_at BIGINT NOT NULL,
            driver_member_id CHAR(32) NOT NULL,
            allow_all_control TINYINT(1) NOT NULL DEFAULT 0,
            allow_all_video TINYINT(1) NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$members} (
            id CHAR(32) NOT NULL,
            room_id VARCHAR(8) NOT NULL,
            name VARCHAR(32) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            is_host TINYINT(1) NOT NULL DEFAULT 0,
            can_control TINYINT(1) NOT NULL DEFAULT 0,
            can_change_video TINYINT(1) NOT NULL DEFAULT 0,
            last_seen BIGINT NOT NULL,
            created_at BIGINT NOT NULL,
            PRIMARY KEY (id),
            KEY idx_room (room_id),
            CONSTRAINT {$fk} FOREIGN KEY (room_id) REFERENCES {$rooms} (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$meta} (
            k VARCHAR(32) NOT NULL,
            v VARCHAR(255) NOT NULL,
            PRIMARY KEY (k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function bili_pdo_ready(array $cfg): PDO
{
    static $readyKey = '';
    $pdo = bili_connect($cfg);
    $key = $cfg['db']['name'] . '|' . bili_prefix($cfg) . '|' . $cfg['db']['host'] . '|' . $cfg['db']['port'];
    if ($readyKey !== $key) {
        bili_ensure_schema($pdo, $cfg);
        $readyKey = $key;
    }
    return $pdo;
}

function bili_test_connection(array $cfg): string
{
    $pdo = bili_connect($cfg);
    bili_ensure_schema($pdo, $cfg);
    $pdo->query('SELECT 1');
    return '已连上 MySQL，数据表已就绪';
}

function bili_hydrate_room(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'host_member_id' => (string) $row['host_member_id'],
        'revision' => (int) $row['revision'],
        'video_key' => (string) $row['video_key'],
        'video_url' => (string) $row['video_url'],
        'video_title' => (string) $row['video_title'],
        'play_time' => (float) $row['play_time'],
        'playing' => bili_flag($row['playing']),
        'play_rate' => (float) $row['play_rate'],
        'playback_updated_at' => (int) $row['playback_updated_at'],
        'driver_member_id' => (string) $row['driver_member_id'],
        'allow_all_control' => bili_flag($row['allow_all_control']),
        'allow_all_video' => bili_flag($row['allow_all_video']),
        'created_at' => (int) $row['created_at'],
        'updated_at' => (int) $row['updated_at'],
    ];
}

function bili_hydrate_member(array $row): array
{
    return [
        'id' => (string) $row['id'],
        'room_id' => (string) $row['room_id'],
        'name' => (string) $row['name'],
        'token_hash' => (string) $row['token_hash'],
        'is_host' => bili_flag($row['is_host']),
        'can_control' => bili_flag($row['can_control']),
        'can_change_video' => bili_flag($row['can_change_video']),
        'last_seen' => (int) $row['last_seen'],
        'created_at' => (int) $row['created_at'],
    ];
}

function bili_effective_control(array $room, array $member): bool
{
    if ($member['id'] === $room['host_member_id']) {
        return true;
    }
    if (!empty($room['allow_all_control'])) {
        return true;
    }
    return !empty($member['can_control']);
}

function bili_effective_video(array $room, array $member): bool
{
    if ($member['id'] === $room['host_member_id']) {
        return true;
    }
    if (!empty($room['allow_all_video'])) {
        return true;
    }
    return !empty($member['can_change_video']);
}

function bili_clean_title(string $title): string
{
    $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title) ?? '';
    return bili_cut(trim($title), 80);
}

function bili_canonical_video(string $url, string $title): array
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
        throw new InvalidArgumentException('只接受哔哩哔哩的 https 视频地址');
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($host, ['www.bilibili.com', 'bilibili.com', 'm.bilibili.com'], true)) {
        throw new InvalidArgumentException('只接受哔哩哔哩的 https 视频地址');
    }
    $path = (string) ($parts['path'] ?? '');
    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }
    $cleanTitle = bili_clean_title($title);
    if (preg_match('#^/video/(BV[0-9A-Za-z]+|av[0-9]+)/?$#i', $path, $match)) {
        $id = $match[1];
        if (preg_match('/^av/i', $id)) {
            $id = 'av' . substr($id, 2);
        }
        $page = (int) ($query['p'] ?? 1);
        if ($page < 1) {
            $page = 1;
        }
        if ($page > 9999) {
            $page = 9999;
        }
        return [
            'key' => $id . '#p' . $page,
            'url' => 'https://www.bilibili.com/video/' . $id . '/?p=' . $page,
            'title' => $cleanTitle,
        ];
    }
    if (preg_match('#^/bangumi/play/(ep[0-9]+|ss[0-9]+)/?$#i', $path, $match)) {
        $id = strtolower($match[1]);
        if (str_starts_with($id, 'ss')) {
            $ep = (string) ($query['ep'] ?? '');
            if (preg_match('/^[0-9]{1,12}$/', $ep)) {
                return [
                    'key' => $id . '#ep' . $ep,
                    'url' => 'https://www.bilibili.com/bangumi/play/' . $id . '?ep=' . $ep,
                    'title' => $cleanTitle,
                ];
            }
        }
        return [
            'key' => $id,
            'url' => 'https://www.bilibili.com/bangumi/play/' . $id,
            'title' => $cleanTitle,
        ];
    }
    throw new InvalidArgumentException('只接受普通视频或番剧播放页');
}

function bili_norm_name(string $name, bool $generate): string
{
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = bili_cut(trim($name), 16);
    if ($name === '') {
        return $generate ? ('用户' . random_int(1000, 9999)) : '';
    }
    return $name;
}

function bili_norm_playback(array $playback): array
{
    $time = isset($playback['time']) ? (float) $playback['time'] : 0.0;
    if (!is_finite($time) || $time < 0) {
        $time = 0.0;
    }
    if ($time > 86400) {
        $time = 86400.0;
    }
    $rate = isset($playback['rate']) ? (float) $playback['rate'] : 1.0;
    if (!is_finite($rate) || $rate < 0.25 || $rate > 4) {
        $rate = 1.0;
    }
    return [round($time, 3), bili_bool($playback['playing'] ?? false), round($rate, 3)];
}

function bili_room_code(mixed $value): string
{
    $code = strtoupper(trim((string) $value));
    if (!preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $code)) {
        throw new InvalidArgumentException('房间号应为 6 位，不含容易看混的 0、O、1、I');
    }
    return $code;
}

function bili_member_code(mixed $value): string
{
    $id = strtolower(trim((string) $value));
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        throw new InvalidArgumentException('成员身份无效');
    }
    return $id;
}

function bili_new_id(): string
{
    return bin2hex(random_bytes(16));
}

function bili_room_id(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $id = '';
    for ($i = 0; $i < 6; $i++) {
        $id .= $alphabet[random_int(0, $max)];
    }
    return $id;
}

function bili_token_ok(array $member, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return false;
    }
    $hash = hash('sha256', $token);
    return strlen($member['token_hash']) === strlen($hash) && hash_equals($member['token_hash'], $hash);
}

function bili_tx(PDO $pdo, callable $fn): mixed
{
    $last = null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $last = $e;
            $message = $e->getMessage();
            $retry = str_contains($message, '1213') || str_contains($message, '1205') || (string) $e->getCode() === '40001';
            if (!$retry || $attempt === 1 || $e instanceof BiliSyncException || $e instanceof InvalidArgumentException) {
                throw $e;
            }
        }
    }
    throw $last instanceof Throwable ? $last : new RuntimeException('事务失败');
}

function bili_lock_room(PDO $pdo, array $cfg, string $roomId): array
{
    $stmt = $pdo->prepare('SELECT * FROM ' . bili_table($cfg, 'rooms') . ' WHERE id = ? FOR UPDATE');
    $stmt->execute([$roomId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new BiliSyncException(404, '房间不存在或已关闭');
    }
    return bili_hydrate_room($row);
}

function bili_lock_members(PDO $pdo, array $cfg, string $roomId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM ' . bili_table($cfg, 'members') . ' WHERE room_id = ? ORDER BY created_at ASC FOR UPDATE'
    );
    $stmt->execute([$roomId]);
    $members = [];
    foreach ($stmt->fetchAll() as $row) {
        $members[] = bili_hydrate_member($row);
    }
    return $members;
}

function bili_find_member(array $members, string $memberId): ?array
{
    foreach ($members as $member) {
        if ($member['id'] === $memberId) {
            return $member;
        }
    }
    return null;
}

function bili_replace_member(array &$members, array $member): void
{
    foreach ($members as $index => $current) {
        if ($current['id'] === $member['id']) {
            $members[$index] = $member;
            return;
        }
    }
}

function bili_save_room(PDO $pdo, array $cfg, array $room): void
{
    $sql = 'UPDATE ' . bili_table($cfg, 'rooms') . ' SET
        host_member_id = ?, revision = ?, video_key = ?, video_url = ?, video_title = ?,
        play_time = ?, playing = ?, play_rate = ?, playback_updated_at = ?, driver_member_id = ?,
        allow_all_control = ?, allow_all_video = ?, updated_at = ? WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $room['host_member_id'],
        $room['revision'],
        $room['video_key'],
        $room['video_url'],
        $room['video_title'],
        sprintf('%.3F', $room['play_time']),
        $room['playing'] ? 1 : 0,
        sprintf('%.3F', $room['play_rate']),
        $room['playback_updated_at'],
        $room['driver_member_id'],
        $room['allow_all_control'] ? 1 : 0,
        $room['allow_all_video'] ? 1 : 0,
        $room['updated_at'],
        $room['id'],
    ]);
}

function bili_public_state(array $room, array $members, array $me, int $now, array $cfg): array
{
    $offlineMs = bili_opt($cfg, 'offline_seconds', 12, 5, 120) * 1000;
    $list = [];
    foreach ($members as $member) {
        $list[] = [
            'id' => $member['id'],
            'name' => $member['name'],
            'isHost' => $member['id'] === $room['host_member_id'],
            'canControl' => bili_effective_control($room, $member),
            'canChangeVideo' => bili_effective_video($room, $member),
            'grantControl' => $member['can_control'],
            'grantVideo' => $member['can_change_video'],
            'online' => ($now - $member['last_seen']) <= $offlineMs,
        ];
    }
    usort($list, static function (array $a, array $b): int {
        if ($a['isHost'] !== $b['isHost']) {
            return $a['isHost'] ? -1 : 1;
        }
        return strcmp($a['id'], $b['id']);
    });
    return [
        'ok' => true,
        'serverNow' => $now,
        'you' => [
            'id' => $me['id'],
            'name' => $me['name'],
            'isHost' => $me['id'] === $room['host_member_id'],
            'canControl' => bili_effective_control($room, $me),
            'canChangeVideo' => bili_effective_video($room, $me),
        ],
        'room' => [
            'id' => $room['id'],
            'revision' => $room['revision'],
            'hostId' => $room['host_member_id'],
            'allowAllControl' => $room['allow_all_control'],
            'allowAllVideo' => $room['allow_all_video'],
            'video' => [
                'key' => $room['video_key'],
                'url' => $room['video_url'],
                'title' => $room['video_title'],
            ],
            'playback' => [
                'time' => $room['play_time'],
                'playing' => $room['playing'],
                'rate' => $room['play_rate'],
                'updatedAt' => $room['playback_updated_at'],
                'driverId' => $room['driver_member_id'],
            ],
            'members' => $list,
        ],
    ];
}

function bili_mark_seen(PDO $pdo, array $cfg, array &$members, array &$me, int $now): void
{
    $previous = $me['last_seen'];
    $me['last_seen'] = $now;
    bili_replace_member($members, $me);
    if ($now - $previous < 2000) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE ' . bili_table($cfg, 'members') . ' SET last_seen = ? WHERE id = ?');
    $stmt->execute([$now, $me['id']]);
}

function bili_takeover_if_needed(array &$room, array $members, array $me, int $now, array $cfg): void
{
    if ($room['driver_member_id'] === $room['host_member_id']) {
        return;
    }
    $limit = bili_opt($cfg, 'driver_timeout_seconds', 8, 3, 60) * 1000;
    $driver = bili_find_member($members, $room['driver_member_id']);
    $seen = $driver['last_seen'] ?? 0;
    if ($driver !== null && ($now - $seen) <= $limit) {
        return;
    }
    if ($room['playing']) {
        $elapsed = min($limit, max(0, $now - $room['playback_updated_at']));
        $room['play_time'] += ($elapsed / 1000) * $room['play_rate'];
        if ($room['play_time'] > 86400) {
            $room['play_time'] = 86400;
        }
    }
    $room['playback_updated_at'] = $now;
    $room['driver_member_id'] = $room['host_member_id'];
    $room['revision']++;
}

function bili_apply_name(PDO $pdo, array $cfg, array $body, array &$members, array &$me): void
{
    if (!array_key_exists('name', $body)) {
        return;
    }
    $name = bili_norm_name((string) $body['name'], false);
    if ($name === '' || $name === $me['name']) {
        return;
    }
    $me['name'] = $name;
    bili_replace_member($members, $me);
    $stmt = $pdo->prepare('UPDATE ' . bili_table($cfg, 'members') . ' SET name = ? WHERE id = ?');
    $stmt->execute([$name, $me['id']]);
}

function bili_apply_video(array &$room, array $me, array $body, int $now, bool &$keyChanged): void
{
    $keyChanged = false;
    if (!array_key_exists('video', $body) || $body['video'] === null) {
        return;
    }
    if (!is_array($body['video'])) {
        throw new InvalidArgumentException('视频数据无效');
    }
    if (!bili_effective_video($room, $me)) {
        throw new BiliSyncException(403, '没有更换视频的权限');
    }
    $video = bili_canonical_video((string) ($body['video']['url'] ?? ''), (string) ($body['video']['title'] ?? ''));
    if ($video['key'] === $room['video_key'] && $video['url'] === $room['video_url'] && $video['title'] === $room['video_title']) {
        return;
    }
    $keyChanged = $video['key'] !== $room['video_key'];
    $room['video_key'] = $video['key'];
    $room['video_url'] = $video['url'];
    $room['video_title'] = $video['title'];
    if ($keyChanged) {
        $room['play_time'] = 0;
        $room['playing'] = false;
        $room['playback_updated_at'] = $now;
        if (bili_effective_control($room, $me)) {
            $room['driver_member_id'] = $me['id'];
        }
    }
    $room['revision']++;
}

function bili_apply_playback(array &$room, array $me, array $body, int $now, bool $videoChanged): void
{
    if (!array_key_exists('playback', $body) || $body['playback'] === null) {
        return;
    }
    if (!is_array($body['playback'])) {
        throw new InvalidArgumentException('进度数据无效');
    }
    $mode = (($body['mode'] ?? '') === 'heartbeat') ? 'heartbeat' : 'user';
    if (!bili_effective_control($room, $me)) {
        if ($mode === 'user' && !$videoChanged) {
            throw new BiliSyncException(403, '没有调整进度的权限');
        }
        return;
    }
    if ($mode === 'heartbeat' && $room['driver_member_id'] !== $me['id']) {
        return;
    }
    [$time, $playing, $rate] = bili_norm_playback($body['playback']);
    if ($mode === 'user') {
        $room['driver_member_id'] = $me['id'];
    }
    $room['play_time'] = $time;
    $room['playing'] = $playing;
    $room['play_rate'] = $rate;
    $room['playback_updated_at'] = $now;
    $room['revision']++;
}

function bili_with_member(PDO $pdo, array $cfg, array $body, callable $fn): array
{
    $roomId = bili_room_code($body['roomId'] ?? '');
    $memberId = bili_member_code($body['memberId'] ?? '');
    $token = (string) ($body['token'] ?? '');
    return bili_tx($pdo, function (PDO $pdo) use ($cfg, $body, $fn, $roomId, $memberId, $token): array {
        $room = bili_lock_room($pdo, $cfg, $roomId);
        $members = bili_lock_members($pdo, $cfg, $roomId);
        $me = bili_find_member($members, $memberId);
        if ($me === null || !bili_token_ok($me, $token)) {
            throw new BiliSyncException(401, '身份无效，请重新加入房间');
        }
        $now = bili_now();
        bili_mark_seen($pdo, $cfg, $members, $me, $now);
        $beforeRevision = $room['revision'];
        $result = $fn($pdo, $room, $members, $me, $now);
        if (is_array($result) && !empty($result['closed'])) {
            return $result['body'];
        }
        if ($room['revision'] !== $beforeRevision || $now - $room['updated_at'] > 10000) {
            $room['updated_at'] = $now;
            bili_save_room($pdo, $cfg, $room);
        }
        $me = bili_find_member($members, $memberId) ?? $me;
        return bili_public_state($room, $members, $me, $now, $cfg);
    });
}

function bili_gc(PDO $pdo, array $cfg, int $now): void
{
    $meta = bili_table($cfg, 'meta');
    $roomsTable = bili_table($cfg, 'rooms');
    $membersTable = bili_table($cfg, 'members');
    $stmt = $pdo->query("SELECT v FROM {$meta} WHERE k = 'last_gc'");
    $row = $stmt->fetch();
    if ($row && ($now - (int) $row['v']) < 30000) {
        return;
    }
    $mark = $pdo->prepare("INSERT INTO {$meta} (k, v) VALUES ('last_gc', ?) ON DUPLICATE KEY UPDATE v = ?");
    $mark->execute([(string) $now, (string) $now]);

    $idleMs = bili_opt($cfg, 'room_idle_minutes', 360, 10, 10080) * 60 * 1000;
    $memberTimeout = bili_opt($cfg, 'member_timeout_seconds', 180, 30, 3600) * 1000;
    $rooms = $pdo->query("SELECT * FROM {$roomsTable}")->fetchAll();
    foreach ($rooms as $rawRoom) {
        $room = bili_hydrate_room($rawRoom);
        $memberStmt = $pdo->prepare("SELECT * FROM {$membersTable} WHERE room_id = ?");
        $memberStmt->execute([$room['id']]);
        $members = [];
        foreach ($memberStmt->fetchAll() as $rawMember) {
            $members[] = bili_hydrate_member($rawMember);
        }
        $fresh = [];
        $staleIds = [];
        $newestSeen = 0;
        foreach ($members as $member) {
            $newestSeen = max($newestSeen, $member['last_seen']);
            if ($now - $member['last_seen'] > $memberTimeout) {
                $staleIds[] = $member['id'];
            } else {
                $fresh[] = $member;
            }
        }
        $lastActivity = max($room['updated_at'], $newestSeen);
        if (!$fresh || $now - $lastActivity > $idleMs) {
            $pdo->prepare("DELETE FROM {$roomsTable} WHERE id = ?")->execute([$room['id']]);
            continue;
        }
        $hostFresh = bili_find_member($fresh, $room['host_member_id']) !== null;
        if (!$hostFresh) {
            usort($fresh, static fn (array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);
            $newHost = $fresh[0];
            $driverFresh = bili_find_member($fresh, $room['driver_member_id']) !== null;
            $pdo->prepare("UPDATE {$membersTable} SET is_host = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE room_id = ?")
                ->execute([$newHost['id'], $room['id']]);
            $pdo->prepare(
                "UPDATE {$roomsTable} SET host_member_id = ?, driver_member_id = ?, revision = revision + 1, updated_at = ? WHERE id = ?"
            )->execute([
                $newHost['id'],
                $driverFresh ? $room['driver_member_id'] : $newHost['id'],
                $now,
                $room['id'],
            ]);
        }
        if ($staleIds) {
            $slots = implode(',', array_fill(0, count($staleIds), '?'));
            $pdo->prepare("DELETE FROM {$membersTable} WHERE room_id = ? AND id IN ($slots)")
                ->execute(array_merge([$room['id']], $staleIds));
        }
    }
}

function bili_insert_room(PDO $pdo, array $cfg, array $room): string
{
    $sql = 'INSERT INTO ' . bili_table($cfg, 'rooms') . ' (
        id, host_member_id, revision, video_key, video_url, video_title, play_time, playing, play_rate,
        playback_updated_at, driver_member_id, allow_all_control, allow_all_video, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $roomId = bili_room_id();
        try {
            $stmt->execute([
                $roomId,
                $room['host_member_id'],
                1,
                $room['video_key'],
                $room['video_url'],
                $room['video_title'],
                sprintf('%.3F', $room['play_time']),
                $room['playing'] ? 1 : 0,
                sprintf('%.3F', $room['play_rate']),
                $room['playback_updated_at'],
                $room['driver_member_id'],
                0,
                0,
                $room['created_at'],
                $room['updated_at'],
            ]);
            return $roomId;
        } catch (PDOException $e) {
            $duplicate = (string) $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
            if (!$duplicate) {
                throw $e;
            }
        }
    }
    throw new BiliSyncException(500, '创建房间失败，请重试');
}

function bili_insert_member(PDO $pdo, array $cfg, array $member): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO ' . bili_table($cfg, 'members') . ' (
            id, room_id, name, token_hash, is_host, can_control, can_change_video, last_seen, created_at
        ) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?)'
    );
    $stmt->execute([
        $member['id'],
        $member['room_id'],
        $member['name'],
        $member['token_hash'],
        $member['is_host'] ? 1 : 0,
        $member['last_seen'],
        $member['created_at'],
    ]);
}

function bili_api_create(PDO $pdo, array $cfg, array $body): array
{
    $name = bili_norm_name((string) ($body['name'] ?? ''), true);
    $now = bili_now();
    $video = ['key' => '', 'url' => '', 'title' => ''];
    if (isset($body['video']) && is_array($body['video']) && (($body['video']['url'] ?? '') !== '')) {
        $video = bili_canonical_video((string) $body['video']['url'], (string) ($body['video']['title'] ?? ''));
    }
    [$time, $playing, $rate] = bili_norm_playback(is_array($body['playback'] ?? null) ? $body['playback'] : []);
    $memberId = bili_new_id();
    $token = bili_new_id();
    $room = [
        'host_member_id' => $memberId,
        'video_key' => $video['key'],
        'video_url' => $video['url'],
        'video_title' => $video['title'],
        'play_time' => $time,
        'playing' => $playing,
        'play_rate' => $rate,
        'playback_updated_at' => $now,
        'driver_member_id' => $memberId,
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $roomId = bili_tx($pdo, function (PDO $pdo) use ($cfg, $room, $memberId, $name, $token, $now): string {
        $roomId = bili_insert_room($pdo, $cfg, $room);
        bili_insert_member($pdo, $cfg, [
            'id' => $memberId,
            'room_id' => $roomId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'is_host' => true,
            'last_seen' => $now,
            'created_at' => $now,
        ]);
        return $roomId;
    });
    $state = bili_tx($pdo, function (PDO $pdo) use ($cfg, $roomId, $memberId, $token): array {
        $room = bili_lock_room($pdo, $cfg, $roomId);
        $members = bili_lock_members($pdo, $cfg, $roomId);
        $me = bili_find_member($members, $memberId);
        if ($me === null || !bili_token_ok($me, $token)) {
            throw new BiliSyncException(500, '创建房间失败');
        }
        return bili_public_state($room, $members, $me, bili_now(), $cfg);
    });
    $state['token'] = $token;
    return $state;
}

function bili_api_join(PDO $pdo, array $cfg, array $body): array
{
    $roomId = bili_room_code($body['roomId'] ?? '');
    $name = bili_norm_name((string) ($body['name'] ?? ''), true);
    $memberId = bili_new_id();
    $token = bili_new_id();
    $maxMembers = bili_opt($cfg, 'max_members', 20, 2, 50);
    return bili_tx($pdo, function (PDO $pdo) use ($cfg, $roomId, $name, $memberId, $token, $maxMembers): array {
        $room = bili_lock_room($pdo, $cfg, $roomId);
        $members = bili_lock_members($pdo, $cfg, $roomId);
        if (count($members) >= $maxMembers) {
            throw new BiliSyncException(403, '房间已满');
        }
        $now = bili_now();
        bili_insert_member($pdo, $cfg, [
            'id' => $memberId,
            'room_id' => $roomId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'is_host' => false,
            'last_seen' => $now,
            'created_at' => $now,
        ]);
        $members[] = [
            'id' => $memberId,
            'room_id' => $roomId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'is_host' => false,
            'can_control' => false,
            'can_change_video' => false,
            'last_seen' => $now,
            'created_at' => $now,
        ];
        $room['updated_at'] = $now;
        bili_save_room($pdo, $cfg, $room);
        $me = bili_find_member($members, $memberId);
        $state = bili_public_state($room, $members, $me, $now, $cfg);
        $state['token'] = $token;
        return $state;
    });
}

function bili_api_sync(PDO $pdo, array $cfg, array $body): array
{
    return bili_with_member($pdo, $cfg, $body, function (PDO $pdo, array &$room, array &$members, array &$me, int $now) use ($cfg, $body): void {
        bili_takeover_if_needed($room, $members, $me, $now, $cfg);
        bili_apply_name($pdo, $cfg, $body, $members, $me);
        $videoChanged = false;
        bili_apply_video($room, $me, $body, $now, $videoChanged);
        bili_apply_playback($room, $me, $body, $now, $videoChanged);
    });
}

function bili_api_permit(PDO $pdo, array $cfg, array $body): array
{
    return bili_with_member($pdo, $cfg, $body, function (PDO $pdo, array &$room, array &$members, array &$me, int $now) use ($cfg, $body): void {
        if ($me['id'] !== $room['host_member_id']) {
            throw new BiliSyncException(403, '只有房主可以设置权限');
        }
        $changed = false;
        if (array_key_exists('allowAllControl', $body)) {
            $room['allow_all_control'] = bili_bool($body['allowAllControl']);
            $changed = true;
        }
        if (array_key_exists('allowAllVideo', $body)) {
            $room['allow_all_video'] = bili_bool($body['allowAllVideo']);
            $changed = true;
        }
        if (array_key_exists('targetId', $body) && $body['targetId'] !== '' && $body['targetId'] !== null) {
            $targetId = bili_member_code($body['targetId']);
            $target = bili_find_member($members, $targetId);
            if ($target === null) {
                throw new BiliSyncException(404, '成员不在房间里');
            }
            if ($target['id'] === $room['host_member_id']) {
                throw new InvalidArgumentException('房主始终可以调整视频和进度');
            }
            if (array_key_exists('canControl', $body)) {
                $target['can_control'] = bili_bool($body['canControl']);
            }
            if (array_key_exists('canChangeVideo', $body)) {
                $target['can_change_video'] = bili_bool($body['canChangeVideo']);
            }
            bili_replace_member($members, $target);
            $stmt = $pdo->prepare(
                'UPDATE ' . bili_table($cfg, 'members') . ' SET can_control = ?, can_change_video = ? WHERE id = ?'
            );
            $stmt->execute([$target['can_control'] ? 1 : 0, $target['can_change_video'] ? 1 : 0, $target['id']]);
            $changed = true;
        }
        $driver = bili_find_member($members, $room['driver_member_id']);
        if ($driver !== null && $driver['id'] !== $room['host_member_id'] && !bili_effective_control($room, $driver)) {
            $room['driver_member_id'] = $room['host_member_id'];
            $room['playback_updated_at'] = $now;
            $changed = true;
        }
        if ($changed) {
            $room['revision']++;
        }
    });
}

function bili_set_host_flags(PDO $pdo, array $cfg, string $roomId, string $hostId): void
{
    $stmt = $pdo->prepare(
        'UPDATE ' . bili_table($cfg, 'members') . ' SET is_host = CASE WHEN id = ? THEN 1 ELSE 0 END WHERE room_id = ?'
    );
    $stmt->execute([$hostId, $roomId]);
}

function bili_api_transfer(PDO $pdo, array $cfg, array $body): array
{
    return bili_with_member($pdo, $cfg, $body, function (PDO $pdo, array &$room, array &$members, array &$me, int $now) use ($cfg, $body): void {
        if ($me['id'] !== $room['host_member_id']) {
            throw new BiliSyncException(403, '只有房主可以移交房主');
        }
        $targetId = bili_member_code($body['targetId'] ?? '');
        $target = bili_find_member($members, $targetId);
        if ($target === null) {
            throw new BiliSyncException(404, '成员不在房间里');
        }
        if ($target['id'] === $room['host_member_id']) {
            throw new InvalidArgumentException('对方已经是房主');
        }
        $room['host_member_id'] = $target['id'];
        $room['driver_member_id'] = $target['id'];
        $room['playback_updated_at'] = $now;
        $room['revision']++;
        foreach ($members as $index => $member) {
            $members[$index]['is_host'] = $member['id'] === $target['id'];
        }
        bili_set_host_flags($pdo, $cfg, $room['id'], $target['id']);
    });
}

function bili_api_kick(PDO $pdo, array $cfg, array $body): array
{
    return bili_with_member($pdo, $cfg, $body, function (PDO $pdo, array &$room, array &$members, array &$me, int $now) use ($cfg, $body): void {
        if ($me['id'] !== $room['host_member_id']) {
            throw new BiliSyncException(403, '只有房主可以移出成员');
        }
        $targetId = bili_member_code($body['targetId'] ?? '');
        if ($targetId === $room['host_member_id']) {
            throw new InvalidArgumentException('不能移出房主');
        }
        if (bili_find_member($members, $targetId) === null) {
            throw new BiliSyncException(404, '成员不在房间里');
        }
        $pdo->prepare('DELETE FROM ' . bili_table($cfg, 'members') . ' WHERE id = ?')->execute([$targetId]);
        $members = array_values(array_filter($members, static fn (array $member): bool => $member['id'] !== $targetId));
        if ($room['driver_member_id'] === $targetId) {
            $room['driver_member_id'] = $room['host_member_id'];
            $room['playback_updated_at'] = $now;
        }
        $room['revision']++;
    });
}

function bili_api_leave(PDO $pdo, array $cfg, array $body): array
{
    return bili_with_member($pdo, $cfg, $body, function (PDO $pdo, array &$room, array &$members, array &$me, int $now) use ($cfg): array {
        $pdo->prepare('DELETE FROM ' . bili_table($cfg, 'members') . ' WHERE id = ?')->execute([$me['id']]);
        $remaining = array_values(array_filter($members, static fn (array $member): bool => $member['id'] !== $me['id']));
        if (!$remaining) {
            $pdo->prepare('DELETE FROM ' . bili_table($cfg, 'rooms') . ' WHERE id = ?')->execute([$room['id']]);
            return ['closed' => true, 'body' => ['ok' => true, 'left' => true, 'serverNow' => $now]];
        }
        if ($me['id'] === $room['host_member_id']) {
            usort($remaining, static fn (array $a, array $b): int => $b['last_seen'] <=> $a['last_seen']);
            $room['host_member_id'] = $remaining[0]['id'];
            foreach ($remaining as $index => $member) {
                $remaining[$index]['is_host'] = $member['id'] === $room['host_member_id'];
            }
            bili_set_host_flags($pdo, $cfg, $room['id'], $room['host_member_id']);
        }
        if (bili_find_member($remaining, $room['driver_member_id']) === null) {
            $room['driver_member_id'] = $room['host_member_id'];
            $room['playback_updated_at'] = $now;
        }
        $members = $remaining;
        $room['revision']++;
        $room['updated_at'] = $now;
        bili_save_room($pdo, $cfg, $room);
        return ['closed' => true, 'body' => ['ok' => true, 'left' => true, 'serverNow' => $now]];
    });
}

function bili_api_health(): array
{
    $cfg = null;
    try {
        $cfg = bili_load_config();
    } catch (Throwable $e) {
        error_log('[bili-sync] config ' . $e->getMessage());
    }
    $configured = is_array($cfg) && !empty($cfg['installed']);
    $db = false;
    if ($configured) {
        try {
            $pdo = bili_pdo_ready($cfg);
            $pdo->query('SELECT 1');
            $db = true;
        } catch (Throwable $e) {
            error_log('[bili-sync] health ' . $e->getMessage());
        }
    }
    return [
        'ok' => true,
        'version' => BILI_SYNC_VERSION,
        'configured' => $configured,
        'db' => $db,
    ];
}

function bili_read_json_body(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 20001);
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > 20000) {
        throw new InvalidArgumentException('请求体过大');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('JSON 无效');
    }
    return $data;
}

function bili_api_main(string $action): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        return;
    }
    try {
        if ($action !== 'health' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new BiliSyncException(405, '请使用 POST');
        }
        $body = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? bili_read_json_body() : [];
        if ($action === 'health') {
            $result = bili_api_health();
        } else {
            $cfg = bili_load_config();
            if (!is_array($cfg) || empty($cfg['installed'])) {
                throw new BiliSyncException(503, '后端还没配置，请先在浏览器打开这个地址完成设置');
            }
            try {
                $pdo = bili_pdo_ready($cfg);
            } catch (Throwable $e) {
                error_log('[bili-sync] db ' . $e->getMessage());
                throw new BiliSyncException(503, '数据库不可用，请检查管理页里的 MySQL 配置');
            }
            try {
                bili_gc($pdo, $cfg, bili_now());
            } catch (Throwable $e) {
                error_log('[bili-sync] gc ' . $e->getMessage());
            }
            $result = match ($action) {
                'create' => bili_api_create($pdo, $cfg, $body),
                'join' => bili_api_join($pdo, $cfg, $body),
                'sync' => bili_api_sync($pdo, $cfg, $body),
                'permit' => bili_api_permit($pdo, $cfg, $body),
                'transfer' => bili_api_transfer($pdo, $cfg, $body),
                'kick' => bili_api_kick($pdo, $cfg, $body),
                'leave' => bili_api_leave($pdo, $cfg, $body),
                default => throw new BiliSyncException(404, '未知接口'),
            };
        }
        http_response_code(200);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (BiliSyncException $e) {
        http_response_code($e->status);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('[bili-sync] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
    }
}

function bili_admin_boot(): void
{
    ini_set('session.use_strict_mode', '1');
    session_name('bili_sync_admin');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
}

function bili_admin_url(string $page): string
{
    $self = $_SERVER['SCRIPT_NAME'] ?? '/sync.php';
    if ($page === '' || $page === 'overview') {
        return $self;
    }
    return $self . '?page=' . rawurlencode($page);
}

function bili_redirect(string $page): never
{
    header('Location: ' . bili_admin_url($page), true, 303);
    exit;
}

function bili_flash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function bili_take_flash(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function bili_authed(): bool
{
    return !empty($_SESSION['admin_ok']);
}

function bili_require_csrf(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    $real = (string) ($_SESSION['csrf'] ?? '');
    if ($real === '' || $sent === '' || !hash_equals($real, $sent)) {
        bili_flash('err', '页面已过期，请刷新后重试');
        bili_redirect((string) ($_POST['back'] ?? 'overview'));
    }
}

function bili_public_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/sync.php';
    return ($https ? 'https' : 'http') . '://' . $host . $script;
}

function bili_fmt_clock(float $seconds): string
{
    $seconds = max(0, (int) round($seconds));
    if ($seconds >= 3600) {
        return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
    return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function bili_fmt_time(int $ms): string
{
    return date('m-d H:i:s', (int) floor($ms / 1000));
}

function bili_posted_db(?array $cfg): array
{
    $pass = (string) ($_POST['db_pass'] ?? '');
    if ($pass === '') {
        $pass = (string) ($cfg['db']['pass'] ?? ($_SESSION['db_draft']['pass'] ?? ''));
    }
    $db = [
        'host' => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
        'port' => (int) ($_POST['db_port'] ?? 3306),
        'name' => trim((string) ($_POST['db_name'] ?? 'bili_sync')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => $pass,
        'prefix' => trim((string) ($_POST['db_prefix'] ?? 'bili_')),
    ];
    bili_validate_db($db);
    $_SESSION['db_draft'] = $db;
    return $db;
}

function bili_clamp_options(array $input): array
{
    $spec = [
        'max_members' => [20, 2, 50],
        'offline_seconds' => [12, 5, 120],
        'member_timeout_seconds' => [180, 30, 3600],
        'room_idle_minutes' => [360, 10, 10080],
        'driver_timeout_seconds' => [8, 3, 60],
    ];
    $options = [];
    foreach ($spec as $key => [$default, $min, $max]) {
        $value = (int) ($input[$key] ?? $default);
        $options[$key] = max($min, min($max, $value));
    }
    return $options;
}

function bili_admin_post(?array $cfg, bool $installed): void
{
    bili_require_csrf();
    $form = (string) ($_POST['form'] ?? '');
    try {
        if ($form === 'login') {
            if (!$installed || !is_array($cfg)) {
                throw new InvalidArgumentException('请先完成初始化');
            }
            $fails = $_SESSION['login_fails'] ?? ['n' => 0, 't' => 0];
            if (($fails['n'] ?? 0) >= 8 && (time() - (int) $fails['t']) < 300) {
                throw new InvalidArgumentException('尝试次数过多，请五分钟后再试');
            }
            $user = trim((string) ($_POST['admin_user'] ?? ''));
            $pass = (string) ($_POST['admin_pass'] ?? '');
            $ok = hash_equals((string) $cfg['admin']['user'], $user)
                && password_verify($pass, (string) $cfg['admin']['pass_hash']);
            if (!$ok) {
                $fails['n'] = (int) ($fails['n'] ?? 0) + 1;
                $fails['t'] = time();
                $_SESSION['login_fails'] = $fails;
                throw new InvalidArgumentException('账号或密码不正确');
            }
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            unset($_SESSION['login_fails']);
            bili_flash('ok', '已登录');
            bili_redirect('overview');
        }

        if ($form === 'install') {
            if ($installed) {
                throw new InvalidArgumentException('已经初始化过了');
            }
            $db = bili_posted_db(null);
            $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
            $adminPass = (string) ($_POST['admin_pass'] ?? '');
            $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $adminUser)) {
                throw new InvalidArgumentException('管理员账号请用 3 到 32 位字母、数字或下划线');
            }
            if (strlen($adminPass) < 6) {
                throw new InvalidArgumentException('管理员密码至少 6 位');
            }
            if (!hash_equals($adminPass, $adminPass2)) {
                throw new InvalidArgumentException('两次输入的管理员密码不一致');
            }
            $next = [
                'installed' => true,
                'db' => $db,
                'admin' => ['user' => $adminUser, 'pass_hash' => password_hash($adminPass, PASSWORD_DEFAULT)],
                'options' => bili_default_options(),
            ];
            $message = bili_test_connection($next);
            bili_save_config($next);
            unset($_SESSION['db_draft']);
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            bili_flash('ok', $message . '。初始化完成');
            bili_redirect('overview');
        }

        if (!bili_authed() || !is_array($cfg)) {
            throw new InvalidArgumentException('请先登录');
        }

        if ($form === 'logout') {
            $_SESSION = [];
            session_destroy();
            session_start();
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            bili_flash('ok', '已退出');
            bili_redirect('login');
        }

        if ($form === 'test-db' || $form === 'save-db') {
            $db = bili_posted_db($cfg);
            $next = $cfg;
            $next['db'] = $db;
            $next['installed'] = true;
            $message = bili_test_connection($next);
            if ($form === 'save-db') {
                bili_save_config($next);
                unset($_SESSION['db_draft']);
                bili_flash('ok', $message . '，配置已保存');
            } else {
                bili_flash('ok', $message);
            }
            bili_redirect('database');
        }

        if ($form === 'save-options') {
            $cfg['options'] = bili_clamp_options($_POST);
            bili_save_config($cfg);
            bili_flash('ok', '参数已保存');
            bili_redirect('options');
        }

        if ($form === 'save-account') {
            $current = (string) ($_POST['current_pass'] ?? '');
            $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
            $adminPass = (string) ($_POST['admin_pass'] ?? '');
            $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
            if (!password_verify($current, (string) $cfg['admin']['pass_hash'])) {
                throw new InvalidArgumentException('当前密码不正确');
            }
            if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $adminUser)) {
                throw new InvalidArgumentException('管理员账号请用 3 到 32 位字母、数字或下划线');
            }
            $cfg['admin']['user'] = $adminUser;
            if ($adminPass !== '' || $adminPass2 !== '') {
                if (strlen($adminPass) < 6) {
                    throw new InvalidArgumentException('新密码至少 6 位');
                }
                if (!hash_equals($adminPass, $adminPass2)) {
                    throw new InvalidArgumentException('两次输入的新密码不一致');
                }
                $cfg['admin']['pass_hash'] = password_hash($adminPass, PASSWORD_DEFAULT);
            }
            bili_save_config($cfg);
            bili_flash('ok', '管理员账号已更新');
            bili_redirect('account');
        }

        if ($form === 'delete-room') {
            $roomId = bili_room_code($_POST['room_id'] ?? '');
            $pdo = bili_pdo_ready($cfg);
            $pdo->prepare('DELETE FROM ' . bili_table($cfg, 'rooms') . ' WHERE id = ?')->execute([$roomId]);
            bili_flash('ok', '已删除房间 ' . $roomId);
            bili_redirect('rooms');
        }

        if ($form === 'repair') {
            $pdo = bili_connect($cfg);
            bili_ensure_schema($pdo, $cfg);
            bili_flash('ok', '已检查并补齐数据表');
            bili_redirect('overview');
        }

        throw new InvalidArgumentException('未知操作');
    } catch (InvalidArgumentException $e) {
        bili_flash('err', $e->getMessage());
        bili_redirect((string) ($_POST['back'] ?? 'overview'));
    } catch (PDOException $e) {
        error_log('[bili-sync] admin ' . $e->getMessage());
        bili_flash('err', '数据库操作失败：' . $e->getMessage());
        bili_redirect((string) ($_POST['back'] ?? 'overview'));
    } catch (RuntimeException $e) {
        bili_flash('err', $e->getMessage());
        bili_redirect((string) ($_POST['back'] ?? 'overview'));
    }
}

function bili_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . bili_e((string) $_SESSION['csrf']) . '">';
}

function bili_db_form(?array $cfg, bool $install): string
{
    $draft = $_SESSION['db_draft'] ?? [];
    $db = is_array($cfg) ? $cfg['db'] : [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'bili_sync',
        'user' => '',
        'prefix' => 'bili_',
    ];
    foreach (['host', 'port', 'name', 'user', 'prefix'] as $key) {
        if (isset($draft[$key]) && $draft[$key] !== '') {
            $db[$key] = $draft[$key];
        }
    }
    $passwordHint = is_array($cfg) ? '留空则保持原密码' : '数据库密码';
    $html = '<div class="grid">';
    $html .= bili_field('数据库主机', 'db_host', (string) $db['host'], '127.0.0.1 走 TCP，localhost 往往走本机套接字');
    $html .= bili_field('端口', 'db_port', (string) $db['port'], '默认 3306', 'number');
    $html .= bili_field('数据库名', 'db_name', (string) $db['name'], '不存在时会尝试自动创建');
    $html .= bili_field('用户名', 'db_user', (string) $db['user'], '');
    $html .= bili_field('密码', 'db_pass', '', $passwordHint, 'password');
    $html .= bili_field('表前缀', 'db_prefix', (string) $db['prefix'], '改前缀会新建一组表，旧房间不会跟着迁移');
    $html .= '</div>';
    if ($install) {
        $html .= '<h2>管理员</h2><div class="grid">';
        $html .= bili_field('管理员账号', 'admin_user', 'admin', '3 到 32 位字母、数字或下划线');
        $html .= bili_field('管理员密码', 'admin_pass', '', '至少 6 位', 'password');
        $html .= bili_field('再输入一次密码', 'admin_pass2', '', '', 'password');
        $html .= '</div>';
    }
    return $html;
}

function bili_field(string $label, string $name, string $value, string $hint, string $type = 'text'): string
{
    $html = '<label><span>' . bili_e($label) . '</span>';
    $html .= '<input name="' . bili_e($name) . '" type="' . bili_e($type) . '" value="' . bili_e($value) . '"';
    if ($type === 'number') {
        $html .= ' min="1" max="65535"';
    }
    $html .= ' autocomplete="' . ($type === 'password' ? 'new-password' : 'off') . '">';
    if ($hint !== '') {
        $html .= '<small>' . bili_e($hint) . '</small>';
    }
    $html .= '</label>';
    return $html;
}

function bili_layout(string $title, string $body, bool $authed): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; script-src 'unsafe-inline'");
    $flash = bili_take_flash();
    $nav = '';
    if ($authed) {
        $current = (string) ($_GET['page'] ?? 'overview');
        if ($current === '') {
            $current = 'overview';
        }
        foreach ([
            'overview' => '概览',
            'database' => '数据库',
            'options' => '参数',
            'rooms' => '房间',
            'account' => '账号',
        ] as $page => $label) {
            $class = $page === $current ? ' class="on"' : '';
            $nav .= '<a' . $class . ' href="' . bili_e(bili_admin_url($page)) . '">' . bili_e($label) . '</a>';
        }
        $nav .= '<form method="post" action="' . bili_e(bili_admin_url('overview')) . '">'
            . bili_csrf_field()
            . '<input type="hidden" name="form" value="logout">'
            . '<input type="hidden" name="back" value="login">'
            . '<button type="submit" class="link">退出</button></form>';
    }
    $flashHtml = '';
    if ($flash) {
        $flashHtml = '<div class="flash ' . ($flash['type'] === 'ok' ? 'ok' : 'err') . '">' . bili_e((string) $flash['text']) . '</div>';
    }
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex">';
    echo '<title>' . bili_e($title) . '</title>';
    echo '<style>
      :root { color-scheme: light; --ink:#1c2430; --muted:#667085; --line:#e4e7ec; --card:#fff; --bg:#f3f5f8; --accent:#00a1d6; --ok:#067647; --err:#b42318; }
      * { box-sizing: border-box; }
      body { margin:0; font:15px/1.5 "Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; color:var(--ink); background:var(--bg); }
      header { display:flex; gap:24px; align-items:center; justify-content:space-between; padding:16px 28px; background:#fff; border-bottom:1px solid var(--line); }
      .brand { font-weight:700; letter-spacing:.02em; }
      .brand small { display:block; font-weight:500; color:var(--muted); letter-spacing:0; }
      nav { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
      nav a, nav button.link { color:var(--muted); text-decoration:none; background:transparent; border:0; padding:6px 10px; border-radius:999px; font:inherit; cursor:pointer; }
      nav a.on, nav a:hover { background:#e8f7fc; color:var(--accent); }
      main { max-width:920px; margin:0 auto; padding:28px 20px 64px; }
      h1 { font-size:28px; margin:0 0 8px; }
      h2 { font-size:18px; margin:24px 0 12px; }
      p.lead { margin:0 0 20px; color:var(--muted); }
      .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px; margin-top:16px; }
      .grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 16px; }
      label { display:flex; flex-direction:column; gap:6px; font-size:14px; }
      label span { font-weight:600; }
      input { font:inherit; padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:#fff; color:var(--ink); }
      input:focus { outline:2px solid #b9e6f6; border-color:var(--accent); }
      small { color:var(--muted); }
      .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
      button, .btn { border:0; border-radius:999px; padding:10px 16px; font:inherit; cursor:pointer; background:var(--accent); color:#fff; text-decoration:none; display:inline-block; }
      button.secondary, .btn.secondary { background:#eef2f6; color:var(--ink); }
      button.danger { background:#fde8e6; color:var(--err); }
      .flash { padding:12px 14px; border-radius:12px; margin-bottom:16px; }
      .flash.ok { background:#e8f7ee; color:var(--ok); }
      .flash.err { background:#fde8e6; color:var(--err); }
      .kv { display:grid; grid-template-columns:140px 1fr; gap:8px 12px; }
      .kv div { padding:6px 0; border-bottom:1px solid var(--line); }
      code, .mono { font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; }
      table { width:100%; border-collapse:collapse; }
      th, td { text-align:left; padding:10px 8px; border-bottom:1px solid var(--line); vertical-align:top; font-size:14px; }
      .steps { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
      .step { background:#f8fafc; border-radius:12px; padding:12px; }
      .step b { display:block; margin-bottom:4px; }
      @media (max-width:720px) { .grid, .steps, .kv { grid-template-columns:1fr; } header { align-items:flex-start; flex-direction:column; } }
    </style></head><body>';
    echo '<header><div class="brand">哔哩同步<small>房间进度后端 ' . bili_e(BILI_SYNC_VERSION) . '</small></div><nav>' . $nav . '</nav></header>';
    echo '<main><h1>' . bili_e($title) . '</h1>' . $flashHtml . $body . '</main>';
    echo '<script>
      document.querySelectorAll("[data-copy]").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var text = btn.getAttribute("data-copy") || "";
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { btn.textContent = "已复制"; });
          } else { btn.textContent = "请手动复制"; }
        });
      });
    </script></body></html>';
}

function bili_page_install(): void
{
    $driver = extension_loaded('pdo_mysql') ? '' : '<div class="flash err">当前 PHP 没有 pdo_mysql 扩展，装好之后才能保存配置。</div>';
    $body = $driver . '<p class="lead">第一次使用。填好 MySQL 和管理员账号后，会自动建库建表。配置保存在 sync.php 旁边的 bili-sync-private 目录，不会写进页面源码。</p>';
    $body .= '<form class="card" method="post" action="' . bili_e(bili_admin_url('overview')) . '">';
    $body .= bili_csrf_field();
    $body .= '<input type="hidden" name="form" value="install"><input type="hidden" name="back" value="overview">';
    $body .= bili_db_form(null, true);
    $body .= '<div class="actions"><button type="submit">保存并初始化</button></div></form>';
    bili_layout('初始化', $body, false);
}

function bili_page_login(): void
{
    $body = '<p class="lead">管理页用来配置 MySQL、查看房间。视频同步接口不需要登录这个账号。</p>';
    $body .= '<form class="card" method="post" action="' . bili_e(bili_admin_url('login')) . '">';
    $body .= bili_csrf_field();
    $body .= '<input type="hidden" name="form" value="login"><input type="hidden" name="back" value="login">';
    $body .= '<div class="grid">';
    $body .= bili_field('管理员账号', 'admin_user', '', '');
    $body .= bili_field('密码', 'admin_pass', '', '', 'password');
    $body .= '</div><div class="actions"><button type="submit">登录</button></div></form>';
    bili_layout('登录', $body, false);
}

function bili_page_overview(array $cfg): void
{
    $dbText = '未连接';
    $dbClass = 'err';
    $roomCount = 0;
    $memberCount = 0;
    try {
        $pdo = bili_pdo_ready($cfg);
        $pdo->query('SELECT 1');
        $dbText = '已连接 ' . $cfg['db']['user'] . '@' . $cfg['db']['host'] . ':' . $cfg['db']['port'] . '/' . $cfg['db']['name'];
        $dbClass = 'ok';
        $roomCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . bili_table($cfg, 'rooms'))->fetchColumn();
        $memberCount = (int) $pdo->query('SELECT COUNT(*) FROM ' . bili_table($cfg, 'members'))->fetchColumn();
    } catch (Throwable $e) {
        $dbText = $e->getMessage();
    }
    $endpoint = bili_public_base();
    $body = '<p class="lead">把下面的接口地址填进油猴脚本。播放进度默认跟着房主，房主可以授权某个成员调整进度或更换视频。</p>';
    $body .= '<div class="card"><div class="kv">';
    $body .= '<div>接口地址</div><div><span class="mono">' . bili_e($endpoint) . '</span> <button type="button" class="secondary" data-copy="' . bili_e($endpoint) . '">复制</button></div>';
    $body .= '<div>数据库</div><div class="' . $dbClass . '">' . bili_e($dbText) . '</div>';
    $body .= '<div>房间 / 成员</div><div>' . $roomCount . ' 个房间，' . $memberCount . ' 名成员</div>';
    $body .= '<div>表前缀</div><div class="mono">' . bili_e((string) $cfg['db']['prefix']) . '</div>';
    $body .= '</div>';
    $body .= '<form method="post" action="' . bili_e(bili_admin_url('overview')) . '" class="actions">';
    $body .= bili_csrf_field();
    $body .= '<input type="hidden" name="form" value="repair"><input type="hidden" name="back" value="overview">';
    $body .= '<button class="secondary" type="submit">修复数据表</button></form></div>';
    $body .= '<div class="steps">';
    $body .= '<div class="step"><b>1. 安装脚本</b>在 Tampermonkey 里新建脚本，粘贴 bilibili-sync.user.js。</div>';
    $body .= '<div class="step"><b>2. 填写地址</b>打开任意哔哩哔哩视频，把接口地址填进面板并保存。</div>';
    $body .= '<div class="step"><b>3. 建房一起看</b>房主创建房间，把 6 位房号发给朋友。默认同步房主的视频和进度。</div>';
    $body .= '</div>';
    bili_layout('概览', $body, true);
}

function bili_page_database(array $cfg): void
{
    $body = '<p class="lead">MySQL 账号需要能读写目标库。如果账号有建库权限，不存在的数据库会自动创建。</p>';
    $body .= '<form class="card" method="post">';
    $body .= bili_csrf_field();
    $body .= '<input type="hidden" name="back" value="database">';
    $body .= bili_db_form($cfg, false);
    $body .= '<div class="actions">';
    $body .= '<button type="submit" name="form" value="test-db" class="secondary">测试连接</button>';
    $body .= '<button type="submit" name="form" value="save-db">保存</button>';
    $body .= '</div></form>';
    bili_layout('数据库', $body, true);
}

function bili_page_options(array $cfg): void
{
    $options = $cfg['options'];
    $fields = [
        ['max_members', '每个房间人数上限', '2 到 50'],
        ['offline_seconds', '多久没心跳算离线（秒）', '只影响管理页和脚本里的在线状态，不会立刻移出'],
        ['member_timeout_seconds', '多久没心跳就移出房间（秒）', '房主被移出后，房主会交给最近还在线的成员'],
        ['room_idle_minutes', '房间空闲多久后删除（分钟）', '有人轮询就不会算空闲'],
        ['driver_timeout_seconds', '控制者失联后交还房主（秒）', '被授权的成员停发进度后，同步源回到房主'],
    ];
    $body = '<p class="lead">这些值会立刻作用在新的同步请求上。人数上限只限制之后的加入。</p><form class="card" method="post">';
    $body .= bili_csrf_field();
    $body .= '<input type="hidden" name="form" value="save-options"><input type="hidden" name="back" value="options"><div class="grid">';
    foreach ($fields as [$name, $label, $hint]) {
        $body .= bili_field($label, $name, (string) ($options[$name] ?? ''), $hint, 'number');
    }
    $body .= '</div><div class="actions"><button type="submit">保存参数</button></div></form>';
    bili_layout('参数', $body, true);
}

function bili_page_rooms(array $cfg): void
{
    $rows = '';
    try {
        $pdo = bili_pdo_ready($cfg);
        $sql = 'SELECT r.*, (SELECT COUNT(*) FROM ' . bili_table($cfg, 'members') . ' m WHERE m.room_id = r.id) AS member_count,
            (SELECT name FROM ' . bili_table($cfg, 'members') . ' h WHERE h.id = r.host_member_id) AS host_name
            FROM ' . bili_table($cfg, 'rooms') . ' r ORDER BY r.updated_at DESC LIMIT 100';
        foreach ($pdo->query($sql) as $row) {
            $room = bili_hydrate_room($row);
            $title = $room['video_title'] !== '' ? $room['video_title'] : ($room['video_key'] !== '' ? $room['video_key'] : '还没选视频');
            $rows .= '<tr><td class="mono">' . bili_e($room['id']) . '</td>';
            $rows .= '<td>' . bili_e((string) ($row['host_name'] ?? '')) . '</td>';
            $rows .= '<td>' . (int) $row['member_count'] . '</td>';
            $rows .= '<td>' . bili_e($title) . '<br><small>' . ($room['playing'] ? '播放中' : '暂停') . ' ' . bili_e(bili_fmt_clock($room['play_time'])) . '</small></td>';
            $rows .= '<td>' . bili_e(bili_fmt_time($room['updated_at'])) . '</td><td>';
            $rows .= '<form method="post" onsubmit="return confirm(\'删除房间 ' . bili_e($room['id']) . '？\');">';
            $rows .= bili_csrf_field();
            $rows .= '<input type="hidden" name="form" value="delete-room"><input type="hidden" name="back" value="rooms">';
            $rows .= '<input type="hidden" name="room_id" value="' . bili_e($room['id']) . '">';
            $rows .= '<button class="danger" type="submit">删除</button></form></td></tr>';
        }
    } catch (Throwable $e) {
        $rows = '<tr><td colspan="6">' . bili_e($e->getMessage()) . '</td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="6">还没有房间。在哔哩哔哩里用脚本创建。</td></tr>';
    }
    $body = '<p class="lead">这里可以清掉不需要的房间。成员权限在视频页的脚本面板里由房主设置。</p>';
    $body .= '<div class="card" style="padding:8px 12px; overflow:auto"><table><thead><tr><th>房号</th><th>房主</th><th>人数</th><th>视频</th><th>最近活动</th><th></th></tr></thead><tbody>';
    $body .= $rows;
    $body .= '</tbody></table></div>';
    bili_layout('房间', $body, true);
}

function bili_page_account(array $cfg): void
{
    $body = '<p class="lead">改密码时要填当前密码。新密码留空表示只改账号名。</p>';
    $body .= '<form class="card" method="post">';
    $body .= bili_csrf_field();
    $body .= '<input type="hidden" name="form" value="save-account"><input type="hidden" name="back" value="account"><div class="grid">';
    $body .= bili_field('当前密码', 'current_pass', '', '', 'password');
    $body .= bili_field('管理员账号', 'admin_user', (string) $cfg['admin']['user'], '');
    $body .= bili_field('新密码', 'admin_pass', '', '留空则不修改', 'password');
    $body .= bili_field('再输入一次新密码', 'admin_pass2', '', '', 'password');
    $body .= '</div><div class="actions"><button type="submit">保存账号</button></div></form>';
    bili_layout('账号', $body, true);
}

function bili_admin_render(?array $cfg, bool $installed): void
{
    if (!$installed || !is_array($cfg) || empty($cfg['installed'])) {
        bili_page_install();
        return;
    }
    if (!bili_authed()) {
        bili_page_login();
        return;
    }
    $page = (string) ($_GET['page'] ?? 'overview');
    match ($page) {
        'database' => bili_page_database($cfg),
        'options' => bili_page_options($cfg),
        'rooms' => bili_page_rooms($cfg),
        'account' => bili_page_account($cfg),
        'login' => bili_redirect('overview'),
        default => bili_page_overview($cfg),
    };
}

function bili_admin_main(): void
{
    bili_admin_boot();
    $cfg = null;
    try {
        $cfg = bili_load_config();
    } catch (Throwable $e) {
        error_log('[bili-sync] config ' . $e->getMessage());
    }
    $installed = is_array($cfg) && !empty($cfg['installed']);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        bili_admin_post($cfg, $installed);
        return;
    }
    bili_admin_render($cfg, $installed);
}

function bili_http_main(): void
{
    date_default_timezone_set('Asia/Shanghai');
    $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
    if ($action === '') {
        bili_admin_main();
        return;
    }
    bili_api_main($action);
}

function bili_unit_tests(): int
{
    $failed = 0;
    $check = static function (bool $ok, string $message) use (&$failed): void {
        if ($ok) {
            return;
        }
        $failed++;
        fwrite(STDERR, "FAIL {$message}\n");
    };
    $path = __DIR__ . '/tests/canonical-fixtures.json';
    $fixtures = json_decode((string) file_get_contents($path), true);
    $check(is_array($fixtures), 'fixtures json');
    foreach ($fixtures as $index => $case) {
        try {
            $got = bili_canonical_video($case['url'], $case['title']);
        } catch (InvalidArgumentException) {
            $got = null;
        }
        $check($got == $case['expect'], 'fixture ' . $index . ' got ' . json_encode($got, JSON_UNESCAPED_UNICODE));
    }
    $long = str_repeat('测', 81);
    $cut = bili_canonical_video('https://www.bilibili.com/video/BV1xx411c7mD', $long);
    $check(bili_strlen($cut['title']) === 80, 'title length');
    $check(bili_norm_name("  \n小明  ", false) === '小明', 'name trim');
    $check(bili_strlen(bili_norm_name(str_repeat('名', 20), false)) === 16, 'name length');
    [$time, $playing, $rate] = bili_norm_playback(['time' => 12.3456, 'playing' => 1, 'rate' => 9]);
    $check($time === 12.346 && $playing === true && $rate === 1.0, 'playback normalize');
    $room = ['host_member_id' => 'host', 'allow_all_control' => false, 'allow_all_video' => true];
    $host = ['id' => 'host', 'can_control' => false, 'can_change_video' => false];
    $guest = ['id' => 'guest', 'can_control' => true, 'can_change_video' => false];
    $check(bili_effective_control($room, $host) && bili_effective_video($room, $guest) && !bili_effective_control(['host_member_id' => 'host', 'allow_all_control' => false], ['id' => 'other', 'can_control' => false]), 'permissions');
    $thrown = false;
    try {
        bili_room_code('ABCO12');
    } catch (InvalidArgumentException) {
        $thrown = true;
    }
    $check($thrown, 'room code rejects O');
    $check(preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', bili_room_id()) === 1, 'generated room id');
    echo $failed === 0 ? "unit ok\n" : "unit failed {$failed}\n";
    return $failed === 0 ? 0 : 1;
}

if (PHP_SAPI === 'cli') {
    $command = $argv[1] ?? '';
    if ($command === 'unit') {
        exit(bili_unit_tests());
    }
    fwrite(STDERR, "请通过 Web 服务器访问 sync.php。\n单元测试：php sync.php unit\n");
    exit($command === '' ? 1 : 1);
}

bili_http_main();
