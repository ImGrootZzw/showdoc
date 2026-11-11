<template>
  <div class="excalidraw-item">
    <!-- 顶部导航 -->
    <ItemHeader v-if="!isFullscreen" :item-info="itemInfo">
      <template #right>
        <ExcalidrawHeaderRight
          :item-info="itemInfo"
          :page-id="pageId"
          :saving="saving"
          :last-saved-at="lastSavedAt"
          :is-fullscreen="isFullscreen"
          @save="handleSave"
          @export="handleExport"
          @toggle-fullscreen="toggleFullscreen"
        />
      </template>
    </ItemHeader>

    <!-- 画布区域 -->
    <div ref="canvasHostRef" class="canvas-host" :class="{ fullscreen: isFullscreen }">
      <!-- 加载中 -->
      <div v-if="loading" class="state-layer">
        <a-spin size="large" />
        <p class="state-text">{{ t('item.excalidraw_loading') }}</p>
        <p class="state-sub">{{ t('item.excalidraw_loading_hint') }}</p>
      </div>

      <!-- 加载失败 -->
      <div v-else-if="loadError" class="state-layer">
        <i class="far fa-triangle-exclamation state-icon"></i>
        <p class="state-text">{{ t('item.excalidraw_load_failed') }}</p>
        <p class="state-sub">{{ loadError }}</p>
        <a-button type="primary" @click="reload">{{ t('common.retry') }}</a-button>
      </div>

      <!-- Excalidraw 挂载点 -->
      <div ref="mountRef" class="excalidraw-mount"></div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import ItemHeader from '@/views/item/components/ItemHeader.vue'
import ExcalidrawHeaderRight from './HeaderRight.vue'
import request from '@/utils/request'
import { useAppStore } from '@/store'
import { unescapeHTML } from '@/models/page'
import { useExcalidrawLoader } from './utils/bundleLoader'

interface Props {
  itemInfo?: any
}

const props = withDefaults(defineProps<Props>(), {
  itemInfo: () => ({}),
})

const { t, locale } = useI18n()
const appStore = useAppStore()
const { load } = useExcalidrawLoader()

const canvasHostRef = ref<HTMLElement | null>(null)
const mountRef = ref<HTMLElement | null>(null)

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const lastSavedAt = ref<number>(0)
const isFullscreen = ref(false)

const pageId = ref(0)
const itemId = ref(0)

let bundle: any = null
let instance: any = null
let api: any = null
let pendingSaveTimer: number | null = null
let lastSerialized = ''
let isDestroying = false

// 是否有编辑权限（只读分享链接 / 无权限成员只能看）
// item_edit 由后端返回：1 可编辑，0 只读
const isEditingAllowed = computed(() => props.itemInfo?.item_edit == 1)

// ============================================
// 场景数据
// ============================================
const emptyScene = () => ({
  elements: [],
  appState: { viewBackgroundColor: '#ffffff' },
  files: {},
})

const parseScene = (raw: string) => {
  if (!raw || !raw.trim()) return emptyScene()
  try {
    // 页面正文入库时经过 htmlspecialchars，这里还原一次
    const data = JSON.parse(unescapeHTML(raw))
    if (!data || typeof data !== 'object') return emptyScene()
    return {
      elements: Array.isArray(data.elements) ? data.elements : [],
      appState: data.appState || {},
      files: data.files || {},
    }
  } catch (e) {
    console.warn('[excalidraw] 场景数据解析失败，按空图打开', e)
    return emptyScene()
  }
}

// ============================================
// 保存
// ============================================
const buildScenePayload = (): string => {
  if (!api || !bundle) return ''
  try {
    const elements = api.getSceneElements()
    const appState = api.getAppState()
    const files = api.getFiles?.() || {}
    const scene = {
      type: 'excalidraw',
      version: 2,
      source: 'showdoc',
      elements,
      appState: {
        // 只保留可复现的关键视图状态，避免把临时 UI 状态写入
        viewBackgroundColor: appState.viewBackgroundColor,
        gridSize: appState.gridSize,
        scrollX: appState.scrollX,
        scrollY: appState.scrollY,
        zoom: appState.zoom,
      },
      files,
    }
    return JSON.stringify(scene)
  } catch (e) {
    console.warn('[excalidraw] 序列化场景失败', e)
    return ''
  }
}

const handleSave = async (silent = false) => {
  if (!pageId.value || !api) return
  if (!isEditingAllowed.value) {
    if (!silent) message.warning(t('item.excalidraw_no_permission'))
    return
  }

  const payload = buildScenePayload()
  if (!payload || payload === lastSerialized) {
    if (!silent) message.success(t('item.excalidraw_nothing_to_save'))
    return
  }

  saving.value = true
  try {
    const result = await request(
      '/api/page/save',
      {
        page_id: pageId.value,
        item_id: itemId.value,
        page_title: props.itemInfo?.item_name || '',
        is_urlencode: 1,
        page_content: encodeURIComponent(payload),
        ext_info: JSON.stringify({ page_type: 'excalidraw' }),
      },
      'post',
      false
    )

    if (result.error_code === 0) {
      lastSerialized = payload
      lastSavedAt.value = Date.now()
      if (!silent) message.success(t('page.save_success'))
    } else if (!silent) {
      message.error(result.error_message || t('page.save_failed'))
    }
  } catch (e) {
    console.error('[excalidraw] 保存失败', e)
    if (!silent) message.error(t('page.save_failed'))
  } finally {
    saving.value = false
  }
}

// 内容变化后延迟自动保存（节流 2 秒）
const scheduleAutoSave = () => {
  if (!isEditingAllowed.value) return
  if (pendingSaveTimer) window.clearTimeout(pendingSaveTimer)
  pendingSaveTimer = window.setTimeout(() => {
    pendingSaveTimer = null
    handleSave(true)
  }, 2000)
}

// ============================================
// 挂载
// ============================================
const mountEditor = async () => {
  loading.value = true
  loadError.value = ''

  try {
    bundle = await load()
    if (isDestroying || !mountRef.value) return

    instance = bundle.mount(mountRef.value, {
      initialData: parseScene(rawScene),
      viewModeEnabled: !isEditingAllowed.value,
      theme: appStore.theme === 'dark' ? 'dark' : 'light',
      langCode: String(locale.value || '').startsWith('en') ? 'en' : 'zh-CN',
      name: props.itemInfo?.item_name || '',
      UIOptions: isEditingAllowed.value
        ? {}
        : { canvasActions: { export: { saveFileToDisk: true } } },
      onApi: (_api: any) => {
        api = _api
        // 首次记录基线，避免刚打开就触发自动保存
        lastSerialized = buildScenePayload()
      },
      onChange: () => {
        scheduleAutoSave()
      },
    })

    loading.value = false
  } catch (e: any) {
    console.error('[excalidraw] 加载失败', e)
    loadError.value = e?.message || String(e)
    loading.value = false
  }
}

// ============================================
// 导出
// ============================================
const handleExport = async (type: 'png' | 'svg' | 'json') => {
  if (!bundle || !api) return
  try {
    const elements = api.getSceneElements()
    const appState = api.getAppState()
    const files = api.getFiles?.() || {}
    const baseName = props.itemInfo?.item_name || 'excalidraw'

    if (type === 'json') {
      const payload = buildScenePayload()
      downloadBlob(
        new Blob([payload], { type: 'application/json' }),
        `${baseName}.excalidraw`
      )
      return
    }

    if (type === 'png') {
      const blob = await bundle.exportToBlob({
        elements,
        appState: { ...appState, exportWithDarkMode: appStore.theme === 'dark' },
        files,
        mimeType: bundle.MIME_TYPES.png,
        quality: 1,
      })
      downloadBlob(blob, `${baseName}.png`)
      return
    }

    const svg = await bundle.exportToSvg({
      elements,
      appState: { ...appState, exportWithDarkMode: appStore.theme === 'dark' },
      files,
    })
    const svgText = new XMLSerializer().serializeToString(svg)
    downloadBlob(new Blob([svgText], { type: 'image/svg+xml' }), `${baseName}.svg`)
  } catch (e) {
    console.error('[excalidraw] 导出失败', e)
    message.error(t('item.excalidraw_export_failed'))
  }
}

const downloadBlob = (blob: Blob, filename: string) => {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

// ============================================
// 全屏
// ============================================
const toggleFullscreen = () => {
  isFullscreen.value = !isFullscreen.value
  document.body.style.overflow = isFullscreen.value ? 'hidden' : ''
  // 让 Excalidraw 重新计算尺寸
  setTimeout(() => window.dispatchEvent(new Event('resize')), 50)
}

// ============================================
// 生命周期
// ============================================
let rawScene = ''

const loadPage = async () => {
  const targetPageId =
    props.itemInfo?.menu?.pages?.[0]?.page_id || props.itemInfo?.default_page_id
  if (!targetPageId) {
    loading.value = false
    loadError.value = t('item.excalidraw_no_page')
    return
  }

  try {
    const data = await request(
      '/api/page/info',
      { page_id: targetPageId },
      'post',
      false
    )
    if (data.error_code === 0 && data.data) {
      pageId.value = Number(data.data.page_id)
      itemId.value = Number(data.data.item_id)
      rawScene = data.data.page_content || ''
    } else {
      loadError.value = data.error_message || t('item.excalidraw_load_failed')
      loading.value = false
    }
  } catch (e: any) {
    console.error('[excalidraw] 获取页面失败', e)
    loadError.value = e?.message || t('item.excalidraw_load_failed')
    loading.value = false
  }
}

const reload = () => {
  destroyEditor()
  mountEditor()
}

const destroyEditor = () => {
  if (pendingSaveTimer) {
    window.clearTimeout(pendingSaveTimer)
    pendingSaveTimer = null
  }
  if (bundle && mountRef.value) {
    try {
      bundle.unmount(mountRef.value)
    } catch (e) {
      console.warn('[excalidraw] 卸载失败', e)
    }
  }
  instance = null
  api = null
}

// 页面隐藏/关闭前，尽量把未保存内容落盘
const handleBeforeUnload = () => {
  if (pendingSaveTimer) {
    handleSave(true)
  }
}

// 跟随 ShowDoc 主题切换（Excalidraw 通过 updateScene 动态切主题）
watch(
  () => appStore.theme,
  (theme) => {
    if (!api) return
    try {
      api.updateScene({ appState: { theme: theme === 'dark' ? 'dark' : 'light' } })
    } catch (e) {
      console.warn('[excalidraw] 切换主题失败', e)
    }
  }
)

onMounted(async () => {
  await loadPage()
  await mountEditor()
  window.addEventListener('beforeunload', handleBeforeUnload)
})

onBeforeUnmount(() => {
  isDestroying = true
  window.removeEventListener('beforeunload', handleBeforeUnload)
  if (pendingSaveTimer) {
    // 离开页面前保存一次（不等待结果）
    handleSave(true)
  }
  destroyEditor()
  if (isFullscreen.value) document.body.style.overflow = ''
})
</script>

<style scoped lang="scss">
.excalidraw-item {
  min-height: 100vh;
  background-color: var(--color-bg-secondary);
  display: flex;
  flex-direction: column;
}

.canvas-host {
  position: relative;
  margin: 88px auto 24px;
  width: calc(100% - 48px);
  max-width: 1600px;
  height: calc(100vh - 120px);
  min-height: 560px;
  background-color: var(--color-bg-primary);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);

  &.fullscreen {
    position: fixed;
    inset: 0;
    margin: 0;
    width: 100%;
    height: 100%;
    max-width: none;
    border-radius: 0;
    border: 0;
    z-index: 999;
  }
}

.excalidraw-mount {
  width: 100%;
  height: 100%;

  // Excalidraw 默认铺满父容器
  :deep(.excalidraw) {
    border-radius: inherit;
  }
}

.state-layer {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  background-color: var(--color-bg-primary);
  color: var(--color-text-secondary);

  .state-icon {
    font-size: 32px;
    color: var(--color-text-secondary);
  }

  .state-text {
    margin: 8px 0 0;
    font-size: 14px;
    color: var(--color-text-primary);
  }

  .state-sub {
    margin: 0 0 12px;
    font-size: 12px;
    color: var(--color-text-secondary);
    max-width: 80%;
    text-align: center;
    word-break: break-all;
  }
}
</style>
