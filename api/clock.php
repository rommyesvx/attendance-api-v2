<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input['latitude']) || !isset($input['longitude'])) {
    sendResponse(400, 'Koordinat latitude dan longitude wajib dikirim');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');
$attendanceType = isset($input['type']) ? strtoupper($input['type']) : 'KDK';

if (!in_array($attendanceType, ['KDK', 'KDM'])) {
    sendResponse(400, 'Tipe absen tidak valid. Pilih KDK atau KDM.');
}

$officeStmt = $pdo->prepare("SELECT * FROM offices WHERE id = ?");
$officeStmt->execute([$user['office_id']]);
$office = $officeStmt->fetch();

$inArea = isPointInPolygon($userLat, $userLng, $office['polygon_coordinates']);

if ($attendanceType === 'KDK') {
    // SKENARIO A: User bilangnya KDK (Di Kantor)
    if (!$inArea) {
        // TAPI GPS bilang di luar --> TOLAK!
        sendResponse(400, "Gagal Clock In! Anda memilih 'Kerja Dari Kantor', namun lokasi Anda terdeteksi di luar area kantor.");
    }
} else {
    // SKENARIO B: User bilangnya KDM (Di Mana Saja)
    // Biasanya ini diloloskan saja, atau Anda bisa tambah aturan radius maksimal dari rumah (opsional).
    // Untuk sekarang, kita loloskan.
}

$checkStmt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$checkStmt->execute([$user['id'], $today]);
$attendance = $checkStmt->fetch();

try {
    if (!$attendance) {
        $jamMasuk = "07:30:00";

        $jamSekarang = date('H:i:s');

        if ($jamSekarang > $jamMasuk) {
            $statusAbsen = 'late';
        } else {
            $statusAbsen = 'on_time';
        }

        $insertStmt = $pdo->prepare("INSERT INTO attendances (user_id, date, clock_in_time, clock_in_lat, clock_in_lng, status, location_type) VALUES (?, ?, ?, ?, ?, ? , ?)");
        $insertStmt->execute([
            $user['id'],
            $today,
            $now,
            $userLat,
            $userLng,
            $statusAbsen,
            $attendanceType
        ]);

        sendResponse(200, 'Berhasil Clock In (Masuk)', [
            'type' => 'clock_in',
            'time' => $now
        ]);
    } elseif ($attendance['clock_out_time'] == NULL) {

        $jamMasuk = strtotime($attendance['clock_in_time']); // Ubah ke timestamp
        $jamSekarang = strtotime($now);
        $durasiKerja = $jamSekarang - $jamMasuk;

        if ($durasiKerja < 60) {
        sendResponse(400, 'Terlalu cepat! Tunggu setidaknya 1 menit setelah Clock In untuk bisa Clock Out.');
    }

        $updateStmt = $pdo->prepare("UPDATE attendances SET clock_out_time = ?, clock_out_lat = ?, clock_out_lng = ? WHERE id = ?");
        $updateStmt->execute([$now, $userLat, $userLng, $attendance['id']]);

        sendResponse(200, 'Berhasil Clock Out (Pulang)', [
            'type' => 'clock_out',
            'time' => $now
        ]);
    } else {
        sendResponse(400, 'Anda sudah menyelesaikan absensi hari ini.');
    }
} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
