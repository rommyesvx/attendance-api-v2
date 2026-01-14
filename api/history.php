<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year  = isset($_GET['year'])  ? $_GET['year']  : date('Y');

$jam_batas_masuk = '07:30:00'; 

try {
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            date, 
            clock_in_time, 
            clock_out_time, 
            location_type as status 
        FROM attendances 
        WHERE user_id = ? 
        AND MONTH(date) = ? 
        AND YEAR(date) = ? 
        ORDER BY date DESC
    ");
    
    $stmt->execute([$user['id'], $month, $year]);
    $raw_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_history = [];

    $days_id = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];

    foreach ($raw_history as $row) {
        $jam_masuk_raw = $row['clock_in_time'];
        
        $jam_masuk_display  = $jam_masuk_raw  ? date('H:i', strtotime($jam_masuk_raw))  : '-';
        $jam_keluar_display = $row['clock_out_time'] ? date('H:i', strtotime($row['clock_out_time'])) : '-';
        
        $day_english = date('l', strtotime($row['date']));
        $hari_indo   = $days_id[$day_english];


        $formatted_history[] = [
            'id'         => $row['id'],
            'tanggal'    => $row['date'],
            'hari'       => $hari_indo,
            
            'status'     => $row['status'], 
            'jam_masuk'  => $jam_masuk_display,
            'jam_keluar' => $jam_keluar_display
        ];
    }

    sendResponse(200, 'Data history berhasil diambil', [

        'history' => $formatted_history
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>