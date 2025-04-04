<?php

/**
 * PHP Proxy Script
 * 
 * Handles GET, POST, PUT, DELETE, OPTIONS requests and proxies them
 * to the target URL with support for various content types including
 * form data and multipart/form-data.
 */

// Enable error reporting for debugging
error_reporting(E_ALL);

ini_set('log_errors', true);
ini_set("error_log", "./errors.log");
ini_set('display_errors', false); // Set to TRUE in development

// Configuration
$config = [
    // Target server URL. Change as per your server
    'target_url' => 'https://<api.yoursite.com>/',

    // Request timeout in seconds
    'timeout' => 30,

    // Whether to forward client IP to the target server
    'forward_ip' => true,

    // Headers to exclude from being forwarded
    'excluded_headers' => [
        'host',
        'connection',
        'content-length',
        'content-md5',
        'expect',
        'max-forwards',
        'pragma',
        'range',
        'te',
        'if-match',
        'if-none-match',
        'if-modified-since',
        'if-unmodified-since',
        'if-range',
        'accept-encoding',
        'content-encoding',
        'transfer-encoding'
    ],

    'user_agent' => 'Mozilla/5.0 (compatible; ProxyScript/1.0)', // User agent to use
];

$requestUri = implode('/', array_slice(explode('/', $_SERVER['REQUEST_URI']), 3));
$targetUrl = $config['target_url'] . $requestUri;
$method = $_SERVER['REQUEST_METHOD'];

$ch = curl_init(); // Initialize cURL

curl_setopt_array($ch, getCurlOptions($targetUrl, $method)); // Build cURL options

$response = curl_exec($ch); // Execute request
if ($response === false) {
    debugLog("cURL Error for [$method] $targetUrl \n" . curl_error($ch));
    header('HTTP/1.1 502 Bad Gateway');
    echo json_encode(['error' => 'Failed to proxy request: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

// Get response info
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

// Split response into headers and body
$responseHeadersStr = substr($response, 0, $headerSize);
$responseBody = substr($response, $headerSize);

curl_close($ch); // Close cURL

header("HTTP/1.1 $httpStatus"); // Set response status

// Set response headers
$responseHeaders = getParsdeResponseHeaders($responseHeadersStr); // Parse response headers
foreach ($responseHeaders as $key => $value) {
    header("$key: $value");
}

echo $responseBody; // Output response body

gc_collect_cycles(); // Clear memory
exit; // Exit

######################################### Helper Functions ######################################################

/**
 * Log debug information if debug mode is enabled
 */
function debugLog(string $message): void
{
    $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    file_put_contents('proxy_log.log', $logEntry, FILE_APPEND);
}

/**
 * Get request headers to forward
 */
function getRequestHeaders(): array
{
    global $config;

    $headers = [];

    // Get all headers and filter out excluded ones
    foreach (getallheaders() as $headerName => $headerValue) {
        if (!in_array(strtolower($headerName), $config['excluded_headers'])) {
            $headers[$headerName] = $headerValue;
        }
    }

    // Forward content type if not excluded and if it exists
    if (
        !isset($headers['Content-Type'])
        && isset($_SERVER['CONTENT_TYPE'])
        && !in_array('content-type', $config['excluded_headers'])
    ) {
        $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
    }

    // Remove Content-Type header if it's multipart/form-data
    if (isset($headers['Content-Type']) && strpos($headers['Content-Type'], 'multipart/form-data') !== false) {
        unset($headers['Content-Type']);
    }

    // Forward client IP if enabled
    if ($config['forward_ip']) {
        $headers['X-Forwarded-For'] = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? $_SERVER['HTTP_X_FORWARDED_FOR']
            : $_SERVER['REMOTE_ADDR'];
    }

    // Add a custom authorization header if needed
    if (!isset($headers['Authorization'])) {
        $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            $authorization = $apacheHeaders['Authorization'] ?? $authorization;
        }
        $headers['Authorization'] = $authorization;
    }

    return $headers;
}

/**
 * Get cURL options
 */
function getCurlOptions(string $targetUrl, string $method): array
{
    global $config;

    $curlOptions = [
        CURLOPT_URL => $targetUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => $config['user_agent']
    ];

    // Convert headers array to cURL format
    $headerLines = [];
    foreach (getRequestHeaders() as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }

    $curlOptions[CURLOPT_HTTPHEADER] = $headerLines;
    $curlOptions[CURLOPT_POSTFIELDS] = getPostFields($method);

    return $curlOptions;
}

/**
 * Get post fields
 */
function getPostFields(string $method): array|string
{
    $postFields = [];

    // Handle request body for methods that support it
    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // Handle different content types
        if (strpos($contentType, 'multipart/form-data') !== false) {
            $postFields = $_POST;

            // Add file fields
            foreach ($_FILES as $key => $fileInfo) {
                if (is_array($fileInfo['name'])) {
                    // Multiple files with the same name
                    for ($i = 0; $i < count($fileInfo['name']); $i++) {
                        if ($fileInfo['error'][$i] === UPLOAD_ERR_OK) {
                            $postFields[$key . '[' . $i . ']'] = curl_file_create(
                                $fileInfo['tmp_name'][$i],
                                $fileInfo['type'][$i],
                                $fileInfo['name'][$i]
                            );
                        }
                    }
                } else {
                    // Single file
                    if ($fileInfo['error'] === UPLOAD_ERR_OK) {
                        $postFields[$key] = curl_file_create(
                            $fileInfo['tmp_name'],
                            $fileInfo['type'],
                            $fileInfo['name']
                        );
                    }
                }
            }
        } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            // Handle URL encoded form data
            $postFields = http_build_query($_POST);
        } else {
            $postFields = file_get_contents('php://input');
        }
    }

    return $postFields;
}

/**
 * Parse response headers from cURL response
 */
function getParsdeResponseHeaders(string $responseHeadersStr): array
{
    $headers = [];
    $headerLines = explode("\r\n", $responseHeadersStr);

    foreach ($headerLines as $line) {
        if (strpos($line, ':') !== false) {
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Skip headers we don't want to forward
            if (in_array(strtolower($key), ['transfer-encoding', 'connection'])) {
                continue;
            }

            $headers[$key] = $value;
        }
    }

    return $headers;
}
