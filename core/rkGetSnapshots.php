<?php

declare(strict_types=1);

require_once __DIR__ . '/RscFramework.php';


// ----------------------------------------
// Function rkGetSnapshots : Retrieve recovery points for a selected workload
// ----------------------------------------
function rkGetSnapshots(
    string $workloadId,
    int $maximum = 100
): array
{
    $workloadId = trim($workloadId);

    if ($workloadId === '')
    {
        throw new InvalidArgumentException(
            'Workload FID is required.'
        );
    }

    if ($maximum < 1 || $maximum > 1000)
    {
        throw new InvalidArgumentException(
            'Snapshot maximum must be between 1 and 1000.'
        );
    }

    $response = rkExecuteGraphQLFile(
        __DIR__ . '/../graphql/GetSnapshots.graphql',
        [
            'workloadId' => $workloadId,
            'first' => $maximum
        ],
        'GetSnapshots'
    );

    $nodes = $response['data']['snapshotOfASnappableConnection']['nodes'] ?? null;

    if (!is_array($nodes))
    {
        throw new RuntimeException(
            'Rubrik Security Cloud did not return snapshot history.'
        );
    }

    $snapshots = [];

    foreach ($nodes as $node)
    {
        if (!is_array($node))
        {
            continue;
        }

        $id = $node['id'] ?? null;

        if (!is_string($id) || trim($id) === '')
        {
            continue;
        }

        $snapshots[] = [
            'id' => trim($id),
            'date' => is_string($node['date'] ?? null)
                ? $node['date']
                : '',
            'expirationDate' => is_string($node['expirationDate'] ?? null)
                ? $node['expirationDate']
                : ''
        ];
    }

    return $snapshots;
}
