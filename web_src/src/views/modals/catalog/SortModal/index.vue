<template>
  <CommonModal
    :show="show"
    :title="t('catalog.sort_nodes')"
    width="620px"
    height="600px"
    @close="handleClose"
  >
    <a-spin :spinning="loading">
      <div class="sort-body">
        <div class="sort-tips">{{ t('catalog.sort_tips') }}</div>

        <div v-if="groups.length === 0" class="sort-empty">
          {{ t('catalog.sort_empty') }}
        </div>

        <div v-for="group in groups" :key="group.parentKey" class="sort-group">
          <div class="group-header">
            <i class="far fa-layer-group"></i>
            <span class="group-title">{{ group.title }}</span>
          </div>
          <SortableList
            handle=".sort-item"
            listClass="sort-list"
            :animate="150"
            @drop="onDropEnd(group.parentKey, $event)"
          >
            <div
              v-for="item in group.items"
              :key="item.key"
              class="sort-item"
            >
              <div
                class="sort-item-left"
                :style="{ paddingLeft: `${(item.level - 1) * 16}px` }"
              >
                <i
                  v-if="item.type === 'folder'"
                  class="far fa-folder-closed sort-folder"
                />
                <i v-else class="far fa-file-lines sort-page" />
                <span class="sort-title" :title="item.title">{{ item.title }}</span>
              </div>
              <div class="sort-order">{{ group.items.indexOf(item) + 1 }}</div>
            </div>
          </SortableList>
        </div>
      </div>
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
        :text="t('common.save')"
        :disabled="loading"
        @click="handleConfirm"
      />
    </template>
  </CommonModal>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import CommonModal from '@/components/CommonModal.vue'
import CommonButton from '@/components/CommonButton.vue'
import SortableList from '@/components/SortableList.vue'
import request from '@/utils/request'
import Message from '@/components/Message'
import AlertModal from '@/components/AlertModal'

interface Props {
  itemId?: number
  treeData?: any[]
}

const props = withDefaults(defineProps<Props>(), {
  itemId: 0,
  treeData: () => []
})

const emit = defineEmits<{
  close: []
}>()

const { t } = useI18n()

const show = ref(true)
const loading = ref(false)

interface SortNode {
  key: string
  title: string
  type: string
  page_id: number
  cat_id: number
  parentCatId: number
  level: number
  children: SortNode[]
}

interface SortGroup {
  parentKey: string
  title: string
  items: SortNode[]
}

const groups = ref<SortGroup[]>([])

const buildGroups = () => {
  const clone: any[] = JSON.parse(JSON.stringify(props.treeData || []))
  const root = { key: 'root', title: t('catalog.sort_top_level'), items: clone }
  const flatNodes = new Map<string, SortNode>()

  const walk = (node: SortNode, level: number) => {
    flatNodes.set(node.key, node)
    node.level = level
    if (node.children) {
      node.children.forEach((child) => walk(child, level + 1))
    }
  }

  const allGroups: SortGroup[] = [{ parentKey: 'root', title: root.title, items: root.items }]
  const queue = [...root.items]
  while (queue.length > 0) {
    const node = queue.shift() as SortNode
    if (node.children && node.children.length > 0) {
      allGroups.push({
        parentKey: node.key,
        title: node.title,
        items: node.children
      })
      queue.push(...node.children)
    }
  }

  groups.value = allGroups
}

const onDropEnd = (parentKey: string, { oldIndex, newIndex }: { oldIndex: number, newIndex: number }) => {
  const group = groups.value.find((g) => g.parentKey === parentKey)
  if (!group) return

  const target = group.items.splice(oldIndex, 1)[0]
  if (target) {
    group.items.splice(newIndex, 0, target)
  }
}

const buildOrderMap = (): Map<string, string[]> => {
  const map = new Map<string, string[]>()
  groups.value.forEach((group) => {
    map.set(
      group.parentKey,
      group.items.map((item) => item.key)
    )
  })
  return map
}

const buildBatUpdatePayload = (): any[] => {
  const root = props.treeData || []
  const result: any[] = []
  const orderMap = buildOrderMap()
  const getChildrenKeys = (parentKey: string): string[] => {
    return orderMap.get(parentKey) || []
  }
  const findNode = (nodes: any[], key: string): any => {
    for (const node of nodes) {
      if (node.key === key) return node
      if (node.children) {
        const found = findNode(node.children, key)
        if (found) return found
      }
    }
    return null
  }

  const pushTreeData = (
    node: any,
    parentCatId: number,
    level: number,
    index: number
  ) => {
    const catId = node.type === 'folder' ? node.cat_id || 0 : 0
    result.push({
      cat_id: catId,
      cat_name: node.title || '',
      page_id: node.page_id || 0,
      parent_cat_id: parentCatId || 0,
      page_cat_id: parentCatId || 0,
      level,
      s_number: index + 1
    })

    const childKeys = getChildrenKeys(node.key)
    childKeys.forEach((childKey, i) => {
      const child = findNode(node.children || [], childKey)
      if (child) {
        pushTreeData(child, catId, level + 1, i)
      }
    })
  }

  const rootChildKeys = getChildrenKeys('root')
  rootChildKeys.forEach((childKey, i) => {
    const child = findNode(root, childKey)
    if (child) {
      pushTreeData(child, 0, 2, i)
    }
  })

  return result
}

const handleConfirm = async () => {
  loading.value = true
  try {
    const cats = buildBatUpdatePayload()
    const result = await request(
      '/api/catalog/batUpdate',
      {
        item_id: props.itemId,
        cats: JSON.stringify(cats)
      },
      'post'
    )

    if (result.error_code === 0) {
      Message.success(t('common.op_success'))
      emit('close')
    } else {
      await AlertModal(result.error_message || t('common.op_failed'))
    }
  } catch (error) {
    console.error('保存排序失败:', error)
    await AlertModal(t('common.op_failed'))
  } finally {
    loading.value = false
  }
}

const handleClose = () => {
  emit('close')
}

onMounted(() => {
  buildGroups()
})
</script>

<style scoped lang="scss">
.sort-body {
  display: flex;
  flex-direction: column;
  gap: 12px;
  overflow-y: auto;
  height: 100%;
}

.sort-tips {
  font-size: 12px;
  color: var(--color-text-secondary);
  padding: 8px 12px;
  background: var(--color-bg-secondary);
  border-radius: 6px;
}

.sort-empty {
  padding: 40px 0;
  text-align: center;
  color: var(--color-text-secondary);
  font-size: 13px;
}

.sort-group {
  .group-header {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--color-active);
    padding: 6px 4px;
    border-bottom: 1px solid var(--color-border);

    i {
      font-size: 12px;
    }
  }

  .sort-list {
    padding: 4px 0;
  }

  .sort-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 7px 12px;
    border-radius: 6px;
    margin: 2px 0;
    cursor: move;
    transition: background-color 0.15s ease;

    &:hover {
      background-color: var(--hover-overlay);
    }

    .sort-item-left {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
      flex: 1;

      .sort-folder {
        color: var(--color-orange);
        font-size: 14px;
        flex-shrink: 0;
      }

      .sort-page {
        color: var(--color-active);
        font-size: 14px;
        flex-shrink: 0;
      }

      .sort-title {
        font-size: 13px;
        color: var(--color-text-primary);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }

    .sort-order {
      flex-shrink: 0;
      font-size: 12px;
      color: var(--color-text-secondary);
      background: var(--color-bg-secondary);
      border-radius: 4px;
      padding: 1px 8px;
      margin-left: 12px;
    }
  }
}
</style>
