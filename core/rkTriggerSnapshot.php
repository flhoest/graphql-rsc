<?php

declare(strict_types=1);

require_once __DIR__ . '/RscFramework.php';

// ----------------------------------------
// Function rkTriggerSnapshot : Trigger an on-demand snapshot for a VMware virtual machine
// ----------------------------------------
function rkTriggerSnapshot(
    string $workloadId,
    ?string $slaId = null
): array
{
    $workloadId = trim($workloadId);
    $slaId = $slaId !== null
        ? trim($slaId)
        : null;


    if ($workloadId === '')
    {
        throw new InvalidArgumentException(
            'The workload ID cannot be empty.'
        );
    }


    if ($slaId === '')
    {
        $slaId = null;
    }


    $mutationFile = __DIR__
        . '/../graphql/mutation_vsphereOnDemandSnapshot.graphql';


    if (!is_file($mutationFile))
    {
        throw new RuntimeException(
            "GraphQL mutation file not found: {$mutationFile}"
        );
    }


    $mutation = file_get_contents($mutationFile);


    if ($mutation === false)
    {
        throw new RuntimeException(
            "Unable to read GraphQL mutation file: {$mutationFile}"
        );
    }


    $input = [
        'id' => $workloadId
    ];


    if ($slaId !== null)
    {
        $input['config'] = [
            'slaId' => $slaId
        ];
    }


    $variables = [
        'input' => $input
    ];


    $response = rkExecuteGraphQL(
        $mutation,
        $variables
    );


    if (isset($response['errors']))
    {
        $errorMessages = [];


        foreach ($response['errors'] as $error)
        {
            $errorMessages[] = $error['message']
                ?? 'Unknown GraphQL error';
        }


        throw new RuntimeException(
            'Unable to trigger the snapshot: '
            . implode(' | ', $errorMessages)
        );
    }


    $request = $response['data']['vsphereOnDemandSnapshot']
        ?? null;


    if (!is_array($request))
    {
        throw new RuntimeException(
            'The snapshot mutation returned an unexpected response.'
        );
    }


    $requestId = $request['id'] ?? null;
    $status = $request['status'] ?? null;


    if (!is_string($requestId) || $requestId === '')
    {
        throw new RuntimeException(
            'The snapshot mutation did not return a request ID.'
        );
    }


    return [
        'success' => true,
        'requestId' => $requestId,
        'status' => is_string($status)
            ? $status
            : 'UNKNOWN',
        'workloadId' => $workloadId,
        'slaId' => $slaId
    ];
}
