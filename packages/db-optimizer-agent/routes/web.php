<?php

use Illuminate\Support\Facades\Route;
use Mdj\DbOptimizer\Http\Controllers\DbOptimizerAgentController;
use Mdj\DbOptimizer\Http\Controllers\DbOptimizerDashboardController;
use Mdj\DbOptimizer\Http\Controllers\DbOptimizerScannerController;

$prefix = trim((string) config('db_optimizer.route_prefix', '_db-optimizer'), '/');

if ((bool) config('db_optimizer.register_dashboard_routes', true)) {
    Route::prefix($prefix)
        ->name('db-optimizer.')
        ->middleware(['web', 'db-optimizer.access'])
        ->group(function (): void {
            Route::get('/', [DbOptimizerDashboardController::class, 'index'])->name('index');
            Route::get('/snapshots/{snapshotId}', [DbOptimizerDashboardController::class, 'show'])->name('show');
            Route::get('/scanner', [DbOptimizerScannerController::class, 'index'])->name('scanner.index');
            Route::post('/scanner/run', [DbOptimizerScannerController::class, 'run'])->name('scanner.run');
        });
}

if ((bool) config('db_optimizer.register_agent_routes', true)) {
    Route::prefix($prefix.'/agent')
        ->middleware(['web', 'db-optimizer.agent'])
        ->group(function (): void {
            Route::get('/ping', [DbOptimizerAgentController::class, 'ping']);
            Route::get('/snapshots', [DbOptimizerAgentController::class, 'snapshots']);
            Route::post('/reset', [DbOptimizerAgentController::class, 'reset']);
        });
}
