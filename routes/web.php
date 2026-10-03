<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AjaxController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankTransactionController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\CounterpartyController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DealPaymentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceApprovalController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesDocumentController;
use App\Http\Controllers\Settings;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/* ---------- guests ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [Auth\LoginController::class, 'show'])->name('login');
    Route::post('/login', [Auth\LoginController::class, 'store'])->middleware('throttle:login');
    Route::get('/two-factor-challenge', [Auth\LoginController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor-challenge', [Auth\LoginController::class, 'verifyChallenge'])->middleware('throttle:login')->name('two-factor.verify');

    Route::get('/register', [Auth\RegisterController::class, 'show'])->name('register');
    Route::post('/register', [Auth\RegisterController::class, 'store'])->middleware('throttle:login');

    Route::get('/forgot-password', [Auth\PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [Auth\PasswordController::class, 'email'])->middleware('throttle:login')->name('password.email');
    Route::get('/reset-password/{token}', [Auth\PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [Auth\PasswordController::class, 'update'])->middleware('throttle:login')->name('password.update');

    Route::get('/invitation/{token}', [Auth\InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation/{token}', [Auth\InvitationController::class, 'store'])->middleware('throttle:login')->name('invitation.accept');
});

Route::post('/logout', [Auth\LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/* ---------- platform owner ---------- */
Route::middleware(['auth', 'superadmin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [Admin\AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/companies', [Admin\AdminController::class, 'companies'])->name('companies.index');
    Route::get('/companies/{company}', [Admin\AdminController::class, 'company'])->name('companies.show');
    Route::put('/companies/{company}', [Admin\AdminController::class, 'updateCompany'])->name('companies.update');
    Route::get('/plans', [Admin\AdminController::class, 'plans'])->name('plans.index');
    Route::post('/plans', [Admin\AdminController::class, 'storePlan'])->name('plans.store');
    Route::put('/plans/{plan}', [Admin\AdminController::class, 'updatePlan'])->name('plans.update');
    Route::get('/logs', [Admin\AdminController::class, 'logs'])->name('logs');
});

/* ---------- tenant application ---------- */
Route::middleware(['auth', 'tenant'])->group(function () {
    Route::get('/subscription/expired', [Settings\SubscriptionController::class, 'expired'])->name('subscription.expired');

    Route::get('/', [DashboardController::class, 'index'])->middleware('can:dashboard.view')->name('dashboard');
    Route::post('/dashboard/layout', [DashboardController::class, 'saveLayout'])->name('dashboard.layout');

    Route::get('/my-work', [MyWorkController::class, 'index'])->name('my-work');
    Route::post('/reminders', [MyWorkController::class, 'storeReminder'])->name('reminders.store');
    Route::post('/reminders/{reminder}/done', [MyWorkController::class, 'doneReminder'])->name('reminders.done');
    Route::delete('/reminders/{reminder}', [MyWorkController::class, 'destroyReminder'])->name('reminders.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::post('/profile/theme', [ProfileController::class, 'theme'])->name('profile.theme');
    Route::post('/profile/two-factor', [ProfileController::class, 'enableTwoFactor'])->name('profile.2fa.enable');
    Route::post('/profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.2fa.confirm');
    Route::delete('/profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.2fa.disable');
    Route::post('/profile/sessions/logout-others', [ProfileController::class, 'logoutOthers'])->name('profile.sessions.others');

    Route::prefix('ajax')->name('ajax.')->middleware('throttle:ajax')->group(function () {
        Route::get('/ticker', [AjaxController::class, 'ticker'])->name('ticker');
        Route::get('/my-day', [AjaxController::class, 'myDay'])->name('my-day');
        Route::get('/search', [AjaxController::class, 'search'])->name('search');
        Route::get('/rate', [AjaxController::class, 'rate'])->name('rate');
        Route::get('/cross-rate', [AjaxController::class, 'cross'])->name('cross-rate');
        Route::get('/lookup/{type}', [AjaxController::class, 'lookup'])->whereIn('type', ['counterparties', 'contracts', 'projects', 'users'])->name('lookup');
        Route::post('/counterparties', [AjaxController::class, 'storeCounterparty'])->name('counterparties.store');
        Route::post('/tasks/{task}/complete', [AjaxController::class, 'completeTask'])->name('tasks.complete');
        Route::post('/reminders/{reminder}/read', [AjaxController::class, 'readReminder'])->name('reminders.read');
        Route::post('/reminders/{reminder}/snooze', [AjaxController::class, 'snoozeReminder'])->name('reminders.snooze');
    });

    Route::get('/currency', [CurrencyController::class, 'index'])->middleware('can:currency.view')->name('currency.index');

    /* Projects & tasks */
    Route::middleware('can:projects.view')->group(function () {
        Route::get('/projects/export', [ProjectController::class, 'export'])->middleware('can:projects.export')->name('projects.export');
        Route::resource('projects', ProjectController::class);
        Route::post('/projects/{project}/milestones', [ProjectController::class, 'storeMilestone'])->name('projects.milestones.store');
        Route::put('/projects/{project}/milestones/{milestone}', [ProjectController::class, 'updateMilestone'])->name('projects.milestones.update');
        Route::delete('/projects/{project}/milestones/{milestone}', [ProjectController::class, 'destroyMilestone'])->name('projects.milestones.destroy');

        // Tədarüklər (deals) and their invoices
        Route::get('/projects/{project}/deals/create', [DealController::class, 'create'])->name('deals.create');
        Route::post('/projects/{project}/deals', [DealController::class, 'store'])->name('deals.store');
        Route::get('/deals/{deal}', [DealController::class, 'show'])->name('deals.show');
        Route::get('/deals/{deal}/edit', [DealController::class, 'edit'])->name('deals.edit');
        Route::put('/deals/{deal}', [DealController::class, 'update'])->name('deals.update');
        Route::delete('/deals/{deal}', [DealController::class, 'destroy'])->name('deals.destroy');
        Route::post('/deals/{deal}/payments', [DealPaymentController::class, 'store'])->name('deals.payments.store');
        Route::delete('/deals/{deal}/payments/{payment}', [DealPaymentController::class, 'destroy'])->name('deals.payments.destroy');
        Route::get('/invoices/template', [InvoiceController::class, 'template'])->name('invoices.template');
        Route::post('/deals/{deal}/invoices/import', [InvoiceController::class, 'import'])->name('invoices.import');
        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::get('/invoices/{invoice}/export', [InvoiceController::class, 'export'])->name('invoices.export');
        Route::put('/invoices/{invoice}/status', [InvoiceController::class, 'status'])->name('invoices.status');
        Route::post('/invoices/{invoice}/logistics', [InvoiceController::class, 'logistics'])->name('invoices.logistics');
        Route::delete('/invoices/{invoice}/logistics', [InvoiceController::class, 'clearLogistics'])->name('invoices.logistics.clear');
        Route::post('/invoices/{invoice}/commission', [InvoiceController::class, 'commission'])->name('invoices.commission');
        Route::delete('/invoices/{invoice}/commission', [InvoiceController::class, 'clearCommission'])->name('invoices.commission.clear');
        Route::post('/invoices/{invoice}/rub', [InvoiceController::class, 'rub'])->name('invoices.rub');
        Route::delete('/invoices/{invoice}/rub', [InvoiceController::class, 'clearRub'])->name('invoices.rub.clear');
        Route::post('/invoices/{invoice}/documents', [InvoiceController::class, 'documents'])->name('invoices.documents');
        Route::post('/invoices/{invoice}/approval', [InvoiceApprovalController::class, 'submit'])->name('invoices.approval.submit');
        Route::post('/invoices/{invoice}/approval/decide', [InvoiceApprovalController::class, 'decide'])->name('invoices.approval.decide');
        Route::post('/invoices/{invoice}/approval/withdraw', [InvoiceApprovalController::class, 'withdraw'])->name('invoices.approval.withdraw');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');

        // Buyer's documents (proforma, specification): generated, then editable, PDF from the current state
        Route::get('/sales-documents/{document}', [SalesDocumentController::class, 'show'])->name('sales-documents.show');
        Route::put('/sales-documents/{document}', [SalesDocumentController::class, 'update'])->name('sales-documents.update');
        Route::post('/sales-documents/{document}/refresh', [SalesDocumentController::class, 'refresh'])->name('sales-documents.refresh');
        Route::get('/sales-documents/{document}/pdf', [SalesDocumentController::class, 'pdf'])->name('sales-documents.pdf');
        Route::delete('/sales-documents/{document}', [SalesDocumentController::class, 'destroy'])->name('sales-documents.destroy');

        Route::get('/tasks/export', [TaskController::class, 'export'])->middleware('can:projects.export')->name('tasks.export');
        Route::resource('tasks', TaskController::class);
        Route::post('/tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
        Route::post('/tasks/{task}/checklist', [TaskController::class, 'addChecklist'])->name('tasks.checklist.store');
        Route::post('/tasks/{task}/checklist/{item}', [TaskController::class, 'toggleChecklist'])->name('tasks.checklist.toggle');
        Route::delete('/tasks/{task}/checklist/{item}', [TaskController::class, 'destroyChecklist'])->name('tasks.checklist.destroy');
        Route::post('/tasks/{task}/comments', [TaskController::class, 'comment'])->name('tasks.comments.store');
        Route::post('/tasks/{task}/time', [TaskController::class, 'logTime'])->name('tasks.time.store');
    });

    /* CRM */
    Route::middleware('can:crm.view')->group(function () {
        Route::get('/counterparties/export', [CounterpartyController::class, 'export'])->middleware('can:crm.export')->name('counterparties.export');
        Route::resource('counterparties', CounterpartyController::class);
    });

    /* Contracts */
    Route::middleware('can:contracts.view')->group(function () {
        Route::get('/contracts/export', [ContractController::class, 'export'])->middleware('can:contracts.export')->name('contracts.export');
        Route::resource('contracts', ContractController::class);
        Route::get('/contracts/{contract}/pdf', [ContractController::class, 'pdf'])->name('contracts.pdf');
        Route::post('/contracts/{contract}/payments/{payment}/toggle', [ContractController::class, 'togglePayment'])->middleware('can:contracts.update')->name('contracts.payments.toggle');
    });

    /* Bank */
    Route::middleware('can:expenses.view')->group(function () {
        Route::get('/expenses/categories', [ExpenseController::class, 'categories'])->name('expenses.categories');
        Route::post('/expenses/categories', [ExpenseController::class, 'storeCategory'])->name('expenses.categories.store');
        Route::put('/expenses/categories/{category}', [ExpenseController::class, 'updateCategory'])->name('expenses.categories.update');
        Route::delete('/expenses/categories/{category}', [ExpenseController::class, 'destroyCategory'])->name('expenses.categories.destroy');
        Route::resource('expenses', ExpenseController::class)->except(['show']);
    });

    Route::middleware('can:bank.view')->prefix('bank')->name('bank.')->group(function () {
        Route::get('/transactions/export', [BankTransactionController::class, 'export'])->middleware('can:bank.export')->name('transactions.export');
        Route::resource('transactions', BankTransactionController::class);
        Route::get('/accounts/{account}/statement', [BankAccountController::class, 'statement'])->name('accounts.statement');
        Route::resource('accounts', BankAccountController::class)->except(['show']);
    });

    /* Logistics */
    Route::middleware('can:logistics.view')->group(function () {
        Route::get('/shipments/export', [ShipmentController::class, 'export'])->middleware('can:logistics.export')->name('shipments.export');
        Route::resource('shipments', ShipmentController::class);
        Route::post('/shipments/{shipment}/status', [ShipmentController::class, 'status'])->middleware('can:logistics.update')->name('shipments.status');
        Route::post('/shipments/{shipment}/costs', [ShipmentController::class, 'storeCost'])->middleware('can:logistics.update')->name('shipments.costs.store');
        Route::delete('/shipments/{shipment}/costs/{cost}', [ShipmentController::class, 'destroyCost'])->middleware('can:logistics.update')->name('shipments.costs.destroy');
    });

    /* Reports */
    Route::middleware('can:reports.view')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{report}', [ReportController::class, 'show'])->name('reports.show');
        Route::get('/reports/{report}/export', [ReportController::class, 'export'])->middleware('can:reports.export')->name('reports.export');
    });

    /* Files */
    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');

    /* Excel import */
    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('/imports/template/{type}', [ImportController::class, 'template'])->name('imports.template');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('/imports/{import}/run', [ImportController::class, 'run'])->name('imports.run');
    Route::get('/imports/{import}/errors', [ImportController::class, 'errors'])->name('imports.errors');

    /* Settings */
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [Settings\SettingsController::class, 'index'])->name('index');
        Route::middleware('can:settings.view')->group(function () {
            Route::get('/company', [Settings\SettingsController::class, 'company'])->name('company');
            Route::put('/company', [Settings\SettingsController::class, 'updateCompany'])->middleware('can:settings.update')->name('company.update');
            Route::get('/general', [Settings\SettingsController::class, 'general'])->name('general');
            Route::put('/general', [Settings\SettingsController::class, 'updateGeneral'])->middleware('can:settings.update')->name('general.update');
            Route::get('/mail', [Settings\SettingsController::class, 'mail'])->name('mail');
            Route::put('/mail', [Settings\SettingsController::class, 'updateMail'])->middleware('can:settings.update')->name('mail.update');
            Route::post('/mail/test', [Settings\SettingsController::class, 'testMail'])->middleware('can:settings.update')->name('mail.test');
            Route::get('/approvals', [Settings\SettingsController::class, 'approvals'])->name('approvals');
            Route::put('/approvals', [Settings\SettingsController::class, 'updateApprovals'])->middleware('can:settings.update')->name('approvals.update');
            Route::get('/categories', [Settings\SettingsController::class, 'categories'])->name('categories');
            Route::post('/categories', [Settings\SettingsController::class, 'storeCategory'])->middleware('can:settings.update')->name('categories.store');
            Route::delete('/categories/{category}', [Settings\SettingsController::class, 'destroyCategory'])->middleware('can:settings.update')->name('categories.destroy');
        });
        Route::middleware('can:users.view')->group(function () {
            Route::resource('users', Settings\UserController::class)->except(['show']);
            Route::post('/users/{user}/invite', [Settings\UserController::class, 'resendInvite'])->middleware('can:users.update')->name('users.invite');
            Route::post('/users/{user}/logout', [Settings\UserController::class, 'forceLogout'])->middleware('can:users.update')->name('users.logout');
            Route::post('/users/{user}/unlock', [Settings\UserController::class, 'unlock'])->middleware('can:users.update')->name('users.unlock');
            Route::resource('roles', Settings\RoleController::class)->except(['show']);
        });
        Route::middleware('can:logs.view')->group(function () {
            Route::get('/logs/logins', [Settings\LogController::class, 'logins'])->name('logs.logins');
            Route::get('/logs/sessions', [Settings\LogController::class, 'sessions'])->name('logs.sessions');
            Route::delete('/logs/sessions/{session}', [Settings\LogController::class, 'destroySession'])->middleware('can:users.update')->name('logs.sessions.destroy');
            Route::get('/logs/audit', [Settings\LogController::class, 'audit'])->name('logs.audit');
            Route::get('/logs/mail', [Settings\LogController::class, 'mail'])->name('logs.mail');
        });
    });
});
