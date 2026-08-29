<?php

namespace App\Services;

use Cloudinary\Cloudinary;

class CloudinaryService
{
    protected $cloudinary;

    public function __construct()
    {
        // Read via config(), not env() directly — see ProductController
        // for why (config:cache in production makes env() return null
        // outside config/*.php files).
        $this->cloudinary = new Cloudinary(config('services.cloudinary.url'));
    }

    public function upload($file, $folder = 'products')
    {
        $result = $this->cloudinary->uploadApi()->upload(
            $file->getRealPath(),
            ['folder' => $folder]
        );

        return $result['secure_url'];
    }

    public function delete($publicId)
    {
        $this->cloudinary->uploadApi()->destroy($publicId);
    }
}
