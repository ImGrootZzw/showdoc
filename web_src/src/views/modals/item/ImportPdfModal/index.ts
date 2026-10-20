import Component from './index.vue'
import { createModalApp, destroyModalApp } from '@/utils/modalAppFactory'

export default function (props: { itemId: number }): Promise<boolean> {
  return new Promise((resolve) => {
    const { app, mountNode } = createModalApp(Component, {
      ...props,
      onClose: () => {
        resolve(true)
        destroyModalApp(app, mountNode)
      }
    })
    app.mount(mountNode)
  })
}
