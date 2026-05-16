# Release Notes

## v0.9-stable-smoke-pass

- Snapshot date (UTC): `2026-05-08T11:44:21Z`
- Baseline commit: `b3083ba23392eef867fee558319aafc3b2e03c39`
- Release scope: stabilization, runtime hardening, production smoke-pass.

### Included stabilization outcomes

- Fixed missing runtime function path for combo suggestions (`get_combo_suggestions()` availability).
- Hardened KDS legacy compatibility (qty/quantity, payment field fallback, total fallback).
- Reduced runtime schema duplicate-spam via idempotent schema bootstrap checks.
- Smoke-validated operational flows (guest order creation, kitchen queue visibility, waiter updates, dashboard availability).

### Snapshot artifacts

- Snapshot root: `snapshots/v0.9-stable-smoke-pass/`
- Critical file backups: `snapshots/v0.9-stable-smoke-pass/critical_files/`
- Tracked files manifest: `snapshots/v0.9-stable-smoke-pass/manifests/git-tracked-files.txt`
- Critical flow list: `snapshots/v0.9-stable-smoke-pass/manifests/critical-flow-files.txt`
- SHA256 checksums: `snapshots/v0.9-stable-smoke-pass/manifests/critical-files.sha256`

### Notes

- This release intentionally does not introduce new product features.
- Focus is production safety, backward compatibility, and operational resilience.
