<?php

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    throw new RuntimeException('Faltan dependencias. Ejecuta "composer install" en la carpeta del proyecto.');
}

require_once $autoload;

function getMongoSettings(): array
{
    $settingsFile = __DIR__ . '/../mongodb.config.local.php';
    $settings = [];

    if (is_file($settingsFile)) {
        $localSettings = require $settingsFile;
        if (!is_array($localSettings)) {
            throw new RuntimeException('mongodb.config.local.php debe devolver un arreglo de configuración.');
        }
        $settings = $localSettings;
    }

    $environmentUri = getenv('MONGODB_URI');
    $uri = is_string($environmentUri) && trim($environmentUri) !== ''
        ? $environmentUri
        : ($settings['uri'] ?? null);

    if (!is_string($uri) || trim($uri) === '') {
        throw new RuntimeException('Configura MONGODB_URI en el entorno o en mongodb.config.local.php.');
    }

    $settings['uri'] = $uri;
    $environmentToken = getenv('TOKEN');
    if (is_string($environmentToken) && trim($environmentToken) !== '') {
        $settings['apiToken'] = $environmentToken;
    } else {
        $settings['apiToken'] = $settings['apiToken'] ?? '';
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
