<?php

namespace App\Support;

use App\Models\Category;
use App\Models\VendorBanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The banners shown on the customer home page. Each vendor can save up to two
 * banners, and every saved banner of an active vendor is shown. With none the
 * built-in slides are shown.
 */
class HomeBanner
{
    public const MAX_PER_VENDOR = 2;

    /**
     * Saved banners of active vendors, ordered by vendor then slot. Banners with
     * no heading are skipped. Pass $vendorIds to keep only those vendors' banners.
     *
     * @param  array<int, int>|null  $vendorIds
     * @return Collection<int, VendorBanner>
     */
    public static function selected(?array $vendorIds = null): Collection
    {
        if (! Schema::hasTable('vendor_banners') || $vendorIds === []) {
            return collect();
        }

        return VendorBanner::query()
            ->with('vendor')
            ->whereHas('vendor', fn ($query) => $query->where('status', 'active'))
            ->when($vendorIds !== null, fn ($query) => $query->whereIn('vendor_id', $vendorIds))
            ->orderBy('vendor_id')
            ->orderBy('slot')
            ->get()
            ->filter(fn (VendorBanner $banner) => trim((string) $banner->heading) !== '')
            ->values();
    }

    /**
     * Plain-language places the banner button can lead to, grouped for a dropdown.
     *
     * @return array<string, array<string, string>> group label => [link => label]
     */
    public static function destinations(): array
    {
        $groups = [
            'On the home page' => [
                '#featured-sections' => 'Featured products',
                '#top-offers' => 'Top offers',
            ],
            'Other pages' => [
                '/cart' => 'Shopping cart',
                '/' => 'Home page (top)',
            ],
        ];

        if (Schema::hasTable('categories')) {
            $categories = Category::query()
                ->where('status', 'active')
                ->orderBy('category_name')
                ->get();

            foreach ($categories as $category) {
                $path = route('storefront.category', ['category' => $category], false);
                $groups['Category pages'][$path] = $category->category_name;
            }
        }

        return $groups;
    }

    /** Readable name of a saved link, or null when it is a custom link. */
    public static function destinationLabel(?string $link): ?string
    {
        foreach (self::destinations() as $options) {
            if (isset($options[(string) $link])) {
                return $options[(string) $link];
            }
        }

        return null;
    }

    /** Only same-site paths, #anchors or http(s) links are allowed as button targets. */
    public static function safeLink(?string $link): string
    {
        $link = trim((string) $link);

        if ($link === '') {
            return '#featured-sections';
        }

        if (str_starts_with($link, '#') || (str_starts_with($link, '/') && ! str_starts_with($link, '//'))) {
            return $link;
        }

        return preg_match('#^https?://#i', $link) ? $link : '#featured-sections';
    }
}
