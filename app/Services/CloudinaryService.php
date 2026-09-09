<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Cloudinary SDK, resilient to Cloudinary not being
 * configured — same fallback pattern ProductController already uses for
 * product photos (try to construct the client, log + fall back to null on
 * failure), so callers can inject this unconditionally without Cloudinary
 * being required in every environment (local dev, CI — see phpunit.xml,
 * which deliberately sets CLOUDINARY_URL="").
 */
class CloudinaryService
{
    protected ?Cloudinary $cloudinary = null;

    public function __construct()
    {
        // Read via config(), not env() directly — production runs
        // `php artisan config:cache` on every boot, after which env()
        // calls outside config/*.php always return null.
        $cloudinaryUrl = config('services.cloudinary.url');
        if ($cloudinaryUrl) {
            try {
                $this->cloudinary = new Cloudinary($cloudinaryUrl);
            } catch (\Exception $e) {
                Log::warning('Cloudinary not configured: ' . $e->getMessage());
                $this->cloudinary = null;
            }
        }
    }

    public function isConfigured(): bool
    {
        return $this->cloudinary !== null;
    }

    /**
     * @return array{secure_url: string, public_id: string}
     *
     * @throws \Exception on any Cloudinary upload failure — callers must
     *         catch this and respond gracefully rather than let it surface
     *         as a raw 500.
     */
    public function upload($file, string $folder = 'products'): array
    {
        $result = $this->cloudinary->uploadApi()->upload(
            $file->getRealPath(),
            ['folder' => $folder]
        );

        return [
            'secure_url' => $result['secure_url'],
            'public_id' => $result['public_id'],
        ];
    }

    public function delete(string $publicId): void
    {
        if (!$this->cloudinary) {
            return;
        }

        $this->cloudinary->uploadApi()->destroy($publicId);
    }
}
