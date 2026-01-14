<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);
$today = date('Y-m-d');

try {
    $stmt = $pdo->prepare("SELECT * FROM attendances WHERE user_id = ? AND date = ?");
    $stmt->execute([$user['id'], $today]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    $statusAbsen = 'not_clocked_in';
    $jamMasuk = null;
    $jamPulang = null;

    if ($attendance) {
        if ($attendance['clock_out_time'] == NULL) {
            $statusAbsen = 'clocked_in';
            $jamMasuk = $attendance['clock_in_time'];
        } else {
            $statusAbsen = 'checked_out';
            $jamMasuk = $attendance['clock_in_time'];
            $jamPulang = $attendance['clock_out_time'];
        }
    }

    sendResponse(200, 'Status absensi hari ini', [
        'date' => $today,
        'status' => $statusAbsen, 
        'clock_in_time' => $jamMasuk,
        'clock_out_time' => $jamPulang
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error: ' . $e->getMessage());
}
?>