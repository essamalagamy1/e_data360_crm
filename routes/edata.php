<?php

use App\Http\Controllers\Edata\AttendanceController;
use App\Http\Controllers\Edata\AuditLogController;
use App\Http\Controllers\Edata\ClientController;
use App\Http\Controllers\Edata\DashboardController;
use App\Http\Controllers\Edata\EmployeeServicesController;
use App\Http\Controllers\Edata\InvoiceController;
use App\Http\Controllers\Edata\KudosController;
use App\Http\Controllers\Edata\LegacyRedirectController;
use App\Http\Controllers\Edata\MeController;
use App\Http\Controllers\Edata\NoteController;
use App\Http\Controllers\Edata\NotificationController;
use App\Http\Controllers\Edata\PeopleController;
use App\Http\Controllers\Edata\ProductController;
use App\Http\Controllers\Edata\ProjectController;
use App\Http\Controllers\Edata\PublicInvoiceController;
use App\Http\Controllers\Edata\RoleController;
use App\Http\Controllers\Edata\TaskController;
use App\Http\Controllers\Edata\TeamDeskController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| E-Data 360 Business Management
|--------------------------------------------------------------------------
| Loaded after web.php. A route with the same method and URI replaces the old
| one, so old links and old route names keep working while the page behind them
| is the rebuilt one. Routes with the same name keep the old name on purpose.
*/

// What a client opens from a shared link or a QR code: no login, unguessable token.
Route::get('i/{slug}', [PublicInvoiceController::class, 'show'])->where('slug', '[A-Za-z0-9\-]+')->middleware('throttle:60,1')->name('edata.invoice.public');
Route::get('customer/invoice/{id}', [PublicInvoiceController::class, 'legacyLink'])->middleware('throttle:60,1')->name('invoice.link.copy');
Route::get('invoice/pdf/{id}', [PublicInvoiceController::class, 'legacyPdf'])->middleware('throttle:60,1')->name('invoice.pdf');
// The old page holds the online payment buttons, so it stays reachable for companies that use them.
Route::get('customer/invoice/{id}/pay', [\App\Http\Controllers\InvoiceController::class, 'invoiceLink'])->middleware('throttle:60,1')->name('edata.invoice.pay');

Route::middleware(['auth', 'verified', 'XSS', 'revalidate'])->group(function () {

    // Account actions that had no login requirement on their routes.
    Route::any('user-reset-password/{id}', [\App\Http\Controllers\UserController::class, 'userPassword'])->name('users.reset');
    Route::post('user-reset-password/{id}', [\App\Http\Controllers\UserController::class, 'userPasswordReset'])->name('user.password.update');
    Route::post('user-unable', [\App\Http\Controllers\UserController::class, 'userUnable'])->name('user.unable');
    Route::get('user-login/{id}', [\App\Http\Controllers\UserController::class, 'LoginManage'])->name('users.login');
    Route::get('company-info/{id}', [\App\Http\Controllers\UserController::class, 'companyInfo'])->middleware('can:manage user')->name('company.info');

    // Dashboards
    Route::get('account-dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('accounting-overview', [\App\Http\Controllers\DashboardController::class, 'account_dashboard_index'])->name('edata.accounting');
    Route::get('hrm-dashboard', [DashboardController::class, 'hrm'])->name('hrm.dashboard');

    // My space
    Route::get('my', [MeController::class, 'index'])->name('edata.me');
    Route::get('my/attendance', [MeController::class, 'attendance'])->name('edata.me.attendance');
    Route::post('my/attendance/clock-in', [MeController::class, 'clockIn'])->name('edata.me.clock-in');
    Route::post('my/attendance/clock-out', [MeController::class, 'clockOut'])->name('edata.me.clock-out');
    Route::post('my/kudos', [KudosController::class, 'store'])->name('edata.kudos.store');
    Route::delete('my/kudos/{kudos}', [KudosController::class, 'destroy'])->whereNumber('kudos')->name('edata.kudos.destroy');
    Route::post('my/requests/leave', [EmployeeServicesController::class, 'leave'])->middleware('throttle:20,1')->name('edata.my.leave');
    Route::post('my/requests/permission', [EmployeeServicesController::class, 'permission'])->middleware('throttle:20,1')->name('edata.my.permission');
    Route::post('my/requests/correction', [EmployeeServicesController::class, 'correction'])->middleware('throttle:20,1')->name('edata.my.correction');
    Route::post('my/plan', [EmployeeServicesController::class, 'plan'])->name('edata.my.plan');
    Route::post('my/timer/{task}', [EmployeeServicesController::class, 'startTimer'])->whereNumber('task')->name('edata.timer.start');
    Route::post('my/timer-stop', [EmployeeServicesController::class, 'stopTimer'])->name('edata.timer.stop');
    Route::post('my/announcements/{announcement}/read', [EmployeeServicesController::class, 'readAnnouncement'])->whereNumber('announcement')->name('edata.announcements.read');

    // Team desk: approvals, daily reports, announcements
    Route::get('requests', [TeamDeskController::class, 'requests'])->name('edata.requests.index');
    Route::post('requests/{type}/{id}', [TeamDeskController::class, 'decide'])->where('type', 'leave|request')->whereNumber('id')->name('edata.requests.decide');
    Route::get('daily-reports', [TeamDeskController::class, 'daily'])->name('edata.daily.index');
    Route::get('announcements', [TeamDeskController::class, 'announcements'])->name('edata.announcements.index');
    Route::post('announcements', [TeamDeskController::class, 'storeAnnouncement'])->name('edata.announcements.store');
    Route::post('announcements/{announcement}/archive', [TeamDeskController::class, 'archiveAnnouncement'])->whereNumber('announcement')->name('edata.announcements.archive');

    // Attendance
    Route::get('attendance', [AttendanceController::class, 'index'])->name('edata.attendance.index');
    Route::get('attendance/export', [AttendanceController::class, 'export'])->name('edata.attendance.export');
    Route::post('attendance/settings', [AttendanceController::class, 'saveSettings'])->name('edata.attendance.settings');
    Route::post('attendance', [AttendanceController::class, 'store'])->name('edata.attendance.store');
    Route::put('attendance/{row}', [AttendanceController::class, 'update'])->whereNumber('row')->name('edata.attendance.update');
    Route::delete('attendance/{row}', [AttendanceController::class, 'destroy'])->whereNumber('row')->name('edata.attendance.destroy');

    // Old attendance URLs: same names, safe behaviour
    Route::get('attendanceemployee', [LegacyRedirectController::class, 'attendance'])->name('attendanceemployee.index');
    Route::get('attendanceemployee/create', [LegacyRedirectController::class, 'attendance'])->name('attendanceemployee.create');
    Route::post('attendanceemployee', [AttendanceController::class, 'store'])->name('attendanceemployee.store');
    Route::get('attendanceemployee/{attendanceemployee}', [LegacyRedirectController::class, 'attendance'])->name('attendanceemployee.show');
    Route::get('attendanceemployee/{attendanceemployee}/edit', [LegacyRedirectController::class, 'attendance'])->name('attendanceemployee.edit');
    Route::match(['put', 'patch'], 'attendanceemployee/{attendanceemployee}', [MeController::class, 'clockOut'])->name('attendanceemployee.update');
    Route::delete('attendanceemployee/{attendanceemployee}', [AttendanceController::class, 'destroyLegacy'])->name('attendanceemployee.destroy');
    Route::post('attendanceemployee/attendance', [MeController::class, 'clockIn'])->name('attendanceemployee.attendance');
    Route::get('import/attendance/file', [\App\Http\Controllers\AttendanceEmployeeController::class, 'importFile'])->middleware('can:create attendance')->name('attendance.file.import');
    Route::post('import/attendance', [\App\Http\Controllers\AttendanceEmployeeController::class, 'attendanceImportdata'])->middleware('can:create attendance')->name('attendance.import.data');

    // People
    Route::get('people', [PeopleController::class, 'index'])->name('edata.people.index');
    Route::get('people/create', [PeopleController::class, 'create'])->name('edata.people.create');
    Route::post('people', [PeopleController::class, 'store'])->name('edata.people.store');
    Route::get('people/{user}', [PeopleController::class, 'show'])->whereNumber('user')->name('edata.people.show');
    Route::get('people/{user}/edit', [PeopleController::class, 'edit'])->whereNumber('user')->name('edata.people.edit');
    Route::put('people/{user}', [PeopleController::class, 'update'])->whereNumber('user')->name('edata.people.update');
    Route::post('people/{user}/password', [PeopleController::class, 'password'])->whereNumber('user')->name('edata.people.password');
    Route::post('people/{user}/end-service', [PeopleController::class, 'endService'])->whereNumber('user')->name('edata.people.end');
    Route::delete('people/{user}', [PeopleController::class, 'destroy'])->whereNumber('user')->name('edata.people.destroy');
    Route::post('people/{user}/access', [PeopleController::class, 'toggleAccess'])->whereNumber('user')->name('edata.people.access');
    Route::get('users', [LegacyRedirectController::class, 'people'])->name('users.index');
    Route::delete('users/{user}', [PeopleController::class, 'destroyLegacy'])->whereNumber('user')->name('users.destroy');

    // Roles
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}', [LegacyRedirectController::class, 'roles'])->name('roles.show');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->whereNumber('role')->name('roles.edit');
    Route::match(['put', 'patch'], 'roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');

    // Clients
    Route::get('customers', [ClientController::class, 'index'])->name('edata.clients.index');
    Route::get('customers/export', [ClientController::class, 'export'])->name('edata.clients.export');
    Route::get('customers/create', [ClientController::class, 'create'])->name('edata.clients.create');
    Route::post('customers', [ClientController::class, 'store'])->name('edata.clients.store');
    Route::get('customers/{customer}', [ClientController::class, 'show'])->whereNumber('customer')->name('edata.clients.show');
    Route::get('customers/{customer}/edit', [ClientController::class, 'edit'])->whereNumber('customer')->name('edata.clients.edit');
    Route::put('customers/{customer}', [ClientController::class, 'update'])->whereNumber('customer')->name('edata.clients.update');
    Route::post('customers/{customer}/archive', [ClientController::class, 'archive'])->whereNumber('customer')->name('edata.clients.archive');
    Route::get('customer', [LegacyRedirectController::class, 'clients'])->name('customer.index');
    Route::get('customer/{customer}', [LegacyRedirectController::class, 'client'])->name('customer.show');
    Route::delete('customer/{customer}', [ClientController::class, 'destroyLegacy'])->name('customer.destroy');

    // Projects (old URLs and names kept)
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->whereNumber('project')->name('projects.show');
    Route::get('projects/{project}/edit', [ProjectController::class, 'edit'])->whereNumber('project')->name('projects.edit');
    Route::match(['put', 'patch'], 'projects/{project}', [ProjectController::class, 'update'])->whereNumber('project')->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->whereNumber('project')->name('projects.destroy');
    Route::post('projects/{project}/status', [ProjectController::class, 'status'])->whereNumber('project')->name('edata.projects.status');
    Route::post('projects/{project}/team', [ProjectController::class, 'addMember'])->whereNumber('project')->name('edata.projects.team.add');
    Route::delete('projects/{project}/team/{user}', [ProjectController::class, 'removeMember'])->whereNumber('project')->whereNumber('user')->name('edata.projects.team.remove');
    Route::get('project-list', [LegacyRedirectController::class, 'projects'])->name('edata.projects.index');
    Route::get('project/{project}', [LegacyRedirectController::class, 'project'])->whereNumber('project')->name('edata.projects.show');

    // Tasks
    Route::get('tasks', [TaskController::class, 'index'])->name('edata.tasks.index');
    Route::get('tasks/create', [TaskController::class, 'create'])->name('edata.tasks.create');
    Route::post('tasks', [TaskController::class, 'store'])->name('edata.tasks.store');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->whereNumber('task')->name('edata.tasks.show');
    Route::get('tasks/{task}/edit', [TaskController::class, 'edit'])->whereNumber('task')->name('edata.tasks.edit');
    Route::put('tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')->name('edata.tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task')->name('edata.tasks.destroy');
    Route::post('tasks/{task}/move', [TaskController::class, 'move'])->whereNumber('task')->name('edata.tasks.move');
    Route::post('tasks/{task}/progress', [TaskController::class, 'progress'])->whereNumber('task')->name('edata.tasks.progress');
    Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->whereNumber('task')->name('edata.tasks.cancel');
    Route::post('tasks/{task}/comments', [TaskController::class, 'comment'])->whereNumber('task')->name('edata.tasks.comment');
    Route::get('taskboard/{view?}', [LegacyRedirectController::class, 'tasks'])->name('taskBoard.view');

    // Invoices
    Route::get('invoices', [InvoiceController::class, 'index'])->name('edata.invoices.index');
    Route::get('invoices/export', [InvoiceController::class, 'export'])->name('edata.invoices.export');
    Route::get('invoices/create', [InvoiceController::class, 'create'])->name('edata.invoices.create');
    Route::post('invoices', [InvoiceController::class, 'store'])->name('edata.invoices.store');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice')->name('edata.invoices.show');
    Route::get('invoices/{invoice}/print', [InvoiceController::class, 'printView'])->whereNumber('invoice')->name('edata.invoices.print');
    Route::get('invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->whereNumber('invoice')->name('edata.invoices.edit');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice')->name('edata.invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->whereNumber('invoice')->name('edata.invoices.destroy');
    Route::post('invoices/{invoice}/sent', [InvoiceController::class, 'markSent'])->whereNumber('invoice')->name('edata.invoices.sent');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->whereNumber('invoice')->name('edata.invoices.cancel');
    Route::post('invoices/{invoice}/relink', [InvoiceController::class, 'relink'])->whereNumber('invoice')->name('edata.invoices.relink');
    Route::post('invoices/{invoice}/collected', [InvoiceController::class, 'markCollected'])->whereNumber('invoice')->name('edata.invoices.collected');
    Route::post('invoices/{invoice}/pending', [InvoiceController::class, 'markPending'])->whereNumber('invoice')->name('edata.invoices.pending');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'addPayment'])->whereNumber('invoice')->name('edata.invoices.payment');
    Route::delete('invoices/{invoice}/payments/{payment}', [InvoiceController::class, 'removePayment'])->whereNumber('invoice')->whereNumber('payment')->name('edata.invoices.payment.remove');
    Route::get('invoice', [LegacyRedirectController::class, 'invoices'])->name('invoice.index');
    Route::get('invoice/{invoice}', [LegacyRedirectController::class, 'invoice'])->name('invoice.show');
    // The old edit screen stamped "partly paid" on every invoice it saved.
    Route::get('invoice/{invoice}/edit', [LegacyRedirectController::class, 'invoiceEdit'])->name('invoice.edit');

    // Products and services
    Route::get('products', [ProductController::class, 'index'])->name('edata.products.index');
    Route::get('products/create', [ProductController::class, 'create'])->name('edata.products.create');
    Route::post('products', [ProductController::class, 'store'])->name('edata.products.store');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->name('edata.products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('edata.products.update');
    Route::post('products/{product}/toggle', [ProductController::class, 'toggle'])->whereNumber('product')->name('edata.products.toggle');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('edata.products.destroy');
    Route::get('productservice', [LegacyRedirectController::class, 'products'])->name('productservice.index');

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index'])->name('edata.notifications.index');
    Route::get('notifications/feed', [NotificationController::class, 'feed'])->name('edata.notifications.feed');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('edata.notifications.read-all');
    Route::get('notifications/{notification}/open', [NotificationController::class, 'open'])->whereNumber('notification')->name('edata.notifications.open');

    // Notes on clients and projects
    Route::post('notes', [NoteController::class, 'store'])->name('edata.notes.store');
    Route::delete('notes/{note}', [NoteController::class, 'destroy'])->whereNumber('note')->name('edata.notes.destroy');

    // Audit log: read only, there are no write routes.
    Route::get('activity-log', [AuditLogController::class, 'index'])->name('edata.audit.index');
});
