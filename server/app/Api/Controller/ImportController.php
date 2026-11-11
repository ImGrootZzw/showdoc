<?php

namespace App\Api\Controller;

use App\Common\BaseController;
use App\Model\Item;
use App\Model\Page;
use App\Model\Catalog;
use App\Common\Helper\FileHelper;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

use const UPLOAD_ERR_OK;

class ImportController extends BaseController
{
    /**
     * 自动检测导入的文件类型从而选择不同的控制器方法
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function auto(Request $request, Response $response): Response
    {
        set_time_limit(100);
        ini_set('memory_limit', '600M');

        $user = [];
        if ($error = $this->requireLoginUser($request, $response, $user)) {
            return $error;
        }

        $uid = (int) ($user['uid'] ?? 0);
        $itemId = $this->getParam($request, 'item_id', 0);

        // 权限检查
        if ($itemId > 0) {
            if (!$this->checkItemEdit($uid, $itemId)) {
                return $this->error($response, 10302, '没有权限');
            }
            Item::deleteCache($itemId); // 清除项目缓存
        }


        // 获取上传的文件
        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            return $this->error($response, 10101, '请上传文件');
        }

        $file = $uploadedFiles['file'];
        $filename = $file->getClientFilename();

        // 检查文件扩展名（只允许 .zip、.json、.pdf 或 .html 文件）
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['zip', 'json', 'pdf', 'html', 'htm'], true)) {
            return $this->error($response, 10101, '只支持上传 ZIP、JSON、PDF 或 HTML 格式的文件');
        }

        $tail = $ext;

        if ($tail == 'pdf') {
            // PDF 文件处理：保存为附件并自动创建一个内嵌阅读页面
            return $this->importPdfPage($request, $response);
        }

        if ($tail == 'html' || $tail == 'htm') {
            // HTML 文件处理：HTML 源码保存为页面正文，前端用沙箱 iframe 渲染
            return $this->importHtml($request, $response);
        }

        if ($tail == 'zip') {
            // ZIP 文件处理
            $tempFile = sys_get_temp_dir() . '/' . FileHelper::getRandStr() . '.zip';
            $file->moveTo($tempFile);

            $zipArc = new \ZipArchive();
            $ret = $zipArc->open($tempFile, \ZipArchive::CREATE);
            if ($ret !== true) {
                @unlink($tempFile);
                return $this->error($response, 10101, 'ZIP 文件打开失败');
            }

            // 先尝试新的格式 info.json，如果不存在则尝试旧的格式 prefix_info.json
            $info = $zipArc->getFromName("info.json");
            if (!$info) {
                $info = $zipArc->getFromName("prefix_info.json");
            }

            // 如有 info.json 或 prefix_info.json 文件，则导入
            if ($info) {
                $infoArray = json_decode($info, true);
                if ($infoArray) {
                    $infoArray['item_id'] = $itemId;
                    $zipArc->close();
                    @unlink($tempFile);
                    return $this->importMarkdownInfo($request, $response, $infoArray, $itemId);
                }
            } else {
                // 如果没有，则尝试解压压缩包后，遍历 markdown 文件导入
                $zipArc->close();
                return $this->importFromReadingMDFile($request, $response, $tempFile, $filename, $itemId);
            }
        }

        if ($tail == 'json') {
            // JSON 文件处理
            $tempFile = sys_get_temp_dir() . '/' . FileHelper::getRandStr() . '.json';
            $file->moveTo($tempFile);
            $json = file_get_contents($tempFile);
            $jsonArray = json_decode($json, true);
            @unlink($tempFile);
            unset($json);

            // 检测 Swagger/OpenAPI 格式
            if (($jsonArray['swagger'] ?? false) || (($jsonArray['openapi'] ?? false) && ($jsonArray['info'] ?? false))) {
                // Swagger 格式，调用 ImportSwaggerController
                $swaggerController = new \App\Api\Controller\ImportSwaggerController();
                // 需要重新构建请求，将文件内容传递给 ImportSwaggerController
                // 由于 ImportSwaggerController 需要从上传的文件中读取，我们需要创建一个临时文件
                $tempFile = sys_get_temp_dir() . '/' . FileHelper::getRandStr() . '.json';
                file_put_contents($tempFile, json_encode($jsonArray, JSON_UNESCAPED_UNICODE));

                // 创建一个新的上传文件对象（模拟上传）
                $streamFactory = new \Slim\Psr7\Factory\StreamFactory();
                $uploadedFile = new UploadedFile(
                    $tempFile,
                    'swagger.json',
                    'application/json',
                    filesize($tempFile),
                    UPLOAD_ERR_OK,
                    false
                );

                // 创建新的请求对象，包含上传的文件
                $newRequest = $request->withUploadedFiles(['file' => $uploadedFile]);

                $result = $swaggerController->import($newRequest, $response);
                @unlink($tempFile);
                return $result;
            }

            // 检测 Postman 格式
            if (($jsonArray['id'] ?? false) || ($jsonArray['info'] ?? false)) {
                // Postman 格式，调用 ImportPostmanController
                $postmanController = new \App\Api\Controller\ImportPostmanController();
                // 需要重新构建请求，将文件内容传递给 ImportPostmanController
                $tempFile = sys_get_temp_dir() . '/' . FileHelper::getRandStr() . '.json';
                file_put_contents($tempFile, json_encode($jsonArray, JSON_UNESCAPED_UNICODE));

                // 创建一个新的上传文件对象（模拟上传）
                $uploadedFile = new UploadedFile(
                    $tempFile,
                    'postman.json',
                    'application/json',
                    filesize($tempFile),
                    UPLOAD_ERR_OK,
                    false
                );

                // 创建新的请求对象，包含上传的文件
                $newRequest = $request->withUploadedFiles(['file' => $uploadedFile]);

                $result = $postmanController->import($newRequest, $response);
                @unlink($tempFile);
                return $result;
            }
        }

        return $this->error($response, 10101, '不支持的文件格式');
    }

    /**
     * 导入 PDF：保存为附件，并自动创建一个内嵌阅读的页面
     *
     * 路由：/api/import/auto（由 auto 按扩展名分流）或 /api/import/importPdfPage
     * 参数：
     * - item_id：项目 ID（可选；不传则按 PDF 文件名自动新建项目）
     * - file：.pdf 文件
     *
     * 页面内容为 iframe 内嵌本地 PDF.js 阅读器（web/pdfjs），打开页面即可在线阅读
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function importPdfPage(Request $request, Response $response): Response
    {
        set_time_limit(300);
        ini_set('memory_limit', '600M');

        $user = [];
        if ($error = $this->requireLoginUser($request, $response, $user)) {
            return $error;
        }

        $uid = (int) ($user['uid'] ?? 0);
        $itemId = (int) $this->getParam($request, 'item_id', 0);
        $catId = (int) $this->getParam($request, 'cat_id', 0);

        // 获取上传的文件
        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            return $this->error($response, 10101, '请上传文件');
        }
        $file = $uploadedFiles['file'];
        $filename = (string) $file->getClientFilename();

        // 检查扩展名
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            return $this->error($response, 10101, '只支持上传 PDF 格式的文件');
        }

        // 页面标题 = PDF 文件名（去扩展名）
        $pageTitle = pathinfo($filename, PATHINFO_FILENAME);
        if ($pageTitle === '') {
            $pageTitle = 'PDF 文档';
        }

        // 权限检查；未指定项目时按 PDF 名自动新建项目
        if ($itemId > 0) {
            if (!$this->checkItemEdit($uid, $itemId)) {
                return $this->error($response, 10302, '没有权限');
            }
            Item::deleteCache($itemId);
        } else {
            $itemData = [
                'item_name'        => $pageTitle,
                'item_domain'      => '',
                'item_type'        => 1,
                'item_description' => '',
                'password'         => FileHelper::getRandStr(),
                'uid'              => $uid,
                'username'         => $user['username'] ?? '',
                'addtime'          => time(),
            ];
            $itemId = Item::add($itemData);
            if ($itemId <= 0) {
                return $this->error($response, 10500, '创建项目失败');
            }
        }

        // 保存 PDF 并创建内嵌阅读页面（与 MCP 导入共用同一套逻辑）
        $tmpPath = \App\Model\Attachment::getUploadedTmpPath($file);
        if (!$tmpPath || !is_file($tmpPath)) {
            return $this->error($response, 10101, 'PDF 文件读取失败');
        }

        $pageId = \App\Common\Service\DocumentImportService::importPdf(
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
        ]);
    }

    /**
     * 导入 HTML 文件：将 HTML 源码保存为页面正文
     *
     * 路由：/api/import/importHtml（或 /api/import/auto 按扩展名分流）
     * 参数：
     * - item_id：项目 ID（可选；不传则按 HTML 文件名自动新建项目）
     * - cat_id：目标目录 ID（可选，默认根目录）
     * - file：.html / .htm 文件
     *
     * 页面正文保存完整的 HTML 源码，并通过 page.ext_info 标记
     * {"page_type":"html"}，前端据此使用沙箱 iframe 渲染；
     * 后续编辑走页面保存接口，会自动生成历史版本。
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function importHtml(Request $request, Response $response): Response
    {
        set_time_limit(300);
        ini_set('memory_limit', '600M');

        $user = [];
        if ($error = $this->requireLoginUser($request, $response, $user)) {
            return $error;
        }

        $uid    = (int) ($user['uid'] ?? 0);
        $itemId = (int) $this->getParam($request, 'item_id', 0);
        $catId  = (int) $this->getParam($request, 'cat_id', 0);

        // 获取上传的文件
        $uploadedFiles = $request->getUploadedFiles();
        if (empty($uploadedFiles['file'])) {
            return $this->error($response, 10101, '请上传文件');
        }
        $file = $uploadedFiles['file'];
        $filename = (string) $file->getClientFilename();

        // 检查扩展名
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'htm'], true)) {
            return $this->error($response, 10101, '只支持上传 HTML 格式的文件');
        }

        // 限制文件大小，避免超大 HTML 拖垮页面渲染
        $maxSize = 10 * 1024 * 1024;
        if ((int) $file->getSize() > $maxSize) {
            return $this->error($response, 10101, 'HTML 文件不能超过 10MB');
        }

        // 页面标题 = HTML 文件名（去扩展名）
        $pageTitle = pathinfo($filename, PATHINFO_FILENAME);
        if ($pageTitle === '') {
            $pageTitle = 'HTML 页面';
        }

        // 权限检查；未指定项目时按 HTML 文件名自动新建项目
        if ($itemId > 0) {
            if (!$this->checkItemEdit($uid, $itemId)) {
                return $this->error($response, 10302, '没有权限');
            }
            Item::deleteCache($itemId);
        } else {
            $itemData = [
                'item_name'        => $pageTitle,
                'item_domain'      => '',
                'item_type'        => 1,
                'item_description' => '',
                'password'         => FileHelper::getRandStr(),
                'uid'              => $uid,
                'username'         => $user['username'] ?? '',
                'addtime'          => time(),
            ];
            $itemId = Item::add($itemData);
            if ($itemId <= 0) {
                return $this->error($response, 10500, '创建项目失败');
            }
        }

        // 目标目录路径（updateByTitle 按路径创建/匹配目录）
        $catName = '';
        if ($catId > 0) {
            $cat = Catalog::findByIdAndItemId($catId, $itemId);
            if (!$cat) {
                return $this->error($response, 10101, '目标目录不存在');
            }
            $catName = Catalog::getCatPath($catId);
        }

        // 读取 HTML 源码
        $tmpPath = \App\Model\Attachment::getUploadedTmpPath($file);
        if (!$tmpPath || !is_file($tmpPath)) {
            return $this->error($response, 10101, 'HTML 文件读取失败');
        }
        $html = (string) file_get_contents($tmpPath);
        if (trim($html) === '') {
            return $this->error($response, 10101, 'HTML 文件内容为空');
        }

        // 与 MCP 导入共用同一套落库逻辑（BOM 清理、编码转换、ext_info 标记）
        $pageId = \App\Common\Service\DocumentImportService::importHtml(
            $uid,
            (string) ($user['username'] ?? ''),
            (int) $itemId,
            $catId,
            $html,
            $pageTitle
        );
        if (!$pageId) {
            return $this->error($response, 10500, '创建页面失败');
        }

        return $this->success($response, [
            'item_id' => $itemId,
            'page_id' => $pageId,
        ]);
    }

    /**
     * 导入 Markdown 压缩包（根据压缩包内的 info 文件导入）
     *
     * @param Request $request
     * @param Response $response
     * @param array|null $infoArray 信息数组（可选）
     * @param int $itemId 项目 ID
     * @return Response
     */
    public function importMarkdownInfo(Request $request, Response $response, ?array $infoArray = null, int $itemId = 0): Response
    {
        set_time_limit(100);
        ini_set('memory_limit', '200M');

        $user = [];
        if ($error = $this->requireLoginUser($request, $response, $user)) {
            return $error;
        }

        $uid = (int) ($user['uid'] ?? 0);

        // 如果没有传入 infoArray，则从上传的文件中读取
        if (!$infoArray) {
            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles['file'])) {
                return $this->error($response, 10101, '请上传文件');
            }

            $file = $uploadedFiles['file'];
            $tempFile = sys_get_temp_dir() . '/' . FileHelper::getRandStr() . '.zip';
            $file->moveTo($tempFile);

            $zipArc = new \ZipArchive();
            $ret = $zipArc->open($tempFile, \ZipArchive::CREATE);
            if ($ret !== true) {
                @unlink($tempFile);
                return $this->error($response, 10101, 'ZIP 文件打开失败');
            }

            // 先尝试新的格式 info.json，如果不存在则尝试旧的格式 prefix_info.json
            $info = $zipArc->getFromName("info.json");
            if (!$info) {
                $info = $zipArc->getFromName("prefix_info.json");
            }
            $zipArc->close();
            @unlink($tempFile);

            if (!$info) {
                return $this->error($response, 10101, '未找到 info.json 文件');
            }

            $infoArray = json_decode($info, true);
            unset($info);
        }

        if ($infoArray) {
            $infoArray['item_id'] = $itemId;
            $json = json_encode($infoArray, JSON_UNESCAPED_UNICODE);
            $result = Item::import($json, $uid, $itemId);
            if ($result) {
                return $this->success($response, ['item_id' => $result]);
            }
        }

        return $this->error($response, 10101, '导入失败');
    }

    /**
     * 从 Markdown 文件导入（遍历目录）
     *
     * @param Request $request
     * @param Response $response
     * @param string $file ZIP 文件路径
     * @param string $filename 文件名
     * @param int $itemId 项目 ID
     * @return Response
     */
    public function importFromReadingMDFile(Request $request, Response $response, string $file, string $filename, int $itemId): Response
    {
        $user = [];
        if ($error = $this->requireLoginUser($request, $response, $user)) {
            return $error;
        }

        $uid = (int) ($user['uid'] ?? 0);

        // 如果项目 id 不存在，则新建一个项目
        if ($itemId <= 0) {
            $itemData = [
                'item_name'        => str_replace('.zip', '', $filename),
                'item_domain'      => '',
                'item_type'        => 1,
                'item_description' => '',
                'password'         => FileHelper::getRandStr(),
                'uid'              => $uid,
                'username'          => $user['username'] ?? '',
                'addtime'           => time(),
            ];
            $itemId = Item::add($itemData);
            if ($itemId <= 0) {
                return $this->error($response, 10500, '创建项目失败');
            }
        }

        $zipArc = new \ZipArchive();
        $zipArc->open($file, \ZipArchive::CREATE);

        // 处理中文名乱码问题
        $fileNum = $zipArc->numFiles;
        for ($i = 0; $i < $fileNum; $i++) {
            $statInfo = $zipArc->statIndex($i, \ZipArchive::FL_ENC_RAW);
            $currentEncode = mb_detect_encoding($statInfo['name'], ['ASCII', 'GB2312', 'GBK', 'BIG5', 'UTF-8']);
            $statInfo['name'] = mb_convert_encoding($statInfo['name'], 'UTF-8', $currentEncode);
            $zipArc->renameIndex($i, $statInfo['name']);
        }
        $zipArc->close();
        $zipArc->open($file, \ZipArchive::CREATE);

        // Zip Slip 防护：检查每个条目路径，拒绝含 .. 或以 / 开头的条目
        for ($i = 0; $i < $zipArc->numFiles; $i++) {
            $entry = $zipArc->getNameIndex($i);
            if (strpos($entry, '..') !== false || (isset($entry[0]) && $entry[0] === '/')) {
                $zipArc->close();
                @unlink($file);
                return $this->error($response, 10101, 'ZIP 文件包含不安全的路径');
            }
        }

        // 直接遍历 ZIP 条目导入，不依赖文件系统解压
        // （部分平台解压非 ASCII 目录名时会丢失目录结构，导致分类丢失）
        for ($i = 0; $i < $zipArc->numFiles; $i++) {
            $entry = $zipArc->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            // 统一分隔符，兼容使用反斜杠作路径分隔符的 ZIP
            $entry = str_replace('\\', '/', $entry);
            // 跳过目录条目
            if (substr($entry, -1) === '/') {
                continue;
            }
            $baseName = basename($entry);
            // 只处理 .md 文件（自动跳过 info.json/prefix_info.json）
            if (substr($baseName, -3) !== '.md') {
                continue;
            }
            $pageTitle   = str_replace('.md', '', $baseName);
            $pageContent = $zipArc->getFromIndex($i);
            if ($pageContent === false) {
                continue;
            }
            // 分类名为条目所在的相对目录（支持多级目录，saveCatPath 会逐级创建）
            $catName = dirname($entry);
            if ($catName === '.' || $catName === '/') {
                $catName = '';
            }
            $catName = trim($catName, '/ ');
            Page::updateByTitle(
                $itemId,
                $pageTitle,
                $pageContent,
                $catName,
                99,
                $uid,
                $user['username'] ?? ''
            );
        }
        $zipArc->close();
        @unlink($file);

        return $this->success($response, []);
    }

    /**
     * 直接上传 Markdown 文件导入为页面
     *
     * 路由：/api/import/importMd
     * 参数：
     * - item_id：项目 ID（必填）
     * - cat_id：目标目录 ID（可选，默认 0 根目录）
     * - file：一个或多个 .md / .markdown 文件（支持多选）
     *
     * 说明：同名页面（同目录下同标题）会被覆盖更新，否则新建页面
     *
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function importMd(Request $request, Response $response): Response
    {
        set_time_limit(300);
        ini_set('memory_limit', '600M');

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

        // 目标目录路径（updateByTitle 按路径创建/匹配目录）
        $catName = '';
        if ($catId > 0) {
            $cat = \App\Model\Catalog::findByIdAndItemId($catId, $itemId);
            if (!$cat) {
                return $this->error($response, 10101, '目标目录不存在');
            }
            $catName = \App\Model\Catalog::getCatPath($catId);
        }

        // 获取上传的文件（支持单文件与多文件 file[]）
        // 注意：不使用 $request->getUploadedFiles()，因为当前 Slim 版本对同名多文件字段的解析存在覆盖问题（只保留最后一个）
        if (empty($_FILES['file'])) {
            return $this->error($response, 10101, '请选择要上传的 Markdown 文件');
        }

        $files = [];
        if (is_array($_FILES['file']['name'] ?? null)) {
            $count = count($_FILES['file']['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = [
                    'name'     => (string) ($_FILES['file']['name'][$i] ?? ''),
                    'tmp_name' => (string) ($_FILES['file']['tmp_name'][$i] ?? ''),
                    'type'     => (string) ($_FILES['file']['type'][$i] ?? ''),
                    'size'     => (int) ($_FILES['file']['size'][$i] ?? 0),
                    'error'    => (int) ($_FILES['file']['error'][$i] ?? UPLOAD_ERR_OK),
                ];
            }
        } else {
            $files[] = [
                'name'     => (string) ($_FILES['file']['name'] ?? ''),
                'tmp_name' => (string) ($_FILES['file']['tmp_name'] ?? ''),
                'type'     => (string) ($_FILES['file']['type'] ?? ''),
                'size'     => (int) ($_FILES['file']['size'] ?? 0),
                'error'    => (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_OK),
            ];
        }

        $successCount = 0;
        $failedList   = [];

        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                continue;
            }

            $filename = (string) $file['name'];
            $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['md', 'markdown'], true)) {
                $failedList[] = $filename . '（不支持的格式）';
                continue;
            }

            $pageTitle = (string) pathinfo($filename, PATHINFO_FILENAME);
            if ($pageTitle === '') {
                $failedList[] = $filename . '（文件名无效）';
                continue;
            }

            $pageContent = (string) file_get_contents((string) $file['tmp_name']);

            $pageId = Page::updateByTitle(
                $itemId,
                $pageTitle,
                $pageContent,
                $catName,
                99,
                $uid,
                (string) ($user['username'] ?? '')
            );

            if ($pageId) {
                // 建立搜索索引（更新路径已建索引，新增路径不建，这里统一补上）
                \App\Model\DocSearchIndex::indexPage($pageId);
                $successCount++;
            } else {
                $failedList[] = $filename;
            }
        }

        Item::deleteCache($itemId);

        if ($successCount > 0) {
            return $this->success($response, [
                'success' => $successCount,
                'failed'  => $failedList,
            ]);
        }

        return $this->error($response, 10101, $failedList ? implode('；', $failedList) : '导入失败');
    }
}
