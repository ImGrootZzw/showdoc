#!/bin/bash
# ShowDoc 停止脚本 (Rocky/Alma/CentOS Linux)
# 停止 caddy + php-fpm 服务
set -e

if [ "$(id -u)" -ne 0 ]; then
    echo "[错误] 请以 root 运行: sudo bash stop.sh"
    exit 1
fi

echo "== 停止 ShowDoc 服务..."
systemctl stop caddy 2>/dev/null || true

# 兼容 remi php84 与系统 php-fpm 两种安装方式
if systemctl list-units --type=service | grep -q 'php84-php-fpm'; then
    systemctl stop php84-php-fpm 2>/dev/null || true
else
    systemctl stop php-fpm 2>/dev/null || true
fi

echo "== 已停止"
systemctl is-active caddy php84-php-fpm php-fpm 2>/dev/null || true