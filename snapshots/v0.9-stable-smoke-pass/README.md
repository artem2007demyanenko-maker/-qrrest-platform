# Snapshot: v0.9-stable-smoke-pass

- Created: `2026-05-08`
- Purpose: production stabilization baseline after controlled smoke-test pass.

## Structure

- `critical_files/` — copies of critical runtime and flow files.
- `manifests/git-tracked-files.txt` — tracked files list at snapshot time.
- `manifests/critical-flow-files.txt` — explicit critical flow map.
- `manifests/critical-files.sha256` — integrity checksums for copied critical files.

## Restore guidance

1. Validate checksum file before restore.
2. Restore only targeted files for hotfix rollback.
3. Re-run:
   - `php -l` on restored PHP files
   - `/health.php`
   - `/ready.php`
   - core smoke (`qr`, `kitchen_api`, `orders_api`, `dashboard`)
