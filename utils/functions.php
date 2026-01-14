<?php

function sendResponse($code, $message, $data = null) {
    http_response_code($code);
    echo json_encode([
        'status' => $code == 200 || $code == 201 ? 'success' : 'error',
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

function triangleArea($lat1, $lng1, $lat2, $lng2, $lat3, $lng3) {
    return abs(
        ($lat1 * ($lng2 - $lng3) + 
         $lat2 * ($lng3 - $lng1) + 
         $lat3 * ($lng1 - $lng2)) / 2.0
    );
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

    $stmt = $pdo->prepare("SELECT * FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(401, 'Token tidak valid atau sesi berakhir');
    }

    return $user;
}
?>