# Minimal Docker image for the PHP middleware
FROM php:8.2-cli

# Workdir
WORKDIR /var/www/html

# Copy app files
COPY index.php /var/www/html/

# Expose the application port
EXPOSE 8888

# Run the PHP built-in server with index.php as router
CMD ["php", "-S", "0.0.0.0:8888", "-t", "/var/www/html", "index.php"]