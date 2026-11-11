<template>
  <div class="word-editor-wrap">
    <!-- 加载中 -->
    <div v-if="loading" class="state-layer">
      <a-spin size="large" />
      <p class="state-text">{{ t('item.word_loading') }}</p>
    </div>

    <!-- 加载失败 -->
    <div v-else-if="error" class="state-layer">
      <i class="far fa-triangle-exclamation state-icon"></i>
      <p class="state-text">{{ t('item.word_load_failed') }}</p>
      <p class="state-sub">{{ error }}</p>
      <div class="state-actions">
        <a-button type="primary" @click="reload">{{ t('common.retry') }}</a-button>
        <a-button @click="downloadOriginal">{{ t('item.word_download_original') }}</a-button>
      </div>
    </div>

    <!-- OnlyOffice 挂载点 -->
    <div v-show="!loading && !error" ref="mountRef" class="word-editor-host"></div>

    <!-- 右下角状态 -->
    <div v-if="!loading && !error" class="word-status">
      <span v-if="canEdit" class="status-item">
        <i class="far fa-circle-check"></i>
        {{ t('item.word_autosave_hint') }}
      </span>
      <span v-else class="status-item readonly">
        <i class="far fa-lock"></i>
        {{ t('item.word_readonly_hint') }}
      </span>
      <a-tooltip :title="t('item.word_download_original')">
        <span class="status-item action" @click="downloadOriginal">
          <i class="far fa-download"></i>
        </span>
      </a-tooltip>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import request from '@/utils/request'
import { getServerHost, appendUrlParams } from '@/utils/system'

/**
 * Word 在线编辑组件
 *
 * 依赖外部的 OnlyOffice Document Server：
 * 1. 从配置的文档服务器加载 api.js
 * 2. 向 ShowDoc 后端请求带 JWT 的编辑器配置
 * 3. 实例化 DocsAPI.DocEditor
 *
 * 文档保存由文档服务器通过 callbackUrl 回调后端完成，前端不参与保存。
 */
interface Props {
  pageId?: number | string
  /** 单页分享链接标识（/p/:unique_key），有值时无需登录即可访问 */
  uniqueKey?: string
  /** 只读模式（如分享链接、无编辑权限的访客） */
  readonly?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  pageId: 0,
  uniqueKey: '',
  readonly: false
})

const { t } = useI18n()

const mountRef = ref<HTMLElement | null>(null)
const loading = ref(true)
const error = ref('')
const canEdit = ref(false)

let editorInstance: any = null
let placeholderId = ''

const loadScript = (src: string): Promise<void> =>
  new Promise((resolve, reject) => {
    const existing = document.querySelector(
      `script[data-onlyoffice="1"][src="${src}"]`
    ) as HTMLScriptElement | null
    if (existing) {
      if ((window as any).DocsAPI) {
        resolve()
        return
      }
      existing.addEventListener('load', () => resolve())
      existing.addEventListener('error', () => reject(new Error('load error')))
      return
    }

    const script = document.createElement('script')
    script.src = src
    script.async = true
    script.dataset.onlyoffice = '1'
    script.onload = () => resolve()
    script.onerror = () => reject(new Error(`无法加载 ${src}`))
    document.head.appendChild(script)
  })

const mountEditor = async () => {
  loading.value = true
  error.value = ''

  try {
    if (!props.pageId) {
      throw new Error(t('item.word_no_page'))
    }

    // 1. 取配置
    const res = await request(
      '/api/word/editorConfig',
      {
        page_id: String(props.pageId),
        unique_key: props.uniqueKey || '',
        force_readonly: props.readonly ? 1 : 0
      },
      'post',
      false
    )

    if (res.error_code !== 0) {
      throw new Error(res.error_message || t('item.word_load_failed'))
    }

    const serverUrl = String(res.data?.document_server_url || '').replace(/\/$/, '')
    const config = res.data?.config
    canEdit.value = !!res.data?.can_edit && !props.readonly

    if (!serverUrl || !config) {
      throw new Error(t('item.word_not_configured'))
    }

    // 只读场景强制 view 模式。
    // 注意：配置里带 token 时说明后端已签名，前端不能再改任何字段，否则签名失效，
    // 此时只依靠后端下发的 mode / permissions（后端已按权限计算）。
    if (!canEdit.value && !config.token) {
      config.editorConfig.mode = 'view'
      config.document.permissions.edit = false
    }

    // 2. 加载文档服务器的 api.js
    const apiUrl = `${serverUrl}/web-apps/apps/api/documents/api.js`
    await loadScript(apiUrl)

    if (!(window as any).DocsAPI) {
      throw new Error(t('item.word_api_missing'))
    }

    // 3. 挂载
    await nextTick()
    if (!mountRef.value) return

    placeholderId = `onlyoffice-word-${props.pageId}-${Date.now()}`
    const holder = document.createElement('div')
    holder.id = placeholderId
    holder.className = 'onlyoffice-holder'
    mountRef.value.innerHTML = ''
    mountRef.value.appendChild(holder)

    editorInstance = new (window as any).DocsAPI.DocEditor(placeholderId, config)
    loading.value = false
  } catch (e: any) {
    console.error('[word] 编辑器加载失败', e)
    error.value = e?.message || String(e)
    loading.value = false
  }
}

const destroyEditor = () => {
  if (editorInstance && typeof editorInstance.destroyEditor === 'function') {
    try {
      editorInstance.destroyEditor()
    } catch (e) {
      /* ignore */
    }
  }
  editorInstance = null
  if (mountRef.value) {
    mountRef.value.innerHTML = ''
  }
}

const reload = () => {
  destroyEditor()
  mountErrorCleanup()
  mountEditor()
}

const mountErrorCleanup = () => {
  error.value = ''
}

/**
 * 下载当前文档的原始 .docx
 * 走带登录态的下载接口（后端会校验访问权限）
 */
const downloadOriginal = () => {
  if (!props.pageId) return
  let userToken = ''
  try {
    const userinfo = JSON.parse(localStorage.getItem('userinfo') || '{}')
    userToken = userinfo?.user_token || ''
  } catch (e) {
    /* ignore */
  }

  const params: Record<string, any> = {
    page_id: props.pageId,
    user_token: userToken
  }
  if (props.uniqueKey) params.unique_key = props.uniqueKey
  const itemPwd = sessionStorage.getItem('_item_pwd')
  if (itemPwd) params._item_pwd = itemPwd

  window.location.href = appendUrlParams(
    `${getServerHost()}/api/word/download`,
    params
  )
}

onMounted(() => {
  mountEditor()
})

onBeforeUnmount(() => {
  destroyEditor()
})
</script>

<style scoped lang="scss">
.word-editor-wrap {
  position: relative;
  width: 100%;
  height: calc(100vh - 170px);
  min-height: 620px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  overflow: hidden;
  background: var(--color-bg-primary);
}

.word-editor-host {
  width: 100%;
  height: 100%;

  :deep(.onlyoffice-holder) {
    width: 100%;
    height: 100%;
  }

  :deep(iframe) {
    width: 100% !important;
    height: 100% !important;
    border: 0;
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
  background: var(--color-bg-primary);
  color: var(--color-text-secondary);

  .state-icon {
    font-size: 30px;
  }

  .state-text {
    margin: 8px 0 0;
    font-size: 14px;
    color: var(--color-text-primary);
  }

  .state-sub {
    margin: 0;
    max-width: 80%;
    text-align: center;
    font-size: 12px;
    word-break: break-all;
  }

  .state-actions {
    margin-top: 12px;
    display: flex;
    gap: 10px;
  }
}

.word-status {
  position: absolute;
  right: 12px;
  bottom: 12px;
  z-index: 3;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 4px 10px;
  border-radius: 14px;
  background: var(--color-bg-secondary);
  border: 1px solid var(--color-border);
  font-size: 12px;
  color: var(--color-text-secondary);

  .status-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;

    &.readonly {
      color: #d46b08;
    }

    &.action {
      cursor: pointer;

      &:hover {
        color: var(--color-active);
      }
    }
  }
}
</style>
