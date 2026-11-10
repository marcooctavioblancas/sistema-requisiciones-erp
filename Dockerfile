FROM php:8.2-apache

# Composer, solo para instalar PHPUnit (dev-dependency) -- el proyecto no usa
# Composer para nada más, así que se copia el binario en vez de instalar todo
# el paquete apt.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Instalar dependencias del sistema y extensiones PHP
RUN apt-get update && apt-get install -y \
    $PHPIZE_DEPS \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    python3 \
    python3-pip \
    python3-venv \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        gd \
        zip \
        exif \
        fileinfo \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar PyMuPDF para extracción de texto de PDFs
RUN python3 -m venv /opt/pymupdf-env \
    && /opt/pymupdf-env/bin/pip install --no-cache-dir PyMuPDF

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Límites de subida de archivos: el default de PHP (upload_max_filesize=2M) es
# mucho más bajo que lo que el código YA espera permitir (hasta 50MB para PDFs
# de documentos de referencia) -- sin esto, cualquier subida de más de 2MB
# fallaba en silencio antes de que el código de validación de la app la viera.
RUN { \
    echo "upload_max_filesize = 60M"; \
    echo "post_max_size = 65M"; \
    } > /usr/local/etc/php/conf.d/99-uploads.ini

# Configurar Apache en puerto 8080 (no privilegiado) para poder correr como www-data
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8080>/' /etc/apache2/sites-available/000-default.conf

# Configurar Apache para el proyecto
RUN echo '<Directory /var/www/html>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/sistema.conf \
    && a2enconf sistema

# Copiar código fuente
COPY . /var/www/html/

# Permisos para uploads y logs — asignados a www-data antes de cambiar usuario
RUN mkdir -p /var/www/html/uploads /var/www/html/logs \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/logs

WORKDIR /var/www/html

# Entrypoint: fix permissions en bind-mounts, luego inicia Apache
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Correr como www-data — Apache 8080 no necesita root
USER www-data

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
