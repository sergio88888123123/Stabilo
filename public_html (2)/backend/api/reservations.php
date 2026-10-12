<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

$status = clean_string($_GET['status'] ?? 'all', 20);
$allowedStatus = ['all', 'pendiente', 'confirmada', 'cancelada', 'finalizada'];
if (!in_array($status, $allowedStatus, true)) {
    json_response(['ok' => false, 'message' => 'El filtro de estado no es válido.'], 422);
}

$conn = get_db();

try {
    $where = $status === 'all' ? '' : 'WHERE r.status = ?';
    $sql = "SELECT r.id, r.reservation_code, r.check_in, r.check_out, r.status, r.notes, r.created_at,
                   g.name AS guest_name, g.whatsapp, g.email,
                   rm.room_number, rm.room_type, rm.capacity, rm.price
            FROM reservations r
            INNER JOIN guests g ON g.id = r.guest_id
            INNER JOIN rooms rm ON rm.id = r.room_id
            $where
            ORDER BY r.created_at DESC
            LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($status !== 'all') $stmt->bind_param('s', $status);
    $stmt->execute();
    $result = $stmt->get_result();

    $reservations = [];
    while ($row = $result->fetch_assoc()) {
        $reservations[] = [
            'id' => (int) $row['id'],
            'reservationCode' => $row['reservation_code'],
            'guestName' => $row['guest_name'],
            'whatsapp' => $row['whatsapp'],
            'email' => $row['email'],
            'roomNumber' => $row['room_number'],
            'roomType' => $row['room_type'],
            'capacity' => (int) $row['capacity'],
            'price' => (float) $row['price'],
            'checkIn' => $row['check_in'],
            'checkOut' => $row['check_out'],
            'status' => $row['status'],
            'notes' => $row['notes'],
            'createdAt' => $row['created_at'],
        ];
    }

    $stmt->close();
    $conn->close();
    json_response(['ok' => true, 'reservations' => $reservations]);
} catch (Throwable $error) {
    $conn->close();
    json_response(['ok' => false, 'message' => 'No fue posible cargar las reservaciones.'], 500);
}
