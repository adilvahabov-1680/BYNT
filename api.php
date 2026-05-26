<?php
/**
 * BY.NT CRM API v2.0
 * JSON-fayl əsaslı saxlama API-si (cPanel hosting üçün).
 *
 * Quraşdırma:
 *   1) Bu faylı yükləyin: /public_html/bynt/api.php
 *   2) /public_html/bynt/data/ qovluğunu yaradın (chmod 755)
 *   3) .htaccess ilə data/ qovluğuna birbaşa girişi bağlayın
 *
 * Endpointlər:
 *   GET  ?action=ping&token=...           → {"ok":true}
 *   GET  ?action=get&key=orders&token=... → [data array]
 *   POST ?action=set&key=orders&token=... → {"ok":true,"count":N}
 */

define('API_TOKEN', 'bynt_g0ldexpr_2024');
define('DATA_DIR',  __DIR__ . '/data');
define('BACKUP_DIR', __DIR__ . '/data/backups');
define('ALLOWED_KEYS', ['orders', 'trans', 'staff', 'expenses', 'logs', 'boot']);
define('MAX_SIZE', 50 * 1024 * 1024);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!is_dir(DATA_DIR))   mkdir(DATA_DIR, 0755, true);
if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0755, true);

// .htaccess for data protection
$htaccess = DATA_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Deny from all\n");
}

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token !== API_TOKEN) {
    http_response_code(403);
    die(json_encode(['error' => 'Invalid token']));
}

$action = $_GET['action'] ?? '';
$key    = $_GET['key'] ?? '';

// PING
if ($action === 'ping') {
    die(json_encode(['ok' => true, 'time' => date('c'), 'version' => '2.0']));
}

if (!in_array($key, ALLOWED_KEYS)) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid key']));
}

$file = DATA_DIR . '/' . $key . '.json';
$lockFile = DATA_DIR . '/.' . $key . '.lock';
$journalFile = DATA_DIR . '/write_journal.log';

// GET
if ($action === 'get') {
    if (!file_exists($file)) { die('[]'); }
    $fh = @fopen($file, 'rb');
    if (!$fh) { die('[]'); }
    $raw = '';
    if (@flock($fh, LOCK_SH)) {
        $raw = stream_get_contents($fh);
        @flock($fh, LOCK_UN);
    } else {
        $raw = stream_get_contents($fh);
    }
    @fclose($fh);
    $d = json_decode($raw, true);
    die(json_encode(is_array($d) ? $d : []));
}

// SET
if ($action === 'set') {
    $input = file_get_contents('php://input');
    if (strlen($input) > MAX_SIZE) {
        http_response_code(413);
        die(json_encode(['error' => 'Too large']));
    }
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        die(json_encode(['error' => 'Invalid JSON']));
    }

    $lockHandle = @fopen($lockFile, 'c+');
    if (!$lockHandle) {
        http_response_code(500);
        die(json_encode(['error' => 'Lock open failed']));
    }
    if (!@flock($lockHandle, LOCK_EX)) {
        @fclose($lockHandle);
        http_response_code(500);
        die(json_encode(['error' => 'Lock acquire failed']));
    }

    try {
        // Backup (unique name even under high write frequency)
        if (file_exists($file)) {
            $stamp = date('Ymd_His') . '_' . substr((string)microtime(true), -6) . '_' . bin2hex(random_bytes(3));
            $bk = BACKUP_DIR . '/' . $key . '_' . $stamp . '.json';
            @copy($file, $bk);
            // Keep last 120 backups (for high turnover environments)
            $bks = glob(BACKUP_DIR . '/' . $key . '_*.json');
            if (count($bks) > 120) {
                usort($bks, fn($a,$b) => filemtime($a) - filemtime($b));
                foreach (array_slice($bks, 0, count($bks) - 120) as $old) @unlink($old);
            }
        }

        // Atomic write
        $tmp = $file . '.tmp';
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        $encoded = json_encode($data, $flags);
        if ($encoded === false) {
            throw new RuntimeException('JSON encode failed');
        }
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Write failed');
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic rename failed');
        }

        $journal = [
            'ts' => date('c'),
            'key' => $key,
            'count' => count($data),
            'size' => strlen($encoded),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 180)
        ];
        @file_put_contents($journalFile, json_encode($journal, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        http_response_code(500);
        die(json_encode(['error' => $e->getMessage()]));
    } finally {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }

    die(json_encode(['ok' => true, 'count' => count($data)]));
}

http_response_code(400);
die(json_encode(['error' => 'Unknown action']));
