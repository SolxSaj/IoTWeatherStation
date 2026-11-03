<?php

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException('Faltan dependencias. Ejecuta "composer install" en la carpeta del proyecto.');
}

require_once $autoload;

function getMongoSettings(): array
{
    $settingsFile = __DIR__ . '/../mongodb.config.local.php';

    if (!is_file($settingsFile)) {
        throw new RuntimeException('Copia mongodb.config.example.php como mongodb.config.local.php y configura tu URI.');
    }

    $settings = require $settingsFile;
    if (!is_array($settings)) {
        throw new RuntimeException('mongodb.config.local.php debe devolver un arreglo de configuración.');
    }

    $uri = $settings['uri'] ?? null;

    if (!is_string($uri) || trim($uri) === '') {
        throw new RuntimeException('Configura una URI válida en mongodb.config.local.php.');
    }

    return $settings;
}

function getWeatherCollection(): MongoDB\Collection
{
    $settings = getMongoSettings();
    return getWeatherDatabase()->selectCollection($settings['collection'] ?? 'IoTWheatherStation');
}

function getWeatherDatabase(): MongoDB\Database
{
    $settings = getMongoSettings();
    $client = new MongoDB\Client($settings['uri']);

    try {
    $client = new MongoDB\Client($config['uri']);
    $db = $client->selectDatabase($config['database']);
    // Prueba rápida de comando
    $db->command(['ping' => 1]);
    echo "¡Conexión exitosa a MongoDB Atlas!";
} catch (Exception $e) {
    echo "Fallo crítico al conectar: " . $e->getMessage();
}
exit;

    return $client->selectDatabase($settings['database'] ?? 'IoTWheatherStation');
}

function getNextReadingId(MongoDB\Database $database, MongoDB\Collection $readings): int
{
    $counterCollection = $database->selectCollection('api_counters');
    $counterId = 'readingId';
    $typeMap = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
    $counter = $counterCollection->findOne(['_id' => $counterId], ['projection' => ['_id' => 1]]);

    if ($counter === null) {
        $lastReading = $readings->findOne(
            ['idLecture' => ['$type' => 'number'], 'temp' => ['$type' => 'number']],
            [
                'sort' => ['idLecture' => -1],
                'projection' => ['idLecture' => 1],
                'typeMap' => $typeMap,
            ]
        );
        $initialValue = is_array($lastReading) ? (int) ($lastReading['idLecture'] ?? 0) : 0;

        try {
            $counterCollection->updateOne(
                ['_id' => $counterId],
                ['$max' => ['value' => $initialValue]],
                ['upsert' => true]
            );
        } catch (Throwable $error) {
            $counter = $counterCollection->findOne(['_id' => $counterId], ['projection' => ['_id' => 1]]);
            if ($counter === null) {
                throw $error;
            }

            $counterCollection->updateOne(['_id' => $counterId], ['$max' => ['value' => $initialValue]]);
        }
    }

    $updatedCounter = $counterCollection->findOneAndUpdate(
        ['_id' => $counterId],
        ['$inc' => ['value' => 1]],
        [
            'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            'typeMap' => $typeMap,
        ]
    );

    if (!is_array($updatedCounter) || !isset($updatedCounter['value'])) {
        throw new RuntimeException('No se pudo generar el ID de la lectura.');
    }

    return (int) $updatedCounter['value'];
}
