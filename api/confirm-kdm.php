<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['confirmation_status']) || $input['confirmation_status'] !== true) {
    sendResponse(400, 'Status konfirmasi tidak valid, harus bernilai true');
}

$userLat = $input['latitude'] ?? null;
$userLng = $input['longitude'] ?? null;
$attendanceId = $input['attendance_id'] ?? null;
$today   = date('Y-m-d');
$currentTime = date('Y-m-d H:i:s');

try {
    // Jika attendance_id dikirim (skema lama)
    if ($attendanceId) {
        $updateStmt = $pdo->prepare("UPDATE absensi_attendances SET is_confirmed = 1 WHERE id = ? AND user_id = ?");
        $updateStmt->execute([$attendanceId, $user['user_id']]);

        sendResponse(200, 'Absen KDM berhasil dikonfirmasi', [
            'status' => 'success',
            'attendance_id' => $attendanceId,
            'is_confirmed' => true
        ]);
        exit;
    }

    // Skema baru: INSERT ke DB baru dilakukan saat konfirmasi
    if (!$userLat || !$userLng) {
        sendResponse(400, 'Koordinat latitude dan longitude wajib dikirim untuk konfirmasi KDM');
    }

    // Cek apakah pegawai sudah clock-in hari ini
    $todayStmt = $pdo->prepare("SELECT id FROM absensi_attendances WHERE user_id = ? AND date = ? AND is_confirmed = 1 LIMIT 1");
    $todayStmt->execute([$user['user_id'], $today]);
    if ($todayStmt->fetch()) {
        sendResponse(400, 'Anda sudah melakukan presensi hari ini.');
    }

    // Lakukan INSERT resmi ke DB
    $insertStmt = $pdo->prepare("
        INSERT INTO absensi_attendances
        (user_id, date, clock_in_time, clock_in_lat, clock_in_lng, location_type, is_confirmed, status)
        VALUES (?, ?, ?, ?, ?, 'KDM', 1, 'on_time')
    ");

    $insertStmt->execute([
        $user['user_id'],
        $today,
        $currentTime,
        $userLat,
        $userLng
    ]);

    $newAttendanceId = $pdo->lastInsertId();

    sendResponse(200, 'Absen KDM berhasil dikonfirmasi dan tercatat di database', [
        'status' => 'success',
        'attendance_id' => $newAttendanceId,
        'is_confirmed' => true
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>
