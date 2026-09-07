<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/rkRequestStatus.php';


// ----------------------------------------
// Function rkDisplayUsage : Display command-line usage information
// ----------------------------------------
function rkDisplayUsage(): void
{
    echo <<<TEXT
Rubrik Asynchronous Request Monitor

Usage:
  php MonitorRequest.php \
    --request-id="<REQUEST_ID>" \
    --cluster-uuid="<CLUSTER_UUID>" \
    [--poll-interval=<SECONDS>] \
    [--timeout=<SECONDS>]

Example:
  php MonitorRequest.php \
    --request-id="CREATE_VSPHERE_SNAPSHOT_<VM_FID>_<RUN_ID>:::0" \
    --cluster-uuid="YOUR_RUBRIK_CLUSTER_UUID" \
    --poll-interval=5 \
    --timeout=300

TEXT;
}


$options = getopt(
    '',
    [
        'request-id:',
        'cluster-uuid:',
        'poll-interval::',
        'timeout::',
        'help'
    ]
);

if (isset($options['help']))
{
    rkDisplayUsage();
    exit(0);
}

$requestId = $options['request-id'] ?? null;
$clusterUuid = $options['cluster-uuid'] ?? null;
$pollInterval = isset($options['poll-interval'])
    ? (int) $options['poll-interval']
    : 5;
$timeout = isset($options['timeout'])
    ? (int) $options['timeout']
    : 300;

if (
    !is_string($requestId)
    || trim($requestId) === ''
    || !is_string($clusterUuid)
    || trim($clusterUuid) === ''
)
{
    fwrite(
        STDERR,
        "Missing --request-id or --cluster-uuid.\n\n"
    );

    rkDisplayUsage();
    exit(1);
}

try
{
    echo "Rubrik Asynchronous Request Monitor\n";
    echo "-----------------------------------\n";
    echo "Request ID   : {$requestId}\n";
    echo "Cluster UUID : {$clusterUuid}\n";
    echo "Poll interval: {$pollInterval} seconds\n";
    echo "Timeout      : {$timeout} seconds\n\n";

    $result = rkWaitForRequest(
        $requestId,
        $clusterUuid,
        $pollInterval,
        $timeout
    );

    echo PHP_EOL;
    echo "Final status : {$result['status']}\n";

    if ($result['progress'] !== null)
    {
        echo "Progress     : {$result['progress']}%\n";
    }

    if (!empty($result['startTime']))
    {
        echo "Start time   : {$result['startTime']}\n";
    }

    if (!empty($result['endTime']))
    {
        echo "End time     : {$result['endTime']}\n";
    }

    if (!empty($result['error']))
    {
        echo "Error        : {$result['error']}\n";
    }

    if ($result['status'] === 'SUCCEEDED')
    {
        exit(0);
    }

    if ($result['status'] === 'TIMEOUT')
    {
        exit(2);
    }

    exit(1);
}
catch (Throwable $exception)
{
    fwrite(
        STDERR,
        'Error: ' . $exception->getMessage() . PHP_EOL
    );

    exit(1);
}
