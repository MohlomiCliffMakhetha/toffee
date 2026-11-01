FROM torii-php-base:8.2
WORKDIR /app
COPY . .
# Static text files gzipped once here, so no request writes a cache file (router.php).
RUN php tools/precompress.php
ENV PORT=3000
# 10 PHP workers instead of 1, so a slow upload, a video playing or a phone notification being
# sent doesn't hold up everyone else (about 20 MB each, well within the app's 512 MB).
ENV PHP_CLI_SERVER_WORKERS=10
EXPOSE 3000
# router.php: hides .git, tests, docs..., and serves static files with cache headers and gzip.
# Files up to 50 MB (videos; see UPLOAD_KINDS in api.php), with room for the rest of the form.
# opcache: api.php is compiled once, not on every request (the code only changes with a new image,
# so it never needs re-checking).
CMD ["sh","-c","php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 -d upload_max_filesize=55M -d post_max_size=60M -d max_execution_time=120 -d max_input_time=120 -S 0.0.0.0:${PORT:-3000} -t /app /app/router.php"]
