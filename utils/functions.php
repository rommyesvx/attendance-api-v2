<?php

define('JWT_SECRET_KEY', 'AutentikasiAbsensiAPI2026!');
function sendResponse($code, $message, $data = null) {
    http_response_code($code);
    echo json_encode([
        'status' => $code == 200 || $code == 201 ? 'success' : 'error',
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

function generate_jwt($headers, $payload, $secret = JWT_SECRET_KEY) {
	$headers_encoded = rtrim(strtr(base64_encode(json_encode($headers)), '+/', '-_'), '=');
	$payload_encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
	
	$signature = hash_hmac('SHA256', "$headers_encoded.$payload_encoded", $secret, true);
	$signature_encoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
	
	return "$headers_encoded.$payload_encoded.$signature_encoded";
}

function validate_jwt($token, $secret = JWT_SECRET_KEY) {
	$tokenParts = explode('.', $token);
    if (count($tokenParts) != 3) return false;

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

function isPointInPolygon($pointLat, $pointLng, $polygonJson) {
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

function authenticate($pdo) {
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        sendResponse(401, 'Gagal: Request ini butuh TOKEN (Bearer Token).');
    }

    $authHeader = $headers['Authorization'];
    $token = str_replace('Bearer ', '', $authHeader);

    $payload = validate_jwt($token);

    if (!$payload) {
        sendResponse(401, 'Token tidak valid atau sudah kadaluarsa');
    }

    $userId = $payload['sub'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(401, 'User tidak ditemukan');
    }

    return $user;
}
?>