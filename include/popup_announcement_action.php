<?php
session_start();
error_reporting(0);

require_once __DIR__ . '/acl.php';
requireLogin('../admin.php?id=login');
ensureRole();
if (isOperator() && !operator_can('ann_manage')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Akses ditolak. Izin popup diperlukan.']);
    exit;
}
require_once __DIR__ . '/popup_announcement.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($method === 'POST') {
    $csrf = $_SESSION['csrf_token'] ?? '';
    $token = (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($csrf === '' || $token === '' || !hash_equals($csrf, $token)) {
        echo json_encode(['ok' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }
}

try {
    if ($method === 'GET') {
        if ($action === 'list') {
            $items = popup_announcement_list();
            echo json_encode(['ok' => true, 'data' => $items]);
            exit;
        }
        if ($action === 'get') {
            $id = (int)($_GET['id'] ?? 0);
            $data = $id > 0 ? popup_announcement_get_by_id($id) : popup_announcement_defaults();
            echo json_encode(['ok' => true, 'data' => $data]);
            exit;
        }
    }

    if ($method === 'POST') {
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                popup_announcement_delete($id);
            }
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                popup_announcement_activate($id);
            }
            echo json_encode(['ok' => true]);
            exit;
        }
        if ($action === 'save' || $action === '') {
            $data = [
                'id' => isset($_POST['id']) ? (int)$_POST['id'] : 0,
                'enabled' => isset($_POST['enabled']) ? (int)$_POST['enabled'] : 0,
                'auto_show' => isset($_POST['auto_show']) ? (int)$_POST['auto_show'] : 0,
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? '',
                'repeat_type' => $_POST['repeat_type'] ?? 'none',
                'repeat_value' => $_POST['repeat_value'] ?? 0,
                'title' => $_POST['title'] ?? '',
                'message' => $_POST['message'] ?? '',
                'image_url' => $_POST['image_url'] ?? '',
                'link_label' => $_POST['link_label'] ?? '',
                'link_url' => $_POST['link_url'] ?? '',
                'start_time' => $_POST['start_time'] ?? '',
                'end_time' => $_POST['end_time'] ?? '',
                'button_label' => $_POST['button_label'] ?? '',
                'level' => $_POST['level'] ?? 'info'
            ];
            $saved = popup_announcement_save($data);
            echo json_encode(['ok' => true, 'data' => $saved]);
            exit;
        }
    }
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Gagal memproses data.']);
    exit;
}

echo json_encode(['ok' => false, 'message' => 'Permintaan tidak dikenali.']);
