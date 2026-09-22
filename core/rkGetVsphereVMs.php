<?php

declare(strict_types=1);

require_once __DIR__ . '/RscFramework.php';


// ----------------------------------------
// Function rkGetVsphereVMs : Retrieve VMware virtual machines for the Restore Portal
// ----------------------------------------
function rkGetVsphereVMs(
    int $maximum = 100
): array
{
    if ($maximum < 1 || $maximum > 1000)
    {
        throw new InvalidArgumentException(
            'VM maximum must be between 1 and 1000.'
        );
    }

    $response = rkExecuteGraphQLFile(
        __DIR__ . '/../graphql/GetVsphereVMs.graphql',
        [
            'first' => $maximum
        ],
        'GetVsphereVMs'
    );

    $nodes = $response['data']['snappableConnection']['nodes'] ?? null;

    if (!is_array($nodes))
    {
        throw new RuntimeException(
            'Rubrik Security Cloud did not return VMware VM inventory.'
        );
    }

    $vms = [];

    foreach ($nodes as $node)
    {
        if (!is_array($node))
        {
            continue;
        }

        $fid = $node['fid'] ?? null;

        if (!is_string($fid) || trim($fid) === '')
        {
            continue;
        }

        $vms[] = [
            'name' => is_string($node['name'] ?? null)
                ? $node['name']
                : '(unnamed VM)',
            'fid' => trim($fid)
        ];
    }

    usort(
        $vms,
        static fn (array $left, array $right): int => strcasecmp(
            $left['name'],
            $right['name']
        )
    );

    return $vms;
}
