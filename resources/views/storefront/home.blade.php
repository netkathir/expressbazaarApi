@extends('layouts.storefront')

@section('content')
    <main class="sf-page sf-home-page">
        @php($filterQuery = array_filter([
            'pincode' => $pincode ?? null,
            'vendor_id' => $selectedVendorId ?? request('vendor_id'),
        ], fn ($value) => filled($value)))
        @php($search = $search ?? '')
        @php($isSearch = filled($search))
        @php($selectedVendorProducts = $selectedVendorProducts ?? collect())
        @php($showNoPincodeData = !empty($pincode ?? null) && !($hasPincodeProducts ?? true))
        @php($availableVendors = $vendors ?? collect())
        @php($topStatusMessage = null)
        @php($safeRouteUrl = $safeRouteUrl ?? function (string $name, string $fallback, array $parameters = [], bool $absolute = true) {
            if (\Illuminate\Support\Facades\Route::has($name)) {
                return route($name, $parameters, $absolute);
            }

            return $absolute ? url($fallback) : (parse_url(url($fallback), PHP_URL_PATH) ?: $fallback);
        })
        @php($filterQueryString = http_build_query($filterQuery))
        @php($homeFilterUrl = '/'.($filterQueryString ? '?'.$filterQueryString : ''))
        @if (!$isSearch && !empty($location ?? null) && $availableVendors->isEmpty())
            @php($topStatusMessage = config('ui_messages.no_vendors'))
        @elseif (!$isSearch && ($selectedVendor ?? null) && $selectedVendorProducts->isEmpty())
            @php($topStatusMessage = config('ui_messages.no_vendor_products'))
        @elseif (!$isSearch && $showNoPincodeData)
            @php($topStatusMessage = config('ui_messages.no_products'))
        @endif
        @if ($isSearch)
            <section class="container-fluid px-3 px-lg-4 pt-0 mb-4">
                <div class="sf-section-header">
                    <div>
                        <h3>Search Results</h3>
                        <p class="text-secondary mb-0">Results for "{{ $search }}"</p>
                    </div>
                    <a href="{{ $safeRouteUrl('user.home', $homeFilterUrl, $filterQuery) }}" class="btn btn-sm btn-light ms-2">Clear</a>
                </div>
                <div class="sf-grid js-product-list" id="product-list">
                    @include('storefront.partials.product-grid', [
                        'products' => $searchResults,
                        'emptyMessage' => 'No results found',
                    ])
                </div>
            </section>
        @endif

        @if (!$isSearch)
            <section class="container-fluid px-3 px-lg-4 pt-0 sf-promo-showcase">
                <div class="sf-promo-grid">
                    @php($homeBanners = $homeBanners ?? \App\Support\HomeBanner::selected())
                    <div class="sf-promo-card sf-promo-card-primary js-promo-slider" aria-label="Featured offers">
                        <div class="sf-promo-slides">
                            @if ($homeBanners->isNotEmpty())
                                {{-- Banners chosen by the admin, in the admin's order, from vendors' saved fields --}}
                                @foreach ($homeBanners as $homeBanner)
                                <div class="sf-promo-slide {{ $loop->first ? 'is-active' : '' }}" data-promo-slide>
                                    <div class="sf-promo-copy">
                                        @if (filled($homeBanner->shop_name))
                                            <span class="sf-promo-shop"><i class="ti ti-building-store"></i> by {{ $homeBanner->shop_name }}</span>
                                        @endif
                                        @if (filled($homeBanner->eyebrow))
                                            <span class="sf-promo-eyebrow">{{ $homeBanner->eyebrow }}</span>
                                        @endif
                                        <h1>{!! nl2br(e($homeBanner->heading)) !!}@if (filled($homeBanner->highlight))<br><span>{{ $homeBanner->highlight }}</span>@endif</h1>
                                        @if (filled($homeBanner->description))
                                            <p>{{ $homeBanner->description }}</p>
                                        @endif
                                        <div class="sf-promo-actions">
                                            <a href="{{ $homeBanner->shopUrl() }}" class="btn btn-dark rounded-pill px-4">Shop Now <i class="ti ti-arrow-right ms-1"></i></a>
                                        </div>
                                    </div>
                                    <div class="sf-promo-visual" aria-hidden="true">
                                        <div class="sf-promo-offer-frame">
                                            <img src="{{ $homeBanner->imageUrl() }}" alt="">
                                        </div>
                                    </div>
                                </div>
                                @endforeach
                            @else
                            <div class="sf-promo-slide is-active" data-promo-slide>
                                <div class="sf-promo-copy">
                                    <span class="sf-promo-eyebrow">Daily Essentials</span>
                                    <h1>Your Daily<br>Essentials,<br><span>Delivered Fresh</span></h1>
                                    <p>Shop groceries, fruits, spices, and more with instant add-to-cart and fast delivery.</p>
                                    <div class="sf-promo-actions">
                                        <a href="#featured-sections" class="btn btn-dark rounded-pill px-4">Shop Now <i class="ti ti-arrow-right ms-1"></i></a>
                                    </div>
                                </div>
                                <div class="sf-promo-visual" aria-hidden="true">
                                    <div class="sf-promo-offer-frame">
                                        <img src="{{ asset('sample-assets/banner/1560931223Oq6Ox.jpg') }}" alt="">
                                    </div>
                                </div>
                            </div>
                            <div class="sf-promo-slide" data-promo-slide>
                                <div class="sf-promo-copy">
                                    <span class="sf-promo-eyebrow">Fresh Deals</span>
                                    <h1>Fruits &amp;<br>Vegetables,<br><span>Big Savings</span></h1>
                                    <p>Pick fresh produce for daily cooking with quick browsing and one-vendor checkout.</p>
                                    <div class="sf-promo-actions">
                                        <a href="#top-offers" class="btn btn-dark rounded-pill px-4">View Offers <i class="ti ti-arrow-right ms-1"></i></a>
                                    </div>
                                </div>
                                <div class="sf-promo-visual" aria-hidden="true">
                                    <div class="sf-promo-offer-frame">
                                        <img src="{{ asset('sample-assets/banner/1562925151VT7ep.jpg') }}" alt="">
                                    </div>
                                </div>
                            </div>
                            <div class="sf-promo-slide" data-promo-slide>
                                <div class="sf-promo-copy">
                                    <span class="sf-promo-eyebrow">Weekend Offer</span>
                                    <h1>Stock Up<br>Pantry Picks,<br><span>Save More</span></h1>
                                    <p>Find cooking essentials, snacks, and household favorites from nearby vendors.</p>
                                    <div class="sf-promo-actions">
                                        <a href="#featured-sections" class="btn btn-dark rounded-pill px-4">Shop Deals <i class="ti ti-arrow-right ms-1"></i></a>
                                    </div>
                                </div>
                                <div class="sf-promo-visual" aria-hidden="true">
                                    <div class="sf-promo-offer-frame">
                                        <img src="{{ asset('sample-assets/banner/1562925306rlzPX.jpg') }}" alt="">
                                    </div>
                                </div>
                            </div>
                            <div class="sf-promo-slide" data-promo-slide>
                                <div class="sf-promo-copy">
                                    <span class="sf-promo-eyebrow">Limited Discount</span>
                                    <h1>Hot Offers<br>Near You,<br><span>Ready Fast</span></h1>
                                    <p>Browse local offers by city, select your vendor, and keep checkout simple.</p>
                                    <div class="sf-promo-actions">
                                        <a href="#top-offers" class="btn btn-dark rounded-pill px-4">Explore Deals <i class="ti ti-arrow-right ms-1"></i></a>
                                    </div>
                                </div>
                                <div class="sf-promo-visual" aria-hidden="true">
                                    <div class="sf-promo-offer-frame">
                                        <img src="{{ asset('sample-assets/banner/1563889380AxUAu.jpg') }}" alt="">
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>
                        @if ($homeBanners->count() > 1)
                        <div class="sf-promo-dots" role="tablist" aria-label="Choose featured offer">
                            @foreach ($homeBanners as $homeBanner)
                                <button class="{{ $loop->first ? 'is-active' : '' }}" type="button" data-promo-dot aria-label="Show offer {{ $loop->iteration }}"></button>
                            @endforeach
                        </div>
                        <button type="button" class="sf-promo-nav sf-promo-nav-prev" data-promo-prev aria-label="Previous offer">
                            <i class="ti ti-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="sf-promo-nav sf-promo-nav-next" data-promo-next aria-label="Next offer">
                            <i class="ti ti-chevron-right" aria-hidden="true"></i>
                        </button>
                        @endif
                        @if ($homeBanners->isEmpty())
                        <div class="sf-promo-dots" role="tablist" aria-label="Choose featured offer">
                            <button class="is-active" type="button" data-promo-dot aria-label="Show daily essentials offer"></button>
                            <button type="button" data-promo-dot aria-label="Show fresh deals offer"></button>
                            <button type="button" data-promo-dot aria-label="Show pantry offer"></button>
                            <button type="button" data-promo-dot aria-label="Show local discount offer"></button>
                        </div>
                        <button type="button" class="sf-promo-nav sf-promo-nav-prev" data-promo-prev aria-label="Previous offer">
                            <i class="ti ti-chevron-left" aria-hidden="true"></i>
                        </button>
                        <button type="button" class="sf-promo-nav sf-promo-nav-next" data-promo-next aria-label="Next offer">
                            <i class="ti ti-chevron-right" aria-hidden="true"></i>
                        </button>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        @if (!$isSearch)
            <section class="container-fluid px-3 px-lg-4 pt-0 sf-promo-showcase">
                <div class="sf-service-strip">
                    <div><i class="ti ti-leaf"></i><strong>Fresh &amp; Quality</strong><span>100% quality assured</span></div>
                    <div><i class="ti ti-category"></i><strong>Wide Range</strong><span>1000+ products</span></div>
                    <div><i class="ti ti-truck-delivery"></i><strong>Fast Delivery</strong><span>Quick &amp; reliable</span></div>
                    <div><i class="ti ti-shield-check"></i><strong>Secure Payments</strong><span>100% secure checkout</span></div>
                    <div><i class="ti ti-refresh"></i><strong>Easy Returns</strong><span>Hassle-free returns</span></div>
                </div>
            </section>
        @endif

        @if (!$isSearch)
            <section class="container-fluid px-3 px-lg-4 mt-4 mb-2">
                <div class="sf-section-header">
                    <div>
                        <h3>Shop by category</h3>
                        <p class="text-secondary mb-0">{{ ($selectedVendor ?? null) ? 'Filtered for '.$selectedVendor->vendor_name : 'Products from available vendors' }}</p>
                    </div>
                </div>
            </section>
        @endif
        <section class="container-fluid px-3 px-lg-4 pt-0 sf-category-showcase">
            @if ($topStatusMessage)
                <div class="sf-category-strip-header sf-category-strip-status">
                    <div class="sf-top-status js-storefront-status">
                        {{ $topStatusMessage }}
                    </div>
                </div>
            @endif
            <div class="sf-rail-wrap sf-category-rail-wrap">
                <button type="button" class="sf-rail-arrow sf-rail-arrow-left js-rail-scroll" data-direction="-1" aria-label="Scroll categories left">
                    <i class="ti ti-chevron-left"></i>
                </button>
                <div class="sf-chip-row">
                    @foreach ($categories as $category)
                        <a href="{{ $safeRouteUrl('storefront.category', '/categories/'.$category->getRouteKey().($filterQueryString ? '?'.$filterQueryString : ''), array_merge(['category' => $category], $filterQuery)) }}" class="sf-chip">
                            <span class="sf-chip-image">
                                <img src="{{ \App\Support\StoreImage::category($category) }}" alt="{{ $category->category_name }}" onerror="{{ \App\Support\StoreImage::onError('category') }}">
                            </span>
                            <span>{{ $category->category_name }}</span>
                        </a>
                    @endforeach
                </div>
                <button type="button" class="sf-rail-arrow sf-rail-arrow-right js-rail-scroll" data-direction="1" aria-label="Scroll categories right">
                    <i class="ti ti-chevron-right"></i>
                </button>
            </div>
        </section>

        @if (!$isSearch && !$showNoPincodeData && ($discountedProducts ?? collect())->isNotEmpty())
            @php($topOfferCategory = $discountedProducts->first()?->category ?? $categories->first())
            <section id="top-offers" class="container-fluid px-3 px-lg-4 mt-4">
                <div class="sf-section-header">
                    <div>
                        <h3>Top offers</h3>
                        <p class="text-secondary mb-0">Discounted products picked from the live sample catalog.</p>
                    </div>
                    @if ($topOfferCategory)
                        <a href="{{ $safeRouteUrl('storefront.category', '/categories/'.$topOfferCategory->getRouteKey().($filterQueryString ? '?'.$filterQueryString : ''), array_merge(['category' => $topOfferCategory], $filterQuery)) }}">See all</a>
                    @endif
                </div>
                <div class="sf-rail-wrap">
                    <button type="button" class="sf-rail-arrow sf-rail-arrow-left js-rail-scroll" data-direction="-1" aria-label="Scroll offers left">
                        <i class="ti ti-chevron-left"></i>
                    </button>
                    <div class="sf-product-rail">
                        @foreach ($discountedProducts as $product)
                            @include('storefront.partials.product-card', ['product' => $product])
                        @endforeach
                    </div>
                    <button type="button" class="sf-rail-arrow sf-rail-arrow-right js-rail-scroll" data-direction="1" aria-label="Scroll offers right">
                        <i class="ti ti-chevron-right"></i>
                    </button>
                </div>
            </section>
        @endif

        @if (!$isSearch && $showNoPincodeData)
            <section class="container-fluid px-3 px-lg-4 mt-4">
                <x-empty-state>{{ config('ui_messages.no_products') }}</x-empty-state>
            </section>
        @elseif (!$isSearch)
            <section id="featured-sections" class="container-fluid px-3 px-lg-4 mt-4">
                @include('storefront.partials.product-sections', [
                    'sections' => $categoryProductSections ?? [],
                    'emptyMessage' => config('ui_messages.no_products'),
                ])
            </section>

            @if (($banners ?? collect())->isNotEmpty())
                <section class="container-fluid px-3 px-lg-4 mt-4">
                    <div class="sf-banner-grid">
                        @foreach ($banners as $banner)
                            <a href="{{ $banner->link_url ?: '#' }}" class="sf-banner-card" style="background-image: linear-gradient(135deg, rgba(0,0,0,.25), rgba(0,0,0,.1)), url('{{ \App\Support\StoreImage::banner($banner->image_path) }}');">
                                <div class="sf-banner-copy">
                                    <span class="sf-kicker text-white-50">Featured</span>
                                    <h2>{{ $banner->title }}</h2>
                                    @if ($banner->subtitle)
                                        <p>{{ $banner->subtitle }}</p>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        @endif

        <section class="container-fluid px-3 px-lg-4 mt-5">
            <div class="sf-info-card sf-how-it-works">
                <div class="sf-how-header">
                    <div>
                        <span class="sf-kicker">Simple flow</span>
                        <h4 class="mb-0">How it works</h4>
                    </div>
                    <span class="sf-how-badge">3 steps</span>
                </div>
                <div class="row g-3 sf-how-grid">
                    <div class="col-12 col-md-4">
                        <div class="sf-mini-step">
                            <strong>1</strong>
                            <i class="ti ti-compass"></i>
                            <span class="sf-step-line"></span>
                            <div>
                                <div class="fw-semibold">Open the app</div>
                                <div class="text-secondary small">Browse instantly without a blocking popup.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="sf-mini-step">
                            <strong>2</strong>
                            <i class="ti ti-basket-plus"></i>
                            <span class="sf-step-line"></span>
                            <div>
                                <div class="fw-semibold">Add items</div>
                                <div class="text-secondary small">Quick add keeps the cart locked to one vendor.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="sf-mini-step">
                            <strong>3</strong>
                            <i class="ti ti-map-check"></i>
                            <span class="sf-step-line"></span>
                            <div>
                                <div class="fw-semibold">Checkout</div>
                                <div class="text-secondary small">Exact delivery validation happens only when needed.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </main>
@endsection
