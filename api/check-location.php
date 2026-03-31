<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['latitude']) || !isset($input['longitude'])) {
    sendResponse(400, 'Koordinat latitude dan longitude wajib dikirim');
}

$userLat = $input['latitude'];
$userLng = $input['longitude'];
$today   = date('Y-m-d');

$stmt_holiday = $pdo->prepare("SELECT name FROM absensi_holidays WHERE date = ?");
$stmt_holiday->execute([$today]);
$holiday = $stmt_holiday->fetch(PDO::FETCH_ASSOC);

if ($holiday) {
    sendResponse(400, "Gagal Clock In! Hari ini libur: " . $holiday['name']);
    exit;
}

$officeStmt = $pdo->prepare("SELECT * FROM absensi_offices WHERE id = ?");
$officeStmt->execute([$user['office_id']]);
$office = $officeStmt->fetch();

$inArea = isPointInPolygon($userLat, $userLng, $office['polygon_coordinates']);

$attendanceType = $inArea ? 'KDK' : 'KDM';

$checkStmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
$checkStmt->execute([$user['user_id'], $today]);
$attendance = $checkStmt->fetch();

try {
    if (!$attendance || $attendance['clock_out_time'] != NULL) {
        if ($attendanceType === 'KDK') {
            sendResponse(200, 'Lokasi valid (KDK)', [
                'status' => 'success',
                'location' => 'KDK'
            ]);
        } else {
            sendResponse(202, 'Lokasi diluar area (KDM)', [
                'status' => 'pending_confirmation',
                'location' => 'KDM'
            ]);
        }
    } else {
        sendResponse(400, 'Anda masih memiliki sesi absen aktif. Silakan Clock Out terlebih dahulu sebelum memulai absen baru.');
    }

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>
