<template>
  <div class="header-right">
    <div>
      <!-- 语言切换按钮 -->
      <a-tooltip :title="t('header.switch_language')" placement="top">
        <div class="icon-item language-item">
          <LanguageToggle />
        </div>
      </a-tooltip>

      <!-- 主题切换按钮 -->
      <a-tooltip :title="appStore.theme === 'light' ? t('common.dark_mode') : t('common.light_mode')" placement="top">
        <div class="icon-item theme-toggle-item" @click="handleToggleTheme">
          <i class="fas fa-circle-half-stroke"></i>
        </div>
      </a-tooltip>

      <a-tooltip :title="t('attachment.my_attachment')" placement="top">
        <div class="icon-item" @click="handleAttachment">
          <i class="fas fa-folder-arrow-up"></i>
        </div>
      </a-tooltip>

      <a-tooltip :title="t('team.team_manage')" placement="top">
        <div class="icon-item" @click="handleTeam">
          <i class="fas fa-users"></i>
        </div>
      </a-tooltip>

      <a-tooltip
        v-if="publicSquareEnabled"
        :title="t('item.public_square')"
        placement="top"
      >
        <div class="icon-item" @click="toPath('/public-square/index')">
          <i class="fas fa-landmark"></i>
        </div>
      </a-tooltip>

      <a-tooltip :title="t('user.user_center')" placement="top">
        <div class="icon-item" @click="handleUserCenter">
          <i class="fas fa-user"></i>
        </div>
      </a-tooltip>

      <a-tooltip v-if="isAdmin" :title="t('admin.background')" placement="top">
        <div class="icon-item" @click="toPath('/admin/index')">
          <i class="fas fa-gear"></i>
        </div>
      </a-tooltip>
    </div>

  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAppStore } from '@/store/app'
import UserCenterModal from '@/views/modals/user/UserCenterModal'
import AttachmentModal from '@/views/modals/attachment/AttachmentModal'
import TeamModal from '@/views/modals/team/TeamModal'
import LanguageToggle from '@/components/LanguageToggle.vue'
import { checkPublicSquareEnabled } from '@/models/publicSquare'

const { t } = useI18n()
const router = useRouter()
const appStore = useAppStore()

// Props
defineProps<{
  isAdmin: boolean
}>()

// 数据状态
const publicSquareEnabled = ref(false)

// 路由跳转
const toPath = (path: string) => {
  router.push({ path })
}

// 切换主题
const handleToggleTheme = () => {
  appStore.toggleTheme()
}

// 显示文件库弹窗
const handleAttachment = async () => {
  await AttachmentModal()
}

// 显示用户中心
const handleUserCenter = async () => {
  await UserCenterModal()
}

// 显示团队管理弹窗
const handleTeam = async () => {
  await TeamModal()
}

// 检查公共广场是否启用
const checkPublicSquare = async () => {
  try {
    const data = await checkPublicSquareEnabled()
    publicSquareEnabled.value = data && data.data && data.data.enable === 1
  } catch (error) {
    publicSquareEnabled.value = false
  }
}

onMounted(() => {
  checkPublicSquare()
})
</script>

<style scoped lang="scss">
// Header 图标按钮（克制设计 - 纯背景反馈）
.icon-item {
  background-color: var(--color-bg-secondary);
  width: 40px;
  height: 40px;
  justify-content: center;
  align-items: center;
  display: inline-flex;
  margin-right: 8px;
  border-radius: 8px;
  box-shadow: var(--shadow-xs);
  cursor: pointer;
  transition: all 0.15s ease;

  i {
    color: var(--color-text-primary);
    transition: none; // 图标颜色不变化
  }
  
  &:hover {
    background-color: var(--hover-overlay);
    box-shadow: var(--shadow-sm);
    // 图标颜色保持不变，只改变背景
  }
}

.icon-item a {
  color: var(--color-text-primary);
}

// 主题切换按钮特殊样式
.theme-toggle-item {
  i {
    color: var(--color-orange);
  }

  [data-theme='dark'] & {
    i {
      color: var(--color-orange);
    }
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

.header-right {
  .icon-item {
    cursor: pointer;
  }

  :deep(.ant-popover) {
    .ant-popover-inner {
      padding: 0;
    }
  }
}
</style>


