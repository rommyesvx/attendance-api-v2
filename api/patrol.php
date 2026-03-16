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
$currentTime = date('H:i:s');

if ($user['user_jabatan'] !== 'Petugas Keamanan') {
    sendResponse(403, 'Fitur ini hanya untuk Security');
}

<<<<<<< HEAD
$shiftStmt = $pdo->prepare("
    SELECT jam_masuk, jam_pulang 
    FROM absensi_pegawai_patroli 
    WHERE user_id = ? AND tanggal_shift = ?
");
$shiftStmt->execute([$user['user_id'], $today]);
$shiftsHariIni = $shiftStmt->fetchAll(PDO::FETCH_ASSOC);

if (!$shiftsHariIni) {
    sendResponse(400, 'Anda tidak memiliki jadwal shift patroli pada hari ini.');
}

$isWithinShift = false;

foreach ($shiftsHariIni as $shift) {
    $jamMasuk  = $shift['jam_masuk'];
    $jamPulang = $shift['jam_pulang'];

    // Toleransi 30 menit sebelum dan sesudah shift agar absen tidak gagal karena beda menit
    $batasBuka  = date('H:i:s', strtotime($jamMasuk . ' -30 minutes'));
    $batasTutup = date('H:i:s', strtotime($jamPulang . ' +30 minutes'));

    if ($currentTime >= $batasBuka && $currentTime <= $batasTutup) {
        $isWithinShift = true;
        break; 
    }
}

if (!$isWithinShift) {
    sendResponse(400, "Gagal Lapor! Saat ini Anda berada di luar jam shift patroli Anda.");
}

=======

$shiftStmt = $pdo->prepare("SELECT shift_start, shift_end FROM security_guards WHERE user_id = ?");
$shiftStmt->execute([$user['id']]); 
$shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

if (!$shift) {
    sendResponse(400, 'Jadwal shift Anda belum terdaftar di sistem.');
}

$shiftStart = $shift['shift_start'];
$shiftEnd   = $shift['shift_end'];
$isWithinShift = false;

if ($shiftStart <= $shiftEnd) {
    $isWithinShift = ($currentTime >= $shiftStart && $currentTime <= $shiftEnd);
} else {
    $isWithinShift = ($currentTime >= $shiftStart || $currentTime <= $shiftEnd);
}

if (!$isWithinShift) {
    $tampilStart = date('H:i', strtotime($shiftStart));
    $tampilEnd   = date('H:i', strtotime($shiftEnd));
    sendResponse(400, "Gagal Lapor! Saat ini di luar jadwal shift Anda ($tampilStart s/d $tampilEnd).");
}


>>>>>>> 94dfb36aad6e5c44623dd769aed5b78540497ba0
$officeStmt = $pdo->prepare("SELECT polygon_coordinates FROM absensi_offices WHERE id = ?");
$officeStmt->execute([$user['office_id']]);
$office = $officeStmt->fetch();

if (!isPointInPolygon($userLat, $userLng, $office['polygon_coordinates'])) {
    sendResponse(400, "Gagal Lapor! Posisi Anda di luar area patroli kantor.");
}


$attStmt = $pdo->prepare("SELECT id, clock_out_time FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$attStmt->execute([$user['user_id'], $today]);
$attendance = $attStmt->fetch();

if (!$attendance) {
    sendResponse(400, 'Anda belum melakukan Clock In (Absen Masuk). Silakan Absen Masuk dulu di menu utama.');
}

if (!is_null($attendance['clock_out_time']) && $attendance['clock_out_time'] !== '') {
    sendResponse(400, 'Anda sudah Clock Out (Pulang). Tidak bisa mengirim laporan patroli lagi.');
}


$logStmt = $pdo->prepare("SELECT created_at FROM absensi_security_logs WHERE user_id = ? ORDER BY id DESC LIMIT 1");
$logStmt->execute([$user['user_id']]);
$lastLog = $logStmt->fetch();

if ($lastLog) {
    $lastTime = strtotime($lastLog['created_at']);
    $currentUnixTime = time();
    $selisihMenit = ($currentUnixTime - $lastTime) / 60;
    
    if ($selisihMenit < 45) {
        $sisaWaktu = ceil(45 - $selisihMenit);
        sendResponse(400, "Terlalu cepat! Patroli berikutnya bisa dilakukan dalam $sisaWaktu menit lagi.");
    }
}


$imagePath = null;

if (isset($input['image']) && !empty($input['image'])) {
    $base64_string = $input['image'];
    
    if (strpos($base64_string, ',') !== false) {
        $base64_string = explode(',', $base64_string)[1];
    }

    $data = base64_decode($base64_string);

    if ($data === false) {
        sendResponse(400, 'Format gambar bukti patroli tidak valid.');
    }

    $fileName = 'log_' . $user['user_id'] . '_' . time() . '.jpg';
    $directory = '../evidence/';
    $filePath = $directory . $fileName;

    try {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        file_put_contents($filePath, $data);
        
        $imagePath = '/evidence/' . $fileName; 

        
    } catch (Exception $e) {
        sendResponse(500, 'Gagal menyimpan gambar bukti ke server. Pastikan folder memiliki permission yang benar.');
    }
}


try {
    $insertStmt = $pdo->prepare("
        INSERT INTO absensi_security_logs (user_id, attendance_id, created_at, latitude, longitude, note, image_path) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $note = isset($input['note']) ? $input['note'] : 'Patroli Rutin';

    $insertStmt->execute([
        $user['user_id'],
        $attendance['id'],
        $now,
        $userLat,
        $userLng,
        $note,
        $imagePath
    ]);

    sendResponse(200, 'Laporan Patroli Berhasil Disimpan', [
        'time' => $now,
<<<<<<< HEAD
        'image_url' => $imagePath ? "https://caraka-biroumumpbj.kemendikdasmen.go.id" . $imagePath : null
=======
        'image_url' => $imagePath ? "https://caraka-biroumumpbj.kemendikdasmen.go.id/" . ltrim($imagePath, '../') : null
>>>>>>> 94dfb36aad6e5c44623dd769aed5b78540497ba0
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error Database: Terjadi kesalahan saat menyimpan log patroli.');
}
?>