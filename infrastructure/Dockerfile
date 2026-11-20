FROM node:22-bookworm-slim AS app-files
WORKDIR /opt/javascript-tools
COPY tools/javascript/package*.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY tools/javascript/obfuscate.cjs tools/javascript/compact-html.cjs ./

ARG APP_SOURCE
COPY ["${APP_SOURCE}/", "/opt/oracle/app/"]
COPY logs/ /opt/oracle/logs/
COPY infrastructure/challenge/ /opt/oracle/challenge/
COPY infrastructure/private/ /opt/oracle/private/
COPY infrastructure/portal/ /opt/oracle/portal/
COPY infrastructure/depth/ /opt/oracle/depth/
RUN node obfuscate.cjs /opt/oracle

# Normaliza a estrutura apenas dentro do estágio temporário. Assim aplicações
# estáticas não precisam manter uma pasta backend fictícia no repositório.
RUN mkdir -p /opt/oracle/app/frontend /opt/oracle/app/backend

FROM php:8.2-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS libsqlite3-dev; \
    docker-php-ext-install mysqli pdo_mysql pdo_sqlite; \
    pecl install apcu; \
    docker-php-ext-enable apcu; \
    a2enmod rewrite; \
    rm -rf /var/lib/apt/lists/*; \
    printf 'apc.enable=1\napc.enable_cli=1\n' > /usr/local/etc/php/conf.d/oracle-apcu.ini

RUN docker-php-ext-install opcache

COPY infrastructure/apache.conf /etc/apache2/conf-enabled/oracle.conf
ENV APACHE_MAX_REQUEST_WORKERS=150
ENV APACHE_START_SERVERS=2 APACHE_MIN_SPARE_SERVERS=2 APACHE_MAX_SPARE_SERVERS=8
COPY infrastructure/apache-performance.conf /etc/apache2/conf-enabled/zz-oracle-performance.conf
COPY infrastructure/php-performance.ini /usr/local/etc/php/conf.d/oracle-performance.ini
COPY --from=app-files /opt/oracle/challenge/ /opt/oracle/challenge/
COPY --from=app-files /opt/oracle/private/ /var/www/private/
COPY --from=app-files /opt/oracle/portal/ /opt/oracle/portal/
COPY --from=app-files /opt/oracle/depth/ /opt/oracle/depth/

ARG APP_MODE
ARG APP_INDEX
ENV APP_INDEX=${APP_INDEX}

COPY --from=app-files /opt/oracle/app/frontend/ /var/www/html/
COPY --from=app-files /opt/oracle/app/backend/ /opt/oracle/backend/
COPY --from=app-files /opt/oracle/logs/ /opt/oracle/logs/

RUN set -eux; \
    case "$APP_MODE" in \
      portal) mkdir -p /var/www/html/api /var/www/html/logs /var/www/private/logs; cp /opt/oracle/backend/*.php /var/www/html/api/; cp /opt/oracle/backend/accounts.html /var/www/private/; cp /opt/oracle/logs/backend/* /var/www/private/logs/; cp /opt/oracle/logs/frontend/* /var/www/html/logs/; cp /opt/oracle/portal/.htaccess /var/www/html/.htaccess ;; \
      fire) cp /opt/oracle/backend/*.php /var/www/html/; cp /opt/oracle/challenge/*.php /var/www/html/; cp /opt/oracle/challenge/.htaccess /var/www/html/.htaccess ;; \
      challenge) for file in /opt/oracle/backend/*.php; do [ ! -f "$file" ] || cp "$file" /var/www/html/; done; cp /opt/oracle/challenge/*.php /var/www/html/; cp /opt/oracle/challenge/.htaccess /var/www/html/.htaccess ;; \
      earth|water) cp /opt/oracle/backend/*.php /var/www/html/; cp /opt/oracle/challenge/*.php /var/www/html/; cp /opt/oracle/challenge/.htaccess /var/www/html/.htaccess ;; \
      water-depth) cp /opt/oracle/backend/*.php /var/www/html/; cp /opt/oracle/challenge/challenge-auth.php /opt/oracle/challenge/contest-status.php /var/www/html/; cp /opt/oracle/depth/.htaccess /var/www/html/.htaccess ;; \
      *) echo "APP_MODE inválido: $APP_MODE" >&2; exit 1 ;; \
    esac; \
    cp /opt/oracle/challenge/flag-submit.js /var/www/html/; \
    printf '<!doctype html><meta http-equiv="refresh" content="0;url=%s">' "$APP_INDEX" > /var/www/html/index.html; \
    rm -rf /opt/oracle; \
    mkdir -p /var/www/data /var/www/sessions; \
    chown -R www-data:www-data /var/www/data /var/www/sessions

EXPOSE 80
