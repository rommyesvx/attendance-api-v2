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

    $stmt = $pdo->prepare("SELECT * FROM user WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        sendResponse(401, 'User tidak ditemukan');
    }

    if (!isset($user['api_token']) || $user['api_token'] !== $token) {
        sendResponse(401, 'Sesi telah berakhir atau akun telah login di perangkat lain');
    }

    return $user;
}

/**
 * Mengambil record data dari tabel schedule (absensi_schedules / absensi_schedule)
 */
function getUserScheduleRecord($pdo, $userId, $date = null) {
    if (!$date) {
        $date = date('Y-m-d');
    }

    $tables = ['absensi_schedules', 'absensi_schedule'];
    foreach ($tables as $tableName) {
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
            $columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);

            $dateCol = null;
            if (in_array('schedule_date', $columns)) {
                $dateCol = 'schedule_date';
            } elseif (in_array('date', $columns)) {
                $dateCol = 'date';
            } elseif (in_array('schedule', $columns)) {
                $dateCol = 'schedule';
            }

            $userCol = null;
            if (in_array('user_id', $columns)) {
                $userCol = 'user_id';
            } elseif (in_array('id_user', $columns)) {
                $userCol = 'id_user';
            }

            if ($dateCol && $userCol) {
                $stmt = $pdo->prepare("
                    SELECT * FROM `{$tableName}` 
                    WHERE `{$userCol}` = ? AND `{$dateCol}` = ? 
                    LIMIT 1
                ");
                $stmt->execute([$userId, $date]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return $row;
                }
            }
        } catch (PDOException $e) {
            continue;
        }
    }

    return null;
}

/**
 * Mengambil data kantor (absensi_offices) untuk user pada tanggal tertentu.
 * Alur penentuan office_id:
 * 1. Cek tabel absensi_schedules / absensi_schedule berdasarkan user_id dan tanggal.
 * 2. Jika tidak ditemukan (atau user tidak ada di tabel schedule), fallback ke office_id dari tabel user.
 * 3. Mengembalikan data record absensi_offices (array) atau null jika tidak ada/tidak valid.
 */
function getUserOffice($pdo, $userId, $fallbackOfficeId = null, $date = null) {
    if (!$date) {
        $date = date('Y-m-d');
    }

    $officeId = null;
    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $date);

    if ($scheduleRecord && !empty($scheduleRecord['office_id'])) {
        $officeId = $scheduleRecord['office_id'];
    }

    // 2. Fallback: Jika tidak ada di tabel schedule, gunakan fallbackOfficeId (dari tabel user)
    if (empty($officeId)) {
        $officeId = $fallbackOfficeId;
    }

    // 3. Ambil data absensi_offices jika officeId tersedia
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
 * Memvalidasi apakah pegawai yang memiliki jadwal di tabel schedule mencoba clock out sebelum waktunya.
 * Mengembalikan array ['valid' => true] atau ['valid' => false, 'message' => '...']
 */
function validateScheduleClockOutTime($pdo, $userId, $shiftDate, $currentTime = null) {
    if (!$currentTime) {
        $currentTime = date('Y-m-d H:i:s');
    }

    $scheduleRecord = getUserScheduleRecord($pdo, $userId, $shiftDate);

    // Jika pegawai TIDAK ada di tabel schedule, diizinkan clock out (fallback)
    if (!$scheduleRecord) {
        return ['valid' => true];
    }

    // Cari kolom target jam pulang
    $clockOutTarget = $scheduleRecord['clock_out_target'] 
        ?? $scheduleRecord['clock_out_time'] 
        ?? $scheduleRecord['jam_pulang'] 
        ?? $scheduleRecord['shift_out'] 
        ?? null;

    // Jika tidak ada batasan jam pulang di tabel schedule, izinkan
    if (!$clockOutTarget) {
        return ['valid' => true];
    }

    // Cari jam masuk untuk cek shift malam/lintas hari
    $clockInTarget = $scheduleRecord['clock_in_target'] 
        ?? $scheduleRecord['clock_in_time'] 
        ?? $scheduleRecord['jam_masuk'] 
        ?? $scheduleRecord['shift_in'] 
        ?? null;

    // Tentukan timestamp target jam pulang
    if ($clockInTarget && $clockOutTarget < $clockInTarget) {
        // Shift malam lintas hari (contoh: 18:00 - 08:00 hari berikutnya)
        $targetTimestamp = strtotime("$shiftDate $clockOutTarget +1 day");
    } else {
        $targetTimestamp = strtotime("$shiftDate $clockOutTarget");
    }

    if (strtotime($currentTime) < $targetTimestamp) {
        $formattedTarget = date('H:i', strtotime($clockOutTarget));
        return [
            'valid' => false,
            'target_time' => $clockOutTarget,
            'message' => "Gagal Clock Out! Belum memasuki jam pulang sesuai jadwal Anda (Jam pulang: {$formattedTarget})."
        ];
    }

    return ['valid' => true];
}
?>