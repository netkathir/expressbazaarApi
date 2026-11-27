@extends('layouts.admin')

@section('content')
    <div class="card shell-card">
        <div class="card-body p-4 p-md-5">
            <div class="mb-4">
                <h1 class="h3 mb-1">Select Zoho POS Store</h1>
                <p class="text-secondary mb-0">This Zoho login can access more than one organization. Choose which store to link to this vendor.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('vendor.zoho-pos.select.store') }}" class="d-grid gap-3">
                @csrf
                @foreach ($organizations as $organization)
                    <label class="border rounded-3 p-3 d-flex gap-3 align-items-start">
                        <input
                            type="radio"
                            name="organization_id"
                            value="{{ $organization['organization_id'] }}"
                            class="mt-1"
                            @checked(old('organization_id') === $organization['organization_id'])
                            required
                        >
                        <span>
                            <span class="fw-semibold d-block">{{ $organization['name'] }}</span>
                            <span class="text-secondary small">Organization ID: {{ $organization['organization_id'] }}</span>
                        </span>
                    </label>
                @endforeach

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Connect selected store</button>
                    <a href="{{ route('vendor.zoho-pos.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
@endsection
