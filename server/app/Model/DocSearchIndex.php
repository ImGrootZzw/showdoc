<?php

namespace App\Model;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * 文档搜索索引模型（开源版新增）
 *
 * 说明：
 * - 建表语句见 Common/Database/Upgrade.php 中的 doc_search_index 建表逻辑
 * - 索引在页面新增/编辑/删除/恢复时自动同步（挂钩 Page/Item 模型）
 * - 全量重建：php server/console/reindex.php
 */
class DocSearchIndex
{
    /**
     * 将 markdown 内容清洗为纯文本（用于索引与搜索片段展示）
     *
     * @param string $content 原始内容（可能为压缩存储）
     * @return string 纯文本
     */
    public static function toPlainText(string $content): string
    {
        if ($content === '') {
            return '';
        }

        // 兼容压缩存储的历史数据
        $decoded = \App\Common\Helper\ContentCodec::decompress($content);
        if ($decoded !== '' && $decoded !== $content) {
            $content = $decoded;
        }

        // 去掉代码块围栏
        $content = preg_replace('/```[\s\S]*?```/', ' ', $content) ?? $content;
        // 去掉行内代码/标题/加粗等 markdown 标记
        $content = str_replace(['`', '**', '__', '~~', '```'], ' ', $content);
        $content = preg_replace('/^#{1,6}\s*/m', ' ', $content) ?? $content;
        $content = preg_replace('/^\s*[-*+]\s+/m', ' ', $content) ?? $content;
        $content = preg_replace('/^\s*>\s*/m', ' ', $content) ?? $content;
        // 去掉链接语法 [文字](url)，保留文字
        $content = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $content) ?? $content;
        // 去掉表格分隔线等
        $content = preg_replace('/^\s*\|?\s*:?-+:?\s*\|.*$/m', ' ', $content) ?? $content;
        // 图片/HTML 标记
        $content = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $content) ?? $content;
        $content = strip_tags($content);

        // 压缩空白
        $content = preg_replace('/[ \t]+/', ' ', $content) ?? $content;
        $content = preg_replace('/\n{2,}/', "\n", $content) ?? $content;
        return trim($content);
    }

    /**
     * 建立/更新单个页面的索引
     *
     * @param int $pageId 页面 ID
     * @return bool
     */
    public static function indexPage(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        try {
            $page = DB::table('page')
                ->where('page_id', $pageId)
                ->first();

            if (!$page || (int) ($page->is_del ?? 0) === 1) {
                return self::removePage($pageId);
            }

            $itemId   = (int) ($page->item_id ?? 0);
            $itemName = '';
            if ($itemId > 0) {
                $item = DB::table('item')->where('item_id', $itemId)->first();
                $itemName = (string) ($item->item_name ?? '');
            }

            $content = self::toPlainText((string) ($page->page_content ?? ''));

            DB::table('doc_search_index')
                ->updateOrInsert(
                    ['page_id' => $pageId],
                    [
                        'item_id'            => $itemId,
                        'item_name'          => $itemName,
                        'page_title'         => (string) ($page->page_title ?? ''),
                        'page_content_plain' => $content,
                        'addtime'            => (int) ($page->addtime ?? time()),
                    ]
                );
            return true;
        } catch (\Throwable $e) {
            error_log('DocSearchIndex::indexPage error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 移除单个页面的索引
     *
     * @param int $pageId 页面 ID
     * @return bool
     */
    public static function removePage(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        try {
            DB::table('doc_search_index')->where('page_id', $pageId)->delete();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 移除某项目下的全部索引
     *
     * @param int $itemId 项目 ID
     * @return int 删除数量
     */
    public static function removeByItem(int $itemId): int
    {
        if ($itemId <= 0) {
            return 0;
        }

        try {
            return DB::table('doc_search_index')->where('item_id', $itemId)->delete();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 全量重建索引
     *
     * @return array ['total' => 扫描页数, 'indexed' => 成功索引数, 'failed' => 失败数]
     */
    public static function rebuildAll(): array
    {
        $total   = 0;
        $indexed = 0;
        $failed  = 0;

        try {
            DB::table('doc_search_index')->delete();

            $pages = DB::table('page')
                ->select(['page_id'])
                ->get()
                ->all();

            foreach ($pages as $page) {
                $total++;
                if (self::indexPage((int) $page->page_id)) {
                    $indexed++;
                } else {
                    $failed++;
                }
            }
        } catch (\Throwable $e) {
            error_log('DocSearchIndex::rebuildAll error: ' . $e->getMessage());
        }

        return [
            'total'   => $total,
            'indexed' => $indexed,
            'failed'  => $failed,
        ];
    }

    /**
     * 重建某项目下的全部索引（用于项目恢复等场景）
     *
     * @param int $itemId 项目 ID
     * @return array ['total' => 扫描页数, 'indexed' => 成功索引数, 'failed' => 失败数]
     */
    public static function rebuildItem(int $itemId): array
    {
        $total   = 0;
        $indexed = 0;
        $failed  = 0;

        if ($itemId <= 0) {
            return ['total' => 0, 'indexed' => 0, 'failed' => 0];
        }

        try {
            $pages = DB::table('page')
                ->where('item_id', $itemId)
                ->select(['page_id'])
                ->get()
                ->all();

            foreach ($pages as $page) {
                $total++;
                if (self::indexPage((int) $page->page_id)) {
                    $indexed++;
                } else {
                    $failed++;
                }
            }
        } catch (\Throwable $e) {
            error_log('DocSearchIndex::rebuildItem error: ' . $e->getMessage());
        }

        return [
            'total'   => $total,
            'indexed' => $indexed,
            'failed'  => $failed,
        ];
    }

    /**
     * 全局模糊搜索文档内容（跨所有有权限访问的项目）
     *
     * 权限规则（与 checkItemVisit 一致）：
     * - 公开项目（无密码）：所有人可见
     * - 私有项目（有密码）：项目创建者/成员/团队可见；其他用户需携带正确的 _item_pwd
     *
     * @param string $keyword 搜索关键词
     * @param int    $uid     当前用户 ID（未登录为 0）
     * @param int    $limit   返回条数上限
     * @return array 搜索结果列表
     */
    public static function search(string $keyword, int $uid = 0, int $limit = 50): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return [];
        }
        $limit = min(max($limit, 1), 200);

        $uid = (int) $uid;
        try {
            $items = DB::table('item')
                ->where('is_del', 0)
                ->get()
                ->all();

            $publicItemIds = [];
            $privateItems  = [];
            foreach ($items as $item) {
                if (empty($item->password)) {
                    $publicItemIds[] = (int) $item->item_id;
                } else {
                    $privateItems[] = $item;
                }
            }

            // 用户有权限的私有项目
            $allowedPrivateIds = [];
            if ($uid > 0) {
                $myItems = DB::table('item')
                    ->where('is_del', 0)
                    ->where(function ($q) use ($uid) {
                        $q->where('uid', $uid);
                    })
                    ->pluck('item_id')
                    ->all();
                $allowedPrivateIds = array_map('intval', $myItems);

                $memberItemIds = DB::table('item_member')
                    ->where('uid', $uid)
                    ->pluck('item_id')
                    ->all();
                $allowedPrivateIds = array_merge($allowedPrivateIds, array_map('intval', $memberItemIds));

                $teamItemIds = DB::table('team_item_member')
                    ->where('member_uid', $uid)
                    ->pluck('item_id')
                    ->all();
                $allowedPrivateIds = array_merge($allowedPrivateIds, array_map('intval', $teamItemIds));

                // 系统管理员可见全部
                $user = DB::table('user')->where('uid', $uid)->first();
                if ($user && (int) ($user->groupid ?? 0) === 1) {
                    $allowedPrivateIds = array_merge($allowedPrivateIds, array_map('intval', array_column($privateItems, 'item_id')));
                }
            }

            // 私有项目密码匹配（_item_pwd 参数，与旧版一致）
            $itemPwd = trim((string) ($_REQUEST['_item_pwd'] ?? ''));
            if ($itemPwd !== '') {
                foreach ($privateItems as $item) {
                    if ((string) $item->password === $itemPwd) {
                        $allowedPrivateIds[] = (int) $item->item_id;
                    }
                }
            }

            $allowedPrivateIds = array_values(array_unique(array_filter($allowedPrivateIds)));
            $searchableItemIds = array_merge($publicItemIds, $allowedPrivateIds);
            $searchableItemIds = array_values(array_unique($searchableItemIds));

            if (empty($searchableItemIds)) {
                return [];
            }

            $like = '%' . $keyword . '%';
            $rows = DB::table('doc_search_index')
                ->whereIn('item_id', $searchableItemIds)
                ->where(function ($q) use ($like, $keyword) {
                    $q->where('page_title', 'like', $like)
                        ->orWhere('page_content_plain', 'like', $like);
                })
                ->limit($limit)
                ->get()
                ->all();

            $result = [];
            // item_id -> item_domain 映射，供前端拼接跳转链接
            $domainMap = [];
            foreach ($items as $item) {
                $domainMap[(int) $item->item_id] = (string) ($item->item_domain ?? '');
            }
            foreach ($rows as $row) {
                $titleHit   = stripos((string) $row->page_title, $keyword) !== false;
                $content    = (string) ($row->page_content_plain ?? '');
                $contentPos = mb_stripos($content, $keyword);

                $searchContent = '';
                if ($contentPos !== false) {
                    $len   = mb_strlen($keyword);
                    $start = $contentPos > 100 ? $contentPos - 100 : 0;
                    $searchContent = '...' . mb_substr($content, $start, $len + 200) . '...';
                } elseif (!$titleHit) {
                    // 理论不会走到这里，兜底展示内容开头
                    $searchContent = mb_substr($content, 0, 200);
                }

                $result[] = [
                    'page_id'        => (int) $row->page_id,
                    'item_id'        => (int) $row->item_id,
                    'item_domain'    => $domainMap[(int) $row->item_id] ?? '',
                    'item_name'      => (string) $row->item_name,
                    'page_title'     => (string) $row->page_title,
                    'search_content' => $searchContent,
                    'title_hit'      => $titleHit ? 1 : 0,
                    'addtime'        => (int) $row->addtime,
                ];
            }

            // 排序：标题命中优先，其次按时间倒序
            usort($result, function ($a, $b) {
                if ($a['title_hit'] !== $b['title_hit']) {
                    return $b['title_hit'] - $a['title_hit'];
                }
                return $b['addtime'] - $a['addtime'];
            });

            return $result;
        } catch (\Throwable $e) {
            error_log('DocSearchIndex::search error: ' . $e->getMessage());
            return [];
        }
    }
}
