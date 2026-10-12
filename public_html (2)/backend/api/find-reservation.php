<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

$code = strtoupper(clean_string($_GET['code'] ?? '', 30));
if ($code === '') {
    json_response(['ok' => false, 'message' => 'Ingresa un código de reservación.'], 422);
}

$conn = get_db();

try {
    $stmt = $conn->prepare(
        "SELECT r.id, r.reservation_code, r.check_in, r.check_out, r.status,
                g.name AS guest_name, g.whatsapp, g.email,
                rm.room_number, rm.room_type
         FROM reservations r
         INNER JOIN guests g ON g.id = r.guest_id
         INNER JOIN rooms rm ON rm.id = r.room_id
         WHERE r.reservation_code = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$row) {
        json_response(['ok' => false, 'message' => 'No se encontró una reservación con ese código.'], 404);
    }

    json_response([
        'ok' => true,
        'reservation' => [
            'id' => (int) $row['id'],
            'reservationCode' => $row['reservation_code'],
            'guestName' => $row['guest_name'],
            'whatsapp' => $row['whatsapp'],
            'email' => $row['email'],
            'roomNumber' => $row['room_number'],
            'roomType' => $row['room_type'],
            'checkIn' => $row['check_in'],
            'checkOut' => $row['check_out'],
            'status' => $row['status'],
        ],
    ]);
} catch (Throwable $error) {
    $conn->close();
    json_response(['ok' => false, 'message' => 'No fue posible buscar la reservación.'], 500);
}
