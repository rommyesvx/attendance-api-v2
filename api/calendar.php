<?php
require_once '../config/database.php';
require_once '../utils/functions.php';


$month = isset($_GET['month']) ? $_GET['month'] : date('m');
$year  = isset($_GET['year'])  ? $_GET['year']  : date('Y');

try {
    $stmt = $pdo->prepare("
        SELECT date, name, type 
        FROM holidays 
        WHERE MONTH(date) = ? AND YEAR(date) = ?
        ORDER BY date ASC
    ");
    
    $stmt->execute([$month, $year]);
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $calendar_data = [];
    foreach ($holidays as $row) {
        $calendar_data[$row['date']] = [
            'name' => $row['name'],
            'type' => $row['type'] 
        ];
    }

    sendResponse(200, 'Data kalender berhasil diambil', [
        'meta' => [
            'month' => (int)$month,
            'year'  => (int)$year
        ],
        'holidays' => $calendar_data
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Error: ' . $e->getMessage());
}
?>