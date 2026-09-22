<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/rkGetVsphereVMs.php';
require_once __DIR__ . '/../core/rkGetSnapshots.php';
require_once __DIR__ . '/../core/rkGetClusters.php';
require_once __DIR__ . '/../core/rkRestoreVm.php';
require_once __DIR__ . '/../core/rkRequestStatus.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
]);

session_start();

if (!isset($_SESSION['restorePortalCsrfToken']))
{
    $_SESSION['restorePortalCsrfToken'] = bin2hex(random_bytes(32));
}


// ----------------------------------------
// Function rkPortalEscape : Encode output for HTML
// ----------------------------------------
function rkPortalEscape(
    string $value
): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


// ----------------------------------------
// Function rkPortalPostValue : Read a string form value
// ----------------------------------------
function rkPortalPostValue(
    string $name
): string
{
    return isset($_POST[$name]) && is_string($_POST[$name])
        ? trim($_POST[$name])
        : '';
}


// ----------------------------------------
// Function rkPortalJson : Format a value for preview in HTML
// ----------------------------------------
function rkPortalJson(
    array $value
): string
{
    return json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
}


// ----------------------------------------
// Function rkPortalLoadVms : Load and return current VMware VMs
// ----------------------------------------
function rkPortalLoadVms(): array
{
    return rkGetVsphereVMs(250);
}


// ----------------------------------------
// Function rkPortalLoadSnapshots : Load recovery points for a selected VM
// ----------------------------------------
function rkPortalLoadSnapshots(
    array $vm
): array
{
    return rkGetSnapshots(
        $vm['fid'],
        100
    );
}


// ----------------------------------------
// Function rkPortalLoadClusters : Load current Rubrik clusters
// ----------------------------------------
function rkPortalLoadClusters(): array
{
    return rkGetClusters(100);
}


$step = $_SESSION['restorePortalStep'] ?? 'vm';
$selectedVm = $_SESSION['selectedVm'] ?? null;
$selectedSnapshot = $_SESSION['selectedSnapshot'] ?? null;
$selectedCluster = $_SESSION['selectedCluster'] ?? null;
$lastRestore = $_SESSION['lastRestore'] ?? null;
$error = null;
$notice = null;
$vms = [];
$snapshots = [];
$clusters = [];
$restoreDryRun = true;

try
{
    $restoreDryRun = rkLoadConfiguration()['restore_dry_run'];
}
catch (Throwable $exception)
{
    error_log($exception->getMessage());
    $error = 'Unable to load the local RSC configuration.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $csrfToken = rkPortalPostValue('csrfToken');
    $action = rkPortalPostValue('action');

    if (!hash_equals($_SESSION['restorePortalCsrfToken'], $csrfToken))
    {
        $error = 'Your form session has expired. Refresh the page and try again.';
    }
    elseif ($action === 'startAgain')
    {
        unset(
            $_SESSION['selectedVm'],
            $_SESSION['selectedSnapshot'],
            $_SESSION['selectedCluster'],
            $_SESSION['lastRestore']
        );
        $step = 'vm';
        $_SESSION['restorePortalStep'] = $step;
        $selectedVm = null;
        $selectedSnapshot = null;
        $selectedCluster = null;
        $lastRestore = null;
    }
    elseif ($action === 'selectVm')
    {
        try
        {
            $vms = rkPortalLoadVms();
            $vmFid = rkPortalPostValue('vmFid');
            $selectedVm = null;

            foreach ($vms as $vm)
            {
                if ($vm['fid'] === $vmFid)
                {
                    $selectedVm = $vm;
                    break;
                }
            }

            if ($selectedVm === null)
            {
                $error = 'Select a VM from the current list.';
            }
            else
            {
                $_SESSION['selectedVm'] = $selectedVm;
                unset(
                    $_SESSION['selectedSnapshot'],
                    $_SESSION['selectedCluster'],
                    $_SESSION['lastRestore']
                );
                $selectedSnapshot = null;
                $selectedCluster = null;
                $lastRestore = null;
                $step = 'snapshot';
                $_SESSION['restorePortalStep'] = $step;
            }
        }
        catch (Throwable $exception)
        {
            error_log($exception->getMessage());
            $error = 'Unable to load VMware VMs. Check the local RSC configuration.';
        }
    }
    elseif ($action === 'selectSnapshot')
    {
        try
        {
            if (!is_array($selectedVm))
            {
                throw new RuntimeException('No VM is selected.');
            }

            $snapshots = rkPortalLoadSnapshots($selectedVm);
            $snapshotId = rkPortalPostValue('snapshotId');
            $selectedSnapshot = null;

            foreach ($snapshots as $snapshot)
            {
                if ($snapshot['id'] === $snapshotId)
                {
                    $selectedSnapshot = $snapshot;
                    break;
                }
            }

            if ($selectedSnapshot === null)
            {
                $error = 'Select a recovery point from the current list.';
            }
            else
            {
                $_SESSION['selectedSnapshot'] = $selectedSnapshot;
                unset($_SESSION['selectedCluster'], $_SESSION['lastRestore']);
                $selectedCluster = null;
                $lastRestore = null;
                $step = 'cluster';
                $_SESSION['restorePortalStep'] = $step;
            }
        }
        catch (Throwable $exception)
        {
            error_log($exception->getMessage());
            $error = 'Unable to load recovery points for the selected VM.';
        }
    }
    elseif ($action === 'selectCluster')
    {
        try
        {
            $clusters = rkPortalLoadClusters();
            $clusterUuid = rkPortalPostValue('clusterUuid');
            $selectedCluster = null;

            foreach ($clusters as $cluster)
            {
                if ($cluster['id'] === $clusterUuid)
                {
                    $selectedCluster = $cluster;
                    break;
                }
            }

            if ($selectedCluster === null)
            {
                $error = 'Select a Rubrik cluster from the current list.';
            }
            else
            {
                $_SESSION['selectedCluster'] = $selectedCluster;
                $step = 'confirm';
                $_SESSION['restorePortalStep'] = $step;
            }
        }
        catch (Throwable $exception)
        {
            error_log($exception->getMessage());
            $error = 'Unable to load Rubrik clusters. Check the local RSC configuration.';
        }
    }
    elseif ($action === 'prepareRestore')
    {
        $confirmed = rkPortalPostValue('confirmRecovery') === 'yes';

        if (!is_array($selectedVm) || !is_array($selectedSnapshot) || !is_array($selectedCluster))
        {
            $error = 'The restore selection is incomplete. Start again and select each item.';
        }
        elseif (!$confirmed)
        {
            $error = 'Confirmation is required before the restore request can be prepared.';
        }
        else
        {
            try
            {
                $snapshots = rkPortalLoadSnapshots($selectedVm);
                $snapshotIsAvailable = false;

                foreach ($snapshots as $snapshot)
                {
                    if ($snapshot['id'] === $selectedSnapshot['id'])
                    {
                        $snapshotIsAvailable = true;
                        break;
                    }
                }

                if (!$snapshotIsAvailable)
                {
                    $error = 'The selected recovery point is no longer available.';
                }
                else
                {
                    $lastRestore = rkRestoreVm(
                        $selectedVm['fid'],
                        $selectedSnapshot['id'],
                        $selectedCluster['id']
                    );
                    $_SESSION['lastRestore'] = $lastRestore;
                    $step = 'monitor';
                    $_SESSION['restorePortalStep'] = $step;
                }
            }
            catch (Throwable $exception)
            {
                error_log($exception->getMessage());
                $error = 'Unable to validate the selected recovery point.';
            }
        }
    }
    elseif ($action === 'reloadProgress')
    {
        if (!is_array($lastRestore) || $lastRestore['requestSubmitted'] !== true)
        {
            $notice = 'No progress is available because dry-run mode never creates an asynchronous RSC request.';
        }
        else
        {
            try
            {
                $status = rkGetRequestStatus(
                    $lastRestore['requestId'],
                    $lastRestore['clusterUuid']
                );
                $lastRestore['status'] = $status['status'];
                $lastRestore['progress'] = $status['progress'];
                $lastRestore['message'] = $status['message'];
                $lastRestore['error'] = $status['error'];
                $_SESSION['lastRestore'] = $lastRestore;
                $notice = 'Recovery status refreshed.';
            }
            catch (Throwable $exception)
            {
                error_log($exception->getMessage());
                $error = 'Unable to refresh the recovery status.';
            }
        }
    }
}

if ($error === null && $step === 'vm' && $vms === [])
{
    try
    {
        $vms = rkPortalLoadVms();
    }
    catch (Throwable $exception)
    {
        error_log($exception->getMessage());
        $error = 'Unable to load VMware VMs. Check the local RSC configuration.';
    }
}

if ($error === null && $step === 'snapshot' && is_array($selectedVm) && $snapshots === [])
{
    try
    {
        $snapshots = rkPortalLoadSnapshots($selectedVm);
    }
    catch (Throwable $exception)
    {
        error_log($exception->getMessage());
        $error = 'Unable to load recovery points for the selected VM.';
    }
}

if ($error === null && $step === 'cluster' && $clusters === [])
{
    try
    {
        $clusters = rkPortalLoadClusters();
    }
    catch (Throwable $exception)
    {
        error_log($exception->getMessage());
        $error = 'Unable to load Rubrik clusters. Check the local RSC configuration.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>VMware Restore Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body { background: #f8f9fa; }
        code { word-break: break-all; }
        .step-current { font-weight: 600; }
    </style>
</head>
<body>
    <main class="container py-4 py-md-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">VMware Restore Portal</h1>
            <form method="post"><input type="hidden" name="csrfToken" value="<?= rkPortalEscape($_SESSION['restorePortalCsrfToken']) ?>"><input type="hidden" name="action" value="startAgain"><button class="btn btn-outline-secondary btn-sm" type="submit">Start again</button></form>
        </div>

        <?php if ($restoreDryRun): ?>
            <div class="alert alert-warning" role="alert"><strong>Dry-run mode.</strong> Discovery calls are read-only. The restore step does not send a GraphQL mutation or initiate recovery.</div>
        <?php else: ?>
            <div class="alert alert-danger" role="alert"><strong>Live restore mode.</strong> Confirming the final step submits an in-place recovery mutation that can overwrite the selected VM's disks.</div>
        <?php endif; ?>
        <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= rkPortalEscape($error) ?></div><?php endif; ?>
        <?php if ($notice !== null): ?><div class="alert alert-info" role="status"><?= rkPortalEscape($notice) ?></div><?php endif; ?>

        <ol class="list-group list-group-horizontal-md mb-4">
            <?php foreach (['vm' => '1. VM', 'snapshot' => '2. Snapshot', 'cluster' => '3. Cluster', 'confirm' => '4. Confirm', 'monitor' => '5. Monitor'] as $stepName => $label): ?>
                <li class="list-group-item flex-fill <?= $step === $stepName ? 'step-current active' : '' ?>"><?= $label ?></li>
            <?php endforeach; ?>
        </ol>

        <div class="card shadow-sm">
            <div class="card-body">
                <?php if ($step === 'vm'): ?>
                    <h2 class="h4">Select a virtual machine</h2>
                    <p class="text-secondary">Choose the VM whose recovery points you want to inspect.</p>
                    <form method="post">
                        <input type="hidden" name="csrfToken" value="<?= rkPortalEscape($_SESSION['restorePortalCsrfToken']) ?>"><input type="hidden" name="action" value="selectVm">
                        <label class="form-label" for="vmFid">Virtual machine</label>
                        <select class="form-select" id="vmFid" name="vmFid" required size="12">
                            <?php foreach ($vms as $vm): ?><option value="<?= rkPortalEscape($vm['fid']) ?>"><?= rkPortalEscape($vm['name']) ?> — <?= rkPortalEscape($vm['fid']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary mt-3" type="submit" <?= count($vms) === 0 ? 'disabled' : '' ?>>Next: Select snapshot</button>
                    </form>
                <?php elseif ($step === 'snapshot' && is_array($selectedVm)): ?>
                    <h2 class="h4">Select a recovery point</h2>
                    <p class="text-secondary mb-3">VM: <strong><?= rkPortalEscape($selectedVm['name']) ?></strong></p>
                    <form method="post">
                        <input type="hidden" name="csrfToken" value="<?= rkPortalEscape($_SESSION['restorePortalCsrfToken']) ?>"><input type="hidden" name="action" value="selectSnapshot">
                        <label class="form-label" for="snapshotId">Available snapshots</label>
                        <select class="form-select" id="snapshotId" name="snapshotId" required size="10">
                            <?php foreach ($snapshots as $snapshot): ?><option value="<?= rkPortalEscape($snapshot['id']) ?>"><?= rkPortalEscape($snapshot['date']) ?> — expires <?= rkPortalEscape($snapshot['expirationDate']) ?></option><?php endforeach; ?>
                        </select>
                        <?php if (count($snapshots) === 0): ?><p class="form-text">No recovery points were returned for this VM.</p><?php endif; ?>
                        <button class="btn btn-primary mt-3" type="submit" <?= count($snapshots) === 0 ? 'disabled' : '' ?>>Next: Select cluster</button>
                    </form>
                <?php elseif ($step === 'cluster'): ?>
                    <h2 class="h4">Select a Rubrik cluster</h2>
                    <p class="text-secondary">This cluster UUID is retained for future request-status monitoring.</p>
                    <form method="post">
                        <input type="hidden" name="csrfToken" value="<?= rkPortalEscape($_SESSION['restorePortalCsrfToken']) ?>"><input type="hidden" name="action" value="selectCluster">
                        <label class="form-label" for="clusterUuid">Rubrik cluster</label>
                        <select class="form-select" id="clusterUuid" name="clusterUuid" required>
                            <?php foreach ($clusters as $cluster): ?><option value="<?= rkPortalEscape($cluster['id']) ?>"><?= rkPortalEscape($cluster['name']) ?> — <?= rkPortalEscape($cluster['id']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary mt-3" type="submit" <?= count($clusters) === 0 ? 'disabled' : '' ?>>Next: Review restore</button>
                    </form>
                <?php elseif ($step === 'confirm' && is_array($selectedVm) && is_array($selectedSnapshot) && is_array($selectedCluster)): ?>
                    <h2 class="h4">Review restore dry run</h2>
                    <dl class="row mb-4"><dt class="col-sm-3">VM</dt><dd class="col-sm-9"><?= rkPortalEscape($selectedVm['name']) ?></dd><dt class="col-sm-3">Snapshot</dt><dd class="col-sm-9"><?= rkPortalEscape($selectedSnapshot['date']) ?></dd><dt class="col-sm-3">Cluster</dt><dd class="col-sm-9"><?= rkPortalEscape($selectedCluster['name']) ?></dd></dl>
                    <form method="post">
                        <input type="hidden" name="csrfToken" value="<?= rkPortalEscape($_SESSION['restorePortalCsrfToken']) ?>"><input type="hidden" name="action" value="prepareRestore">
                        <div class="form-check"><input class="form-check-input" id="confirmRecovery" name="confirmRecovery" required type="checkbox" value="yes"><label class="form-check-label" for="confirmRecovery"><?php if ($restoreDryRun): ?>I understand this is a dry run. No restore will be submitted.<?php else: ?>I understand that this submits an in-place recovery that can overwrite the selected VM's disks.<?php endif; ?></label></div>
                        <button class="btn <?= $restoreDryRun ? 'btn-primary' : 'btn-danger' ?> mt-3" type="submit"><?= $restoreDryRun ? 'Prepare restore dry run' : 'Submit in-place recovery' ?></button>
                    </form>
                <?php else: ?>
                    <h2 class="h4"><?= $restoreDryRun ? 'Restore preview' : 'Monitor recovery' ?></h2>
                    <?php if (is_array($lastRestore)): ?>
                        <div class="alert alert-success" role="status"><strong><?= rkPortalEscape($lastRestore['status']) ?></strong><br><?= rkPortalEscape($lastRestore['message']) ?></div>
                        <?php if (!$lastRestore['dryRun']): ?><p>Request ID: <code><?= rkPortalEscape($lastRestore['requestId']) ?></code><?php if ($lastRestore['progress'] !== null): ?> — <?= (int) $lastRestore['progress'] ?>%<?php endif; ?></p><?php endif; ?>
                        <p class="mb-2"><?= $lastRestore['dryRun'] ? 'The following verified GraphQL mutation would be sent in live restore mode.' : 'The submitted GraphQL mutation and variables are shown for the operation record.' ?></p>
                        <h3 class="h6">Mutation</h3>
                        <pre class="bg-light border rounded p-3"><code><?= rkPortalEscape($lastRestore['graphqlMutation']) ?></code></pre>
                        <h3 class="h6">Variables</h3>
                        <pre class="bg-light border rounded p-3"><code><?= rkPortalEscape(rkPortalJson($lastRestore['variables'])) ?></code></pre>
                    <?php else: ?><p>No restore dry run has been prepared in this session.</p><?php endif; ?>
                    <form method="post"><input type="hidden" name="csrfToken" value="<?= rkPortalEscape($_SESSION['restorePortalCsrfToken']) ?>"><input type="hidden" name="action" value="reloadProgress"><button class="btn btn-outline-primary" type="submit">Reload progress</button></form>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
