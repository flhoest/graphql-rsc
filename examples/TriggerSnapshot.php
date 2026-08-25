<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/RscFramework.php';
require_once __DIR__ . '/../core/rkTriggerSnapshot.php';


// ----------------------------------------
// Function rkDisplayTriggerSnapshotUsage : Display command-line usage information
// ----------------------------------------
function rkDisplayTriggerSnapshotUsage(): void
{
    echo <<<TEXT
Rubrik On-Demand Snapshot
-------------------------

Usage:
  php TriggerSnapshot.php \
    --workload-id="<VM_FID>" \
    --workload-name="<VM_NAME>" \
    [--sla-id="<SLA_FID>"] \
    [--confirm]

TEXT;
}


// ----------------------------------------
// Function rkReadConfirmation : Ask the operator to confirm the snapshot request
// ----------------------------------------
function rkReadConfirmation(
    string $workloadName,
    string $workloadId
): bool
{
    echo "You are about to trigger an on-demand snapshot.\n";
    echo "Workload    : {$workloadName}\n";
    echo "Workload ID : {$workloadId}\n\n";
    echo 'Enter YES to continue: ';

    $answer = fgets(STDIN);

    if ($answer === false)
    {
        return false;
    }

    return strtoupper(trim($answer)) === 'YES';
}


$options = getopt(
    '',
    [
        'workload-id:',
        'workload-name:',
        'sla-id::',
        'confirm',
        'help'
    ]
);

if (isset($options['help']))
{
    rkDisplayTriggerSnapshotUsage();
    exit(0);
}

$workloadId = $options['workload-id'] ?? null;
$workloadName = $options['workload-name'] ?? null;
$slaId = $options['sla-id'] ?? null;
$confirmedByParameter = isset($options['confirm']);

if (!is_string($workloadId) || trim($workloadId) === '')
{
    fwrite(STDERR, "Missing or invalid --workload-id.\n\n");
    rkDisplayTriggerSnapshotUsage();
    exit(1);
}

if (!is_string($workloadName) || trim($workloadName) === '')
{
    fwrite(STDERR, "Missing or invalid --workload-name.\n\n");
    rkDisplayTriggerSnapshotUsage();
    exit(1);
}

$workloadId = trim($workloadId);
$workloadName = trim($workloadName);

if ($slaId !== null)
{
    if (!is_string($slaId))
    {
        fwrite(STDERR, "--sla-id must contain a valid SLA FID.\n");
        exit(1);
    }

    $slaId = trim($slaId);

    if ($slaId === '')
    {
        $slaId = null;
    }
}

if (!$confirmedByParameter)
{
    if (!rkReadConfirmation($workloadName, $workloadId))
    {
        echo "\nSnapshot request cancelled.\n";
        exit(0);
    }
}

try
{
    echo "\nRubrik On-Demand Snapshot\n";
    echo "-------------------------\n";
    echo "Workload    : {$workloadName}\n";
    echo "Workload ID : {$workloadId}\n";
    echo 'SLA ID      : ' . ($slaId ?? 'Use effective SLA') . "\n\n";

    echo "Submitting snapshot request...\n";

    $result = rkTriggerSnapshot(
        $workloadId,
        $slaId
    );

    echo "Snapshot request accepted.\n\n";
    echo "Request ID : {$result['requestId']}\n";
    echo "Status     : {$result['status']}\n\n";
    echo "The snapshot operation is running asynchronously.\n";
}
catch (Throwable $exception)
{
    fwrite(
        STDERR,
        "\nSnapshot request failed: "
        . $exception->getMessage()
        . "\n"
    );

    exit(1);
}
