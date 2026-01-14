<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

$user = authenticate($pdo);

$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year  = isset($_GET['year'])  ? $_GET['year']  : date('Y');

try {
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            date, 
            clock_in_time, 
            clock_out_time, 
            status 
        FROM attendances 
        WHERE user_id = ? 
        AND MONTH(date) = ? 
        AND YEAR(date) = ? 
        ORDER BY date DESC
    ");
    
    $stmt->execute([$user['id'], $month, $year]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'hadir' => 0,
        'telat' => 0,
        'tepat_waktu' => 0
    ];

    foreach ($history as &$row) {
        $row['clock_in_time']  = $row['clock_in_time']  ? date('H:i', strtotime($row['clock_in_time']))  : '-';
        $row['clock_out_time'] = $row['clock_out_time'] ? date('H:i', strtotime($row['clock_out_time'])) : '-';
        
        $summary['hadir']++;
        
        if ($row['status'] == 'late') {
            $summary['telat']++;
        } elseif ($row['status'] == 'on_time') {
            $summary['tepat_waktu']++;
        }
    }

    sendResponse(200, 'Data history berhasil diambil', [
        'filter' => [
            'month' => (int)$month,
            'year' => (int)$year
        ],
        'summary' => $summary,
        'history' => $history
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>