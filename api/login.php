<?php
require_once '../config/database.php';
require_once '../utils/functions.php';


$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['user_id']) || !isset($input['user_password'])) {
    sendResponse(400, 'User ID dan Password wajib diisi');
}

$user_id = $input['user_id'];
$user_password = $input['user_password'];

$stmt = $pdo->prepare("SELECT * FROM user WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$md5_input = md5($user_password);

$password_acak = pwdgenerate($md5_input);

if ($user && $password_acak === $user['user_password']) {
    $headers = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'sub' => $user['user_id'],
        'user_id' => $user['user_id'], 
        'iat' => time(),           
        'exp' => time() + (60 * 60 * 24) 
    ];

    $jwt = generate_jwt($headers, $payload);

    sendResponse(200, 'Login berhasil', [
        'token' => $jwt,
        'user' => [
            'name' => $user['user_name'],
            'user_id' => $user['user_id'] 
        ]
    ]);
} else {
    sendResponse(401, 'User ID atau Password salah');
}
?>