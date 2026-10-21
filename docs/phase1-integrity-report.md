# eReserve Phase 1 integrity repair report

Prepared 21 October 2026 (Asia/Manila). **Isolated testing completed. Live execution is not approved and was not performed.**

The final read-only comparison found no changes to any working `ereserve` table's records, column definitions, indexes or normalized CREATE definition. The working database still has its original 10 MyISAM and 7 InnoDB tables. Its migration ledger still has 26 records. The instance-wide default engine remains MyISAM. No applied migration, .env, production credential, existing payment amount or historical record was modified.

**1. Files created or modified**

Modified:

- `config/database.php`: explicit InnoDB for the MySQL and MariaDB Laravel connections.
- `app/Support/FacilityCatalog.php`: resource deletion checks all reservation history, including null-ID legacy links matched by barangay and slug. Resource lookup, history checks, photo-row deletion and resource deletion share a transaction and resource row lock. File deletion follows the successful transaction.

Created:

- `app/Support/DatabaseIntegrityRepair.php`: engine and foreign-key inspection, orphan rejection, idempotent repairs, and narrowly scoped strengthening of the old reservation-resource SET NULL rule.
- The 14 migrations listed below.
- `scripts/phase1/inspect.php`: read-only working schema and orphan audit.
- `scripts/phase1/common.php`: snapshots, exclusive artifact creation, isolated-account checks and client helpers.
- `scripts/phase1/backup-restore.php`: locked mixed-engine backup, unique database/account creation, restricted restore and verification.
- `scripts/phase1/isolated-app.php`: guarded Laravel bootstrap with isolated credentials and side-effect controls.
- `scripts/phase1/repair-test.php`: migration execution and preservation/idempotency checks, restricted to a verified clone.
- `scripts/phase1/schema-checks.php`: isolated schema failure-case and future-engine checks.
- `scripts/phase1/final-audit.php`: verifies the clone after tests and compares the working database against its backup baseline.
- `tests/Phase1/IntegrityWorkflowTest.php` and `tests/Phase1/phpunit.xml`: separate MySQL integration suite; no RefreshDatabase, resets or database wiping.
- This report.

Local backup, connection and verification artifacts are under the existing git-ignored `storage/app/phase1/` directory. The backup contains private application data; `connection.json` contains only the new disposable-account credentials. They are not source-control artifacts.

**2. Exact repair migrations**

Every filename below is in `database/migrations/`. The first ten execute only `ALTER TABLE ... ENGINE=InnoDB` when the existing engine is MyISAM. An already-InnoDB table is skipped; a missing or unexpected engine stops the repair.

| Migration filename | Repair |
| --- | --- |
| `2026_10_21_000001_convert_cache_to_innodb.php` | `cache` → InnoDB |
| `2026_10_21_000002_convert_cache_locks_to_innodb.php` | `cache_locks` → InnoDB |
| `2026_10_21_000003_convert_facility_photos_to_innodb.php` | `facility_photos` → InnoDB |
| `2026_10_21_000004_convert_failed_jobs_to_innodb.php` | `failed_jobs` → InnoDB |
| `2026_10_21_000005_convert_job_batches_to_innodb.php` | `job_batches` → InnoDB |
| `2026_10_21_000006_convert_jobs_to_innodb.php` | `jobs` → InnoDB |
| `2026_10_21_000007_convert_migrations_to_innodb.php` | `migrations` → InnoDB |
| `2026_10_21_000008_convert_password_reset_tokens_to_innodb.php` | `password_reset_tokens` → InnoDB |
| `2026_10_21_000009_convert_sessions_to_innodb.php` | `sessions` → InnoDB |
| `2026_10_21_000010_convert_two_factor_challenges_to_innodb.php` | `two_factor_challenges` → InnoDB |
| `2026_10_21_000011_repair_reservations_user_id_foreign_key.php` | reservations.user_id → users.id; DELETE RESTRICT |
| `2026_10_21_000012_repair_reservations_facility_id_foreign_key.php` | reservations.facility_id → facilities.id; DELETE RESTRICT; nullable retained |
| `2026_10_21_000013_repair_facility_photos_facility_id_foreign_key.php` | facility_photos.facility_id → facilities.id; DELETE CASCADE |
| `2026_10_21_000014_repair_two_factor_challenges_user_id_foreign_key.php` | two_factor_challenges.user_id → users.id; DELETE CASCADE |

Foreign-key additions inspect the existing relationship and its rules, preserve existing indexes and nullable columns, and reject orphaned non-null references without rewriting or deleting records. All four new constraints use ON UPDATE RESTRICT. A correct existing key is recognized even if it has a different name; incompatible keys stop the migration. The only authorized strengthening is the reservation-resource relationship from the old SET NULL rule to RESTRICT.

On the observed schema all four keys were absent and received their conventional `<table>_<column>_foreign` names. If the legacy SET NULL key already exists under that name, the atomic replacement uses `reservations_facility_id_retained_foreign` to avoid MySQL's duplicate-symbol restriction. Re-entry recognizes that key.

Each `down()` throws an explicit forward-only error. No rollback drops a constraint or changes an engine back to MyISAM. MySQL DDL commits independently, so a partially completed run must be investigated and resumed forward, not rolled back blindly.

**3. Backup and restore verification**

Final acceptance database: `ereserve_p1_20261021143638_b1dd0449`.

Final artifact directory: `storage/app/phase1/20261021143638_b1dd0449/`.

- Backup: `backup.sql`, 47,345 bytes.
- SHA-256: `c8b95f8eb131a4b2279b63f9b2cc326c61b9a7c6cdafc914feee6f210d18cdcb`.
- Database account: `ep1_b1dd0449@localhost`; privileges restricted to the exact escaped disposable database name, with no global privileges beyond USAGE and no grant option.
- A SELECT against `ereserve.users` was denied. SHOW GRANTS verified no working-database write privileges; no write probe was issued against working records.
- No triggers, routines, events or views were present. The backup utility refuses those objects rather than restoring unreviewed executable behavior.
- A single source connection held READ locks on all 17 tables, with autocommit disabled, through both fingerprints and mysqldump. Source fingerprints before and after the dump matched. Locks were released before database/account creation or restore.
- mysqldump ran with `--skip-lock-tables --skip-add-locks --no-tablespaces --set-gtid-purged=OFF --hex-blob --skip-triggers --default-character-set=utf8mb4`. Its independent connection only read while the source connection held the locks.
- The dump was restored using the restricted disposable account, without CREATE DATABASE or USE statements targeting the source.
- All 17 restored tables matched counts, SHA-256 fingerprints of every row/column in primary-key order, full column metadata, indexes excluding estimated cardinality, and normalized CREATE definitions. Only redundant `CHARACTER SET utf8mb4` text preceding an identical utf8mb4 collation is normalized.
- New backup files and artifacts use exclusive creation; none were overwritten.

The locking procedure is necessary for a consistent mixed-engine backup: a transaction-only snapshot does not protect MyISAM rows. See the [MySQL mysqldump documentation](https://dev.mysql.com/doc/refman/9.7/en/mysqldump.html) and [LOCK TABLES documentation](https://dev.mysql.com/doc/refman/9.7/en/lock-tables.html).

| Table | Preserved records |
| --- | ---: |
| cache | 39 |
| cache_locks | 0 |
| facilities | 2 |
| facility_photos | 0 |
| failed_jobs | 0 |
| job_batches | 0 |
| jobs | 5 |
| migrations | 26 original; 14 added only in repaired clones |
| notifications | 8 |
| official_use_conflicts | 0 |
| official_uses | 1 |
| password_reset_tokens | 0 |
| payments | 2 |
| reservations | 2 |
| sessions | 7 |
| two_factor_challenges | 0 |
| users | 3 |

Earlier immutable artifacts remain at `20261021142617_7dab7702` and `20261021142702_571fbe1a`, with corresponding disposable database names prefixed `ereserve_p1_`. The first restore stopped before repair testing because the initial CREATE-text comparison was too strict about redundant character-set syntax. The second passed initial repair/workflow verification. The final third clone tested the completed implementation from a fresh verified restore. No backups or disposable databases were deleted.

**4. Isolated tests**

Final run: **9 tests passed, 65 assertions** on MySQL 9.1.0, PHP 8.3.14. Evidence: `workflows.xml`.

Covered:

- HTTP booking submission and acceptance, payment recording, cancellation history, refund-pending and confirmed refund; original amount retained.
- Equipment allocation, rejected overbooking and restored availability after cancellation.
- Resource deletion blocked for reservation history and nullable legacy links; photo rows retained when deletion is blocked.
- Unavailable resources reject new bookings while preserving historical reservations.
- Official Use history and conflict records protect their parent resources/reservations.
- All four repaired foreign keys reject invalid references (MySQL 1452).
- Referenced historical parents reject deletion (MySQL 1451).
- Unused resources remain deletable; photos and transient two-factor challenges retain their appropriate cleanup behavior.
- Failed and successful username/password authentication.
- A newly generated queue name: push, reserve, release, re-reserve, execute a harmless synthetic job and delete it.
- Array mail transport accepts a synthetic message without sending it.

Additional checks passed:

- All 14 migrations applied to the restored schema; all 17 tables became InnoDB.
- All existing row hashes, column definitions and indexes survived migration. Original migration ledger rows survived; exactly 14 repair entries were added.
- Calling every `up()` again preserved the complete already-correct state.
- Newly created probe tables used the application's configured InnoDB engine.
- Orphans stopped foreign-key creation without data cleanup.
- Conflicting existing delete rules stopped without removing the existing constraint.
- The legacy SET NULL key was strengthened atomically, preserving all records; re-entry recognized its alternate name.
- Probe tables were removed; the final clone again had exactly 17 tables.
- Every workflow used an outer rollback transaction. Final fingerprints confirmed no synthetic records remained and all 5 restored jobs were unchanged.
- Source schema/record fingerprints still matched the locked baseline after all testing.
- PHP syntax checks, targeted Pint checks and `git diff --check` passed.

Test isolation disabled .env loading and cached production config/routes/events. Laravel had only the restricted MySQL connection, array cache/session/mail, null default queue and broadcaster, isolated filesystem/log/view paths, fake notifications, and denied stray Laravel HTTP requests. No queue worker or scheduler was started; no restored job payload was executed. Tests never called migrate:fresh, migrate:refresh or db:wipe.

Evidence files: `verified.json`, `baseline.json`, `repairs-verified.json`, `after-repairs.json`, `workflows.xml`, `schema-checks.json`, and `final-audit.json`.

**5. Deletion policy**

- Reservations → users: RESTRICT. Hard-deleting a user cannot cascade through reservations.
- Reservations → resources: RESTRICT. Nullable legacy links stay nullable; no relinking or snapshot rewriting occurs.
- Existing Official Use → resources, conflicts → Official Use/reservations, and payments → reservations remain RESTRICT.
- Existing nullable creator/refunder relationships retain their existing SET NULL rules. They do not delete historical records.
- Photo → resource and temporary two-factor challenge → user use CASCADE, matching their original intended lifecycle. Neither cascades through reservations, payments or Official Use history.
- Existing resource deactivation is `status = Unavailable`; there is no separate resource soft-delete field. It already blocks new booking and availability responses. The repair directs administrators to this existing behavior.
- SET NULL is not required for reservation resources. Keeping it would permit deleting the resource and losing the relational link despite retaining text snapshots. The prepared policy instead follows the requested resource-retention preference; no SET NULL exception approval is needed.

**6. Failed checks and remaining limitations**

Resolved during preparation:

- The first restore verifier rejected only redundant character-set formatting. Structured metadata and row hashes matched; normalization was narrowed to that exact equivalence and fresh backup/restore verification passed.
- One workflow initially used PUT against a PATCH route. The test was corrected; application routing was not changed.
- A legacy-key probe exposed MySQL error 1826 when dropping/re-adding the same constraint symbol in one ALTER. The helper now uses a distinct name for atomic replacement; the completed path passed on the final clone.
- Pint's `--dirty` mode could not launch its Git subprocess in this environment. Running Pint against explicit changed paths passed.

Remaining considerations:

- Conversion rebuilds tables and needs disk space and a maintenance window; DDL is not one rollbackable transaction.
- The data set is small. These tests validate behavior and preservation, not production-scale duration, load or concurrent-user throughput.
- Null-ID legacy reservations cannot be protected by a foreign key based on their snapshot slug/barangay. The application deletion guard protects that case; privileged raw SQL can bypass application policy. No new trigger or backfill was introduced.
- Referential constraints protect referenced parents; they do not make every historical row immutable against arbitrary privileged direct DELETE statements.
- Real email delivery and outside integrations were intentionally not exercised. Authentication used the isolated users table and array session backend; a full browser/end-to-end and database-session load test was not performed.
- AUTO_INCREMENT counters in test databases advance for rolled-back synthetic inserts. Existing primary keys and records remain unchanged; the working database's counters are unchanged.
- Three disposable databases and their restricted accounts remain for review. They should be removed separately when no longer needed; retain the verified backup according to the application's retention requirements.
- No unresolved failing acceptance check remains.

**7. Live implementation sequence — prepared only, approval required**

Do not run these steps until the user explicitly approves live execution.

1. Review these exact source changes and the final evidence. Confirm the deployment target is the expected MySQL server and database `ereserve`. Use a normal deployment shell with no `PHASE1_ARTIFACT_DIR`, test APP_CONFIG_CACHE, or test database overrides.
2. Schedule downtime; stop incoming application writes, queue workers, scheduler and other database writers. Wait for in-flight work to finish. Use the application's existing maintenance controls; do not delete queued jobs.
3. Run `php scripts/phase1/inspect.php`. Require the expected table inventory, reviewed existing constraints and zero orphans in all four relationships. Check free disk space for table rebuilds and backup storage.
4. Create a new pre-execution backup with `php scripts/phase1/backup-restore.php`. Record the new directory printed by the command. It must pass restricted restore and all 17-table comparisons. Keep writers stopped so that baseline remains valid.
5. Repeat the isolated acceptance run against that new clone before live execution if data/schema has changed since this report:
   - `php scripts/phase1/repair-test.php <new-artifact-directory>`
   - Set process-local `PHASE1_ARTIFACT_DIR` to its absolute path and run `php vendor/phpunit/phpunit/phpunit -c tests/Phase1/phpunit.xml --log-junit <new-artifact-directory>/workflows.xml`.
   - `php scripts/phase1/schema-checks.php <new-artifact-directory>`
   - `php scripts/phase1/final-audit.php <new-artifact-directory>`
   - Close that test shell before using the deployment shell.
6. Make the reviewed application helper, deletion guard, explicit engine setting and 14 new migration files available together. Preserve all historical migration files and environment credentials.
7. In the normal deployment shell, apply only the listed repairs in filename order. The exact PowerShell command sequence is:

```powershell
$repairFiles = @(Get-ChildItem -LiteralPath database/migrations -File |
    Where-Object { $_.Name -like '2026_10_21_*.php' } |
    Sort-Object Name)
if ($repairFiles.Count -ne 14) { throw 'Unexpected repair migration inventory' }
# Compare these filenames against section 2 before execution.
$repairFiles | Select-Object -ExpandProperty Name
foreach ($repairFile in $repairFiles) {
    php artisan migrate --database=mysql --path="database/migrations/$($repairFile.Name)" --force
    if ($LASTEXITCODE -ne 0) { throw "Stopped at $($repairFile.Name)" }
}
```

8. While writers remain stopped, perform the same read-only snapshot verification used in `repair-test.php`: all 17 engines InnoDB; ten total foreign keys with the reviewed rules; every original row count/hash, column and existing index identical to the new baseline; original migration rows intact plus precisely the 14 listed repair entries. Run `php scripts/phase1/inspect.php` again and confirm zero orphans. Do not run synthetic workflows on live records.
9. Refresh the normal Laravel config cache only if that deployment uses one (`php artisan config:cache`), then restart long-lived application/queue processes with their original production settings. Verify the loaded MySQL engine setting is InnoDB. Do not change the server-wide default engine.
10. Reopen the application and resume the original scheduler/workers only after verification passes. Preserve backup and evidence. If any migration/check fails, keep maintenance in place, diagnose, and use a separately reviewed forward fix or recovery plan; do not invoke migration rollback or reset commands.

**Current stopping point:** completed isolated testing and prepared source changes. No live migration, reset, deployment, production configuration-cache refresh, queue restart or live data change was executed.
