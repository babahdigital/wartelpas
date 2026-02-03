<?php
if (function_exists('app_db')) {
    return;
}

function app_db_path()
{
    $root_dir = dirname(__DIR__);
    $env = [];
    $envFile = __DIR__ . '/env.php';
    if (file_exists($envFile)) {
        require $envFile;
    }
    $db_rel = $env['system']['app_db_file'] ?? 'db_data/babahdigital_app.db';
    if (preg_match('/^[A-Za-z]:\\\\|^\//', $db_rel)) {
        $target = $db_rel;
    } else {
        $target = $root_dir . '/' . ltrim($db_rel, '/');
    }

    return $target;
}

function app_db()
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbFile = app_db_path();
    $dir = dirname($dbFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
    app_db_init($pdo);

    return $pdo;
}

function app_db_init(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_account (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS operator_account (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS router_sessions (
        id TEXT PRIMARY KEY,
        iphost TEXT,
        userhost TEXT,
        passwdhost TEXT,
        hotspotname TEXT,
        dnsname TEXT,
        currency TEXT,
        areload TEXT,
        iface TEXT,
        infolp TEXT,
        idleto TEXT,
        livereport TEXT,
        hotspot_server TEXT,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );");

    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_config (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        endpoint_send TEXT,
        token TEXT,
        notify_target TEXT,
        notify_request_enabled INTEGER NOT NULL DEFAULT 1,
        notify_retur_enabled INTEGER NOT NULL DEFAULT 1,
        notify_refund_enabled INTEGER NOT NULL DEFAULT 1,
        country_code TEXT,
        timezone TEXT,
        log_limit INTEGER NOT NULL DEFAULT 50,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );");
}

function app_db_seed_default_admin(PDO $pdo)
{
    $username = 'admin';
    $password = 'admin123';
    if (function_exists('hash_password_value')) {
        $password = hash_password_value($password);
    }
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO admin_account (id, username, password, updated_at) VALUES (1, :u, :p, CURRENT_TIMESTAMP)');
    $stmt->execute([':u' => $username, ':p' => $password]);
}

function app_db_has_any_data(PDO $pdo)
{
    $admin = (int)$pdo->query('SELECT COUNT(*) FROM admin_account')->fetchColumn();
    $sessions = (int)$pdo->query('SELECT COUNT(*) FROM router_sessions')->fetchColumn();
    return ($admin > 0 || $sessions > 0);
}

function app_db_import_legacy_if_needed()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = app_db();
    if (app_db_has_any_data($pdo)) {
        return;
    }

    $legacyFile = __DIR__ . '/config_legacy.php';
    if (is_file($legacyFile)) {
        $legacy_data = [];
        $data = [];
        include $legacyFile;
        if (isset($data) && is_array($data)) {
            $legacy_data = $data;
        }

        foreach ($legacy_data as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($key === 'mikhmon') {
                $username = '';
                $password = '';
                if (isset($row[1])) {
                    $parts = explode('<|<', $row[1]);
                    $username = $parts[1] ?? '';
                }
                if (isset($row[2])) {
                    $parts = explode('>|>', $row[2]);
                    $password = $parts[1] ?? '';
                }
                if ($username !== '' || $password !== '') {
                    app_db_set_admin($username, $password);
                }
                continue;
            }

            $id = (string)$key;
            $iphost = isset($row[1]) ? explode('!', (string)$row[1])[1] ?? '' : '';
            $userhost = isset($row[2]) ? explode('@|@', (string)$row[2])[1] ?? '' : '';
            $passwdhost = isset($row[3]) ? explode('#|#', (string)$row[3])[1] ?? '' : '';
            $hotspotname = isset($row[4]) ? explode('%', (string)$row[4])[1] ?? '' : '';
            $dnsname = isset($row[5]) ? explode('^', (string)$row[5])[1] ?? '' : '';
            $currency = isset($row[6]) ? explode('&', (string)$row[6])[1] ?? '' : '';
            $areload = isset($row[7]) ? explode('*', (string)$row[7])[1] ?? '' : '';
            $iface = isset($row[8]) ? explode('(', (string)$row[8])[1] ?? '' : '';
            $infolp = isset($row[9]) ? explode(')', (string)$row[9])[1] ?? '' : '';
            $idleto = isset($row[10]) ? explode('=', (string)$row[10])[1] ?? '' : '';
            $livereport = isset($row[11]) ? explode('@!@', (string)$row[11])[1] ?? '' : '';
            $hotspot_server = isset($row[12]) ? explode('~', (string)$row[12])[1] ?? '' : '';

            app_db_upsert_session($id, $id, [
                'iphost' => $iphost,
                'userhost' => $userhost,
                'passwdhost' => $passwdhost,
                'hotspotname' => $hotspotname,
                'dnsname' => $dnsname,
                'currency' => $currency,
                'areload' => $areload,
                'iface' => $iface,
                'infolp' => $infolp,
                'idleto' => $idleto,
                'livereport' => $livereport,
                'hotspot_server' => $hotspot_server,
            ]);
        }
    }

    $env = [];
    $envFile = __DIR__ . '/env.php';
    if (file_exists($envFile)) {
        require $envFile;
    }
    $opUser = $env['auth']['operator_user'] ?? '';
    $opPass = $env['auth']['operator_pass'] ?? '';
    if ($opUser !== '' || $opPass !== '') {
        app_db_set_operator($opUser, $opPass);
    }

    $admin = app_db_get_admin();
    if (empty($admin)) {
        app_db_seed_default_admin($pdo);
    }
}

function app_db_get_admin()
{
    $pdo = app_db();
    $stmt = $pdo->query('SELECT username, password FROM admin_account WHERE id = 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function app_db_set_admin($username, $password)
{
    $pdo = app_db();
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO admin_account (id, username, password, updated_at) VALUES (1, :u, :p, CURRENT_TIMESTAMP)');
    $stmt->execute([
        ':u' => (string)$username,
        ':p' => (string)$password,
    ]);
}

function app_db_get_operator()
{
    $pdo = app_db();
    $stmt = $pdo->query('SELECT username, password FROM operator_account WHERE id = 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function app_db_set_operator($username, $password)
{
    $pdo = app_db();
    $stmt = $pdo->prepare('INSERT OR REPLACE INTO operator_account (id, username, password, updated_at) VALUES (1, :u, :p, CURRENT_TIMESTAMP)');
    $stmt->execute([
        ':u' => (string)$username,
        ':p' => (string)$password,
    ]);
}

function app_db_read_whatsapp_env()
{
    $env = [];
    $envFile = __DIR__ . '/env.php';
    if (file_exists($envFile)) {
        require $envFile;
    }
    $wh = $env['whatsapp'] ?? [];
    return is_array($wh) ? $wh : [];
}

function app_db_get_whatsapp_config()
{
    $pdo = app_db();
    $stmt = $pdo->query('SELECT * FROM whatsapp_config WHERE id = 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $fromEnv = app_db_read_whatsapp_env();
        $hasEnv = false;
        foreach ($fromEnv as $val) {
            if ($val !== '' && $val !== null) {
                $hasEnv = true;
                break;
            }
        }
        if ($hasEnv) {
            app_db_set_whatsapp_config($fromEnv);
            $stmt = $pdo->query('SELECT * FROM whatsapp_config WHERE id = 1');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    return [
        'endpoint_send' => isset($row['endpoint_send']) && $row['endpoint_send'] !== '' ? (string)$row['endpoint_send'] : 'https://api.fonnte.com/send',
        'token' => isset($row['token']) ? (string)$row['token'] : '',
        'notify_target' => isset($row['notify_target']) ? (string)$row['notify_target'] : '',
        'notify_request_enabled' => isset($row['notify_request_enabled']) ? (int)$row['notify_request_enabled'] : 1,
        'notify_retur_enabled' => isset($row['notify_retur_enabled']) ? (int)$row['notify_retur_enabled'] : 1,
        'notify_refund_enabled' => isset($row['notify_refund_enabled']) ? (int)$row['notify_refund_enabled'] : 1,
        'country_code' => isset($row['country_code']) && $row['country_code'] !== '' ? (string)$row['country_code'] : '62',
        'timezone' => isset($row['timezone']) && $row['timezone'] !== '' ? (string)$row['timezone'] : 'Asia/Makassar',
        'log_limit' => isset($row['log_limit']) ? (int)$row['log_limit'] : 50,
    ];
}

function app_db_set_whatsapp_config(array $data)
{
    $pdo = app_db();
    $endpoint = trim((string)($data['endpoint_send'] ?? 'https://api.fonnte.com/send'));
    $token = trim((string)($data['token'] ?? ''));
    $notify_target = trim((string)($data['notify_target'] ?? ''));
    $notify_request_enabled = !empty($data['notify_request_enabled']) ? 1 : 0;
    $notify_retur_enabled = !empty($data['notify_retur_enabled']) ? 1 : 0;
    $notify_refund_enabled = !empty($data['notify_refund_enabled']) ? 1 : 0;
    $country_code = trim((string)($data['country_code'] ?? '62'));
    $timezone = trim((string)($data['timezone'] ?? 'Asia/Makassar'));
    $log_limit = isset($data['log_limit']) ? (int)$data['log_limit'] : 50;
    if ($log_limit <= 0) {
        $log_limit = 50;
    }

    $stmt = $pdo->prepare("INSERT OR REPLACE INTO whatsapp_config
        (id, endpoint_send, token, notify_target, notify_request_enabled, notify_retur_enabled, notify_refund_enabled, country_code, timezone, log_limit, updated_at)
        VALUES (1, :endpoint_send, :token, :notify_target, :nreq, :nret, :nref, :country_code, :timezone, :log_limit, CURRENT_TIMESTAMP)");
    $stmt->execute([
        ':endpoint_send' => $endpoint,
        ':token' => $token,
        ':notify_target' => $notify_target,
        ':nreq' => $notify_request_enabled,
        ':nret' => $notify_retur_enabled,
        ':nref' => $notify_refund_enabled,
        ':country_code' => $country_code,
        ':timezone' => $timezone,
        ':log_limit' => $log_limit,
    ]);
}

function app_db_get_sessions()
{
    $pdo = app_db();
    $stmt = $pdo->query('SELECT * FROM router_sessions ORDER BY id ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

function app_db_session_exists($id)
{
    $pdo = app_db();
    $stmt = $pdo->prepare('SELECT 1 FROM router_sessions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (string)$id]);
    return (bool)$stmt->fetchColumn();
}

function app_db_upsert_session($oldId, $newId, array $fields)
{
    $pdo = app_db();
    $oldId = (string)$oldId;
    $newId = (string)$newId;

    $pdo->beginTransaction();
    try {
        if ($oldId !== '' && $newId !== '' && $oldId !== $newId) {
            $exists = app_db_session_exists($newId);
            if ($exists) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'Nama sesi sudah digunakan.'];
            }
            $del = $pdo->prepare('DELETE FROM router_sessions WHERE id = :id');
            $del->execute([':id' => $oldId]);
        }

        $stmt = $pdo->prepare("INSERT OR REPLACE INTO router_sessions
            (id, iphost, userhost, passwdhost, hotspotname, dnsname, currency, areload, iface, infolp, idleto, livereport, hotspot_server, updated_at)
            VALUES
            (:id, :iphost, :userhost, :passwdhost, :hotspotname, :dnsname, :currency, :areload, :iface, :infolp, :idleto, :livereport, :hotspot_server, CURRENT_TIMESTAMP)");

        $stmt->execute([
            ':id' => $newId,
            ':iphost' => $fields['iphost'] ?? '',
            ':userhost' => $fields['userhost'] ?? '',
            ':passwdhost' => $fields['passwdhost'] ?? '',
            ':hotspotname' => $fields['hotspotname'] ?? '',
            ':dnsname' => $fields['dnsname'] ?? '',
            ':currency' => $fields['currency'] ?? '',
            ':areload' => $fields['areload'] ?? '',
            ':iface' => $fields['iface'] ?? '',
            ':infolp' => $fields['infolp'] ?? '',
            ':idleto' => $fields['idleto'] ?? '',
            ':livereport' => $fields['livereport'] ?? '',
            ':hotspot_server' => $fields['hotspot_server'] ?? '',
        ]);

        $pdo->commit();
        return ['ok' => true];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => 'Gagal menyimpan ke database.'];
    }
}

function app_db_delete_session($id)
{
    $pdo = app_db();
    $stmt = $pdo->prepare('DELETE FROM router_sessions WHERE id = :id');
    return $stmt->execute([':id' => (string)$id]);
}

function app_db_first_session_id()
{
    $pdo = app_db();
    $stmt = $pdo->query('SELECT id FROM router_sessions ORDER BY id ASC LIMIT 1');
    $id = $stmt->fetchColumn();
    return is_string($id) ? $id : '';
}

function app_db_load_config()
{
    app_db_import_legacy_if_needed();

    $data = [];
    $admin = app_db_get_admin();
    if (empty($admin)) {
        $pdo = app_db();
        app_db_seed_default_admin($pdo);
        $admin = app_db_get_admin();
    }

    $adminUser = $admin['username'] ?? '';
    $adminPass = $admin['password'] ?? '';
    $data['mikhmon'] = array('1' => 'mikhmon<|<' . $adminUser, 'mikhmon>|>' . $adminPass);

    $rows = app_db_get_sessions();
    foreach ($rows as $row) {
        $id = (string)($row['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $data[$id] = array(
            '1' => $id . '!' . ($row['iphost'] ?? ''),
            $id . '@|@' . ($row['userhost'] ?? ''),
            $id . '#|#' . ($row['passwdhost'] ?? ''),
            $id . '%' . ($row['hotspotname'] ?? ''),
            $id . '^' . ($row['dnsname'] ?? ''),
            $id . '&' . ($row['currency'] ?? ''),
            $id . '*' . ($row['areload'] ?? ''),
            $id . '(' . ($row['iface'] ?? ''),
            $id . ')' . ($row['infolp'] ?? ''),
            $id . '=' . ($row['idleto'] ?? ''),
            $id . '@!@' . ($row['livereport'] ?? ''),
            $id . '~' . ($row['hotspot_server'] ?? ''),
        );
    }

    return $data;
}
