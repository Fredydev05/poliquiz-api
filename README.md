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
- agrega la config de MinIO al `.env` si falta (instalaciones previas a MinIO)
- `php artisan migrate`
- `php artisan storage:link`

En paralelo, un contenedor `minio_setup` crea el bucket de multimedia y lo deja de
lectura pública (idempotente).

Después arrancan los servicios de siempre, cada uno en su propio contenedor: **api**
(REST, `:8000`), **reverb** (WebSockets, `:8080`), **queue** (cierra preguntas por
tiempo, etc.), **scheduler** (limpia salas abandonadas cada 5 min) y **minio**
(almacenamiento de imágenes S3-compatible, API en `:9000` y consola en `:9001`).

## 3 · Frontend (`poliquiz-web`)

Este repo no está dockerizado — corre nativo con Node y **pnpm** (`corepack enable`
o `npm i -g pnpm` si no lo tenés):

```bash
cd ../poliquiz-web
pnpm install
cp .env.example .env
```

Abrí `poliquiz-api/.env` y copiá el valor de `REVERB_APP_KEY` (lo generó el paso
anterior) en `VITE_REVERB_APP_KEY` dentro de `poliquiz-web/.env`. Tiene que ser **el
mismo valor en ambos repos**, sino el frontend no puede autenticar el canal de
WebSockets.

```bash
pnpm dev --port 5180
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
- **MinIO (multimedia)**: consola web en `http://localhost:9001`
  (`poliquiz` / `poliquiz-secret-dev` por defecto). Las imágenes de los quiz se guardan
  en el bucket público `poliquiz-media`. El código usa el disco `media`
  (`config/filesystems.php`), así que pasar a S3/R2/Spaces en producción es solo cambiar
  las variables `AWS_*` del `.env` — sin tocar código.
- **Usuario de prueba**: no viene ninguno seedeado — registrate desde `/login` en el
  frontend.
- **Producción**: este `docker-compose.yml` es para desarrollo (bind mount, Reverb sin
  TLS, MinIO sin TLS, etc.). El setup de producción todavía no está armado (fase de
  deploy pendiente).
