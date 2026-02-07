<?php
if (function_exists('popup_announcement_list')) {
    return;
}

function popup_announcement_defaults()
{
    return [
        'id' => 0,
        'enabled' => 0,
        'auto_show' => 1,
        'start_date' => '',
        'end_date' => '',
        'start_time' => '',
        'end_time' => '',
        'repeat_type' => 'none',
        'repeat_value' => 0,
        'title' => 'Informasi',
        'message' => '',
        'image_url' => '',
        'link_label' => '',
        'link_url' => '',
        'button_label' => 'Mengerti',
        'level' => 'info',
        'updated_at' => ''
    ];
}

function popup_announcement_db()
{
    if (!function_exists('app_db')) {
        require_once __DIR__ . '/db.php';
    }
    $db = app_db();
    $db->exec("CREATE TABLE IF NOT EXISTS popup_announcements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        message TEXT,
        image_url TEXT,
        link_label TEXT,
        link_url TEXT,
        button_label TEXT,
        level TEXT,
        auto_show INTEGER NOT NULL DEFAULT 1,
        start_date TEXT,
        end_date TEXT,
        start_time TEXT,
        end_time TEXT,
        repeat_type TEXT,
        repeat_value INTEGER DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    try {
        $cols = $db->query("PRAGMA table_info(popup_announcements)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $colNames = array_map(function ($c) { return $c['name'] ?? ''; }, $cols);
        if (!in_array('image_url', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN image_url TEXT");
        }
        if (!in_array('link_label', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN link_label TEXT");
        }
        if (!in_array('link_url', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN link_url TEXT");
        }
        if (!in_array('start_date', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN start_date TEXT");
        }
        if (!in_array('end_date', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN end_date TEXT");
        }
        if (!in_array('start_time', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN start_time TEXT");
        }
        if (!in_array('end_time', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN end_time TEXT");
        }
        if (!in_array('repeat_type', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN repeat_type TEXT");
        }
        if (!in_array('repeat_value', $colNames, true)) {
            $db->exec("ALTER TABLE popup_announcements ADD COLUMN repeat_value INTEGER DEFAULT 0");
        }
    } catch (Exception $e) {}
    return $db;
}

function popup_announcement_migrate_legacy($db)
{
    try {
        $count = (int)$db->query('SELECT COUNT(*) FROM popup_announcements')->fetchColumn();
        if ($count > 0) return;
        $db->exec("CREATE TABLE IF NOT EXISTS system_settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)");
        $stmt = $db->prepare('SELECT value, updated_at FROM system_settings WHERE key = :k');
        $stmt->execute([':k' => 'popup_announcement']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['value'])) return;
        $decoded = json_decode((string)$row['value'], true);
        if (!is_array($decoded)) return;

        $data = popup_announcement_normalize($decoded);
        $stmt = $db->prepare('INSERT INTO popup_announcements (title, message, image_url, link_label, link_url, button_label, level, auto_show, is_active, created_at, updated_at)
            VALUES (:title, :message, :image_url, :link_label, :link_url, :button_label, :level, :auto_show, :is_active, :created_at, :updated_at)');
        $stmt->execute([
            ':title' => $data['title'],
            ':message' => $data['message'],
            ':image_url' => $data['image_url'],
            ':link_label' => $data['link_label'],
            ':link_url' => $data['link_url'],
            ':button_label' => $data['button_label'],
            ':level' => $data['level'],
            ':auto_show' => $data['auto_show'],
            ':is_active' => $data['enabled'],
            ':created_at' => $data['updated_at'],
            ':updated_at' => $data['updated_at']
        ]);
    } catch (Exception $e) {
    }
}

function popup_announcement_normalize(array $data)
{
    $defaults = popup_announcement_defaults();

    $enabled = !empty($data['enabled']) || !empty($data['is_active']) ? 1 : 0;
    $auto_show = !empty($data['auto_show']) ? 1 : 0;
    $title = trim((string)($data['title'] ?? $defaults['title']));
    $message = trim((string)($data['message'] ?? ''));
    $image_url = trim((string)($data['image_url'] ?? ''));
    $link_label = trim((string)($data['link_label'] ?? ''));
    $link_url = trim((string)($data['link_url'] ?? ''));
    $button_label = trim((string)($data['button_label'] ?? $defaults['button_label']));
    $level = strtolower(trim((string)($data['level'] ?? $defaults['level'])));
    $start_date = popup_announcement_parse_date($data['start_date'] ?? '');
    $end_date = popup_announcement_parse_date($data['end_date'] ?? '');
    $start_time = popup_announcement_parse_time($data['start_time'] ?? '');
    $end_time = popup_announcement_parse_time($data['end_time'] ?? '');
    $repeat_type = strtolower(trim((string)($data['repeat_type'] ?? 'none')));
    $repeat_value = (int)($data['repeat_value'] ?? 0);

    if ($title === '') $title = $defaults['title'];
    if ($button_label === '') $button_label = $defaults['button_label'];
    if (!in_array($level, ['info', 'success', 'warning', 'danger'], true)) {
        $level = $defaults['level'];
    }
    if (!in_array($repeat_type, ['none', 'daily', 'weekly', 'monthly'], true)) {
        $repeat_type = 'none';
    }
    if ($start_time === '') {
        if ($repeat_type !== 'none') {
            $start_time = '00:00';
        } elseif ($start_date !== '') {
            $start_time = date('H:i');
        }
    }
    if ($end_date !== '' && $end_time === '') {
        $end_time = '23:59';
    }
    if ($repeat_type === 'weekly') {
        if ($repeat_value < 1 || $repeat_value > 7) $repeat_value = 1;
    } elseif ($repeat_type === 'monthly') {
        if ($repeat_value < 1 || $repeat_value > 31) $repeat_value = 1;
    } else {
        $repeat_value = 0;
    }

    if (function_exists('mb_substr')) {
        $title = mb_substr($title, 0, 80, 'UTF-8');
        $button_label = mb_substr($button_label, 0, 30, 'UTF-8');
        $message = mb_substr($message, 0, 800, 'UTF-8');
        $image_url = mb_substr($image_url, 0, 300, 'UTF-8');
        $link_label = mb_substr($link_label, 0, 30, 'UTF-8');
        $link_url = mb_substr($link_url, 0, 300, 'UTF-8');
    } else {
        $title = substr($title, 0, 80);
        $button_label = substr($button_label, 0, 30);
        $message = substr($message, 0, 800);
        $image_url = substr($image_url, 0, 300);
        $link_label = substr($link_label, 0, 30);
        $link_url = substr($link_url, 0, 300);
    }

    $image_url = popup_announcement_sanitize_url($image_url, true);
    $link_url = popup_announcement_sanitize_url($link_url, false);

    return [
        'id' => isset($data['id']) ? (int)$data['id'] : 0,
        'enabled' => $enabled,
        'auto_show' => $auto_show,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'repeat_type' => $repeat_type,
        'repeat_value' => $repeat_value,
        'title' => $title,
        'message' => $message,
        'image_url' => $image_url,
        'link_label' => $link_label,
        'link_url' => $link_url,
        'button_label' => $button_label,
        'level' => $level,
        'start_date_dmy' => popup_announcement_format_date_dmy($start_date),
        'end_date_dmy' => popup_announcement_format_date_dmy($end_date),
        'start_time_hm' => $start_time,
        'end_time_hm' => $end_time,
        'updated_at' => date('Y-m-d H:i:s')
    ];
}

function popup_announcement_parse_date($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return '';
}

function popup_announcement_format_date_dmy($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return '';
    $parts = explode('-', $raw);
    return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
}

function popup_announcement_parse_time($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^(\d{2}):(\d{2})$/', $raw, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
            return sprintf('%02d:%02d', $h, $i);
        }
    }
    return '';
}

function popup_announcement_get_base_url()
{
    $base = '';
    if (isset($GLOBALS['env_config']) && is_array($GLOBALS['env_config'])) {
        $base = (string)($GLOBALS['env_config']['system']['base_url'] ?? '');
    }
    if ($base === '') {
        $envFile = __DIR__ . '/env.php';
        if (file_exists($envFile)) {
            require $envFile;
        }
        if ($base === '' && isset($env) && is_array($env)) {
            $base = (string)($env['system']['base_url'] ?? '');
        }
        if ($base === '' && isset($GLOBALS['env_config']) && is_array($GLOBALS['env_config'])) {
            $base = (string)($GLOBALS['env_config']['system']['base_url'] ?? '');
        }
    }
    $base = rtrim($base, '/');
    return $base;
}

function popup_announcement_public_image_url($url)
{
    $url = trim((string)$url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    $base = popup_announcement_get_base_url();
    if ($base === '') return $url;
    if (strpos($url, '/') === 0) {
        return $base . $url;
    }
    return $base . '/' . ltrim($url, '/');
}

function popup_announcement_local_image_path($url)
{
    $url = trim((string)$url);
    if ($url === '') return '';
    $path = $url;
    if (preg_match('#^https?://#i', $url)) {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';
    }
    if ($path === '' || strpos($path, '/img/popup/') !== 0) return '';
    $baseDir = dirname(__DIR__);
    $fullPath = $baseDir . $path;
    return $fullPath;
}

function popup_announcement_sanitize_url($url, $allow_relative)
{
    $url = trim((string)$url);
    if ($url === '') return '';
    if ($allow_relative && strpos($url, '/') === 0) {
        return $url;
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme'])) return '';
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return '';
    return $url;
}

function popup_announcement_is_active_now(array $data)
{
    if (empty($data['enabled'])) return false;
    $today = date('Y-m-d');
    $nowTime = date('H:i');
    $start = (string)($data['start_date'] ?? '');
    $end = (string)($data['end_date'] ?? '');
    $startTime = (string)($data['start_time'] ?? '');
    $endTime = (string)($data['end_time'] ?? '');
    if ($start !== '' && $today < $start) return false;
    if ($end !== '' && $today > $end) return false;
    if ($startTime !== '' && $nowTime < $startTime) return false;
    if ($endTime !== '' && $nowTime > $endTime) return false;

    $repeat = (string)($data['repeat_type'] ?? 'none');
    $repeatValue = (int)($data['repeat_value'] ?? 0);
    if ($repeat === 'daily') return true;
    if ($repeat === 'weekly') {
        $dow = (int)date('N');
        return $repeatValue > 0 ? ($dow === $repeatValue) : true;
    }
    if ($repeat === 'monthly') {
        $dom = (int)date('j');
        return $repeatValue > 0 ? ($dom === $repeatValue) : true;
    }
    return true;
}

function popup_announcement_list()
{
    $db = popup_announcement_db();
    popup_announcement_migrate_legacy($db);
    $stmt = $db->query('SELECT * FROM popup_announcements ORDER BY updated_at DESC, id DESC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $out[] = popup_announcement_normalize($row);
    }
    return $out;
}

function popup_announcement_get_by_id($id)
{
    $db = popup_announcement_db();
    popup_announcement_migrate_legacy($db);
    $stmt = $db->prepare('SELECT * FROM popup_announcements WHERE id = :id');
    $stmt->execute([':id' => (int)$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return popup_announcement_defaults();
    return popup_announcement_normalize($row);
}

function popup_announcement_get_active_public()
{
    $db = popup_announcement_db();
    popup_announcement_migrate_legacy($db);
    $stmt = $db->query('SELECT * FROM popup_announcements WHERE is_active = 1 ORDER BY updated_at DESC, id DESC LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $def = popup_announcement_defaults();
        return [
            'enabled' => 0,
            'auto_show' => (int)$def['auto_show'],
            'id' => 0,
            'title' => $def['title'],
            'message' => $def['message'],
            'image_url' => $def['image_url'],
            'link_label' => $def['link_label'],
            'link_url' => $def['link_url'],
            'button_label' => $def['button_label'],
            'level' => $def['level'],
            'repeat_type' => $def['repeat_type'] ?? 'none'
        ];
    }
    $data = popup_announcement_normalize($row);
    if (!popup_announcement_is_active_now($data)) {
        return [
            'enabled' => 0,
            'auto_show' => (int)$data['auto_show'],
            'id' => (int)($data['id'] ?? 0),
            'title' => (string)$data['title'],
            'message' => (string)$data['message'],
            'image_url' => (string)$data['image_url'],
            'link_label' => (string)$data['link_label'],
            'link_url' => (string)$data['link_url'],
            'button_label' => (string)$data['button_label'],
            'level' => (string)$data['level'],
            'repeat_type' => (string)($data['repeat_type'] ?? 'none')
        ];
    }
    $data['image_url'] = popup_announcement_public_image_url($data['image_url'] ?? '');
    return [
        'enabled' => 1,
        'auto_show' => (int)$data['auto_show'],
        'id' => (int)($data['id'] ?? 0),
        'title' => (string)$data['title'],
        'message' => (string)$data['message'],
        'image_url' => (string)$data['image_url'],
        'link_label' => (string)$data['link_label'],
        'link_url' => (string)$data['link_url'],
        'button_label' => (string)$data['button_label'],
        'level' => (string)$data['level'],
        'repeat_type' => (string)($data['repeat_type'] ?? 'none')
    ];
}

function popup_announcement_save(array $data)
{
    $db = popup_announcement_db();
    popup_announcement_migrate_legacy($db);
    $payload = popup_announcement_normalize($data);
    $now = $payload['updated_at'];

    $db->beginTransaction();
    try {
        if ($payload['enabled']) {
            $db->exec('UPDATE popup_announcements SET is_active = 0');
        }
        if ($payload['id'] > 0) {
            $stmt = $db->prepare('UPDATE popup_announcements SET title = :title, message = :message, image_url = :image_url, link_label = :link_label, link_url = :link_url, button_label = :button_label, level = :level, auto_show = :auto_show, start_date = :start_date, end_date = :end_date, start_time = :start_time, end_time = :end_time, repeat_type = :repeat_type, repeat_value = :repeat_value, is_active = :is_active, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                ':title' => $payload['title'],
                ':message' => $payload['message'],
                ':image_url' => $payload['image_url'],
                ':link_label' => $payload['link_label'],
                ':link_url' => $payload['link_url'],
                ':button_label' => $payload['button_label'],
                ':level' => $payload['level'],
                ':auto_show' => $payload['auto_show'],
                ':start_date' => $payload['start_date'],
                ':end_date' => $payload['end_date'],
                ':start_time' => $payload['start_time'],
                ':end_time' => $payload['end_time'],
                ':repeat_type' => $payload['repeat_type'],
                ':repeat_value' => $payload['repeat_value'],
                ':is_active' => $payload['enabled'],
                ':updated_at' => $now,
                ':id' => $payload['id']
            ]);
        } else {
            $stmt = $db->prepare('INSERT INTO popup_announcements (title, message, image_url, link_label, link_url, button_label, level, auto_show, start_date, end_date, start_time, end_time, repeat_type, repeat_value, is_active, created_at, updated_at)
                VALUES (:title, :message, :image_url, :link_label, :link_url, :button_label, :level, :auto_show, :start_date, :end_date, :start_time, :end_time, :repeat_type, :repeat_value, :is_active, :created_at, :updated_at)');
            $stmt->execute([
                ':title' => $payload['title'],
                ':message' => $payload['message'],
                ':image_url' => $payload['image_url'],
                ':link_label' => $payload['link_label'],
                ':link_url' => $payload['link_url'],
                ':button_label' => $payload['button_label'],
                ':level' => $payload['level'],
                ':auto_show' => $payload['auto_show'],
                ':start_date' => $payload['start_date'],
                ':end_date' => $payload['end_date'],
                ':start_time' => $payload['start_time'],
                ':end_time' => $payload['end_time'],
                ':repeat_type' => $payload['repeat_type'],
                ':repeat_value' => $payload['repeat_value'],
                ':is_active' => $payload['enabled'],
                ':created_at' => $now,
                ':updated_at' => $now
            ]);
            $payload['id'] = (int)$db->lastInsertId();
        }
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

    return $payload;
}

function popup_announcement_delete($id)
{
    $db = popup_announcement_db();
    popup_announcement_migrate_legacy($db);
    $stmt = $db->prepare('SELECT image_url FROM popup_announcements WHERE id = :id');
    $stmt->execute([':id' => (int)$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $db->prepare('DELETE FROM popup_announcements WHERE id = :id');
    $stmt->execute([':id' => (int)$id]);
    if (!empty($row['image_url'])) {
        $localPath = popup_announcement_local_image_path($row['image_url']);
        if ($localPath !== '' && is_file($localPath)) {
            @unlink($localPath);
        }
    }
    return true;
}

function popup_announcement_activate($id)
{
    $db = popup_announcement_db();
    popup_announcement_migrate_legacy($db);
    $db->beginTransaction();
    try {
        $db->exec('UPDATE popup_announcements SET is_active = 0');
        $stmt = $db->prepare('UPDATE popup_announcements SET is_active = 1, updated_at = :t WHERE id = :id');
        $stmt->execute([':id' => (int)$id, ':t' => date('Y-m-d H:i:s')]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    return true;
}
