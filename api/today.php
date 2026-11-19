<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);
$today = date('Y-m-d');

try {
    // Cek dulu apakah ada sesi yang masih clocked_in (termasuk shift malam kemarin)
    $activeAtt = getActiveAttendance($pdo, $user['user_id']);

    $statusAbsen = 'not_clocked_in';
    $jamMasuk = null;
    $jamPulang = null;
    $latestTime = null;

    if ($activeAtt) {
        $statusAbsen = 'clocked_in';
        $jamMasuk = $activeAtt['clock_in_time'];
        $latestTime = $activeAtt['clock_in_time'];
    } else {
        $stmt = $pdo->prepare("SELECT * FROM absensi_attendances WHERE user_id = ? AND is_confirmed = 1 AND date = ? ORDER BY id ASC");
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

    // Ambil data jadwal shift dari master_shift_user & master_shift serta kantor penugasan pegawai
    $isSecurity = isSecurityUser($user);
    $yesterday  = date('Y-m-d', strtotime('-1 day'));
    $nowTs      = time();

    $activeShiftDate = $activeAtt ? $activeAtt['date'] : $today;

    // Jika belum clock_in, cek apakah ada shift malam dari kemarin yang masih aktif
    if (!$activeAtt) {
        $yesterdayRecord = getUserScheduleRecord($pdo, $user['user_id'], $yesterday);
        if ($yesterdayRecord) {
            $yTipeShift = strtolower(trim((string)($yesterdayRecord['tipe_shift'] ?? '')));
            $yIn  = $yesterdayRecord['clock_in_target'] ?? null;
            $yOut = $yesterdayRecord['clock_out_target'] ?? null;
            if (($yTipeShift === 'malam' || !empty($yesterdayRecord['is_overnight'])) && $yIn && $yOut) {
                $yStartTs = strtotime("$yesterday $yIn");
                $yEndTs   = strtotime("$yesterday $yOut +1 day");
                if ($nowTs >= ($yStartTs - 1800) && $nowTs <= $yEndTs) {
                    $activeShiftDate = $yesterday;
                }
            }
        }
    }

    $scheduleRecord = getUserScheduleRecord($pdo, $user['user_id'], $activeShiftDate);
    $userOffice     = getUserOffice($pdo, $user['user_id'], $user['office_id'] ?? null, $activeShiftDate);

    $rawClockIn  = $scheduleRecord['clock_in_target'] ?? null;
    $rawClockOut = $scheduleRecord['clock_out_target'] ?? null;
    $tipeShift   = isset($scheduleRecord['tipe_shift']) ? strtolower(trim((string)$scheduleRecord['tipe_shift'])) : null;
    $shiftId     = $scheduleRecord['shift_id'] ?? null;
    $shiftName   = $scheduleRecord['shift_name'] ?? null;
    $shiftType   = $scheduleRecord['shift_type'] ?? null;

    $formatTime = function($timeStr, $default) {
        if (!$timeStr || $timeStr === '00:00:00' || $timeStr === '00:00' || $timeStr === '-') {
            return $default;
        }
        $ts = strtotime($timeStr);
        return ($ts !== false) ? date('H:i', $ts) : $default;
    };

    $hasSchedule  = ($scheduleRecord !== null);
    $formattedIn  = $rawClockIn ? $formatTime($rawClockIn, '--:--') : ($hasSchedule ? '--:--' : ($isSecurity ? '--:--' : '07:30'));
    $formattedOut = $rawClockOut ? $formatTime($rawClockOut, '--:--') : ($hasSchedule ? '--:--' : ($isSecurity ? '--:--' : '16:00'));

    $isOvernight = !empty($scheduleRecord['is_overnight']) || ($tipeShift === 'malam');
    $endDate     = $activeShiftDate;
    $shiftStatus = 'no_shift';

    if ($hasSchedule && $rawClockIn && $rawClockOut) {
        $startTs = strtotime("$activeShiftDate $rawClockIn");
        if ($isOvernight) {
            $endDate = date('Y-m-d', strtotime("$activeShiftDate +1 day"));
            $endTs   = strtotime("$activeShiftDate $rawClockOut +1 day");
        } else {
            $endTs   = strtotime("$activeShiftDate $rawClockOut");
        }

        if ($nowTs >= ($startTs - 1800) && $nowTs <= $endTs) {
            $shiftStatus = 'ongoing';
        } elseif ($nowTs < ($startTs - 1800)) {
            $shiftStatus = ($activeShiftDate === $today) ? 'today_upcoming' : 'upcoming';
        } else {
            $shiftStatus = 'completed';
        }
    } elseif ($hasSchedule) {
        $shiftStatus = ($activeShiftDate === $today) ? 'ongoing' : 'upcoming';
    }

    // Ambil daftar jadwal shift beberapa hari ke depan dari master_shift_user & master_shift
    $upcomingShifts = [];
    $daysIndo = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];

    for ($i = 0; $i < 7; $i++) {
        $sDate = date('Y-m-d', strtotime("$activeShiftDate +{$i} days"));
        $sRow  = getUserScheduleRecord($pdo, $user['user_id'], $sDate);

        if (!$sRow) {
            continue;
        }

        $sTipe      = isset($sRow['tipe_shift']) ? strtolower(trim((string)$sRow['tipe_shift'])) : null;
        $sIn        = $sRow['clock_in_target'] ?? null;
        $sOut       = $sRow['clock_out_target'] ?? null;
        $sOvernight = !empty($sRow['is_overnight']) || ($sTipe === 'malam');
        $sEndDate   = $sDate;

        if ($sIn && $sOut) {
            $sStartTs = strtotime("$sDate $sIn");
            if ($sOvernight) {
                $sEndDate = date('Y-m-d', strtotime("$sDate +1 day"));
                $sEndTs   = strtotime("$sDate $sOut +1 day");
            } else {
                $sEndTs   = strtotime("$sDate $sOut");
            }

            if ($sDate === $yesterday && $nowTs > $sEndTs) {
                continue;
            }

            if ($nowTs >= ($sStartTs - 1800) && $nowTs <= $sEndTs) {
                $sStatus = 'ongoing';
            } elseif ($sDate === $today && $nowTs < ($sStartTs - 1800)) {
                $sStatus = 'today_upcoming';
            } elseif ($sDate === $today && $nowTs > $sEndTs) {
                $sStatus = 'completed';
            } else {
                $sStatus = 'upcoming';
            }
        } else {
            $sStatus = ($sDate === $today) ? 'ongoing' : 'upcoming';
        }

        $dayStartEn = date('l', strtotime($sDate));
        $dayEndEn   = date('l', strtotime($sEndDate));

        $upcomingShifts[] = [
            'id'                   => $sRow['id'] ?? null,
            'shift_id'             => $sRow['shift_id'] ?? null,
            'shift_name'           => $sRow['shift_name'] ?? null,
            'shift_type'           => $sRow['shift_type'] ?? null,
            'tipe_shift'           => $sTipe,
            'is_security'          => $isSecurity,
            'is_patrol_required'   => $isSecurity,
            'schedule_date'        => $sDate,
            'end_date'             => $sEndDate,
            'hari_mulai'           => $daysIndo[$dayStartEn] ?? $dayStartEn,
            'hari_selesai'         => $daysIndo[$dayEndEn] ?? $dayEndEn,
            'is_overnight'         => $sOvernight,
            'office_id'            => $userOffice['id'] ?? null,
            'office_name'          => $userOffice['name'] ?? 'Kantor Utama',
            'clock_in_target'      => $sIn,
            'clock_out_target'     => $sOut,
            'formatted_in_target'  => $formatTime($sIn, '--:--'),
            'formatted_out_target' => $formatTime($sOut, '--:--'),
            'shift_status'         => $sStatus
        ];
    }

    $dayStartMainEn = date('l', strtotime($activeShiftDate));
    $dayEndMainEn   = date('l', strtotime($endDate));

    $scheduleInfo = [
        'has_schedule'         => $hasSchedule,
        'shift_id'             => $shiftId,
        'shift_name'           => $shiftName,
        'shift_type'           => $shiftType,
        'is_security'          => $isSecurity,
        'tipe_shift'           => $tipeShift,
        'is_patrol_required'   => $isSecurity,
        'office_id'            => $userOffice['id'] ?? null,
        'office_name'          => $userOffice['name'] ?? null,
        'schedule_date'        => $activeShiftDate,
        'end_date'             => $endDate,
        'hari_mulai'           => $daysIndo[$dayStartMainEn] ?? $dayStartMainEn,
        'hari_selesai'         => $daysIndo[$dayEndMainEn] ?? $dayEndMainEn,
        'is_overnight'         => $isOvernight,
        'shift_status'         => $shiftStatus,
        'clock_in_target'      => $rawClockIn,
        'clock_out_target'     => $rawClockOut,
        'formatted_in_target'  => $formattedIn,
        'formatted_out_target' => $formattedOut,
        'upcoming_shifts'      => $upcomingShifts
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