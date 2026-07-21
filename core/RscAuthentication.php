<?php


declare(strict_types=1);


/*
 * Rubrik Security Cloud Authentication Module
 *
 * Responsibilities:
 * - Request OAuth2 access tokens from Rubrik Security Cloud
 * - Cache the token in memory for the lifetime of the PHP process
 * - Refresh expired or nearly expired tokens
 * - Clear the token cache when required
 *
 * Important implementation notes:
 * - client_id and client_secret are sent in the JSON POST body
 * - curl_close() is not used because it is deprecated in PHP 8.5
 * - The cURL handle is released with unset($curl)
 */


const RK_RSC_TOKEN_EXPIRY_MARGIN = 60;


/*
 * In-memory token cache.
 *
 * This cache exists only for the lifetime of the current PHP process.
 * A new CLI execution will therefore request a new token.
 */
$GLOBALS['rkRscTokenCache'] = [
    'accessToken' => null,
    'expiresAt' => 0
];


// ----------------------------------------
// Function rkRscGetToken : Obtain or reuse an OAuth2 access token from Rubrik Security Cloud
// ----------------------------------------
function rkRscGetToken(
    array $configuration,
    bool $forceRefresh = false
): string
{
    rkValidateAuthenticationConfiguration(
        $configuration
    );


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


    $tokenResponse = rkRscRequestToken(
        $configuration
    );


    $accessToken = $tokenResponse['access_token']
        ?? null;


    if (!is_string($accessToken) || trim($accessToken) === '')
    {
        throw new RuntimeException(
            'Rubrik Security Cloud did not return a valid access_token.'
        );
    }


    $expiresIn = $tokenResponse['expires_in']
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
        'accessToken' => trim($accessToken),
        'expiresAt' => time() + $expiresIn
    ];


    return trim($accessToken);
}


// ----------------------------------------
// Function rkRscRequestToken : Request a new OAuth2 token from Rubrik Security Cloud
// ----------------------------------------
function rkRscRequestToken(
    array $configuration
): array
{
    rkValidateAuthenticationConfiguration(
        $configuration
    );


    $rscUrl = rtrim(
        trim($configuration['rsc_url']),
        '/'
    );


    $tokenUrl = $rscUrl
        . '/api/client_token';


    /*
     * Rubrik Security Cloud expects the client credentials
     * in the JSON request body.
     */
    $payload = [
        'client_id' => trim(
            $configuration['client_id']
        ),
        'client_secret' => trim(
            $configuration['client_secret']
        )
    ];


    try
    {
        $encodedPayload = json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_SLASHES
        );
    }
    catch (JsonException $exception)
    {
        throw new RuntimeException(
            'Unable to encode the authentication request: '
            . $exception->getMessage()
        );
    }


    $connectTimeout = rkGetAuthenticationTimeout(
        $configuration,
        'connect_timeout',
        30
    );


    $requestTimeout = rkGetAuthenticationTimeout(
        $configuration,
        'request_timeout',
        120
    );


    $verifySsl = rkGetAuthenticationBoolean(
        $configuration,
        'verify_ssl',
        true
    );


    $curl = curl_init(
        $tokenUrl
    );


    if ($curl === false)
    {
        throw new RuntimeException(
            'Unable to initialize the Rubrik authentication request.'
        );
    }


    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl
                ? 2
                : 0,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => ''
        ]
    );


    $responseBody = curl_exec(
        $curl
    );


    if ($responseBody === false)
    {
        $curlErrorNumber = curl_errno(
            $curl
        );

        $curlErrorMessage = curl_error(
            $curl
        );


        /*
         * curl_close() is intentionally not used.
         * Releasing the handle with unset() is compatible with PHP 8.5.
         */
        unset($curl);


        throw new RuntimeException(
            "Rubrik authentication request failed "
            . "({$curlErrorNumber}): {$curlErrorMessage}"
        );
    }


    $httpStatus = curl_getinfo(
        $curl,
        CURLINFO_RESPONSE_CODE
    );


    unset($curl);


    try
    {
        $decodedResponse = json_decode(
            $responseBody,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
    catch (JsonException $exception)
    {
        throw new RuntimeException(
            'Rubrik Security Cloud returned an invalid JSON authentication response: '
            . $exception->getMessage()
        );
    }


    if (!is_array($decodedResponse))
    {
        throw new RuntimeException(
            'Rubrik Security Cloud returned an invalid authentication response.'
        );
    }


    if ($httpStatus < 200 || $httpStatus >= 300)
    {
        $errorMessage = rkExtractAuthenticationError(
            $decodedResponse,
            $responseBody
        );


        throw new RuntimeException(
            "Rubrik authentication returned HTTP {$httpStatus}: "
            . $errorMessage
        );
    }


    return $decodedResponse;
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
// Function rkRscGetTokenCacheInformation : Return non-sensitive token cache metadata
// ----------------------------------------
function rkRscGetTokenCacheInformation(): array
{
    $expiresAt = $GLOBALS['rkRscTokenCache']['expiresAt']
        ?? 0;

    $accessToken = $GLOBALS['rkRscTokenCache']['accessToken']
        ?? null;


    return [
        'hasToken' => is_string($accessToken)
            && $accessToken !== '',
        'expiresAt' => is_int($expiresAt)
            ? $expiresAt
            : 0,
        'expiresIn' => is_int($expiresAt)
            ? max(0, $expiresAt - time())
            : 0
    ];
}


// ----------------------------------------
// Function rkValidateAuthenticationConfiguration : Validate authentication configuration values
// ----------------------------------------
function rkValidateAuthenticationConfiguration(
    array $configuration
): void
{
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
            throw new InvalidArgumentException(
                "Missing or invalid authentication configuration value: "
                . $requiredKey
            );
        }
    }


    if (
        filter_var(
            trim($configuration['rsc_url']),
            FILTER_VALIDATE_URL
        ) === false
    )
    {
        throw new InvalidArgumentException(
            'The Rubrik Security Cloud URL is not valid.'
        );
    }
}


// ----------------------------------------
// Function rkGetAuthenticationTimeout : Read and validate an authentication timeout value
// ----------------------------------------
function rkGetAuthenticationTimeout(
    array $configuration,
    string $key,
    int $defaultValue
): int
{
    $value = $configuration[$key]
        ?? $defaultValue;


    if (is_string($value) && ctype_digit($value))
    {
        $value = (int) $value;
    }


    if (!is_int($value) || $value <= 0)
    {
        throw new InvalidArgumentException(
            "Configuration value {$key} must be a positive integer."
        );
    }


    return $value;
}


// ----------------------------------------
// Function rkGetAuthenticationBoolean : Read and validate an authentication boolean value
// ----------------------------------------
function rkGetAuthenticationBoolean(
    array $configuration,
    string $key,
    bool $defaultValue
): bool
{
    $value = $configuration[$key]
        ?? $defaultValue;


    if (is_bool($value))
    {
        return $value;
    }


    if (is_string($value))
    {
        $normalizedValue = strtolower(
            trim($value)
        );


        if (
            in_array(
                $normalizedValue,
                [
                    '1',
                    'true',
                    'yes',
                    'on'
                ],
                true
            )
        )
        {
            return true;
        }


        if (
            in_array(
                $normalizedValue,
                [
                    '0',
                    'false',
                    'no',
                    'off'
                ],
                true
            )
        )
        {
            return false;
        }
    }


    throw new InvalidArgumentException(
        "Configuration value {$key} must be a boolean."
    );
}


// ----------------------------------------
// Function rkExtractAuthenticationError : Extract a readable error from an authentication response
// ----------------------------------------
function rkExtractAuthenticationError(
    array $decodedResponse,
    string $rawResponse
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
            isset($decodedResponse[$candidateField])
            && is_string($decodedResponse[$candidateField])
            && trim($decodedResponse[$candidateField]) !== ''
        )
        {
            return trim(
                $decodedResponse[$candidateField]
            );
        }
    }


    $rawResponse = trim(
        $rawResponse
    );


    if ($rawResponse !== '')
    {
        return $rawResponse;
    }


    return 'No authentication error details were returned.';
}
