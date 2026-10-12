<?php

declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Método no permitido.'], 405);
}

$requestedKey = normalize_survey_key((string) ($_GET['surveyKey'] ?? 'all'));
$surveyKey = $requestedKey ?: 'all';
$allowedSurveys = allowed_surveys();

if ($surveyKey !== 'all' && !array_key_exists($surveyKey, $allowedSurveys)) {
    json_response(['ok' => false, 'message' => 'La encuesta solicitada no es válida.'], 422);
}

$conn = get_db();
$where = $surveyKey === 'all' ? '' : 'WHERE survey_key = ?';
$joinWhere = $surveyKey === 'all' ? '' : 'WHERE r.survey_key = ?';

try {
    $totalResponses = 0;
    if ($surveyKey === 'all') {
        $result = $conn->query('SELECT COUNT(*) AS total FROM survey_responses');
        $totalResponses = (int) ($result->fetch_assoc()['total'] ?? 0);
    } else {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM survey_responses WHERE survey_key = ?');
        $stmt->bind_param('s', $surveyKey);
        $stmt->execute();
        $totalResponses = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
    }

    $summarySql = "SELECT COUNT(a.id) AS total_answers, COALESCE(AVG(a.rating), 0) AS average
                   FROM survey_answers a
                   INNER JOIN survey_responses r ON r.id = a.response_id
                   $joinWhere";
    $summaryStmt = $conn->prepare($summarySql);
    if ($surveyKey !== 'all') $summaryStmt->bind_param('s', $surveyKey);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $distribution = ['1' => 0, '2' => 0, '3' => 0];
    $distSql = "SELECT a.rating, COUNT(*) AS total
                FROM survey_answers a
                INNER JOIN survey_responses r ON r.id = a.response_id
                $joinWhere
                GROUP BY a.rating";
    $distStmt = $conn->prepare($distSql);
    if ($surveyKey !== 'all') $distStmt->bind_param('s', $surveyKey);
    $distStmt->execute();
    $distResult = $distStmt->get_result();
    while ($row = $distResult->fetch_assoc()) {
        $distribution[(string) $row['rating']] = (int) $row['total'];
    }
    $distStmt->close();

    $questionAverages = [];
    $questionSql = "SELECT a.question_key, a.question_label, COUNT(*) AS count, COALESCE(AVG(a.rating), 0) AS average
                    FROM survey_answers a
                    INNER JOIN survey_responses r ON r.id = a.response_id
                    $joinWhere
                    GROUP BY a.question_key, a.question_label
                    ORDER BY average DESC, a.question_label ASC";
    $questionStmt = $conn->prepare($questionSql);
    if ($surveyKey !== 'all') $questionStmt->bind_param('s', $surveyKey);
    $questionStmt->execute();
    $questionResult = $questionStmt->get_result();
    while ($row = $questionResult->fetch_assoc()) {
        $questionAverages[] = [
            'questionKey' => $row['question_key'],
            'questionLabel' => $row['question_label'],
            'count' => (int) $row['count'],
            'average' => round((float) $row['average'], 2),
        ];
    }
    $questionStmt->close();

    $recentResponses = [];
    $recentSql = "SELECT r.id, r.survey_key, r.survey_title, r.name, r.whatsapp, r.room, r.feedback, r.created_at,
                         rv.reservation_code,
                         COALESCE(AVG(a.rating), 0) AS average
                  FROM survey_responses r
                  LEFT JOIN survey_answers a ON a.response_id = r.id
                  LEFT JOIN reservations rv ON rv.id = r.reservation_id
                  $where
                  GROUP BY r.id, r.survey_key, r.survey_title, r.name, r.whatsapp, r.room, r.feedback, r.created_at, rv.reservation_code
                  ORDER BY r.created_at DESC
                  LIMIT 10";
    $recentStmt = $conn->prepare($recentSql);
    if ($surveyKey !== 'all') $recentStmt->bind_param('s', $surveyKey);
    $recentStmt->execute();
    $recentResult = $recentStmt->get_result();
    while ($row = $recentResult->fetch_assoc()) {
        $recentResponses[] = [
            'id' => (int) $row['id'],
            'surveyKey' => $row['survey_key'],
            'surveyTitle' => $row['survey_title'],
            'name' => $row['name'],
            'whatsapp' => $row['whatsapp'],
            'room' => $row['room'],
            'feedback' => $row['feedback'],
            'createdAt' => $row['created_at'],
            'reservationCode' => $row['reservation_code'] ?? null,
            'average' => round((float) $row['average'], 2),
        ];
    }
    $recentStmt->close();
    $conn->close();

    json_response([
        'ok' => true,
        'totalResponses' => $totalResponses,
        'totalAnswers' => (int) ($summary['total_answers'] ?? 0),
        'average' => round((float) ($summary['average'] ?? 0), 2),
        'distribution' => $distribution,
        'questionAverages' => $questionAverages,
        'recentResponses' => $recentResponses,
    ]);
} catch (Throwable $error) {
    $conn->close();
    json_response([
        'ok' => false,
        'message' => 'No fue posible cargar el dashboard.',
    ], 500);
}
