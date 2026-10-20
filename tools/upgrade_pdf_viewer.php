<?php
/**
 * 把已导入的 PDF 页面切换为本地 PDF.js 阅读器（web/pdfjs）
 *
 * 背景：早期「导入PDF」生成的页面用 iframe 直接指向附件地址，
 * 浏览器内置 PDF 阅读器简陋。新版导入已改用本地 PDF.js 阅读器；
 * 本脚本把历史页面批量切换过来（只改数据库正文，不动磁盘文件）。
 *
 * 用法（showdoc 根目录下执行，先备份数据库）：
 *   php tools/upgrade_pdf_viewer.php          # 预览（dry-run，不改库）
 *   php tools/upgrade_pdf_viewer.php --apply  # 执行
 *
 * 说明：
 *  - 只匹配「iframe + ../server/?s=/api/attachment/visitFile&sign=」的旧导入页面；
 *  - 幂等，可重复执行；
 *  - 建议执行后清一次缓存（或在后台设置里点「更新缓存」）。
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

$apply = in_array('--apply', array_slice($argv, 1), true);
echo $apply ? "== 执行模式（--apply） ==\n" : "== 预览模式（dry-run，不改库；加 --apply 执行） ==\n";

$db = getDb();
$stmt = $db->query("SELECT page_id, page_title, page_content FROM page WHERE page_content LIKE '%visitFile%'");
$upd = $db->prepare("UPDATE page SET page_content = ? WHERE page_id = ?");

$count = 0;
foreach ($stmt as $row) {
    $content = (string) $row['page_content'];
    $replaced = false;

    // 转义存储（常规情况）：&lt;iframe src=&quot;../server/?s=/api/attachment/visitFile&amp;sign=xxx&quot;
    $new = preg_replace_callback(
        '/&lt;iframe src=&quot;(?:\.\.?\/server\/\?s=\/api\/attachment\/visitFile&(?:amp;)?sign=([A-Za-z0-9]+))&quot;/',
        function ($m) use (&$replaced) {
            $replaced = true;
            $viewerFile = '../../../server/?s=/api/attachment/visitFile&sign=' . $m[1];
            return '&lt;iframe src=&quot;../web/pdfjs/web/viewer.html?file=' . rawurlencode($viewerFile) . '#zoom=page-width&quot;';
        },
        $content
    );

    // 未转义存储（个别直接写库的页面）
    $new = preg_replace_callback(
        '/<iframe src="(?:\.\.?\/server\/\?s=\/api\/attachment\/visitFile&sign=([A-Za-z0-9]+))"/',
        function ($m) use (&$replaced) {
            $replaced = true;
            $viewerFile = '../../../server/?s=/api/attachment/visitFile&sign=' . $m[1];
            return '<iframe src="../web/pdfjs/web/viewer.html?file=' . rawurlencode($viewerFile) . '#zoom=page-width"';
        },
        $new
    );

    if (!$replaced) {
        continue;
    }

    // 阅读区域高度改为自适应视口
    $new = str_replace('height:850px;', 'height:calc(100vh - 150px);min-height:600px;', $new);

    $id = (int) $row['page_id'];
    $title = (string) $row['page_title'];

    if ($apply) {
        // 清页面内容缓存（即使正文已是最新，也顺带清一次，避免 24h 内看到旧渲染）
        $cacheFile = __DIR__ . '/../server/app/Runtime/cache/' . md5('showdoc_page_cache2_page_id_' . $id) . '.json';
        if (is_file($cacheFile)) {
            @unlink($cacheFile);
            echo "  [cache] cleared page_id={$id}\n";
        }
    }

    if ($new === $content) {
        continue;
    }

    if ($apply) {
        $upd->execute([$new, $id]);
        echo "  [updated] page_id={$id} ({$title})\n";
    } else {
        echo "  [dry-run] page_id={$id} ({$title}) 将切换为 PDF.js 阅读器\n";
    }
    $count++;
}

echo "\n共 " . ($apply ? "更新" : "待更新") . " {$count} 个页面。\n";
if (!$apply && $count > 0) {
    echo "确认无误后执行: php tools/upgrade_pdf_viewer.php --apply\n";
}
