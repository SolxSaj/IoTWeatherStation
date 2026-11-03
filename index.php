<?php
$records = [];
$devices = [];
$deviceNames = [];
$selectedDevice = '';
$selectedFromDate = $_GET['from'] ?? '';
$selectedToDate = $_GET['to'] ?? '';
$selectedFromDate = is_string($selectedFromDate) ? trim($selectedFromDate) : '';
$selectedToDate = is_string($selectedToDate) ? trim($selectedToDate) : '';
$dateFilterError = null;
$summary = ['avgTemperature' => null, 'avgHumidity' => null, 'avgPressure' => null, 'readingCount' => 0];
$databaseError = null;

$utc = new DateTimeZone('UTC');
$fromDate = $selectedFromDate !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $selectedFromDate, $utc) : null;
$toDate = $selectedToDate !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $selectedToDate, $utc) : null;

if ($selectedFromDate !== '' && (!$fromDate || $fromDate->format('Y-m-d') !== $selectedFromDate)) {
    $dateFilterError = 'La fecha inicial no es válida.';
} elseif ($selectedToDate !== '' && (!$toDate || $toDate->format('Y-m-d') !== $selectedToDate)) {
    $dateFilterError = 'La fecha final no es válida.';
} elseif ($fromDate && $toDate && $fromDate > $toDate) {
    $dateFilterError = 'La fecha inicial debe ser anterior o igual a la fecha final.';
}

try {
    require __DIR__ . '/includes/db.php';
    $collection = getWeatherCollection();
    $typeMap = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
    $readingFilter = ['temp' => ['$type' => 'number'], 'timestamp' => ['$type' => 'date']];
    $devices = $collection->distinct('idDevice', $readingFilter);
    $devices = array_values(array_filter($devices, static fn($deviceId) => is_int($deviceId) || is_float($deviceId)));
    usort($devices, static fn($left, $right) => (int) $left <=> (int) $right);

    $deviceMetadata = $collection->find(
        ['idDevice' => ['$in' => $devices], 'descripcion' => ['$type' => 'string']],
        ['projection' => ['idDevice' => 1, 'descripcion' => 1], 'typeMap' => $typeMap]
    )->toArray();
    foreach ($deviceMetadata as $metadata) {
        if (isset($metadata['idDevice'], $metadata['descripcion'])) {
            $deviceNames[(string) (int) $metadata['idDevice']] = trim($metadata['descripcion']);
        }
    }

    $deviceValues = array_map(static fn($deviceId) => (string) (int) $deviceId, $devices);
    $requestedDevice = $_GET['device'] ?? '';
    if (is_string($requestedDevice) && in_array($requestedDevice, $deviceValues, true)) {
        $selectedDevice = $requestedDevice;
        $readingFilter['idDevice'] = (int) $selectedDevice;
    }

    if ($dateFilterError === null) {
        $timestampFilter = ['$type' => 'date'];
        if ($fromDate) {
            $timestampFilter['$gte'] = new MongoDB\BSON\UTCDateTime((int) $fromDate->format('U') * 1000);
        }
        if ($toDate) {
            $exclusiveEnd = $toDate->modify('+1 day');
            $timestampFilter['$lt'] = new MongoDB\BSON\UTCDateTime((int) $exclusiveEnd->format('U') * 1000);
        }
        $readingFilter['timestamp'] = $timestampFilter;

        $summaryRows = $collection->aggregate([
            ['$match' => $readingFilter],
            ['$group' => [
                '_id' => null,
                'avgTemperature' => ['$avg' => '$temp'],
                'avgHumidity' => ['$avg' => '$humity'],
                'avgPressure' => ['$avg' => '$pressure'],
                'readingCount' => ['$sum' => 1],
            ]],
        ], ['typeMap' => $typeMap])->toArray();

        if ($summaryRows) {
            $summary = array_merge($summary, $summaryRows[0]);
        }

        $records = $collection->find(
            $readingFilter,
            ['sort' => ['timestamp' => -1], 'limit' => 12, 'typeMap' => $typeMap]
        )->toArray();
    }
} catch (Throwable $error) {
    error_log('MongoDB dashboard error: ' . get_class($error) . ' (code ' . $error->getCode() . ').');
    $databaseError = 'No se pudo conectar con MongoDB. Revisa la extensión PHP, las dependencias y mongodb.config.local.php.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estación meteorológica</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container">
        <header class="topbar">
            <div>
                <p class="eyebrow">Monitor IoT</p>
                <h1>Estación meteorológica</h1>
            </div>
            <?php if ($databaseError): ?>
                <div class="status-pill status-error">MongoDB desconectada</div>
            <?php else: ?>
                <div class="status-pill"><span class="dot"></span>MongoDB conectada</div>
            <?php endif; ?>
        </header>

        <?php if ($databaseError): ?>
            <p class="empty-state" role="alert"><?= htmlspecialchars($databaseError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($dateFilterError): ?>
            <p class="empty-state" role="alert"><?= htmlspecialchars($dateFilterError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="metrics-grid">
            <article class="metric-card accent-blue">
                <span class="label">Temperatura promedio</span>
                <strong><?= $summary['avgTemperature'] === null ? '--' : number_format((float) $summary['avgTemperature'], 1) ?>°C</strong>
            </article>
            <article class="metric-card accent-green">
                <span class="label">Humedad promedio</span>
                <strong><?= $summary['avgHumidity'] === null ? '--' : number_format((float) $summary['avgHumidity'], 1) ?>%</strong>
            </article>
            <article class="metric-card accent-orange">
                <span class="label">Presión promedio</span>
                <strong><?= $summary['avgPressure'] === null ? '--' : number_format((float) $summary['avgPressure'], 1) ?></strong>
            </article>
            <article class="metric-card accent-purple">
                <span class="label">Lecturas registradas</span>
                <strong><?= number_format((int) $summary['readingCount']) ?></strong>
            </article>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2>Lecturas recientes</h2>
                <form class="filter-form" method="get" action="index.php">
                    <label class="filter-field" for="device">
                        <span>Dispositivo</span>
                        <select id="device" name="device">
                            <option value="">Todos</option>
                            <?php foreach ($devices as $deviceId): ?>
                                <?php
                                $deviceValue = (string) (int) $deviceId;
                                $deviceName = $deviceNames[$deviceValue] ?? 'Dispositivo';
                                ?>
                                <option value="<?= htmlspecialchars($deviceValue, ENT_QUOTES, 'UTF-8') ?>" <?= $selectedDevice === $deviceValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($deviceName, ENT_QUOTES, 'UTF-8') ?> (#<?= htmlspecialchars($deviceValue, ENT_QUOTES, 'UTF-8') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="filter-field" for="from">
                        <span>Desde</span>
                        <input id="from" name="from" type="date" value="<?= htmlspecialchars($selectedFromDate, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="filter-field" for="to">
                        <span>Hasta</span>
                        <input id="to" name="to" type="date" value="<?= htmlspecialchars($selectedToDate, ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <button type="submit">Filtrar</button>
                    <?php if ($selectedDevice !== '' || $selectedFromDate !== '' || $selectedToDate !== ''): ?>
                        <a class="clear-filter" href="index.php">Limpiar</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (empty($records)): ?>
                <p class="empty-state">No hay lecturas para los filtros seleccionados.</p>
            <?php else: ?>
                <div class="records-list" role="table" aria-label="Lecturas recientes">
                    <div class="records-list-header" role="row">
                        <span role="columnheader">Fecha</span>
                        <span role="columnheader">Dispositivo</span>
                        <span role="columnheader">Temperatura</span>
                        <span role="columnheader">Humedad</span>
                        <span role="columnheader">Presión</span>
                    </div>
                    <div class="records-list-body" role="rowgroup">
                        <?php foreach ($records as $record): ?>
                            <?php
                            $timestamp = $record['timestamp'] ?? null;
                            $recordedAt = $timestamp instanceof MongoDB\BSON\UTCDateTime
                                ? $timestamp->toDateTime()->format('d/m/Y H:i')
                                : 'Fecha no disponible';
                            $recordDeviceId = (string) (int) ($record['idDevice'] ?? 0);
                            $recordDeviceName = $deviceNames[$recordDeviceId] ?? 'Dispositivo';
                            ?>
                            <article class="record-card" role="row">
                                <span class="record-date" role="cell"><?= htmlspecialchars($recordedAt, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="record-device" role="cell">
                                    <?= htmlspecialchars($recordDeviceName, ENT_QUOTES, 'UTF-8') ?>
                                    <span class="device-id">#<?= htmlspecialchars($recordDeviceId, ENT_QUOTES, 'UTF-8') ?></span>
                                </span>
                                <strong class="record-value" role="cell"><?= number_format((float) ($record['temp'] ?? 0), 1) ?>°C</strong>
                                <strong class="record-value" role="cell"><?= number_format((float) ($record['humity'] ?? 0), 1) ?>%</strong>
                                <strong class="record-value" role="cell"><?= number_format((float) ($record['pressure'] ?? 0), 1) ?></strong>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</body>
</html>
