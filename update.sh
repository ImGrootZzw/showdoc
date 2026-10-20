#!/bin/bash
# =============================================================
# ShowDoc 增量更新脚本 (Rocky/Alma/CentOS Linux)
# 把本地/最新源码增量覆盖到服务器部署目录，并重启服务。
# 不会触碰数据目录（Sqlite/ 数据库、Public/Uploads/ 上传文件）。
#
# 用法1（推荐）：
#   把最新代码连同本脚本放同一目录，执行：
#     sudo bash update.sh /path/to/latest/source
# 用法2（在服务器上就地更新）：
#   拷贝最新 server/ web/ 等到 /opt/showdoc 后，执行：
#     sudo bash /opt/showdoc/update.sh
# =============================================================
set -e

if [ "$(id -u)" -ne 0 ]; then
    echo "[错误] 请以 root 运行: sudo bash $0"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_ROOT="${APP_ROOT:-/opt/showdoc}"

# 若指定了源码目录则用它，否则用脚本所在目录
if [ -n "$1" ] && [ -d "$1/server" ]; then
    SRC_DIR="$1"
else
    SRC_DIR="$SCRIPT_DIR"
fi

if [ ! -d "$SRC_DIR/server" ] || [ ! -d "$SRC_DIR/web" ]; then
    echo "[错误] 源码目录不正确: $SRC_DIR"
    exit 1
fi
if [ ! -d "$APP_ROOT/server" ]; then
    echo "[错误] 部署目录不存在: $APP_ROOT (请先运行 install.sh)"
    exit 1
fi

echo "== 源码: $SRC_DIR"
echo "== 部署: $APP_ROOT"

echo "== 增量拷贝代码（保留数据目录）..."
# 排除数据目录与构建无关文件：Sqlite(数据库) / Public/Uploads(上传文件) 绝不覆盖
rsync -a \
    --exclude 'Sqlite/' \
    --exclude 'Public/Uploads/' \
    --exclude 'web_src' --exclude 'mock' --exclude 'tests' \
    --exclude 'tools' --exclude 'documentation' --exclude '.github' \
    --exclude '.git' --exclude '*.bat' --exclude 'router.php' \
    --exclude 'Caddyfile' --exclude '.htaccess' --exclude '.dockerignore' \
    --exclude '.editorconfig' --exclude '.gitattributes' --exclude '.gitignore' \
    --exclude 'Dockerfile' --exclude 'Dockerfile.ARM' --exclude 'docker-compose.yml' \
    --exclude 'docker.run.sh' --exclude 'install.sh' --exclude 'start.sh' --exclude 'stop.sh' --exclude 'update.sh' \
    "$SRC_DIR/" "$APP_ROOT/"

# 更新工具类修改后保持可执行
chmod +x "$APP_ROOT/tools/"*.php 2>/dev/null || true

# 恢复写权限（代码覆盖后属主可能变为 root）
chown -R apache:apache "$APP_ROOT/Public" "$APP_ROOT/Sqlite" 2>/dev/null || true
chmod -R u+w "$APP_ROOT/Public/Uploads" "$APP_ROOT/Sqlite" 2>/dev/null || true

echo "== 重启服务..."
if systemctl list-unit-files 2>/dev/null | grep -q 'php84-php-fpm.service'; then
    FPM_SVC="php84-php-fpm"
else
    FPM_SVC="php-fpm"
fi
systemctl restart "$FPM_SVC"
systemctl restart caddy
sleep 2

echo "== 验证:"
curl -s -o /dev/null -w "  http://127.0.0.1:4999/ -> %{http_code}\n" "http://127.0.0.1:4999/" || true
echo "  API: $(curl -s http://127.0.0.1:4999/server/ping || echo 无响应)"
echo "== 更新完成"