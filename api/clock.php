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

$attendanceType = isset($input['type']) ? strtoupper($input['type']) : 'KDK';

if (!in_array($attendanceType, ['KDK', 'KDM'])) {
    sendResponse(400, 'Tipe absen tidak valid. Pilih KDK atau KDM.');
}

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

// Validasi KDK wajib di kantor
if ($inArea) {
    $attendanceType = 'KDK'; // Jika di dalam area -> KDK
} else {
    $attendanceType = 'KDM'; // Jika di luar area -> KDM
}


$checkStmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$checkStmt->execute([$user['user_id'], $today]);
$attendance = $checkStmt->fetch();

try {
    // --- CLOCK IN (MASUK) ---
    if (!$attendance || $attendance['clock_out_time'] != NULL) {
        
        $jamBatasMasuk = "07:30:00";
        $jamSekarang   = date('H:i:s');

        if ($jamSekarang > $jamBatasMasuk) {
            $statusWaktu = 'late';
        } else {
            $statusWaktu = 'on_time';
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO absensi_attendances 
            (user_id, date, clock_in_time, clock_in_lat, clock_in_lng, status, location_type) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            $user['user_id'],
            $today,
            $now,
            $userLat,
            $userLng,
            $statusWaktu,  
            $attendanceType  
        ]);

        sendResponse(200, 'Berhasil Clock In', [
            'type'          => 'clock_in',
            'time'          => $now,
            'status'        => $statusWaktu,    
            'location_type' => $attendanceType  
        ]);
        
    } 
    // --- CLOCK OUT (PULANG) ---
    else {
        
        $jamMasuk = strtotime($attendance['clock_in_time']);
        $durasi   = time() - $jamMasuk;

        if ($durasi < 60) {
            sendResponse(400, 'Terlalu cepat! Tunggu 1 menit setelah Clock In.');
        }

        $updateStmt = $pdo->prepare("
            UPDATE absensi_attendances 
            SET clock_out_time = ?, clock_out_lat = ?, clock_out_lng = ? 
            WHERE id = ?
        ");
        $updateStmt->execute([$now, $userLat, $userLng, $attendance['id']]);

        sendResponse(200, 'Berhasil Clock Out', [
            'type' => 'clock_out',
            'time' => $now
        ]);
        
    }

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>