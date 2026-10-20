<?php
/**
 * ShowDoc PHP 内置服务器路由脚本
 * 用法: php -S 127.0.0.1:4999 router.php
 */
$root = __DIR__;
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// /server 开头的 API 请求交给 server/index.php
if ($uri === '/server' || strpos($uri, '/server/') === 0) {
    require $root . '/server/index.php';
    return true;
}

// 静态文件，以及含 index.html/index.php 的目录（如 /web/），直接交给内置服务器
$path = $root . rtrim($uri, '/');
if ($uri !== '/' && (
    is_file($path) ||
    (is_dir($path) && (is_file($path . '/index.html') || is_file($path . '/index.php')))
)) {
    return false;
}

// 其余交给根 index.php（前端 SPA / 安装页 / 项目页面）
require $root . '/index.php';
return true;
