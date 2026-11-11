<template>
  <div class="header-right">
    <a-tooltip :title="$t('item.share')" placement="bottom">
      <div class="icon-item" @click="handleShare">
        <i class="far fa-share-nodes"></i>
      </div>
    </a-tooltip>

    <a-tooltip
      v-if="itemInfo?.item_edit == 1"
      :title="$t('common.save')"
      placement="bottom"
    >
      <div class="icon-item" @click="$emit('save')">
        <i v-if="!saving" class="far fa-save"></i>
        <i v-else class="fas fa-spinner fa-spin"></i>
      </div>
    </a-tooltip>

    <a-tooltip :title="$t('item.excalidraw_fullscreen')" placement="bottom">
      <div class="icon-item" @click="$emit('toggleFullscreen')">
        <i :class="isFullscreen ? 'far fa-compress' : 'far fa-expand'"></i>
      </div>
    </a-tooltip>

    <!-- 导出 -->
    <CommonDropdownMenu
      :list="exportMenu"
      trigger="hover"
      placement="bottom"
      @select="handleExportSelect"
    >
      <a-tooltip :title="$t('item.export')" placement="bottom">
        <div class="icon-item">
          <i class="far fa-download"></i>
        </div>
      </a-tooltip>
    </CommonDropdownMenu>

    <a-tooltip
      v-if="itemInfo?.item_manage == 1"
      :title="$t('item.member_manage')"
      placement="bottom"
    >
      <div class="icon-item" @click="handleMember">
        <i class="far fa-users"></i>
      </div>
    </a-tooltip>

    <a-tooltip
      v-if="itemInfo?.is_login != 1"
      :title="$t('user.login')"
      placement="bottom"
    >
      <div class="icon-item" @click="handleLogin">
        <i class="far fa-user"></i>
      </div>
    </a-tooltip>

    <a-tooltip
      :title="
        appStore.theme === 'light'
          ? $t('common.dark_mode')
          : $t('common.light_mode')
      "
      placement="bottom"
    >
      <div class="icon-item theme-toggle-item" @click="handleToggleTheme">
        <i class="fas fa-circle-half-stroke"></i>
      </div>
    </a-tooltip>

    <CommonDropdownMenu
      v-if="itemInfo?.item_manage == 1"
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
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import CommonDropdownMenu from '@/components/CommonDropdownMenu.vue'
import { useAppStore } from '@/store'
import ShareModal from '@/views/modals/item/ShareModal/index'
import MemberModal from '@/views/modals/item/MemberModal/index'
import CreateItemModal from '@/views/modals/item/CreateItemModal'
import ArchiveModal from '@/views/modals/item/ArchiveModal/index'
import AttornModal from '@/views/modals/item/AttornModal/index'
import DeleteModal from '@/views/modals/item/DeleteModal/index'
import ChangeLogModal from '@/views/modals/item/ChangeLogModal/index'
import AiSettingsModal from '@/views/modals/item/AiSettingsModal/index'

interface Props {
  itemInfo?: any
  pageId?: number
  saving?: boolean
  lastSavedAt?: number
  isFullscreen?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  itemInfo: () => ({}),
  pageId: 0,
  saving: false,
  lastSavedAt: 0,
  isFullscreen: false,
})

const emit = defineEmits<{
  save: []
  export: [type: 'png' | 'svg' | 'json']
  toggleFullscreen: []
}>()

const router = useRouter()
const { t } = useI18n()
const appStore = useAppStore()

const handleToggleTheme = () => {
  appStore.toggleTheme()
}

const exportMenu = computed(() => [
  { value: 'png', icon: ['far', 'fa-image'], text: t('item.excalidraw_export_png') },
  { value: 'svg', icon: ['far', 'fa-bezier-curve'], text: t('item.excalidraw_export_svg') },
  { value: 'json', icon: ['far', 'fa-file-code'], text: t('item.excalidraw_export_json') },
])

const handleExportSelect = (item: any) => {
  emit('export', item.value)
}

const menuItems = computed(() => [
  { value: 'update', icon: ['far', 'fa-edit'], text: t('item.base_info') },
  { value: 'aiSetting', icon: ['fas', 'fa-wand-magic-sparkles'], text: t('item.ai_assistant_setting') },
  { value: 'archive', icon: ['far', 'fa-box-archive'], text: t('item.archive') },
  { value: 'attorn', icon: ['far', 'fa-recycle'], text: t('item.attorn') },
  { value: 'changelog', icon: ['far', 'fa-clock-rotate-left'], text: t('item.change_log') },
  { value: 'delete', icon: ['far', 'fa-trash-can'], text: t('item.delete_item'), danger: true },
])

const handleShare = async () => {
  if (props.itemInfo) {
    await ShareModal({
      item_domain: props.itemInfo.item_domain,
      item_id: props.itemInfo.item_id,
      page_id: props.pageId,
    })
  }
}

const handleMember = async () => {
  if (props.itemInfo) {
    await MemberModal(props.itemInfo.item_id)
  }
}

const handleLogin = () => {
  router.push('/user/login')
}

const handleDropdownSelect = async (item: any) => {
  const key = item.value
  switch (key) {
    case 'update':
      if (props.itemInfo) {
        await CreateItemModal({ item_id: props.itemInfo.item_id })
      }
      break
    case 'aiSetting':
      if (props.itemInfo) {
        await AiSettingsModal(props.itemInfo.item_id)
      }
      break
    case 'archive':
      if (props.itemInfo) {
        await ArchiveModal(props.itemInfo.item_id)
      }
      break
    case 'attorn':
      if (props.itemInfo) {
        await AttornModal(props.itemInfo.item_id)
      }
      break
    case 'changelog':
      if (props.itemInfo) {
        await ChangeLogModal(props.itemInfo.item_id)
      }
      break
    case 'delete':
      if (props.itemInfo) {
        await DeleteModal(props.itemInfo.item_id)
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
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: var(--color-bg-primary);
  border-radius: 8px;
  box-shadow: var(--shadow-xs);
  cursor: pointer;
  transition: all 0.15s ease;
  flex-shrink: 0;
  color: var(--color-text-secondary);

  &:hover {
    background-color: var(--hover-overlay);
    color: var(--color-active);
  }

  i {
    font-size: 15px;
  }
}
</style>
