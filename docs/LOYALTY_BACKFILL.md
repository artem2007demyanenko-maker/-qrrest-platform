# Loyalty legacy backfill

One-time migration of historical balances from legacy loyalty tables into the canonical guest loyalty ledger. Use this only when you have existing production data in legacy tables that must appear in the current wallet/cabinet.

## When backfill is needed

- **Canonical ledger** (production): `guest_loyalty_accounts`, `guest_loyalty_tx`, `guest_cards`.
- **Legacy tables**: `loyalty_accounts`, `loyalty_transactions` (no longer written by QR or read by wallet/cabinet).

Run the backfill when:

- You switched to the canonical ledger and have **historical balances** only in `loyalty_accounts` (by phone or by card).
- You want those balances to appear in the guest wallet and cabinet without manual re-accrual.

Do **not** run it if:

- You have no legacy data, or
- All relevant guests already have canonical balances (script will skip them).

## How to run it

1. **From project root** (where `app/` and `scripts/` live):

   ```bash
   php scripts/loyalty_backfill.php --dry-run
   ```

   This does **not** write to the DB. It reports how many rows would be skipped (no guest / canonical already exists) and how many would be inserted.

2. If the dry-run output looks correct:

   ```bash
   php scripts/loyalty_backfill.php
   ```

   The script inserts into `guest_loyalty_accounts` only. It does **not** update or overwrite existing canonical rows.

3. **Safe to re-run**: Running again skips any `(guest_id, restaurant_id)` that already has a row in `guest_loyalty_accounts`, so it will not duplicate or overwrite.

## How to verify migrated balances

1. **Count canonical rows** (per restaurant or total):

   ```sql
   SELECT restaurant_id, COUNT(*) AS accounts, SUM(balance) AS total_balance
   FROM guest_loyalty_accounts
   GROUP BY restaurant_id;
   ```

2. **Spot-check a guest**: Pick a phone that had legacy balance. Ensure that guest exists in `guests` (same normalized phone). Then:

   ```sql
   SELECT g.id AS guest_id, g.phone,
          gla.restaurant_id, gla.balance
   FROM guests g
   JOIN guest_loyalty_accounts gla ON gla.guest_id = g.id
   WHERE g.phone = '+79XXXXXXXXX';
   ```

   Compare with the legacy balance (if you still have `loyalty_accounts` with `restaurant_id` + `phone` / `points_balance`).

3. **In the app**: Log in as that guest, open the wallet or cabinet for the restaurant; the balance should match the migrated value.

## Migration strategy

| Legacy source | Mapping | Behaviour |
|--------------|--------|-----------|
| **Phone-based** `loyalty_accounts` (`restaurant_id`, `phone`, `points_balance`) | Phone normalized (same as app); lookup `guests.id` by `guests.phone`. | For each legacy row: if no guest with that phone → skip. If `guest_loyalty_accounts` already has `(guest_id, restaurant_id)` → skip. Else **INSERT** one row with legacy balance. |
| **Card-based** `loyalty_accounts` (`card_id`, `balance`) | Join to `guest_cards` to get `guest_id`, `restaurant_id`. | For each row: if `guest_loyalty_accounts` already has `(guest_id, restaurant_id)` → skip. Else **INSERT** one row with legacy balance. |

- **No overwrite**: Existing canonical balances are never updated by this script.
- **No duplicate rows**: Insert is only when there is no existing `guest_loyalty_accounts` row for that `(guest_id, restaurant_id)`.
- **Balance-only**: Transaction history in `loyalty_transactions` is **not** copied into `guest_loyalty_tx`. Only the balance is migrated into `guest_loyalty_accounts`.

## Limitations

1. **Phone-based**: Only rows whose `phone` matches a normalized `guests.phone` are migrated. Legacy accounts whose phone is not in `guests` are skipped (reported as `skipped_no_guest`).
2. **Transaction history**: Legacy `loyalty_transactions` are not migrated. Only balances are moved. History in the guest cabinet will show new activity after migration, not old legacy accruals/spends.
3. **Two legacy schemas**: If both phone-based and card-based legacy rows exist for the same guest/restaurant, the script runs phone-based first; the card-based pass will then skip (canonical already exists). The migrated balance is the one from the first applicable source.
4. **Column names**: The script checks for `points_balance` or `balance` in `loyalty_accounts` and uses whatever exists.
5. **One-time use**: Intended for a single run (or rare re-runs) to bring legacy data over. Ongoing accrual and spend use only the canonical ledger.

## Files

- **Script**: `scripts/loyalty_backfill.php` (CLI, `--dry-run` supported).
- **Canonical tables**: `guest_loyalty_accounts`, `guest_loyalty_tx`, `guest_cards`.
- **Legacy tables** (read-only): `loyalty_accounts` (and optionally `loyalty_transactions` for reference; not written by this script).
