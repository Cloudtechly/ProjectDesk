# Project Desk backup and recovery

Project Desk creates complete `.pdesk` recovery packages for file-backed
SQLite deployments. A package contains a consistent SQLite snapshot and every
non-backup `FileObject` referenced by that snapshot. Backup packages themselves
are deliberately excluded so a new package cannot recursively contain older
packages.

## Security and format

- The package payload is a ZIP archive, but it is never stored unencrypted.
- The whole payload is encrypted in authenticated 1 MiB chunks with
  AES-256-GCM. A versioned, authenticated header identifies the cipher and a
  non-secret key ID; the key is never embedded in the package.
- `manifest.json` records the SQLite checksum, every project-file checksum and
  destination, the inventory checksum, and completeness flags.
- Creation succeeds only after decrypting the new package and rechecking every
  manifest entry. Validation and restoration repeat that verification.
- Archive paths are allowlisted (`manifest.json`, `database/database.sqlite`,
  and checksum-named blobs). The restore code does not extract archive-provided
  paths, rejects duplicate entries and traversal paths, and enforces expanded
  size and entry-count limits.

Generate a dedicated 32-byte key once:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Store the result in the deployment secret manager as
`BACKUP_ENCRYPTION_KEY`. Never commit it, log it, or store it next to the
packages. Production and staging refuse to create or open packages without a
dedicated key. Local development and tests may derive an isolated fallback
from `APP_KEY` to keep first-run setup simple.

### Key rotation

1. Preserve the current key in the secret manager.
2. Put the new key in `BACKUP_ENCRYPTION_KEY`.
3. Put old keys, comma-separated, in `BACKUP_PREVIOUS_ENCRYPTION_KEYS`.
4. Create and validate a new backup with the new active key.
5. Test-restore the oldest retained package while its old key remains present.
6. Remove an old key only after every package encrypted by it has expired.

The package header's key ID lets the application select the right configured
key without trial-decrypting with every key.

## Configuration

```dotenv
BACKUP_MAX_FILE_KB=524288
BACKUP_MAX_EXPANDED_KB=2097152
BACKUP_MAX_ENTRIES=10000
BACKUP_DISK=local
BACKUP_FILE_DISKS=local
BACKUP_ENCRYPTION_KEY=base64:REPLACE_WITH_32_BYTE_SECRET
BACKUP_PREVIOUS_ENCRYPTION_KEYS=
```

`BACKUP_FILE_DISKS` is an allowlist. Creation fails closed if the SQLite
snapshot references a project-file disk that is not listed, if a file is
missing, or if its bytes differ from its recorded checksum. The current backup
package disk must use a Laravel local filesystem driver because validation and
restore require an OS path. Replicate completed `.pdesk` packages to encrypted
off-host or immutable storage after creation; do not point the active Project
Desk file tree and its only backups at the same failure domain.

## Restore transaction

Restore is intentionally administrative and destructive, so the UI requires
the exact confirmation phrase and package checksum. The controller activates
maintenance mode and takes an exclusive filesystem write fence; in-flight web
requests drain first, new requests are rejected, and built-in scheduled writers
skip their run. The fence and maintenance marker remain active through rollback
bookkeeping. The backend then:

1. authenticates and fully validates the source package;
2. verifies exact schema compatibility and that the current administrator is
   active in the source snapshot;
3. creates and verifies a new complete encrypted `pre_restore` package;
4. stages every source project file and verifies its checksum on its destination
   disk;
5. moves current file versions to isolated rollback keys, commits source files,
   and isolates files absent from the source;
6. isolates the current SQLite main file together with any `-wal` and `-shm`
   sidecars, installs the restored main file by same-directory rename, validates
   it, and only then removes the isolated database set;
7. records the restore and audit event; then removes rollback staging.

If file commit, database replacement, or bookkeeping fails, Project Desk moves
the old files back and restores the pre-restore SQLite snapshot. A database-swap
failure returns the previous main database and its original WAL/SHM set as one
unit, so stale WAL frames are never left beside a successfully restored main
file. A failure of that automatic rollback is surfaced as a critical error;
preserve the isolated `.restore-rollback-*` / `.restore-failed-*` files and the
`pre_restore` package, then escalate to an operator rather than retrying blindly.

Legacy `.sqlite`, `.sqlite3`, and `.db` uploads remain accepted. They are
validated for schema, administrator continuity, and safe storage metadata,
then immediately converted to an encrypted `.pdesk` package marked
`legacy_database_only=true`. Restoring one cannot reconstruct file bytes that
were never present, so use it only as a migration bridge.

## Operating policy

- Run `project-desk:automatic-backup` through Laravel's scheduler. The system
  retains the configured number of automatic packages and never prunes manual
  or pre-restore packages through that retention job.
- Monitor the command exit status and absence of a successful backup in the
  expected period. Silence is not evidence of success.
- Copy completed packages off-host using an authenticated transport and record
  the outer SHA-256 checksum supplied by Project Desk.
- Suggested starting target: daily backups, RPO 24 hours, and RTO four hours.
  Tighten these only after measuring package size and restore duration.
- Perform a monthly restore drill on an isolated clone. Confirm application
  login, representative project/task counts, requirement books, meeting
  minutes, and several downloaded attachments—not only archive listing.
- The application enforces maintenance mode and a web/scheduler write fence for
  restores. Stop any custom queue workers as an additional operational guard,
  verify the named target, retain the source and pre-restore packages, then
  resume only after application-level checks pass.
