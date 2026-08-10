<?php

declare(strict_types=1);

namespace App\Services\Ai\Exceptions;

use RuntimeException;

/** Falla al hablar con el proveedor de IA (red, credenciales, cuota, etc.). */
final class AiProviderException extends RuntimeException {}
