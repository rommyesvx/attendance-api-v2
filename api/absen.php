<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['latitude']) || !isset($input['longitude']) || !isset($input['location_type'])) {
    sendResponse(400, 'Data latitude, longitude, dan location_type wajib dikirim');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$attendanceType = $input['location_type'];
$isConfirmed = isset($input['is_confirmed']) ? (int)$input['is_confirmed'] : 0;
$today   = date('Y-m-d');
$now     = date('Y-m-d H:i:s');

try {
    $checkStmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
    $checkStmt->execute([$user['user_id'], $today]);
    $attendance = $checkStmt->fetch();

    if (!$attendance || $attendance['clock_out_time'] != NULL) {
        $statusWaktu = 'on_time'; // Default, bisa diubah jika perlu logika keterlambatan nantinya.

        $insertStmt = $pdo->prepare("
            INSERT INTO absensi_attendances 
            (user_id, date, clock_in_time, clock_in_lat, clock_in_lng, status, location_type, is_confirmed) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            $user['user_id'],
            $today,
            $now,
            $userLat,
            $userLng,
            $statusWaktu,  
            $attendanceType,
            $isConfirmed
        ]);

        $attendanceId = $pdo->lastInsertId();

        sendResponse(200, 'Absen berhasil dicatat', [
            'status' => 'success',
            'attendance_id' => $attendanceId
        ]);
    } else {
        // Jika sudah absen tapi belum clock out
        sendResponse(400, 'Anda masih memiliki sesi absen aktif. Silakan Clock Out terlebih dahulu sebelum memulai absen baru.');
    }
} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>
