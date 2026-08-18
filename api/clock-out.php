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
    // Cari sesi absensi aktif yang BELUM Clock Out (dalam kurun waktu 24 jam)
    $openStmt = $pdo->prepare("
        SELECT id, clock_in_time, clock_out_time, location_type, date 
        FROM absensi_attendances 
        WHERE user_id = ? AND is_confirmed = 1 AND clock_out_time IS NULL 
        ORDER BY id DESC LIMIT 1
    ");
    $openStmt->execute([$user['user_id']]);
    $activeAttendance = $openStmt->fetch();

    $attendance = null;
    if ($activeAttendance) {
        $hoursElapsed = (time() - strtotime($activeAttendance['clock_in_time'])) / 3600;
        if ($hoursElapsed <= 24) {
            $attendance = $activeAttendance;
        }
    }

    // Jika tidak ada sesi yang belum clock out, cek apakah hari ini sudah ada data absensi untuk memperbarui jam pulang (meniban clock out)
    if (!$attendance) {
        $todayStmt = $pdo->prepare("
            SELECT id, clock_in_time, clock_out_time, location_type, date 
            FROM absensi_attendances 
            WHERE user_id = ? AND is_confirmed = 1 AND date = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $todayStmt->execute([$user['user_id'], $today]);
        $todayAttendance = $todayStmt->fetch();
        if ($todayAttendance) {
            $attendance = $todayAttendance;
        }
    }

    if ($attendance) {
        $shiftDate = $attendance['date'];

        // Cek apakah pegawai mencoba Clock Out sebelum jam pulang jadwalnya
        $scheduleValidation = validateScheduleClockOutTime($pdo, $user['user_id'], $shiftDate, $now);
        if (!$scheduleValidation['valid']) {
            sendResponse(400, $scheduleValidation['message']);
        }

        $office = getUserOffice($pdo, $user['user_id'], $user['office_id'] ?? null, $shiftDate);

        if ($office && !empty($office['polygon_coordinates'])) {
            $inArea = isPointInPolygon($userLat, $userLng, $office['polygon_coordinates']);
            $attendanceType = $inArea ? 'KDK' : 'KDM';
        } else {
            $attendanceType = 'KDM';
        }

        if ($attendance['location_type'] !== $attendanceType) {
            sendResponse(400, "Gagal Absen Pulang! Jenis absensi saat ini ({$attendanceType}) tidak sesuai dengan saat Clock In ({$attendance['location_type']}). Lokasi absen harus sesuai dengan lokasi saat Clock In.");
        }
    } else {
        sendResponse(400, 'Data Absen Masuk tidak ditemukan atau sesi sudah diakhiri.');
    }

    // if (!$attendance) {
    //     sendResponse(404, 'Data Absen Masuk hari ini tidak ditemukan. Silakan Clock In terlebih dahulu.');
    // }

    // if ($attendance['clock_out_time'] !== NULL) {
    //     sendResponse(400, 'Sesi absen ini sudah diakhiri. Silakan Clock In kembali jika ingin absensi baru.');
    // }

    $jamMasuk = strtotime($attendance['clock_in_time']);
    $durasi   = time() - $jamMasuk;

    // if ($durasi < 60) {
    //     sendResponse(400, 'Tunggu minimal 1 menit setelah Clock In untuk bisa Absen Pulang.');
    // }

    $updateStmt = $pdo->prepare("
        UPDATE absensi_attendances 
        SET clock_out_time = ?, clock_out_lat = ?, clock_out_lng = ?, status = 'on_time' 
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
