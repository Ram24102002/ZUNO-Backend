# Bagisto with the ZUNO package, served by Apache. Build context: this backend/ folder.
FROM php:8.3-apache

ARG BAGISTO_VERSION=^2.3

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath calendar exif gd intl pdo_mysql zip opcache \
    && apt-get update && apt-get install -y --no-install-recommends unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && sed -ri 's#/var/www/html#/var/www/bagisto/public#g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/bagisto/public>\n    AllowOverride All\n</Directory>\n' > /etc/apache2/conf-enabled/bagisto.conf \
    && printf 'memory_limit=512M\nupload_max_filesize=20M\npost_max_size=40M\n' > /usr/local/etc/php/conf.d/zuno.ini \
    # Few Apache workers: each holds a PHP process (~60 MB), and the server has little memory
    && printf '<IfModule mpm_prefork_module>\n    StartServers 2\n    MinSpareServers 1\n    MaxSpareServers 3\n    MaxRequestWorkers 6\n</IfModule>\nServerName localhost\n' > /etc/apache2/conf-enabled/zuno-workers.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/bagisto
RUN composer create-project bagisto/bagisto:"${BAGISTO_VERSION}" . --no-interaction --no-scripts --prefer-dist

# The ZUNO package, installed from its folder (changes need a rebuild)
COPY packages/Zuno/Storefront packages/Zuno/Storefront
RUN composer config repositories.zuno '{"type": "path", "url": "packages/Zuno/Storefront", "options": {"symlink": false}}' \
    && composer require zuno/storefront:@dev --no-interaction --prefer-dist \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/zuno-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/zuno-entrypoint && chmod +x /usr/local/bin/zuno-entrypoint

EXPOSE 80
ENTRYPOINT ["zuno-entrypoint"]
CMD ["apache2-foreground"]
