<?php
require_once '../config/database.php';
require_once '../utils/functions.php';

date_default_timezone_set('Asia/Jakarta');

$user = authenticate($pdo);
$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['attendance_id']) || !isset($input['confirmation_status'])) {
    sendResponse(400, 'Parameter attendance_id dan confirmation_status wajib dikirim');
}

$attendanceId = $input['attendance_id'];
$confirmationStatus = $input['confirmation_status'];

// Validasi status konfirmasi harus bernilai true
if ($confirmationStatus !== true) {
    sendResponse(400, 'Status konfirmasi tidak valid, harus bernilai true');
}

try {
    // Cari data absensi KDM yang butuh konfirmasi
    $checkStmt = $pdo->prepare("SELECT id FROM absensi_attendances WHERE id = ? AND user_id = ? AND is_confirmed = 0");
    $checkStmt->execute([$attendanceId, $user['user_id']]);
    $attendance = $checkStmt->fetch();

    if (!$attendance) {
        sendResponse(404, 'Data absensi tidak ditemukan atau sudah dikonfirmasi sebelumnya');
    }

    // Update row, set is_confirmed = true
    $updateStmt = $pdo->prepare("UPDATE absensi_attendances SET is_confirmed = 1 WHERE id = ?");
    $updateStmt->execute([$attendanceId]);

    sendResponse(200, 'Absen KDM berhasil dikonfirmasi', [
        'status' => 'success',
        'is_confirmed' => true
    ]);

} catch (Exception $e) {
    sendResponse(500, 'Terjadi kesalahan server: ' . $e->getMessage());
}
?>
