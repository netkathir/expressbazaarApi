@extends('layouts.admin')

@push('head')
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        /* Match the admin form fields: 32px tall, 13px text. */
        .select2-container--default .select2-selection--single {
            display: flex;
            align-items: center;
            height: 32px;
            border: var(--bs-border-width, 1px) solid var(--bs-border-color, #dee2e6);
            border-radius: var(--bs-border-radius, .375rem);
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered,
        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            padding-left: .75rem;
            font-size: 13px;
            line-height: 30px;
            color: var(--bs-body-color, #212529);
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            padding-left: 0;
            color: var(--admin-muted, #6c757d);
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 30px;
            right: 6px;
        }

        select.is-invalid ~ .select2-container .select2-selection--single {
            border-color: var(--bs-form-invalid-border-color, #dc3545);
        }

        .select2-dropdown {
            font-size: 13px;
        }

        .select2-search--dropdown .select2-search__field {
            height: 30px;
            padding: 3px .5rem;
            font-size: 13px;
        }

        .select2-results__option {
            padding: 6px .75rem;
            font-size: 13px;
        }
    </style>
@endpush

@section('content')
    @php
        $selectedProduct = $products->firstWhere('id', (int) old('product_id'));
        $currentStock = (int) ($selectedProduct?->inventory?->stock_quantity ?? 0);
    @endphp

    <div class="card shell-card">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <h1 class="h3 mb-1">Adjust Stock</h1>
                </div>
                <a href="{{ route('vendor.inventory.index') }}" class="btn btn-outline-secondary" data-dirty-back>Back</a>
            </div>

            <form method="POST" action="{{ route('vendor.inventory.store') }}" class="row g-3" data-dirty-check>
                @csrf

                <div class="col-md-6">
                    <label class="form-label">Product</label>
                    <select name="product_id" class="form-select" id="inventoryProductSelect" required>
                        <option value="">Select product</option>
                        @foreach ($products as $product)
                            <option
                                value="{{ $product->id }}"
                                data-stock="{{ (int) ($product->inventory?->stock_quantity ?? 0) }}"
                                data-pos="{{ $product->inventory_mode === 'pos' ? '1' : '0' }}"
                                @selected((string) old('product_id') === (string) $product->id)
                            >
                                {{ $product->name_with_source }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text" id="posStockNote" hidden>This is a POS product. The adjustment will also be sent to Zoho POS.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Current Stock</label>
                    <input type="text" class="form-control" id="currentStockDisplay" value="{{ $currentStock }}" readonly>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Adjustment Type</label>
                    <select name="adjustment_type" class="form-select" required>
                        <option value="add" @selected(old('adjustment_type') === 'add')>Add</option>
                        <option value="reduce" @selected(old('adjustment_type') === 'reduce')>Reduce</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Quantity</label>
                    <input type="number" min="1" name="quantity" value="{{ old('quantity', 1) }}" class="form-control" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Reason</label>
                    <input type="text" name="reason" value="{{ old('reason') }}" class="form-control" placeholder="Optional note">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Save Adjustment</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const productSelect = document.getElementById('inventoryProductSelect');
            const stockDisplay = document.getElementById('currentStockDisplay');

            if (!productSelect || !stockDisplay) {
                return;
            }

            $(productSelect)
                .select2({
                    placeholder: 'Search or select product',
                    width: '100%',
                    language: { noResults: () => 'No matching products' },
                })
                // Select2 only fires jQuery events; re-dispatch so native listeners (stock display, inline validation) run.
                .on('select2:select select2:clear', () => productSelect.dispatchEvent(new Event('change', { bubbles: true })));

            const posNote = document.getElementById('posStockNote');

            const syncCurrentStock = () => {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                stockDisplay.value = selectedOption?.dataset.stock || '0';

                if (posNote) {
                    posNote.hidden = selectedOption?.dataset.pos !== '1';
                }
            };

            productSelect.addEventListener('change', syncCurrentStock);
            syncCurrentStock();
        });
    </script>
@endpush
