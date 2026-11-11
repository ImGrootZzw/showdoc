<?php

namespace App\Mcp\Handler;

use App\Mcp\McpHandler;
use App\Mcp\McpError;
use App\Mcp\McpException;
use App\Common\Service\DocumentImportService;
use App\Model\Item;
use App\Model\User;

/**
 * MCP 文档导入 Handler
 *
 * 让 AI 编辑器可以直接把 PDF / Word / HTML / Markdown 导入为 ShowDoc 页面，
 * 行为与网页端的「导入」功能一致：
 * - PDF  → 附件 + 内嵌阅读页面
 * - Word → server/data/word 下的文件 + 可在线编辑页面
 * - HTML → 页面正文存源码，前端沙箱渲染
 */
class ImportHandler extends McpHandler
{
    /** 单个文件大小上限（字节） */
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    public function getSupportedOperations(): array
    {
        return [
            'import_pdf',
            'import_word',
            'import_html',
            'import_markdown',
        ];
    }

    public function execute(string $operation, array $params = [])
    {
        switch ($operation) {
            case 'import_pdf':
                return $this->importBinary($params, 'pdf');
            case 'import_word':
                return $this->importBinary($params, 'word');
            case 'import_html':
                return $this->importHtml($params);
            case 'import_markdown':
                return $this->importMarkdown($params);
            default:
                McpError::throw(McpError::METHOD_NOT_FOUND, "操作不存在: {$operation}");
        }
    }

    /**
     * 读取并校验公共参数
     *
     * @return array{itemId:int, catId:int, uid:int, username:string}
     */
    private function resolveContext(array $params): array
    {
        $itemId = (int) ($params['item_id'] ?? 0);
        if ($itemId <= 0) {
            McpError::throw(McpError::INVALID_PARAMS, '项目ID不能为空');
        }

        $this->requireWritePermission($itemId);

        $item = Item::findById($itemId);
        if (!$item) {
            McpError::throw(McpError::RESOURCE_NOT_FOUND, '项目不存在');
        }

        $uid = $this->getUid();
        $user = User::findById($uid);
        $username = $user ? (string) ($user->username ?? '') : '';

        return [
            'itemId'   => $itemId,
            'catId'    => (int) ($params['cat_id'] ?? 0),
            'uid'      => $uid,
            'username' => $username,
        ];
    }

    /**
     * 把 base64 内容写入临时文件
     *
     * @return string 临时文件路径
     */
    private function writeTempFile(string $base64, string $fileName): string
    {
        $content = base64_decode($base64, true);
        if ($content === false) {
            McpError::throw(McpError::INVALID_PARAMS, 'file_base64 不是合法的 Base64 内容');
        }
        if ($content === '') {
            McpError::throw(McpError::INVALID_PARAMS, '文件内容为空');
        }
        if (strlen($content) > self::MAX_FILE_SIZE) {
            $maxMB = round(self::MAX_FILE_SIZE / 1024 / 1024);
            McpError::throw(McpError::OPERATION_FAILED, "文件大小超出限制（{$maxMB}MB）");
        }

        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mcp_import_' . bin2hex(random_bytes(8)) . '_' . preg_replace('/[^\w.\-]/', '_', $fileName);
        if (@file_put_contents($tmp, $content) === false) {
            McpError::throw(McpError::OPERATION_FAILED, '临时文件写入失败');
        }
        return $tmp;
    }

    /**
     * 导入二进制文档（PDF / Word）
     */
    private function importBinary(array $params, string $type): array
    {
        $ctx = $this->resolveContext($params);

        $fileName = trim((string) ($params['file_name'] ?? ''));
        $base64 = trim((string) ($params['file_base64'] ?? ''));

        if ($base64 === '') {
            McpError::throw(McpError::INVALID_PARAMS, '必须提供 file_base64 参数（Base64 编码的文件内容）');
        }
        if ($fileName === '') {
            McpError::throw(McpError::INVALID_PARAMS, '必须提供 file_name 参数（包含扩展名）');
        }

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($type === 'pdf' && $ext !== 'pdf') {
            McpError::throw(McpError::INVALID_PARAMS, 'import_pdf 只接受 .pdf 文件');
        }
        if ($type === 'word' && !in_array($ext, ['docx', 'doc'], true)) {
            McpError::throw(McpError::INVALID_PARAMS, 'import_word 只接受 .docx / .doc 文件');
        }

        $tmpPath = $this->writeTempFile($base64, $fileName);

        try {
            if ($type === 'pdf') {
                $pageId = DocumentImportService::importPdf(
                    $ctx['uid'],
                    $ctx['username'],
                    $ctx['itemId'],
                    $ctx['catId'],
                    $fileName,
                    $tmpPath
                );
            } else {
                $pageId = DocumentImportService::importWord(
                    $ctx['uid'],
                    $ctx['username'],
                    $ctx['itemId'],
                    $ctx['catId'],
                    $fileName,
                    $tmpPath
                );
            }
        } finally {
            @unlink($tmpPath);
        }

        if (!$pageId) {
            McpError::throw(McpError::OPERATION_FAILED, '导入失败');
        }

        return $this->buildResult($ctx['itemId'], (int) $pageId, $fileName, $type);
    }

    /**
     * 导入 HTML（源码直接传字符串，或传 base64 文件）
     */
    private function importHtml(array $params): array
    {
        $ctx = $this->resolveContext($params);

        $html = (string) ($params['html'] ?? '');
        $fileName = trim((string) ($params['file_name'] ?? ''));
        $base64 = trim((string) ($params['file_base64'] ?? ''));
        $pageTitle = trim((string) ($params['page_title'] ?? ''));

        if ($html === '' && $base64 !== '') {
            $tmpPath = $this->writeTempFile($base64, $fileName !== '' ? $fileName : 'page.html');
            try {
                $html = (string) file_get_contents($tmpPath);
            } finally {
                @unlink($tmpPath);
            }
        }

        if (trim($html) === '') {
            McpError::throw(McpError::INVALID_PARAMS, '必须提供 html 或 file_base64 参数');
        }

        if ($pageTitle === '') {
            $pageTitle = $fileName !== ''
                ? pathinfo($fileName, PATHINFO_FILENAME)
                : 'HTML 页面';
        }

        $pageId = DocumentImportService::importHtml(
            $ctx['uid'],
            $ctx['username'],
            $ctx['itemId'],
            $ctx['catId'],
            $html,
            $pageTitle
        );

        if (!$pageId) {
            McpError::throw(McpError::OPERATION_FAILED, '导入失败');
        }

        return $this->buildResult($ctx['itemId'], (int) $pageId, $pageTitle, 'html');
    }

    /**
     * 导入 Markdown
     */
    private function importMarkdown(array $params): array
    {
        $ctx = $this->resolveContext($params);

        $content = (string) ($params['content'] ?? '');
        $fileName = trim((string) ($params['file_name'] ?? ''));
        $base64 = trim((string) ($params['file_base64'] ?? ''));
        $pageTitle = trim((string) ($params['page_title'] ?? ''));

        if ($content === '' && $base64 !== '') {
            $tmpPath = $this->writeTempFile($base64, $fileName !== '' ? $fileName : 'page.md');
            try {
                $content = (string) file_get_contents($tmpPath);
            } finally {
                @unlink($tmpPath);
            }
        }

        if ($pageTitle === '') {
            $pageTitle = $fileName !== '' ? pathinfo($fileName, PATHINFO_FILENAME) : '';
        }

        if (trim($content) === '' || $pageTitle === '') {
            McpError::throw(McpError::INVALID_PARAMS, '必须提供 content（或 file_base64）与 page_title（或 file_name）');
        }

        $pageId = DocumentImportService::importMarkdown(
            $ctx['uid'],
            $ctx['username'],
            $ctx['itemId'],
            $ctx['catId'],
            $content,
            $pageTitle
        );

        if (!$pageId) {
            McpError::throw(McpError::OPERATION_FAILED, '导入失败');
        }

        return $this->buildResult($ctx['itemId'], (int) $pageId, $pageTitle, 'markdown');
    }

    /**
     * 统一返回结构
     */
    private function buildResult(int $itemId, int $pageId, string $title, string $type): array
    {
        $result = [
            'page_id'    => $pageId,
            'item_id'    => $itemId,
            'page_title' => $title,
            'type'       => $type,
            'message'    => '导入成功',
        ];

        // Word 页面能否在线编辑取决于 OnlyOffice 是否已配置，这里提前告知调用方
        if ($type === 'word') {
            $onlyOfficeUrl = trim((string) \App\Model\Options::get('onlyoffice_url', ''));
            if ($onlyOfficeUrl === '') {
                $result['warning'] = '服务端尚未配置 OnlyOffice 文档服务器，该页面暂时只能下载，无法在线编辑';
            }
        }

        return $result;
    }
}
