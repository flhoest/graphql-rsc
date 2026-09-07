<?php

declare(strict_types=1);

require_once __DIR__ . '/RscFramework.php';


// ----------------------------------------
// Function rkGetRequestStatus : Retrieve and normalize the status of a VMware asynchronous RSC request
// ----------------------------------------
function rkGetRequestStatus(
    string $requestId,
    string $clusterUuid
): array
{
    if (trim($requestId) === '')
    {
        throw new InvalidArgumentException(
            'Request ID cannot be empty.'
        );
    }

    if (trim($clusterUuid) === '')
    {
        throw new InvalidArgumentException(
            'Cluster UUID cannot be empty.'
        );
    }

    $graphqlFile = __DIR__
        . '/../graphql/query_vsphereVMAsyncRequestStatus.graphql';

    $variables = [
        'id' => $requestId,
        'clusterUuid' => $clusterUuid
    ];

    $response = rkExecuteGraphQLFile(
        $graphqlFile,
        $variables,
        'GetVsphereVMAsyncRequestStatus'
    );

    $request = $response['data']['vSphereVMAsyncRequestStatus'] ?? null;

    if (!is_array($request))
    {
        throw new RuntimeException(
            'Rubrik Security Cloud did not return asynchronous request status information.'
        );
    }

    $errorMessage = null;

    if (
        isset($request['error'])
        && is_array($request['error'])
        && !empty($request['error']['message'])
    )
    {
        $errorMessage = (string) $request['error']['message'];
    }

    return [
        'requestId' => (string) ($request['id'] ?? $requestId),
        'status' => strtoupper((string) ($request['status'] ?? 'UNKNOWN')),
        'progress' => isset($request['progress'])
            ? (int) $request['progress']
            : null,
        'startTime' => $request['startTime'] ?? null,
        'endTime' => $request['endTime'] ?? null,
        'message' => $errorMessage === null
            ? 'Operation status retrieved successfully.'
            : $errorMessage,
        'error' => $errorMessage
    ];
}


// ----------------------------------------
// Function rkWaitForRequest : Monitor a VMware asynchronous RSC request until completion or timeout
// ----------------------------------------
function rkWaitForRequest(
    string $requestId,
    string $clusterUuid,
    int $pollInterval = 5,
    int $timeout = 300
): array
{
    if ($pollInterval < 1)
    {
        throw new InvalidArgumentException(
            'Polling interval must be at least one second.'
        );
    }

    if ($timeout < 1)
    {
        throw new InvalidArgumentException(
            'Timeout must be at least one second.'
        );
    }

    $startTime = time();

    while (true)
    {
        $status = rkGetRequestStatus(
            $requestId,
            $clusterUuid
        );

        $progress = $status['progress'] !== null
            ? sprintf(' (%d%%)', $status['progress'])
            : '';

        echo sprintf(
            "[%s] Request %s : %s%s%s",
            date('H:i:s'),
            $requestId,
            $status['status'],
            $progress,
            PHP_EOL
        );

        if ($status['status'] === 'SUCCEEDED')
        {
            return $status;
        }

        if (
            $status['status'] === 'FAILED'
            || $status['status'] === 'CANCELED'
        )
        {
            return $status;
        }

        if ((time() - $startTime) >= $timeout)
        {
            return [
                'requestId' => $requestId,
                'status' => 'TIMEOUT',
                'progress' => $status['progress'] ?? null,
                'startTime' => $status['startTime'] ?? null,
                'endTime' => $status['endTime'] ?? null,
                'message' => 'Maximum monitoring time exceeded.',
                'error' => null
            ];
        }

        sleep($pollInterval);
    }
}
