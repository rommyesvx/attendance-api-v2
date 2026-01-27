<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

// 1. Autentikasi User
$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

// 2. Validasi Input Koordinat
if (!isset($input['latitude']) || !isset($input['longitude'])) {
    sendResponse(400, 'Koordinat wajib dikirim');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$today   = date('Y-m-d');
$now     = date('Y-m-d H:i:s');

// 3. Validasi: Apakah User adalah Security? (Opsional, jika ada kolom role)
if ($user['role'] !== 'security') {
    sendResponse(403, 'Fitur ini hanya untuk Security');
}

// 4. Validasi Geofencing (Patroli Wajib di Area Kantor)
$officeStmt = $pdo->prepare("SELECT polygon_coordinates FROM offices WHERE id = ?");
$officeStmt->execute([$user['office_id']]);
$office = $officeStmt->fetch();


if (!isPointInPolygon($userLat, $userLng, $office['polygon_coordinates'])) {
    sendResponse(400, "Gagal Lapor! Posisi Anda di luar area patroli.");
}

// 5. Cek Status: Apakah Security SUDAH Clock In (Masuk Kerja)?
// Kita butuh ID dari attendance hari ini untuk disambungkan
$attStmt = $pdo->prepare("SELECT id, clock_out_time FROM attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$attStmt->execute([$user['id'], $today]);
$attendance = $attStmt->fetch();

if (!$attendance) {
    sendResponse(400, 'Anda belum melakukan Clock In (Absen Masuk). Silakan Absen Masuk dulu.');
}

if ($attendance['clock_out_time'] != NULL) {
    sendResponse(400, 'Anda sudah Clock Out (Pulang). Tidak bisa patroli.');
}

// 6. Cek Interval Waktu (Agar tidak spam absen setiap menit)
// Ambil log patroli terakhir
$logStmt = $pdo->prepare("SELECT created_at FROM security_logs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$logStmt->execute([$user['id']]);
$lastLog = $logStmt->fetch();

if ($lastLog) {
    $lastTime = strtotime($lastLog['created_at']);
    $currentTime = time();
    $selisihMenit = ($currentTime - $lastTime) / 60;
    
    // Misal: Minimal jarak antar laporan adalah 45 menit
    if ($selisihMenit < 45) {
        $sisaWaktu = ceil(45 - $selisihMenit);
        sendResponse(400, "Terlalu cepat! Patroli berikutnya bisa dilakukan dalam $sisaWaktu menit lagi.");
    }
}

// 7. Simpan Log Patroli
try {
    $insertStmt = $pdo->prepare("
        INSERT INTO security_logs (user_id, attendance_id, created_at, latitude, longitude, note) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $note = isset($input['note']) ? $input['note'] : 'Patroli Rutin';

    $insertStmt->execute([
        $user['id'],
        $attendance['id'], // Menyambungkan ke tabel attendance
        $now,
        $userLat,
        $userLng,
        $note
    ]);

    sendResponse(200, 'Laporan Patroli Berhasil Disimpan', [
        'time' => $now,
        'check_point' => 'Log ke-' . (isset($lastLog) ? 'Sekian' : '1')
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error Server: ' . $e->getMessage());
}
?>