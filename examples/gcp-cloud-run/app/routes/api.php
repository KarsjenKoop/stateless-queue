<?php

use App\Jobs\GenerateReportJob;
use App\Jobs\NotifySlackJob;
use App\Jobs\SendWelcomeEmailJob;
use App\Jobs\SyncInventoryJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Registered with apiPrefix '' so the paths stay /dispatch/*.
|
| These live in the api group rather than web deliberately: they are called by
| curl and by verify.sh, never by a browser, so a session and a CSRF token are
| both meaningless — the web group's CSRF check answers 419 to every one of
| them. The dispatch.token middleware is the actual guard.
*/

Route::middleware('dispatch.token')->group(function (): void {

    Route::post('/dispatch/default', function (): array {
        $runId = (string) Str::uuid();

        SendWelcomeEmailJob::dispatch('jane@example.com', 'Jane')->push();
        GenerateReportJob::dispatch('rpt-'.substr($runId, 0, 8), 9, ['sku', 'units', 'revenue'])->push();
        SyncInventoryJob::dispatch('eu-central-1', true)->push();

        Log::info('stateless-queue-example', [
            'marker' => 'DISPATCHED',
            'run_id' => $runId,
            'topic' => 'default',
            'count' => 3,
        ]);

        return ['dispatched' => 3, 'topic' => 'default', 'run_id' => $runId];
    });

    Route::post('/dispatch/custom', function (): array {
        $runId = (string) Str::uuid();

        NotifySlackJob::dispatch('#deploys', 'Stateless queue example ran '.$runId)->push();

        Log::info('stateless-queue-example', [
            'marker' => 'DISPATCHED',
            'run_id' => $runId,
            'topic' => 'stateless-notifications',
            'count' => 1,
        ]);

        return ['dispatched' => 1, 'topic' => 'stateless-notifications', 'run_id' => $runId];
    });

    Route::post('/dispatch/all', function (Request $request): array {
        $runId = (string) Str::uuid();

        SendWelcomeEmailJob::dispatch('jane@example.com', 'Jane')->push();
        GenerateReportJob::dispatch('rpt-'.substr($runId, 0, 8), 9, ['sku', 'units', 'revenue'])->push();
        SyncInventoryJob::dispatch('eu-central-1', true)->push();
        NotifySlackJob::dispatch('#deploys', 'Stateless queue example ran '.$runId)->push();

        Log::info('stateless-queue-example', [
            'marker' => 'DISPATCHED',
            'run_id' => $runId,
            'topic' => 'default+custom',
            'count' => 4,
        ]);

        return ['dispatched' => 4, 'run_id' => $runId];
    });
});
