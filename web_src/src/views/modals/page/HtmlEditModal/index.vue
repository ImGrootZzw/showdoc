<template>
  <EditorModal :show="show" :mask-closable="false" @close="handleClose">
    <div class="html-edit-container">
      <!-- 顶部工具栏 -->
      <div class="edit-header">
        <div class="header-left">
          <a-button class="close-btn" @click="handleClose">
            <i class="fas fa-times"></i>
          </a-button>

          <!-- 页面标题编辑 -->
          <a-tooltip v-if="!isEditingTitle" :title="$t('page.click_to_edit_page_title')">
            <span class="page-title" @click="isEditingTitle = true">
              {{ form.title || $t('page.untitled') }}
            </span>
          </a-tooltip>
          <CommonInput
            v-else
            v-model="form.title"
            class="page-title-input"
            :placeholder="$t('page.input_page_title')"
            @blur="isEditingTitle = false"
            @keyup.enter="isEditingTitle = false"
          />

          <span class="html-tag">HTML</span>
        </div>

        <div class="header-right">
          <a-tooltip v-if="currentPageId" :title="$t('page.page_history_version')" placement="bottom">
            <div class="icon-item" @click="handleOpenHistory">
              <i class="fas fa-clock-rotate-left"></i>
            </div>
          </a-tooltip>

          <a-tooltip :title="showPreview ? $t('page.html_hide_preview') : $t('page.html_show_preview')" placement="bottom">
            <div class="icon-item" :class="{ active: showPreview }" @click="showPreview = !showPreview">
              <i class="far fa-eye"></i>
            </div>
          </a-tooltip>

          <CommonButton
            theme="dark"
            :text="$t('common.save')"
            :spinning="saving"
            :left-icon="['fas', 'fa-save']"
            @click="handleSave"
          />
        </div>
      </div>

      <!-- 编辑区：源码 + 实时预览 -->
      <div class="edit-body">
        <div class="code-pane" :class="{ 'is-wide': !showPreview }">
          <div class="pane-title">
            <i class="far fa-file-code"></i>
            <span>{{ $t('page.html_source') }}</span>
          </div>
          <div ref="editorHost" v-show="!useFallbackEditor" class="code-editor"></div>
          <textarea
            v-if="useFallbackEditor"
            v-model="code"
            class="code-textarea"
            spellcheck="false"
          ></textarea>
        </div>

        <div v-show="showPreview" class="preview-pane">
          <div class="pane-title">
            <i class="far fa-eye"></i>
            <span>{{ $t('page.html_preview') }}</span>
            <span class="sandbox-hint" :title="$t('page.html_sandbox_hint')">
              <i class="far fa-shield-halved"></i>
              sandbox
            </span>
          </div>
          <iframe
            class="preview-frame"
            :srcdoc="previewHtml"
            :sandbox="HTML_PAGE_SANDBOX"
            referrerpolicy="no-referrer"
            title="HTML preview"
          ></iframe>
        </div>
      </div>

      <!-- 底部状态栏 -->
      <div class="edit-footer">
        <span class="status-text">
          {{ $t('page.html_line_count', { lines: lineCount, chars: charCount }) }}
        </span>
        <span class="status-hint">
          <i class="far fa-circle-info"></i>
          {{ $t('page.html_edit_tips') }}
        </span>
      </div>
    </div>
  </EditorModal>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import EditorModal from '@/components/EditorModal.vue'
import CommonButton from '@/components/CommonButton.vue'
import CommonInput from '@/components/CommonInput.vue'
import Message from '@/components/Message'
import AlertModal from '@/components/AlertModal'
import ConfirmModal from '@/components/ConfirmModal/index'
import HistoryModal from '@/views/modals/page/HistoryModal/index'
import request from '@/utils/request'
import { getStaticPath } from '@/utils/system'
import {
  HTML_PAGE_SANDBOX,
  getHtmlPageSource,
  isHtmlPage
} from '@/models/page'

interface Props {
  itemId?: string | number
  editPageId?: string | number
  copyPageId?: string | number
  catId?: string | number
  onClose: (result: boolean) => void
}

const props = withDefaults(defineProps<Props>(), {
  itemId: '',
  editPageId: '',
  copyPageId: '',
  catId: ''
})

const { t } = useI18n()

const show = ref(true)
const saving = ref(false)
// 窄屏（移动端）默认只看源码，节省空间
const showPreview = ref(window.innerWidth > 900)
const isEditingTitle = ref(false)

const form = ref({
  title: '',
  catId: Number(props.catId || 0)
})

// 页面正文（HTML 源码）
const code = ref('')
const previewHtml = ref('')
const currentPageId = ref<string | number>(
  props.copyPageId ? '' : props.editPageId || ''
)

const originalCode = ref('')
const originalTitle = ref('')

// 编辑器
const editorHost = ref<HTMLElement | null>(null)
const useFallbackEditor = ref(false)
let codeMirror: any = null
let previewTimer: number | null = null

const lineCount = computed(() => (code.value ? code.value.split('\n').length : 0))
const charCount = computed(() => code.value.length)

const escapeHtml = (text: string) =>
  text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')

// 兜底预览：源码为空时给个示意
const previewSource = computed(() => {
  if (code.value.trim()) return code.value
  return `<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:system-ui,sans-serif;color:#999;padding:24px">${escapeHtml(
    t('page.html_preview_empty')
  )}</body></html>`
})

const schedulePreview = () => {
  if (previewTimer) window.clearTimeout(previewTimer)
  previewTimer = window.setTimeout(() => {
    previewHtml.value = previewSource.value
  }, 250)
}

// 源码变化时防抖刷新预览（CodeMirror 与兜底 textarea 都走这里）
watch(code, () => {
  schedulePreview()
})

// ============================================
// CodeMirror 加载（复用 editor.md 内置的 CodeMirror）
// ============================================
const loadedScripts = new Set<string>()
const loadedStyles = new Set<string>()

const loadScript = (url: string): Promise<void> =>
  new Promise((resolve, reject) => {
    if (loadedScripts.has(url) || document.querySelector(`script[src="${url}"]`)) {
      loadedScripts.add(url)
      resolve()
      return
    }
    const script = document.createElement('script')
    script.src = url
    script.onload = () => {
      loadedScripts.add(url)
      resolve()
    }
    script.onerror = () => reject(new Error(`Failed to load ${url}`))
    document.head.appendChild(script)
  })

const loadCSS = (href: string): Promise<void> =>
  new Promise((resolve, reject) => {
    if (loadedStyles.has(href) || document.querySelector(`link[href="${href}"]`)) {
      loadedStyles.add(href)
      resolve()
      return
    }
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = href
    link.onload = () => {
      loadedStyles.add(href)
      resolve()
    }
    link.onerror = () => reject(new Error(`Failed to load CSS: ${href}`))
    document.head.appendChild(link)
  })

const initCodeMirror = async () => {
  const staticPath = getStaticPath()
  const cmBase = `${staticPath}editor.md/lib/codemirror/`

  try {
    // 始终确保基础样式存在（editor.md 可能只加载了脚本）
    await loadCSS(`${cmBase}codemirror.min.css`).catch(() => {})

    if (!(window as any).CodeMirror) {
      await loadScript(`${cmBase}codemirror.min.js`)
      // modes.min.js 内含 htmlmixed 及其依赖的 xml/css/javascript 模式
      await loadScript(`${cmBase}modes.min.js`)
    } else if (!(window as any).CodeMirror.modes?.htmlmixed) {
      // 页面视图可能已通过 editor.md 加载过 CodeMirror，但未包含 htmlmixed
      await loadScript(`${cmBase}modes.min.js`)
    }

    const CodeMirror = (window as any).CodeMirror
    if (!CodeMirror || !editorHost.value) {
      useFallbackEditor.value = true
      return
    }

    await nextTick()

    const mode = CodeMirror.modes && CodeMirror.modes.htmlmixed ? 'htmlmixed' : 'xml'
    codeMirror = CodeMirror(editorHost.value, {
      value: code.value,
      mode,
      lineNumbers: true,
      lineWrapping: true,
      indentUnit: 2,
      tabSize: 2,
      viewportMargin: 30
    })

    codeMirror.on('change', () => {
      code.value = codeMirror.getValue()
    })
  } catch (error) {
    console.warn('CodeMirror 加载失败，退回普通文本编辑器:', error)
    useFallbackEditor.value = true
  }
}

const setCode = (value: string) => {
  code.value = value
  if (codeMirror) {
    codeMirror.setValue(value)
  }
  schedulePreview()
}

// ============================================
// 数据加载
// ============================================
const loadPage = async () => {
  const loadId = props.copyPageId || props.editPageId
  if (!loadId) return

  try {
    const data = await request(
      '/api/page/info',
      { page_id: String(loadId) },
      'post',
      false
    )

    if (data.error_code === 0 && data.data) {
      const page = data.data
      // 复制模式：作为新页面保存
      if (props.copyPageId) {
        form.value.title = t('page.copy_of', { title: page.page_title || '' })
        currentPageId.value = ''
      } else {
        form.value.title = page.page_title || ''
        currentPageId.value = page.page_id
      }
      form.value.catId = Number(page.cat_id || 0)
      setCode(getHtmlPageSource(page.page_content || ''))
      originalCode.value = code.value
      originalTitle.value = form.value.title

      if (!props.copyPageId && !isHtmlPage(page)) {
        // 理论上不会走到这里，兜底避免把普通 Markdown 页面当 HTML 保存
        console.warn('当前页面未被标记为 HTML 页面')
      }
    } else {
      await AlertModal(data.error_message || t('page.html_load_failed'))
    }
  } catch (error) {
    console.error('加载页面失败:', error)
    await AlertModal(t('page.html_load_failed'))
  }
}

// ============================================
// 保存
// ============================================
const handleSave = async () => {
  if (!props.itemId) {
    await AlertModal(t('page.item_id_required'))
    return
  }

  if (codeMirror) {
    code.value = codeMirror.getValue()
  }

  if (!code.value.trim()) {
    await AlertModal(t('page.html_empty_tip'))
    return
  }

  saving.value = true
  try {
    const result = await request(
      '/api/page/save',
      {
        page_id: currentPageId.value || '',
        item_id: String(props.itemId),
        cat_id: form.value.catId,
        page_title: form.value.title.trim() || t('page.untitled'),
        is_urlencode: 1,
        page_content: encodeURIComponent(code.value),
        ext_info: JSON.stringify({ page_type: 'html' })
      },
      'post',
      false
    )

    if (result.error_code === 0) {
      if (!currentPageId.value && result.data?.page_id) {
        currentPageId.value = result.data.page_id
      }
      originalCode.value = code.value
      originalTitle.value = form.value.title
      Message.success(t('page.save_success'))
      props.onClose(true)
    } else {
      await AlertModal(result.error_message || t('page.save_failed'))
    }
  } catch (error) {
    console.error('保存 HTML 页面失败:', error)
    await AlertModal(t('page.save_failed'))
  } finally {
    saving.value = false
  }
}

// 历史版本：恢复时把内容回填到编辑器，保存后生成新版本
const handleOpenHistory = async () => {
  if (!currentPageId.value) return
  await HistoryModal({
    pageId: Number(currentPageId.value),
    allowRecover: true,
    onRestore: (pageContent: string) => {
      setCode(pageContent)
    }
  })
}

// ============================================
// 关闭 / 快捷键
// ============================================
const handleClose = async () => {
  if (saving.value) return

  if (code.value !== originalCode.value || form.value.title !== originalTitle.value) {
    const confirmed = await ConfirmModal({
      title: t('common.tips'),
      msg: t('page.html_unsaved_confirm')
    })
    if (!confirmed) return
  }

  props.onClose(false)
}

const handleKeydown = (event: KeyboardEvent) => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    handleSave()
  }
}

onMounted(async () => {
  await loadPage()
  await initCodeMirror()
  schedulePreview()
  window.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
  if (previewTimer) window.clearTimeout(previewTimer)
  window.removeEventListener('keydown', handleKeydown)
  if (codeMirror) {
    codeMirror.toTextArea?.()
    codeMirror = null
  }
})
</script>

<style scoped lang="scss">
.html-edit-container {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--color-bg-primary);
}

.edit-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 20px;
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.close-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
}

.page-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--color-text-primary);
  cursor: text;
  max-width: 520px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.page-title-input {
  width: 320px;
}

.html-tag {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  color: var(--color-active);
  background: var(--color-bg-secondary);
  border: 1px solid var(--color-border);
  border-radius: 4px;
  padding: 1px 6px;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 10px;

  .icon-item {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    cursor: pointer;
    color: var(--color-text-secondary);
    transition: all 0.15s ease;

    &:hover,
    &.active {
      background: var(--hover-overlay);
      color: var(--color-active);
    }
  }
}

.edit-body {
  flex: 1;
  min-height: 0;
  display: flex;
  gap: 1px;
  background: var(--color-border);
}

.code-pane,
.preview-pane {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
  background: var(--color-bg-primary);
}

.code-pane.is-wide {
  flex: 1 1 100%;
}

.pane-title {
  display: flex;
  align-items: center;
  gap: 6px;
  height: 34px;
  padding: 0 12px;
  font-size: 12px;
  color: var(--color-text-secondary);
  background: var(--color-bg-secondary);
  border-bottom: 1px solid var(--color-border);
  flex-shrink: 0;

  .sandbox-hint {
    margin-left: auto;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    opacity: 0.75;
  }
}

.code-editor {
  flex: 1;
  min-height: 0;
  overflow: hidden;

  :deep(.CodeMirror) {
    height: 100%;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
    font-size: 13px;
    line-height: 1.6;
  }

  :deep(.CodeMirror-scroll) {
    padding-bottom: 0;
  }
}

.code-textarea {
  flex: 1;
  width: 100%;
  min-height: 0;
  padding: 12px 16px;
  border: 0;
  outline: none;
  resize: none;
  font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
  font-size: 13px;
  line-height: 1.6;
  color: var(--color-text-primary);
  background: var(--color-bg-primary);
}

.preview-frame {
  flex: 1;
  width: 100%;
  min-height: 0;
  border: 0;
  background: #fff;
}

.edit-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 16px;
  font-size: 12px;
  color: var(--color-text-secondary);
  border-top: 1px solid var(--color-border);
  flex-shrink: 0;

  .status-hint {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
