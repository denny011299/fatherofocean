<?php

declare(strict_types=1);

use App\Http\Middleware\PreventAuthPageCache;
use App\Http\Middleware\RedirectCustomerFromStaffRoutes;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BrandingLogoController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\BarangEditRequestController;
use App\Http\Controllers\ContainerController;
// use App\Http\Controllers\CustomerController; // removed from routes — marking IS customer
use App\Http\Controllers\DailyRateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\MarkingController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PengajuanController;
use App\Http\Controllers\RepricingRequestController;
use App\Http\Controllers\SalesIncentiveController;
use App\Http\Controllers\CompanyPayoutController;
use App\Http\Controllers\SalesRevenueController;
use App\Http\Controllers\SalesSalaryController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UserController;
use App\Support\Locales;
use Illuminate\Support\Facades\Route;

// ── Locale ──
Route::get('/locale/{locale}', [LanguageController::class, 'switch'])
    ->name('locale.switch')
    ->where('locale', Locales::routePattern());

// ── App branding (no storage symlink required) ──
Route::get('/branding/logo', [BrandingLogoController::class, 'show'])->name('branding.logo');

// ── Public Tracking Portal ──
Route::get('/track', [TrackingController::class, 'publicIndex'])->name('track.index');
Route::post('/track', [TrackingController::class, 'publicSearch'])->name('track.search');
Route::post('/track/{barang}/rate', [TrackingController::class, 'publicRate'])->name('track.rate');
Route::get('/track/invoice/{invoice}/pdf', [TrackingController::class, 'publicInvoicePdf'])->name('track.invoice.pdf');
Route::get('/track/{noIndex}', [TrackingController::class, 'publicShow'])
    ->name('track.show')
    ->where('noIndex', '[^/]+');

// ── Auth ──
Route::middleware(['guest', PreventAuthPageCache::class])->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.post');
});

Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

// ── Authenticated ──
Route::middleware('auth')->group(function () {
    Route::get('/storage-media/{path?}', [\App\Http\Controllers\PublicStorageFileController::class, 'show'])
        ->where('path', '.*')
        ->name('storage-media.show');

    Route::get('/', [DashboardController::class, 'index'])
        ->middleware('permission:view dashboard')
        ->name('dashboard');
    Route::get('/dashboard/shipment-chart', [DashboardController::class, 'shipmentChartData'])
        ->middleware('permission:view dashboard')
        ->name('dashboard.shipment-chart');
    Route::get('/dashboard/company-value', [DashboardController::class, 'companyValueData'])
        ->middleware('permission:view dashboard')
        ->name('dashboard.company-value');
    Route::get('/dashboard/admin-cbm', [DashboardController::class, 'adminCbmData'])
        ->middleware('permission:view dashboard')
        ->name('dashboard.admin-cbm');
    Route::get('/dashboard/team-metrics', [DashboardController::class, 'teamMetricsData'])
        ->middleware('permission:view dashboard')
        ->name('dashboard.team-metrics');
    Route::get('/dashboard/team-omset-total', [DashboardController::class, 'teamOmsetTotalData'])
        ->middleware('permission:view dashboard')
        ->name('dashboard.team-omset-total');

    // ── Profile ──
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    Route::get('/api/daily-rate', [DailyRateController::class, 'show'])->name('api.daily-rate');
    Route::put('/api/daily-rate', [DailyRateController::class, 'update'])
        ->middleware('permission:edit rmb rate')
        ->name('api.daily-rate.update');

    Route::middleware('permission:view barang')->prefix('api/notifications')->group(function () {
        Route::get('/summary', [\App\Http\Controllers\UserNotificationController::class, 'unreadCountEndpoint'])->name('notifications.summary');
        Route::get('/poll', [\App\Http\Controllers\UserNotificationController::class, 'poll'])->name('notifications.poll');
        Route::get('/list', [\App\Http\Controllers\UserNotificationController::class, 'list'])->name('notifications.list');
        Route::post('/read-all', [\App\Http\Controllers\UserNotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::post('/{userNotification}/read', [\App\Http\Controllers\UserNotificationController::class, 'markRead'])->name('notifications.read');
    });

    Route::get('/notifications', [\App\Http\Controllers\UserNotificationController::class, 'index'])
        ->middleware('permission:view barang')
        ->name('notifications.index');

    // ── Feedback Assignment ──
    Route::post('/feedback/{rating}/assign', [DashboardController::class, 'assignFeedback'])
        ->middleware('permission:assign feedback')
        ->name('feedback.assign');

    // ── Dashboard Tasks ──
    Route::middleware('permission:manage task')->group(function () {
        Route::post('/barang-task', [DashboardController::class, 'storeTask'])->name('task.store');
        Route::patch('/barang-task/{barangTask}/toggle', [DashboardController::class, 'toggleTask'])->name('task.toggle');
        Route::patch('/barang-task/{barangTask}/update', [DashboardController::class, 'updateTask'])->name('task.update');
        Route::delete('/barang-task/{barangTask}', [DashboardController::class, 'deleteTask'])->name('task.destroy');
    });

    // ── Marking: View ──
    Route::middleware('permission:view marking')->group(function () {
        Route::get('/marking', [MarkingController::class, 'index'])->name('marking.index');
        Route::get('/marking/data', [MarkingController::class, 'data'])->name('marking.data');
        Route::get('/marking/export', [MarkingController::class, 'export'])
            ->middleware('permission:export marking')
            ->name('marking.export');
        Route::get('/marking/options', [MarkingController::class, 'options'])->name('marking.options');
        Route::get('/marking/customer-users', [MarkingController::class, 'customerUsers'])->name('marking.customerUsers');
        Route::get('/marking/check-nik', [MarkingController::class, 'checkNik'])->name('marking.checkNik');
        Route::get('/marking/{marking}/template', [MarkingController::class, 'template'])->name('marking.template');
    });

    Route::get('/marking/{marking}/pricings', [MarkingController::class, 'pricings'])
        ->middleware('permission:view marking|create barang|edit barang')
        ->name('marking.pricings');

    // ── Marking: Create/Edit/Delete (continued) ──
    Route::post('/marking', [MarkingController::class, 'store'])
        ->middleware('permission:create marking')
        ->name('marking.store');
    Route::put('/marking/{marking}', [MarkingController::class, 'update'])
        ->middleware('permission:edit marking')
        ->name('marking.update');
    Route::delete('/marking/{marking}', [MarkingController::class, 'destroy'])
        ->middleware('permission:delete marking')
        ->name('marking.destroy');
    Route::patch('/marking/{marking}/toggle', [MarkingController::class, 'toggle'])
        ->middleware('permission:edit marking')
        ->name('marking.toggle');

    // ── Barang: View (literal paths before {barang} to avoid "create" being bound as ID) ──
    Route::get('/barang/create', [BarangController::class, 'create'])
        ->middleware('permission:create barang')
        ->name('barang.create');

    Route::middleware('permission:edit barang|approve barang')->group(function () {
        Route::get('/barang/edit-requests/data', [BarangEditRequestController::class, 'data'])->name('barang.edit-requests.data');
        Route::get('/barang/edit-requests/badge', [BarangEditRequestController::class, 'badgeCount'])->name('barang.edit-requests.badge');
        Route::get('/barang/edit-requests/{barangEditRequest}', [BarangEditRequestController::class, 'show'])->name('barang.edit-requests.show');
        Route::post('/barang/edit-requests/{barangEditRequest}/approve', [BarangEditRequestController::class, 'approve'])
            ->middleware('permission:approve barang')
            ->name('barang.edit-requests.approve');
        Route::post('/barang/edit-requests/{barangEditRequest}/reject', [BarangEditRequestController::class, 'reject'])
            ->middleware('permission:reject barang')
            ->name('barang.edit-requests.reject');
    });

    Route::middleware('permission:view barang')->group(function () {
        Route::get('/barang', [BarangController::class, 'index'])->name('barang.index');
        Route::get('/barang/data', [BarangController::class, 'data'])->name('barang.data');
        Route::get('/barang/export/products-excel', [BarangController::class, 'exportListProductsExcel'])
            ->middleware('permission:export barang')
            ->name('barang.list.export-excel');
        Route::get('/barang-lookup', [BarangController::class, 'lookupIndex'])->name('barang.lookup');
        Route::get('/barang/{barang}', [BarangController::class, 'show'])->name('barang.show');
    });
    Route::get('/barang/{barang}/edit', [BarangController::class, 'edit'])
        ->middleware('permission:edit barang')
        ->name('barang.edit');
    Route::get('/barang/{barang}/invoice/pdf', [BarangController::class, 'downloadInvoice'])
        ->middleware('permission:view invoice|export invoice|download barang invoice')
        ->name('barang.invoice.pdf');
    Route::get('/barang/{barang}/original-pl/download', [BarangController::class, 'downloadOriginalPackingList'])
        ->middleware('permission:view barang')
        ->name('barang.original-pl.download');
    Route::get('/barang/{barang}/terima-barang/pdf', [BarangController::class, 'exportTerimaBarangPdf'])
        ->middleware('permission:export barang')
        ->name('barang.terima-barang.pdf');
    Route::get('/barang/{barang}/shipping-label/pdf', [BarangController::class, 'exportShippingLabelPdf'])
        ->middleware('permission:export barang')
        ->name('barang.shipping-label.pdf');
    Route::get('/barang/{barang}/products/export-excel', [BarangController::class, 'exportProductsExcel'])
        ->middleware('permission:export barang')
        ->name('barang.products.export-excel');

    // ── Barang: Create/Edit/Import ──
    Route::post('/barang', [BarangController::class, 'store'])
        ->middleware('permission:create barang')
        ->name('barang.store');
    Route::put('/barang/{barang}', [BarangController::class, 'update'])
        ->middleware('permission:edit barang')
        ->name('barang.update');
    Route::post('/barang/{barang}/products/{product}/dimension-photo', [BarangController::class, 'uploadProductDimensionPhoto'])
        ->middleware('permission:edit barang')
        ->name('barang.products.dimension-photo');
    Route::post('/barang/{barang}/products/{product}/photo', [BarangController::class, 'uploadProductPhoto'])
        ->middleware('permission:edit barang')
        ->name('barang.products.photo');
    Route::post('/barang/{barang}/stage-product-photo', [BarangController::class, 'stageProductPhoto'])
        ->middleware('permission:edit barang')
        ->name('barang.stage-product-photo');
    Route::post('/barang/stage-product-photo', [BarangController::class, 'stageProductPhotoForCreate'])
        ->middleware('permission:create barang')
        ->name('barang.stage-product-photo.create');
    Route::put('/barang/{barang}/dimensions', [BarangController::class, 'updateDimensions'])
        ->middleware('permission:edit barang')
        ->name('barang.dimensions');
    Route::post('/barang/import', [BarangController::class, 'import'])
        ->middleware('permission:create barang')
        ->name('barang.import');

    // ── Barang: Delete ──
    Route::delete('/barang/{barang}', [BarangController::class, 'destroy'])
        ->middleware('permission:delete barang')
        ->name('barang.destroy');

    // ── Container: literal paths before {container} to avoid "create" being bound as ID ──
    Route::get('/container/create', [ContainerController::class, 'createPage'])
        ->middleware('permission:create container')
        ->name('container.create.page');
    Route::get('/container/marking-preview-draft', [ContainerController::class, 'markingPreviewDraft'])
        ->middleware('permission:create container')
        ->name('container.marking-preview-draft');

    Route::middleware('permission:view container')->group(function () {
        Route::get('/container', [ContainerController::class, 'index'])->name('container.index');
        Route::get('/container/data', [ContainerController::class, 'data'])->name('container.data');
        Route::get('/container/{container}', [ContainerController::class, 'show'])->name('container.show');
        Route::get('/container/{container}/detail', [ContainerController::class, 'detail'])->name('container.detail');
        Route::get('/container/{container}/packing-table', [ContainerController::class, 'packingTableFragment'])->name('container.packing-table');
    });

    Route::post('/container', [ContainerController::class, 'store'])
        ->middleware('permission:create container')
        ->name('container.store');

    Route::put('/container/{container}', [ContainerController::class, 'update'])
        ->middleware('permission:edit container')
        ->name('container.update');

    Route::delete('/container/{container}', [ContainerController::class, 'destroy'])
        ->middleware('role:super_admin')
        ->name('container.destroy');

    // ── Container: Load/Unload ──
    Route::middleware('permission:load barang')->group(function () {
        Route::get('/container/{container}/load', [ContainerController::class, 'loadEstimation'])->name('container.load.page');
        Route::get('/container/{container}/marking-preview', [ContainerController::class, 'markingPreview'])->name('container.marking-preview');
        Route::post('/container/{container}/load', [ContainerController::class, 'loadBarang'])->name('container.load');
        Route::post('/container/{container}/load-batch', [ContainerController::class, 'loadBatch'])->name('container.load-batch');
    });

    Route::match(['delete', 'post'], '/container/{container}/unload/{barang}', [ContainerController::class, 'unloadBarang'])
        ->middleware('role_or_permission:super_admin|supervisor|unload barang')
        ->name('container.unload');

    // ── Bongkar muat ──
    Route::middleware('permission:view bongkar muat')->group(function () {
        Route::get('/bongkar-muat', [\App\Http\Controllers\BongkarMuatController::class, 'index'])->name('bongkar-muat.index');
        Route::get('/bongkar-muat/data', [\App\Http\Controllers\BongkarMuatController::class, 'data'])->name('bongkar-muat.data');
    });

    Route::middleware('permission:manage bongkar muat')->group(function () {
        Route::get('/bongkar-muat/create', [\App\Http\Controllers\BongkarMuatController::class, 'create'])->name('bongkar-muat.create');
        Route::get('/bongkar-muat/containers', [\App\Http\Controllers\BongkarMuatController::class, 'containers'])->name('bongkar-muat.containers');
        Route::post('/bongkar-muat/session', [\App\Http\Controllers\BongkarMuatController::class, 'openSession'])->name('bongkar-muat.session.open');
        Route::post('/bongkar-muat/session/{session}/lookup', [\App\Http\Controllers\BongkarMuatController::class, 'lookupScan'])->name('bongkar-muat.session.lookup');
        Route::post('/bongkar-muat/session/{session}/confirm', [\App\Http\Controllers\BongkarMuatController::class, 'confirmScan'])->name('bongkar-muat.session.confirm');
        Route::delete('/bongkar-muat/session/{session}', [\App\Http\Controllers\BongkarMuatController::class, 'destroy'])->name('bongkar-muat.session.destroy');
        Route::delete('/bongkar-muat/session/{session}/scan/{scan}', [\App\Http\Controllers\BongkarMuatController::class, 'removeScan'])->name('bongkar-muat.session.scan.remove');
        Route::post('/bongkar-muat/session/{session}/complete', [\App\Http\Controllers\BongkarMuatController::class, 'complete'])->name('bongkar-muat.session.complete');
    });

    // ── Surat Jalan (delivery trip / truck) ──
    Route::get('/delivery-trip/create', [\App\Http\Controllers\DeliveryTripController::class, 'create'])
        ->middleware('permission:create delivery trip')
        ->name('delivery-trip.create');

    Route::middleware('permission:view delivery trip')->group(function () {
        Route::get('/delivery-trip', [\App\Http\Controllers\DeliveryTripController::class, 'index'])->name('delivery-trip.index');
        Route::get('/delivery-trip/data', [\App\Http\Controllers\DeliveryTripController::class, 'data'])->name('delivery-trip.data');
        Route::get('/delivery-trip/containers', [\App\Http\Controllers\DeliveryTripController::class, 'containers'])->name('delivery-trip.containers');
        Route::get('/delivery-trip/eligible-barangs', [\App\Http\Controllers\DeliveryTripController::class, 'eligibleBarangs'])->name('delivery-trip.eligible-barangs');
        Route::get('/delivery-trip/{deliveryTrip}', [\App\Http\Controllers\DeliveryTripController::class, 'show'])->name('delivery-trip.show');
    });

    Route::post('/delivery-trip', [\App\Http\Controllers\DeliveryTripController::class, 'store'])
        ->middleware('permission:create delivery trip')
        ->name('delivery-trip.store');

    Route::post('/delivery-trip/{deliveryTrip}/confirm-departure', [\App\Http\Controllers\DeliveryTripController::class, 'confirmDeparture'])
        ->middleware('permission:confirm delivery trip')
        ->name('delivery-trip.confirm-departure');

    Route::post('/delivery-trip/{deliveryTrip}/confirm-arrival', [\App\Http\Controllers\DeliveryTripController::class, 'confirmArrival'])
        ->middleware('permission:confirm delivery trip')
        ->name('delivery-trip.confirm-arrival');

    Route::post('/delivery-trip/{deliveryTrip}/complete', [\App\Http\Controllers\DeliveryTripController::class, 'complete'])
        ->middleware('permission:manage delivery trip')
        ->name('delivery-trip.complete');

    Route::get('/delivery-trip/{deliveryTrip}/pdf', [\App\Http\Controllers\DeliveryTripController::class, 'pdf'])
        ->middleware('permission:export delivery trip pdf')
        ->name('delivery-trip.pdf');

    Route::delete('/delivery-trip/{deliveryTrip}', [\App\Http\Controllers\DeliveryTripController::class, 'destroy'])
        ->middleware('permission:delete delivery trip')
        ->name('delivery-trip.destroy');

    // ── Finance ──
    Route::get('/finance/invoice/blank-template', [FinanceController::class, 'downloadInvoiceBlankTemplate'])
        ->middleware('role:super_admin')
        ->name('finance.invoice.blank-template');

    Route::middleware('permission:view invoice')->group(function () {
        Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
        Route::get('/finance/data', [FinanceController::class, 'indexData'])->name('finance.index.data');
        Route::get('/finance/export', [FinanceController::class, 'exportIndex'])->name('finance.index.export');
        Route::get('/finance/invoice/{invoice}', [FinanceController::class, 'showInvoice'])->name('finance.invoice.show');
        Route::get('/finance/customer-barangs', [FinanceController::class, 'customerBarangs'])->name('finance.customer-barangs');
    });

    Route::middleware('permission:view pricing|view pengajuan|submit repricing|approve repricing|change price')->group(function () {
        Route::get('/finance/pricing', [FinanceController::class, 'pricing'])->name('finance.pricing');
    });

    Route::middleware('permission:view pricing')->group(function () {
        Route::get('/finance/pricing/data', [FinanceController::class, 'pricingData'])->name('finance.pricing.data');
    });

    Route::post('/finance/reprice/{barang}', [RepricingRequestController::class, 'store'])
        ->middleware('permission:submit repricing|change price')
        ->name('finance.reprice');
    Route::get('/finance/reprice/setup-weight-threshold', [RepricingRequestController::class, 'setupWeightThresholdColumns'])
        ->middleware('permission:submit repricing|approve repricing|change price')
        ->name('finance.reprice.setup-weight-threshold');
    Route::post('/finance/cost-price/{barang}', [FinanceController::class, 'updateCostPrice'])
        ->middleware('permission:edit harga modal')
        ->name('finance.cost-price.update');
    Route::post('/finance/repricing/{repricingRequest}/approve', [RepricingRequestController::class, 'approve'])
        ->middleware('permission:approve repricing')
        ->name('finance.repricing.approve');
    Route::post('/finance/repricing/{repricingRequest}/reject', [RepricingRequestController::class, 'reject'])
        ->middleware('permission:approve repricing')
        ->name('finance.repricing.reject');

    Route::middleware('permission:view pengajuan|view pricing|submit repricing|approve repricing|change price')->group(function () {
        Route::get('/finance/pengajuan', [PengajuanController::class, 'index'])->name('finance.pengajuan');
        Route::get('/finance/pengajuan/data', [PengajuanController::class, 'data'])->name('finance.pengajuan.data');
        Route::get('/finance/pengajuan/badge', [PengajuanController::class, 'badgeCount'])->name('finance.pengajuan.badge');
    });

    Route::middleware('permission:view sales revenue')->group(function () {
        Route::redirect('/finance/earnings', '/sales-revenue')->name('finance.earnings.index');
        Route::get('/finance/earnings/company-data', [SalesRevenueController::class, 'companyData'])->name('finance.earnings.company-data');
        Route::get('/finance/company-revenue', [SalesRevenueController::class, 'companyIndex'])->name('finance.company-revenue.index');
        Route::get('/finance/company-revenue/summary', [SalesRevenueController::class, 'companySummary'])->name('finance.company-revenue.summary');
        Route::get('/finance/company-revenue/marking-options', [SalesRevenueController::class, 'companyMarkingOptions'])->name('finance.company-revenue.marking-options');
        Route::get('/finance/company-revenue/payout/data', [CompanyPayoutController::class, 'data'])->name('finance.company-revenue.payout.data');
        Route::get('/finance/company-revenue/payout/summary', [CompanyPayoutController::class, 'summary'])->name('finance.company-revenue.payout.summary');
        Route::redirect('/finance/salary', '/sales-revenue')->name('finance.salary.index');
        Route::get('/sales-revenue', [SalesRevenueController::class, 'salesIndex'])->name('sales-revenue.index');
        Route::get('/sales-revenue/data', [SalesRevenueController::class, 'data'])->name('sales-revenue.data');
        Route::get('/sales-revenue/export', [SalesRevenueController::class, 'export'])->name('sales-revenue.export');
        Route::get('/sales-revenue/summary', [SalesRevenueController::class, 'summary'])->name('sales-revenue.summary');
        Route::get('/sales-revenue/badge', [SalesRevenueController::class, 'badgeCount'])->name('sales-revenue.badge');
        Route::get('/sales-revenue/salary/data', [SalesSalaryController::class, 'data'])->name('sales-revenue.salary.data');
        Route::get('/sales-revenue/salary/sales-options', [SalesSalaryController::class, 'salesOptions'])->name('sales-revenue.salary.sales-options');
        Route::get('/sales-revenue/salary/{salesId}/{period}', [SalesSalaryController::class, 'detail'])
            ->where('period', '\d{4}-\d{2}')
            ->name('sales-revenue.salary.detail');
        Route::get('/sales-revenue/commission-detail', [SalesRevenueController::class, 'commissionMarkingDetail'])
            ->name('sales-revenue.commission.detail');
    });

    Route::middleware('permission:view sales incentive|view sales revenue')->group(function () {
        Route::get('/sales-revenue/incentive/summary', [SalesIncentiveController::class, 'summary'])->name('sales-revenue.incentive.summary');
        Route::get('/sales-revenue/incentive/data', [SalesIncentiveController::class, 'data'])->name('sales-revenue.incentive.data');
        Route::get('/sales-revenue/incentive/{salesId}/{period}', [SalesIncentiveController::class, 'detail'])
            ->where('period', '\d{4}-\d{2}')
            ->name('sales-revenue.incentive.detail');
    });

    Route::post('/sales-revenue/salary/{salesId}/{period}/proof', [SalesSalaryController::class, 'uploadProof'])
        ->middleware('permission:upload sales salary proof')
        ->where('period', '\d{4}-\d{2}')
        ->name('sales-revenue.salary.proof');

    Route::post('/sales-revenue/commission-payout', [SalesRevenueController::class, 'storeCommissionMarkingPayout'])
        ->middleware('permission:upload sales salary proof')
        ->name('sales-revenue.commission.payout.store');

    Route::post('/sales-revenue/commission-override', [SalesRevenueController::class, 'updateCommissionMarkingOverride'])
        ->middleware('permission:upload sales salary proof')
        ->name('sales-revenue.commission.override');

    Route::get('/sales-revenue/setup-commission-overrides', [SalesRevenueController::class, 'setupCommissionOverrides'])
        ->middleware('permission:upload sales salary proof')
        ->name('sales-revenue.commission.override.setup');

    Route::get('/sales-revenue/setup-marking-payouts', [SalesRevenueController::class, 'setupMarkingPayouts'])
        ->middleware('permission:upload sales salary proof')
        ->name('sales-revenue.commission.payout.setup');

    Route::post('/finance/company-revenue/payout', [CompanyPayoutController::class, 'store'])
        ->middleware('permission:manage company payout')
        ->name('finance.company-revenue.payout.store');

    Route::post('/sales-revenue/confirm/{barang}', [SalesRevenueController::class, 'confirm'])
        ->middleware('permission:confirm sales commission')
        ->name('sales-revenue.confirm');

    Route::post('/sales-revenue/{salesCommission}/cair', [SalesRevenueController::class, 'markCair'])
        ->middleware('permission:mark sales commission cair')
        ->name('sales-revenue.cair');

    Route::post('/finance/invoice', [FinanceController::class, 'generateInvoice'])
        ->middleware('role:super_admin')
        ->name('finance.invoice');
    Route::post('/finance/invoice/backfill-masuk', [FinanceController::class, 'backfillMasukInvoices'])
        ->middleware('role:super_admin')
        ->name('finance.invoice.backfill-masuk');

    Route::get('/finance/promos', [FinanceController::class, 'promoOptions'])
        ->middleware('permission:apply invoice promo|view invoice')
        ->name('finance.promos');
    Route::post('/finance/invoice/{invoice}/promo', [FinanceController::class, 'applyPromo'])
        ->middleware('permission:apply invoice promo')
        ->name('finance.invoice.promo');

    Route::post('/payment/{payment}/approve', [PaymentController::class, 'approve'])
        ->middleware('permission:approve payment')
        ->name('payment.approve');
    Route::post('/payment/{payment}/reject', [PaymentController::class, 'reject'])
        ->middleware('permission:reject payment')
        ->name('payment.reject');

    Route::get('/claims', [ClaimController::class, 'index'])
        ->middleware('permission:view claim|create claim|approve claim|reject claim')
        ->name('claims.index');
    Route::post('/claims', [ClaimController::class, 'store'])
        ->middleware('permission:create claim')
        ->name('claims.store');
    Route::post('/claims/{claim}/approve', [ClaimController::class, 'approve'])
        ->middleware('permission:approve claim')
        ->name('claims.approve');
    Route::post('/claims/{claim}/reject', [ClaimController::class, 'reject'])
        ->middleware('permission:reject claim')
        ->name('claims.reject');
    Route::delete('/claims/{claim}', [ClaimController::class, 'destroy'])
        ->middleware('permission:create claim')
        ->name('claims.destroy');

    // ── Tracking: Customer portal ──
    Route::middleware('role:customer')->group(function () {
        Route::get('/tracking', [TrackingController::class, 'customerIndex'])
            ->middleware('permission:view own barang')
            ->name('tracking.index');
        Route::get('/billing', [TrackingController::class, 'billing'])
            ->middleware('permission:view own invoice')
            ->name('billing.index');
        Route::get('/billing/invoice/{invoice}/pdf', [TrackingController::class, 'billingInvoicePdf'])
            ->middleware('permission:view own invoice')
            ->name('billing.invoice.pdf');
        Route::post('/rating/{barang}', [TrackingController::class, 'rate'])
            ->middleware('permission:rate delivery')
            ->name('rating.store');
    });

    Route::post('/billing/upload', [TrackingController::class, 'uploadPayment'])
        ->middleware('permission:upload payment proof')
        ->name('billing.upload');

    // ── Tracking: Staff ──
    Route::middleware([RedirectCustomerFromStaffRoutes::class, 'permission:view tracking'])->group(function () {
        Route::get('/tracking-staff', [TrackingController::class, 'staffIndex'])->name('tracking.staff');
        Route::get('/tracking-staff/container/{container}', [TrackingController::class, 'staffShow'])
            ->middleware('role:super_admin|admin_cs|admin')
            ->name('tracking.staff.show');
        Route::get('/tracking-staff/container/{container}/delivery-trip', [\App\Http\Controllers\DeliveryTripController::class, 'trackingSummary'])
            ->middleware('role:super_admin|admin_cs|admin')
            ->name('tracking.staff.delivery-trip.summary');
        Route::post('/tracking-staff/container/{container}/delivery-trip', [\App\Http\Controllers\DeliveryTripController::class, 'trackingUpsert'])
            ->middleware('role:super_admin|admin_cs|admin')
            ->name('tracking.staff.delivery-trip.upsert');
        Route::get('/tracking-staff/container/{container}/surat-jalan', [\App\Http\Controllers\DeliveryTripController::class, 'trackingSuratJalanPage'])
            ->middleware('role:super_admin|admin_cs|admin')
            ->name('tracking.staff.surat-jalan');
        Route::get('/tracking-staff/container/{container}/surat-jalan/template', [\App\Http\Controllers\DeliveryTripController::class, 'blankSuratJalanTemplate'])
            ->middleware('role:super_admin')
            ->name('tracking.staff.surat-jalan.template');
        Route::get('/tracking-staff/container/{container}/surat-jalan/rekapan', [\App\Http\Controllers\DeliveryTripController::class, 'rekapanSuratJalanExcel'])
            ->middleware('role:super_admin|admin_cs|admin')
            ->name('tracking.staff.surat-jalan.rekapan');
        Route::post('/tracking-staff/container/{container}/status', [TrackingController::class, 'staffUpdateStatus'])
            ->middleware('permission:update tracking status')
            ->name('tracking.staff.update');
        Route::put('/tracking-staff/container/{container}/timeline/{containerTrackingStatus}', [TrackingController::class, 'staffUpdateTimelineEntry'])
            ->middleware('permission:update tracking status')
            ->name('tracking.staff.timeline.update');
        Route::delete('/tracking-staff/container/{container}/timeline/{containerTrackingStatus}', [TrackingController::class, 'staffDeleteTimelineEntry'])
            ->middleware('permission:update tracking status')
            ->name('tracking.staff.timeline.delete');
        Route::get('/tracking-staff/align-statuses', [TrackingController::class, 'alignContainerBarangStatuses'])
            ->middleware('permission:update tracking status')
            ->name('tracking.staff.align-statuses');
        Route::get('/tracking-staff/setup-event-date', [TrackingController::class, 'setupEventDate'])
            ->middleware('permission:update tracking status')
            ->name('tracking.staff.setup-event-date');
        Route::get('/tracking-staff/{barang}/share-link', [TrackingController::class, 'shareLink'])->name('tracking.staff.share-link');
    });

    Route::get('/tracking/{barang}', [TrackingController::class, 'show'])
        ->middleware('permission:view tracking|view own barang')
        ->name('tracking.show');

    Route::post('/tracking/{barang}/status', [TrackingController::class, 'updateStatus'])
        ->middleware('permission:update tracking status')
        ->name('tracking.update');

    // ── User Management ──
    Route::middleware('permission:manage user')->group(function () {
        Route::get('/user', [UserController::class, 'index'])->name('user.index');
        Route::get('/user/data', [UserController::class, 'data'])->name('user.data');
        Route::post('/user', [UserController::class, 'store'])->name('user.store');
        Route::put('/user/{user}', [UserController::class, 'update'])->name('user.update');
        Route::get('/user/{user}/subordinates', [UserController::class, 'subordinates'])->name('user.subordinates');
        Route::post('/user/{user}/subordinates', [UserController::class, 'syncSubordinates'])->name('user.subordinates.sync');
        Route::delete('/user/{user}', [UserController::class, 'destroy'])->name('user.destroy');
    });

    // ── Role & Permission Management ──
    Route::middleware('permission:manage roles')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->name('roles.update-permissions');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    });

    // ── Activity Log & System ──
    Route::get('/activity', [SystemController::class, 'activityIndex'])
        ->middleware('permission:view activity log')
        ->name('activity.index');

    Route::middleware('permission:manage system')->group(function () {
        Route::get('/system', [SystemController::class, 'index'])->name('system.index');
        Route::post('/system/commission-rates', [SystemController::class, 'updateSalesCommissionRates'])
            ->name('system.commission-rates.update');
        Route::post('/system/item-modal-rates', [SystemController::class, 'updateItemModalRates'])
            ->middleware('permission:edit harga modal')
            ->name('system.item-modal-rates.update');
        Route::post('/system/logo', [SystemController::class, 'updateLogo'])->name('system.logo.update');
        Route::delete('/system/logo', [SystemController::class, 'removeLogo'])->name('system.logo.remove');
        Route::post('/system/export-db', [SystemController::class, 'exportDatabase'])->name('system.export-db');
        Route::post('/system/shutdown', [SystemController::class, 'shutdown'])->name('system.shutdown');
        Route::post('/system/up', [SystemController::class, 'bringUp'])->name('system.up');
        Route::get('/system/compress-images', [SystemController::class, 'compressStoredImages'])->name('system.images.compress');
    });

    // ── Exports: Invoice ──
    Route::middleware('permission:view invoice|export invoice')->group(function () {
        Route::get('/export/invoice/{invoice}/pdf', [ExportController::class, 'invoicePdf'])->name('export.invoice.pdf');
        Route::get('/export/invoice/{invoice}/excel', [ExportController::class, 'invoiceExcel'])->name('export.invoice.excel');
        Route::get('/export/container-invoice/{container}/pdf', [ExportController::class, 'containerInvoicePdf'])->name('export.container-invoice.pdf');
        Route::get('/export/container-invoice/{container}/excel', [ExportController::class, 'containerInvoiceExcel'])->name('export.container-invoice.excel');
        Route::get('/export/container/{container}/marking-invoice/pdf', [ExportController::class, 'containerMarkingInvoicePdf'])->name('export.container.marking-invoice.pdf');
    });

    // ── Exports: Omset ──
    Route::middleware('permission:export omset')->group(function () {
        Route::get('/export/omset', [ExportController::class, 'omsetIndex'])->name('export.omset');
        Route::get('/export/omset/data', [ExportController::class, 'omsetData'])->name('export.omset.data');
        Route::get('/export/omset/excel', [ExportController::class, 'omsetExcel'])->name('export.omset.excel');
        Route::get('/export/omset/pdf', [ExportController::class, 'omsetPdf'])->name('export.omset.pdf');
    });

    // ── Exports: Packing List ──
    Route::middleware('permission:export packing list')->group(function () {
        Route::get('/export/packing/{container}/pdf', [ExportController::class, 'packingListPdf'])->name('export.packing.pdf');
        Route::get('/export/packing/{container}/excel', [ExportController::class, 'packingListExcel'])->name('export.packing.excel');
        Route::get('/export/packing/bulk/excel', [ExportController::class, 'packingListBulkExcel'])->name('export.packing.bulk.excel');
    });

    // ── Master Data (granular promo checks in MasterDataController) ──
    Route::middleware('permission:manage master data|manage sales incentive master|view promo master|create promo master|edit promo master|delete promo master')->group(function () {
        Route::get('/master-data/sales-incentive-tiers/setup-multi-categories', [MasterDataController::class, 'setupIncentiveMultiCategories'])
            ->name('master-data.incentive.setup-multi-categories');
        Route::get('/master-data/sales-incentive-tiers/recalc', [MasterDataController::class, 'recalcSalesIncentives'])
            ->name('master-data.incentive.recalc');
        Route::get('/master-data', fn () => redirect()->route('master-data.page', 'branches'))->name('master-data');
        Route::get('/master-data/{type}', [MasterDataController::class, 'page'])->name('master-data.page');
        Route::get('/master-data/{type}/data', [MasterDataController::class, 'data'])->name('master-data.data');
        Route::post('/master-data/{type}', [MasterDataController::class, 'store'])->name('master-data.store');
        Route::put('/master-data/{type}/{id}', [MasterDataController::class, 'update'])->name('master-data.update');
        Route::patch('/master-data/{type}/{id}/toggle', [MasterDataController::class, 'toggle'])->name('master-data.toggle');
        Route::delete('/master-data/{type}/{id}', [MasterDataController::class, 'destroy'])->name('master-data.destroy');
        Route::get('/master-data/{type}/options', [MasterDataController::class, 'options'])->name('master-data.options');
    });
});
