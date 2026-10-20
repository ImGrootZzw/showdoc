#!/bin/bash
# =============================================================
# ShowDoc 安装/部署脚本 (Rocky/Alma/CentOS Linux 8 / 9)
# 方案：Caddy (HTTP) + PHP-FPM (FastCGI) ，解决 PHP 内置服务器
#       大文件上传卡死问题（与 Windows 版一致）。
# 安装完成后会自动调用 start.sh 启动服务。
#
# 用法：
#   sudo bash install.sh [/path/to/showdoc-source]
#   - 不带参数时使用脚本自身所在目录作为源码目录
#
# 可调变量（下方）：APP_ROOT / APP_PORT / PHP_MODE 等
# =============================================================
set -e

# ---------- 配置 ----------
APP_ROOT="/app/showdoc"              # 部署目标目录
APP_PORT="4999"                      # HTTP 端口
FCGI_PORT="9001"                     # php-fpm FastCGI 端口
PHP_MODE="remi84"                    # remi84(推荐, PHP 8.4) | system(系统 PHP)
APP_USER="apache"                    # php-fpm 运行用户 (Rocky 默认 apache)
SRC_DIR="${1:-$(cd "$(dirname "$0")" && pwd)}"

# ---------- 0. 前置检查 ----------
if [ "$(id -u)" -ne 0 ]; then
    echo "[错误] 请以 root 运行: sudo bash $0"
    exit 1
fi
if [ ! -d "$SRC_DIR/server" ] || [ ! -d "$SRC_DIR/web" ] || [ ! -d "$SRC_DIR/Sqlite" ]; then
    echo "[错误] 源码目录不正确: $SRC_DIR (缺少 server/ web/ Sqlite/)"
    exit 1
fi
echo "== 源码目录: $SRC_DIR"
echo "== 目标目录: $APP_ROOT"

# 自动检测系统版本 (Rocky/Alma/CentOS 8 或 9)
. /etc/os-release
case "${VERSION_ID:-}" in
    8*) OS_MAJOR=8 ;;
    9*) OS_MAJOR=9 ;;
    *)  echo "[错误] 无法识别系统版本: $ID $VERSION_ID (仅支持 EL8/EL9)" && exit 1 ;;
esac
echo "== 检测到系统: $PRETTY_NAME (EL$OS_MAJOR)"

# ---------- 1. 安装 PHP-FPM ----------
if [ "$PHP_MODE" = "remi84" ]; then
    echo "== 安装 remi 仓库 + PHP 8.4 (EL$OS_MAJOR)"
    dnf install -y epel-release
    dnf install -y dnf-utils
    dnf install -y --nogpgcheck "https://rpms.remirepo.net/enterprise/remi-release-$OS_MAJOR.rpm"
    dnf module reset -y php || true
    dnf install -y php84-php-fpm php84-php-cli php84-php-pdo \
        php84-php-mbstring php84-php-gd php84-php-curl php84-php-openssl \
        php84-php-xml php84-php-zip php84-php-json php84-php-sqlite3 \
        php84-php-process php84-php-gmp
    FPM_CONF="/etc/opt/remi/php84/php-fpm.d/www.conf"
else
    echo "== 安装系统 PHP + php-fpm (EL$OS_MAJOR)"
    dnf install -y epel-release
    dnf install -y php php-fpm php-cli php-pdo php-mbstring php-gd \
        php-curl php-openssl php-xml php-zip php-json php-sqlite3 \
        php-process php-gmp
    FPM_CONF="/etc/php-fpm.d/www.conf"
fi

# ---------- 2. 安装 Caddy ----------
if ! command -v caddy >/dev/null 2>&1; then
    echo "== 安装 Caddy (EPEL)"
    dnf install -y --enablerepo=epel caddy
fi

# # ---------- 3. 拷贝源码 ----------
# echo "== 拷贝源码到 $APP_ROOT (排除构建/无用目录)"
# mkdir -p "$APP_ROOT"
# rsync -a \
#     --exclude 'web_src' --exclude 'mock' --exclude 'tests' \
#     --exclude 'tools' --exclude 'documentation' --exclude '.github' \
#     --exclude '.git' --exclude '*.bat' --exclude 'router.php' \
#     --exclude 'Caddyfile' --exclude '.htaccess' --exclude '.dockerignore' \
#     --exclude '.editorconfig' --exclude '.gitattributes' --exclude '.gitignore' \
#     --exclude 'Dockerfile' --exclude 'Dockerfile.ARM' --exclude 'docker-compose.yml' \
#     --exclude 'docker.run.sh' --exclude 'install.sh' --exclude 'start.sh' --exclude 'stop.sh' \
#     "$SRC_DIR/" "$APP_ROOT/"

# ---------- 4. 配置 php-fpm ----------
echo "== 配置 php-fpm (listen=$FCGI_PORT, 大文件上传不限)"
if ! grep -q "showdoc" "$FPM_CONF"; then
cat >> "$FPM_CONF" <<EOF

; ===== showdoc 大文件上传配置 =====
user = $APP_USER
group = $APP_USER
listen = 127.0.0.1:$FCGI_PORT
listen.allowed_clients = 127.0.0.1
php_value[post_max_size] = 0
php_value[upload_max_filesize] = 0
php_value[max_input_time] = -1
php_value[max_execution_time] = 300
php_value[memory_limit] = 256M
EOF
fi

# ---------- 5. 生成 Caddyfile ----------
echo "== 生成 /etc/caddy/Caddyfile"
mkdir -p /etc/caddy
cat > /etc/caddy/Caddyfile <<EOF
{
	admin off
	auto_https off
}

# ShowDoc: Caddy + php-fpm (FastCGI)
:$APP_PORT {
	root * $APP_ROOT
	encode gzip

	handle_path /server* {
		rewrite * /server/index.php
		php_fastcgi 127.0.0.1:$FCGI_PORT
	}

	handle /mcp.php {
		php_fastcgi 127.0.0.1:$FCGI_PORT
	}
	handle /Public/verifyCode.php {
		php_fastcgi 127.0.0.1:$FCGI_PORT
	}

	handle /install* {
		try_files {path} {path}/index.php index.php
		php_fastcgi 127.0.0.1:$FCGI_PORT
	}

	@dir {
		path */
		not path /
	}
	handle @dir {
		try_files {path} {path}/index.html
		file_server
	}

	@fileexists {
		file
		not path *.php
	}
	handle @fileexists {
		file_server
	}

	@uploadsphp {
		file
		path /Public/Uploads/*
	}
	handle @uploadsphp {
		file_server
	}

	@phppath {
		path *.php
	}
	handle @phppath {
		try_files {path} index.php
		php_fastcgi 127.0.0.1:$FCGI_PORT
	}

	handle {
		try_files {path} index.php
		php_fastcgi 127.0.0.1:$FCGI_PORT
	}
}
EOF

# ---------- 6. SELinux / 防火墙 ----------
echo "== 配置 SELinux 与防火墙"
if command -v semanage >/dev/null 2>&1; then
    semanage port -a -t http_port_t -p tcp $FCGI_PORT 2>/dev/null || true
    setsebool -P httpd_can_network_connect 1 2>/dev/null || true
    # 允许 php-fpm 写入整个 showdoc 目录（showdoc 要求全目录可写，含 install/Runtime/Uploads/Sqlite）
    semanage fcontext -a -t httpd_sys_rw_content_t "$APP_ROOT(/.*)?" 2>/dev/null || true
    restorecon -R "$APP_ROOT" 2>/dev/null || true
fi
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active firewalld >/dev/null 2>&1; then
    firewall-cmd --permanent --add-port=$APP_PORT/tcp 2>/dev/null || true
    firewall-cmd --reload 2>/dev/null || true
fi

# ---------- 7. 目录权限 ----------
echo "== 设置目录权限 ($APP_USER)"
chown -R "$APP_USER":"$APP_USER" "$APP_ROOT/Public" "$APP_ROOT/Sqlite"
chmod -R u+rwX,g+rX,o+rX "$APP_ROOT/Public" "$APP_ROOT/Sqlite"
chmod -R u+w "$APP_ROOT/Public/Uploads" "$APP_ROOT/Sqlite"
[ -d "$APP_ROOT/server/data" ] && chown -R "$APP_USER":"$APP_USER" "$APP_ROOT/server/data" || true


echo ""
echo "============================================================"
echo " 部署完成！浏览器访问:  http://服务器IP:$APP_PORT/"
echo " 默认管理员: showdoc / 123456  (登录后请修改密码)"
echo ""
echo " 后续注意事项:"
echo "  1. 上传域名/回调地址不正确时，登录后台 -> 设置 -> 填写 site_url (如 http://服务器IP:$APP_PORT)"
echo "  2. 上传文件保存在 $APP_ROOT/Public/Uploads/"
echo "  3. 数据库: $APP_ROOT/Sqlite/showdoc.db.php (备份它即可备份全部数据)"
echo "  4. 若修改端口，改 /etc/caddy/Caddyfile 并 systemctl restart caddy"
echo "  5. 若 SELinux 拦截(日志有 denied)，可临时 setenforce 0 验证"
echo "============================================================"