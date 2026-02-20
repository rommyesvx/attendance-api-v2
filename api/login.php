<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['nip']) || !isset($input['password'])) {
    sendResponse(400, 'NIP dan Password wajib diisi');
}

$nip = $input['nip'];
$password = $input['password'];

$stmt = $pdo->prepare("SELECT * FROM users WHERE nip = ?");
$stmt->execute([$nip]);
$user = $stmt->fetch();

if ($user && password_verify($password, $user['password'])) {
    $headers = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'sub' => $user['id'],
        'nip' => $user['nip'], 
        'iat' => time(),           
        'exp' => time() + (60 * 60 * 24) 
    ];

    $jwt = generate_jwt($headers, $payload);

    sendResponse(200, 'Login berhasil', [
        'token' => $jwt,
        'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'nip' => $user['nip'] 
        ]
    ]);
} else {
    sendResponse(401, 'NIP atau Password salah');
}
?>