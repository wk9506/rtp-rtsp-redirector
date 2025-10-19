# Minimal Docker image for the PHP middleware
FROM alpine:3.20
RUN apk add --no-cache php83 php83-cli

WORKDIR /var/www/html
COPY index.php /var/www/html/
EXPOSE 8888
CMD ["php", "-S", "0.0.0.0:8888", "-t", "/var/www/html", "index.php"]