<?php

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_db(): mysqli
{
    $config = require __DIR__ . '/../config/db.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli(
            $config['host'],
            $config['user'],
            $config['password'],
            $config['database'],
            $config['port']
        );
        $conn->set_charset($config['charset']);
        return $conn;
    } catch (Throwable $error) {
        json_response([
            'ok' => false,
            'message' => 'No fue posible conectar con la base de datos. Revisa backend/config/db.php.',
        ], 500);
    }
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['ok' => false, 'message' => 'El cuerpo de la solicitud no es JSON válido.'], 400);
    }
    return $data;
}

function clean_string(mixed $value, int $maxLength = 255): string
{
    $text = trim((string) ($value ?? ''));
    $text = preg_replace('/\s+/', ' ', $text) ?? '';
    return mb_substr($text, 0, $maxLength);
}

function allowed_surveys(): array
{
    return [
        'hotel' => ['title' => 'Hotel', 'requiresRoom' => true],
        'balneario' => ['title' => 'Balneario', 'requiresRoom' => false],
        'restaurantes' => ['title' => 'Restaurantes y bar', 'requiresRoom' => false],
        'spa' => ['title' => 'Spa', 'requiresRoom' => false],
    ];
}

function normalize_survey_key(string $key): string
{
    $key = strtolower(trim($key));
    return preg_match('/^[a-z0-9_-]+$/', $key) ? $key : '';
}

function validate_rating(mixed $value): int
{
    $rating = (int) $value;
    return in_array($rating, [1, 2, 3], true) ? $rating : 0;
}
