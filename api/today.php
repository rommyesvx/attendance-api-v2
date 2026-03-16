<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);
$today = date('Y-m-d');

try {
    $stmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id ASC");
    $stmt->execute([$user['user_id'], $today]);
    $attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusAbsen = 'not_clocked_in';
    $jamMasuk = null;
    $jamPulang = null;
    $latestTime = null;

    if (count($attendances) > 0) {
        $first = $attendances[0];
        $last = $attendances[count($attendances) - 1];

        $jamMasuk = $first['clock_in_time'];
        
        $maxPulang = null;
        foreach ($attendances as $att) {
            if ($att['clock_out_time'] != null) {
                if ($maxPulang == null || strtotime($att['clock_out_time']) > strtotime($maxPulang)) {
                    $maxPulang = $att['clock_out_time'];
                }
            }
        }
        $jamPulang = $maxPulang;

        if ($last['clock_out_time'] == NULL) {
            $statusAbsen = 'clocked_in';
            $latestTime = $last['clock_in_time'];
        } else {
            $statusAbsen = 'checked_out';
            $latestTime = $last['clock_out_time'];
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