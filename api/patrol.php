<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);
validateRequestDevice($pdo, $user, $input, false);

if (!is_numeric($input['latitude']) || !is_numeric($input['longitude'])) {
    sendResponse(400, 'Koordinat wajib dikirim dalam format angka yang valid');
}

$userLat = (float)$input['latitude'];
$userLng = (float)$input['longitude'];
$today   = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$now     = date('Y-m-d H:i:s');
$nowTs   = strtotime($now);

if (!isSecurityUser($user)) {
    sendResponse(403, 'Fitur absen tiap jam (patroli) hanya berlaku untuk Petugas Keamanan');
}

// 1. Verifikasi Absensi Aktif Petugas Keamanan
$attendance = getActiveAttendance($pdo, $user['user_id'], $now);

if (!$attendance) {
    sendResponse(400, 'Anda belum melakukan Clock In (Absen Masuk). Silakan Absen Masuk dulu di menu utama.');
}

if (!empty($attendance['clock_out_time'])) {
    sendResponse(400, 'Anda sudah Clock Out (Pulang). Tidak bisa mengirim laporan patroli lagi.');
}

$activeShiftDate = $attendance['date'];

// 2. Ambil dan Cocokkan Jadwal Shift Keamanan dari master_shift_user & master_shift
$candidateDates = array_values(array_unique([$activeShiftDate, $today]));
$shifts = [];

foreach ($candidateDates as $cDate) {
    $sRec = getUserScheduleRecord($pdo, $user['user_id'], $cDate);
    if ($sRec) {
        $shifts[] = $sRec;
    }
}

$isWithinShift = false;
$matchedShift = null;
$hasDefinedShiftHours = false;

foreach ($shifts as $shift) {
    $shiftDate = $shift['schedule_date'] ?? $activeShiftDate;
    $tipeShift = strtolower(trim((string)($shift['tipe_shift'] ?? '')));
    $jamMasuk  = $shift['clock_in_target'] ?? null;
    $jamPulang = $shift['clock_out_target'] ?? null;

    // Jika jam_masuk atau jam_pulang di master_shift masih 00:00:00 (belum diisi), lewati ke fallback
    if (!$jamMasuk || !$jamPulang) {
        continue;
    }

    $hasDefinedShiftHours = true;
    $startTs = strtotime("$shiftDate $jamMasuk");
    $endTs   = strtotime("$shiftDate $jamPulang");

    // Shift malam lintas hari (contoh: 19:00 -> 07:00 keesokan harinya)
    if ($tipeShift === 'malam' || !empty($shift['is_overnight']) || $endTs <= $startTs) {
        $endTs = strtotime("$shiftDate $jamPulang +1 day");
    }

    // Toleransi 30 menit sebelum jam masuk shift, dan ditutup tepat saat jam pulang shift berakhir
    $batasBukaTs  = $startTs - (30 * 60);
    $batasTutupTs = $endTs;

    if ($nowTs >= $batasBukaTs && $nowTs <= $batasTutupTs) {
        $isWithinShift = true;
        $matchedShift = $shift;
        $activeShiftDate = $shiftDate;
        break;
    }
}

if (!$hasDefinedShiftHours) {
    // Jika jam pada master_shift masih kosong/00:00:00 (rumus rotasi belum diisi), tentukan berdasarkan waktu Clock In
    $clockInTs = strtotime($attendance['clock_in_time']);
    $clockInHour = (int)date('H', $clockInTs);
    
    // Shift Malam: Clock In antara 18:00 s/d 06:59
    if ($clockInHour >= 18 || $clockInHour < 7) {
        $startTs = strtotime("$activeShiftDate 19:00:00");
        $endTs   = strtotime("$activeShiftDate 07:00:00 +1 day");
    } else {
        // Shift Pagi: 07:00 s/d 19:00
        $startTs = strtotime("$activeShiftDate 07:00:00");
        $endTs   = strtotime("$activeShiftDate 19:00:00");
    }

    $batasBukaTs  = $startTs - (30 * 60);
    $batasTutupTs = $endTs;

    if ($nowTs >= $batasBukaTs && $nowTs <= $batasTutupTs) {
        $isWithinShift = true;
    }
}

if (!$isWithinShift) {
    sendResponse(400, "Gagal Lapor! Saat ini Anda berada di luar jam shift dinas keamanan Anda.");
}

$office = getUserOffice($pdo, $user['user_id'], $user['office_id'] ?? null, $activeShiftDate);

if (!$office || !isPointWithinGeofence($userLat, $userLng, $office, 25.0)) {
    sendResponse(400, "Gagal Lapor! Posisi Anda di luar area patroli kantor.");
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
        'image_url' => $imagePath ? "https://caraka-biroumumpbj.kemendikdasmen.go.id" . ltrim($imagePath, '../') : null
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error Database: Terjadi kesalahan saat menyimpan log patroli.');
}
?>