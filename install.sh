#!/usr/bin/env bash
# Linux production installer. Run from a complete checkout: bash install.sh
set +x
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

if [[ " $* " == *" --help "* || " $* " == *" -h "* ]]; then
    cat <<'HELP'
VisionSY Linux 一键安装
用法：bash install.sh [--project visionsy-production]

自动检测并补齐 Docker Engine、Compose、Buildx、Python 3、curl；
构建镜像后交互配置应用监听 IP、HTTP 端口、媒体目录和管理员密码，再初始化并启动服务。
首次安装支持 Ubuntu / Debian / Fedora / CentOS Stream / Rocky / AlmaLinux。
其他 Linux 可使用已安装且可用的 Docker、Compose 和 Buildx。
生产配置保存在 deploy/docker/.env.install，与现有开发部署分开。
HELP
    exit 0
fi

[[ "$(uname -s)" == Linux ]] || { echo '此安装脚本需要 Linux。' >&2; exit 1; }
[[ -f "$SCRIPT_DIR/web/Dockerfile" ]] || { echo '请在完整的 VisionSY 仓库中运行。' >&2; exit 1; }
trap 'echo "[install] 环境准备失败（行 $LINENO）。请修复上方错误后重新运行；已有数据不会被清除。" >&2' ERR

as_root() {
    if [[ "$EUID" == 0 ]]; then
        "$@"
    else
        command -v sudo >/dev/null || { echo '补齐系统环境需要 root；请使用 root 运行此脚本。' >&2; return 1; }
        sudo "$@"
    fi
}

load_os() {
    # /etc/os-release is provided by the operating system, not by the checkout.
    [[ -f /etc/os-release ]] || { echo '无法识别系统：缺少 /etc/os-release。' >&2; return 1; }
    . /etc/os-release
    case "$ID" in
        ubuntu|debian) PACKAGE_FAMILY=apt; DOCKER_DISTRO="$ID" ;;
        fedora) PACKAGE_FAMILY=dnf; DOCKER_DISTRO=fedora ;;
        centos|rocky|almalinux) PACKAGE_FAMILY=dnf; DOCKER_DISTRO=centos ;;
        *) echo "暂不支持自动安装此发行版（$ID）；请先安装所需依赖后重新运行。" >&2; return 1 ;;
    esac
}

install_tools() {
    load_os
    echo '[install] 补齐 curl、系统 CA 根证书（用于下载依赖）、Python 3 和系统工具（可能需要 sudo 密码）...'
    if [[ "$PACKAGE_FAMILY" == apt ]]; then
        as_root apt-get update
        as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl python3 util-linux
    else
        as_root dnf install -y ca-certificates curl python3 util-linux
    fi
}

install_docker_packages() {
    load_os
    echo '[install] 使用 Docker 官方软件源补齐组件...'
    if [[ "$PACKAGE_FAMILY" == apt ]]; then
        local codename arch
        codename="${VERSION_CODENAME:-}"
        [[ -n "$codename" ]] || { echo '无法确定系统发行代号。' >&2; return 1; }
        arch="$(dpkg --print-architecture)"
        as_root install -m 0755 -d /etc/apt/keyrings
        # Reuse an existing official source to avoid duplicate Signed-By declarations.
        if ! grep -Rqs "download.docker.com/linux/$DOCKER_DISTRO" /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
            local key_file repo_file
            key_file="$(mktemp)"
            repo_file="$(mktemp)"
            curl --fail --show-error --location --retry 3 "https://download.docker.com/linux/$DOCKER_DISTRO/gpg" -o "$key_file"
            as_root install -m 0644 "$key_file" /etc/apt/keyrings/docker-visionsy.asc
            cat > "$repo_file" <<EOF
Types: deb
URIs: https://download.docker.com/linux/$DOCKER_DISTRO
Suites: $codename
Components: stable
Architectures: $arch
Signed-By: /etc/apt/keyrings/docker-visionsy.asc
EOF
            as_root install -m 0644 "$repo_file" /etc/apt/sources.list.d/docker-visionsy.sources
            rm -f -- "$key_file" "$repo_file"
        fi
        as_root apt-get update
        as_root env DEBIAN_FRONTEND=noninteractive apt-get install -y --no-remove --no-install-recommends "$@"
    else
        local repo_file
        repo_file="$(mktemp)"
        curl --fail --show-error --location --retry 3 "https://download.docker.com/linux/$DOCKER_DISTRO/docker-ce.repo" -o "$repo_file"
        as_root install -m 0644 "$repo_file" /etc/yum.repos.d/docker-ce.repo
        rm -f -- "$repo_file"
        as_root dnf install -y --setopt=install_weak_deps=False "$@"
    fi
}

echo '[install] 检测 Linux 环境...'
for dependency in curl python3 flock; do
    if ! command -v "$dependency" >/dev/null; then
        install_tools
        break
    fi
done
if [[ ! -f /etc/ssl/certs/ca-certificates.crt && ! -f /etc/pki/tls/certs/ca-bundle.crt ]]; then
    install_tools
fi
if ! python3 -c 'import sys; raise SystemExit(0 if sys.version_info >= (3, 9) else 1)'; then
    install_tools
    python3 -c 'import sys; assert sys.version_info >= (3, 9), "系统软件源中的 Python 版本过旧；请使用支持 Python 3.9+ 的发行版"'
fi

if ! command -v docker >/dev/null; then
    install_docker_packages docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
if ! docker compose version >/dev/null 2>&1 || ! docker buildx version >/dev/null 2>&1 ||
    ! python3 -c 'import re, subprocess; version = subprocess.check_output(["docker", "compose", "version", "--short"], text=True); match = re.search(r"(\d+)\.(\d+)\.(\d+)", version); raise SystemExit(0 if match and tuple(map(int, match.groups())) >= (2, 35, 0) else 1)'; then
    install_docker_packages docker-buildx-plugin docker-compose-plugin
fi

# Keep existing Docker installations and workloads. Do not uninstall packages or reset daemon settings.
if ! docker info >/dev/null 2>&1; then
    if command -v systemctl >/dev/null && ! systemctl is-active --quiet docker; then
        as_root systemctl enable --now docker
    fi
    if ! docker info >/dev/null 2>&1; then
        as_root docker info >/dev/null
        export VISIONSY_DOCKER_SUDO=1
    fi
fi

docker_access=(docker)
[[ "${VISIONSY_DOCKER_SUDO:-}" != 1 ]] || docker_access=(sudo docker)
server_version="$("${docker_access[@]}" version --format '{{.Server.Version}}')"
if ! python3 -c 'import re, sys; match = re.match(r"(\d+)", sys.argv[1]); raise SystemExit(0 if match and int(match[1]) >= 26 else 1)' "$server_version"; then
    echo '[install] Docker Engine 版本低于 26，尝试通过官方软件源升级（需要支持卷子目录挂载）...'
    install_docker_packages docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    as_root systemctl enable --now docker
fi

exec python3 "$SCRIPT_DIR/deploy/install.py" "$@"
