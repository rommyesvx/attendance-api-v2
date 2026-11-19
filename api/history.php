<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');
$user = authenticate($pdo);

$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year  = isset($_GET['year'])  ? $_GET['year']  : date('Y');

$jam_batas_masuk = '07:30:00'; 

try {
    $stmt = $pdo->prepare("
        SELECT 
            a.id, 
            a.date, 
            a.clock_in_time, 
            a.clock_out_time, 
            a.location_type as status
        FROM absensi_attendances a
        WHERE a.user_id = ? 
        AND a.is_confirmed = 1
        AND MONTH(a.date) = ? 
        AND YEAR(a.date) = ? 
        ORDER BY a.date DESC, a.clock_in_time DESC
    ");
    
    $stmt->execute([$user['user_id'], $month, $year]);
    $raw_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_history = [];

    $days_id = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];

    // Ambil data laporan patroli (absensi_security_logs) HANYA untuk Petugas Keamanan
    $isSecurity = isSecurityUser($user);
    $patrolByAttendance = [];
    $patrolByDate = [];
    $formatted_patrol_logs = [];

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
    $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') : '';
    $baseRootUrl = "{$scheme}://{$host}{$scriptDir}";

    if ($isSecurity) {
        try {
            $patrolStmt = $pdo->prepare("
                SELECT 
                    id,
                    attendance_id,
                    created_at,
                    latitude,
                    longitude,
                    note,
                    image_path
                FROM absensi_security_logs
                WHERE user_id = ?
                  AND MONTH(created_at) = ?
                  AND YEAR(created_at) = ?
                ORDER BY created_at DESC, id DESC
            ");
            $patrolStmt->execute([$user['user_id'], $month, $year]);
            $raw_patrols = $patrolStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($raw_patrols as $pRow) {
                $createdAt = $pRow['created_at'];
                $ts = $createdAt ? strtotime($createdAt) : false;
                $tglPatrol = $ts ? date('Y-m-d', $ts) : '-';
                $jamPatrol = $ts ? date('H:i', $ts) : '-';
                $jamLengkap = $ts ? date('H:i:s', $ts) : '-';
                $dayEn = $ts ? date('l', $ts) : '';
                $hariPatrol = $days_id[$dayEn] ?? $dayEn;

                $imgPath = $pRow['image_path'] ?? null;
                $imgUrl = null;
                if ($imgPath) {
                    $cleanPath = '/' . ltrim($imgPath, './\\');
                    $imgUrl = $baseRootUrl . $cleanPath;
                }

                $pItem = [
                    'id'            => (int)$pRow['id'],
                    'attendance_id' => $pRow['attendance_id'] ? (int)$pRow['attendance_id'] : null,
                    'created_at'    => $createdAt,
                    'tanggal'       => $tglPatrol,
                    'hari'          => $hariPatrol,
                    'jam'           => $jamPatrol,
                    'jam_lengkap'   => $jamLengkap,
                    'latitude'      => $pRow['latitude'],
                    'longitude'     => $pRow['longitude'],
                    'note'          => (!empty($pRow['note'])) ? $pRow['note'] : 'Patroli Rutin',
                    'image_path'    => $imgPath,
                    'image_url'     => $imgUrl
                ];

                $formatted_patrol_logs[] = $pItem;

                if (!empty($pRow['attendance_id'])) {
                    $attId = (int)$pRow['attendance_id'];
                    if (!isset($patrolByAttendance[$attId])) {
                        $patrolByAttendance[$attId] = [];
                    }
                    $patrolByAttendance[$attId][] = $pItem;
                }

                if ($tglPatrol !== '-') {
                    if (!isset($patrolByDate[$tglPatrol])) {
                        $patrolByDate[$tglPatrol] = [];
                    }
                    $patrolByDate[$tglPatrol][] = $pItem;
                }
            }
        } catch (Exception $e) {
            // Abaikan jika tabel absensi_security_logs belum tersedia
        }
    }

    foreach ($raw_history as $row) {
        $jam_masuk_raw = $row['clock_in_time'];
        
        $jam_masuk_display  = $jam_masuk_raw  ? date('H:i', strtotime($jam_masuk_raw))  : '-';
        $jam_keluar_display = $row['clock_out_time'] ? date('H:i', strtotime($row['clock_out_time'])) : '-';
        
        $day_english = date('l', strtotime($row['date']));
        $hari_indo   = $days_id[$day_english] ?? $day_english;

        $attId = (int)$row['id'];
        $logsForRow = $isSecurity ? ($patrolByAttendance[$attId] ?? ($patrolByDate[$row['date']] ?? [])) : [];

        $formatted_history[] = [
            'id'           => $row['id'],
            'tanggal'      => $row['date'],
            'hari'         => $hari_indo,
            'status'       => $row['status'], 
            'jam_masuk'    => $jam_masuk_display,
            'jam_keluar'   => $jam_keluar_display,
            'patrol_count' => count($logsForRow),
            'patrol_logs'  => $logsForRow
        ];
    }

    sendResponse(200, 'Data history berhasil diambil', [
        'is_security' => $isSecurity,
        'history'     => $formatted_history,
        'patrol_logs' => $formatted_patrol_logs
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>