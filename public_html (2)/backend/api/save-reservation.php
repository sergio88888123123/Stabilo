<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

$payload = read_json_body();
$name = clean_string($payload['name'] ?? '', 120);
$whatsapp = clean_string($payload['whatsapp'] ?? '', 30);
$email = clean_string($payload['email'] ?? '', 120);
$roomId = (int) ($payload['roomId'] ?? 0);
$checkIn = clean_string($payload['checkIn'] ?? '', 20);
$checkOut = clean_string($payload['checkOut'] ?? '', 20);
$notes = clean_string($payload['notes'] ?? '', 2000);
$servicePaymentMethod = clean_string($payload['servicePaymentMethod'] ?? '', 60);
$servicePaymentReference = clean_string($payload['servicePaymentReference'] ?? '', 120);
$servicePaymentAmountRaw = $payload['servicePaymentAmount'] ?? '';
$servicePaymentAmount = is_numeric($servicePaymentAmountRaw) ? (float) $servicePaymentAmountRaw : 0.0;

if ($servicePaymentMethod !== '' || $servicePaymentAmount > 0 || $servicePaymentReference !== '') {
    $paymentLines = [
        'Pago de servicios: ' . ($servicePaymentMethod !== '' ? $servicePaymentMethod : 'Sin método seleccionado'),
        'Monto de servicios: $' . number_format($servicePaymentAmount, 2, '.', ','),
    ];

    if ($servicePaymentReference !== '') {
        $paymentLines[] = 'Referencia de pago: ' . $servicePaymentReference;
    }

    $notes = trim($notes . "

" . implode("
", $paymentLines));
}

if ($name === '' || $whatsapp === '' || $roomId <= 0 || $checkIn === '' || $checkOut === '') {
    json_response(['ok' => false, 'message' => 'Nombre, WhatsApp, habitación, entrada y salida son obligatorios.'], 422);
}

$checkInDate = DateTime::createFromFormat('Y-m-d', $checkIn);
$checkOutDate = DateTime::createFromFormat('Y-m-d', $checkOut);
if (!$checkInDate || !$checkOutDate || $checkOutDate <= $checkInDate) {
    json_response(['ok' => false, 'message' => 'Las fechas no son válidas. La salida debe ser posterior a la entrada.'], 422);
}

$conn = get_db();
$conn->begin_transaction();

try {
    $roomStmt = $conn->prepare('SELECT id, room_number, status FROM rooms WHERE id = ? LIMIT 1');
    $roomStmt->bind_param('i', $roomId);
    $roomStmt->execute();
    $room = $roomStmt->get_result()->fetch_assoc();
    $roomStmt->close();

    if (!$room) {
        throw new RuntimeException('La habitación seleccionada no existe.');
    }

    if ($room['status'] !== 'disponible') {
        json_response(['ok' => false, 'message' => 'La habitación no está disponible para reservación.'], 422);
    }

    $overlapStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM reservations
         WHERE room_id = ?
           AND status IN ('pendiente', 'confirmada')
           AND check_in < ?
           AND check_out > ?"
    );
    $overlapStmt->bind_param('iss', $roomId, $checkOut, $checkIn);
    $overlapStmt->execute();
    $overlaps = (int) ($overlapStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $overlapStmt->close();

    if ($overlaps > 0) {
        json_response(['ok' => false, 'message' => 'La habitación ya tiene una reservación activa en esas fechas.'], 422);
    }

    $guestStmt = $conn->prepare(
        'INSERT INTO guests (name, whatsapp, email)
         VALUES (?, ?, ?)'
    );
    $emailValue = $email === '' ? null : $email;
    $guestStmt->bind_param('sss', $name, $whatsapp, $emailValue);
    $guestStmt->execute();
    $guestId = $guestStmt->insert_id;
    $guestStmt->close();

    $reservationCode = '';
    for ($i = 0; $i < 5; $i++) {
        $reservationCode = 'RES-' . date('Ymd') . '-' . random_int(1000, 9999);
        $codeStmt = $conn->prepare('SELECT id FROM reservations WHERE reservation_code = ? LIMIT 1');
        $codeStmt->bind_param('s', $reservationCode);
        $codeStmt->execute();
        $exists = $codeStmt->get_result()->fetch_assoc();
        $codeStmt->close();
        if (!$exists) break;
    }

    $status = 'confirmada';
    $stmt = $conn->prepare(
        'INSERT INTO reservations (reservation_code, guest_id, room_id, check_in, check_out, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('siissss', $reservationCode, $guestId, $roomId, $checkIn, $checkOut, $status, $notes);
    $stmt->execute();
    $reservationId = $stmt->insert_id;
    $stmt->close();

    $conn->commit();
    $conn->close();

    json_response([
        'ok' => true,
        'message' => 'Reservación registrada correctamente.',
        'reservation' => [
            'id' => $reservationId,
            'reservationCode' => $reservationCode,
            'guestName' => $name,
            'whatsapp' => $whatsapp,
            'roomNumber' => $room['room_number'],
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'status' => $status,
            'servicePaymentMethod' => $servicePaymentMethod,
            'servicePaymentAmount' => $servicePaymentAmount,
            'servicePaymentReference' => $servicePaymentReference,
        ],
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    json_response(['ok' => false, 'message' => $error->getMessage() ?: 'No fue posible guardar la reservación.'], 500);
}
