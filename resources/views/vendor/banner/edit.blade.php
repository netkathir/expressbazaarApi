@extends('layouts.admin')

@section('content')
    <div class="card shell-card mb-4">
        <div class="card-body p-4">
            <h1 class="h3 mb-1">Home Banner</h1>
            <p class="text-secondary mb-3">
                You can save up to two banners. Saved banners are shown automatically on the customer home page to customers in your delivery area.
            </p>

            <ul class="nav nav-pills gap-2">
                @foreach ([1, 2] as $tab)
                    <li class="nav-item">
                        <a class="nav-link {{ $slot === $tab ? 'active' : 'border' }}" href="{{ route('vendor.banner.edit', ['slot' => $tab]) }}">
                            Banner {{ $tab }}
                            @if ($saved->has($tab))
                                <span class="badge text-bg-success ms-1">Saved</span>
                            @else
                                <span class="badge text-bg-secondary ms-1">Empty</span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($isShown)
                <div class="alert alert-success py-2 mt-3 mb-0">This banner is currently shown on the customer home page.</div>
            @endif
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card shell-card">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('vendor.banner.update', ['slot' => $slot]) }}" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label class="form-label">Shop name <span class="text-secondary small">(shown on the banner as "by &hellip;")</span></label>
                            <input type="text" name="shop_name" maxlength="60" class="form-control @error('shop_name') is-invalid @enderror"
                                   value="{{ old('shop_name', $banner->shop_name) }}" placeholder="{{ $vendor->vendor_name }}">
                            @error('shop_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Small label <span class="text-secondary small">(optional)</span></label>
                            <input type="text" name="eyebrow" maxlength="40" class="form-control @error('eyebrow') is-invalid @enderror"
                                   value="{{ old('eyebrow', $banner->eyebrow) }}" placeholder="Daily Essentials">
                            @error('eyebrow')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Heading <span class="text-danger">*</span></label>
                            <textarea name="heading" rows="2" maxlength="80" class="form-control @error('heading') is-invalid @enderror"
                                      placeholder="Your Daily Essentials,">{{ old('heading', $banner->heading) }}</textarea>
                            <div class="form-text">Press Enter for a new line.</div>
                            @error('heading')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Highlighted line <span class="text-secondary small">(optional, shown in green)</span></label>
                            <input type="text" name="highlight" maxlength="60" class="form-control @error('highlight') is-invalid @enderror"
                                   value="{{ old('highlight', $banner->highlight) }}" placeholder="Delivered Fresh">
                            @error('highlight')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-secondary small">(optional)</span></label>
                            <textarea name="description" rows="3" maxlength="220" class="form-control @error('description') is-invalid @enderror"
                                      placeholder="Shop groceries, fruits, spices and more with fast delivery.">{{ old('description', $banner->description) }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Banner picture <span class="text-secondary small">(JPG, PNG or WebP, up to 3 MB)</span></label>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp"
                                   class="form-control @error('image') is-invalid @enderror">
                            @error('image')<div class="invalid-feedback">{{ $message }}</div>@enderror

                            @if ($banner->image_path)
                                <div class="mt-2 d-flex align-items-center gap-3">
                                    <img src="{{ $banner->imageUrl() }}" alt="" style="height: 64px; border-radius: 8px;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_image">
                                        <label class="form-check-label" for="remove_image">Remove picture</label>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <button type="submit" class="btn btn-primary">Save banner {{ $slot }}</button>
                    </form>

                    @if ($saved->has($slot))
                        <form method="POST" action="{{ route('vendor.banner.destroy', ['slot' => $slot]) }}" class="mt-3"
                              onsubmit="return confirm('Delete banner {{ $slot }}? This cannot be undone.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete banner {{ $slot }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shell-card">
                <div class="card-body p-4">
                    <div class="fw-semibold mb-3">Saved preview</div>
                    @if ($banner->exists)
                        <div class="border rounded-3 p-3" style="background:#fffdf7;">
                            @if ($banner->shop_name)
                                <div class="small fw-semibold mb-1"><i class="ti ti-building-store text-success"></i> by {{ $banner->shop_name }}</div>
                            @endif
                            @if ($banner->eyebrow)
                                <span class="badge text-bg-success-subtle text-success mb-2">{{ $banner->eyebrow }}</span>
                            @endif
                            <div class="fw-bold fs-4" style="line-height:1.1;">{!! nl2br(e($banner->heading)) !!}</div>
                            @if ($banner->highlight)
                                <div class="fw-bold fs-4 text-success" style="line-height:1.1;">{{ $banner->highlight }}</div>
                            @endif
                            @if ($banner->description)
                                <p class="text-secondary mt-2 mb-3">{{ $banner->description }}</p>
                            @endif
                            <a href="{{ $banner->shopUrl() }}" class="btn btn-sm btn-dark rounded-pill">Shop Now</a>
                            <span class="btn btn-sm btn-outline-dark rounded-pill">Set Location</span>
                            <div class="small text-secondary mt-2">
                                Button goes to:
                                <strong>This store's products</strong>
                            </div>
                            <img src="{{ $banner->imageUrl() }}" alt="" class="d-block mt-3 w-100" style="max-height:180px; object-fit:cover; border-radius:12px;">
                        </div>
                    @else
                        <p class="text-secondary mb-0">Nothing saved yet. Fill in the form and press Save.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
