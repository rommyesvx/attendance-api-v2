<?php

define('JWT_SECRET_KEY', 'AutentikasiAbsensiAPI2026!');

/**
 * Mode Testing / Pilot untuk fitur Patrol & Register New Device.
 * Jika PILOT_MODE_ENABLED = true, maka fitur Patrol dan validasi Register Device
 * HANYA aktif untuk user_id yang terdaftar di PILOT_TEST_USER_IDS.
 * Jika siap dirilis untuk seluruh pegawai, cukup ubah PILOT_MODE_ENABLED menjadi false.
 */
define('PILOT_MODE_ENABLED', true);
define('PILOT_TEST_USER_IDS', [
    '3175082710030004', // Satpam Rommy
]);

function isPilotTestUser($userOrId)
{
    if (!PILOT_MODE_ENABLED) {
        return true;
    }
    $userId = is_array($userOrId) ? ($userOrId['user_id'] ?? '') : (string)$userOrId;
    $userId = trim((string)$userId);
    return in_array($userId, PILOT_TEST_USER_IDS, true);
}
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

    if (!isPilotTestUser($user)) {
        return isset($user['device_uuid']) && trim((string)$user['device_uuid']) !== ''
            ? trim((string)$user['device_uuid'])
            : null;
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

        // Jika belum ada baris berstatus 'approved' di tabel device_registrations,
        // pastikan kolom user.device_uuid dikosongkan agar sumber kebenaran tetap dari device_registrations
        if ($userColUuid !== null) {
            try {
                $clearStmt = $pdo->prepare("UPDATE user SET device_uuid = NULL WHERE user_id = ?");
                $clearStmt->execute([$userId]);
            } catch (Exception $e) {
            }
        }
    } catch (Exception $e) {
        return null;
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
    // Selama mode testing (PILOT_MODE_ENABLED = true), akun di luar whitelist tidak dikenakan validasi device
    if (!isPilotTestUser($user)) {
        $deviceUuid = extractDeviceUuid($input);
        if ($deviceUuid !== '') {
            return $deviceUuid;
        }
        return isset($user['device_uuid']) && trim((string)$user['device_uuid']) !== ''
            ? trim((string)$user['device_uuid'])
            : null;
    }

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

function normalizePolygonVertices($polygonJson)
{
    $vertices = is_array($polygonJson) ? $polygonJson : json_decode((string)$polygonJson, true);
    if (!is_array($vertices) || count($vertices) < 3) {
        return [];
    }

    $normalized = [];
    foreach ($vertices as $vertex) {
        if (!is_array($vertex)) {
            continue;
        }
        if (isset($vertex['lat'], $vertex['lng'])) {
            $normalized[] = ['lat' => (float)$vertex['lat'], 'lng' => (float)$vertex['lng']];
        } elseif (isset($vertex['latitude'], $vertex['longitude'])) {
            $normalized[] = ['lat' => (float)$vertex['latitude'], 'lng' => (float)$vertex['longitude']];
        } elseif (isset($vertex[0], $vertex[1])) {
            $normalized[] = ['lat' => (float)$vertex[0], 'lng' => (float)$vertex[1]];
        }
    }

    return count($normalized) >= 3 ? $normalized : [];
}

function distanceMeters($lat1, $lng1, $lat2, $lng2)
{
    $earthRadius = 6371000.0;
    $dLat = deg2rad((float)$lat2 - (float)$lat1);
    $dLng = deg2rad((float)$lng2 - (float)$lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2))
        * sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    return $earthRadius * $c;
}

function pointToSegmentDistanceMeters($pLat, $pLng, $aLat, $aLng, $bLat, $bLng)
{
    $refLatRad = deg2rad(((float)$aLat + (float)$bLat + (float)$pLat) / 3.0);
    $metersPerLat = 111132.92;
    $metersPerLng = 111412.84 * cos($refLatRad);

    $ax = (float)$aLng * $metersPerLng;
    $ay = (float)$aLat * $metersPerLat;
    $bx = (float)$bLng * $metersPerLng;
    $by = (float)$bLat * $metersPerLat;
    $px = (float)$pLng * $metersPerLng;
    $py = (float)$pLat * $metersPerLat;

    $dx = $bx - $ax;
    $dy = $by - $ay;
    $lenSq = ($dx * $dx) + ($dy * $dy);

    if ($lenSq <= 0.0) {
        return hypot($px - $ax, $py - $ay);
    }

    $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq;
    $t = max(0.0, min(1.0, $t));

    $projX = $ax + $t * $dx;
    $projY = $ay + $t * $dy;

    return hypot($px - $projX, $py - $projY);
}

function isPointInPolygon($pointLat, $pointLng, $polygonJson)
{
    $vertices = normalizePolygonVertices($polygonJson);
    $verticesCount = count($vertices);

    if ($verticesCount < 3) {
        return false;
    }

    $lat = (float)$pointLat;
    $lng = (float)$pointLng;
    $isInside = false;

    for ($i = 0, $j = $verticesCount - 1; $i < $verticesCount; $j = $i++) {
        $lati = $vertices[$i]['lat'];
        $lngi = $vertices[$i]['lng'];

        $latj = $vertices[$j]['lat'];
        $lngj = $vertices[$j]['lng'];

        if ($lngj == $lngi) {
            continue;
        }

        $intersect = (($lngi > $lng) != ($lngj > $lng)) &&
            ($lat < ($latj - $lati) * ($lng - $lngi) / ($lngj - $lngi) + $lati);

        if ($intersect) {
            $isInside = !$isInside;
        }
    }

    return $isInside;
}

function isPointWithinGeofence($pointLat, $pointLng, $office, $toleranceMeters = 25.0)
{
    if (!$office || !is_numeric($pointLat) || !is_numeric($pointLng)) {
        return false;
    }

    $lat = (float)$pointLat;
    $lng = (float)$pointLng;

    if (!empty($office['polygon_coordinates'])) {
        if (isPointInPolygon($lat, $lng, $office['polygon_coordinates'])) {
            return true;
        }

        if ($toleranceMeters > 0) {
            $vertices = normalizePolygonVertices($office['polygon_coordinates']);
            $count = count($vertices);
            for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
                $dist = pointToSegmentDistanceMeters(
                    $lat,
                    $lng,
                    $vertices[$j]['lat'],
                    $vertices[$j]['lng'],
                    $vertices[$i]['lat'],
                    $vertices[$i]['lng']
                );
                if ($dist <= $toleranceMeters) {
                    return true;
                }
            }
        }

        return false;
    }

    if (isset($office['latitude'], $office['longitude']) && is_numeric($office['latitude']) && is_numeric($office['longitude'])) {
        $radius = isset($office['radius']) && is_numeric($office['radius']) ? (float)$office['radius'] : 100.0;
        return distanceMeters($lat, $lng, (float)$office['latitude'], (float)$office['longitude']) <= ($radius + max(0.0, (float)$toleranceMeters));
    }

    return false;
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

        $approvedUuid = validateRequestDevice($pdo, $user, ['device_uuid' => $sessionDeviceUuid], true);
        $user['device_uuid'] = $approvedUuid;
    }

    return $user;
}

/**
 * Mengecek apakah user memiliki jabatan Petugas Keamanan (tanpa batasan mode pilot).
 * Digunakan untuk aturan operasional absensi seperti shift malam lintas hari.
 */
function isSecurityRole($user)
{
    if (!is_array($user)) {
        return false;
    }
    $jabatan = trim((string)($user['user_jabatan'] ?? ''));
    return strcasecmp($jabatan, 'Petugas Keamanan') === 0;
}

/**
 * Mengecek apakah user memiliki jabatan Petugas Keamanan.
 * Fitur absen tiap jam (patroli) hanya berlaku untuk Petugas Keamanan.
 */
function isSecurityUser($user)
{
    if (!is_array($user)) {
        return false;
    }
    // Selama mode testing (PILOT_MODE_ENABLED = true), fitur patroli hanya aktif untuk user_id testing
    if (!isPilotTestUser($user)) {
        return false;
    }
    return isSecurityRole($user);
}

/**
 * Menormalisasi nilai waktu jam shift (mengembalikan null jika kosong atau 00:00:00).
 */
function normalizeShiftTime($timeStr)
{
    if ($timeStr === null) {
        return null;
    }
    $trimmed = trim((string)$timeStr);
    if ($trimmed === '' || $trimmed === '00:00:00' || $trimmed === '00:00' || $trimmed === '-') {
        return null;
    }
    return $trimmed;
}

/**
 * Menormalisasi record gabungan dari master_shift_user dan master_shift.
 */
function normalizeMasterShiftRecord($row, $date)
{
    if (!$row) {
        return null;
    }

    $jamMasuk = normalizeShiftTime($row['shift_jam_masuk'] ?? null);
    $jamPulang = normalizeShiftTime($row['shift_jam_pulang'] ?? null);
    $shiftName = trim((string)($row['shift_name'] ?? ''));

    // Placeholder: Jika ke depannya rumus rotasi untuk shift_type 2 & 3 (saat jam masih 00:00:00)
    // sudah ditentukan, perhitungannya dapat ditempatkan di sini.

    $tipeShift = null;
    $isOvernight = false;

    if ($jamMasuk !== null && $jamPulang !== null) {
        if ($jamPulang < $jamMasuk || (int)substr($jamMasuk, 0, 2) >= 18 || stripos($shiftName, 'malam') !== false) {
            $tipeShift = 'malam';
            $isOvernight = true;
        } else {
            $tipeShift = 'pagi';
            $isOvernight = false;
        }
    } elseif (stripos($shiftName, 'malam') !== false) {
        $tipeShift = 'malam';
        $isOvernight = true;
    }

    return [
        'id'                => $row['shiftuser_id'] ?? null,
        'shiftuser_id'      => $row['shiftuser_id'] ?? null,
        'user_id'           => $row['user_id'] ?? null,
        'shift_id'          => $row['shift_id'] ?? null,
        'shift_name'        => $shiftName !== '' ? $shiftName : null,
        'shift_type'        => $row['shift_type'] ?? null,
        'shift_group'       => $row['shift_group'] ?? null,
        'shift_team'        => $row['shift_team'] ?? null,
        'shift_mulai'       => $row['shift_mulai'] ?? null,
        'shift_toleransi'   => isset($row['shift_toleransi']) ? (int)$row['shift_toleransi'] : 0,
        'shift_active'      => $row['shift_active'] ?? '1',
        'shiftuser_dari'    => $row['shiftuser_dari'] ?? null,
        'shiftuser_sampai'  => $row['shiftuser_sampai'] ?? null,
        'shiftuser_active'  => $row['shiftuser_active'] ?? '1',
        'schedule_date'     => $date,
        'tanggal_shift'     => $date,
        'tipe_shift'        => $tipeShift,
        'is_overnight'      => $isOvernight,
        'shift_jam_masuk'   => $row['shift_jam_masuk'] ?? null,
        'shift_jam_pulang'  => $row['shift_jam_pulang'] ?? null,
        'jam_masuk'         => $jamMasuk,
        'jam_pulang'        => $jamPulang,
        'clock_in_target'   => $jamMasuk,
        'clock_out_target'  => $jamPulang
    ];
}

/**
 * Mengambil record data shift karyawan dari tabel master_shift_user dan master_shift
 */
function getUserScheduleRecord($pdo, $userId, $date = null)
{
    if (!$date) {
        $date = date('Y-m-d');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                msu.shiftuser_id,
                msu.user_id,
                msu.shift_id,
                msu.shiftuser_dari,
                msu.shiftuser_sampai,
                msu.shiftuser_active,
                msu.shiftuser_createddate,
                ms.shift_name,
                ms.shift_jam_masuk,
                ms.shift_jam_pulang,
                ms.shift_type,
                ms.shift_group,
                ms.shift_team,
                ms.shift_mulai,
                ms.shift_toleransi,
                ms.shift_active
            FROM master_shift_user msu
            INNER JOIN master_shift ms ON msu.shift_id = ms.shift_id
            WHERE msu.user_id = ?
              AND msu.shiftuser_active = '1'
              AND ms.shift_active = '1'
              AND (msu.shiftuser_dari IS NULL OR msu.shiftuser_dari <= ?)
              AND (msu.shiftuser_sampai IS NULL OR msu.shiftuser_sampai >= ?)
            ORDER BY msu.shiftuser_dari DESC, msu.shiftuser_createddate DESC, msu.shiftuser_id DESC
            LIMIT 1
        ");
        $stmt->execute([$userId, $date, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $fallbackStmt = $pdo->prepare("
                SELECT 
                    msu.shiftuser_id,
                    msu.user_id,
                    msu.shift_id,
                    msu.shiftuser_dari,
                    msu.shiftuser_sampai,
                    msu.shiftuser_active,
                    msu.shiftuser_createddate,
                    ms.shift_name,
                    ms.shift_jam_masuk,
                    ms.shift_jam_pulang,
                    ms.shift_type,
                    ms.shift_group,
                    ms.shift_team,
                    ms.shift_mulai,
                    ms.shift_toleransi,
                    ms.shift_active
                FROM master_shift_user msu
                INNER JOIN master_shift ms ON msu.shift_id = ms.shift_id
                WHERE msu.user_id = ?
                  AND msu.shiftuser_active = '1'
                  AND ms.shift_active = '1'
                  AND (msu.shiftuser_sampai IS NULL OR msu.shiftuser_sampai >= ?)
                ORDER BY msu.shiftuser_createddate DESC, msu.shiftuser_id DESC
                LIMIT 1
            ");
            $fallbackStmt->execute([$userId, $date]);
            $row = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($row) {
            return normalizeMasterShiftRecord($row, $date);
        }
    } catch (PDOException $e) {
        return null;
    }

    return null;
}

function getUserOffice($pdo, $userId, $fallbackOfficeId = null, $date = null)
{
    if (!$date) {
        $date = date('Y-m-d');
    }

    $officeId = $fallbackOfficeId;

    // Jika fallbackOfficeId belum dikirim, ambil dari tabel user
    if (empty($officeId) && $userId) {
        try {
            $uStmt = $pdo->prepare("SELECT office_id FROM user WHERE user_id = ? LIMIT 1");
            $uStmt->execute([$userId]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
            if ($uRow && !empty($uRow['office_id'])) {
                $officeId = $uRow['office_id'];
            }
        } catch (Exception $e) {
        }
    }

    // Jika office_id masih kosong/null, otomatis diset ke 2 dan diupdate ke tabel user
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

    // Ambil data absensi_offices jika officeId tersedia
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
 * Memvalidasi apakah pegawai yang memiliki jadwal di tabel master_shift mencoba clock out sebelum waktunya.
 * Mengembalikan array ['valid' => true] atau ['valid' => false, 'message' => '...']
 */
function validateScheduleClockOutTime($pdo, $userId, $shiftDate, $currentTime = null)
{
    if (!$currentTime) {
        $currentTime = date('Y-m-d H:i:s');
    }

    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $shiftDate);

    // Jika pegawai TIDAK ada di tabel shift, diizinkan clock out (fallback)
    if (!$scheduleRecord) {
        return ['valid' => true];
    }

    // Cari kolom target jam pulang dari master_shift
    $clockOutTarget = normalizeShiftTime(
        $scheduleRecord['clock_out_target']
        ?? $scheduleRecord['shift_jam_pulang']
        ?? $scheduleRecord['jam_pulang']
        ?? null
    );

    // Jika jam pulang masih 00:00:00 / belum diisi di master_shift, izinkan
    if (!$clockOutTarget) {
        return ['valid' => true];
    }

    // Cari jam masuk untuk cek shift malam/lintas hari
    $clockInTarget = normalizeShiftTime(
        $scheduleRecord['clock_in_target']
        ?? $scheduleRecord['shift_jam_masuk']
        ?? $scheduleRecord['jam_masuk']
        ?? null
    );

    $tipeShift = strtolower(trim((string)($scheduleRecord['tipe_shift'] ?? '')));
    $isOvernightSchedule = ($tipeShift === 'malam' || !empty($scheduleRecord['is_overnight']));
    $clockOutTsSameDay = strtotime("$shiftDate $clockOutTarget");
    $clockInTsSameDay = $clockInTarget ? strtotime("$shiftDate $clockInTarget") : false;

    if ($clockInTsSameDay !== false && $clockOutTsSameDay !== false && $clockOutTsSameDay <= $clockInTsSameDay) {
        $isOvernightSchedule = true;
    }

    // Tentukan timestamp target jam pulang
    if ($isOvernightSchedule && ($clockInTsSameDay === false || $clockOutTsSameDay <= $clockInTsSameDay)) {
        // Shift malam lintas hari (contoh: 19:00 - 07:00 hari berikutnya)
        $targetTimestamp = strtotime("$shiftDate $clockOutTarget +1 day");
    } else {
        $targetTimestamp = $clockOutTsSameDay;
    }

    if ($targetTimestamp !== false && strtotime($currentTime) < $targetTimestamp) {
        $formattedTarget = date('H:i', strtotime($clockOutTarget));
        return [
            'valid' => false,
            'target_time' => $clockOutTarget,
            'message' => "Gagal Clock Out! Belum memasuki jam pulang sesuai jadwal shift Anda (Jam pulang: {$formattedTarget})."
        ];
    }

    return ['valid' => true];
}

/**
 * Memvalidasi apakah pegawai yang memiliki jadwal di tabel master_shift mencoba clock in terlalu awal.
 * Batas toleransi paling awal adalah 30 menit sebelum jam masuk.
 * Mengembalikan array ['valid' => true] atau ['valid' => false, 'message' => '...']
 */
function validateScheduleClockInTime($pdo, $userId, $shiftDate, $currentTime = null)
{
    if (!$currentTime) {
        $currentTime = date('Y-m-d H:i:s');
    }

    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $shiftDate);

    // Jika pegawai TIDAK ada di tabel shift, diizinkan clock in kapan saja (bebas)
    if (!$scheduleRecord) {
        return ['valid' => true];
    }

    // Cari kolom target jam masuk dari master_shift
    $clockInTarget = normalizeShiftTime(
        $scheduleRecord['clock_in_target']
        ?? $scheduleRecord['shift_jam_masuk']
        ?? $scheduleRecord['jam_masuk']
        ?? null
    );

    // Jika jam masuk masih 00:00:00 / belum diisi di master_shift, izinkan
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
 * Mem-parse nilai waktu absensi (DATETIME atau TIME) menjadi Unix timestamp secara aman.
 */
function parseAttendanceTimestamp($timeStr, $fallbackDate = null)
{
    $raw = trim((string)$timeStr);
    if ($raw === '') {
        return false;
    }
    if (strpos($raw, ' ') === false && strpos($raw, '-') === false && $fallbackDate) {
        $raw = $fallbackDate . ' ' . $raw;
    }
    return strtotime($raw);
}

/**
 * Mengevaluasi apakah record absensi dari hari sebelumnya masih termasuk sesi shift malam
 * lintas hari yang valid pada waktu saat ini ($nowTs).
 */
function isValidOvernightAttendance($pdo, $userId, array $attendance, $nowTs, $user = null)
{
    $shiftDate = $attendance['date'] ?? null;
    if (!$shiftDate) {
        return false;
    }

    $clockInTs = parseAttendanceTimestamp($attendance['clock_in_time'] ?? '', $shiftDate);
    if ($clockInTs === false) {
        return false;
    }

    $hoursElapsed = ($nowTs - $clockInTs) / 3600;
    if ($hoursElapsed < 0 || $hoursElapsed > 24) {
        return false;
    }

    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $shiftDate);
    $isNightShift = false;
    $maxAllowedClockOutTs = null;
    $isRotatingSecurityShift = false;

    if ($scheduleRecord) {
        $clockInTarget = normalizeShiftTime(
            $scheduleRecord['clock_in_target']
            ?? $scheduleRecord['shift_jam_masuk']
            ?? $scheduleRecord['jam_masuk']
            ?? null
        );

        $clockOutTarget = normalizeShiftTime(
            $scheduleRecord['clock_out_target']
            ?? $scheduleRecord['shift_jam_pulang']
            ?? $scheduleRecord['jam_pulang']
            ?? null
        );

        $tipeShift = strtolower(trim((string)($scheduleRecord['tipe_shift'] ?? '')));
        $shiftType = (string)($scheduleRecord['shift_type'] ?? '');
        $isRotatingSecurityShift = in_array($shiftType, ['2', '3'], true);

        $clockInTargetTs = $clockInTarget ? strtotime("$shiftDate $clockInTarget") : false;
        $clockOutTargetTs = $clockOutTarget ? strtotime("$shiftDate $clockOutTarget") : false;

        $isOvernightSchedule = (
            $tipeShift === 'malam'
            || !empty($scheduleRecord['is_overnight'])
            || ($clockInTargetTs !== false && $clockOutTargetTs !== false && $clockOutTargetTs <= $clockInTargetTs)
        );

        if ($isOvernightSchedule) {
            // Shift malam lintas hari (contoh: 19:00 -> 07:00 atau 22:00 -> 06:00 hari berikutnya)
            $isNightShift = true;
            if ($clockOutTarget) {
                $targetClockOutTs = ($clockInTargetTs === false || $clockOutTargetTs <= $clockInTargetTs)
                    ? strtotime("$shiftDate $clockOutTarget +1 day")
                    : $clockOutTargetTs;
                // Toleransi jam pulang shift malam hingga 6 jam setelah jam target pulang
                $maxAllowedClockOutTs = $targetClockOutTs + (6 * 3600);
            } else {
                $maxAllowedClockOutTs = $clockInTs + (20 * 3600);
            }
        }
    }

    // Jika tidak ada jadwal shift malam eksplisit (misal shift_type 2 & 3 dengan jam 00:00:00,
    // atau petugas keamanan yang mengambil shift malam di luar jam default)
    if (!$isNightShift) {
        $isSecurity = isSecurityRole($user) || $isRotatingSecurityShift;
        if (!$isSecurity && $user === null) {
            try {
                $uStmt = $pdo->prepare("SELECT user_jabatan FROM user WHERE user_id = ? LIMIT 1");
                $uStmt->execute([$userId]);
                $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uRow) {
                    $isSecurity = isSecurityRole($uRow);
                }
            } catch (Exception $e) {
            }
        }

        $clockInHour = (int)date('H', $clockInTs);
        if ($isSecurity) {
            // Petugas keamanan shift malam (19:00 -> 07:00, toleransi masuk lebih awal mulai 17:00 & lembur hingga 20 jam)
            if ($clockInHour >= 17 && $hoursElapsed <= 20) {
                $isNightShift = true;
                $maxAllowedClockOutTs = $clockInTs + (20 * 3600);
            }
        } elseif ($clockInHour >= 18 && $hoursElapsed <= 16) {
            // Pegawai non-keamanan: jika clock in malam hari (jam 18:00 ke atas) dan durasi belum 16 jam
            $isNightShift = true;
            $maxAllowedClockOutTs = $clockInTs + (16 * 3600);
        }
    }

    return ($isNightShift && $maxAllowedClockOutTs && $nowTs <= $maxAllowedClockOutTs);
}

/**
 * Mengambil sesi absensi aktif yang BELUM Clock Out dan MASIH VALID untuk di-Clock Out.
 * Mengabaikan sesi dari hari sebelumnya jika pegawai lupa Clock Out (kecuali shift malam lintas hari).
 */
function getActiveAttendance($pdo, $userId, $currentTime = null, $user = null)
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

    $shiftDate = $activeAttendance['date'];
    $clockInTs = parseAttendanceTimestamp($activeAttendance['clock_in_time'] ?? '', $shiftDate);
    if ($clockInTs === false) {
        return null;
    }

    $hoursElapsed = ($nowTs - $clockInTs) / 3600;

    // Batas maksimal mutlak 24 jam
    if ($hoursElapsed > 24) {
        return null;
    }

    // Jika sesi absensi adalah HARI INI, maka sesi tersebut pasti aktif
    if ($shiftDate === $today) {
        return $activeAttendance;
    }

    // Jika sesi absensi berasal dari HARI SEBELUMNYA ($shiftDate < $today):
    // Validasi apakah ini shift malam lintas hari (misal 19:00 tgl 25 -> 07:00 tgl 26)
    if (isValidOvernightAttendance($pdo, $userId, $activeAttendance, $nowTs, $user)) {
        return $activeAttendance;
    }

    // Selain kondisi di atas (misalnya clock in pagi/siang kemarin dan lupa clock out),
    // sesi dianggap sudah berakhir / kedaluwarsa.
    return null;
}

/**
 * Mengambil sesi absensi shift malam kemarin yang sudah sempat Clock Out hari ini,
 * agar pembaruan jam pulang (re-clock-out) pada endpoint clock-out tetap dapat dilakukan.
 */
function getRecentOvernightAttendance($pdo, $userId, $currentTime = null, $user = null)
{
    if (!$currentTime) {
        $currentTime = date('Y-m-d H:i:s');
    }
    $nowTs = strtotime($currentTime);
    $yesterday = date('Y-m-d', strtotime(date('Y-m-d', $nowTs) . ' -1 day'));

    $stmt = $pdo->prepare("
        SELECT * FROM absensi_attendances
        WHERE user_id = ? AND is_confirmed = 1 AND date = ?
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId, $yesterday]);
    $yesterdayAtt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$yesterdayAtt) {
        return null;
    }

    if (isValidOvernightAttendance($pdo, $userId, $yesterdayAtt, $nowTs, $user)) {
        return $yesterdayAtt;
    }

    return null;
}
?>