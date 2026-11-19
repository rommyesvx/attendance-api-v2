<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');
$user = authenticate($pdo);

$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');

try {
    $isSecurity = isSecurityUser($user);
    $office = getUserOffice($pdo, $user['user_id'], $user['office_id'] ?? null);

    $firstDateOfMonth = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth      = (int)date('t', strtotime($firstDateOfMonth));
    $lastDateOfMonth  = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

    // Ambil semua data master_shift_user & master_shift aktif milik user yang mencakup bulan terkait
    $stmt = $pdo->prepare("
        SELECT 
            msu.shiftuser_id,
            msu.user_id,
            msu.shift_id,
            msu.shiftuser_dari,
            msu.shiftuser_sampai,
            msu.shiftuser_active,
            msu.shiftuser_createddate,
            ms.shift_name,
            ms.shift_jam_masuk,
            ms.shift_jam_pulang,
            ms.shift_type,
            ms.shift_group,
            ms.shift_team,
            ms.shift_mulai,
            ms.shift_toleransi,
            ms.shift_active
        FROM master_shift_user msu
        INNER JOIN master_shift ms ON msu.shift_id = ms.shift_id
        WHERE msu.user_id = ?
          AND msu.shiftuser_active = '1'
          AND ms.shift_active = '1'
          AND (msu.shiftuser_dari IS NULL OR msu.shiftuser_dari <= ?)
          AND (msu.shiftuser_sampai IS NULL OR msu.shiftuser_sampai >= ?)
        ORDER BY msu.shiftuser_dari DESC, msu.shiftuser_createddate DESC, msu.shiftuser_id DESC
    ");
    $stmt->execute([$user['user_id'], $lastDateOfMonth, $firstDateOfMonth]);
    $shiftAssignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $sDate = sprintf('%04d-%02d-%02d', $year, $month, $day);

        $matchedAssignment = null;
        foreach ($shiftAssignments as $assignment) {
            $dari   = $assignment['shiftuser_dari'] ?? null;
            $sampai = $assignment['shiftuser_sampai'] ?? null;
            if (($dari === null || $dari <= $sDate) && ($sampai === null || $sampai >= $sDate)) {
                $matchedAssignment = $assignment;
                break;
            }
        }

        if (!$matchedAssignment) {
            continue;
        }

        $normalized  = normalizeMasterShiftRecord($matchedAssignment, $sDate);
        $dayEnglish  = date('l', strtotime($sDate));
        $rawIn       = $normalized['clock_in_target'] ?? null;
        $rawOut      = $normalized['clock_out_target'] ?? null;
        $rowTipeShift = $normalized['tipe_shift'] ?? null;
        $isOvernight = !empty($normalized['is_overnight']) || ($rowTipeShift === 'malam');

        $endDate = $sDate;
        if ($isOvernight) {
            $endDate = date('Y-m-d', strtotime($sDate . ' +1 day'));
        }
        $dayEndEnglish = date('l', strtotime($endDate));

        $formattedSchedules[] = [
            'id'                   => $normalized['id'],
            'shift_id'             => $normalized['shift_id'],
            'shift_name'           => $normalized['shift_name'],
            'shift_type'           => $normalized['shift_type'],
            'tipe_shift'           => $rowTipeShift,
            'is_security'          => $isSecurity,
            'is_patrol_required'   => $isSecurity,
            'schedule_date'        => $sDate,
            'end_date'             => $endDate,
            'hari'                 => $daysIndo[$dayEnglish] ?? $dayEnglish,
            'hari_selesai'         => $daysIndo[$dayEndEnglish] ?? $dayEndEnglish,
            'is_overnight'         => $isOvernight,
            'office_id'            => $office['id'] ?? null,
            'office_name'          => $office['name'] ?? 'Kantor Utama',
            'clock_in_target'      => $rawIn,
            'clock_out_target'     => $rawOut,
            'formatted_in_target'  => $formatTime($rawIn, '--:--'),
            'formatted_out_target' => $formatTime($rawOut, '--:--')
        ];
    }

    sendResponse(200, 'Data jadwal kerja pegawai berhasil diambil', [
        'meta' => [
            'month' => $month,
            'year'  => $year
        ],
        'is_security' => $isSecurity,
        'schedules'   => $formattedSchedules
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error: ' . $e->getMessage());
}
?>
