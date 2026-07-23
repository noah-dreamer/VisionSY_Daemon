####################################################################
# VisionSY 应用镜像（FrankenPHP）
#
# app / worker / scheduler 三个服务共享此镜像，以不同命令运行：
#   - app       : FrankenPHP Web 服务（public/ 为 Web 根）
#   - worker    : php artisan queue:work
#   - scheduler : php artisan schedule:work
#
# 设计要点：
#   - 多阶段构建：前端（Node）与 Composer 依赖分别构建，生产镜像不含
#     node_modules、构建缓存与 dev 依赖。
#   - 不复制 .env、真实媒体、数据库文件或秘密（见 .dockerignore）。
#   - 容器启动不自动 migrate/seed；迁移是单独的发布步骤。
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
FROM dunglas/frankenphp:1-php8.3 AS production

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
