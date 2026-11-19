<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = $_POST;
}

if (!isset($input['user_id']) || !isset($input['user_password']) || trim((string)$input['user_id']) === '' || trim((string)$input['user_password']) === '') {
    sendResponse(400, 'User ID dan Password wajib diisi');
}

$user_id = trim((string)$input['user_id']);
$user_password = trim((string)$input['user_password']);

$user = verifyUserCredentials($pdo, $user_id, $user_password);

if ($user) {
    // Setelah password cocok, validasi identitas perangkat (device_uuid / imei)
    $approvedDeviceUuid = validateRequestDevice($pdo, $user, $input, true);

    $headers = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'sub' => $user['user_id'],
        'user_id' => $user['user_id'],
        'device_uuid' => $approvedDeviceUuid,
        'iat' => time(),
        'exp' => time() + (60 * 60 * 24 * 30)
    ];

    $jwt = generate_jwt($headers, $payload);
    $now = date('Y-m-d H:i:s');

    try {
        $updateStmt = $pdo->prepare("UPDATE user SET api_token = ?, device_uuid = ?, user_lastlogin = ? WHERE user_id = ?");
        $updateStmt->execute([$jwt, $approvedDeviceUuid, $now, $user['user_id']]);
    } catch (Exception $e) {
        $updateStmt = $pdo->prepare("UPDATE user SET api_token = ? WHERE user_id = ?");
        $updateStmt->execute([$jwt, $user['user_id']]);
    }

    sendResponse(200, 'Login berhasil', [
        'token' => $jwt,
        'user' => [
            'name' => $user['user_name'],
            'user_id' => $user['user_id'],
            'user_jabatan' => $user['user_jabatan'] ?? null,
            'user_type' => $user['user_type'] ?? null,
            'is_security' => isSecurityUser($user),
            'device_uuid' => $approvedDeviceUuid,
            'registered_device_uuid' => $approvedDeviceUuid
        ]
    ]);
} else {
    sendResponse(401, 'User ID atau Password salah');
}
?>