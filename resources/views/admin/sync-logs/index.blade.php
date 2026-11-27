@extends('layouts.admin')

@section('content')
    @php
        $badge = fn ($status) => match ($status) {
            'success' => 'success',
            'failed' => 'warning',
            'dead' => 'danger',
            'processing' => 'info',
            default => 'secondary',
        };
    @endphp

    <div class="card shell-card mb-4">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1">Sync Monitoring</h1>
                <p class="text-secondary mb-0">Zoho POS sync activity. Failed events retry automatically (1, 5, 15, 60, 240 min), then move to Dead.</p>
            </div>
            @canRoute('admin.sync-logs.retry-all', 'POST')
                @if ($stats['dead'] > 0)
                    <form method="POST" action="{{ route('admin.sync-logs.retry-all') }}" onsubmit="return confirm('Retry up to 50 dead events now?');">
                        @csrf
                        <button class="btn btn-outline-danger">Retry all dead ({{ $stats['dead'] }})</button>
                    </form>
                @endif
            @endcanRoute
        </div>
    </div>

    <div class="row g-3 mb-2">
        @foreach ([
            ['Succeeded (24h)', $stats['success_24h'], 'success'],
            ['Failed (24h)', $stats['failed_24h'], $stats['failed_24h'] ? 'danger' : 'secondary'],
            ['Awaiting retry', $stats['awaiting_retry'], $stats['awaiting_retry'] ? 'warning' : 'secondary'],
            ['Dead (needs attention)', $stats['dead'], $stats['dead'] ? 'danger' : 'secondary'],
        ] as [$label, $value, $tone])
            <div class="col-6 col-lg-3">
                <div class="card shell-card h-100">
                    <div class="card-body">
                        <div class="text-secondary small">{{ $label }}</div>
                        <div class="h2 mb-0 text-{{ $tone }}">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    <p class="text-secondary small mb-3">
        Last successful sync:
        {{ $stats['last_success'] ? \App\Support\StoreDate::dateTime(\Illuminate\Support\Carbon::parse($stats['last_success'])) : 'never' }}
    </p>

    <div class="card shell-card mb-4">
        <form method="GET" class="card-body row g-2 align-items-end">
            <div class="col-6 col-md-2">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small">Direction</label>
                <select name="direction" class="form-select">
                    <option value="">All</option>
                    <option value="inbound" @selected(request('direction') === 'inbound')>Inbound (Zoho → us)</option>
                    <option value="outbound" @selected(request('direction') === 'outbound')>Outbound (us → Zoho)</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small">Event</label>
                <select name="event" class="form-select">
                    <option value="">All</option>
                    @foreach ($events as $event)
                        <option value="{{ $event }}" @selected(request('event') === $event)>{{ $event }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small">Vendor</label>
                <select name="vendor_id" class="form-select">
                    <option value="">All</option>
                    @foreach ($vendors as $vendor)
                        <option value="{{ $vendor->id }}" @selected((string) request('vendor_id') === (string) $vendor->id)>{{ $vendor->vendor_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1">Filter</button>
                <a href="{{ route('admin.sync-logs.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card shell-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>When</th>
                        <th>Event</th>
                        <th>Vendor / Product</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Error / Next retry</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td>{{ $log->id }}</td>
                            <td class="text-nowrap">{{ \App\Support\StoreDate::dateTime($log->created_at) }}</td>
                            <td>
                                {{ $log->event }}
                                <div class="small text-secondary">
                                    {{ $log->direction }}@if ($log->order_id) · order #{{ $log->order_id }}@endif
                                </div>
                            </td>
                            <td>
                                {{ $log->vendor?->vendor_name ?? '—' }}
                                <div class="small text-secondary">
                                    {{ $log->product?->name_with_source ?? ($log->zoho_item_id ? 'Zoho item '.$log->zoho_item_id : '') }}
                                </div>
                            </td>
                            <td><span class="badge text-bg-{{ $badge($log->status) }}">{{ ucfirst($log->status) }}</span></td>
                            <td>{{ $log->attempts }}/{{ $log->max_attempts }}</td>
                            <td style="max-width: 360px;">
                                @if ($log->error_message)
                                    <div class="small text-break">{{ \Illuminate\Support\Str::limit($log->error_message, 160) }}</div>
                                @endif
                                @if ($log->status === 'failed' && $log->next_retry_at)
                                    <div class="small text-secondary">Next retry {{ \App\Support\StoreDate::dateTime($log->next_retry_at) }}</div>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($log->isRetryable())
                                    @canRoute('admin.sync-logs.retry', 'POST')
                                        <form method="POST" action="{{ route('admin.sync-logs.retry', $log) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary">Retry now</button>
                                        </form>
                                    @endcanRoute
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-5">No sync events found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body">
            @include('partials.pagination', ['paginator' => $logs])
        </div>
    </div>
@endsection
