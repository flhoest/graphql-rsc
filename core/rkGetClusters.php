<?php

declare(strict_types=1);

require_once __DIR__ . '/RscFramework.php';


// ----------------------------------------
// Function rkGetClusters : Retrieve Rubrik clusters for request monitoring
// ----------------------------------------
function rkGetClusters(
    int $maximum = 100
): array
{
    if ($maximum < 1 || $maximum > 1000)
    {
        throw new InvalidArgumentException(
            'Cluster maximum must be between 1 and 1000.'
        );
    }

    $response = rkExecuteGraphQLFile(
        __DIR__ . '/../graphql/GetClusters.graphql',
        [
            'first' => $maximum
        ],
        'GetClusters'
    );

    $nodes = $response['data']['clusterConnection']['nodes'] ?? null;

    if (!is_array($nodes))
    {
        throw new RuntimeException(
            'Rubrik Security Cloud did not return cluster inventory.'
        );
    }

    $clusters = [];

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

        $clusters[] = [
            'id' => trim($id),
            'name' => is_string($node['name'] ?? null)
                ? $node['name']
                : '(unnamed cluster)'
        ];
    }

    usort(
        $clusters,
        static fn (array $left, array $right): int => strcasecmp(
            $left['name'],
            $right['name']
        )
    );

    return $clusters;
}
