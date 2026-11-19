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
        $shift = getUserScheduleRecord($pdo, $profile['user_id'], $today);
        $approvedDeviceUuid = getApprovedUserDeviceUuid($pdo, $user);
        
        $profile['office_name'] = $office ? $office['name'] : null;
        $profile['office_id'] = $office ? $office['id'] : null;
        $profile['is_security'] = isSecurityUser($profile);
        $profile['shift_id'] = $shift['shift_id'] ?? null;
        $profile['shift_name'] = $shift['shift_name'] ?? null;
        $profile['shift_jam_masuk'] = $shift['clock_in_target'] ?? null;
        $profile['shift_jam_pulang'] = $shift['clock_out_target'] ?? null;
        $profile['device_uuid'] = $approvedDeviceUuid;
        $profile['registered_device_uuid'] = $approvedDeviceUuid;
        
        sendResponse(200, 'Berhasil mengambil data profile', $profile);
    } else {
        sendResponse(404, 'User tidak ditemukan');
    }

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>