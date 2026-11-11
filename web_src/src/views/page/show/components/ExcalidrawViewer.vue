<template>
  <div class="excalidraw-viewer">
    <div v-if="loading" class="viewer-state">
      <a-spin size="large" />
      <p>{{ $t('item.excalidraw_loading') }}</p>
    </div>
    <div v-else-if="error" class="viewer-state">
      <i class="far fa-triangle-exclamation"></i>
      <p>{{ $t('item.excalidraw_load_failed') }}</p>
    </div>
    <div v-show="!loading && !error" ref="mountRef" class="viewer-mount"></div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAppStore } from '@/store'
import { unescapeHTML } from '@/models/page'
import { useExcalidrawLoader } from '@/views/item/show/ShowExcalidrawItem/utils/bundleLoader'

/**
 * Excalidraw 只读展示组件
 * 用于单页分享链接（/p/:unique_key）等场景：只渲染画布，不允许编辑
 */
interface Props {
  /** 页面正文中的场景 JSON（未反转义） */
  scene?: string
}

const props = withDefaults(defineProps<Props>(), {
  scene: '',
})

const { t, locale } = useI18n()
const appStore = useAppStore()
const { load } = useExcalidrawLoader()

const mountRef = ref<HTMLElement | null>(null)
const loading = ref(true)
const error = ref('')

let bundle: any = null
let api: any = null

const parseScene = () => {
  if (!props.scene || !props.scene.trim()) return { elements: [], appState: {}, files: {} }
  try {
    const data = JSON.parse(unescapeHTML(props.scene))
    if (!data || typeof data !== 'object') return { elements: [], appState: {}, files: {} }
    return {
      elements: Array.isArray(data.elements) ? data.elements : [],
      appState: data.appState || {},
      files: data.files || {},
    }
  } catch (e) {
    console.warn('[excalidraw] 只读场景解析失败', e)
    return { elements: [], appState: {}, files: {} }
  }
}

const mount = async () => {
  loading.value = true
  error.value = ''
  try {
    bundle = await load()
    if (!mountRef.value) return

    const initialData = parseScene()
    bundle.mount(mountRef.value, {
      initialData,
      viewModeEnabled: true, // 只读
      theme: appStore.theme === 'dark' ? 'dark' : 'light',
      langCode: String(locale.value || '').startsWith('en') ? 'en' : 'zh-CN',
      UIOptions: {
        canvasActions: {
          // 只读场景下仍允许导出图片
          export: { saveFileToDisk: false },
          loadScene: false,
          saveToActiveFile: false,
          toggleTheme: null,
          changeViewBackgroundColor: false,
          clearCanvas: false,
        },
        tools: { image: false },
      },
      onApi: (_api: any) => {
        api = _api
      },
    })
    loading.value = false
  } catch (e: any) {
    console.error('[excalidraw] 只读画布加载失败', e)
    error.value = e?.message || String(e)
    loading.value = false
  }
}

watch(
  () => appStore.theme,
  (theme) => {
    if (!api) return
    try {
      api.updateScene({ appState: { theme: theme === 'dark' ? 'dark' : 'light' } })
    } catch (e) {
      /* ignore */
    }
  }
)

onMounted(mount)

onBeforeUnmount(() => {
  if (bundle && mountRef.value) {
    try {
      bundle.unmount(mountRef.value)
    } catch (e) {
      /* ignore */
    }
  }
  api = null
})
</script>

<style scoped lang="scss">
.excalidraw-viewer {
  position: relative;
  width: 100%;
  height: calc(100vh - 220px);
  min-height: 520px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  overflow: hidden;
  background: #fff;
}

.viewer-mount {
  width: 100%;
  height: 100%;
}

.viewer-state {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: var(--color-text-secondary);
  font-size: 13px;
}
</style>
