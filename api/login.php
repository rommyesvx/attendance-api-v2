<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['email']) || !isset($input['password'])) {
    sendResponse(400, 'Email dan Password wajib diisi');
}

$email = $input['email'];
$password = $input['password'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    $token = bin2hex(random_bytes(32)); 

    $updateStmt = $pdo->prepare("UPDATE users SET api_token = ? WHERE id = ?");
    $updateStmt->execute([$token, $user['id']]);

    sendResponse(200, 'Login berhasil', [
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email']
        ]
    ]);
} else {
    sendResponse(401, 'Email atau Password salah');
}
?>