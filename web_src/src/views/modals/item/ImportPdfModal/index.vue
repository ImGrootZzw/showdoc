<template>
  <CommonModal
    :show="show"
    :title="t('item.import_pdf')"
    width="560px"
    @close="handleClose"
  >
    <a-spin :spinning="loading">
      <a-form layout="vertical">
        <a-form-item :label="t('catalog.target_catalog')">
          <CommonSelector
            v-model:value="catId"
            :options="catalogOptions"
          />
        </a-form-item>

        <a-form-item :label="t('item.import_pdf_files')">
          <div class="upload-area" @click="triggerFileInput">
            <input
              ref="fileInputRef"
              type="file"
              accept=".pdf,application/pdf"
              style="display: none"
              @change="handleFilesChange"
            />
            <i class="far fa-file-pdf"></i>
            <span>{{ t('item.import_pdf_click_upload') }}</span>
          </div>

          <div v-if="fileList.length > 0" class="file-list">
            <div v-for="(f, index) in fileList" :key="index" class="file-item">
              <i class="far fa-file-pdf"></i>
              <span class="file-name" :title="f.name">{{ f.name }}</span>
              <span class="file-size">{{ formatSize(f.size) }}</span>
              <i class="far fa-xmark file-remove" @click.stop="removeFile(index)"></i>
            </div>
          </div>

          <div class="import-tips">
            <i class="far fa-circle-info"></i>
            {{ t('item.import_pdf_tips') }}
          </div>
        </a-form-item>
      </a-form>
    </a-spin>

    <template #footer>
      <CommonButton
        theme="light"
        :text="t('common.cancel')"
        :disabled="loading"
        style="margin-right: 12px"
        @click="handleClose"
      />
      <CommonButton
        theme="dark"
        :text="t('common.confirm')"
        :disabled="loading || fileList.length === 0"
        @click="handleConfirm"
      />
    </template>
  </CommonModal>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import CommonModal from '@/components/CommonModal.vue'
import CommonButton from '@/components/CommonButton.vue'
import CommonSelector from '@/components/CommonSelector.vue'
import request from '@/utils/request'
import Message from '@/components/Message'
import AlertModal from '@/components/AlertModal'

interface Props {
  itemId?: number
}

const props = withDefaults(defineProps<Props>(), {
  itemId: 0
})

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()

const show = ref(true)
const loading = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)
const fileList = ref<File[]>([])
const catId = ref(0)
const catalogs = ref<any[]>([])

const catalogOptions = computed(() => {
  return [
    { label: t('catalog.root_catalog'), value: 0 },
    ...catalogs.value.map((cat) => ({
      label: cat.cat_name,
      value: Number(cat.cat_id || 0)
    }))
  ]
})

const getCatalogList = async () => {
  try {
    const result = await request(
      '/api/catalog/catListName',
      { item_id: props.itemId },
      'post',
      false
    )
    if (result.error_code === 0) {
      catalogs.value = result.data || []
    }
  } catch (error) {
    console.error('获取目录列表失败:', error)
  }
}

const triggerFileInput = () => {
  fileInputRef.value?.click()
}

const handleFilesChange = (event: Event) => {
  const input = event.target as HTMLInputElement
  const files = input.files
  if (files && files.length > 0) {
    fileList.value = [files[0]]
  }
  input.value = ''
}

const removeFile = (index: number) => {
  fileList.value.splice(index, 1)
}

const formatSize = (size: number): string => {
  if (size < 1024) return size + ' B'
  if (size < 1024 * 1024) return (size / 1024).toFixed(1) + ' KB'
  return (size / 1024 / 1024).toFixed(1) + ' MB'
}

const handleConfirm = async () => {
  if (fileList.value.length === 0) {
    await AlertModal(t('item.import_pdf_click_upload'))
    return
  }

  loading.value = true
  try {
    const formData = new FormData()
    formData.append('item_id', String(props.itemId))
    formData.append('cat_id', String(catId.value))
    formData.append('file', fileList.value[0])

    const result = await request('/api/import/auto', formData, 'post', false)

    if (result.error_code === 0) {
      Message.success(t('item.import_pdf_success_msg'))
      emit('close')
    } else {
      await AlertModal(result.error_message || t('common.op_failed'))
    }
  } catch (error) {
    console.error('导入 PDF 失败:', error)
    await AlertModal(t('common.op_failed'))
  } finally {
    loading.value = false
  }
}

const handleClose = () => {
  if (loading.value) return
  emit('close')
}

onMounted(() => {
  getCatalogList()
})
</script>

<style scoped lang="scss">
.upload-area {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 96px;
  border: 1.5px dashed var(--color-border);
  border-radius: 8px;
  cursor: pointer;
  color: var(--color-text-secondary);
  font-size: 13px;
  transition: all 0.15s ease;
  background: var(--color-bg-secondary);

  &:hover {
    border-color: var(--color-active);
    color: var(--color-active);
  }

  i {
    font-size: 20px;
  }
}

.file-list {
  margin-top: 10px;
  max-height: 180px;
  overflow-y: auto;

  .file-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 6px;
    font-size: 13px;
    color: var(--color-text-primary);
    transition: background-color 0.15s ease;

    &:hover {
      background: var(--hover-overlay);
    }

    i.fa-file-pdf {
      color: var(--color-active);
      font-size: 13px;
      flex-shrink: 0;
    }

    .file-name {
      flex: 1;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .file-size {
      flex-shrink: 0;
      font-size: 12px;
      color: var(--color-text-secondary);
    }

    .file-remove {
      flex-shrink: 0;
      cursor: pointer;
      color: var(--color-text-secondary);
      padding: 2px;

      &:hover {
        color: #ff4d4f;
      }
    }
  }
}

.import-tips {
  margin-top: 12px;
  font-size: 12px;
  color: var(--color-text-secondary);
  display: flex;
  align-items: center;
  gap: 6px;

  i {
    color: var(--color-active);
  }
}
</style>
