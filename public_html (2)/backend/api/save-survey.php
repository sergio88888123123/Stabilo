<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

$payload = read_json_body();
$allowedSurveys = allowed_surveys();
$surveyKey = normalize_survey_key((string) ($payload['surveyKey'] ?? ''));

if (!$surveyKey || !array_key_exists($surveyKey, $allowedSurveys)) {
    json_response(['ok' => false, 'message' => 'La encuesta seleccionada no es válida.'], 422);
}

$name = clean_string($payload['name'] ?? '', 120);
$phone = clean_string($payload['phone'] ?? '', 30);
$room = clean_string($payload['room'] ?? '', 30);
$reservationId = (int) ($payload['reservationId'] ?? 0);
$reservationCode = strtoupper(clean_string($payload['reservationCode'] ?? '', 30));
$feedback = clean_string($payload['feedback'] ?? '', 3000);
$answers = $payload['answers'] ?? [];

if (($name === '' || $phone === '') && $reservationCode === '' && $reservationId <= 0) {
    json_response(['ok' => false, 'message' => 'Nombre y WhatsApp son obligatorios.'], 422);
}

if ($allowedSurveys[$surveyKey]['requiresRoom'] && $room === '' && $reservationCode === '' && $reservationId <= 0) {
    json_response(['ok' => false, 'message' => 'La habitación es obligatoria para la encuesta de hotel.'], 422);
}

if (!is_array($answers) || count($answers) === 0) {
    json_response(['ok' => false, 'message' => 'La encuesta debe incluir respuestas.'], 422);
}

$conn = get_db();
$conn->begin_transaction();

try {
    if ($reservationCode !== '') {
        $reservationStmt = $conn->prepare(
            "SELECT r.id, g.name, g.whatsapp, rm.room_number
             FROM reservations r
             INNER JOIN guests g ON g.id = r.guest_id
             INNER JOIN rooms rm ON rm.id = r.room_id
             WHERE r.reservation_code = ?
             LIMIT 1"
        );
        $reservationStmt->bind_param('s', $reservationCode);
        $reservationStmt->execute();
        $reservation = $reservationStmt->get_result()->fetch_assoc();
        $reservationStmt->close();

        if (!$reservation) {
            json_response(['ok' => false, 'message' => 'El código de reservación no existe.'], 422);
        }

        $reservationId = (int) $reservation['id'];
        if ($name === '') $name = clean_string($reservation['name'] ?? '', 120);
        if ($phone === '') $phone = clean_string($reservation['whatsapp'] ?? '', 30);
        if ($room === '') $room = clean_string($reservation['room_number'] ?? '', 30);
    }

    if ($reservationId > 0 && $reservationCode === '') {
        $reservationStmt = $conn->prepare(
            "SELECT r.id, g.name, g.whatsapp, rm.room_number
             FROM reservations r
             INNER JOIN guests g ON g.id = r.guest_id
             INNER JOIN rooms rm ON rm.id = r.room_id
             WHERE r.id = ?
             LIMIT 1"
        );
        $reservationStmt->bind_param('i', $reservationId);
        $reservationStmt->execute();
        $reservation = $reservationStmt->get_result()->fetch_assoc();
        $reservationStmt->close();

        if (!$reservation) {
            json_response(['ok' => false, 'message' => 'La reservación seleccionada no existe.'], 422);
        }

        if ($name === '') $name = clean_string($reservation['name'] ?? '', 120);
        if ($phone === '') $phone = clean_string($reservation['whatsapp'] ?? '', 30);
        if ($room === '') $room = clean_string($reservation['room_number'] ?? '', 30);
    }


    if ($name === '' || $phone === '') {
        json_response(['ok' => false, 'message' => 'Nombre y WhatsApp son obligatorios.'], 422);
    }

    if ($allowedSurveys[$surveyKey]['requiresRoom'] && $room === '') {
        json_response(['ok' => false, 'message' => 'La habitación es obligatoria para la encuesta de hotel.'], 422);
    }

    $surveyTitle = clean_string($payload['surveyTitle'] ?? $allowedSurveys[$surveyKey]['title'], 120);

    $stmt = $conn->prepare(
        'INSERT INTO survey_responses (reservation_id, survey_key, survey_title, name, whatsapp, room, feedback, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $reservationIdForDb = $reservationId > 0 ? $reservationId : null;
    $stmt->bind_param('issssss', $reservationIdForDb, $surveyKey, $surveyTitle, $name, $phone, $room, $feedback);
    $stmt->execute();
    $responseId = $stmt->insert_id;
    $stmt->close();

    $answerStmt = $conn->prepare(
        'INSERT INTO survey_answers (response_id, question_key, question_label, rating)
         VALUES (?, ?, ?, ?)'
    );

    $insertedAnswers = 0;
    foreach ($answers as $answer) {
        if (!is_array($answer)) continue;
        $questionKey = normalize_survey_key((string) ($answer['key'] ?? ''));
        $questionLabel = clean_string($answer['label'] ?? '', 180);
        $rating = validate_rating($answer['rating'] ?? 0);

        if ($questionKey === '' || $questionLabel === '' || $rating === 0) continue;
        $answerStmt->bind_param('issi', $responseId, $questionKey, $questionLabel, $rating);
        $answerStmt->execute();
        $insertedAnswers++;
    }
    $answerStmt->close();

    if ($insertedAnswers === 0) {
        throw new RuntimeException('No se recibieron respuestas válidas.');
    }

    $conn->commit();
    $conn->close();

    json_response([
        'ok' => true,
        'message' => 'Encuesta registrada correctamente.',
        'data' => [
            'id' => $responseId,
            'answers' => $insertedAnswers,
            'reservationId' => $reservationIdForDb,
        ],
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    $conn->close();
    json_response([
        'ok' => false,
        'message' => 'No fue posible guardar la encuesta.',
    ], 500);
}
