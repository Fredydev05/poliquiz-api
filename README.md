# POLI Quiz · API

Backend de POLI Quiz (clon de Kahoot para la Facultad Politécnica · UNE). Laravel 13 +
PostgreSQL + Redis + Reverb (WebSockets). Este repo es solo la API; el frontend vive en
[`poliquiz-web`](https://github.com/Fredydev05/poliquiz-web), un repo aparte.

## Requisitos

- PHP **8.3+** con extensiones `pdo_pgsql` y `redis`
- Composer 2
- Docker + Docker Compose (para Postgres y Redis)
- Node 20+ (para el frontend, ver paso 3)

## 1 · Clonar ambos repos

Deben quedar **como carpetas hermanas** (misma convención que usa este equipo, aunque el frontend no depende de rutas relativas):

```bash
git clone https://github.com/Fredydev05/poliquiz-api.git
git clone https://github.com/Fredydev05/poliquiz-web.git
```

## 2 · Backend (`poliquiz-api`)

```bash
cd poliquiz-api
composer install
cp .env.example .env
php artisan key:generate
```

**Levantar Postgres y Redis** (Docker Desktop debe estar corriendo):

```bash
docker compose up -d
```

**Generar las credenciales de Reverb** (WebSockets) — son secretos únicos por instalación, por eso no vienen ya cargadas en `.env.example`. Este paso es **obligatorio**: sin él el realtime no conecta.

```bash
sed -i '' "s/^REVERB_APP_ID=.*/REVERB_APP_ID=$(php -r 'echo random_int(100000,999999);')/" .env
sed -i '' "s/^REVERB_APP_KEY=.*/REVERB_APP_KEY=$(php -r 'echo bin2hex(random_bytes(10));')/" .env
sed -i '' "s/^REVERB_APP_SECRET=.*/REVERB_APP_SECRET=$(php -r 'echo bin2hex(random_bytes(10));')/" .env
```

> En Linux, `sed -i` no lleva el `''` después de `-i` (quitalo de los tres comandos).

**Migrar y enlazar el storage** (las imágenes de las preguntas se sirven desde acá):

```bash
php artisan migrate
php artisan storage:link
```

## 3 · Frontend (`poliquiz-web`)

En la carpeta hermana:

```bash
cd ../poliquiz-web
npm install
cp .env.example .env
```

Abrí `poliquiz-web/.env` y pegá en `VITE_REVERB_APP_KEY` el mismo `REVERB_APP_KEY` que generaste en el paso 2 (`poliquiz-api/.env`). Tiene que ser **el mismo valor en ambos repos**, sino el frontend no puede autenticar el canal de WebSockets.

## 4 · Levantar todo

La app necesita **4 procesos corriendo a la vez** (4 terminales, o backgroundeados con `&`). Desde `poliquiz-api/`:

```bash
php artisan serve --port=8000        # API REST
php artisan reverb:start --port=8080 # WebSockets — el "socket" del backend
php artisan queue:work                # cierra preguntas por tiempo, etc.
```

Y desde `poliquiz-web/`:

```bash
npm run dev -- --port 5180           # frontend
```

## 5 · Verificar que quedó bien

```bash
curl http://localhost:8000/api/v1/health
# {"ok":true,"app":"POLI Quiz",...}
```

Abrí `http://localhost:5180/dashboard`, creá una cuenta de docente y probá crear un cuestionario. Si al iniciar un juego los estudiantes no aparecen en vivo en la proyección, el proceso de Reverb (`reverb:start`) no está corriendo — es la causa más común.

## Tests

```bash
php artisan test
```

Usan **Redis real en la base 15** (aislada de la base de dev, que usa la 0) y Postgres. No hace falta nada extra: cada test limpia su estado en `beforeEach`.

## Notas útiles

- **Salas abandonadas**: hay un comando (`poliquiz:expire-abandoned`) agendado cada 5 min vía el scheduler de Laravel. En dev, para que corra, dejá `php artisan schedule:work` en otra terminal — es opcional, si no lo corrés simplemente no se auto-limpian las salas viejas.
- **pgAdmin**: el `docker-compose.yml` levanta una UI de administración de Postgres en `http://localhost:8090` (`admin@admin.com` / `admin123` por defecto).
- **Usuario de prueba**: no viene ninguno seedeado — registrate desde `/login` en el frontend.
- **Producción**: estos 4 procesos manuales se reemplazan por Supervisor + Nginx + cron (`schedule:run`). Todavía no está armado ese setup (fase de deploy pendiente).
