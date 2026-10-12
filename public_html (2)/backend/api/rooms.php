<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$conn = get_db();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $checkIn = clean_string($_GET['checkIn'] ?? '', 20);
        $checkOut = clean_string($_GET['checkOut'] ?? '', 20);

        $rooms = [];
        $result = $conn->query(
            'SELECT id, room_number, room_type, capacity, price, status, created_at
             FROM rooms
             ORDER BY room_number ASC'
        );

        while ($row = $result->fetch_assoc()) {
            $room = [
                'id' => (int) $row['id'],
                'roomNumber' => $row['room_number'],
                'roomType' => $row['room_type'],
                'capacity' => (int) $row['capacity'],
                'price' => (float) $row['price'],
                'status' => $row['status'],
                'createdAt' => $row['created_at'],
                'isAvailable' => $row['status'] === 'disponible',
            ];

            if ($checkIn !== '' && $checkOut !== '') {
                $stmt = $conn->prepare(
                    "SELECT COUNT(*) AS total
                     FROM reservations
                     WHERE room_id = ?
                       AND status IN ('pendiente', 'confirmada')
                       AND check_in < ?
                       AND check_out > ?"
                );
                $stmt->bind_param('iss', $room['id'], $checkOut, $checkIn);
                $stmt->execute();
                $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
                $stmt->close();
                $room['isAvailable'] = $room['status'] === 'disponible' && $total === 0;
            }

            $rooms[] = $room;
        }

        $conn->close();
        json_response(['ok' => true, 'rooms' => $rooms]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = read_json_body();
        $roomNumber = clean_string($payload['roomNumber'] ?? '', 20);
        $roomType = clean_string($payload['roomType'] ?? '', 80);
        $capacity = max(1, (int) ($payload['capacity'] ?? 2));
        $price = max(0, (float) ($payload['price'] ?? 0));
        $status = clean_string($payload['status'] ?? 'disponible', 20);
        $allowedStatus = ['disponible', 'ocupada', 'mantenimiento'];

        if ($roomNumber === '' || $roomType === '') {
            json_response(['ok' => false, 'message' => 'Número y tipo de habitación son obligatorios.'], 422);
        }

        if (!in_array($status, $allowedStatus, true)) {
            json_response(['ok' => false, 'message' => 'El estado de la habitación no es válido.'], 422);
        }

        $stmt = $conn->prepare(
            'INSERT INTO rooms (room_number, room_type, capacity, price, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('ssids', $roomNumber, $roomType, $capacity, $price, $status);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        $conn->close();

        json_response(['ok' => true, 'message' => 'Habitación registrada correctamente.', 'roomId' => $id]);
    }

    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
} catch (Throwable $error) {
    $conn->close();
    json_response(['ok' => false, 'message' => 'No fue posible procesar las habitaciones.'], 500);
}
