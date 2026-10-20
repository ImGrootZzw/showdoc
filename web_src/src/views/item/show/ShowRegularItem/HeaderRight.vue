<template>
  <div class="header-right">
    <!-- 编辑页面 -->
    <a-tooltip v-if="itemInfo?.item_edit == 1 && pageId" :title="$t('page.edit_page')" placement="bottom">
      <div class="icon-item" @click="handleEdit">
        <i class="far fa-edit"></i>
      </div>
    </a-tooltip>

    <!-- RunApi 项目提示 -->
    <a-tooltip v-if="itemInfo?.item_type == 3 && itemInfo?.is_login" :title="$t('item.runapi_edit_hint')" placement="bottom">
      <div class="icon-item disabled">
        <i class="far fa-edit"></i>
      </div>
    </a-tooltip>

    <!-- 分享 -->
    <a-tooltip :title="$t('item.share')" placement="bottom">
      <div class="icon-item" @click="handleShare">
        <i class="far fa-share-nodes"></i>
      </div>
    </a-tooltip>

    <!-- 导入 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.import')" placement="bottom">
      <div class="icon-item" @click="handleImport">
        <i class="far fa-upload"></i>
      </div>
    </a-tooltip>

    <!-- 导出 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.export')" placement="bottom">
      <div class="icon-item" @click="handleExport">
        <i class="far fa-download"></i>
      </div>
    </a-tooltip>

    <!-- 成员 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.member_manage')" placement="bottom">
      <div class="icon-item" @click="handleMember">
        <i class="far fa-users"></i>
      </div>
    </a-tooltip>

    <!-- 回收站 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.recycle')" placement="bottom">
      <div class="icon-item" @click="handleRecycle">
        <i class="far fa-recycle"></i>
      </div>
    </a-tooltip>

    <!-- 更新项目信息 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.update_base_info')" placement="bottom">
      <div class="icon-item" @click="handleSetting">
        <i class="far fa-gear"></i>
      </div>
    </a-tooltip>

    <!-- 项目变更日志 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.change_log')" placement="bottom">
      <div class="icon-item" @click="handleChangeLog">
        <i class="far fa-clock-rotate-left"></i>
      </div>
    </a-tooltip>

    <!-- 归档 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.archive')" placement="bottom">
      <div class="icon-item" @click="handleArchive">
        <i class="far fa-box-archive"></i>
      </div>
    </a-tooltip>

    <!-- 删除项目 -->
    <a-tooltip v-if="itemInfo?.item_manage" :title="$t('item.delete_item')" placement="bottom">
      <div class="icon-item danger-item" @click="handleDelete">
        <i class="far fa-trash-can"></i>
      </div>
    </a-tooltip>

    <!-- 用户中心 -->
    <a-tooltip v-if="itemInfo?.is_login" :title="$t('user.user_center')" placement="bottom">
      <div class="icon-item" @click="handleUserCenter">
        <i class="far fa-user"></i>
      </div>
    </a-tooltip>

    <!-- 登录 -->
    <a-tooltip v-if="!itemInfo?.is_login" :title="$t('user.login')" placement="bottom">
      <div class="icon-item" @click="handleLogin">
        <i class="far fa-user"></i>
      </div>
    </a-tooltip>

    <!-- 关于 ShowDoc -->
    <a-tooltip v-if="!itemInfo?.is_login" :title="$t('item.aboutShowdoc')" placement="bottom">
      <div class="icon-item" @click="handleAboutShowdoc">
        <i class="far fa-circle-info"></i>
      </div>
    </a-tooltip>

    <!-- 语言切换（仅游客显示） -->
    <a-tooltip v-if="!itemInfo?.is_login" :title="$t('header.switch_language')" placement="bottom">
      <div class="icon-item language-item">
        <LanguageToggle />
      </div>
    </a-tooltip>

    <!-- 页面宽度（预设 + 拖动条） -->
    <a-popover placement="bottomRight" trigger="click" :overlayStyle="{ width: '280px' }">
      <template #content>
        <div style="padding: 4px 2px;">
          <div style="font-size: 13px; font-weight: 500; color: var(--color-text-primary); margin-bottom: 10px;">
            {{ $t('page.page_width') }}
          </div>
          <a-radio-group
            size="small"
            button-style="solid"
            :value="currentPreset"
            style="display: flex; width: 100%;"
            @change="handlePresetChange"
          >
            <a-radio-button value="standard" style="flex: 1; text-align: center;">{{ $t('page.width_standard') }}</a-radio-button>
            <a-radio-button value="wide" style="flex: 1; text-align: center;">{{ $t('page.width_wide') }}</a-radio-button>
            <a-radio-button value="full" style="flex: 1; text-align: center;">{{ $t('page.width_full') }}</a-radio-button>
          </a-radio-group>
          <div style="margin: 16px 6px 0 6px;">
            <a-slider
              :min="MIN_CONTENT_WIDTH"
              :max="MAX_CONTENT_WIDTH"
              :step="10"
              :value="sliderValue"
              @change="handleSliderChange"
            />
          </div>
          <div style="text-align: center; font-size: 12px; color: var(--color-text-secondary);">
            {{ $t('page.width_custom') }}:
            <span style="color: var(--color-active); font-weight: 600;">{{ sliderValue }}px</span>
          </div>
        </div>
      </template>
      <div class="icon-item" :title="$t('page.page_width')">
        <i class="far fa-arrows-left-right"></i>
      </div>
    </a-popover>

    <!-- 主题切换 -->
    <a-tooltip :title="appStore.theme === 'light' ? $t('common.dark_mode') : $t('common.light_mode')" placement="bottom">
      <div class="icon-item theme-toggle-item" @click="handleToggleTheme">
        <i class="far fa-circle-half-stroke"></i>
      </div>
    </a-tooltip>

    <!-- 更多菜单 -->
    <CommonDropdownMenu
      v-if="itemInfo?.item_manage"
      :list="menuItems"
      trigger="hover"
      placement="bottom"
      :offsetX="-80"
      @select="handleDropdownSelect"
    >
      <div class="icon-item">
        <i class="far fa-ellipsis"></i>
      </div>
    </CommonDropdownMenu>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAppStore } from '@/store'
import CommonDropdownMenu from '@/components/CommonDropdownMenu.vue'
import Message from '@/components/Message'
import EditPageModal from '@/views/modals/page/EditPageModal/index'
import ShareModal from '@/views/modals/item/ShareModal/index'
import MemberModal from '@/views/modals/item/MemberModal/index'
import CreateItemModal from '@/views/modals/item/CreateItemModal'
import UserCenterModal from '@/views/modals/user/UserCenterModal/index'
import ImportFileModal from '@/views/modals/item/ImportFileModal/index'
import ExportFileModal from '@/views/modals/item/ExportFileModal/index'
import RecycleModal from '@/views/modals/item/RecycleModal/index'
import ChangeLogModal from '@/views/modals/item/ChangeLogModal/index'
import AiSettingsModal from '@/views/modals/item/AiSettingsModal/index'
import OpenApiModal from '@/views/modals/item/OpenApiModal/index'
import ArchiveModal from '@/views/modals/item/ArchiveModal/index'
import AttornModal from '@/views/modals/item/AttornModal/index'
import DeleteModal from '@/views/modals/item/DeleteModal/index'
import LanguageToggle from '@/components/LanguageToggle.vue'
import type { DropdownMenuItem } from '@/components/CommonDropdownMenu.vue'

// Props
interface Props {
  pageId?: number
  itemInfo?: any
  pageInfo?: any
  searchItem?: (keyword: string) => void
}

const props = withDefaults(defineProps<Props>(), {
  pageId: 0,
  itemInfo: () => ({}),
  pageInfo: () => ({}),
  searchItem: () => {}
})

// Emits
const emit = defineEmits<{
  editPage: []
}>()

// Composables
const router = useRouter()
const { t } = useI18n()
const appStore = useAppStore()

// Methods
const handleToggleTheme = () => {
  appStore.toggleTheme()
}

// 下拉菜单项
const menuItems = computed<DropdownMenuItem[]>(() => [
  {
    icon: ['fas', 'fa-wand-magic-sparkles'],
    text: t('item.ai_assistant_setting'),
    value: 'aiSetting'
  },
  {
    icon: ['far', 'fa-terminal'],
    text: t('item.open_api'),
    value: 'openapi'
  },
  {
    icon: ['far', 'fa-repeat'],
    text: t('item.attorn'),
    value: 'attorn'
  }
])

// 下拉菜单点击处理
const handleDropdownSelect = (item: DropdownMenuItem) => {
  const key = item.value
  handleMenuClick({ key })
}

// 页面宽度切换（预设 + 拖动条自定义，localStorage 记忆，index.vue 监听事件应用样式）
const PRESET_CONTENT_WIDTHS: Record<string, number> = { standard: 900, wide: 1300 }
const MIN_CONTENT_WIDTH = 700
const MAX_CONTENT_WIDTH = 2600
const widthMode = ref(localStorage.getItem('showdoc_page_width') || 'standard')
const sliderValue = ref<number>((() => {
  const m = widthMode.value.match(/^custom:(\d+)$/)
  if (m) {
    return Math.min(MAX_CONTENT_WIDTH, Math.max(MIN_CONTENT_WIDTH, parseInt(m[1], 10)))
  }
  if (PRESET_CONTENT_WIDTHS[widthMode.value]) {
    return PRESET_CONTENT_WIDTHS[widthMode.value]
  }
  return MAX_CONTENT_WIDTH
})())
const currentPreset = computed(() => (widthMode.value.startsWith('custom:') ? '' : widthMode.value))

const applyWidth = (mode: string) => {
  widthMode.value = mode
  localStorage.setItem('showdoc_page_width', mode)
  window.dispatchEvent(
    new CustomEvent('showdoc-page-width-change', { detail: { mode } })
  )
}

const handlePresetChange = (e: any) => {
  const mode = e?.target?.value
  if (!mode) return
  if (PRESET_CONTENT_WIDTHS[mode]) {
    sliderValue.value = PRESET_CONTENT_WIDTHS[mode]
  }
  applyWidth(mode)
  Message.success(`${t('page.page_width')}: ${t('page.width_' + mode)}`)
}

const handleSliderChange = (value: number) => {
  sliderValue.value = value
  applyWidth(`custom:${value}`)
}

// Methods
const handleEdit = async () => {
  if (props.pageId && props.itemInfo?.item_id) {
    const result = await EditPageModal({
      itemId: props.itemInfo.item_id,
      editPageId: props.pageId,
      copyPageId: 0
    })
    if (result) {
      emit('editPage')
    }
  }
}

const handleShare = async () => {
  if (props.itemInfo) {
    await ShareModal({
      item_domain: props.itemInfo.item_domain,
      item_id: props.itemInfo.item_id,
      page_id: props.pageId,
      page_unique_key: props.pageInfo?.unique_key,
      page_title: props.pageInfo?.page_title,
      item_info: props.itemInfo
    })
  }
}

const handleUserCenter = async () => {
  await UserCenterModal()
}

const handleLogin = () => {
  router.push('/user/login')
}

const handleAboutShowdoc = () => {
  window.open('https://www.showdoc.com.cn/help', '_blank')
}

// 顶部图标：导入
const handleImport = async () => {
  if (props.itemInfo) {
    await ImportFileModal({
      itemId: props.itemInfo.item_id
    })
  }
}

// 顶部图标：导出
const handleExport = async () => {
  if (props.itemInfo) {
    await ExportFileModal(props.itemInfo.item_id)
  }
}

// 顶部图标：成员
const handleMember = async () => {
  if (props.itemInfo) {
    await MemberModal(props.itemInfo.item_id)
  }
}

// 顶部图标：回收站
const handleRecycle = async () => {
  if (props.itemInfo) {
    await RecycleModal(props.itemInfo.item_id)
  }
}

// 顶部图标：更新项目信息
const handleSetting = async () => {
  if (props.itemInfo) {
    await CreateItemModal({
      item_id: props.itemInfo.item_id
    })
  }
}

// 顶部图标：项目变更日志
const handleChangeLog = async () => {
  if (props.itemInfo) {
    await ChangeLogModal(props.itemInfo.item_id)
  }
}

// 顶部图标：归档
const handleArchive = async () => {
  if (props.itemInfo) {
    await ArchiveModal(props.itemInfo.item_id)
  }
}

// 顶部图标：删除项目
const handleDelete = async () => {
  if (props.itemInfo) {
    await DeleteModal(props.itemInfo.item_id)
  }
}

const handleMenuClick = async ({ key }: { key: string }) => {
  switch (key) {
    case 'aiSetting':
      if (props.itemInfo) {
        await AiSettingsModal(props.itemInfo.item_id)
      }
      break

    case 'openapi':
      if (props.itemInfo) {
        await OpenApiModal(props.itemInfo.item_id)
      }
      break

    case 'attorn':
      if (props.itemInfo) {
        await AttornModal(props.itemInfo.item_id)
      }
      break
  }
}
</script>

<style scoped lang="scss">
.header-right {
  display: flex;
  align-items: center;
  gap: 10px;
}

.icon-item {
  width: 40px;
  height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0; // 防止收缩，保持正方形
  background-color: var(--color-bg-primary);
  border-radius: 8px;
  box-shadow: var(--shadow-xs);
  cursor: pointer;
  transition: all 0.15s ease;

  &:hover {
    background-color: var(--hover-overlay);
    box-shadow: var(--shadow-sm);
  }

  &.disabled {
    opacity: 0.5;
    cursor: not-allowed;

    &:hover {
      background-color: var(--color-bg-primary);
    }
  }

  &.danger-item {
    i {
      color: #ff4d4f;
    }

    &:hover {
      background-color: rgba(255, 77, 79, 0.08);
    }
  }

  i {
    color: var(--color-text-primary);
    font-size: 16px;
    transition: none; // 图标颜色不变化
  }

  // 图标颜色保持不变（克制设计 - hover 只改变背景）

  [data-theme="dark"] & {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    
    &:hover {
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
    }
  }
}

.icon-item a {
  color: var(--color-text-primary);
}

// 主题切换按钮特殊样式
.theme-toggle-item {
  i {
    color: var(--color-orange) !important;
  }

  &:hover i {
    color: var(--color-orange) !important;
  }
}

// 语言切换按钮样式
.language-item {
  :deep(.language-toggle) {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 0;
    width: 100%;
    height: 100%;
    background: none;
    box-shadow: none;
    transition: none;

    .language-text {
      font-size: 13px;
      font-weight: 500;
      line-height: 1;
    }

    .language-icon {
      font-size: 14px;
    }

    &:hover {
      background-color: var(--hover-overlay);
      box-shadow: var(--shadow-sm);
    }
  }
}
</style>

