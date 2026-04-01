# Loyalty legacy data — backfill

## Current product state

- **Canonical ledger:** `guest_cards`, `guest_loyalty_accounts`, `guest_loyalty_tx`.
- **Production flows** (QR accrual, wallet, cabinet, staff issue/scan, analytics) use only this ledger. They do **not** read from `loyalty_accounts` or `loyalty_transactions`.
- Legacy tables (`loyalty_accounts`, `loyalty_transactions`) are **not written by QR** and **not read by wallet, history, or analytics**.

## Is migration/backfill required?

**No.** For current product state, no automatic backfill or migration from legacy to canonical ledger is required. New accrual and all balance/history reads go to the canonical ledger only.

If you have historical balances only in legacy tables (e.g. from pre–guest-wallet behaviour), those balances are not shown in the current wallet or cabinet. To restore them you would need a **one-time, manually run** script that:

- Maps legacy accounts to guests (e.g. by phone)
- Inserts or updates `guest_loyalty_accounts` and optionally `guest_loyalty_tx` with appropriate care (idempotency, no double-credit).

Such a script is **not** included by default; add it only if there is a clear need and run it with caution.
