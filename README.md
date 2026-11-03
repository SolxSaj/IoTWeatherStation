# IoT Weather Station Dashboard

PHP dashboard connected to the MongoDB database `IoTWheatherStation` and its `IoTWheatherStation` collection.

## Features

- PHP + MongoDB connection
- Authenticated POST endpoint for sensor readings
- Average temperature, humidity, and pressure metrics
- Responsive grid layout for reading cards
- Recent sensor readings from MongoDB

## Run locally

1. Install PHP with the MongoDB extension enabled (`extension=mongodb` in `php.ini`).
2. Install Composer and the project dependency:

```bash
composer install
```

3. Create a local config file from the example:

```powershell
Copy-Item mongodb.config.example.php mongodb.config.local.php
```

Edit `mongodb.config.local.php` with your MongoDB URI. This file is excluded from Git; do not remove it from `.gitignore`.

4. Start the local development server:

```bash
php -S localhost:8000
```

5. Open the browser at `http://localhost:8000`.

## Receive readings

Add an API token of at least 32 characters to `mongodb.config.local.php`. To generate one locally:

```powershell
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Copy the result into the `apiToken` setting. Send a `POST` request to `/api/readings.php` with a Bearer token and JSON body:

```powershell
$headers = @{ Authorization = "Bearer <your-local-api-token>" }
$body = @{
	idDevice = 1
	temp = 23.7
	humity = 51
	pressure = 25.4
	timestamp = (Get-Date).ToUniversalTime().ToString("o")
} | ConvertTo-Json

Invoke-RestMethod -Uri "http://localhost:8000/api/readings.php" `
	-Method Post -Headers $headers -ContentType "application/json" -Body $body
```

`idDevice`, `temp`, `humity`, and `pressure` are required. `timestamp` defaults to the current UTC time. The server assigns `idLecture` with an atomic counter initialized from the largest existing reading ID, so do not include it in the request. The endpoint rejects device IDs without a descriptive device record and returns HTTP `201` when the reading is stored. Use HTTPS when exposing the endpoint beyond local development.

## Project structure

- `index.php` - Main dashboard page
- `api/readings.php` - Authenticated reading ingestion endpoint
- `includes/db.php` - MongoDB connection helper
- `mongodb.config.example.php` - Safe MongoDB config template
- `mongodb.config.local.php` - Local MongoDB credentials (not committed)
- `assets/style.css` - Dashboard styling

## Notes

The dashboard uses the collection fields `temp`, `humity`, `pressure`, `timestamp`, and `idDevice`. The collection does not currently include a weather-condition field, so the dashboard shows sensor readings and metrics instead.
