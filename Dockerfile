FROM php:8.2-apache

COPY . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Render/Railway inject the real port to bind at runtime via $PORT;
# the entrypoint rewrites Apache's config to listen on it before starting.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["docker-entrypoint.sh"]
