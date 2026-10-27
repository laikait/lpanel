#!/bin/sh
# Inside the panel container: install the panel from /src (read-only), set it
# up as production would be, start PHP-FPM and Nginx, then signal readiness.
set -eu

mkdir -p /srv/lpanel /srv/data /srv/keys /etc/lpanel-e2e
tar -C /src \
    --exclude=./.git --exclude=./.env --exclude=./test/e2e/.work \
    --exclude='./system/Runtime/*' --exclude='./system/Logs/*' --exclude='./system/Keys/*' \
    --exclude='./system/Sessions/*' --exclude='./system/Cache/*' --exclude='./system/Security/*' \
    -cf - . | tar -C /srv/lpanel -xf -

# Panel Nginx's certificate, and the pin agents check it against.
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 2 \
    -subj /CN=panel -addext subjectAltName=DNS:panel \
    -keyout /etc/lpanel-e2e/panel.key -out /etc/lpanel-e2e/panel.crt 2>/dev/null
printf 'sha256/%s\n' "$(openssl x509 -in /etc/lpanel-e2e/panel.crt -pubkey -noout \
    | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')" \
    > /etc/lpanel-e2e/pin

cat > /srv/lpanel/.env <<EOF
APP_ENV=production
APP_DEBUG=0
APP_KEY=$(php -r 'require "/srv/lpanel/vendor/autoload.php"; echo App\Engine\Security\Signer::generate();')
APP_URL=https://panel:8443
DB_DSN=sqlite:/srv/data/panel.sqlite
LPANEL_AGENT_URL=https://panel:8443
LPANEL_TLS_CERTIFICATE=/etc/lpanel-e2e/panel.crt
LPANEL_OPERATION_KEY=/srv/keys/operations.key
LOG_WRITERS=file
EOF

chown -R www-data:www-data /srv/lpanel/system /srv/data
su -s /bin/sh www-data -c 'php /srv/lpanel/laika migrate' >/dev/null

# The operation key as in production: written by root, readable by the panel's group, not writable by it.
php /srv/lpanel/laika servers:keygen >/dev/null
chown -R root:www-data /srv/keys && chmod 0750 /srv/keys && chmod 0640 /srv/keys/operations.key

php-fpm8.2 --daemonize
nginx
touch /run/e2e-ready
exec sleep infinity
