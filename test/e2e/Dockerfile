# The panel as a server runs it, for test/e2e/run.sh: panel Nginx with
# optional client certificates (ADR-0003) in front of PHP-FPM, on Debian 12,
# the oldest OS in the matrix (ADR-0004).
FROM debian:12

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      nginx php8.2-fpm php8.2-cli php8.2-sqlite3 php8.2-intl php8.2-mbstring openssl ca-certificates \
 && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY nginx.conf /etc/nginx/conf.d/lpanel-e2e.conf
COPY panel-start.sh /usr/local/bin/panel-start

CMD ["/usr/local/bin/panel-start"]
