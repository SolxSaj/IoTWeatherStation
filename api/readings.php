<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respondJson(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respondJson(405, ['error' => 'Método no permitido. Usa POST.']);
}

try {
    require __DIR__ . '/../includes/db.php';
    $settings = getMongoSettings();
    $expectedToken = $settings['apiToken'] ?? '';

    if (!is_string($expectedToken) || strlen($expectedToken) < 32) {
        respondJson(503, ['error' => 'El token de la API no está configurado correctamente.']);
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $headerValue) {
            if (strcasecmp($headerName, 'Authorization') === 0 && is_string($headerValue)) {
                $authorization = $headerValue;
                break;
            }
        }
    }

    if (!is_string($authorization)
        || !preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $tokenMatch)
        || !hash_equals($expectedToken, trim($tokenMatch[1]))) {
        respondJson(401, ['error' => 'Token de autorización inválido o ausente.']);
    }

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType === 'application/json') {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || strlen($rawBody) > 16384) {
            respondJson(413, ['error' => 'El cuerpo de la solicitud está vacío o es demasiado grande.']);
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            respondJson(400, ['error' => 'El JSON no es válido.']);
        }
    } elseif ($contentType === 'application/x-www-form-urlencoded' || $contentType === 'multipart/form-data') {
        $payload = $_POST;
    } else {
        respondJson(415, ['error' => 'Usa application/json o un formulario POST.']);
    }

    if (!is_array($payload)) {
        respondJson(400, ['error' => 'El cuerpo debe contener un objeto JSON o un formulario.']);
    }

    if (array_key_exists('idLecture', $payload)) {
        respondJson(422, ['error' => 'idLecture lo genera el servidor; no lo incluyas en la petición.']);
    }

    $deviceId = filter_var($payload['idDevice'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($deviceId === false) {
        respondJson(422, ['error' => 'idDevice debe ser un entero positivo.']);
    }

    $reading = [];
    foreach (['temp', 'humity', 'pressure'] as $field) {
        $value = $payload[$field] ?? null;
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            respondJson(422, ['error' => "$field debe ser numérico."]);
        }

        $number = (float) $value;
        if (!is_finite($number)) {
            respondJson(422, ['error' => "$field debe ser un número finito."]);
        }

        $reading[$field] = $number;
    }

    if ($reading['humity'] < 0 || $reading['humity'] > 100) {
        respondJson(422, ['error' => 'humity debe estar entre 0 y 100.']);
    }

    $timestampInput = $payload['timestamp'] ?? null;
    try {
        if ($timestampInput === null || $timestampInput === '') {
            $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } elseif (is_string($timestampInput)) {
            $timestamp = new DateTimeImmutable($timestampInput);
        } else {
            respondJson(422, ['error' => 'timestamp debe ser una fecha en formato de texto.']);
        }
    } catch (Throwable) {
        respondJson(422, ['error' => 'timestamp no contiene una fecha válida.']);
    }

    $timestamp = $timestamp->setTimezone(new DateTimeZone('UTC'));
    $timestampMilliseconds = ((int) $timestamp->format('U') * 1000) + (int) $timestamp->format('v');

    $database = getWeatherDatabase();
    $collection = $database->selectCollection($settings['collection'] ?? 'IoTWheatherStation');
    $device = $collection->findOne(
        ['idDevice' => $deviceId, 'descripcion' => ['$type' => 'string']],
        ['projection' => ['_id' => 1]]
    );
    if ($device === null) {
        respondJson(422, ['error' => 'No existe un dispositivo registrado con ese idDevice.']);
    }

    $lectureId = getNextReadingId($database, $collection);
    $document = $reading + [
        'idLecture' => $lectureId,
        'idDevice' => $deviceId,
        'timestamp' => new MongoDB\BSON\UTCDateTime($timestampMilliseconds),
    ];
    $result = $collection->insertOne($document);
    $response = [
        'ok' => true,
        'insertedId' => (string) $result->getInsertedId(),
        'reading' => [
            'idDevice' => $deviceId,
            'idLecture' => $lectureId,
            'temp' => $reading['temp'],
            'humity' => $reading['humity'],
            'pressure' => $reading['pressure'],
            'timestamp' => $timestamp->format(DATE_ATOM),
        ],
    ];
    respondJson(201, $response);
} catch (Throwable $error) {
    error_log('Reading API error: ' . get_class($error) . ' (code ' . $error->getCode() . ').');
    respondJson(500, ['error' => 'No se pudo guardar la lectura.']);
}