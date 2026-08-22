# Attachments and retention

Project Desk stores every upload as a private `file_objects` record and a
separate `attachment_links` record. The Documents tab can create exactly one
initial link to the project, an active task in that project, or an active
requirement in that project. Task and requirement IDs are checked against the
route project before storage and again inside the persistence transaction.

Listing and downloading inherit project visibility. Uploads retain the
project upload policy, scanner availability check, MIME/content validation,
per-user/project quota, rate limit, and audit trail. Archiving changes only the
selected attachment link; it does not delete the file or other links. Only a
successful malware scan is downloadable.

## Orphan retention

An orphan is a file object older than `UPLOAD_ORPHAN_RETENTION_HOURS` with no
row in any of these durable reference sets:

- `attachment_links`, including archived links;
- `requirement_book_versions`;
- `meeting_minutes`;
- `data_jobs`, including backup, import, and export artifacts.

Run a production preview before first activation:

```shell
php artisan project-desk:prune-orphaned-files --dry-run
```

The scheduled command uses a single-run cache lock and the restore write
fence. It revalidates age and every reference in the deletion transaction,
moves an existing blob to a private quarantine path, commits the audit record
and database deletion, then removes the quarantined blob. A database rollback
restores the original blob. Stale quarantine blobs from an interrupted
post-commit cleanup are retried on later runs.
