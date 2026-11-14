<?php

namespace Zuno\Storefront\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Zuno\Storefront\Http\Controllers\CheckoutError;

/**
 * Custom-tee designs arrive from the browser as base64 data URLs: one preview of the finished
 * tee and the original artwork files. They are written to private storage (never public URLs)
 * and the order item keeps their paths; admins view them through an authenticated route.
 */
class Designs
{
    private const DISK = 'private';

    private const DATA_URL = '/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/=]+)$/';

    private const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Saves a design and returns ['preview' => path, 'artwork' => [paths]].
     * Every path written is appended to $saved so the caller can clean up on failure.
     */
    public function store(array $design, array &$saved): array
    {
        $artwork = $design['artwork'] ?? [];

        if (! is_array($artwork) || count($artwork) > config('zuno.max_artwork')) {
            throw new CheckoutError('The design images are not valid.');
        }

        $folder = 'zuno-designs/'.now()->format('Y/m').'/'.Str::uuid();

        $result = ['preview' => $this->write($design['preview'] ?? null, "$folder/preview", $saved), 'artwork' => []];

        foreach (array_values($artwork) as $i => $image) {
            $result['artwork'][] = $this->write($image, "$folder/artwork-".($i + 1), $saved);
        }

        return $result;
    }

    /** The stored path for "preview" or "artwork-N" of an order item, if it has one. */
    public function pathFor(array $design, string $file): ?string
    {
        if ($file === 'preview') {
            return $design['preview'] ?? null;
        }

        $index = (int) Str::after($file, 'artwork-') - 1;

        return $design['artwork'][$index] ?? null;
    }

    public function disk()
    {
        return Storage::disk(self::DISK);
    }

    public function delete(array $paths): void
    {
        if ($paths) {
            $this->disk()->delete($paths);
        }
    }

    protected function write(mixed $dataUrl, string $pathWithoutExtension, array &$saved): string
    {
        if (! is_string($dataUrl) || ! preg_match(self::DATA_URL, $dataUrl, $match)) {
            throw new CheckoutError('The design images are not valid.');
        }

        $bytes = base64_decode($match[2], true);

        // The bytes must really be the image type the data URL claims
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || ! $this->isImage($bytes, $match[1])) {
            throw new CheckoutError('The design images are not valid.');
        }

        $path = $pathWithoutExtension.'.'.($match[1] === 'jpeg' ? 'jpg' : $match[1]);
        $this->disk()->put($path, $bytes);
        $saved[] = $path;

        return $path;
    }

    protected function isImage(string $bytes, string $type): bool
    {
        $info = @getimagesizefromstring($bytes);

        return $info && $info['mime'] === "image/$type";
    }
}
