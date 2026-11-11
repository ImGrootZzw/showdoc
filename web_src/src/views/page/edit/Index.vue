<template>
  <div class="page-edit-page"></div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import EditPageModal from '@/views/modals/page/EditPageModal/index'
import HtmlEditModal from '@/views/modals/page/HtmlEditModal/index'
import request from '@/utils/request'
import { isHtmlPage } from '@/models/page'
import { useAppStore } from '@/store'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()

// 从路由参数获取信息（使用字符串避免大整数精度丢失）
const itemId = route.params.item_id as string
const pageId = route.params.page_id as string
const catId = (route.query.cat_id as string) || ''

// 判断是否为 HTML 页面（HTML 页面走源码编辑器）
const checkIsHtmlPage = async (): Promise<boolean> => {
  if (!pageId) return false
  try {
    const data = await request(
      '/api/page/info',
      { page_id: pageId },
      'post',
      false
    )
    return data.error_code === 0 && isHtmlPage(data.data)
  } catch (error) {
    return false
  }
}

onMounted(async () => {
  const htmlPage = await checkIsHtmlPage()

  // 打开编辑弹窗
  const result = htmlPage
    ? await HtmlEditModal({
        itemId: Number(itemId),
        editPageId: Number(pageId),
        catId: Number(catId) || 0
      })
    : await EditPageModal({
        itemId,
        editPageId: pageId,
        catId
      })

  // 根据结果处理
  if (result && pageId) {
    // 编辑模式：刷新当前页面
    window.location.reload()
  } else if (result && !pageId) {
    // 新建模式：返回上一页（页面ID在弹窗内已更新）
    router.back()
  } else {
    // 取消：返回上一页
    router.back()
  }
})
</script>

<style lang="scss" scoped>
.page-edit-page {
  min-height: 100vh;
  background-color: var(--color-bg-secondary);
}
</style>
