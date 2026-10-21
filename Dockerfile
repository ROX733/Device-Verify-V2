FROM php:8.2-apache
COPY . /var/www/html/
RUN a2enmod rewrite && \
    chown -R www-data:www-data /var/www/html && \
    mkdir -p /var/www/html/data && \
    chmod -R 777 /var/www/html/data && \
    echo "[]" > /var/www/html/data/bots.json && \
    echo "[]" > /var/www/html/data/users.json
EXPOSE 80
