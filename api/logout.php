<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

try {
    $stmt = $pdo->prepare("UPDATE users SET api_token = NULL WHERE id = ?");
    $stmt->execute([$user['id']]);

    sendResponse(200, 'Logout berhasil. Token telah dinonaktifkan.');
} catch (Exception $e) {
    sendResponse(500, 'Gagal memproses logout: ' . $e->getMessage());
}
?>