import Component from './index.vue'
import { createModalApp, destroyModalApp } from '@/utils/modalAppFactory'

interface HtmlEditModalOptions {
  itemId: number
  editPageId?: number // 编辑模式：传入 pageId
  copyPageId?: number // 复制模式：传入要复制的 pageId
  catId?: number // 复制/新建时所在目录
  onClose?: (result: boolean) => void
}

export default function (options: HtmlEditModalOptions): Promise<boolean> {
  return new Promise((resolve) => {
    const { app, mountNode } = createModalApp(Component, {
      itemId: options.itemId,
      editPageId: options.editPageId || 0,
      copyPageId: options.copyPageId || 0,
      catId: options.catId || 0,
      onClose: (result: boolean) => {
        resolve(result)
        destroyModalApp(app, mountNode)
      },
    })
    app.mount(mountNode)
  })
}
