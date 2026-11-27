<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Keeps product images uploaded with a form that failed validation, since
 * browsers cannot refill a file input after the page reloads.
 */
class PendingProductImages
{
    public const MAX_IMAGES = 5;
    public const FILE_RULES = ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'];

    private const SESSION_KEY = 'pending_product_images';
    private const FOLDER = 'products-pending';

    /**
     * Stores this request's valid uploads alongside the still-selected earlier ones
     * and puts the list back on the request so it survives a validation redirect.
     *
     * @return list<string>
     */
    public static function collect(Request $request): array
    {
        $disk = Storage::disk('public');
        $owned = self::owned();
        $submitted = array_filter((array) $request->input('pending_images', []), 'is_string');
        $paths = array_values(array_filter(
            array_intersect($submitted, $owned),
            fn (string $path) => $disk->exists(self::diskPath($path))
        ));

        foreach ((array) $request->file('images', []) as $file) {
            if ($file instanceof UploadedFile && $file->isValid()
                && Validator::make(['image' => $file], ['image' => self::FILE_RULES])->passes()) {
                $paths[] = UploadedImage::store($file, self::FOLDER, 'pending');
            }
        }

        foreach (array_diff($owned, $paths) as $removed) {
            $disk->delete(self::diskPath($removed));
        }

        session([self::SESSION_KEY => $paths]);
        $request->merge(['pending_images' => $paths]);

        return $paths;
    }

    /**
     * Images to show again on a form that came back with errors.
     *
     * @return list<array{path: string, url: string}>
     */
    public static function forForm(): array
    {
        $owned = self::owned();

        return collect((array) old('pending_images', []))
            ->filter(fn ($path) => is_string($path) && in_array($path, $owned, true))
            ->map(fn (string $path) => ['path' => $path, 'url' => asset($path)])
            ->values()
            ->all();
    }

    /**
     * Drops leftovers from an abandoned attempt when a form is opened fresh.
     */
    public static function discardUnlessReturning(): void
    {
        if (session()->hasOldInput('pending_images')) {
            return;
        }

        foreach (self::owned() as $path) {
            Storage::disk('public')->delete(self::diskPath($path));
        }

        session()->forget(self::SESSION_KEY);
    }

    /**
     * @param  list<string>  $paths
     */
    public static function attach(Product $product, array $paths, ?int $userId): void
    {
        $disk = Storage::disk('public');

        foreach (array_values($paths) as $index => $path) {
            $source = self::diskPath($path);

            if (! $disk->exists($source)) {
                continue;
            }

            $filename = 'product_'.Str::uuid()->toString().'.'.pathinfo($path, PATHINFO_EXTENSION);
            $disk->move($source, 'uploads/products/'.$filename);

            ProductImage::create([
                'product_id' => $product->id,
                'image_path' => 'storage/uploads/products/'.$filename,
                'sort_order' => $index,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        }

        session()->forget(self::SESSION_KEY);
    }

    private static function diskPath(string $path): string
    {
        return 'uploads/'.self::FOLDER.'/'.basename($path);
    }

    /**
     * @return list<string>
     */
    private static function owned(): array
    {
        return array_values(array_filter((array) session(self::SESSION_KEY, []), 'is_string'));
    }
}
