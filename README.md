# POLI Quiz · API

Backend de POLI Quiz (clon de Kahoot para la Facultad Politécnica · UNE). Laravel 13 +
PostgreSQL + Redis + Reverb (WebSockets). Este repo es solo la API; el frontend vive en
[`poliquiz-web`](https://github.com/Fredydev05/poliquiz-web), un repo aparte.

**Todo el backend corre en Docker con un solo comando** — no hace falta PHP ni Composer
instalados en tu máquina, `docker compose` se encarga de todo (build, dependencias,
credenciales, migraciones y los 5 procesos que necesita la app).

## Requisitos

- Docker + Docker Compose
- Node 20+ (solo para el frontend, ver paso 3 — ese repo no está dockerizado)

## 1 · Clonar ambos repos

Deben quedar **como carpetas hermanas**:

```bash
git clone https://github.com/Fredydev05/poliquiz-api.git
git clone https://github.com/Fredydev05/poliquiz-web.git
```

## 2 · Backend (`poliquiz-api`)

```bash
cd poliquiz-api
docker compose up -d
```

Eso es todo. La primera vez tarda un par de minutos (construye la imagen y corre
`composer install`); las siguientes arranca en segundos. Un servicio (`setup`) corre
antes que el resto y hace, en orden, todo lo que en un setup manual habría que acordarse
de no saltear:

- crea `.env` desde `.env.example` si no existe
- `composer install` si falta `vendor/`
- `php artisan key:generate` si falta `APP_KEY`
- genera credenciales de Reverb (WebSockets) si faltan — son secretos únicos por instalación
- `php artisan migrate`
- `php artisan storage:link`

Después arrancan los servicios de siempre, cada uno en su propio contenedor: **api**
(REST, `:8000`), **reverb** (WebSockets, `:8080`), **queue** (cierra preguntas por
tiempo, etc.) y **scheduler** (limpia salas abandonadas cada 5 min).

## 3 · Frontend (`poliquiz-web`)

Este repo no está dockerizado — corre nativo con Node:

```bash
cd ../poliquiz-web
npm install
cp .env.example .env
```

Abrí `poliquiz-api/.env` y copiá el valor de `REVERB_APP_KEY` (lo generó el paso
anterior) en `VITE_REVERB_APP_KEY` dentro de `poliquiz-web/.env`. Tiene que ser **el
mismo valor en ambos repos**, sino el frontend no puede autenticar el canal de
WebSockets.

```bash
npm run dev -- --port 5180
```

## 4 · Verificar que quedó bien

```bash
curl http://localhost:8000/api/v1/health
# {"ok":true,"app":"POLI Quiz",...}
```

Abrí `http://localhost:5180/dashboard`, creá una cuenta de docente y probá crear un
cuestionario. Si al iniciar un juego los estudiantes no aparecen en vivo en la
proyección, revisá `docker compose logs reverb` — es la causa más común.

## Comandos del día a día

```bash
docker compose up -d          # levantar todo
docker compose down           # apagar todo (la base de datos persiste)
docker compose logs -f api    # ver logs de un servicio (api | reverb | queue | scheduler)
docker compose exec api php artisan test   # correr los tests
docker compose exec api php artisan tinker # una consola dentro del contenedor
```

Los tests usan **Redis real en la base 15** (aislada de la base de dev, que usa la 0) y
Postgres. No hace falta nada extra: cada test limpia su estado en `beforeEach`.

## Notas útiles

- **Código en vivo**: el repo está montado como bind mount en los contenedores, así que
  los cambios en `app/`, `routes/`, etc. se reflejan al instante — no hace falta
  reconstruir la imagen para editar PHP. Solo hay que reconstruir (`docker compose build`)
  si tocás el `Dockerfile` o agregás una extensión nueva.
- **pgAdmin**: hay una UI de administración de Postgres en `http://localhost:8090`
  (`admin@admin.com` / `admin123` por defecto).
- **Usuario de prueba**: no viene ninguno seedeado — registrate desde `/login` en el
  frontend.
- **Producción**: este `docker-compose.yml` es para desarrollo (bind mount, Reverb sin
  TLS, etc.). El setup de producción todavía no está armado (fase de deploy pendiente).
