# Project Desk environment

This document defines the supported baseline for local development, CI, and
production. It contains no credentials; keep real environment values in an
untracked `.env` file or in the deployment platform's secret store.

## Supported runtimes

| Component | Supported baseline | Notes |
| --- | --- | --- |
| PHP | 8.3 or newer | CI uses PHP 8.4. |
| Composer | 2.x | Install dependencies from `composer.lock`. |
| Node.js | 22.12 or newer | Required by Vite 8 and its React plugin. |
| pnpm | 11.16.0 | Pinned by `package.json`; use Corepack. |
| Supported v1 database | SQLite in WAL mode | Local, CI, and single-host production. |
| Production OS | Maintained Linux LTS | One application host with PHP-FPM and private file storage. |

Required PHP extensions for the current stack and planned file features are:
`bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `intl`, `mbstring`,
`openssl`, `pdo`, `pdo_sqlite`, `sqlite3`, `tokenizer`, `xml`, and `zip`.
The supported v1 profile requires PDO SQLite. Install another PDO driver only
as part of a separately tested database-migration project.

## Local development with SQLite

1. Copy `.env.example` to `.env`.
2. Create an empty `database/database.sqlite` file if it does not exist.
3. Install the locked backend and frontend dependencies.
4. Ensure a local application key exists (without rotating an existing key) and run the migrations.

PowerShell example:

```powershell
Copy-Item .env.example .env
if (-not (Test-Path database/database.sqlite)) {
    New-Item database/database.sqlite -ItemType File
}
composer install
php artisan project-desk:ensure-app-key
php artisan migrate
php artisan db:seed --class=WorkflowStatusSeeder
php artisan project-desk:provision-admin
corepack enable
corepack prepare pnpm@11.16.0 --activate
pnpm install --frozen-lockfile
```

Do not rerun `key:generate` on an initialized environment: rotating `APP_KEY`
without a dedicated migration invalidates encrypted application data and the
local backup-key fallback. The idempotent Project Desk command creates the key
only when it is absent.

The provisioning command interactively creates the first internal administrator
without shipping a default password. It exits successfully without creating a
duplicate when an active administrator already exists. Public self-registration
is intentionally disabled.

Run Laravel and Vite in separate terminals:

```powershell
php artisan serve
pnpm run dev
```

Do not share the SQLite file over a network filesystem or commit it. The v1
production profile is a single application host with WAL, a five-second busy
timeout, immediate write transactions, encrypted off-host `.pdesk` copies, and
the measured internal workload described in the release checks. Horizontal
scaling or a move to PostgreSQL/MySQL is a separate migration: it is not
supported until a database-specific backup adapter and restore drill exist.

## File uploads and imports

The PHP defaults of 2 MB per upload and 128 MB of memory are too small for
project attachments, Excel/CSV imports, PDF exports, and backups. Start with
the following server-side limits and revise them after measuring real files:

```ini
upload_max_filesize = 25M
post_max_size = 32M
memory_limit = 512M
max_file_uploads = 20
max_execution_time = 120
```

Keep application validation at or below 25 MB per file. On Nginx, set
`client_max_body_size 32m`; apply an equivalent request-size limit on any
reverse proxy. Restart PHP-FPM and the web server after changing these values.
Large imports and exports should run through the queue rather than an HTTP
request. The current synchronous XLSX path caps uploads at
`XLSX_MAX_FILE_KB`, rows at `CSV_MAX_ROWS`, ZIP entries at
`XLSX_MAX_ARCHIVE_ENTRIES`, and total expanded content at
`XLSX_MAX_UNCOMPRESSED_MB`. Import only the versioned workbook downloaded from
Data Center: one worksheet, fixed headers, no formulas, macros, or external
relationships. Generated XLSX/PDF responses are authorized downloads with a
private/no-store cache policy. Move materially larger exports or PDF batches to
a queue before raising these caps.

Browser-uploaded recovery packages may be larger than the general
25 MB attachment baseline; if operators use that feature, set the reverse
proxy and PHP request limits at or above `BACKUP_MAX_FILE_KB`. Prefer locally
created automatic packages and off-host replication for large installations.

The application always validates the declared extension, detected MIME type,
file signature and active content before storing an upload. Structural
validation never makes a file downloadable: only a successful malware scan
promotes it to `safe`. Scanner failure leaves it `structurally_safe`, and a
malware finding moves it to `quarantined`; both states are fail-closed.

For a local ClamAV installation set `MALWARE_SCANNER_DRIVER=command` and point
`MALWARE_SCANNER_EXECUTABLE` at `clamscan` (or a compatible executable using
exit code 0 for clean, 1 for infected and another code for failure). Arguments
are a comma-separated list in `MALWARE_SCANNER_ARGUMENTS`; the absolute file
path is appended as the final argument without shell interpolation. For a
queue, daemon or vendor integration set `MALWARE_SCANNER_DRIVER=callback` and
`MALWARE_SCANNER_CALLBACK` to a container-resolvable class implementing
`App\Contracts\MalwareScanner`. Production rejects uploads while neither
integration is configured.

Set per-project and per-user storage ceilings with
`UPLOAD_PROJECT_QUOTA_BYTES`, `UPLOAD_USER_PROJECT_QUOTA_BYTES` and
`UPLOAD_PROJECT_FILE_LIMIT`. `UPLOAD_RATE_LIMIT_PER_MINUTE` controls the
per-user/per-project request limiter. These controls are enforcement limits,
not capacity planning substitutes; alert on quota and scanner-failure audit
events.

`UPLOAD_ORPHAN_RETENTION_HOURS` controls the grace period for file objects
that have no attachment, requirement-book, meeting-minutes, import/export, or
backup reference. The default is 72 hours and production accepts 1–8760. The
daily scheduler runs `project-desk:prune-orphaned-files`; verify candidates
without changing storage with `php artisan project-desk:prune-orphaned-files
--dry-run`. The command rechecks every reference under a database lock, holds
the restore write fence, quarantines the private blob before committing the
database deletion, restores it on rollback, and writes a
`project_file.retention_pruned` audit event. Archived attachment links are
durable history and therefore never qualify as orphaned.

## Linux production baseline

Use a maintained Linux LTS release with:

- Nginx (or an equivalent reverse proxy) and PHP-FPM;
- SQLite on durable local storage with WAL; never NFS/SMB shared storage;
- a queue worker supervised by `systemd` or Supervisor;
- Laravel's scheduler invoked every minute by a timer or cron;
- TLS, automated database backups, log rotation, and off-host attachment
  storage appropriate to the deployment;
- `APP_ENV=production` and `APP_DEBUG=false`;
- `SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`, and HTTPS-only access;
- secrets supplied by the hosting platform, never committed to Git.

Node.js is required in the build stage. Production web processes should serve
the generated assets and do not need to run the Vite development server.

The scheduler runs both `project-desk:sync-notifications` (persistent,
permission-scoped task and meeting alerts) and
`project-desk:automatic-backup`. User notification preferences may narrow the
administrator's system policy but cannot enable a disabled category or exceed
the configured lead window.

SQLite backups are complete encrypted `.pdesk` packages containing the
database and project files. Set a dedicated, secret-manager supplied
`BACKUP_ENCRYPTION_KEY` in staging and production; do not rely on `APP_KEY` or
store the key with the packages. See [BACKUP_AND_RECOVERY.md](BACKUP_AND_RECOVERY.md)
for key rotation, retention, off-host replication, rollback behavior, and the
mandatory restore-drill procedure.

The restore HTTP path itself activates Laravel maintenance mode and takes an
exclusive filesystem fence. Existing web requests finish before the database
swap; new requests and scheduled notification/backup writers are refused until
the restore commits or rolls back. Operators must still stop any custom queue
workers before a production drill.

## CI parity

GitHub Actions uses PHP 8.4, Node.js 22.12, pnpm 11.16.0, and SQLite. The
workflow installs both lockfiles, validates migrations, checks formatting,
linting and types, builds the frontend, then runs `composer test`.
