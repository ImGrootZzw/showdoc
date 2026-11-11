<?php

namespace App\Common\Service;

use App\Model\Attachment;
use App\Model\Catalog;
use App\Model\Page;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * 文档导入服务
 *
 * 把「PDF / Word / HTML / Markdown 文件 → 页面」的落库逻辑集中在这里，
 * 供网页导入接口（ImportController / WordController）和 MCP 工具共用，
 * 避免两套入口各自实现导致行为不一致。
 *
 * 约定：调用方负责登录态与权限校验，本服务只做数据落库。
 */
class DocumentImportService
{
    /** Word 文档存储根目录：server/data/word */
    public static function getWordStorageDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'word';
    }

    /**
     * 确保 Word 存储目录存在且可写
     */
    public static function ensureWordStorageDir(): bool
    {
        $dir = self::getWordStorageDir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }
        return is_writable($dir);
    }

    /**
     * 解析目录 ID 为目录路径（updateByTitle 按路径匹配/创建目录）
     */
    public static function resolveCatPath(int $itemId, int $catId): ?string
    {
        if ($catId <= 0) {
            return '';
        }
        $cat = Catalog::findByIdAndItemId($catId, $itemId);
        if (!$cat) {
            return null;
        }
        return Catalog::getCatPath($catId);
    }

    /**
     * 导入 PDF：存为附件 + 创建内嵌 PDF.js 阅读器页面
     *
     * @param int $uid 操作人
     * @param string $username 操作人用户名
     * @param int $itemId 项目
     * @param int $catId 目标目录（0 表示根目录）
     * @param string $filename 原始文件名
     * @param string $tmpPath 已落盘到本地临时路径的 PDF 文件
     * @return int 页面 ID（失败返回 0）
     */
    public static function importPdf(
        int $uid,
        string $username,
        int $itemId,
        int $catId,
        string $filename,
        string $tmpPath
    ): int {
        if (!is_file($tmpPath)) {
            return 0;
        }

        $pageTitle = pathinfo($filename, PATHINFO_FILENAME);
        if ($pageTitle === '') {
            $pageTitle = 'PDF 文档';
        }

        // 保存 PDF 为附件
        $_files = [
            'file' => [
                'name'     => $filename,
                'type'     => 'application/pdf',
                'tmp_name' => $tmpPath,
                'size'     => (int) filesize($tmpPath),
            ],
        ];
        $url = Attachment::upload($_files, 'file', $uid, $itemId, 0);
        if (!$url) {
            return 0;
        }

        // 优先用本地 PDF.js 阅读器
        $iframeSrc = $url;
        if (preg_match('/[?&]sign=([A-Za-z0-9]+)/', $url, $m)) {
            $viewerFile = '../../../server/?s=/api/attachment/visitFile&sign=' . $m[1];
            $iframeSrc = '../web/pdfjs/web/viewer.html?file=' . rawurlencode($viewerFile) . '#zoom=page-width';
        }
        $safeUrl = htmlspecialchars($iframeSrc, ENT_QUOTES, 'UTF-8');
        $content = '<iframe src="' . $safeUrl . '" style="width:100%;height:calc(100vh - 150px);min-height:600px;border:1px solid #eee;border-radius:6px;" frameborder="0"></iframe>';

        $catPath = self::resolveCatPath($itemId, $catId);
        if ($catPath === null) {
            return 0;
        }

        $pageId = Page::updateByTitle($itemId, $pageTitle, $content, $catPath, 99, $uid, $username);
        if (!$pageId) {
            return 0;
        }

        // 指定目录时把页面移入该目录（updateByTitle 已按路径建好目录）
        if ($catId > 0) {
            DB::table('page')->where('page_id', $pageId)->update(['cat_id' => $catId]);
        }

        Page::deleteCache($pageId);
        \App\Model\Item::deleteCache($itemId);

        return (int) $pageId;
    }

    /**
     * 导入 Word：文件存 server/data/word + 创建可在线编辑的页面
     *
     * @param int $uid
     * @param string $username
     * @param int $itemId
     * @param int $catId
     * @param string $filename 原始文件名（.docx / .doc）
     * @param string $tmpPath 已落盘的临时文件
     * @return int 页面 ID（失败返回 0）
     */
    public static function importWord(
        int $uid,
        string $username,
        int $itemId,
        int $catId,
        string $filename,
        string $tmpPath
    ): int {
        if (!is_file($tmpPath)) {
            return 0;
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'doc'], true)) {
            return 0;
        }

        if (!self::ensureWordStorageDir()) {
            return 0;
        }

        $catPath = self::resolveCatPath($itemId, $catId);
        if ($catPath === null) {
            return 0;
        }

        $saveName = bin2hex(random_bytes(16)) . '.' . $ext;
        $fullPath = self::getWordStorageDir() . DIRECTORY_SEPARATOR . $saveName;

        if (!@copy($tmpPath, $fullPath)) {
            return 0;
        }

        $pageTitle = pathinfo($filename, PATHINFO_FILENAME);
        if ($pageTitle === '') {
            $pageTitle = 'Word 文档';
        }

        $meta = [
            'path'  => $saveName,
            'name'  => $filename,
            'title' => $pageTitle,
            'size'  => (int) filesize($fullPath),
            'mtime' => time(),
            'ext'   => $ext,
        ];

        $pageId = Page::updateByTitle($itemId, $pageTitle, '', $catPath, 99, $uid, $username);
        if (!$pageId) {
            @unlink($fullPath);
            return 0;
        }

        // 覆盖已有 Word 页面时清理旧文件
        $existing = Page::findById($pageId);
        if ($existing) {
            $oldMeta = json_decode(htmlspecialchars_decode((string) ($existing['page_content'] ?? ''), ENT_QUOTES), true);
            $oldPath = is_array($oldMeta) ? (string) ($oldMeta['path'] ?? '') : '';
            if ($oldPath !== '' && $oldPath !== $saveName) {
                $oldFull = self::resolveWordPath($oldPath);
                if ($oldFull !== null && is_file($oldFull)) {
                    @unlink($oldFull);
                }
            }
        }

        DB::table('page')->where('page_id', $pageId)->update([
            'page_content' => htmlspecialchars(
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ENT_QUOTES,
                'UTF-8'
            ),
            'ext_info' => json_encode(['page_type' => 'word'], JSON_UNESCAPED_UNICODE),
        ]);

        Page::deleteCache($pageId);
        \App\Model\Item::deleteCache($itemId);
        \App\Model\DocSearchIndex::indexPage($pageId);

        return (int) $pageId;
    }

    /**
     * 导入 HTML：源码直接存页面正文，前端用沙箱 iframe 渲染
     *
     * @param int $uid
     * @param string $username
     * @param int $itemId
     * @param int $catId
     * @param string $html HTML 源码
     * @param string $pageTitle 页面标题
     * @return int 页面 ID（失败返回 0）
     */
    public static function importHtml(
        int $uid,
        string $username,
        int $itemId,
        int $catId,
        string $html,
        string $pageTitle
    ): int {
        $html = trim($html);
        if ($html === '') {
            return 0;
        }

        if ($pageTitle === '') {
            $pageTitle = 'HTML 页面';
        }

        // 去 BOM
        if (strpos($html, "\xEF\xBB\xBF") === 0) {
            $html = substr($html, 3);
        }

        // 非 UTF-8 内容转码，避免入库时 htmlspecialchars 清空非法字节
        if (!mb_check_encoding($html, 'UTF-8')) {
            $detected = mb_detect_encoding($html, ['UTF-8', 'GB18030', 'GBK', 'BIG5'], true);
            $html = mb_convert_encoding($html, 'UTF-8', $detected ?: 'GB18030');
            // 同步改写页面内的 charset 声明，避免浏览器按旧编码解析
            $html = preg_replace(
                '/(<meta[^>]*charset\s*=\s*["\']?)([A-Za-z0-9_\-]+)/i',
                '$1utf-8',
                $html
            ) ?? $html;
        }

        $catPath = self::resolveCatPath($itemId, $catId);
        if ($catPath === null) {
            return 0;
        }

        $pageId = Page::updateByTitle($itemId, $pageTitle, '', $catPath, 99, $uid, $username);
        if (!$pageId) {
            return 0;
        }

        // 按页面保存接口的规则转义写入，保证 HTML 里的实体原样保存
        DB::table('page')->where('page_id', $pageId)->update([
            'page_content' => htmlspecialchars($html, ENT_QUOTES, 'UTF-8'),
            'ext_info'     => json_encode(['page_type' => 'html'], JSON_UNESCAPED_UNICODE),
        ]);

        Page::deleteCache($pageId);
        \App\Model\Item::deleteCache($itemId);
        \App\Model\DocSearchIndex::indexPage($pageId);

        return (int) $pageId;
    }

    /**
     * 导入 Markdown：按普通页面写入
     */
    public static function importMarkdown(
        int $uid,
        string $username,
        int $itemId,
        int $catId,
        string $markdown,
        string $pageTitle
    ): int {
        if (trim($markdown) === '' || $pageTitle === '') {
            return 0;
        }

        $catPath = self::resolveCatPath($itemId, $catId);
        if ($catPath === null) {
            return 0;
        }

        $pageId = Page::updateByTitle($itemId, $pageTitle, $markdown, $catPath, 99, $uid, $username);
        if (!$pageId) {
            return 0;
        }

        \App\Model\DocSearchIndex::indexPage((int) $pageId);
        return (int) $pageId;
    }

    /**
     * 校验 Word 存储相对路径，防止目录穿越
     */
    public static function resolveWordPath(string $relativePath): ?string
    {
        $relativePath = str_replace(['..', '\\'], ['', '/'], $relativePath);
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') {
            return null;
        }

        $base = self::getWordStorageDir();
        $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $realBase = realpath($base);
        $realFull = realpath($full);

        if ($realBase === false || $realFull === false) {
            return null;
        }
        if (strpos($realFull, $realBase . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $realFull;
    }
}
