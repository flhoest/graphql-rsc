<?php

declare(strict_types=1);

require_once __DIR__ . '/RscFramework.php';


/*
 * This helper defaults to dry-run mode. Set restore_dry_run to false in the
 * local configuration to submit the recovery mutation.
 */


// ----------------------------------------
// Function rkRestoreVm : Preview or submit a VMware in-place recovery
// ----------------------------------------
function rkRestoreVm(
    string $vmId,
    string $snapshotId,
    string $clusterUuid
): array
{
    $vmId = trim($vmId);
    $snapshotId = trim($snapshotId);
    $clusterUuid = trim($clusterUuid);

    if ($vmId === '')
    {
        throw new InvalidArgumentException(
            'VM FID is required.'
        );
    }

    if ($snapshotId === '')
    {
        throw new InvalidArgumentException(
            'Snapshot FID is required.'
        );
    }

    if ($clusterUuid === '')
    {
        throw new InvalidArgumentException(
            'Cluster UUID is required for later request monitoring.'
        );
    }

    $graphqlFile = __DIR__
        . '/../graphql/mutation_vsphereVmInitiateInPlaceRecovery.graphql';

    if (!is_file($graphqlFile))
    {
        throw new RuntimeException(
            "GraphQL mutation file not found: {$graphqlFile}"
        );
    }

    $mutation = file_get_contents($graphqlFile);

    if ($mutation === false)
    {
        throw new RuntimeException(
            "Unable to read GraphQL mutation file: {$graphqlFile}"
        );
    }

    $variables = [
        'input' => [
            'id' => $vmId,
            'config' => [
                'requiredRecoveryParameters' => [
                    'snapshotId' => $snapshotId
                ]
            ]
        ]
    ];

    $configuration = rkLoadConfiguration();
    $dryRun = $configuration['restore_dry_run'];

    if ($dryRun)
    {
        return [
            'success' => true,
            'dryRun' => true,
            'requestSubmitted' => false,
            'requestId' => null,
            'status' => 'DRY_RUN',
            'vmId' => $vmId,
            'snapshotId' => $snapshotId,
            'clusterUuid' => $clusterUuid,
            'operationName' => 'RestoreVsphereVm',
            'graphqlMutation' => $mutation,
            'variables' => $variables,
            'message' => 'Dry run completed. No Rubrik Security Cloud mutation was sent.'
        ];
    }

    $response = rkExecuteGraphQLFile(
        $graphqlFile,
        $variables,
        'RestoreVsphereVm',
        $configuration
    );

    $request = $response['data']['vsphereVmInitiateInPlaceRecovery']
        ?? null;

    if (!is_array($request))
    {
        throw new RuntimeException(
            'The restore mutation returned an unexpected response.'
        );
    }

    $requestId = $request['id'] ?? null;

    if (!is_string($requestId) || trim($requestId) === '')
    {
        throw new RuntimeException(
            'The restore mutation did not return a request ID.'
        );
    }

    return [
        'success' => true,
        'dryRun' => false,
        'requestSubmitted' => true,
        'requestId' => trim($requestId),
        'status' => strtoupper((string) ($request['status'] ?? 'UNKNOWN')),
        'progress' => isset($request['progress'])
            ? (int) $request['progress']
            : null,
        'error' => isset($request['error']['message'])
            ? (string) $request['error']['message']
            : null,
        'vmId' => $vmId,
        'snapshotId' => $snapshotId,
        'clusterUuid' => $clusterUuid,
        'operationName' => 'RestoreVsphereVm',
        'graphqlMutation' => $mutation,
        'variables' => $variables,
        'message' => 'Rubrik Security Cloud accepted the restore request.'
    ];
}
