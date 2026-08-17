# Asset Library — design.poh-apps.com/asset-library/
#
# The stock poh-dashboard-base image has no image extension, so PHP cannot resize anything.
# This app generates web + thumbnail derivatives on upload (a design library serves large
# source PNGs; the homepage grid must not ship them), so it needs GD.
#
# Rebuild + restart after changing this file:
#   podman build -t localhost/asset-library:0.1 /home/poh-svc/apps/asset-library
#   systemctl --user restart asset-library
#
# Note this makes deploys "rebuild the image", not just "git pull" — the PHP code is still
# bind-mounted read-only, so ordinary code changes remain a plain pull. Only changes to THIS
# file require a rebuild.
FROM localhost/poh-dashboard-base:0.1

# gd  — resize/encode derivatives (freetype/jpeg/webp so PNG, JPEG and WebP all round-trip)
# exif — read orientation so phone/camera uploads aren't stored sideways
#
# Build headers are purged afterwards, but the built extensions still need their runtime .so
# files (libpng16, libjpeg, ...). The ldd/dpkg-query dance is the upstream php-image idiom:
# re-mark whatever the extensions actually link against as manually installed so
# --auto-remove keeps them. (Same approach as apps/wiki/Containerfile.)
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd exif; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual > /dev/null; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# Upload ceiling. upload_max_filesize is the per-file cap Carson chose (5 MB); post_max_size
# is larger because the asset form can submit a featured image plus up to 10 gallery images in
# a single POST (11 x 5 MB + form fields). memory_limit covers GD holding a decoded bitmap —
# pixels, not file bytes, drive that, so a small but huge-dimensioned PNG still costs a lot;
# code also rejects absurd pixel dimensions before decoding.
#
# KEEP IN STEP with client_max_body_size in the nginx location block for /asset-library/
# and with MAX_UPLOAD_BYTES in src/images.php (which reports a friendly error well before
# PHP would silently truncate the POST).
RUN { \
      echo 'upload_max_filesize = 5M'; \
      echo 'post_max_size = 64M'; \
      echo 'max_file_uploads = 24'; \
      echo 'memory_limit = 256M'; \
    } > /usr/local/etc/php/conf.d/zz-asset-library.ini
