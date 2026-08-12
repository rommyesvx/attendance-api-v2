<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);
$today = date('Y-m-d');

try {
    // Cek dulu apakah ada sesi yang masih clocked_in (termasuk shift malam kemarin)
    $activeStmt = $pdo->prepare("
        SELECT * FROM absensi_attendances 
        WHERE user_id = ? AND clock_out_time IS NULL 
        ORDER BY id DESC LIMIT 1
    ");
    $activeStmt->execute([$user['user_id']]);
    $activeAtt = $activeStmt->fetch(PDO::FETCH_ASSOC);

    $statusAbsen = 'not_clocked_in';
    $jamMasuk = null;
    $jamPulang = null;
    $latestTime = null;

    if ($activeAtt && ((time() - strtotime($activeAtt['clock_in_time'])) / 3600) <= 24) {
        $statusAbsen = 'clocked_in';
        $jamMasuk = $activeAtt['clock_in_time'];
        $latestTime = $activeAtt['clock_in_time'];
    } else {
        $stmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND date = ? ORDER BY id ASC");
        $stmt->execute([$user['user_id'], $today]);
        $attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    }

    // Ambil data jadwal (absensi_schedules) dan kantor penugasan pegawai
    $scheduleRecord = getUserScheduleRecord($pdo, $user['user_id'], $today);
    $userOffice     = getUserOffice($pdo, $user['user_id'], $user['office_id'] ?? null, $today);

    $scheduleInfo = [
        'has_schedule'         => $scheduleRecord ? true : false,
        'office_id'            => $userOffice['id'] ?? null,
        'office_name'          => $userOffice['name'] ?? null,
        'schedule_date'        => $today,
        'clock_in_target'      => $scheduleRecord['clock_in_target'] ?? null,
        'clock_out_target'     => $scheduleRecord['clock_out_target'] ?? null,
        'formatted_in_target'  => ($scheduleRecord && !empty($scheduleRecord['clock_in_target'])) ? date('H:i', strtotime($scheduleRecord['clock_in_target'])) : '07:30',
        'formatted_out_target' => ($scheduleRecord && !empty($scheduleRecord['clock_out_target'])) ? date('H:i', strtotime($scheduleRecord['clock_out_target'])) : '16:00'
    ];

    sendResponse(200, 'Status absensi hari ini', [
        'date'                 => $today,
        'status'               => $statusAbsen, 
        'clock_in_time'        => $jamMasuk,
        'clock_out_time'       => $jamPulang,
        'latest_presence_time' => $latestTime,
        'schedule'             => $scheduleInfo
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error: ' . $e->getMessage());
}
?>