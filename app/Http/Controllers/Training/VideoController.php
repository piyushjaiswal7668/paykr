<?php

declare(strict_types=1);

namespace App\Http\Controllers\Training;

use App\Support\TrainingLibrary;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class VideoController
{
    public function __invoke(string $audience, string $id, TrainingLibrary $library): BinaryFileResponse
    {
        $path = $library->path($audience, $id);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
