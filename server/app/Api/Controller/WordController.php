<?php

namespace App\Api\Controller;

use App\Common\BaseController;
use App\Common\Helper\JwtHelper;
use App\Common\Helper\UrlHelper;
use App\Model\Catalog;
use App\Model\Item;
use App\Model\Options;
use App\Model\Page;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Word 文档（.docx）编辑相关接口
 *
 * 设计说明：
 * - 文档本体以文件形式存放在 server/data/word/ 下（不在静态目录中，无法被直接下载）
 * - 页面正文（page.page_content）只保存文档元信息的 JSON，页面通过 ext_info.page_type = word 标记
 * - 编辑能力由独立的 OnlyOffice Document Server 提供，本控制器负责：
 *   1) 上传导入并创建页面
 *   2) 生成带 JWT 签名的编辑器配置
 *   3) 向文档服务器提供文档下载（签名 URL，脱离登录态）
 *   4) 接收文档服务器的保存回调并回写文件
 *   5) 供用户下载 / 导出当前 .docx
 */
class WordController extends BaseController
{
    /** 单个 Word 文档允许的最大体积 */
    private const MAX_FILE_SIZE = 50 * 1024 * 1024;

    // ============================================================
    // 配置
    // ============================================================

    /**
     * 读取 OnlyOffice 配置
     *
     * @return array{url:string, jwt_secret:string, enabled:bool}
     */
    private function getOnlyOfficeConfig(): array
    {
        $url = trim((string) Options::get('onlyoffice_url', ''));
        $secret = trim((string) Options::get('onlyoffice_jwt_secret', ''));

        return [
            'url'        => rtrim($url, '/'),
            'jwt_secret' => $secret,
            'enabled'    => $url !== '',
        ];
    }

    /**
     * 文档存储根目录：server/data/word
     */
    private function getStorageDir(): string
    {
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'word';
    }

    /**
     * 确保存储目录存在且可写
     */
    private function ensureStorageDir(): bool
    {
        $dir = $this->getStorageDir();
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }
        return is_writable($dir);
    }

    /**
     * 校验存储的相对路径，防止目录穿越
     */
    private function resolveSafePath(string $relativePath): ?string
    {
        $relativePath = str_replace(['..', '\\'], ['', '/'], $relativePath);
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') {
            return null;
        }

        $base = $this->getStorageDir();
        $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $realBase = realpath($base);
        $realFull = realpath($full);

        if ($realBase === false || $realFull === false) {
            return null;
        }
        // 必须位于存储目录内
        if (strpos($realFull, $realBase . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $realFull;
    }

    /**
     * 解析页面正文中的文档元信息
     */
    private function decodeMeta(string $pageContent): array
    {
        $json = htmlspecialchars_decode($pageContent, ENT_QUOTES);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 生成页面正文（元信息 JSON，按项目约定转义存储）
     */
    private function encodeMeta(array $meta): string
    {
        return htmlspecialchars(json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    }

    /**
     * 通过单页分享链接（unique_key）解析页面，并校验链接有效性
     *
     * @return int|null 有效时返回 page_id
     */
    private function resolvePageIdByUniqueKey(string $uniqueKey): ?int
    {
        if ($uniqueKey === '') {
            return null;
        }

        $singlePage = DB::table('single_page')->where('unique_key', $uniqueKey)->first();
        if (!$singlePage) {
            return null;
        }

        $expireTime = (int) ($singlePage->expire_time ?? 0);
        if ($expireTime > 0 && $expireTime < time()) {
            DB::table('single_page')->where('unique_key', $uniqueKey)->delete();
            return null;
        }

        return (int) ($singlePage->page_id ?? 0) ?: null;
    }

    /**
     * 文档内容指纹，用于 OnlyOffice 的 document.key
     * 文档变化后 key 必须变化，否则文档服务器会返回缓存版本
     */
    private function buildDocumentKey(int $pageId, array $meta): string
    {
        $stamp = (string) ($meta['mtime'] ?? 0) . '_' . (string) ($meta['size'] ?? 0);
        return substr(md5($pageId . '_' . $stamp), 0, 20);
    }

    /**
     * 生成访问令牌（供文档服务器下载 / 回调使用）
     */
    private function buildAccessToken(int $pageId, string $purpose, int $ttl = 86400): string
    {
        $secret = $this->getTokenSecret();
        return JwtHelper::signPayload([
            'page_id' => $pageId,
            'purpose' => $purpose,
        ], $secret, $ttl);
    }

    /**
     * 校验访问令牌
     */
    private function verifyAccessToken(string $token, int $pageId, string $purpose): bool
    {
        if ($token === '') {
            return false;
        }
        $data = JwtHelper::verifyPayload($token, $this->getTokenSecret());
        if (!$data) {
            return false;
        }
        return (int) ($data['page_id'] ?? 0) === $pageId
            && (string) ($data['purpose'] ?? '') === $purpose;
    }

    /**
     * 令牌签名密钥：优先复用系统的 login_secret_key，保证每套部署唯一
     */
    private function getTokenSecret(): string
    {
        $secret = (string) Options::get('login_secret_key', '');
        if ($secret === '') {
            $secret = 'showdoc-word-fallback-secret';
        }
        return $secret;
    }

    // ============================================================
    // 1. 导入 Word 文档
    // ============================================================

    /**
     * 上传 .docx 并创建（或覆盖）一个页面
     *
     * 路由：/api/word/import
     * 参数：item_id、cat_id（可选）、file（.docx）
     */
    public function import(Request $request, Response $response): Response
    {
        set_time_limit(300);
        ini_set('memory_limit', '256M');

        $user = [];
        if ($error = $this->requireLoginUser($request, $response, $user)) {
            return $error;
        }

        $uid    = (int) ($user['uid'] ?? 0);
        $itemId = (int) $this->getParam($request, 'item_id', 0);
        $catId  = (int) $this->getParam($request, 'cat_id', 0);

        if ($itemId <= 0) {
            return $this->error($response, 10101, '项目不存在');
        }
        if (!$this->checkItemEdit($uid, $itemId)) {
            return $this->error($response, 10302, '没有权限');
        }

        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            return $this->error($response, 10101, '请上传 Word 文件');
        }

        $file = $uploadedFiles['file'];
        $filename = (string) $file->getClientFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['docx', 'doc'], true)) {
            return $this->error($response, 10101, '只支持上传 .docx / .doc 文件');
        }

        if ((int) $file->getSize() > self::MAX_FILE_SIZE) {
            return $this->error($response, 10101, 'Word 文件不能超过 50MB');
        }

        if (!$this->ensureStorageDir()) {
            return $this->error($response, 10500, '文档存储目录不可写，请检查 server/data 权限');
        }

        // 目录校验
        $catName = '';
        if ($catId > 0) {
            $cat = Catalog::findByIdAndItemId($catId, $itemId);
            if (!$cat) {
                return $this->error($response, 10101, '目标目录不存在');
            }
            $catName = Catalog::getCatPath($catId);
        }

        // 先把上传的临时文件落到本地临时路径，再交给统一的导入服务
        $tmpPath = \App\Model\Attachment::getUploadedTmpPath($file);
        if (!$tmpPath || !is_file($tmpPath)) {
            return $this->error($response, 10101, '文件读取失败');
        }

        // 与 MCP 导入共用同一套落库逻辑（文件存储、元信息、ext_info 标记）
        $pageId = \App\Common\Service\DocumentImportService::importWord(
            $uid,
            (string) ($user['username'] ?? ''),
            (int) $itemId,
            $catId,
            $filename,
            $tmpPath
        );

        if (!$pageId) {
            return $this->error($response, 10500, '创建页面失败');
        }

        return $this->success($response, [
            'item_id' => $itemId,
            'page_id' => $pageId,
            'name'    => $filename,
        ]);
    }

    // ============================================================
    // 2. 生成编辑器配置
    // ============================================================

    /**
     * 返回 OnlyOffice 编辑器配置
     *
     * 路由：/api/word/editorConfig
     * 参数：page_id
     */
    public function editorConfig(Request $request, Response $response): Response
    {
        $config = $this->getOnlyOfficeConfig();
        if (!$config['enabled']) {
            return $this->error($response, 10101, '尚未配置 OnlyOffice 文档服务器地址，请联系管理员');
        }

        $uniqueKey = (string) $this->getParam($request, 'unique_key', '');
        $pageId = (int) $this->getParam($request, 'page_id', 0);

        // 单页分享链接：通过 unique_key 解析页面，不需要登录
        $viaShareLink = false;
        if ($uniqueKey !== '') {
            $resolvedPageId = $this->resolvePageIdByUniqueKey($uniqueKey);
            if ($resolvedPageId === null) {
                return $this->error($response, 10101, '该分享链接已过期或不存在');
            }
            $pageId = $resolvedPageId;
            $viaShareLink = true;
        }

        if ($pageId <= 0) {
            return $this->error($response, 10101, '页面不存在');
        }

        $page = Page::findById($pageId);
        if (!$page) {
            return $this->error($response, 10101, '页面不存在');
        }

        $itemId = (int) ($page['item_id'] ?? 0);
        $loginUser = [];
        $this->requireLoginUser($request, $response, $loginUser, false);
        $uid = (int) ($loginUser['uid'] ?? 0);

        if (!$viaShareLink && !$this->checkItemVisit($uid, $itemId)) {
            return $this->error($response, 10101, '您没有访问权限');
        }

        $meta = $this->decodeMeta((string) ($page['page_content'] ?? ''));
        if (empty($meta['path'])) {
            return $this->error($response, 10101, '该页面的文档数据有误');
        }

        $filePath = $this->resolveSafePath((string) $meta['path']);
        if ($filePath === null || !is_file($filePath)) {
            return $this->error($response, 10101, '文档文件不存在');
        }

        // force_readonly：分享链接等场景强制只读，避免前端在签名后改配置导致 JWT 失效
        $forceReadonly = (int) $this->getParam($request, 'force_readonly', 0) === 1;
        $canEdit = !$forceReadonly && $this->checkItemEdit($uid, $itemId);
        $title   = (string) ($meta['title'] ?? $page['page_title'] ?? 'document');
        $ext     = (string) ($meta['ext'] ?? 'docx');

        $downloadUrl = UrlHelper::serverUrl('api/word/download', [
            'page_id' => $pageId,
            'token'   => $this->buildAccessToken($pageId, 'download'),
        ]);
        $callbackUrl = UrlHelper::serverUrl('api/word/callback', [
            'page_id' => $pageId,
            'token'   => $this->buildAccessToken($pageId, 'callback', 7 * 86400),
        ]);

        $editorConfig = [
            'documentType' => 'word',
            'document'     => [
                'fileType' => $ext,
                'key'      => $this->buildDocumentKey($pageId, $meta),
                'title'    => $title . '.' . $ext,
                'url'      => $downloadUrl,
                'permissions' => [
                    'edit'                 => $canEdit,
                    'download'             => true,
                    'print'                => true,
                    'review'               => $canEdit,
                    'comment'              => $canEdit,
                    'fillForms'            => $canEdit,
                    'modifyFilter'         => false,
                    'modifyContentControl' => $canEdit,
                ],
            ],
            'editorConfig' => [
                'mode'         => $canEdit ? 'edit' : 'view',
                'lang'         => 'zh',
                'callbackUrl'  => $callbackUrl,
                'user'         => [
                    'id'   => (string) $uid,
                    'name' => (string) ($loginUser['username'] ?? '访客'),
                ],
                'customization' => [
                    'autosave'          => true,
                    'forcesave'         => true,
                    'compactHeader'     => false,
                    'toolbarNoTabs'     => false,
                    'hideRightMenu'     => false,
                    'uiTheme'           => 'theme-classic-light',
                    'anonymous'         => ['request' => false],
                ],
            ],
        ];

        // 文档服务器开启 JWT 校验时，必须把整个配置签名
        if ($config['jwt_secret'] !== '') {
            $editorConfig['token'] = JwtHelper::encode($editorConfig, $config['jwt_secret']);
        }

        return $this->success($response, [
            'document_server_url' => $config['url'],
            'config'              => $editorConfig,
            'can_edit'            => $canEdit,
            'title'               => $title,
        ]);
    }

    // ============================================================
    // 3. 文档下载（供 OnlyOffice 文档服务器拉取）
    // ============================================================

    /**
     * 输出文档文件
     *
     * 路由：/api/word/download
     * 参数：page_id、token（签名令牌）；登录用户也可直接下载导出
     */
    public function download(Request $request, Response $response): Response
    {
        $pageId = (int) $this->getParam($request, 'page_id', 0);
        $token  = (string) $this->getParam($request, 'token', '');
        $uniqueKey = (string) $this->getParam($request, 'unique_key', '');

        // 单页分享链接：无需登录即可下载
        if ($uniqueKey !== '') {
            $resolvedPageId = $this->resolvePageIdByUniqueKey($uniqueKey);
            if ($resolvedPageId === null) {
                return $this->error($response, 10101, '该分享链接已过期或不存在');
            }
            $pageId = $resolvedPageId;
        }

        if ($pageId <= 0) {
            return $this->error($response, 10101, '页面不存在');
        }

        // 三条放行路径：签名令牌（文档服务器）/ 单页分享链接 / 登录且有访问权限（用户导出）
        $allowedByToken = $this->verifyAccessToken($token, $pageId, 'download');
        if (!$allowedByToken && $uniqueKey === '') {
            $loginUser = [];
            $this->requireLoginUser($request, $response, $loginUser, false);
            $uid = (int) ($loginUser['uid'] ?? 0);

            $page = Page::findById($pageId);
            if (!$page || !$this->checkItemVisit($uid, (int) ($page['item_id'] ?? 0))) {
                return $this->error($response, 10101, '没有访问权限');
            }
        }

        $page = Page::findById($pageId);
        if (!$page) {
            return $this->error($response, 10101, '页面不存在');
        }

        $meta = $this->decodeMeta((string) ($page['page_content'] ?? ''));
        if (empty($meta['path'])) {
            return $this->error($response, 10101, '该页面的文档数据有误');
        }

        $filePath = $this->resolveSafePath((string) $meta['path']);
        if ($filePath === null || !is_file($filePath)) {
            return $this->error($response, 10101, '文档文件不存在');
        }

        $ext = (string) ($meta['ext'] ?? 'docx');
        $title = (string) ($meta['title'] ?? $page['page_title'] ?? 'document');
        $filename = $title . '.' . $ext;

        return $this->outputFile($filePath, $filename, $ext, $response);
    }

    /**
     * 以流的方式输出文件
     */
    private function outputFile(string $filePath, string $filename, string $ext, Response $response): Response
    {
        $contentType = $ext === 'doc'
            ? 'application/msword'
            : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        $asciiFallback = (string) preg_replace('/[^\x20-\x7E]/', '', $filename);
        $asciiFallback = str_replace(['\\', '"'], '_', $asciiFallback);
        if ($asciiFallback === '' || $asciiFallback === '.' . $ext) {
            $asciiFallback = 'document.' . $ext;
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return $response->withStatus(500);
        }

        $stream = new \Slim\Psr7\Stream($handle);

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $asciiFallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename))
            ->withHeader('Content-Length', (string) filesize($filePath));
    }

    // ============================================================
    // 4. 保存回调（由 OnlyOffice 文档服务器调用）
    // ============================================================

    /**
     * OnlyOffice 保存回调
     *
     * 路由：/api/word/callback
     * 参数：page_id、token
     *
     * 回调 status 含义（仅列出用到的）：
     * - 2：文档已关闭，所有用户退出，可以保存
     * - 6：强制保存（forcesave）
     * 此时 body.url 指向文档服务器上生成的新文件，需要下载回来覆盖本地文件。
     */
    public function callback(Request $request, Response $response): Response
    {
        $pageId = (int) $this->getParam($request, 'page_id', 0);
        $token  = (string) $this->getParam($request, 'token', '');

        // 文档服务器无法携带登录态，只认签名令牌
        if ($pageId <= 0 || !$this->verifyAccessToken($token, $pageId, 'callback')) {
            return $this->json($response, ['error' => 1], 403);
        }

        // OnlyOffice 要求回调必须返回 {"error":0}，否则会反复重试
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $raw = (string) $request->getBody();
            $body = json_decode($raw, true) ?: [];
        }

        // 若配置了 JWT，校验请求签名
        $config = $this->getOnlyOfficeConfig();
        if ($config['jwt_secret'] !== '') {
            $authToken = '';
            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader !== '' && stripos($authHeader, 'Bearer ') === 0) {
                $authToken = trim(substr($authHeader, 7));
            }
            if ($authToken === '' && !empty($body['token'])) {
                $authToken = (string) $body['token'];
            }

            if ($authToken !== '') {
                $decoded = JwtHelper::decode($authToken, $config['jwt_secret']);
                if ($decoded === null) {
                    return $this->json($response, ['error' => 1], 403);
                }
            }
        }

        $status = (int) ($body['status'] ?? 0);

        // 2 = 可保存，6 = 强制保存
        if ($status !== 2 && $status !== 6) {
            return $this->json($response, ['error' => 0]);
        }

        $downloadUrl = (string) ($body['url'] ?? '');
        if ($downloadUrl === '') {
            return $this->json($response, ['error' => 0]);
        }

        $page = Page::findById($pageId);
        if (!$page) {
            return $this->json($response, ['error' => 0]);
        }

        $meta = $this->decodeMeta((string) ($page['page_content'] ?? ''));
        if (empty($meta['path'])) {
            return $this->json($response, ['error' => 0]);
        }

        $targetPath = $this->resolveSafePath((string) $meta['path']);
        if ($targetPath === null) {
            return $this->json($response, ['error' => 0]);
        }

        // 下载最新文件内容
        $newContent = $this->fetchRemoteFile($downloadUrl);
        if ($newContent === null || $newContent === '') {
            return $this->json($response, ['error' => 0]);
        }

        // 先写临时文件再原子替换，避免写入中断导致文档损坏
        $tmpPath = $targetPath . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmpPath, $newContent) === false) {
            return $this->json($response, ['error' => 0]);
        }
        if (!@rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            return $this->json($response, ['error' => 0]);
        }

        // 更新元信息（大小 / 修改时间），使 document.key 发生变化
        $meta['size']  = (int) filesize($targetPath);
        $meta['mtime'] = time();

        DB::table('page')->where('page_id', $pageId)->update([
            'page_content' => $this->encodeMeta($meta),
            'addtime'      => time(),
            'page_addtime' => time(),
        ]);

        Page::deleteCache($pageId);
        Item::deleteCache((int) ($page['item_id'] ?? 0));

        return $this->json($response, ['error' => 0]);
    }

    /**
     * 从文档服务器拉取文件内容（支持 http/https）
     */
    private function fetchRemoteFile(string $url): ?string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $data = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($data === false || $code >= 400) {
                return null;
            }
            return (string) $data;
        }

        $ctx = stream_context_create([
            'http' => ['timeout' => 60, 'follow_location' => 1],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        return $data === false ? null : (string) $data;
    }

    // ============================================================
    // 5. 状态探测
    // ============================================================

    /**
     * 检查 OnlyOffice 是否已配置可用
     *
     * 路由：/api/word/status
     */
    public function status(Request $request, Response $response): Response
    {
        $config = $this->getOnlyOfficeConfig();

        return $this->success($response, [
            'enabled' => $config['enabled'],
            'url'     => $config['url'],
        ]);
    }
}
