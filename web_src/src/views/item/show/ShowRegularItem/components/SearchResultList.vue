<template>
  <div class="search-result-list">
    <div class="result-header">
      <i class="far fa-magnifying-glass"></i>
      <span>{{ t('search_result_title') }}「{{ keyword }}」</span>
      <span class="result-count">({{ results.length }})</span>
    </div>

    <div v-if="results.length === 0" class="result-empty">
      {{ t('search_no_results') }}
    </div>

    <div
      v-for="onePage in results"
      :key="onePage.page_id"
      class="result-item"
      @click="handleClick(onePage)"
    >
      <div class="result-title">
        <i class="far fa-file-lines result-page-icon"></i>
        <TextHighlight :queries="queries">{{ onePage.page_title }}</TextHighlight>
        <span v-if="onePage.cat_name" class="result-path">
          <i class="far fa-folder-closed"></i>{{ onePage.cat_name }}
        </span>
      </div>
      <div class="result-snippet">
        <TextHighlight :queries="queries">{{ snippet(onePage) }}</TextHighlight>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import TextHighlight from '@/components/TextHighlight.vue'

interface Props {
  keyword: string
  results: any[]
}

const props = withDefaults(defineProps<Props>(), {
  keyword: '',
  results: () => []
})

const emit = defineEmits<{
  select: [pageId: number]
}>()

const { t } = useI18n()

const queries = computed(() => (props.keyword ? [props.keyword] : []))

const snippet = (page: any): string => {
  const content: string = page.page_content || ''
  if (!content) return ''

  const plain = content
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/[#>*`\-\[\]()!|]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()

  const keyword = props.keyword.toLowerCase()
  if (!keyword) return plain.slice(0, 160)

  const lower = plain.toLowerCase()
  const pos = lower.indexOf(keyword)
  if (pos < 0) return plain.slice(0, 160)

  const start = pos > 80 ? pos - 80 : 0
  return (start > 0 ? '...' : '') + plain.slice(start, pos + 240) + '...'
}

const handleClick = (page: any) => {
  if (page.page_id) {
    emit('select', Number(page.page_id))
  }
}
</script>

<style scoped lang="scss">
.search-result-list {
  padding: 8px 0 24px;

  .result-header {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 600;
    color: var(--color-text-primary);
    padding: 12px 16px;
    background: var(--color-bg-secondary);
    border-radius: 8px;
    margin-bottom: 12px;

    i {
      color: var(--color-active);
      font-size: 14px;
    }

    .result-count {
      font-size: 12px;
      font-weight: 400;
      color: var(--color-text-secondary);
    }
  }

  .result-empty {
    padding: 40px 0;
    text-align: center;
    color: var(--color-text-secondary);
    font-size: 13px;
  }

  .result-item {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 8px;
    border: 1px solid var(--color-border);
    background: var(--color-bg-primary);
    cursor: pointer;
    transition: all 0.15s ease;

    &:hover {
      border-color: var(--color-active);
      background: var(--hover-overlay);

      .result-title {
        color: var(--color-active);
      }
    }

    .result-title {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
      font-weight: 600;
      color: var(--color-text-primary);
      white-space: nowrap;
      overflow: hidden;

      .result-page-icon {
        color: var(--color-active);
        font-size: 13px;
        flex-shrink: 0;
      }

      .result-path {
        flex-shrink: 0;
        font-size: 12px;
        font-weight: 400;
        color: var(--color-text-secondary);
        background: var(--color-bg-secondary);
        border-radius: 4px;
        padding: 1px 8px;
        margin-left: auto;
        max-width: 45%;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;

        i {
          margin-right: 4px;
          color: var(--color-orange);
        }
      }
    }

    .result-snippet {
      margin-top: 6px;
      font-size: 12px;
      line-height: 1.7;
      color: var(--color-text-secondary);
      display: -webkit-box;
      -webkit-line-clamp: 3;
      -webkit-box-orient: vertical;
      overflow: hidden;
      word-break: break-all;
    }
  }
}
</style>