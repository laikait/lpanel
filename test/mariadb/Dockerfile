# PHP for running LPanel's tests against MariaDB (test/mariadb/run.sh).
# 8.2 on purpose: the lowest PHP LPanel supports (Debian 12, ADR-0004).
FROM php:8.2-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends libicu-dev \
 && docker-php-ext-install intl pdo_mysql \
 && rm -rf /var/lib/apt/lists/*

# The tests run as an ordinary user, as the panel does.
RUN useradd --create-home --uid 1000 tester
USER tester
WORKDIR /app
