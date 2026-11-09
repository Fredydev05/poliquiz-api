<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Events\Game\PlayerKicked;
use App\Services\Game\GameRepository;
use Illuminate\Support\Str;

/** Expulsa a un jugador: sale del roster, su sesión muere y se avisa por WS. */
class KickPlayer
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly CloseQuestion $closeQuestion,
    ) {}

    public function handle(string $pin, string $playerUuid): void
    {
        $player = $this->repo->player($pin, $playerUuid);

        if ($player !== null) {
            // Recalculamos la clave normalizada para liberar el nickname.
            $normalized = str_replace([' ', '_', '-'], '', Str::lower(Str::ascii($player['nickname'])));
            $this->repo->removePlayer($pin, $playerUuid, $normalized);
        }

        // Se difunde SIEMPRE (aunque ya no esté en Redis): cubre al "fantasma"
        // que quedó suscripto al canal tras un kick previo o una reconexión.
        PlayerKicked::dispatch($pin, $playerUuid);

        // Si el expulsado era el único que faltaba responder, la ronda ya
        // está completa: se cierra sin esperar el timer.
        if ($player !== null) {
            $this->closeQuestion->closeIfEveryoneAnswered($pin);
        }
    }
}
