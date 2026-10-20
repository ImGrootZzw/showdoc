<?php
/**
 * 修复 showdoc 中的文件/图片链接地址
 *
 * 背景：showdoc 原在本机/旧域名运行，库中保存了旧域名的绝对链接
 * （upload_file.real_url、page 的 page_content 里的 markdown 图片/附件地址）。
 * 搬到新服务器后，这些旧链接会失效。
 *
 * 用法（服务器 showdoc 根目录下执行，先备份数据库）：
 *
 * 1) 把 upload_file.real_url 统一改为相对路径（推荐，迁移后不用再改）：
 *      php tools/fix_upload_url.php --relative
 *
 * 2) 把历史 markdown 正文里的旧绝对地址改成相对地址（推荐）：
 *      php tools/fix_upload_url.php --pages http://127.0.0.1:4999
 *    （--pages 后只给一个旧前缀 = 相对化；给两个前缀 = 替换成新域名的绝对地址）
 *      php tools/fix_upload_url.php --pages http://127.0.0.1:4999 http://doc.example.com
 *
 * 3) 旧用法（仅替换 real_url 的域名，不推荐，下次迁移还要再改）：
 *      php tools/fix_upload_url.php http://127.0.0.1:4999 http://doc.example.com
 *
 * 说明：
 *  - 只改数据库元数据/正文，不影响磁盘文件；
 *  - 相对地址在"web 与 server 同源"部署下生效（showdoc 默认即如此）；
 *  - 幂等，可重复执行。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("仅支持命令行运行\n");
}

function getDb(): PDO
{
    $dbPath = __DIR__ . '/../Sqlite/showdoc.db.php';
    if (!is_file($dbPath)) {
        exit("找不到数据库: $dbPath\n");
    }
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

// 把绝对地址(http://host/...)去掉域名/端口前缀，保留 /xxx/yyy 相对路径
function relativize(string $url): string
{
    $u = preg_replace('#^https?://[^/]+#i', '', $url);
    return $u === null ? $url : $u;
}

$opts = array_slice($argv, 1);

// ---- 用法校验 ----
if (!$opts) {
    echo "用法:\n";
    echo "  php tools/fix_upload_url.php --relative\n";
    echo "  php tools/fix_upload_url.php --pages http://旧域名[:端口] [http://新域名[:端口]]\n";
    echo "  php tools/fix_upload_url.php http://旧域名[:端口] http://新域名[:端口]\n";
    exit(1);
}

// ---- 模式 1: --relative  real_url 全部相对化 ----
if ($opts[0] === '--relative') {
    $db = getDb();
    $count = 0;
    $stmt = $db->query("SELECT file_id, real_url FROM upload_file WHERE real_url LIKE 'http://%' OR real_url LIKE 'https://%'");
    foreach ($stmt as $row) {
        $newUrl = relativize($row['real_url']);
        if ($newUrl === $row['real_url']) {
            continue;
        }
        $db->prepare("UPDATE upload_file SET real_url = ? WHERE file_id = ?")
            ->execute([$newUrl, (int) $row['file_id']]);
        echo "  file_id={$row['file_id']}: {$row['real_url']}\n          -> $newUrl\n";
        $count++;
    }
    echo "\nreal_url 共修正 {$count} 条（已全部转为相对路径）。\n";
    if ($count > 0) {
        echo "今后新上传的文件链接也已是相对路径，服务器迁移/换域名无需再改库。\n";
    }
    exit(0);
}

// ---- 模式 2: --pages  历史 markdown 正文里的旧地址 ----
if ($opts[0] === '--pages') {
    if (count($opts) < 2) {
        exit("--pages 需指定旧域名前缀: php tools/fix_upload_url.php --pages http://127.0.0.1:4999\n");
    }
    $old = rtrim($opts[1], '/');
    $new = (count($opts) >= 3 && $opts[2] !== '') ? rtrim($opts[2], '/') : null;
    if ($new === null) {
        echo "将把旧前缀 $old 相对化\n";
    } else {
        echo "将把旧前缀 $old 替换为 $new\n";
    }

    $db = getDb();
    $count = 0;
    $stmt = $db->query("SELECT page_id, page_content FROM page");
    $upd = $db->prepare("UPDATE page SET page_content = ? WHERE page_id = ?");
    foreach ($stmt as $row) {
        $content = $row['page_content'];
        if ($content === null || $content === '' || strpos($content, $old) === false) {
            continue;
        }
        $id = (int) $row['page_id'];
        if ($new === null) {
            // 相对化：去掉旧域名前缀，剩下的 /server/... 或 /Public/... 原本以 / 开头则保留
            $newContent = str_replace($old . '/', '/', $content);
            // 兼容旧前缀不带尾斜杠却直接拼接完整路径的情况（防御）
            $newContent = str_replace($old, '', $newContent);
        } else {
            $newContent = str_replace($old, $new, $content);
        }
        if ($newContent === $content) {
            continue;
        }
        $upd->execute([$newContent, $id]);
        echo "  page_id={$id}: 更新正文\n";
        $count++;
    }
    echo "\npage 正文共修正 {$count} 页。\n";
    exit(0);
}

// ---- 模式 3: 兼容旧用法  real_url 域名替换 ----
$old = rtrim($opts[0] ?? '', '/');
$new = rtrim($opts[1] ?? '', '/');
if ($old === '' || $new === '') {
    exit("参数不完整: 需要 旧前缀 新前缀\n");
}

$db = getDb();
$count = 0;
$stmt = $db->query("SELECT file_id, real_url FROM upload_file WHERE real_url LIKE '" . str_replace("'", "''", $old) . "%'");
foreach ($stmt as $row) {
    $newUrl = $new . substr($row['real_url'], strlen($old));
    $db->prepare("UPDATE upload_file SET real_url = ? WHERE file_id = ?")
        ->execute([$newUrl, (int) $row['file_id']]);
    echo "  file_id={$row['file_id']}: {$row['real_url']}\n          -> $newUrl\n";
    $count++;
}
echo "\nreal_url 共替换 {$count} 条。\n";