# Production deployment runbook

This document prepares a release; it is not authorization to deploy. Never commit a real `.env`, `APP_KEY`, database password, mail credential, or object-storage key.

## Required environment

Start from `backend/.env.production.example` and `frontend/.env.production.example`. Required groups are:

- Application: `APP_NAME`, `APP_ENV=production`, `APP_KEY`, `APP_DEBUG=false`, `APP_URL`, `APP_TIMEZONE`, `BUSINESS_TIMEZONE`, locale settings.
- MySQL: `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and optionally `MYSQL_ATTR_SSL_CA`.
- Runtime: `CACHE_STORE`, `CACHE_PREFIX`, `QUEUE_CONNECTION`, `QUEUE_FAILED_DRIVER`, session driver/lifetime/encryption/domain/security/SameSite settings.
- HTTPS/API: `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` without schemes in the Sanctum list and with full HTTPS origins in CORS.
- Mail: mailer, host, port, username, password, encryption, from address, and from name.
- Files: `FILESYSTEM_DISK`, backup disk/prefix/retention/schedule, and MySQL client binary names.
- Frontend build: `VITE_API_BASE_URL=https://api.example.com/api` and `VITE_CURRENCY`.

Use a secret manager for every real secret. The example values are placeholders only.

## HTTPS, CORS, sessions, and Sanctum

Terminate TLS at the web server or trusted proxy and redirect HTTP to HTTPS. For a typical `app.example.com` frontend and `api.example.com` API:

- `APP_URL=https://api.example.com`
- `VITE_API_BASE_URL=https://api.example.com/api`
- `CORS_ALLOWED_ORIGINS=https://app.example.com`
- `SANCTUM_STATEFUL_DOMAINS=app.example.com`
- `SESSION_DOMAIN=.example.com`
- `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`

Never use `*` for credentialed CORS. The current frontend authenticates API requests with Sanctum bearer tokens. If authentication is changed to Sanctum cookie mode, requests must include credentials and `/sanctum/csrf-cookie` must be called before login. If frontend and API use different registrable domains, cookie mode requires `SESSION_SAME_SITE=none` with secure HTTPS cookies; confirm browser and CSRF behavior before release.

API exceptions are normalized and 5xx responses do not contain traces, SQL, filesystem paths, or secrets. Keep `APP_DEBUG=false`; server details remain in protected logs.

## Pre-deploy

1. Select a clean, reviewed release revision and record its identifier.
2. Confirm the full backend suite and frontend production build are green.
3. Populate production environment variables through the deployment secret manager.
4. Verify PHP extensions, including PDO MySQL, Phar, and zlib.
5. Verify `mysql` and `mysqldump` are compatible with the production server.
6. Create and validate a backup. Do not continue without a recoverable artifact.
7. Complete the isolated restore drill below and record its date/result.
8. Confirm database/cache/session/queue tables exist through normal migrations.

## Deploy

1. Put the application in maintenance mode if the migration or release cannot safely coexist with the old code. Stop queue workers before schema changes.
2. Install backend dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`.
3. Install locked frontend dependencies and build with the production frontend environment: `npm ci` then `npm run build`.
4. Run only `php artisan migrate --force`. Never run `migrate:fresh`.
5. Rebuild caches:
   - `php artisan optimize:clear`
   - `php artisan config:cache`
   - `php artisan event:cache`
   - `php artisan route:cache`
   - `php artisan view:cache`
6. Ensure the web/PHP and worker users can write `storage/app`, `storage/framework/cache`, `storage/framework/sessions`, `storage/framework/views`, and `storage/logs`. The configured backup disk must also be writable.
7. Expose only `public/` as the web root. Create the Laravel public storage link only for intentionally public product uploads. Never link `storage/app/private`, Print Identity originals, attachments, imports, or backups.
8. Start supervised queue workers and reload them after code changes.
9. Configure the scheduler and web server/HTTPS, then leave maintenance mode after smoke tests succeed.

Generated frontend build artifacts are deployment outputs and remain ignored by source control.

## Queue operations

Use a durable production driver such as `database` or `redis`, not `sync`. Run workers under Supervisor, systemd, or the hosting platform worker manager, for example:

```text
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

Monitor `failed_jobs` with `php artisan queue:failed`; investigate before using `queue:retry`. Use `php artisan queue:restart` during controlled releases. Do not rely on a terminal session remaining open.

## Scheduler

The scheduler runs daily backups when `BACKUP_SCHEDULE=daily`. Configure one scheduler invocation per minute using a platform scheduler or cron:

```text
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Use the real deployment path; do not copy a local Windows path.

## Logging and monitoring

Use `LOG_CHANNEL=daily`, `LOG_LEVEL=warning`, and a deliberate `LOG_DAILY_DAYS` retention value. Restrict log access to operators. Monitor web-server/PHP errors, Laravel logs, `failed_jobs`, backup failures in `backup_runs` and audit logs, worker exits, disk utilization, and scheduler execution. Errors are logged server-side while normalized API responses remain non-sensitive.

The unauthenticated `/up` framework liveness endpoint and `/api/health` database health endpoint return only coarse status. `/api/deployment/readiness` is permission-protected and checks application key presence, database connectivity, storage, queue mode, backup destination, and MySQL client availability without returning credentials or paths.

## Isolated restore drill

1. Provision a new temporary MySQL database with a name that cannot be confused with production.
2. Create a temporary recovery directory outside active application storage.
3. Validate the selected backup checksum in the Administrator UI.
4. Run `php artisan backup:restore BACKUP_ID --target-database=TEMP_DATABASE --storage-path=/temporary/recovery-storage --confirm=RESTORE`.
5. Verify import success and the presence of `migrations`, users/roles, products/batches/stock, sales/purchases, journals, document sequences, `backup_runs`, and Print Identity tables.
6. Compare plausible row counts with the manifest-time environment and verify document references and balances using read-only queries.
7. Verify Print Identity logos, attachments, import sources, and public uploads in the temporary storage tree; checksum validation must remain successful.
8. Record backup ID, release revision, test date, operator, MySQL versions, row-count checks, asset checks, and pass/fail result.
9. Destroy only the temporary database and recovery directory after sign-off. Never point this command at active production targets.

## Post-deploy smoke tests

- Check `/up`, `/api/health`, and the protected readiness screen.
- Sign in and confirm role/permission boundaries.
- Read an existing sale and create one authorized low-risk test sale according to business procedure.
- Print a transaction and confirm configured Print Identity and canonical reference.
- Send a queue/mail test and confirm worker processing and failure visibility.
- Create, validate, and download a production backup to the configured durable destination.
- Confirm unauthorized users cannot access backup, restore, financial-cost, or administration functions.

## Rollback

Code-only rollback is acceptable only while the previous code remains schema-compatible. Keep the release artifact and prior revision available. For database-affecting failures, stop traffic and workers, preserve the failed state, and assess forward repair before restoring. Never reverse production by running destructive migrations. If recovery is required, follow `docs/backup-restore.md`, restore into replacement targets, validate fully, then switch deployment configuration. Keep the original database/storage preserved until recovery is accepted.
