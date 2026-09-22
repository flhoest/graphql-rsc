# graphql-rsc

Reusable PHP helpers, GraphQL operations, and practical examples for Rubrik Security Cloud automation.

This project is the GraphQL-based successor to an earlier PHP framework built for the Rubrik CDM REST APIs. It supports the accompanying Rubrik Community technical blog series, beginning with [Introducing GraphQL Mutations: Taking Action with Rubrik Security Cloud](https://www.rubrik.com/community/blogs/introducing-graphql-mutations-taking-action-rubrik-security-cloud).

## What is included

- OAuth2 client-credential authentication for Rubrik Security Cloud.
- In-memory access-token caching for the lifetime of a PHP process.
- Shared JSON HTTP and GraphQL execution helpers.
- Consistent handling for HTTP, cURL, JSON, and GraphQL errors.
- VMware on-demand snapshot submission.
- VMware asynchronous request-status lookup and CLI monitoring.
- VMware VM, snapshot, and Rubrik cluster discovery helpers.
- A small browser-based VMware Restore Portal.

The active shared runtime is `core/RscFramework.php`. Do not load it together with `core/RscAuthentication.php`, because the two files define overlapping authentication functions. `RscAuthentication.php` remains as a standalone reference from the earlier stages of the series.

## Requirements

- PHP 8.1 or later.
- PHP cURL.
- A Rubrik Security Cloud service account with permissions appropriate for the queries and mutations you intend to use.

There are no Composer dependencies.

## Configuration

Copy the example configuration:

```bash
cp config/rsc-config.example.php config/rsc-config.php
```

Set these values in `config/rsc-config.php`:

- `rsc_url`: the RSC tenant URL, for example `https://your-tenant.my.rubrik.com`.
- `client_id`: service-account client ID.
- `client_secret`: service-account client secret.

`config/rsc-config.php` is ignored by Git. Never commit it, `secret.json`, access tokens, or any other credentials.

The framework sends `client_id` and `client_secret` in the JSON body of the `/api/client_token` request. Tokens are cached in memory only; a new CLI process requests a new token.

## Project structure

```text
graphql-rsc/
├── config/
│   └── rsc-config.example.php
├── core/
│   ├── RscFramework.php
│   ├── rkGetClusters.php
│   ├── rkGetSnapshots.php
│   ├── rkGetVsphereVMs.php
│   ├── rkRequestStatus.php
│   ├── rkRestoreVm.php
│   └── rkTriggerSnapshot.php
├── examples/
│   ├── MonitorRequest.php
│   └── TriggerSnapshot.php
├── graphql/
├── web/
│   └── index.php
└── LICENSE
```

## Command-line examples

Trigger an on-demand VMware snapshot:

```bash
php examples/TriggerSnapshot.php \
  --workload-id="VM_FID" \
  --workload-name="VM_NAME" \
  --confirm
```

Monitor an asynchronous VMware request:

```bash
php examples/MonitorRequest.php \
  --request-id="REQUEST_ID" \
  --cluster-uuid="CLUSTER_UUID" \
  --poll-interval=5 \
  --timeout=300
```

`rkWaitForRequest()` is a blocking CLI helper. It returns when a request reaches `SUCCEEDED`, `FAILED`, or `CANCELED`, or when the local monitoring timeout expires. `TIMEOUT` means the monitor stopped waiting; it does not prove that the RSC operation failed.

## VMware Restore Portal

The portal is a small server-side wizard that:

1. Lists VMware VMs.
2. Lists recovery points for the selected VM.
3. Lists Rubrik clusters for request monitoring.
4. Reviews the recovery selection.
5. Displays a GraphQL preview or asynchronous request status.

For local testing, run:

```bash
php -S localhost:8080 -t web
```

Then open `http://localhost:8080`.

Expose only `web/` through a PHP-capable web server. Keep `config/`, `core/`, and `graphql/` outside the web-server document root.

### Dry-run and live restore mode

The portal defaults to dry-run mode:

```php
'restore_dry_run' => true
```

Dry run performs read-only discovery and displays the exact `vsphereVmInitiateInPlaceRecovery` mutation and variables. It does not submit a recovery request.

To deliberately submit a live VMware in-place recovery, change the local configuration only:

```php
'restore_dry_run' => false
```

This is destructive and can overwrite the selected VM’s disks. Test with a non-production VM first. The portal has server-side CSRF validation, explicit confirmation, current VM/snapshot/cluster validation, session cookies with `HttpOnly` and `SameSite=Strict`, and HTML output escaping. It does not yet provide a complete production authentication, authorization, audit, rate-limit, or repeat-submission solution.

## Development notes

- `rkExecuteGraphQLFile()` loads operations from `graphql/` and executes them through the shared runtime.
- GraphQL errors returned with HTTP 200 are still raised as exceptions.
- cURL handles are released with `unset($curl)`.
- The portal keeps long-running monitoring non-blocking by issuing individual status requests when the operator selects **Reload progress**.

## License

See [LICENSE](LICENSE).
