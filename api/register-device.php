<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, 'Method tidak diizinkan. Gunakan POST.');
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (empty($input['user_id']) || !isset($input['user_password']) || trim((string)$input['user_password']) === '') {
    sendResponse(400, 'User ID dan Password wajib diisi');
}

$userId = trim((string)$input['user_id']);
$userPassword = trim((string)$input['user_password']);
$deviceUuid = extractDeviceUuid($input);

if ($deviceUuid === '') {
    sendResponse(400, 'ID Perangkat (UUID / IMEI) wajib dikirim');
}

$deviceModel = isset($input['device_model']) && trim((string)$input['device_model']) !== ''
    ? substr(trim((string)$input['device_model']), 0, 128)
    : null;

$devicePlatform = isset($input['device_platform']) && trim((string)$input['device_platform']) !== ''
    ? substr(trim((string)$input['device_platform']), 0, 64)
    : null;

$reason = isset($input['reason']) ? trim((string)$input['reason']) : '';
if ($reason === '' && (!empty($input['reason_category']) || !empty($input['reason_detail']))) {
    $reasonParts = array_filter([
        isset($input['reason_category']) ? trim((string)$input['reason_category']) : '',
        isset($input['reason_detail']) ? trim((string)$input['reason_detail']) : ''
    ], function ($part) {
        return $part !== '';
    });
    $reason = implode(' - ', $reasonParts);
}
$reasonValue = $reason !== '' ? $reason : null;

// 1. Validasi user_id & user_password
$user = verifyUserCredentials($pdo, $userId, $userPassword);
if (!$user) {
    sendResponse(401, 'User ID atau Password salah');
}

if (!isPilotTestUser($user)) {
    sendResponse(403, 'Fitur registrasi perangkat saat ini sedang dalam tahap pengujian terbatas.');
}

try {
    // 2. Cek apakah device_uuid sudah terikat (approved) pada user_id lain (1 HP tidak boleh dipakai 2 akun)
    if (isDeviceBoundToAnotherUser($pdo, $deviceUuid, $user['user_id'])) {
        sendDeviceError(
            409,
            'DEVICE_ALREADY_BOUND',
            'bound_other_account',
            'Perangkat ini sudah terdaftar dan terikat pada akun pegawai lain. Satu perangkat hanya dapat digunakan untuk satu akun.'
        );
    }

    // Cek apakah perangkat ini sebenarnya sudah aktif (approved) pada akun user ini sendiri
    $approvedUuid = getApprovedUserDeviceUuid($pdo, $user);
    if ($approvedUuid !== null && $approvedUuid === $deviceUuid) {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM device_registrations WHERE user_id = ?");
        $countStmt->execute([$user['user_id']]);
        $registrationCount = (int)$countStmt->fetchColumn();

        sendResponse(200, 'Perangkat ini sudah terdaftar dan disetujui pada akun Anda. Silakan langsung Login.', [
            'registration_count' => max(1, $registrationCount),
            'device_status' => 'approved'
        ]);
    }

    $now = date('Y-m-d H:i:s');

    // Cek apakah akun ini sudah pernah memiliki perangkat yang disetujui sebelumnya
    $approvedHistoryStmt = $pdo->prepare("SELECT COUNT(*) FROM device_registrations WHERE user_id = ? AND status = 'approved'");
    $approvedHistoryStmt->execute([$user['user_id']]);
    $approvedHistoryCount = (int)$approvedHistoryStmt->fetchColumn();

    // Jika belum pernah punya perangkat approved sama sekali (pendaftaran pertama kali), otomatis langsung APPROVED
    if ($approvedHistoryCount === 0) {
        $firstReason = $reasonValue !== null ? $reasonValue : 'Pendaftaran Pertama';

        // Jika sebelumnya sempat ada baris pending pertama untuk user ini, ubah menjadi approved; jika belum ada, INSERT baru
        $firstPendingStmt = $pdo->prepare("SELECT id FROM device_registrations WHERE user_id = ? ORDER BY id ASC LIMIT 1");
        $firstPendingStmt->execute([$user['user_id']]);
        $firstExistingRow = $firstPendingStmt->fetch(PDO::FETCH_ASSOC);

        if ($firstExistingRow) {
            $upgradeFirstStmt = $pdo->prepare("
                UPDATE device_registrations
                SET device_uuid = ?,
                    device_model = COALESCE(?, device_model),
                    device_platform = COALESCE(?, device_platform),
                    reason = ?,
                    status = 'approved',
                    requested_at = ?,
                    reviewed_by = 'AUTO_FIRST_DEVICE',
                    reviewed_at = ?
                WHERE id = ?
            ");
            $upgradeFirstStmt->execute([
                $deviceUuid,
                $deviceModel,
                $devicePlatform,
                $firstReason,
                $now,
                $now,
                $firstExistingRow['id']
            ]);
        } else {
            $insertFirstStmt = $pdo->prepare("
                INSERT INTO device_registrations
                (user_id, device_uuid, device_model, device_platform, reason, status, requested_at, reviewed_by, reviewed_at)
                VALUES (?, ?, ?, ?, ?, 'approved', ?, 'AUTO_FIRST_DEVICE', ?)
            ");
            $insertFirstStmt->execute([
                $user['user_id'],
                $deviceUuid,
                $deviceModel,
                $devicePlatform,
                $firstReason,
                $now,
                $now
            ]);
        }

        try {
            $syncUserStmt = $pdo->prepare("UPDATE user SET device_uuid = ? WHERE user_id = ?");
            $syncUserStmt->execute([$deviceUuid, $user['user_id']]);
        } catch (Exception $e) {
            // Abaikan jika kolom user.device_uuid belum tersedia
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM device_registrations WHERE user_id = ?");
        $countStmt->execute([$user['user_id']]);
        $registrationCount = (int)$countStmt->fetchColumn();

        sendResponse(
            200,
            'Registrasi perangkat pertama berhasil dan otomatis disetujui. Silakan langsung Login.',
            [
                'registration_count' => max(1, $registrationCount),
                'device_status' => 'approved'
            ]
        );
    }

    // Untuk pendaftaran ke-2 dan seterusnya (Ganti HP / Reset Pabrik), wajib menunggu approval Admin (status = 'pending')
    // Cek apakah sudah ada pengajuan pending untuk device_uuid yang sama dari user ini (mencegah duplikasi klik ganda)
    $existingPendingStmt = $pdo->prepare("
        SELECT id
        FROM device_registrations
        WHERE user_id = ? AND device_uuid = ? AND status = 'pending'
        ORDER BY id DESC
        LIMIT 1
    ");
    $existingPendingStmt->execute([$user['user_id'], $deviceUuid]);
    $existingPending = $existingPendingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingPending) {
        $updatePendingStmt = $pdo->prepare("
            UPDATE device_registrations
            SET device_model = COALESCE(?, device_model),
                device_platform = COALESCE(?, device_platform),
                reason = COALESCE(?, reason),
                requested_at = ?
            WHERE id = ?
        ");
        $updatePendingStmt->execute([
            $deviceModel,
            $devicePlatform,
            $reasonValue,
            $now,
            $existingPending['id']
        ]);
    } else {
        // Batalkan pengajuan pending lama untuk device berbeda (jika ada) agar hanya pengajuan terbaru yang pending
        $cancelOldPendingStmt = $pdo->prepare("
            UPDATE device_registrations
            SET status = 'rejected',
                reviewed_by = 'SYSTEM_REPLACED',
                reviewed_at = ?
            WHERE user_id = ? AND status = 'pending' AND device_uuid != ?
        ");
        $cancelOldPendingStmt->execute([$now, $user['user_id'], $deviceUuid]);

        // 3. Simpan baris baru ke device_registrations dengan status = 'pending'
        $insertStmt = $pdo->prepare("
            INSERT INTO device_registrations
            (user_id, device_uuid, device_model, device_platform, reason, status, requested_at)
            VALUES (?, ?, ?, ?, ?, 'pending', ?)
        ");
        $insertStmt->execute([
            $user['user_id'],
            $deviceUuid,
            $deviceModel,
            $devicePlatform,
            $reasonValue,
            $now
        ]);
    }

    // 4. Hitung jumlah riwayat pengajuan user
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM device_registrations WHERE user_id = ?");
    $countStmt->execute([$user['user_id']]);
    $registrationCount = (int)$countStmt->fetchColumn();

    sendResponse(
        200,
        'Pengajuan registrasi perangkat berhasil dikirim dan menunggu persetujuan Admin.',
        [
            'registration_count' => $registrationCount,
            'device_status' => 'pending'
        ]
    );
} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server saat memproses registrasi perangkat: ' . $e->getMessage());
}
?>