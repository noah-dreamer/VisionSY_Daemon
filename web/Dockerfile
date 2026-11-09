####################################################################
# VisionSY 应用镜像（FrankenPHP）
#
# 开发使用 runtime 阶段；生产使用最后的 production 阶段，包含 MariaDB 与进程管理。
# 开发 app / worker / scheduler 共享 runtime 镜像，以不同命令运行：
#   - app       : FrankenPHP Web 服务（public/ 为 Web 根）
#   - worker    : php artisan queue:work
#   - scheduler : php artisan schedule:work
#
# 设计要点：
#   - 多阶段构建：前端（Node）与 Composer 依赖分别构建，生产镜像不含
#     node_modules、构建缓存与 dev 依赖。
#   - 不复制 .env、真实媒体、数据库文件或秘密（见 .dockerignore）。
#   - 开发 runtime 不自动迁移；生产由唯一 bootstrap 进程执行增量迁移，不自动 seed。
#   - 容器内应用根目录为 /app（FrankenPHP 默认 Web 根 /app/public）。
#   - 包发现清单（bootstrap/cache/packages.php）在首次启动时由 Laravel
#     自动构建（要求 bootstrap/cache 可写），因此构建期不执行 artisan。
####################################################################

# ---------- 阶段 1：前端构建 ----------
FROM node:22-alpine AS frontend
WORKDIR /build

# 先复制依赖清单以利用层缓存
COPY package.json package-lock.json ./
# --ignore-scripts：postinstall（scripts/copy-playcaptcha-assets.mjs）需要 scripts/ 与 public/，
# 先装依赖（保持本层仅依赖 package 清单的缓存），待源码复制后再手动执行。
RUN npm ci --ignore-scripts

# 复制构建所需源码
COPY vite.config.js postcss.config.js tailwind.config.js ./
COPY resources ./resources
COPY scripts ./scripts
COPY public ./public

# 手动执行被跳过的 postinstall：把 playcaptcha 的 toys/ 与 playcaptcha.svg 复制进 public/
RUN node scripts/copy-playcaptcha-assets.mjs

# 生成 public/build
RUN npm run build

# ---------- 阶段 2：Composer 依赖 ----------
FROM composer:2 AS vendor
WORKDIR /build

COPY composer.json composer.lock ./
# --no-scripts：package:discover 需要完整应用，交由运行时自动构建。
# 生产默认 --no-dev（不含 dev 依赖）；开发镜像可置 INSTALL_COMPOSER_DEV=1
# 安装 dev 依赖（phpunit/collision），以便在容器内运行 `php artisan test`。
ARG INSTALL_COMPOSER_DEV=0
RUN if [ "$INSTALL_COMPOSER_DEV" = "1" ]; then \
        composer install --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs; \
    else \
        composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs; \
    fi

# ---------- 阶段 3：生产运行时 ----------
FROM dunglas/frankenphp:1-php8.3-bookworm AS runtime

# 系统工具与 PHP 扩展：
#   - gd（含 freetype/jpeg/webp）：水印与派生图（必需）
#   - pdo_mysql：MariaDB/MySQL
#   - pcntl：queue worker / scheduler 信号处理
#   - exif：照片元数据回退路径
#   - opcache / zip：性能与归档
#   - exiftool / ffmpeg：完整元数据与视频封面
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl \
        libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev libzip-dev \
        libimage-exiftool-perl ffmpeg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql pcntl exif opcache zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# 应用源码（.env / 媒体 / 数据库 / 秘密由 .dockerignore 排除）
COPY . .

# 生产 Composer 依赖
COPY --from=vendor /build/vendor ./vendor

# 前端构建产物（含 build/、playcaptcha 的 toys/ 与 playcaptcha.svg）
COPY --from=frontend /build/public ./public

# FrankenPHP / Caddy 配置（随应用一同版本化，见 web/docker/Caddyfile）
COPY docker/Caddyfile /etc/caddy/Caddyfile

# PHP 生产配置（上传限制、opcache、安全加固）
COPY docker/php.prod.ini /usr/local/etc/php/conf.d/visionsy.ini

# 运行时目录与权限（storage / bootstrap/cache 需可写）
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        storage/app/protected \
        storage/app/public \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV SERVER_NAME=:80

EXPOSE 80

# 默认启动 Web 服务；worker / scheduler 在 compose 中覆盖 command。
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---------- 阶段 4：单容器生产运行时 ----------
# 复用官方 MariaDB 初始化逻辑，数据库版本与旧部署同为 11.4。
FROM mariadb:11.4 AS mariadb-entrypoint

FROM runtime AS production
RUN curl -fsSL https://mariadb.org/mariadb_release_signing_key.pgp \
        -o /usr/share/keyrings/mariadb.asc \
    && echo 'deb [signed-by=/usr/share/keyrings/mariadb.asc] https://deb.mariadb.org/11.4/debian bookworm main' \
        > /etc/apt/sources.list.d/mariadb.list \
    && printf 'Package: *\nPin: origin "deb.mariadb.org"\nPin-Priority: 1000\n' > /etc/apt/preferences.d/mariadb \
    && apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        mariadb-server mariadb-client supervisor gosu pwgen zstd \
    && mariadbd --version | grep '11.4.' \
    && rm -rf /var/lib/mysql/* /var/lib/apt/lists/* \
    && mkdir -p /docker-entrypoint-initdb.d /run/mysqld \
    && chown mysql:mysql /run/mysqld
COPY --from=mariadb-entrypoint /usr/local/bin/docker-entrypoint.sh /usr/local/bin/mariadb-entrypoint.sh
COPY docker/single/ /etc/visionsy/
COPY docker/Caddyfile.prod /etc/caddy/Caddyfile
RUN chmod +x /etc/visionsy/*.sh
# Inherited FrankenPHP image healthcheck only checks the web process; check all services instead.
HEALTHCHECK --interval=30s --timeout=10s --start-period=180s --retries=3 \
    CMD /etc/visionsy/healthcheck.sh
STOPSIGNAL SIGTERM
ENTRYPOINT ["/etc/visionsy/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/visionsy/supervisord.conf"]
