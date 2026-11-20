FROM php:8.2-apache
COPY ["app_chall/06. light&dark/internal/index.php", "/var/www/html/index.php"]
COPY infrastructure/private/light-dark.php /var/www/private/light-dark.php
COPY ["app_chall/06. light&dark/internal/apache.conf", "/etc/apache2/conf-enabled/observatorio.conf"]
EXPOSE 80
