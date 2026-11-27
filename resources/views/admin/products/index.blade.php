@extends('layouts.admin')

@section('content')
    @php
        $routePrefix = $routePrefix ?? 'admin.products';
        $isVendorPanel = $isVendorPanel ?? false;
        $panelUser = $isVendorPanel ? auth('vendor')->user() : auth()->user();
        $canCreateProducts = $isVendorPanel
            ? $panelUser?->hasRolePermission('products', 'create')
            : ($panelUser?->hasRolePermission('products', 'create') ?? true);
        $canEditProducts = $isVendorPanel
            ? $panelUser?->hasRolePermission('products', 'edit')
            : ($panelUser?->hasRolePermission('products', 'edit') ?? true);
        $canDeleteProducts = $isVendorPanel
            ? $panelUser?->hasRolePermission('products', 'delete')
            : ($panelUser?->hasRolePermission('products', 'delete') ?? true);
    @endphp
    <div class="card shell-card mb-3">
        <div class="card-body px-3 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h1 class="h3 mb-0">Product Management</h1>
            </div>
            @if ($canCreateProducts)
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route($routePrefix.'.bulk') }}" class="btn btn-sm btn-outline-primary">Bulk Import</a>
                    <a href="{{ route($routePrefix.'.create') }}" class="btn btn-sm btn-primary">Add Product</a>
                </div>
            @endif
        </div>
    </div>

    @if (! empty($posSyncWarning))
        <div class="alert alert-warning">{{ $posSyncWarning }}</div>
    @endif

    <div class="card shell-card mb-3">
        <div class="card-body px-3 py-2">
            <form class="row g-2 align-items-end" method="GET">
                <div class="col-md-4">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" class="form-control form-control-sm" placeholder="Product name">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Category</label>
                    <select name="category_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->category_name }}</option>
                        @endforeach
                    </select>
                </div>
                @unless ($isVendorPanel)
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Vendor</label>
                        <select name="vendor_id" class="form-select form-select-sm">
                            <option value="">All</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) request('vendor_id') === (string) $vendor->id)>{{ $vendor->vendor_name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endunless
                <div class="col-md-2">
                    <label class="form-label small mb-1">Mode</label>
                    <select name="inventory_mode" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="internal" @selected(request('inventory_mode') === 'internal')>Internal</option>
                        <option value="pos" @selected(request('inventory_mode') === 'pos')>POS</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active" @selected(request('status') === 'active')>Active</option>
                        <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Discount</label>
                    <select name="discount" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active" @selected(request('discount') === 'active')>Active discount</option>
                    </select>
                </div>
                <div class="col-12 col-md-auto ms-auto d-flex gap-2 justify-content-end">
                    <button class="btn btn-sm btn-dark" type="submit">Filter</button>
                    <a href="{{ route($routePrefix.'.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>


    <div class="card shell-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Images</th>
                        <th>Category</th>
                        <th>Tax</th>
                        <th>Vendor</th>
                        <th>Price</th>
                        <th>Inventory</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($products as $product)
                        <tr>
                            <td class="fw-semibold">{{ $product->name_with_source }}</td>
                            <td>
                                @if ($product->images->isNotEmpty())
                                    <div class="d-flex flex-wrap gap-2">
                                        @foreach ($product->images->take(2) as $image)
                                            <img src="{{ asset($image->image_path) }}" alt="{{ $product->product_name }}" style="width: 34px; height: 34px; object-fit: cover; border-radius: 8px;" onerror="{{ \App\Support\StoreImage::onError('product') }}">
                                        @endforeach
                                        @if ($product->images->count() > 2)
                                            <span class="small text-secondary align-self-center">+{{ $product->images->count() - 2 }} more</span>
                                        @endif
                                    </div>
                                @else
                                    <img src="{{ \App\Support\StoreImage::product($product) }}" alt="{{ $product->product_name }}" style="width: 34px; height: 34px; object-fit: cover; border-radius: 8px;" onerror="{{ \App\Support\StoreImage::onError('product') }}">
                                @endif
                            </td>
                            <td>
                                {{ $product->category?->category_name ?? '-' }}
                                <div class="small text-secondary">{{ $product->subcategory?->subcategory_name ?? '' }}</div>
                            </td>
                            <td>{{ $product->tax?->tax_name ?? '-' }}</td>
                            <td>{{ $product->vendor?->vendor_name ?? '-' }}</td>
                            <td>
                                @if ($product->has_active_discount)
                                    <div class="fw-semibold">{{ \App\Support\StoreCurrency::format($product->final_price) }}</div>
                                    <div class="small text-secondary text-decoration-line-through">{{ \App\Support\StoreCurrency::format($product->price) }}</div>
                                    <span class="badge text-bg-success">Discount active · {{ $product->discount_tag }}</span>
                                @else
                                    {{ \App\Support\StoreCurrency::format($product->price) }}
                                @endif
                            </td>
                            <td>
                                <span class="badge text-bg-{{ $product->inventory_mode === 'pos' ? 'info' : 'primary' }}">{{ $product->inventory_mode === 'pos' ? 'POS' : strtoupper($product->inventory_mode) }}</span>
                                <div class="small text-secondary">
                                    {{ $product->inventory?->stock_quantity ?? 0 }} {{ $product->unit ?: $product->inventory?->unit }}
                                </div>
                            </td>
                            <td><span class="badge text-bg-{{ $product->status === 'active' ? 'success' : 'secondary' }}">{{ ucfirst($product->status) }}</span></td>
                            <td class="text-end">
                                @if ($canEditProducts)
                                    <a href="{{ route($routePrefix.'.edit', $product) }}" class="btn btn-sm btn-outline-primary" aria-label="Edit product" title="Edit product">
                                        <i class="ti ti-pencil"></i>
                                    </a>
                                @endif
                                @if ($canDeleteProducts)
                                    <form action="{{ route($routePrefix.'.destroy', $product) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this product?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Delete product" title="Delete product">
                                            <i class="ti ti-trash"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-secondary py-5">{{ config('ui_messages.no_products') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-body">
            @include('partials.pagination', ['paginator' => $products])
        </div>
    </div>
@endsection
