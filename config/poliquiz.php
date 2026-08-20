<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuración del dominio POLI Quiz
|--------------------------------------------------------------------------
*/
return [

    // Vida máxima de una sala en Redis (se renueva en cada transición).
    'game_ttl' => (int) env('POLIQUIZ_GAME_TTL', 21600), // 6 h

    // Límite de jugadores por sala (decisión del plan §10).
    'max_players' => (int) env('POLIQUIZ_MAX_PLAYERS', 100),

    // Fase de "reveal" antes de cada pregunta (segundos): se muestra el
    // enunciado (sin opciones) para que los alumnos lean, y recién ahí aparecen
    // las respuestas y arranca el timer. Global para todas las preguntas.
    'get_ready_seconds' => 5,

    // Una sala sin actividad por más de esto se considera abandonada y se
    // cierra sola (el barrido corre cada 5 min).
    'abandoned_after' => (int) env('POLIQUIZ_ABANDONED_AFTER', 900), // 15 min

    // Margen de gracia de red al validar respuestas tardías (ms).
    'answer_grace_ms' => 300,

    // Nicknames: longitud y lista de bloqueo (subcadenas, sin acentos).
    'nickname' => [
        'min' => 2,
        'max' => 15,
        'blocklist' => [
            'puto', 'puta', 'mierda', 'pendejo', 'idiota', 'imbecil',
            'estupido', 'admin', 'profesor', 'docente', 'fuck', 'shit',
        ],
    ],
];
