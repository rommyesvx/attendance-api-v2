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
    $headers = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'sub' => $user['id'],
        'email' => $user['email'], 
        'iat' => time(),           
        'exp' => time() + (60 * 60 * 24) // Expired dalam 24 Jam
    ];

    $jwt = generate_jwt($headers, $payload);

    sendResponse(200, 'Login berhasil', [
        'token' => $jwt,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
        ]
    ]);
} else {
    sendResponse(401, 'Email atau Password salah');
}
?>