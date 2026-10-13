<?php
/**
 * Microi IoT HTTP-to-HTTPS Proxy Gateway
 * =========================================================================
 * Place this file at: http://bluecoregames.com/telemetry_relay.php
 *
 * Purpose:
 * SIM800 / SIM808 2G modules only support deprecated SSLv3 / TLS 1.0 stacks,
 * which modern web hosts (Cloudflare, LiteSpeed, Hostinger) reject over HTTPS.
 *
 * This lightweight script receives plain HTTP GET/POST from the Arduino SIM808
 * over port 80 (where SSL is not required) and forwards it securely via cURL
 * over modern TLS 1.2/1.3 to https://microi.micromek.net/api/v1/device/telemetry.
 */

// Target secure Microi Ingestion API
$destinationUrl = 'https://microi.micromek.net/api/v1/device/telemetry';

// Collect all incoming parameters from either GET or POST
$params = $_GET;
if (empty($params) && !empty($_POST)) {
    $params = $_POST;
}

// Fallback: check raw JSON payload in php://input
if (empty($params)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            $params = $decoded;
        }
    }
}

// If no telemetry params received, show health status
if (empty($params)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'idle',
        'message' => 'Microi Telemetry Relay is online and waiting for Arduino data.'
    ]);
    exit;
}

// Append parameters as query string for destination forward
$forwardUrl = $destinationUrl . '?' . http_build_query($params);

// Initialize cURL session with modern TLS support
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $forwardUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false, // Prevents curl CA mismatches on shared host
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT => 'Microi-Relay/1.0 (SIM808-Bridge)',
    CURLOPT_HTTPHEADER => [
        'Accept: application/json'
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Output HTTP status code and response back to SIM808
http_response_code($httpCode ?: 500);
header('Content-Type: application/json; charset=utf-8');

if ($curlError) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Relay failed to reach Microi: ' . $curlError
    ]);
} else {
    echo $response;
}
