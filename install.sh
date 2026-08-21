#!/bin/bash
set -e
cd "$(dirname "$0")"

if docker compose version >/dev/null 2>&1; then DC="docker compose"
elif docker-compose version >/dev/null 2>&1; then DC="docker-compose"
else DC="/var/packages/ContainerManager/target/usr/bin/docker-compose"; fi
echo "Используется: $DC"

if [ -f compose.yaml ]; then
    mv compose.yaml "compose.yaml.disabled-$(date +%Y%m%d-%H%M%S)"
    echo "Лишний compose.yaml переименован."
fi

echo "════════════════════════════════════════════════════════"
echo " 1/9  Файл .env с паролями"
echo "════════════════════════════════════════════════════════"
if [ ! -f .env ]; then
    DB_PASS="$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32)"
    APP_SEC="$(openssl rand -hex 32)"
    cat > .env <<ENVEOF
DB_PASSWORD=${DB_PASS}

APP_SECRET=${APP_SEC}

APP_BASE_URL=https://kimbo.odotibmebel.synology.me

MAILER_DSN=null://null
MAILER_FROM=no-reply@kimbo.local
ENVEOF
    chmod 600 .env
    echo "  .env создан, пароли сгенерированы"
else
    echo "  .env уже существует — не трогаем"
fi

echo ""
echo "════════════════════════════════════════════════════════"
echo " 2/9  Каталоги"
echo "════════════════════════════════════════════════════════"
mkdir -p app data/postgres
echo "  готово"

echo ""
echo "════════════════════════════════════════════════════════"
echo " 3/9  Сборка образов и запуск контейнеров"
echo "════════════════════════════════════════════════════════"
$DC build
$DC up -d
echo "  ждём готовности базы..."
sleep 25

echo ""
echo "════════════════════════════════════════════════════════"
echo " 4/9  Каркас Symfony"
echo "════════════════════════════════════════════════════════"
if [ -f app/composer.json ]; then
    echo "  проект уже создан — пропускаем"
else
    $DC exec -T php sh -c 'cd /tmp && composer create-project symfony/skeleton:^7.2 s --no-interaction --no-scripts -q && cp -a /tmp/s/. /var/www/html/ && rm -rf /tmp/s'
    echo "  каркас создан"
fi

echo ""
echo "════════════════════════════════════════════════════════"
echo " 5/9  Библиотеки"
echo "════════════════════════════════════════════════════════"
$DC exec -T php sh -c 'cd /var/www/html && composer require --no-interaction -q \
    symfony/orm-pack symfony/security-bundle 2>&1 | tail -2'
$DC exec -T php sh -c 'cd /var/www/html && composer require --no-interaction -q \
    twig/extra-bundle symfony/twig-pack symfony/asset 2>&1 | tail -2'
$DC exec -T php sh -c 'cd /var/www/html && composer require --no-interaction -q \
    symfony/mailer symfony/google-mailer symfony/messenger symfony/doctrine-messenger 2>&1 | tail -2'
echo "  библиотеки установлены"

echo ""
echo "════════════════════════════════════════════════════════"
echo " 6/9  Исходники приложения"
echo "════════════════════════════════════════════════════════"
cp -a app-src/. app/
echo "  файлы скопированы в app/"

echo ""
echo "════════════════════════════════════════════════════════"
echo " 7/9  Права и кэш"
echo "════════════════════════════════════════════════════════"
$DC exec -T php sh -c 'cd /var/www/html && \
    chown -R www-data:www-data . && \
    rm -rf var/cache/* && \
    su -s /bin/sh www-data -c "composer dump-autoload --optimize -q" && \
    su -s /bin/sh www-data -c "php bin/console cache:warmup --no-interaction" 2>&1 | tail -1'
echo "  права выставлены, кэш собран"

echo ""
echo "════════════════════════════════════════════════════════"
echo " 8/9  Схема базы данных"
echo "════════════════════════════════════════════════════════"
$DC exec -T php sh -c 'cd /var/www/html && mkdir -p migrations && chown www-data:www-data migrations'
$DC exec -T php sh -c 'cd /var/www/html && \
    su -s /bin/sh www-data -c "php bin/console doctrine:migrations:diff --no-interaction" 2>&1 | tail -3' || true
$DC exec -T php sh -c 'cd /var/www/html && \
    su -s /bin/sh www-data -c "php bin/console doctrine:migrations:migrate --no-interaction" 2>&1 | tail -3'
$DC exec -T php sh -c 'cd /var/www/html && \
    su -s /bin/sh www-data -c "php bin/console messenger:setup-transports --no-interaction" 2>&1 | tail -2'

echo ""
echo "  Проверяем УНИКАЛЬНЫЙ ИНДЕКС (требование №1):"
$DC exec -T db psql -U kimbo -d kimbo -c "\di uniq_user_email" 2>&1 | sed 's/^/    /'

echo ""
echo "════════════════════════════════════════════════════════"
echo " 9/9  Перезапуск и проверка"
echo "════════════════════════════════════════════════════════"
$DC up -d --force-recreate
sleep 12

OK=1
for p in /login /register /; do
    CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 15 "http://127.0.0.1:8086${p}")
    printf "  %-12s -> %s" "$p" "$CODE"
    case "$p:$CODE" in
        /login:200|/register:200|/:302) echo "  ок" ;;
        *) echo "  НЕ ОЖИДАЛОСЬ"; OK=0 ;;
    esac
done

echo ""
if [ "$OK" = "1" ]; then
    echo "  ГОТОВО. Откройте https://kimbo.odotibmebel.synology.me"
    echo "  Создайте первую учётную запись через Create account."
else
    echo "  Что-то не так. Проверьте:  sudo docker compose logs --tail=30 php"
fi
echo "════════════════════════════════════════════════════════"
