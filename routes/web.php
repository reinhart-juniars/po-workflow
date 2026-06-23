<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminAppController;
use App\Http\Controllers\ProductionAppController;
use App\Http\Controllers\DeliveryAppController;
use App\Http\Controllers\SalesAppController;
use App\Http\Controllers\OwnerAppController;
use App\Http\Controllers\AdminProductController;
use App\Http\Controllers\AdminCustomerController;
use App\Http\Controllers\OwnerUserController;
use App\Http\Controllers\SuperadminDashboardController;
use App\Http\Controllers\SuperadminBackupController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\AccountingAppController;
use App\Http\Controllers\CashAccountController;
use App\Http\Controllers\IncomeCategoryController;
use App\Http\Controllers\InventoryItemController;
use App\Http\Controllers\InventoryOpeningController;
use App\Http\Controllers\InventoryPurchaseController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\InventoryUsageReportController;
use App\Http\Controllers\ProfitLossReportController;
use App\Http\Controllers\BalanceSheetReportController;
use App\Http\Controllers\FinalReportController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;

Route::middleware('web')->group(function () {
        // Halaman login
        Route::get('/', [AuthController::class, 'showLogin'])
            ->name('login');
        Route::get('/login',   [AuthController::class, 'showLogin']); // opsional, biar / & /login sama
        Route::post('/login', [AuthController::class, 'login'])
            ->name('login.submit');
        Route::post('/', [AuthController::class, 'login']);

        // Logout    
        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth')->name('logout');

        // 🔹 Dispatcher setelah login
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware(['auth', 'force.password.change'])
            ->name('dashboard');
    });

Route::middleware(['web', 'guest'])->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])
        ->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware(['web', 'auth', 'force.password.change'])->group(function () {
    Route::get('/profile', [UserProfileController::class, 'edit'])
        ->name('profile.edit');

    Route::patch('/profile', [UserProfileController::class, 'update'])
        ->name('profile.update');
    Route::delete('/profile', [UserProfileController::class, 'destroy'])
        ->name('profile.destroy');

    Route::put('/profile/password', [UserProfileController::class, 'updatePassword'])
        ->name('profile.password.update');
});

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('/confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');
    Route::post('/confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('/password', [PasswordController::class, 'update'])
        ->name('password.update');
});

Route::middleware(['web','auth','force.password.change','ensure.role:admin|owner|superadmin'])
    ->prefix('admin-app')
    ->name('adminapp.')
    ->group(function () {

        // Route Dashboard    
        Route::get('/',               [AdminAppController::class, 'dashboard'])->name('dashboard');
        Route::get('/dashboard', function () {
            return redirect()->route('adminapp.dashboard');
        });

        // Route Orders
        Route::get('/orders',         [AdminAppController::class, 'ordersIndex'])->name('orders.index');
        Route::get('/orders/{po}',    [AdminAppController::class, 'ordersShow'])->name('orders.show');
        Route::post('/orders',        [AdminAppController::class, 'ordersStore'])->name('orders.store');

        // Edit & Update PO
        Route::get('/orders/{po}/edit', [AdminAppController::class, 'ordersEdit'])->name('orders.edit');
        Route::put('/orders/{po}',      [AdminAppController::class, 'ordersUpdate'])->name('orders.update');

        // Delete PO
        Route::delete('/orders/{po}', [AdminAppController::class, 'ordersDestroy'])->name('orders.destroy');

        // Route SPK
        Route::get('/spk',            [AdminAppController::class, 'spkIndex'])->name('spk.index');
        Route::post('/spk',           [AdminAppController::class, 'spkStore'])->name('spk.store');

        // Route Delivery
        Route::get('/delivery',  [AdminAppController::class, 'deliveryIndex'])->name('delivery.index');
        Route::post('/delivery', [AdminAppController::class, 'deliveryStore'])->name('delivery.store');

        // MASTER PRODUCTS
        Route::get('/products',                [AdminProductController::class, 'index'])->name('products.index');
        Route::get('/products/create',         [AdminProductController::class, 'create'])->name('products.create');
        Route::get('/products/export/excel',   [AdminProductController::class, 'exportExcel'])->name('products.export.excel');
        Route::post('/products/import/excel',  [AdminProductController::class, 'importExcel'])->name('products.import.excel');
        Route::post('/products/import/excel/commit', [AdminProductController::class, 'commitImportExcel'])->name('products.import.excel.commit');
        Route::post('/products/import/excel/cancel', [AdminProductController::class, 'cancelImportExcel'])->name('products.import.excel.cancel');
        Route::post('/products',               [AdminProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [AdminProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}',      [AdminProductController::class, 'update'])->name('products.update');

        // MASTER CUSTOMER
        Route::get('/customers',                 [AdminCustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create',          [AdminCustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers',                [AdminCustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [AdminCustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}',      [AdminCustomerController::class, 'update'])->name('customers.update');

        // LAPORAN MENU TANPA HPP/OHC
        Route::get('/reports/missing-costs', [AdminAppController::class, 'ordersMissingCosts'])
            ->name('reports.missing-costs');
        Route::get('/reports/missing-costs/export/excel', [AdminAppController::class, 'exportMissingCostsExcel'])
            ->name('reports.missing-costs.export.excel');

        // LAPORAN PO
        Route::get('/reports/orders', [AdminAppController::class, 'ordersReport'])
            ->name('reports.orders');
        Route::get('/reports/orders/export/excel', [AdminAppController::class, 'exportOrdersExcel'])
            ->name('reports.orders.export.excel');
        Route::get('/reports/orders/export/pdf', [AdminAppController::class, 'exportOrdersPdf'])
            ->name('reports.orders.export.pdf');

        // LAPORAN BEST SELLER PER CUSTOMER
        Route::get('/reports/bestseller', [AdminAppController::class, 'bestSellerReport'])
            ->name('reports.bestseller');
        Route::get('/reports/bestseller/export/excel', [AdminAppController::class, 'exportBestSellerExcel'])
            ->name('reports.bestseller.export.excel');
        Route::get('/reports/bestseller/export/pdf', [AdminAppController::class, 'exportBestSellerPdf'])
            ->name('reports.bestseller.export.pdf');

        // LAPORAN PRODUKSI (SPK)
        Route::get('/reports/production', [AdminAppController::class, 'productionReport'])
            ->name('reports.production');
        Route::get('/reports/production/export/excel', [AdminAppController::class, 'exportProductionExcel'])
            ->name('reports.production.export.excel');
        Route::get('/reports/production/export/pdf', [AdminAppController::class, 'exportProductionPdf'])
            ->name('reports.production.export.pdf');

        // LAPORAN DELIVERY
        Route::get('/reports/delivery', [AdminAppController::class, 'deliveryReport'])
            ->name('reports.delivery');
        Route::get('/reports/delivery/export/excel', [AdminAppController::class, 'exportDeliveryExcel'])
            ->name('reports.delivery.export.excel');
        Route::get('/reports/delivery/export/pdf', [AdminAppController::class, 'exportDeliveryPdf'])
            ->name('reports.delivery.export.pdf');

        // AUDIT LOGS (khusus owner & superadmin)
        Route::get('/audit-logs', [AdminAppController::class, 'auditIndex'])
            ->middleware('ensure.role:owner,superadmin,admin')
            ->name('audit.index');
    });

Route::middleware(['web','auth','force.password.change','ensure.role:production|owner|superadmin'])
    ->prefix('production-app')
    ->name('productionapp.')
    ->group(function () {

        Route::get('/',                 [ProductionAppController::class, 'dashboard'])->name('dashboard');
        Route::get('/dashboard', function () {
            return redirect()->route('productionapp.dashboard');
        });

        // detail PO (progress)
        Route::get('/orders/{po}',      [ProductionAppController::class, 'show'])->name('orders.show');
        Route::put('/orders/{po}',      [AdminAppController::class, 'ordersUpdate'])->name('orders.update');

        // ubah status menjadi completed
        Route::post('/orders/{po}/complete', [ProductionAppController::class, 'complete'])->name('orders.complete');

        // batalkan PO (customer membatalkan)
        Route::post('/orders/{po}/cancel', [ProductionAppController::class, 'cancel'])->name('orders.cancel');
    });

Route::middleware(['web','auth','force.password.change','ensure.role:delivery|owner|superadmin'])
    ->prefix('delivery-app')
    ->name('deliveryapp.')
    ->group(function () {
        Route::get('/',                    [DeliveryAppController::class, 'dashboard'])->name('dashboard');
        Route::get('/dashboard', function () {
            return redirect()->route('deliveryapp.dashboard');
        });

        // Detail DO
        Route::get('/orders/{do}',         [DeliveryAppController::class, 'show'])->name('orders.show');

        // Transisi status
        Route::post('/orders/{do}/start',  [DeliveryAppController::class, 'start'])
            ->name('orders.start');     // ready -> on_delivery
        Route::post('/orders/{do}/cancel', [DeliveryAppController::class, 'cancel'])
            ->name('orders.cancel');   // on_delivery -> ready
        Route::post('/orders/{do}/done',   [DeliveryAppController::class, 'complete'])
            ->name('orders.done');   // on_delivery -> delivered
    });

Route::middleware(['web','auth','force.password.change','ensure.role:sales|owner|superadmin'])
    ->prefix('sales-app')
    ->name('salesapp.')
    ->group(function () {
        Route::get('/', [SalesAppController::class, 'dashboard'])->name('dashboard');
        Route::get('/dashboard', function () {
            return redirect()->route('salesapp.dashboard');
        });
        Route::get('/actuals/{salesActual}', [SalesAppController::class, 'edit'])->name('actuals.edit');
        Route::put('/actuals/{salesActual}', [SalesAppController::class, 'update'])->name('actuals.update');
        Route::post('/actuals/{salesActual}/submit', [SalesAppController::class, 'submit'])->name('actuals.submit');
        Route::get('/reports', function () {
            return redirect()->route('salesapp.reports.final-retur');
        })->name('reports');
        Route::get('/reports/final-retur', [SalesAppController::class, 'reports'])->name('reports.final-retur');
        Route::get('/reports/waste', [SalesAppController::class, 'wasteReport'])->name('reports.waste');
        Route::get('/reports/waste/export/excel', [SalesAppController::class, 'exportWasteExcel'])->name('reports.waste.export.excel');
        Route::get('/reports/waste/export/pdf', [SalesAppController::class, 'exportWastePdf'])->name('reports.waste.export.pdf');
        Route::get('/reports/sales', [SalesAppController::class, 'salesReport'])->name('reports.sales');
        Route::get('/reports/sales/export/excel', [SalesAppController::class, 'exportSalesExcel'])->name('reports.sales.export.excel');
        Route::get('/reports/sales/export/pdf', [SalesAppController::class, 'exportSalesPdf'])->name('reports.sales.export.pdf');
    });

Route::middleware(['web', 'auth', 'force.password.change', 'ensure.role:superadmin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        Route::get('/dashboard', [SuperadminDashboardController::class, 'index'])
            ->name('dashboard');

        // BACKUP & RESTORE DATABASE
        Route::get('/backup', [SuperadminBackupController::class, 'index'])
            ->name('backup.index');
        Route::get('/backup/download', [SuperadminBackupController::class, 'download'])
            ->name('backup.download');
        Route::post('/backup/restore', [SuperadminBackupController::class, 'restore'])
            ->name('backup.restore');
    });

Route::middleware(['web', 'auth', 'force.password.change', 'ensure.role:owner|superadmin|admin'])
    ->prefix('owner-app')
    ->name('ownerapp.')
    ->group(function () {

        // DASHBOARD RINGKASAN
        Route::get('/',          [OwnerAppController::class, 'dashboard'])->name('dashboard');
        Route::get('/dashboard', function () {
            return redirect()->route('ownerapp.dashboard');
        });

        // MASTER USER (khusus owner & superadmin)
        Route::get('/users', [OwnerUserController::class, 'index'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('users.index');
        Route::post('/users', [OwnerUserController::class, 'store'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('users.store');
        Route::put('/users/{user}', [OwnerUserController::class, 'update'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('users.update');
        // 🔥 RESET PASSWORD
        Route::patch('/users/{user}/reset-password',
            [OwnerUserController::class, 'resetPassword'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('users.reset-password');
        // (opsional) NONAKTIFKAN USER
        Route::patch('/users/{user}/deactivate',
            [OwnerUserController::class, 'deactivate'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('users.deactivate');
        Route::delete('/users/{user}', [OwnerUserController::class, 'destroy'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('users.destroy');

        Route::get('/audit-logs', [OwnerAppController::class, 'auditIndex'])
            ->middleware('ensure.role:owner,superadmin,admin')
            ->name('audit.index');

        Route::get('/hpp-analysis', [OwnerAppController::class, 'hppAnalysis'])
            ->middleware('ensure.role:owner,superadmin,admin')
            ->name('hpp-analysis');

        // Shortcuts
        Route::get('/open/admin-app',      fn () => redirect('/admin-app'))->name('open.admin');
        Route::get('/open/production-app', fn () => redirect('/production-app'))->name('open.production');
        Route::get('/open/delivery-app',   fn () => redirect('/delivery-app'))->name('open.delivery');
    });

Route::middleware(['web', 'auth', 'ensure.role:accounting|owner|superadmin'])
    ->prefix('accounting-app')
    ->name('accountingapp.')
    ->group(function () {

        Route::get('/', [AccountingAppController::class, 'dashboard'])->name('dashboard');
        Route::get('/dashboard', function () {
            return redirect()->route('accountingapp.dashboard');
        });

        // Cash out / pengeluaran
        Route::get('/expenses', [AccountingAppController::class, 'expensesIndex'])->name('expenses.index');
        Route::post('/expenses', [AccountingAppController::class, 'expensesStore'])->name('expenses.store');
        Route::get('/other-incomes', [AccountingAppController::class, 'otherIncomesIndex'])->name('other-incomes.index');
        Route::post('/other-incomes', [AccountingAppController::class, 'otherIncomesStore'])->name('other-incomes.store');
        Route::get('/other-incomes/{otherIncome}/edit', [AccountingAppController::class, 'otherIncomesEdit'])->name('other-incomes.edit');
        Route::put('/other-incomes/{otherIncome}', [AccountingAppController::class, 'otherIncomesUpdate'])->name('other-incomes.update');
        Route::delete('/other-incomes/{otherIncome}', [AccountingAppController::class, 'otherIncomesDestroy'])->name('other-incomes.destroy');
        Route::get('/sales-closings', [AccountingAppController::class, 'salesClosingsIndex'])->name('sales-closings.index');
        Route::post('/sales-closings', [AccountingAppController::class, 'salesClosingsStore'])->name('sales-closings.store');
        Route::get('/opening-balances', [AccountingAppController::class, 'openingBalancesIndex'])->name('opening-balances.index');
        Route::post('/opening-balances', [AccountingAppController::class, 'openingBalancesStore'])->name('opening-balances.store');
        Route::get('/opening-balances/{openingBalance}/edit', [AccountingAppController::class, 'openingBalancesEdit'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('opening-balances.edit');
        Route::put('/opening-balances/{openingBalance}', [AccountingAppController::class, 'openingBalancesUpdate'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('opening-balances.update');
        Route::get('/balance-sheet-adjustments', [AccountingAppController::class, 'balanceSheetAdjustmentsIndex'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('balance-sheet-adjustments.index');
        Route::post('/balance-sheet-adjustments', [AccountingAppController::class, 'balanceSheetAdjustmentsStore'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('balance-sheet-adjustments.store');
        Route::get('/balance-sheet-adjustments/{balanceSheetAdjustment}/edit', [AccountingAppController::class, 'balanceSheetAdjustmentsEdit'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('balance-sheet-adjustments.edit');
        Route::put('/balance-sheet-adjustments/{balanceSheetAdjustment}', [AccountingAppController::class, 'balanceSheetAdjustmentsUpdate'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('balance-sheet-adjustments.update');
        Route::delete('/balance-sheet-adjustments/{balanceSheetAdjustment}', [AccountingAppController::class, 'balanceSheetAdjustmentsDestroy'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('balance-sheet-adjustments.destroy');
        Route::get('/profit-loss-adjustments', [AccountingAppController::class, 'profitLossAdjustmentsIndex'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('profit-loss-adjustments.index');
        Route::post('/profit-loss-adjustments', [AccountingAppController::class, 'profitLossAdjustmentsStore'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('profit-loss-adjustments.store');
        Route::get('/profit-loss-adjustments/{profitLossAdjustment}/edit', [AccountingAppController::class, 'profitLossAdjustmentsEdit'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('profit-loss-adjustments.edit');
        Route::put('/profit-loss-adjustments/{profitLossAdjustment}', [AccountingAppController::class, 'profitLossAdjustmentsUpdate'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('profit-loss-adjustments.update');
        Route::delete('/profit-loss-adjustments/{profitLossAdjustment}', [AccountingAppController::class, 'profitLossAdjustmentsDestroy'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('profit-loss-adjustments.destroy');
        Route::get('/period-closings', [AccountingAppController::class, 'periodClosingsIndex'])->name('period-closings.index');
        Route::post('/period-closings', [AccountingAppController::class, 'periodClosingsStore'])->name('period-closings.store');
        Route::delete('/period-closings/{periodClosing}', [AccountingAppController::class, 'periodClosingsDestroy'])->name('period-closings.destroy');
        Route::get('/periods', [AccountingAppController::class, 'periodsIndex'])->name('periods.index');
        Route::get('/periods/export/excel', [AccountingAppController::class, 'exportReceivablesExcel'])->name('periods.export.excel');
        Route::get('/periods/export/pdf', [AccountingAppController::class, 'exportReceivablesPdf'])->name('periods.export.pdf');
        Route::post('/periods/{po}/complete', [AccountingAppController::class, 'periodsComplete'])->name('periods.complete');
        Route::get('/payables', [AccountingAppController::class, 'payablesIndex'])->name('payables.index');
        Route::get('/master-categories', [AccountingAppController::class, 'categoriesIndex'])->name('categories.index');
        Route::post('/master-categories', [AccountingAppController::class, 'categoriesStore'])->name('categories.store');
        Route::put('/master-categories/{category}', [AccountingAppController::class, 'categoriesUpdate'])->name('categories.update');
        Route::delete('/master-categories/{category}', [AccountingAppController::class, 'categoriesDestroy'])->name('categories.destroy');
        Route::resource('cash-accounts', CashAccountController::class)->except(['show']);
        Route::get('/cash-account-transfers', [AccountingAppController::class, 'cashAccountTransfersIndex'])
            ->name('cash-account-transfers.index');
        Route::post('/cash-account-transfers', [AccountingAppController::class, 'cashAccountTransfersStore'])
            ->name('cash-account-transfers.store');
        Route::delete('/cash-account-transfers/{cashAccountTransfer}', [AccountingAppController::class, 'cashAccountTransfersDestroy'])
            ->name('cash-account-transfers.destroy');
        Route::resource('income-categories', IncomeCategoryController::class)->except(['show']);
        Route::resource('inventory-items', InventoryItemController::class)->except(['show']);
        Route::resource('inventory-openings', InventoryOpeningController::class)->except(['show']);
        Route::resource('inventory-purchases', InventoryPurchaseController::class)->except(['show', 'destroy']);
        Route::delete('/inventory-purchases/{inventoryPurchase}', [InventoryPurchaseController::class, 'destroy'])
            ->middleware('ensure.role:owner,superadmin')
            ->name('inventory-purchases.destroy');
        Route::resource('stock-opnames', StockOpnameController::class)->except(['show']);

        // optional: edit & update pengeluaran
        Route::get('/expenses/{cashOut}/edit', [AccountingAppController::class, 'expensesEdit'])->name('expenses.edit');
        Route::put('/expenses/{cashOut}', [AccountingAppController::class, 'expensesUpdate'])->name('expenses.update');
        Route::delete('/expenses/{cashOut}', [AccountingAppController::class, 'expensesDestroy'])->name('expenses.destroy');

        // laporan ringkas
        Route::get('/reports/cashflow', [AccountingAppController::class, 'cashflowReport'])->name('reports.cashflow');
        Route::get('/reports/cashflow/export/excel', [AccountingAppController::class, 'exportCashflowExcel'])->name('reports.cashflow.export.excel');
        Route::get('/reports/cashflow/export/pdf', [AccountingAppController::class, 'exportCashflowPdf'])->name('reports.cashflow.export.pdf');
        Route::get('/reports/sales', [AccountingAppController::class, 'salesReport'])->name('reports.sales');
        Route::get('/reports/sales/export/excel', [AccountingAppController::class, 'exportSalesExcel'])->name('reports.sales.export.excel');
        Route::get('/reports/sales/export/pdf', [AccountingAppController::class, 'exportSalesPdf'])->name('reports.sales.export.pdf');
        Route::get('/reports/profit-loss', [ProfitLossReportController::class, 'index'])->name('reports.profit-loss');
        Route::get('/reports/profit-loss/export/excel', [ProfitLossReportController::class, 'exportExcel'])->name('reports.profit-loss.export.excel');
        Route::get('/reports/profit-loss/export/pdf', [ProfitLossReportController::class, 'exportPdf'])->name('reports.profit-loss.export.pdf');
        Route::get('/reports/profit-loss/yearly', [ProfitLossReportController::class, 'yearly'])->name('reports.profit-loss.yearly');
        Route::get('/reports/profit-loss/yearly/export/excel', [ProfitLossReportController::class, 'exportYearlyExcel'])->name('reports.profit-loss.yearly.export.excel');
        Route::get('/reports/profit-loss/yearly/export/pdf', [ProfitLossReportController::class, 'exportYearlyPdf'])->name('reports.profit-loss.yearly.export.pdf');
        Route::get('/reports/balance-sheet', [BalanceSheetReportController::class, 'index'])->name('reports.balance-sheet');
        Route::get('/reports/balance-sheet/export/excel', [BalanceSheetReportController::class, 'exportExcel'])->name('reports.balance-sheet.export.excel');
        Route::get('/reports/balance-sheet/export/pdf', [BalanceSheetReportController::class, 'exportPdf'])->name('reports.balance-sheet.export.pdf');
        Route::get('/reports/final', [FinalReportController::class, 'index'])->name('reports.final');
        Route::get('/reports/final/export/excel', [FinalReportController::class, 'exportExcel'])->name('reports.final.export.excel');
        Route::get('/reports/final/export/pdf', [FinalReportController::class, 'exportPdf'])->name('reports.final.export.pdf');
        Route::get('/reports/inventory-usage', [InventoryUsageReportController::class, 'index'])->name('reports.inventory-usage');
        Route::get('/reports/inventory-usage/export/excel', [InventoryUsageReportController::class, 'exportExcel'])->name('reports.inventory-usage.export.excel');
        Route::get('/reports/inventory-usage/export/pdf', [InventoryUsageReportController::class, 'exportPdf'])->name('reports.inventory-usage.export.pdf');
    });

Route::middleware(['web', 'auth'])->prefix('financial')->name('financial.')->group(function () {
    Route::get('/dashboard', [FinancialController::class, 'dashboard'])->name('dashboard');

    Route::get('/expenses', [FinancialController::class, 'expensesIndex'])->name('expenses.index');
    Route::post('/expenses', [FinancialController::class, 'expensesStore'])->name('expenses.store');
});
