<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorBanner;
use App\Support\HomeBanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BannerController extends Controller
{
    public function edit(int $slot = 1): View
    {
        $vendor = $this->vendor();
        $slot = $this->slot($slot);

        $saved = VendorBanner::query()->where('vendor_id', $vendor->id)->orderBy('slot')->get()->keyBy('slot');

        return view('vendor.banner.edit', [
            'title' => 'Home Banner',
            'activeMenu' => 'banner',
            'vendor' => $vendor,
            'slot' => $slot,
            'saved' => $saved,
            'banner' => $saved->get($slot) ?? new VendorBanner([
                'vendor_id' => $vendor->id,
                'slot' => $slot,
                'shop_name' => $vendor->vendor_name,
                'primary_button_text' => 'Shop Now',
            ]),
            'isShown' => $saved->has($slot) && $vendor->status === 'active',
        ]);
    }

    public function update(Request $request, int $slot = 1): RedirectResponse
    {
        $vendor = $this->vendor();
        $slot = $this->slot($slot);

        $data = $request->validate([
            'shop_name' => ['nullable', 'string', 'max:60'],
            'eyebrow' => ['nullable', 'string', 'max:40'],
            'heading' => ['required', 'string', 'max:80'],
            'highlight' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:220'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        $banner = VendorBanner::query()->firstOrNew(['vendor_id' => $vendor->id, 'slot' => $slot]);

        $banner->fill([
            'shop_name' => $data['shop_name'] ?? null,
            'eyebrow' => $data['eyebrow'] ?? null,
            'heading' => $data['heading'],
            'highlight' => $data['highlight'] ?? null,
            'description' => $data['description'] ?? null,
            'primary_button_text' => 'Shop Now',
            'primary_button_link' => $banner->shopUrl(),
        ]);

        if ($request->hasFile('image')) {
            $this->deleteImage($banner->image_path);
            $banner->image_path = $this->storeImage($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $this->deleteImage($banner->image_path);
            $banner->image_path = null;
        }

        $banner->save();

        return redirect()->route('vendor.banner.edit', ['slot' => $slot])
            ->with('success', "Banner {$slot} saved. It is now shown on the customer home page.");
    }

    public function destroy(int $slot = 1): RedirectResponse
    {
        $vendor = $this->vendor();
        $slot = $this->slot($slot);

        $banner = VendorBanner::query()->where('vendor_id', $vendor->id)->where('slot', $slot)->first();

        if ($banner) {
            $this->deleteImage($banner->image_path);
            $banner->delete();
        }

        return redirect()->route('vendor.banner.edit', ['slot' => $slot])->with('success', "Banner {$slot} deleted.");
    }

    private function slot(int $slot): int
    {
        abort_unless($slot >= 1 && $slot <= HomeBanner::MAX_PER_VENDOR, 404);

        return $slot;
    }

    private function storeImage($file): string
    {
        $directory = public_path('uploads/banners');
        File::ensureDirectoryExists($directory);

        $name = 'banner_'.Str::uuid()->toString().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $file->move($directory, $name);

        return 'uploads/banners/'.$name;
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, 'uploads/banners/') && File::exists(public_path($path))) {
            File::delete(public_path($path));
        }
    }

    private function vendor(): Vendor
    {
        /** @var Vendor */
        return Auth::guard('vendor')->user();
    }
}
