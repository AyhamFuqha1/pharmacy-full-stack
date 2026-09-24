# Production backup and recovery

## Included data

Each versioned TAR/GZIP package contains a MySQL-native logical dump, files from the private `local` disk (Print Identity logos, attachments, and retained import sources), files from the public upload disk, and a JSON manifest with SHA-256 checksums and sizes. Application source, `.env`, secrets, logs, caches, `vendor`, and `node_modules` are excluded.

## Production prerequisites

- PHP Phar and zlib support.
- `mysqldump` and `mysql` client tools compatible with the production MySQL server.
- A private Laravel filesystem disk configured through `BACKUP_DISK`; use a mounted durable volume or an object-storage disk in production.
- The web/PHP user must be able to write temporary files under `storage/framework/cache`.
- Back up `.env`, `APP_KEY`, database credentials, and other secrets separately in the deployment secret manager. They are intentionally never packaged.

Set `BACKUP_PREFIX`, `BACKUP_RETENTION_COUNT`, and `BACKUP_SCHEDULE` as needed. Manual creation is available in the Administrator UI or with `php artisan backup:create --user=ADMIN_USER_ID`.

For daily scheduling, configure the host to run Laravel's scheduler every minute:

```text
* * * * * cd /path/to/backend && php artisan schedule:run
```

The application does not assume cron already exists.

## Validation and isolated restore test

Validate package checksums from the UI. For a real import test, first create an empty, isolated MySQL database and a storage directory outside active application storage, then run:

```text
php artisan backup:restore BACKUP_ID --target-database=pharmacy_recovery_test --storage-path=/srv/pharmacy-recovery-files --confirm=RESTORE
```

The command rejects the configured active database and active application storage. Confirm the import, migrations table, critical business tables, plausible row counts, Print Identity logo, attachments, and import sources. Remove only the isolated database/directory after verification.

## Production recovery procedure

1. Preserve the failed environment and current database before changing anything.
2. Validate the selected package and provision a replacement database/storage location.
3. Put the application behind maintenance mode or stop all web and queue workers so no writes occur.
4. Run the isolated restore command against the replacement database and storage location.
5. Verify key tables, row counts, login, document references, assets, and checksums.
6. Update secret-managed deployment configuration to point at the recovered database/storage. Do not copy secrets from the package.
7. Run `php artisan optimize:clear`, restart queue workers, and perform smoke tests.
8. Re-enable traffic only after validation. If validation fails, keep traffic stopped and point back to the preserved pre-recovery environment.

The HTTP restore action only validates and prepares instructions; it never overwrites the active database. Production cutover remains an explicit infrastructure operation.

## Retention

After a successful backup, completed packages beyond `BACKUP_RETENTION_COUNT` are pruned. Failed/running backups are not treated as valid, and the configured minimum is one, so the only successful package is never silently removed.
