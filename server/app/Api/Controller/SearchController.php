<?php

namespace App\Api\Controller;

use App\Common\BaseController;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * 全局搜索控制器（开源版新增）
 *
 * 路由：/api/search/index  全局模糊搜索所有有权限访问的项目文档内容
 * 路由：/api/search/reindex 重建文档搜索索引（仅系统管理员）
 */
class SearchController extends BaseController
{
    /**
     * 全局模糊搜索文档内容
     *
     * 参数：
     * - keyword：搜索关键词（必填）
     * - limit：返回条数上限（可选，默认 50）
     */
    public function index(Request $request, Response $response): Response
    {
        // 未登录也可搜索（仅能搜到公开项目），登录后附带私有项目权限
        $loginUser = [];
        $this->requireLoginUser($request, $response, $loginUser, false);
        $uid = (int) ($loginUser['uid'] ?? 0);

        $keyword = trim((string) $this->getParam($request, 'keyword', ''));
        $limit   = (int) $this->getParam($request, 'limit', 50);

        if ($keyword === '') {
            return $this->error($response, 10101, '请输入搜索关键词');
        }

        $pages = \App\Model\DocSearchIndex::search($keyword, $uid, $limit);

        // 按项目分组返回
        $items = [];
        foreach ($pages as $page) {
            $itemId = $page['item_id'];
            if (!isset($items[$itemId])) {
                $items[$itemId] = [
                    'item_id'   => $itemId,
                    'item_name' => $page['item_name'],
                    'pages'     => [],
                ];
            }
            $items[$itemId]['pages'][] = $page;
        }

        return $this->success($response, [
            'keyword' => $keyword,
            'total'   => count($pages),
            'items'   => array_values($items),
        ]);
    }

    /**
     * 重建文档搜索索引（仅系统管理员）
     */
    public function reindex(Request $request, Response $response): Response
    {
        $loginUser = [];
        if ($error = $this->requireLoginUser($request, $response, $loginUser)) {
            return $error;
        }
        if ((int) ($loginUser['groupid'] ?? 0) !== 1) {
            return $this->error($response, 10303, '没有权限');
        }

        $stat = \App\Model\DocSearchIndex::rebuildAll();
        return $this->success($response, $stat);
    }
}
