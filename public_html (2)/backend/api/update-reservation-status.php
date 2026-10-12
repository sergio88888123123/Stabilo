<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

$payload = read_json_body();
$id = (int) ($payload['id'] ?? 0);
$status = clean_string($payload['status'] ?? '', 20);
$allowedStatus = ['pendiente', 'confirmada', 'cancelada', 'finalizada'];

if ($id <= 0 || !in_array($status, $allowedStatus, true)) {
    json_response(['ok' => false, 'message' => 'Datos de actualización no válidos.'], 422);
}

$conn = get_db();

try {
    $stmt = $conn->prepare('UPDATE reservations SET status = ? WHERE id = ?');
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    json_response([
        'ok' => true,
        'message' => $affected >= 0 ? 'Estado actualizado correctamente.' : 'No se actualizó la reservación.',
    ]);
} catch (Throwable $error) {
    $conn->close();
    json_response(['ok' => false, 'message' => 'No fue posible actualizar la reservación.'], 500);
}
