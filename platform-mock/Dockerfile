FROM php:8.4-cli-alpine

WORKDIR /app
COPY . /app
RUN mkdir -p /var/lib/platform-mock \
    && chmod -R 777 /var/lib/platform-mock

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
