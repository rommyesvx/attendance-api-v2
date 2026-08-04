<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

try {
    $stmt = $pdo->prepare("UPDATE user SET api_token = NULL WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);

    sendResponse(200, 'Logout berhasil. Token telah dinonaktifkan.');
} catch (Exception $e) {
    sendResponse(500, 'Gagal memproses logout: ' . $e->getMessage());
}
?>