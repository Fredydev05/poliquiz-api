<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Subida de imágenes de pregunta al disco "media" (MinIO/S3 en real).
 * Storage::fake('media') reemplaza el disco por uno local en memoria, así el
 * test corre sin MinIO ni red — igual valida el contrato del endpoint.
 */

it('stores an uploaded image as webp on the media disk and returns its public url', function () {
    Storage::fake('media');
    $host = User::factory()->create();

    $response = comoDocente($host)->postJson('/api/v1/media', [
        'image' => UploadedFile::fake()->image('pregunta.png', 400, 300),
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['path', 'url']);

    $path = $response->json('path');

    // Se guardó en el disco correcto, como .webp y bajo el prefijo esperado.
    expect($path)->toStartWith('quiz-media/')->toEndWith('.webp');
    Storage::disk('media')->assertExists($path);

    // La URL pública apunta al mismo objeto (no una ruta interna de Docker).
    expect($response->json('url'))->toContain($path);
});

it('rejects non-image files', function () {
    Storage::fake('media');
    $host = User::factory()->create();

    comoDocente($host)->postJson('/api/v1/media', [
        'image' => UploadedFile::fake()->create('malicioso.pdf', 100, 'application/pdf'),
    ])->assertStatus(422);
});

it('rejects images larger than 2 MB', function () {
    Storage::fake('media');
    $host = User::factory()->create();

    comoDocente($host)->postJson('/api/v1/media', [
        'image' => UploadedFile::fake()->image('gigante.jpg')->size(3000), // 3 MB
    ])->assertStatus(422);
});

it('requires authentication', function () {
    Storage::fake('media');

    $this->postJson('/api/v1/media', [
        'image' => UploadedFile::fake()->image('pregunta.png'),
    ])->assertUnauthorized();
});
