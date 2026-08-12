<?php

require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT 
            user_id, 
            user_name, 
            user_email,
            user_nip,
            user_alamat,
            user_birthday,
            user_type,
            user_jabatan,
            office_id
        FROM user 
        WHERE user_id = ?
    ");
    
    $stmt->execute([$user['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($profile) {
        $today = date('Y-m-d');
        $office = getUserOffice($pdo, $profile['user_id'], $profile['office_id'] ?? null, $today);
        
        $profile['office_name'] = $office ? $office['name'] : null;
        $profile['office_id'] = $office ? $office['id'] : null;
        $profile['is_security'] = ($profile['user_jabatan'] === 'Petugas Keamanan');
        
        sendResponse(200, 'Berhasil mengambil data profile', $profile);
    } else {
        sendResponse(404, 'User tidak ditemukan');
    }

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>