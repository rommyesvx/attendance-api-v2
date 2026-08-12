<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');
$user = authenticate($pdo);

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

try {
    $stmt = $pdo->prepare("
        SELECT 
            s.id,
            s.user_id,
            s.office_id,
            s.schedule_date,
            s.clock_in_target,
            s.clock_out_target,
            o.name as office_name,
            o.polygon_coordinates
        FROM absensi_schedules s
        LEFT JOIN absensi_offices o ON s.office_id = o.id
        WHERE s.user_id = ? 
          AND MONTH(s.schedule_date) = ? 
          AND YEAR(s.schedule_date) = ?
        ORDER BY s.schedule_date ASC
    ");
    $stmt->execute([$user['user_id'], $month, $year]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedSchedules = [];
    $daysIndo = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];

    $formatTime = function($timeStr, $default) {
        if (!$timeStr || $timeStr === '00:00:00' || $timeStr === '00:00' || $timeStr === '-') {
            return $default;
        }
        $ts = strtotime($timeStr);
        return ($ts !== false) ? date('H:i', $ts) : $default;
    };

    foreach ($schedules as $row) {
        $dayEnglish = date('l', strtotime($row['schedule_date']));
        $rawIn  = $row['clock_in_target'] ?? null;
        $rawOut = $row['clock_out_target'] ?? null;

        $formattedSchedules[] = [
            'id'                  => $row['id'],
            'schedule_date'       => $row['schedule_date'],
            'hari'                => $daysIndo[$dayEnglish] ?? $dayEnglish,
            'office_id'           => $row['office_id'],
            'office_name'         => $row['office_name'] ?? 'Kantor Utama',
            'clock_in_target'     => $rawIn,
            'clock_out_target'    => $rawOut,
            'formatted_in_target' => $formatTime($rawIn, '07:30'),
            'formatted_out_target'=> $formatTime($rawOut, '16:00')
        ];
    }

    sendResponse(200, 'Data jadwal kerja pegawai berhasil diambil', [
        'meta' => [
            'month' => $month,
            'year'  => $year
        ],
        'schedules' => $formattedSchedules
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error: ' . $e->getMessage());
}
?>
