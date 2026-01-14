<?php

require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT 
            users.id, 
            users.name, 
            users.email,
            users.nip,
            users.alamat,
            users.tempat_lahir,
            users.tanggal_lahir,
            users.jabatan, 
            offices.name as office_name 
        FROM users 
        LEFT JOIN offices ON users.office_id = offices.id 
        WHERE users.id = ?
    ");
    
    $stmt->execute([$user['id']]);
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