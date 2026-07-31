FROM php:8.2-cli

WORKDIR /app
COPY . .

# Render/Railway inject the port to bind at runtime via $PORT.
ENV PORT=8080
EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t ."]
