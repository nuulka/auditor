# Revizor Asszisztens 1.0 — AI Context

## Project Overview
OTS bank reconciliation automation tool. Matches bank CSV statements against OTS accounting records.

## Tech Stack
- PHP 8.3 procedural (no composer/PDO/ORM/MVC)
- MySQL 8 (two DBs: `revizor_db` + `ots` read-only)
- No Bootstrap on WebIX pages (`all_transactions_multi.php`)
- Locally hosted on Laragon (Windows)

## Key Rules
- Only edit `D:\laragon\www\revizor\` (OTS is read-only at `D:\laragon\www\ots\`)
- Native MySQLi, no abstractions
- `CASH_DOCUMENT_NUMBER` is unreliable — use `ots_record_id` for joins
- Expense sign: `IF(TYPE IN (7,9,20), -1 * AMOUNT, AMOUNT)`
- TET accounts: `church_id = 0 → continue` on upload
- URLs: `localhost/revizor/`, `localhost/ots/`

## Database Structure
- **OTS DB**: `TRANSACTIONS` (434k records, 73 churches), `churches`, `PERSONS`, `NAMES_OF_TRANSACTION`, `TRANSACTION_TYPE`, `funds`, `USERS`, `transfers_to_conference`, `church_bank_accounts`, `provider_keywords`
- **Revizor DB**: `bank_reconciliation`, `bank_reconciliation_items`, `church_bank_accounts`, `provider_keywords`, `custom_patterns`, `audit_checklist`

## Session
- OTS timeout: 10 min, Revizor: 20 min
- Shared PHP session (same `PHPSESSID` cookie)
- AJAX session ping via `session_ping.php`

## Key Files
- `index.php` — Dashboard/menu
- `reconciliation.php` — Main reconciliation UI (table, modal, auto-match)
- `upload.php` — CSV upload + progressive matching (passes: 0,3,6,12,35,60,text)
- `search.php` — Server-side transaction search with reverse OTS→Bank lookup
- `document_check.php` — Document audit checklist (13 items)
- `document_check_get.php` — AJAX endpoint for document check
- `login.php` / `logout.php` — Auth
- `help.php` — User guide
- `session_ping.php` — AJAX session extender
- `all_transactions/all_transactions_multi.php` — WebIX OTS query tool
- `print.html` — Print view

## Git
- Branch: `main`
- Remote: `origin/main`
- English filenames for new files
- Development helpers moved to `_dev/`
