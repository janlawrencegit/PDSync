# PDSync on Apache with PHP 8.3: local development (compose.yaml), production (compose.prod.yaml) and Railway.
# Apache serves many connections at once, unlike `php -S`, which handles one at a time and stalls a browser that
# loads the form's page images, fonts and scripts in parallel.
FROM php:8.3-apache

# LibreOffice (Writer, no desktop) turns the filled Work Experience Sheet into a PDF that matches the Word file;
# Liberation fonts have the same metrics as Arial and Arial Narrow, which the official template uses.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libreoffice-writer-nogui fonts-liberation fonts-liberation2 \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod headers rewrite \
    && printf 'memory_limit = 512M\nupload_max_filesize = 20M\npost_max_size = 60M\nexpose_php = Off\nopcache.enable = 1\nopcache.memory_consumption = 128\nopcache.max_accelerated_files = 10000\n' > /usr/local/etc/php/conf.d/pdsync.ini

# PORT: the port Apache listens on (hosts such as Railway set it). APACHE_WORKERS: most requests served at once;
# each worker is a PHP process, so a small cap keeps memory within small plans (Railway's free tier has 0.5 GB).
ENV PORT=80 APACHE_WORKERS=12
# The site root is public/; lib/, data/ and the config sit beside it, out of reach of the web.
ENV APACHE_DOCUMENT_ROOT=/var/www/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri 's!^Listen 80$!Listen ${PORT}!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!' /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerTokens Prod\nServerSignature Off\n<IfModule mpm_prefork_module>\n  StartServers 2\n  MinSpareServers 2\n  MaxSpareServers 4\n  MaxRequestWorkers ${APACHE_WORKERS}\n  MaxConnectionsPerChild 500\n</IfModule>\n' > /etc/apache2/conf-enabled/zz-pdsync.conf

WORKDIR /var/www/app
COPY . /var/www/app
RUN sed -i 's/\r$//' tools/docker-entrypoint.sh && chmod +x tools/docker-entrypoint.sh \
    && mkdir -p data && chown -R www-data:www-data data

# Healthy when Apache answers and PHP renders the editor page.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS -o /dev/null "http://localhost:${PORT}/" || exit 1

CMD ["tools/docker-entrypoint.sh"]
