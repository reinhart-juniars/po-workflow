# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

PO-workflow — purchase-order workflow plus light accounting for an Indonesian business: POs → SPK (work orders) → delivery orders → proof of delivery → sales actuals/daily closings, alongside inventory, cash accounts, expenses, payables, and balance-sheet/P&L reporting with period closings. Stack: Laravel 12 (PHP 8.2), MySQL (`po_workflow`). Tests run on SQLite `:memory:`.

## Two UI layers

1. **Blade + Alpine.js app** (the main product) — routes in `routes/web.php` (~28 KB, the app's central map), organized into role-scoped areas named `owner-app`, `admin-app`, `sales-app`, `delivery-app`, `production-app`, `accounting-app`, guarded by `ensure.role:` middleware (`EnsureHasAnyRole`) on top of spatie/laravel-permission roles.
2. **Filament v3 admin panel** at `/admin` (`app/Providers/Filament/AdminPanelProvider`, Filament's own login) — resources for Customer, DeliveryOrder, Product, PurchaseOrder, Spk, User in `app/Filament/Resources/`. Access control via bezhansalleh/filament-shield, which generates the policies in `app/Policies/`.

Most day-to-day feature work happens in the Blade app; check both layers when changing a model that has a Filament resource.

## Commands

- Serve: Laravel Herd serves the app at `http://po-workflow.test` — never start a server (`.env` `APP_URL` says `localhost:8000` but Herd is the actual serving path)
- Tests (Pest): `php artisan test --compact`, filter with `--filter=name` or a file path
- Format PHP after edits: `vendor/bin/pint --dirty`
- Frontend assets: `npm run dev` or `npm run build`
- `php artisan po:audit-cash-in {--fix}` — audits/repairs legacy cash-PO `cash_received_at` data (defined inline in `routes/console.php`)

## Architecture notes

- **Document numbering** goes through `Services/AutoNumberService` — use it for any new numbered document type (PO, SPK, DO, …) instead of ad-hoc counters.
- **Financial reporting** is built by `Services/BalanceSheetService`, `Services/SalesReportService`, and `Services/SalesActualService`, with manual adjustment models (`BalanceSheetAdjustment`, `ProfitLossAdjustment`) and `PeriodClosing` snapshots. Opening balances/inventory have dedicated models (`OpeningBalance`, `OpeningInventory`, `InventoryOpening`).
- **Inventory consumption** is computed in `Services/InventoryUsageService`; stock corrections via `StockOpname`.
- `Services/DatabaseBackupService` handles DB backups.
- `AuditLog` records mutations; `ForcePasswordChange` middleware blocks users flagged for a password reset.
- Excel import/export via maatwebsite/excel (`app/Exports`, `app/Imports`); PDFs via barryvdh/laravel-dompdf. Follow these patterns for any new report.
- UI text is Bahasa Indonesia (helpers in `App\Support\UiLabel`) — keep new user-facing text in Indonesian.
