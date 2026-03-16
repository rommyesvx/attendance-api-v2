<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);
$today = date('Y-m-d');

try {
    $stmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user['user_id'], $today]);
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);

    $statusAbsen = 'not_clocked_in';
    $jamMasuk = null;
    $jamPulang = null;
    $latestTime = null;

    if ($attendance) {
        if ($attendance['clock_out_time'] == NULL) {
            $statusAbsen = 'clocked_in';
            $jamMasuk = $attendance['clock_in_time'];
            $latestTime = $jamMasuk;
        } else {
            $statusAbsen = 'checked_out';
            $jamMasuk = $attendance['clock_in_time'];
            $jamPulang = $attendance['clock_out_time'];
            $latestTime = $jamPulang;
        }
    }

    sendResponse(200, 'Status absensi hari ini', [
        'date' => $today,
        'status' => $statusAbsen, 
        'clock_in_time' => $jamMasuk,
        'clock_out_time' => $jamPulang,
        'latest_presence_time' => $latestTime
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error: ' . $e->getMessage());
}
?>