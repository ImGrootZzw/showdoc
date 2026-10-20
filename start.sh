#!/bin/bash
# =============================================================
# ShowDoc 启动脚本 (Rocky/Alma/CentOS Linux)
# 启动 caddy + php-fpm 服务并设置开机自启
#
# 用法：sudo bash start.sh
# =============================================================
set -e

if [ "$(id -u)" -ne 0 ]; then
    echo "[错误] 请以 root 运行: sudo bash $0"
    exit 1
fi

# 自动识别 PHP-FPM 服务名（remi php84 或 系统 php）
if systemctl list-unit-files 2>/dev/null | grep -q 'php84-php-fpm.service'; then
    FPM_SVC="php84-php-fpm"
else
    FPM_SVC="php-fpm"
fi

echo "== 启动 $FPM_SVC ..."
systemctl enable --now "$FPM_SVC" 2>/dev/null || true
systemctl start "$FPM_SVC"

echo "== 启动 caddy ..."
systemctl enable --now caddy 2>/dev/null || true
systemctl start caddy

sleep 2

echo "== 服务状态:"
systemctl is-active "$FPM_SVC" caddy

echo ""
echo "== 验证 HTTP 访问:"
curl -s -o /dev/null -w "  http://127.0.0.1:4999/ -> %{http_code}\n" "http://127.0.0.1:4999/" || echo "  [错误] caddy 未响应，请查看日志: journalctl -u caddy -n 50"
echo "  API: $(curl -s http://127.0.0.1:4999/server/ping || echo 无响应)"
echo ""
echo "== 启动完成"