<?php


declare(strict_types=1);


/*
 * Rubrik Security Cloud PHP Framework
 *
 * This file centralizes:
 * - Configuration loading
 * - OAuth2 client credential authentication
 * - In-memory access-token caching
 * - Generic HTTP POST requests
 * - GraphQL query and mutation execution
 * - Consistent response and error handling
 *
 * Compatible with PHP 8.1 and later.
 */


const RK_RSC_DEFAULT_CONNECT_TIMEOUT = 30;
const RK_RSC_DEFAULT_REQUEST_TIMEOUT = 120;
const RK_RSC_TOKEN_EXPIRY_MARGIN = 60;


/*
 * The token cache remains in memory for the lifetime of the PHP process.
 * It avoids requesting a new token before every GraphQL operation.
 */
$GLOBALS['rkRscTokenCache'] = [
    'accessToken' => null,
    'expiresAt' => 0
];


// ----------------------------------------
// Function rkLoadConfiguration : Load and validate the Rubrik Security Cloud configuration
// ----------------------------------------
function rkLoadConfiguration(
    ?string $configurationFile = null
): array
{
    if ($configurationFile === null)
    {
        $configurationFile = dirname(__DIR__)
            . '/config/rsc-config.php';
    }


    if (!is_file($configurationFile))
    {
        throw new RuntimeException(
            "Rubrik configuration file not found: {$configurationFile}"
        );
    }


    $configuration = require $configurationFile;


    if (!is_array($configuration))
    {
        throw new RuntimeException(
            'The Rubrik configuration file must return an array.'
        );
    }


    $requiredKeys = [
        'rsc_url',
        'client_id',
        'client_secret'
    ];


    foreach ($requiredKeys as $requiredKey)
    {
        if (
            !array_key_exists($requiredKey, $configuration)
            || !is_string($configuration[$requiredKey])
            || trim($configuration[$requiredKey]) === ''
        )
        {
            throw new RuntimeException(
                "Missing or invalid configuration value: {$requiredKey}"
            );
        }


        $configuration[$requiredKey] = trim(
            $configuration[$requiredKey]
        );
    }


    $configuration['rsc_url'] = rtrim(
        $configuration['rsc_url'],
        '/'
    );


    $configuration['connect_timeout'] = rkNormalizePositiveInteger(
        $configuration['connect_timeout']
            ?? RK_RSC_DEFAULT_CONNECT_TIMEOUT,
        'connect_timeout'
    );


    $configuration['request_timeout'] = rkNormalizePositiveInteger(
        $configuration['request_timeout']
            ?? RK_RSC_DEFAULT_REQUEST_TIMEOUT,
        'request_timeout'
    );


    $configuration['verify_ssl'] = rkNormalizeBoolean(
        $configuration['verify_ssl'] ?? true,
        'verify_ssl'
    );


    return $configuration;
}


// ----------------------------------------
// Function rkNormalizePositiveInteger : Validate and normalize a positive integer configuration value
// ----------------------------------------
function rkNormalizePositiveInteger(
    mixed $value,
    string $name
): int
{
    if (is_string($value) && ctype_digit($value))
    {
        $value = (int) $value;
    }


    if (!is_int($value) || $value <= 0)
    {
        throw new RuntimeException(
            "Configuration value {$name} must be a positive integer."
        );
    }


    return $value;
}


// ----------------------------------------
// Function rkNormalizeBoolean : Validate and normalize a boolean configuration value
// ----------------------------------------
function rkNormalizeBoolean(
    mixed $value,
    string $name
): bool
{
    if (is_bool($value))
    {
        return $value;
    }


    if (is_string($value))
    {
        $normalizedValue = strtolower(trim($value));


        if (in_array($normalizedValue, ['1', 'true', 'yes', 'on'], true))
        {
            return true;
        }


        if (in_array($normalizedValue, ['0', 'false', 'no', 'off'], true))
        {
            return false;
        }
    }


    throw new RuntimeException(
        "Configuration value {$name} must be a boolean."
    );
}


// ----------------------------------------
// Function rkRscGetToken : Obtain or reuse an OAuth2 access token from Rubrik Security Cloud
// ----------------------------------------
function rkRscGetToken(
    bool $forceRefresh = false,
    ?array $configuration = null
): string
{
    if ($configuration === null)
    {
        $configuration = rkLoadConfiguration();
    }


    $cachedToken = $GLOBALS['rkRscTokenCache']['accessToken']
        ?? null;

    $cachedExpiry = $GLOBALS['rkRscTokenCache']['expiresAt']
        ?? 0;


    if (
        !$forceRefresh
        && is_string($cachedToken)
        && $cachedToken !== ''
        && is_int($cachedExpiry)
        && $cachedExpiry > time() + RK_RSC_TOKEN_EXPIRY_MARGIN
    )
    {
        return $cachedToken;
    }


    $tokenUrl = $configuration['rsc_url']
        . '/api/client_token';


    /*
     * Rubrik Security Cloud expects client_id and client_secret
     * in the JSON POST body for the client credential request.
     */
    $payload = [
        'client_id' => $configuration['client_id'],
        'client_secret' => $configuration['client_secret']
    ];


    $response = rkHttpPostJson(
        $tokenUrl,
        $payload,
        [],
        $configuration
    );


    $responseBody = $response['body'];


    $accessToken = $responseBody['access_token']
        ?? null;


    if (!is_string($accessToken) || $accessToken === '')
    {
        throw new RuntimeException(
            'Rubrik Security Cloud did not return an access_token.'
        );
    }


    $expiresIn = $responseBody['expires_in']
        ?? 3600;


    if (is_string($expiresIn) && ctype_digit($expiresIn))
    {
        $expiresIn = (int) $expiresIn;
    }


    if (!is_int($expiresIn) || $expiresIn <= 0)
    {
        $expiresIn = 3600;
    }


    $GLOBALS['rkRscTokenCache'] = [
        'accessToken' => $accessToken,
        'expiresAt' => time() + $expiresIn
    ];


    return $accessToken;
}


// ----------------------------------------
// Function rkRscClearTokenCache : Clear the in-memory Rubrik access-token cache
// ----------------------------------------
function rkRscClearTokenCache(): void
{
    $GLOBALS['rkRscTokenCache'] = [
        'accessToken' => null,
        'expiresAt' => 0
    ];
}


// ----------------------------------------
// Function rkExecuteGraphQL : Execute a GraphQL query or mutation against Rubrik Security Cloud
// ----------------------------------------
function rkExecuteGraphQL(
    string $query,
    array $variables = [],
    ?string $operationName = null,
    ?array $configuration = null
): array
{
    $query = trim($query);


    if ($query === '')
    {
        throw new InvalidArgumentException(
            'The GraphQL query cannot be empty.'
        );
    }


    if ($configuration === null)
    {
        $configuration = rkLoadConfiguration();
    }


    $accessToken = rkRscGetToken(
        false,
        $configuration
    );


    $payload = [
        'query' => $query,
        'variables' => $variables
    ];


    if ($operationName !== null && trim($operationName) !== '')
    {
        $payload['operationName'] = trim($operationName);
    }


    $graphqlUrl = $configuration['rsc_url']
        . '/api/graphql';


    $headers = [
        'Authorization: Bearer ' . $accessToken
    ];


    $response = rkHttpPostJson(
        $graphqlUrl,
        $payload,
        $headers,
        $configuration
    );


    $responseBody = $response['body'];


    /*
     * GraphQL may return HTTP 200 while still reporting operation errors.
     */
    if (
        isset($responseBody['errors'])
        && is_array($responseBody['errors'])
        && count($responseBody['errors']) > 0
    )
    {
        throw new RuntimeException(
            rkFormatGraphQLErrors(
                $responseBody['errors']
            )
        );
    }


    if (
        !array_key_exists('data', $responseBody)
        || !is_array($responseBody['data'])
    )
    {
        throw new RuntimeException(
            'Rubrik Security Cloud returned no GraphQL data object.'
        );
    }


    return $responseBody;
}


// ----------------------------------------
// Function rkExecuteGraphQLFile : Load and execute a GraphQL operation stored in a file
// ----------------------------------------
function rkExecuteGraphQLFile(
    string $graphqlFile,
    array $variables = [],
    ?string $operationName = null,
    ?array $configuration = null
): array
{
    if (!is_file($graphqlFile))
    {
        throw new RuntimeException(
            "GraphQL file not found: {$graphqlFile}"
        );
    }


    $query = file_get_contents($graphqlFile);


    if ($query === false)
    {
        throw new RuntimeException(
            "Unable to read GraphQL file: {$graphqlFile}"
        );
    }


    return rkExecuteGraphQL(
        $query,
        $variables,
        $operationName,
        $configuration
    );
}


// ----------------------------------------
// Function rkHttpPostJson : Send a JSON HTTP POST request and decode the JSON response
// ----------------------------------------
function rkHttpPostJson(
    string $url,
    array $payload,
    array $additionalHeaders = [],
    ?array $configuration = null
): array
{
    if ($configuration === null)
    {
        $configuration = rkLoadConfiguration();
    }


    $encodedPayload = json_encode(
        $payload,
        JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
    );


    $headers = array_merge(
        [
            'Accept: application/json',
            'Content-Type: application/json'
        ],
        $additionalHeaders
    );


    $curl = curl_init($url);


    if ($curl === false)
    {
        throw new RuntimeException(
            "Unable to initialize the HTTP request for {$url}."
        );
    }


    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_CONNECTTIMEOUT => $configuration['connect_timeout'],
            CURLOPT_TIMEOUT => $configuration['request_timeout'],
            CURLOPT_SSL_VERIFYPEER => $configuration['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $configuration['verify_ssl']
                ? 2
                : 0,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => ''
        ]
    );


    $responseBody = curl_exec($curl);


    if ($responseBody === false)
    {
        $curlErrorNumber = curl_errno($curl);
        $curlErrorMessage = curl_error($curl);

        /*
         * curl_close() is intentionally not used.
         * Releasing the handle with unset() is compatible with PHP 8.5.
         */
        unset($curl);


        throw new RuntimeException(
            "HTTP request failed ({$curlErrorNumber}): "
            . $curlErrorMessage
        );
    }


    $httpStatus = curl_getinfo(
        $curl,
        CURLINFO_RESPONSE_CODE
    );


    unset($curl);


    $decodedBody = rkDecodeJsonResponse(
        $responseBody,
        $url
    );


    if ($httpStatus < 200 || $httpStatus >= 300)
    {
        $errorMessage = rkExtractApiErrorMessage(
            $decodedBody,
            $responseBody
        );


        throw new RuntimeException(
            "Rubrik API request returned HTTP {$httpStatus}: "
            . $errorMessage
        );
    }


    return [
        'statusCode' => $httpStatus,
        'body' => $decodedBody
    ];
}


// ----------------------------------------
// Function rkDecodeJsonResponse : Decode and validate a JSON API response
// ----------------------------------------
function rkDecodeJsonResponse(
    string $responseBody,
    string $url
): array
{
    try
    {
        $decodedBody = json_decode(
            $responseBody,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
    catch (JsonException $exception)
    {
        throw new RuntimeException(
            "Invalid JSON response received from {$url}: "
            . $exception->getMessage()
        );
    }


    if (!is_array($decodedBody))
    {
        throw new RuntimeException(
            "The API response received from {$url} is not a JSON object."
        );
    }


    return $decodedBody;
}


// ----------------------------------------
// Function rkExtractApiErrorMessage : Extract a useful error message from an API response
// ----------------------------------------
function rkExtractApiErrorMessage(
    array $decodedBody,
    string $rawBody
): string
{
    $candidateFields = [
        'message',
        'error_description',
        'error',
        'details'
    ];


    foreach ($candidateFields as $candidateField)
    {
        if (
            isset($decodedBody[$candidateField])
            && is_string($decodedBody[$candidateField])
            && trim($decodedBody[$candidateField]) !== ''
        )
        {
            return trim($decodedBody[$candidateField]);
        }
    }


    $rawBody = trim($rawBody);


    if ($rawBody !== '')
    {
        return $rawBody;
    }


    return 'No error details were returned.';
}


// ----------------------------------------
// Function rkFormatGraphQLErrors : Convert GraphQL error objects into a readable exception message
// ----------------------------------------
function rkFormatGraphQLErrors(
    array $errors
): string
{
    $messages = [];


    foreach ($errors as $error)
    {
        if (!is_array($error))
        {
            continue;
        }


        $message = $error['message']
            ?? 'Unknown GraphQL error';


        if (!is_string($message))
        {
            $message = 'Unknown GraphQL error';
        }


        $path = $error['path']
            ?? null;


        if (is_array($path) && count($path) > 0)
        {
            $pathParts = array_map(
                static fn (mixed $part): string => (string) $part,
                $path
            );


            $message .= ' [path: '
                . implode('.', $pathParts)
                . ']';
        }


        $messages[] = $message;
    }


    if (count($messages) === 0)
    {
        $messages[] = 'Unknown GraphQL error';
    }


    return 'Rubrik GraphQL operation failed: '
        . implode(' | ', $messages);
}


// ----------------------------------------
// Function rkGetNestedValue : Safely retrieve a value from a nested array
// ----------------------------------------
function rkGetNestedValue(
    array $data,
    array $path,
    mixed $default = null
): mixed
{
    $currentValue = $data;


    foreach ($path as $pathPart)
    {
        if (
            !is_array($currentValue)
            || !array_key_exists($pathPart, $currentValue)
        )
        {
            return $default;
        }


        $currentValue = $currentValue[$pathPart];
    }


    return $currentValue;
}
