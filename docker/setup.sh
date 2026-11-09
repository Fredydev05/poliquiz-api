#!/bin/sh
# Corre UNA vez, antes de que arranquen api/reverb/queue (ver "setup" en
# docker-compose.yml). Cada paso es idempotente: en corridas siguientes casi
# todo se salta y esto termina en segundos.
set -e
cd /var/www/html

if [ ! -f .env ]; then
  echo "→ Creando .env desde .env.example"
  cp .env.example .env
fi

if [ ! -d vendor ]; then
  echo "→ composer install"
  composer install --no-interaction --prefer-dist
fi

# IMPORTANTE: esto tiene que estar en el archivo .env, no alcanza con las
# variables de entorno que pone docker-compose.yml. "php artisan serve"
# arma el entorno del proceso hijo que atiende cada request a partir de
# $_ENV (ServeCommand::startProcess), y ese hijo NO hereda las variables
# reales del contenedor — solo lo que venga escrito en el archivo .env.
# Sin este paso, cada request le pegaba a 127.0.0.1 en vez de a los
# contenedores pgsql/redis/reverb → "Connection refused" en todo lo que
# tocara caché, colas o WebSockets.
sed -i "s/^DB_HOST=.*/DB_HOST=pgsql/" .env
sed -i "s/^REDIS_HOST=.*/REDIS_HOST=redis/" .env
sed -i "s/^REVERB_HOST=.*/REVERB_HOST=\"reverb\"/" .env

# Config de MinIO (almacenamiento de multimedia). En un .env fresco ya viene de
# .env.example; esto es para .env de instalaciones previas a MinIO — se agrega
# solo si falta. Tiene que estar en el archivo por el mismo motivo que DB_HOST:
# el proceso hijo de "artisan serve" lee del .env, no del entorno del contenedor.
if ! grep -q '^AWS_ENDPOINT=' .env; then
  echo "→ Agregando config de MinIO al .env"
  cat >> .env <<'EOF'

# Almacenamiento de multimedia (MinIO en dev, S3-compatible) — ver docker-compose.yml
AWS_ACCESS_KEY_ID=poliquiz
AWS_SECRET_ACCESS_KEY=poliquiz-secret-dev
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=poliquiz-media
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_ENDPOINT=http://minio:9000
AWS_URL=http://localhost:9000/poliquiz-media
EOF
fi

# Config del asistente de IA (Groq). La key la completa el docente a mano.
if ! grep -q '^GROQ_API_KEY=' .env; then
  echo "→ Agregando config del asistente de IA al .env (falta pegar GROQ_API_KEY)"
  cat >> .env <<'EOF'

# Asistente de IA — conseguí tu key gratis en https://console.groq.com/keys
AI_PROVIDER=groq
GROQ_API_KEY=
GROQ_MODEL=openai/gpt-oss-120b
EOF
fi

if ! grep -q '^APP_KEY=base64' .env; then
  echo "→ Generando APP_KEY"
  php artisan key:generate --force
fi

if ! grep -q '^REVERB_APP_KEY=.\+' .env; then
  echo "→ Generando credenciales de Reverb"
  ID=$(php -r 'echo random_int(100000, 999999);')
  KEY=$(php -r 'echo bin2hex(random_bytes(10));')
  SECRET=$(php -r 'echo bin2hex(random_bytes(10));')
  sed -i "s/^REVERB_APP_ID=.*/REVERB_APP_ID=$ID/" .env
  sed -i "s/^REVERB_APP_KEY=.*/REVERB_APP_KEY=$KEY/" .env
  sed -i "s/^REVERB_APP_SECRET=.*/REVERB_APP_SECRET=$SECRET/" .env
  echo "  ⚠ Copiá REVERB_APP_KEY ($KEY) a poliquiz-web/.env → VITE_REVERB_APP_KEY"
fi

echo "→ Migrando"
php artisan migrate --force

echo "→ storage:link"
php artisan storage:link || true

echo "✓ Setup completo"
