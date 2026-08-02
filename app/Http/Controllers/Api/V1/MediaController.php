<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

class MediaController extends Controller
{
    /**
     * Sube una imagen de pregunta/portada: valida, la reduce a máx. 1280px
     * y la convierte a webp en el disco público.
     *
     * Nota: Intervention Image v4 renombró read()->decode() y quitó los
     * shortcuts toXxx() a favor de encode(new XxxEncoder()).
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:2048'], // 2 MB
        ]);

        $image = (new ImageManager(new Driver))
            ->decode($request->file('image'))
            ->scaleDown(width: 1280);

        $path = 'quiz-media/'.Str::uuid().'.webp';
        Storage::disk('public')->put($path, (string) $image->encode(new WebpEncoder(quality: 82)));

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }
}
