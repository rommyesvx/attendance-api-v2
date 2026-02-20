<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['latitude']) || !isset($input['longitude'])) {
    sendResponse(400, 'Koordinat wajib dikirim');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$today   = date('Y-m-d');
$now     = date('Y-m-d H:i:s');

if ($user['role'] !== 'security') {
    sendResponse(403, 'Fitur ini hanya untuk Security');
}

$officeStmt = $pdo->prepare("SELECT polygon_coordinates FROM offices WHERE id = ?");
$officeStmt->execute([$user['office_id']]);
$office = $officeStmt->fetch();


if (!isPointInPolygon($userLat, $userLng, $office['polygon_coordinates'])) {
    sendResponse(400, "Gagal Lapor! Posisi Anda di luar area patroli.");
}

$attStmt = $pdo->prepare("SELECT id, clock_out_time FROM attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$attStmt->execute([$user['id'], $today]);
$attendance = $attStmt->fetch();

if (!$attendance) {
    sendResponse(400, 'Anda belum melakukan Clock In (Absen Masuk). Silakan Absen Masuk dulu.');
}

if ($attendance['clock_out_time'] != NULL) {
    sendResponse(400, 'Anda sudah Clock Out (Pulang). Tidak bisa patroli.');
}

$logStmt = $pdo->prepare("SELECT created_at FROM security_logs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$logStmt->execute([$user['id']]);
$lastLog = $logStmt->fetch();

if ($lastLog) {
    $lastTime = strtotime($lastLog['created_at']);
    $currentTime = time();
    $selisihMenit = ($currentTime - $lastTime) / 60;
    
    if ($selisihMenit < 45) {
        $sisaWaktu = ceil(45 - $selisihMenit);
        sendResponse(400, "Terlalu cepat! Patroli berikutnya bisa dilakukan dalam $sisaWaktu menit lagi.");
    }
}

// Proses upload gambar 
$imagePath = null;

if (isset($input['image']) && !empty($input['image'])) {
    
    $base64_string = $input['image'];
    
    if (strpos($base64_string, ',') !== false) {
        $base64_string = explode(',', $base64_string)[1];
    }

    $data = base64_decode($base64_string);

    if ($data === false) {
        sendResponse(400, 'Format gambar tidak valid');
    }

    $fileName = 'log_' . $user['id'] . '_' . time() . '.jpg';
    $directory = '../evidence/';
    $filePath = $directory . $fileName;

    try {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true); // Buat folder otomatis jika belum ada
        }
        
        file_put_contents($filePath, $data);
        
        $imagePath = '../evidence/' . $fileName; 
        
    } catch (Exception $e) {
        sendResponse(500, 'Gagal menyimpan gambar ke server');
    }
}

try {
    $insertStmt = $pdo->prepare("
        INSERT INTO security_logs (user_id, attendance_id, created_at, latitude, longitude, note, image_path) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $note = isset($input['note']) ? $input['note'] : 'Patroli Rutin';

    $insertStmt->execute([
        $user['id'],
        $attendance['id'],
        $now,
        $userLat,
        $userLng,
        $note,
        $imagePath
    ]);

    sendResponse(200, 'Laporan Patroli Berhasil Disimpan', [
        'time' => $now,
        'image_url' => $imagePath ? "http://10.30.13.24:8000/" . $imagePath : null
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error Server: ' . $e->getMessage());
}
?>