# SmartCell en Render: PHP 8 + SQLite (sin Apache; servidor integrado con el router de la app)
FROM php:8.3-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev libpng-dev libjpeg-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_sqlite gd \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN chmod +x /app/render-start.sh && mkdir -p /app/frontend/uploads/productos /app/frontend/uploads/promociones

EXPOSE 10000
CMD ["/app/render-start.sh"]
