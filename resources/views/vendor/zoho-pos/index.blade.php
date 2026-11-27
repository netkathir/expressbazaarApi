@extends('layouts.admin')

@section('content')
    <div class="card shell-card">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1">Zoho POS Connection</h1>
                    <p class="text-secondary mb-0">Link this vendor to a Zoho POS store for automatic product sync.</p>
                </div>
            </div>

            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($connected)
                <div class="border rounded-3 p-4 mb-4">
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
                        <div>
                            <div class="badge text-bg-success mb-2">Connected</div>
                            <div class="fw-semibold">{{ $zoho['organization_name'] ?? 'Zoho POS Store' }}</div>
                            <div class="text-secondary small mt-1">Organization ID: {{ $vendor->zoho_organization_id ?? '—' }}</div>
                            @if (! empty($zoho['connected_at']))
                                <div class="text-secondary small">Connected at: {{ $zoho['connected_at'] }}</div>
                            @endif
                            @if (! empty($zoho['last_catalog_sync_at']))
                                <div class="text-secondary small">Last catalog sync: {{ $zoho['last_catalog_sync_at'] }}</div>
                            @endif
                            <div class="text-secondary small">Inventory mode: {{ $vendor->inventory_mode === 'pos' ? 'POS' : ucfirst((string) $vendor->inventory_mode) }}</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('vendor.zoho-pos.sync') }}">
                                @csrf
                                <button type="submit" class="btn btn-primary">
                                    <i class="ti ti-refresh me-1"></i>Sync now
                                </button>
                            </form>
                            <form method="POST" action="{{ route('vendor.zoho-pos.disconnect') }}" onsubmit="return confirm('Disconnect Zoho POS from this vendor?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">Disconnect</button>
                            </form>
                        </div>
                    </div>
                </div>
            @else
                <div class="border rounded-3 p-4">
                    <p class="mb-3">No Zoho POS store is linked yet. Connect with the Zoho account that has access to your store (for example Kitchen Fresh Grocery Stores).</p>
                    <a href="{{ route('vendor.zoho-pos.connect') }}" class="btn btn-primary">
                        <i class="ti ti-plug me-1"></i>Connect Zoho POS
                    </a>
                </div>
            @endif
        </div>
    </div>
@endsection
