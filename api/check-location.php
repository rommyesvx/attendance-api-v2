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
$now     = date('Y-m-d H:i:s');

$stmt_holiday = $pdo->prepare("SELECT name FROM absensi_holidays WHERE date = ?");
$stmt_holiday->execute([$today]);
$holiday = $stmt_holiday->fetch(PDO::FETCH_ASSOC);

if ($holiday) {
    sendResponse(400, "Gagal Clock In! Hari ini libur: " . $holiday['name']);
    exit;
}

// Cek Lokasi Kantor (Polygon)
$officeStmt = $pdo->prepare("SELECT * FROM absensi_offices WHERE id = ?");
$officeStmt->execute([$user['office_id']]);
$office = $officeStmt->fetch();

$inArea = isPointInPolygon($userLat, $userLng, $office['polygon_coordinates']);

// Evaluasi status lokasi
$attendanceType = $inArea ? 'KDK' : 'KDM';
$isConfirmed = $inArea ? 1 : 0;

$checkStmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$checkStmt->execute([$user['user_id'], $today]);
$attendance = $checkStmt->fetch();

try {
    // Pastikan user belum absen masuk hari ini (atau kalau diperlukan, izinkan multi shift)
    if (!$attendance || $attendance['clock_out_time'] != NULL) {
        
        $statusWaktu = 'on_time';

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

        $attendanceId = $pdo->lastInsertId(); // Dapatkan ID untuk Endpoint 2

        if ($attendanceType === 'KDK') {
            sendResponse(200, 'Absen berhasil', [
                'status' => 'success',
                'location' => 'KDK',
                'is_confirmed' => true,
                'attendance_id' => $attendanceId // Optional, tapi baik disertakan
            ]);
        } else {
            // Jika branch KDM dipilih
            sendResponse(202, 'KDM terdeteksi, butuh konfirmasi', [
                'status' => 'pending_confirmation',
                'location' => 'KDM',
                'is_confirmed' => false,
                'attendance_id' => $attendanceId // Dibutuhkan oleh Front-end untuk Pop-up KDM
            ]);
        }
    } else {
        // Jika sudah absen tapi belum clock out
        sendResponse(400, 'Anda masih memiliki sesi absen aktif. Silakan Clock Out terlebih dahulu sebelum memulai absen baru.');
    }

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>
