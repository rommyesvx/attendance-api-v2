<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);
validateRequestDevice($pdo, $user, $input, true);

if (!isset($input['latitude']) || !isset($input['longitude'])) {
    sendResponse(400, 'Koordinat latitude dan longitude wajib dikirim');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$today = date('Y-m-d');
$currentTime = date('Y-m-d H:i:s');

// Cari sesi absensi aktif yang BELUM Clock Out dan masih valid (termasuk shift malam kemarin)
$attendance = getActiveAttendance($pdo, $user['user_id'], $currentTime, $user);

// Validasi hari libur hanya berlaku untuk Clock In baru pegawai non-keamanan (tidak memblokir Clock Out shift malam)
if (!$attendance && !isSecurityRole($user)) {
    $stmt_holiday = $pdo->prepare("SELECT name FROM absensi_holidays WHERE date = ?");
    $stmt_holiday->execute([$today]);
    $holiday = $stmt_holiday->fetch(PDO::FETCH_ASSOC);

    if ($holiday) {
        sendResponse(400, "Gagal Clock In! Hari ini libur: " . $holiday['name']);
        exit;
    }
}

// Jika tidak ada sesi yang belum clock out, cek apakah hari ini sudah ada data absensi (sudah Clock In & Clock Out)
if (!$attendance) {
    $todayStmt = $pdo->prepare("
        SELECT * FROM absensi_attendances 
        WHERE user_id = ? AND is_confirmed = 1 AND date = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $todayStmt->execute([$user['user_id'], $today]);
    $todayAttendance = $todayStmt->fetch();

    if ($todayAttendance) {
        // Cek apakah pegawai ini memiliki jadwal untuk hari ini
        $hasSchedule = getUserScheduleRecord($pdo, $user['user_id'], $today);

        if (isSecurityRole($user) && $hasSchedule && !empty($todayAttendance['clock_out_time'])) {
            // Petugas keamanan terjadwal yang absen shift lagi di hari yang sama -> buat baris baru
        } else {
            // Pegawai reguler atau sesi belum clock out -> update baris ini
            $attendance = $todayAttendance;
        }
    }
}


// Jika user office_id nya null/kosong, otomatis diset ke 2 dan diupdate di DB
if (empty($user['office_id'])) {
    $user['office_id'] = 2;
    try {
        $updateUserOffice = $pdo->prepare("UPDATE user SET office_id = 2 WHERE user_id = ? AND (office_id IS NULL OR office_id = '' OR office_id = 0)");
        $updateUserOffice->execute([$user['user_id']]);
    } catch (Exception $e) {
        // Ignore exception
    }
}

// Jika sedang Clock Out, acuan tanggal kantor diambil dari tanggal Clock In
$shiftDate = $attendance ? $attendance['date'] : $today;
$office = getUserOffice($pdo, $user['user_id'], $user['office_id'] ?? null, $shiftDate);

if ($office && !empty($office['polygon_coordinates'])) {
    $inArea = isPointInPolygon($userLat, $userLng, $office['polygon_coordinates']);
    $attendanceType = $inArea ? 'KDK' : 'KDM';
} else {
    $attendanceType = 'KDM';
}

try {
    // Attendance already exists = Clock Out
    if ($attendance) {
        // Cek apakah pegawai mencoba Clock Out sebelum jam pulang jadwalnya
        $scheduleValidation = validateScheduleClockOutTime($pdo, $user['user_id'], $shiftDate, $currentTime);
        if (!$scheduleValidation['valid']) {
            sendResponse(400, $scheduleValidation['message']);
        }

        if ($attendance['location_type'] !== $attendanceType) {
            sendResponse(400, "Gagal Clock Out! Jenis absensi saat ini ({$attendanceType}) tidak sesuai dengan saat Clock In ({$attendance['location_type']}). Lokasi absen harus sesuai dengan lokasi saat Clock In.");
        }

        $updateStmt = $pdo->prepare("
            UPDATE absensi_attendances
            SET
                clock_out_time = ?,
                clock_out_lat = ?,
                clock_out_lng = ?,
                status = ?
            WHERE id = ?
        ");

        $updateStmt->execute([
            $currentTime,
            $userLat,
            $userLng,
            'on_time',
            $attendance['id']
        ]);

        sendResponse(200, 'Clock Out berhasil', [
            'status' => 'success',
            'office_id' => $office ? $office['id'] : null,
            'office_name' => $office ? $office['name'] : null,
            'attendance_id' => $attendance['id']
        ]);
    } else {
        // Attendance does not exist = Validation for new Clock In
        $clockInValidation = validateScheduleClockInTime($pdo, $user['user_id'], $shiftDate, $currentTime);
        if (!$clockInValidation['valid']) {
            sendResponse(400, $clockInValidation['message']);
        }
    }

    // Jika terdeteksi KDM (luar area): JANGAN simpan ke DB dulu! Kembalikan HTTP 202
    if ($attendanceType === 'KDM') {
        sendResponse(202, 'Lokasi diluar area (KDM). Menunggu konfirmasi.', [
            'status' => 'pending_confirmation',
            'location' => 'KDM',
            'office_id' => $office ? $office['id'] : null,
            'office_name' => $office ? $office['name'] : null,
            'latitude' => $userLat,
            'longitude' => $userLng,
            'is_confirmed' => false
        ]);
        exit;
    }

    // Jika KDK (Di area kantor): Langsung INSERT ke DB
    $insertStmt = $pdo->prepare("
        INSERT INTO absensi_attendances
        (user_id, date, clock_in_time, clock_in_lat, clock_in_lng, location_type, is_confirmed, status)
        VALUES (?, ?, ?, ?, ?, ?, 1, 'on_time')
    ");

    $insertStmt->execute([
        $user['user_id'],
        $today,
        $currentTime,
        $userLat,
        $userLng,
        $attendanceType
    ]);

    $newAttendanceId = $pdo->lastInsertId();

    sendResponse(200, "Clock In berhasil ({$attendanceType})", [
        'status' => 'success',
        'location' => $attendanceType,
        'office_id' => $office ? $office['id'] : null,
        'office_name' => $office ? $office['name'] : null,
        'attendance_id' => $newAttendanceId,
        'is_confirmed' => true
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>