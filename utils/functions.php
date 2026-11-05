<?php

define('JWT_SECRET_KEY', 'AutentikasiAbsensiAPI2026!');
function sendResponse($code, $message, $data = null)
{
    http_response_code($code);
    echo json_encode([
        'status' => $code == 200 || $code == 201 ? 'success' : 'error',
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

function sendDeviceError($httpCode, $errorCode, $deviceStatus, $message, $extraData = [])
{
    http_response_code($httpCode);
    $response = [
        'status' => 'error',
        'code' => $errorCode,
        'device_status' => $deviceStatus,
        'message' => $message
    ];
    if (!empty($extraData)) {
        $response['data'] = $extraData;
    }
    echo json_encode($response);
    exit();
}

function getRequestHeadersNormalized()
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $normalized = [];
    if (is_array($headers)) {
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }
    }
    if (!isset($normalized['authorization'])) {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $normalized['authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $normalized['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
    }
    if (!isset($normalized['x-device-uuid']) && !empty($_SERVER['HTTP_X_DEVICE_UUID'])) {
        $normalized['x-device-uuid'] = $_SERVER['HTTP_X_DEVICE_UUID'];
    }
    return $normalized;
}

function extractDeviceUuid($input = null)
{
    if (is_array($input)) {
        $candidates = [
            $input['device_uuid'] ?? null,
            $input['imei'] ?? null,
            $input['device_id'] ?? null
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim((string)$candidate) !== '') {
                return trim((string)$candidate);
            }
        }
    }

    $headers = getRequestHeadersNormalized();
    if (!empty($headers['x-device-uuid']) && trim((string)$headers['x-device-uuid']) !== '') {
        return trim((string)$headers['x-device-uuid']);
    }

    return '';
}

function verifyUserCredentials($pdo, $userId, $userPassword)
{
    $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return null;
    }

    $md5_input = md5($userPassword);
    $password_acak = pwdgenerate($md5_input);

    if ($password_acak === trim($user['user_password'])) {
        return $user;
    }

    return null;
}

/**
 * Mengambil device_uuid aktif yang sudah di-approve untuk user.
 * Mensinkronkan tabel device_registrations (status = 'approved' terbaru) dan kolom user.device_uuid.
 */
function getApprovedUserDeviceUuid($pdo, $user)
{
    if (!$user || empty($user['user_id'])) {
        return null;
    }

    $userId = $user['user_id'];
    $userColUuid = isset($user['device_uuid']) && trim((string)$user['device_uuid']) !== ''
        ? trim((string)$user['device_uuid'])
        : null;

    try {
        // Cari registrasi perangkat berstatus 'approved' terbaru milik user ini
        $stmt = $pdo->prepare("
            SELECT device_uuid
            FROM device_registrations
            WHERE user_id = ? AND status = 'approved'
            ORDER BY COALESCE(reviewed_at, requested_at) DESC, id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $approvedRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($approvedRow && trim((string)$approvedRow['device_uuid']) !== '') {
            $approvedUuid = trim((string)$approvedRow['device_uuid']);

            // Sinkronkan ke kolom user.device_uuid bila berbeda/kosong
            if ($userColUuid !== $approvedUuid) {
                try {
                    $syncStmt = $pdo->prepare("UPDATE user SET device_uuid = ? WHERE user_id = ?");
                    $syncStmt->execute([$approvedUuid, $userId]);
                } catch (Exception $e) {
                    // Abaikan jika kolom belum ada
                }
            }

            return $approvedUuid;
        }

        // Jika tidak ada yang 'approved' di device_registrations, periksa apakah user.device_uuid diisi manual oleh Admin
        if ($userColUuid !== null) {
            $latestStmt = $pdo->prepare("
                SELECT status
                FROM device_registrations
                WHERE user_id = ? AND device_uuid = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $latestStmt->execute([$userId, $userColUuid]);
            $latestRow = $latestStmt->fetch(PDO::FETCH_ASSOC);

            // Jika status terakhir di device_registrations adalah 'rejected' atau 'pending', berarti belum/tidak disetujui
            if ($latestRow && in_array($latestRow['status'], ['rejected', 'pending'], true)) {
                if ($latestRow['status'] === 'rejected') {
                    try {
                        $clearStmt = $pdo->prepare("UPDATE user SET device_uuid = NULL WHERE user_id = ?");
                        $clearStmt->execute([$userId]);
                    } catch (Exception $e) {
                    }
                }
                return null;
            }

            return $userColUuid;
        }
    } catch (Exception $e) {
        // Fallback jika tabel device_registrations belum dimigrasi
        return $userColUuid;
    }

    return null;
}

/**
 * Mengecek apakah suatu device_uuid sedang terikat (approved aktif) pada user_id lain.
 */
function isDeviceBoundToAnotherUser($pdo, $deviceUuid, $currentUserId)
{
    $deviceUuid = trim((string)$deviceUuid);
    if ($deviceUuid === '') {
        return false;
    }

    $candidateUserIds = [];

    // 1. Cek dari kolom user.device_uuid
    try {
        $uStmt = $pdo->prepare("SELECT user_id FROM user WHERE device_uuid = ? AND user_id != ?");
        $uStmt->execute([$deviceUuid, $currentUserId]);
        while ($row = $uStmt->fetch(PDO::FETCH_ASSOC)) {
            $candidateUserIds[$row['user_id']] = true;
        }
    } catch (Exception $e) {
        // Abaikan jika kolom user.device_uuid belum tersedia
    }

    // 2. Cek dari riwayat device_registrations berstatus approved pada akun lain
    try {
        $dStmt = $pdo->prepare("
            SELECT DISTINCT user_id
            FROM device_registrations
            WHERE device_uuid = ? AND user_id != ? AND status = 'approved'
        ");
        $dStmt->execute([$deviceUuid, $currentUserId]);
        while ($row = $dStmt->fetch(PDO::FETCH_ASSOC)) {
            $candidateUserIds[$row['user_id']] = true;
        }
    } catch (Exception $e) {
    }

    // Pastikan apakah deviceUuid tersebut benar-benar masih menjadi device aktif yang di-approve pada user lain tersebut
    foreach (array_keys($candidateUserIds) as $otherUserId) {
        $otherStmt = $pdo->prepare("SELECT * FROM user WHERE user_id = ? LIMIT 1");
        $otherStmt->execute([$otherUserId]);
        $otherUser = $otherStmt->fetch(PDO::FETCH_ASSOC);

        if ($otherUser) {
            $otherApprovedUuid = getApprovedUserDeviceUuid($pdo, $otherUser);
            if ($otherApprovedUuid !== null && $otherApprovedUuid === $deviceUuid) {
                return true;
            }
        } else {
            $lastStmt = $pdo->prepare("
                SELECT device_uuid
                FROM device_registrations
                WHERE user_id = ? AND status = 'approved'
                ORDER BY COALESCE(reviewed_at, requested_at) DESC, id DESC
                LIMIT 1
            ");
            $lastStmt->execute([$otherUserId]);
            $lastApproved = $lastStmt->fetch(PDO::FETCH_ASSOC);
            if ($lastApproved && trim((string)$lastApproved['device_uuid']) === $deviceUuid) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Memvalidasi kecocokan device_uuid request terhadap akun user.
 * Mengeluarkan HTTP 403 (DEVICE_PENDING / DEVICE_NOT_REGISTERED) bila tidak sesuai.
 */
function validateRequestDevice($pdo, $user, $input = null, $requireDeviceInput = true)
{
    $deviceUuid = extractDeviceUuid($input);
    $approvedUuid = getApprovedUserDeviceUuid($pdo, $user);

    if ($deviceUuid === '') {
        if (!$requireDeviceInput) {
            return $approvedUuid;
        }
        sendDeviceError(
            403,
            'DEVICE_NOT_REGISTERED',
            'unregistered',
            'Perangkat ini belum terdaftar pada akun Anda. Silakan lakukan Register Device.'
        );
    }

    // 1. Jika device_uuid cocok dengan perangkat aktif yang sudah di-approve
    if ($approvedUuid !== null && $deviceUuid === $approvedUuid) {
        return $approvedUuid;
    }

    // 2. Cek apakah ada pengajuan untuk device_uuid tersebut yang masih berstatus pending
    try {
        $pendingStmt = $pdo->prepare("
            SELECT id
            FROM device_registrations
            WHERE user_id = ? AND device_uuid = ? AND status = 'pending'
            ORDER BY id DESC
            LIMIT 1
        ");
        $pendingStmt->execute([$user['user_id'], $deviceUuid]);
        if ($pendingStmt->fetch(PDO::FETCH_ASSOC)) {
            sendDeviceError(
                403,
                'DEVICE_PENDING',
                'pending',
                'Pendaftaran perangkat Anda sedang menunggu persetujuan (Approval) Admin.'
            );
        }
    } catch (Exception $e) {
    }

    // 3. Belum pernah mendaftarkan perangkat atau device_uuid berbeda dari perangkat yang di-approve
    sendDeviceError(
        403,
        'DEVICE_NOT_REGISTERED',
        'unregistered',
        'Perangkat ini belum terdaftar pada akun Anda. Silakan lakukan Register Device.'
    );
}

function pwdgenerate($awal)
{
    $awal1 = substr($awal, 0, 8);
    $awal2 = substr($awal, 8, 8);
    $awal3 = substr($awal, 16, 8);
    $awal4 = substr($awal, 24, 7);
    $awal5 = substr($awal, 31, 1);

    //STARTING ENCRYPT
    $awal6 = pwdrein($awal5);
    return $awal4 . $awal2 . $awal1 . $awal3 . $awal6;
}

function pwdrein($w)
{
    if ($w == 'a') {
        return '1';
    } elseif ($w == 'b') {
        return 'z';
    } elseif ($w == 'c') {
        return '2';
    } elseif ($w == 'd') {
        return 'f';
    } elseif ($w == 'e') {
        return '3';
    } elseif ($w == 'f') {
        return 'x';
    } elseif ($w == 'g') {
        return 'c';
    } elseif ($w == 'h') {
        return 'r';
    } elseif ($w == 'i') {
        return '4';
    } elseif ($w == 'j') {
        return 's';
    } elseif ($w == 'k') {
        return 'q';
    } elseif ($w == 'l') {
        return 'v';
    } elseif ($w == 'm') {
        return 'e';
    } elseif ($w == 'n') {
        return '5';
    } elseif ($w == 'o') {
        return 'b';
    } elseif ($w == 'p') {
        return '8';
    } elseif ($w == 'q') {
        return 'a';
    } elseif ($w == 'r') {
        return '9';
    } elseif ($w == 's') {
        return 'l';
    } elseif ($w == 't') {
        return '6';
    } elseif ($w == 'u') {
        return 'p';
    } elseif ($w == 'v') {
        return 'j';
    } elseif ($w == 'w') {
        return 'u';
    } elseif ($w == 'x') {
        return '7';
    } elseif ($w == 'y') {
        return 'w';
    } elseif ($w == 'z') {
        return 'o';
    } elseif ($w == '1') {
        return 'g';
    } elseif ($w == '2') {
        return 'h';
    } elseif ($w == '3') {
        return 'i';
    } elseif ($w == '4') {
        return 'd';
    } elseif ($w == '5') {
        return 'n';
    } elseif ($w == '6') {
        return 't';
    } elseif ($w == '7') {
        return 'k';
    } elseif ($w == '8') {
        return 'y';
    } elseif ($w == '9') {
        return 'm';
    } elseif ($w == '0') {
        return '0';
    }
    return $w;
}

function generate_jwt($headers, $payload, $secret = JWT_SECRET_KEY)
{
    $headers_encoded = rtrim(strtr(base64_encode(json_encode($headers)), '+/', '-_'), '=');
    $payload_encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

    $signature = hash_hmac('SHA256', "$headers_encoded.$payload_encoded", $secret, true);
    $signature_encoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return "$headers_encoded.$payload_encoded.$signature_encoded";
}

function validate_jwt($token, $secret = JWT_SECRET_KEY)
{
    $tokenParts = explode('.', $token);
    if (count($tokenParts) != 3)
        return false;

    $header = base64_decode(strtr($tokenParts[0], '-_', '+/'));
    $payload = base64_decode(strtr($tokenParts[1], '-_', '+/'));
    $signature_provided = $tokenParts[2];

    $payload_data = json_decode($payload, true);
    if (isset($payload_data['exp']) && $payload_data['exp'] < time()) {
        return false; // Token kadaluarsa
    }

    $base64_url_header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
    $base64_url_payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $signature = hash_hmac('SHA256', $base64_url_header . "." . $base64_url_payload, $secret, true);
    $base64_url_signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    if ($base64_url_signature === $signature_provided) {
        return $payload_data; // Token Valid, kembalikan isinya
    } else {
        return false; // Token Palsu
    }
}

function isPointInPolygon($pointLat, $pointLng, $polygonJson)
{
    $vertices = json_decode($polygonJson, true);

    if (!$vertices || count($vertices) < 3) {
        return false;
    }

    $verticesCount = count($vertices);
    $isInside = false;

    for ($i = 0, $j = $verticesCount - 1; $i < $verticesCount; $j = $i++) {

        $lati = $vertices[$i]['lat'];
        $lngi = $vertices[$i]['lng'];

        $latj = $vertices[$j]['lat'];
        $lngj = $vertices[$j]['lng'];

        $intersect = (($lngi > $pointLng) != ($lngj > $pointLng)) &&
            ($pointLat < ($latj - $lati) * ($pointLng - $lngi) / ($lngj - $lngi) + $lati);

        if ($intersect) {
            $isInside = !$isInside;
        }
    }

    return $isInside;
}

function authenticate($pdo, $checkDevice = true)
{
    $headers = getRequestHeadersNormalized();
    if (!isset($headers['authorization'])) {
        sendResponse(401, 'Gagal: Request ini butuh TOKEN (Bearer Token).');
    }

    $authHeader = $headers['authorization'];
    $token = trim(preg_replace('/^Bearer\s+/i', '', $authHeader));

    $payload = validate_jwt($token);

    if (!$payload) {
        sendResponse(401, 'Token tidak valid atau sudah kadaluarsa');
    }

    $userId = $payload['sub'];

    $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(401, 'User tidak ditemukan');
    }

    if (!isset($user['api_token']) || $user['api_token'] !== $token) {
        sendResponse(401, 'Sesi telah berakhir atau akun telah login di perangkat lain');
    }

    if ($checkDevice) {
        $headerDeviceUuid = extractDeviceUuid(null);
        $sessionDeviceUuid = $headerDeviceUuid !== ''
            ? $headerDeviceUuid
            : (isset($payload['device_uuid']) ? trim((string)$payload['device_uuid']) : '');

        if ($sessionDeviceUuid !== '') {
            $approvedUuid = validateRequestDevice($pdo, $user, ['device_uuid' => $sessionDeviceUuid], true);
            $user['device_uuid'] = $approvedUuid;
        } else {
            $user['device_uuid'] = getApprovedUserDeviceUuid($pdo, $user);
        }
    }

    return $user;
}

/**
 * Mengambil record data dari tabel schedule (absensi_schedules / absensi_schedule)
 */
function getUserScheduleRecord($pdo, $userId, $date = null)
{
    if (!$date) {
        $date = date('Y-m-d');
    }

    $tables = ['absensi_schedules', 'absensi_schedule'];
    foreach ($tables as $tableName) {
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);

            $dateCol = null;
            if (in_array('schedule_date', $columns)) {
                $dateCol = 'schedule_date';
            } elseif (in_array('date', $columns)) {
                $dateCol = 'date';
            } elseif (in_array('schedule', $columns)) {
                $dateCol = 'schedule';
            }

            $userCol = null;
            if (in_array('user_id', $columns)) {
                $userCol = 'user_id';
            } elseif (in_array('id_user', $columns)) {
                $userCol = 'id_user';
            }

            if ($dateCol && $userCol) {
                $stmt = $pdo->prepare("
                    SELECT * FROM `{$tableName}` 
                    WHERE `{$userCol}` = ? AND `{$dateCol}` = ? 
                    LIMIT 1
                ");
                $stmt->execute([$userId, $date]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            }
        } catch (PDOException $e) {
            continue;
        }
    }

    return null;
}

function getUserOffice($pdo, $userId, $fallbackOfficeId = null, $date = null)
{
    if (!$date) {
        $date = date('Y-m-d');
    }

    $officeId = null;
    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $date);

    if ($scheduleRecord && !empty($scheduleRecord['office_id'])) {
        $officeId = $scheduleRecord['office_id'];
    }

    // 2. Fallback: Jika tidak ada di tabel schedule, gunakan fallbackOfficeId (dari tabel user)
    if (empty($officeId)) {
        $officeId = $fallbackOfficeId;
    }

    // 3. Jika office_id masih kosong/null, otomatis diset ke 2 dan diupdate ke tabel user
    if (empty($officeId)) {
        $officeId = 2;
        if ($userId) {
            try {
                $updateUserStmt = $pdo->prepare("UPDATE user SET office_id = 2 WHERE user_id = ? AND (office_id IS NULL OR office_id = '' OR office_id = 0)");
                $updateUserStmt->execute([$userId]);
            } catch (Exception $e) {
                // Ignore exception if user table cannot be updated
            }
        }
    }

    // 4. Ambil data absensi_offices jika officeId tersedia
    if (!empty($officeId)) {
        $officeStmt = $pdo->prepare("SELECT * FROM absensi_offices WHERE id = ?");
        $officeStmt->execute([$officeId]);
        $office = $officeStmt->fetch(PDO::FETCH_ASSOC);
        if ($office) {
            return $office;
        }
    }

    return null;
}

/**
 * Memvalidasi apakah pegawai yang memiliki jadwal di tabel schedule mencoba clock out sebelum waktunya.
 * Mengembalikan array ['valid' => true] atau ['valid' => false, 'message' => '...']
 */
function validateScheduleClockOutTime($pdo, $userId, $shiftDate, $currentTime = null)
{
    if (!$currentTime) {
        $currentTime = date('Y-m-d H:i:s');
    }

    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $shiftDate);

    // Jika pegawai TIDAK ada di tabel schedule, diizinkan clock out (fallback)
    if (!$scheduleRecord) {
        return ['valid' => true];
    }

    // Cari kolom target jam pulang
    $clockOutTarget = $scheduleRecord['clock_out_target']
        ?? $scheduleRecord['clock_out_time']
        ?? $scheduleRecord['jam_pulang']
        ?? $scheduleRecord['shift_out']
        ?? null;

    // Jika tidak ada batasan jam pulang di tabel schedule, izinkan
    if (!$clockOutTarget) {
        return ['valid' => true];
    }

    // Cari jam masuk untuk cek shift malam/lintas hari
    $clockInTarget = $scheduleRecord['clock_in_target']
        ?? $scheduleRecord['clock_in_time']
        ?? $scheduleRecord['jam_masuk']
        ?? $scheduleRecord['shift_in']
        ?? null;

    // Tentukan timestamp target jam pulang
    if ($clockInTarget && $clockOutTarget < $clockInTarget) {
        // Shift malam lintas hari (contoh: 18:00 - 08:00 hari berikutnya)
        $targetTimestamp = strtotime("$shiftDate $clockOutTarget +1 day");
    } else {
        $targetTimestamp = strtotime("$shiftDate $clockOutTarget");
    }

    if (strtotime($currentTime) < $targetTimestamp) {
        $formattedTarget = date('H:i', strtotime($clockOutTarget));
        return [
            'valid' => false,
            'target_time' => $clockOutTarget,
            'message' => "Gagal Clock Out! Belum memasuki jam pulang sesuai jadwal Anda (Jam pulang: {$formattedTarget})."
        ];
    }

    return ['valid' => true];
}

/**
 * Memvalidasi apakah pegawai yang memiliki jadwal di tabel schedule mencoba clock in terlalu awal.
 * Batas toleransi paling awal adalah 30 menit sebelum jam masuk.
 * Mengembalikan array ['valid' => true] atau ['valid' => false, 'message' => '...']
 */
function validateScheduleClockInTime($pdo, $userId, $shiftDate, $currentTime = null)
{
    if (!$currentTime) {
        $currentTime = date('Y-m-d H:i:s');
    }

    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $shiftDate);

    // Jika pegawai TIDAK ada di tabel schedule, diizinkan clock in kapan saja (bebas)
    if (!$scheduleRecord) {
        return ['valid' => true];
    }

    // Cari kolom target jam masuk
    $clockInTarget = $scheduleRecord['clock_in_target']
        ?? $scheduleRecord['clock_in_time']
        ?? $scheduleRecord['jam_masuk']
        ?? $scheduleRecord['shift_in']
        ?? null;

    // Jika tidak ada batasan jam masuk di tabel schedule, izinkan
    if (!$clockInTarget) {
        return ['valid' => true];
    }

    // Tentukan timestamp target jam masuk
    $targetTimestamp = strtotime("$shiftDate $clockInTarget");

    // Batas paling awal (30 menit sebelumnya)
    $earliestAllowedTimestamp = $targetTimestamp - (30 * 60);

    // Jika mencoba clock in sebelum batas paling awal (30 menit)
    if (strtotime($currentTime) < $earliestAllowedTimestamp) {
        $formattedTarget = date('H:i', strtotime($clockInTarget));
        $formattedEarliest = date('H:i', $earliestAllowedTimestamp);
        return [
            'valid' => false,
            'target_time' => $clockInTarget,
            'message' => "Gagal Clock In! Belum waktunya absen masuk. Anda baru diizinkan absen mulai pukul {$formattedEarliest} (Jam masuk: {$formattedTarget})."
        ];
    }

    return ['valid' => true];
}

/**
 * Mengambil sesi absensi aktif yang BELUM Clock Out dan MASIH VALID untuk di-Clock Out.
 * Mengabaikan sesi dari hari sebelumnya jika pegawai lupa Clock Out (kecuali shift malam lintas hari).
 */
function getActiveAttendance($pdo, $userId, $currentTime = null)
{
    if (!$currentTime) {
        $currentTime = date('Y-m-d H:i:s');
    }
    $nowTs = strtotime($currentTime);
    $today = date('Y-m-d', $nowTs);

    $openStmt = $pdo->prepare("
        SELECT * FROM absensi_attendances 
        WHERE user_id = ? AND is_confirmed = 1 AND clock_out_time IS NULL 
        ORDER BY id DESC LIMIT 1
    ");
    $openStmt->execute([$userId]);
    $activeAttendance = $openStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activeAttendance) {
        return null;
    }

    $clockInTimeStr = $activeAttendance['clock_in_time'];
    $clockInTs = strtotime($clockInTimeStr);
    $hoursElapsed = ($nowTs - $clockInTs) / 3600;

    // Batas maksimal mutlak 24 jam
    if ($hoursElapsed > 24) {
        return null;
    }

    $shiftDate = $activeAttendance['date'];

    // Jika sesi absensi adalah HARI INI, maka sesi tersebut pasti aktif
    if ($shiftDate === $today) {
        return $activeAttendance;
    }

    // Jika sesi absensi berasal dari HARI SEBELUMNYA ($shiftDate < $today):
    // Cek apakah ini shift malam / lintas hari yang valid.
    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $shiftDate);
    
    $isNightShift = false;
    $maxAllowedClockOutTs = null;

    if ($scheduleRecord) {
        $clockInTarget = $scheduleRecord['clock_in_target'] 
            ?? $scheduleRecord['clock_in_time'] 
            ?? $scheduleRecord['jam_masuk'] 
            ?? $scheduleRecord['shift_in'] 
            ?? null;

        $clockOutTarget = $scheduleRecord['clock_out_target'] 
            ?? $scheduleRecord['clock_out_time'] 
            ?? $scheduleRecord['jam_pulang'] 
            ?? $scheduleRecord['shift_out'] 
            ?? null;

        if ($clockInTarget && $clockOutTarget && $clockOutTarget < $clockInTarget) {
            // Shift malam lintas hari (contoh: 22:00 -> 06:00 hari berikutnya)
            $isNightShift = true;
            // Toleransi jam pulang shift malam hingga 4 jam setelah jam target pulang
            $targetClockOutTs = strtotime("$shiftDate $clockOutTarget +1 day");
            $maxAllowedClockOutTs = $targetClockOutTs + (4 * 3600);
        }
    }

    // Jika tidak ada jadwal shift malam bertuliskan lintas hari, cek berdasarkan jam clock in
    if (!$isNightShift) {
        $clockInHour = (int)date('H', $clockInTs);
        // Jika clock in dilakukan malam hari (jam 18:00 ke atas) dan durasi belum 16 jam
        if ($clockInHour >= 18 && $hoursElapsed <= 16) {
            $isNightShift = true;
            $maxAllowedClockOutTs = $clockInTs + (16 * 3600);
        }
    }

    // Jika merupakan shift malam dan waktu sekarang masih dalam batas toleransi clock out
    if ($isNightShift && $maxAllowedClockOutTs && $nowTs <= $maxAllowedClockOutTs) {
        return $activeAttendance;
    }

    // Selain kondisi di atas (misalnya clock in pagi/siang kemarin dan lupa clock out),
    // sesi dianggap sudah berakhir / kedaluwarsa.
    return null;
}
?>