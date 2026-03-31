<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['latitude']) || !isset($input['longitude'])) {
    sendResponse(400, 'Koordinat latitude dan longitude wajib dikirim untuk Absen Pulang');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$today   = date('Y-m-d');
$now     = date('Y-m-d H:i:s');

try {
    $checkStmt = $pdo->prepare("SELECT id, clock_in_time, clock_out_time FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
    $checkStmt->execute([$user['user_id'], $today]);
    $attendance = $checkStmt->fetch();

    if (!$attendance) {
        sendResponse(404, 'Data Absen Masuk hari ini tidak ditemukan. Silakan Clock In terlebih dahulu.');
    }

    if ($attendance['clock_out_time'] !== NULL) {
        sendResponse(400, 'Sesi absen ini sudah diakhiri. Silakan Clock In kembali jika ingin absensi baru.');
    }

    $jamMasuk = strtotime($attendance['clock_in_time']);
    $durasi   = time() - $jamMasuk;

    if ($durasi < 60) {
        sendResponse(400, 'Terlalu cepat! Tunggu minimal 1 menit setelah Clock In untuk bisa Absen Pulang.');
    }

    $updateStmt = $pdo->prepare("
        UPDATE absensi_attendances 
        SET clock_out_time = ?, clock_out_lat = ?, clock_out_lng = ? 
        WHERE id = ?
    ");
    $updateStmt->execute([$now, $userLat, $userLng, $attendance['id']]);

    sendResponse(200, 'Berhasil Absen Pulang', [
        'type' => 'clock_out',
        'time' => $now
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>
