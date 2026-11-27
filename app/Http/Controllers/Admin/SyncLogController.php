<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Models\Vendor;
use App\Services\SyncLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SyncLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = SyncLog::query()
            ->with(['vendor:id,vendor_name', 'product:id,product_name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', (int) $request->input('vendor_id')))
            ->latest('id')
            ->paginate(\App\Support\PerPage::size(20))
            ->withQueryString();

        $since = now()->subDay();

        return view('admin.sync-logs.index', [
            'title' => 'Sync Monitoring',
            'activeMenu' => 'sync-logs',
            'logs' => $logs,
            'stats' => [
                'success_24h' => SyncLog::where('status', SyncLog::STATUS_SUCCESS)->where('created_at', '>=', $since)->count(),
                'failed_24h' => SyncLog::whereIn('status', [SyncLog::STATUS_FAILED, SyncLog::STATUS_DEAD])->where('created_at', '>=', $since)->count(),
                'awaiting_retry' => SyncLog::where('status', SyncLog::STATUS_FAILED)->count(),
                'dead' => SyncLog::where('status', SyncLog::STATUS_DEAD)->count(),
                'last_success' => SyncLog::where('status', SyncLog::STATUS_SUCCESS)->max('resolved_at'),
            ],
            'vendors' => Vendor::query()->where('inventory_mode', 'pos')->orderBy('vendor_name')->get(['id', 'vendor_name']),
            'events' => SyncLog::query()->distinct()->orderBy('event')->pluck('event'),
            'statuses' => [
                SyncLog::STATUS_SUCCESS,
                SyncLog::STATUS_FAILED,
                SyncLog::STATUS_DEAD,
                SyncLog::STATUS_SKIPPED,
                SyncLog::STATUS_SUPERSEDED,
                SyncLog::STATUS_PROCESSING,
            ],
        ]);
    }

    public function retry(SyncLog $syncLog, SyncLogService $service): RedirectResponse
    {
        if (! $syncLog->isRetryable()) {
            return back()->withErrors(['sync' => 'Sync event #'.$syncLog->id.' cannot be retried.']);
        }

        return $service->retry($syncLog, manual: true)
            ? back()->with('success', 'Sync event #'.$syncLog->id.' retried successfully.')
            : back()->withErrors(['sync' => 'Retry of sync event #'.$syncLog->id.' failed: '.($syncLog->fresh()->error_message ?? 'unknown error')]);
    }

    public function retryAll(SyncLogService $service): RedirectResponse
    {
        $logs = SyncLog::query()
            ->where('status', SyncLog::STATUS_DEAD)
            ->whereNotNull('vendor_id')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $succeeded = $logs->filter(fn (SyncLog $log) => $service->retry($log, manual: true))->count();

        return back()->with('success', "Retried {$logs->count()} dead event(s); {$succeeded} succeeded.");
    }
}
