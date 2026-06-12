<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['latitude']) || !isset($input['longitude'])) {
    sendResponse(400, 'Koordinat latitude dan longitude wajib dikirim');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$today   = date('Y-m-d');
$currentTime = date('Y-m-d H:i:s');

$stmt_holiday = $pdo->prepare("SELECT name FROM absensi_holidays WHERE date = ?");
$stmt_holiday->execute([$today]);
$holiday = $stmt_holiday->fetch(PDO::FETCH_ASSOC);

if ($holiday) {
    sendResponse(400, "Gagal Clock In! Hari ini libur: " . $holiday['name']);
    exit;
}

if (empty($user['office_id'])) {
    $attendanceType = 'KDM';
} else {
    $officeStmt = $pdo->prepare("SELECT * FROM absensi_offices WHERE id = ?");
    $officeStmt->execute([$user['office_id']]);
    $office = $officeStmt->fetch();

    if ($office && !empty($office['polygon_coordinates'])) {
        $inArea = isPointInPolygon($userLat, $userLng, $office['polygon_coordinates']);
        $attendanceType = $inArea ? 'KDK' : 'KDM';
    } else {
        $attendanceType = 'KDM';
    }
}

$checkStmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$checkStmt->execute([$user['user_id'], $today]);
$attendance = $checkStmt->fetch();

try {
    if (!$attendance || $attendance['clock_out_time'] != NULL) {
        
        $isConfirmed = ($attendanceType === 'KDM') ? 0 : 1;

        $insertStmt = $pdo->prepare("
            INSERT INTO absensi_attendances 
            (user_id, date, clock_in_time, clock_in_lat, clock_in_lng, location_type, is_confirmed) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $user['user_id'], 
            $today, 
            $currentTime, 
            $userLat, 
            $userLng, 
            $attendanceType, 
            $isConfirmed
        ]);

        $newAttendanceId = $pdo->lastInsertId();

        if ($attendanceType === 'KDK' || $attendanceType === 'WFA') {
            sendResponse(200, "Clock In berhasil ({$attendanceType})", [
                'status' => 'success',
                'location' => $attendanceType,
                'attendance_id' => $newAttendanceId,
                'is_confirmed' => true
            ]);
        } else {
            sendResponse(202, 'Lokasi diluar area (KDM). Menunggu konfirmasi.', [
                'status' => 'pending_confirmation',
                'location' => 'KDM',
                'attendance_id' => $newAttendanceId,
                'is_confirmed' => false
            ]);
        }
        
    } else {
        sendResponse(400, 'Anda masih memiliki sesi absen aktif. Silakan Clock Out terlebih dahulu sebelum memulai absen baru.');
    }

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>