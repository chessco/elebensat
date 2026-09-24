FROM php:8.2-fpm-bookworm

ENV DEBIAN_FRONTEND=noninteractive

# Instalar dependencias del sistema requeridas para las extensiones PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libssl-dev \
    libonig-dev \
    unzip \
    ca-certificates \
    tzdata \
    && rm -rf /var/lib/apt/lists/*

# Configurar zona horaria de México
ENV TZ=America/Mexico_City
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Instalar extensiones PHP necesarias y auditadas
RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    xml \
    dom \
    simplexml \
    curl \
    zip \
    iconv \
    fileinfo \
    opcache

# Copiar configuración personalizada de PHP y OPcache
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-elebensat.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Crear estructura del almacenamiento persistente y asignar permisos a www-data
RUN mkdir -p /var/data/sgksat/metadata_manual \
             /var/data/sgksat/conciliacion_iva \
             /var/data/sgksat/catalogos_sat/jobs \
             /var/data/sgksat/catalogos_sat/tmp \
             /var/data/sgksat/schemas \
             /var/data/sgksat/logs \
    && chown -R www-data:www-data /var/data/sgksat \
    && chmod -R 775 /var/data/sgksat

WORKDIR /var/www/html

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
