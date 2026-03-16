<?php

require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT 
            user.user_id, 
            user.user_name, 
            user.user_email,
            user.user_nip,
            user.user_alamat,
            user.user_birthday,
            user.user_type, 
            absensi_offices.name as office_name 
        FROM user 
        LEFT JOIN absensi_offices ON user.office_id = absensi_offices.id 
        WHERE user.user_id = ?
    ");
    
    $stmt->execute([$user['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($profile) {
        sendResponse(200, 'Berhasil mengambil data profile', $profile);
    } else {
        sendResponse(404, 'User tidak ditemukan');
    }

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>