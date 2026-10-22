FROM php:8.2-apache
COPY . /var/www/html/
RUN a2enmod rewrite && \
    chown -R www-data:www-data /var/www/html && \
    mkdir -p /var/www/html/data && \
    chmod -R 777 /var/www/html/data
# HATA DIYA: echo "[]" wala part - ab data reset nahi hoga
# PHP code khud file bana lega agar nahi hogi toh

EXPOSE 80
CMD ["apache2-foreground"]
