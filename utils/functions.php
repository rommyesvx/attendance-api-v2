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

function pwdgenerate($awal) {
    $awal1  = substr($awal,0,8);
    $awal2  = substr($awal,8,8);
    $awal3  = substr($awal,16,8);
    $awal4  = substr($awal,24,7);
    $awal5  = substr($awal,31,1);
    
    //STARTING ENCRYPT
    $awal6 = pwdrein($awal5);
    return $awal4.$awal2.$awal1.$awal3.$awal6;
}

function pwdrein($w) {
    if ($w=='a'){ return '1'; } elseif ($w=='b'){ return 'z'; }
    elseif ($w=='c'){ return '2'; } elseif ($w=='d'){ return 'f'; }
    elseif ($w=='e'){ return '3'; } elseif ($w=='f'){ return 'x'; }
    elseif ($w=='g'){ return 'c'; } elseif ($w=='h'){ return 'r'; }
    elseif ($w=='i'){ return '4'; } elseif ($w=='j'){ return 's'; }
    elseif ($w=='k'){ return 'q'; } elseif ($w=='l'){ return 'v'; }
    elseif ($w=='m'){ return 'e'; } elseif ($w=='n'){ return '5'; }
    elseif ($w=='o'){ return 'b'; } elseif ($w=='p'){ return '8'; }
    elseif ($w=='q'){ return 'a'; } elseif ($w=='r'){ return '9'; }
    elseif ($w=='s'){ return 'l'; } elseif ($w=='t'){ return '6'; }
    elseif ($w=='u'){ return 'p'; } elseif ($w=='v'){ return 'j'; }
    elseif ($w=='w'){ return 'u'; } elseif ($w=='x'){ return '7'; }
    elseif ($w=='y'){ return 'w'; } elseif ($w=='z'){ return 'o'; }
    elseif ($w=='1'){ return 'g'; } elseif ($w=='2'){ return 'h'; }
    elseif ($w=='3'){ return 'i'; } elseif ($w=='4'){ return 'd'; }
    elseif ($w=='5'){ return 'n'; } elseif ($w=='6'){ return 't'; }
    elseif ($w=='7'){ return 'k'; } elseif ($w=='8'){ return 'y'; }
    elseif ($w=='9'){ return 'm'; } elseif ($w=='0'){ return '0'; }
    return $w;
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