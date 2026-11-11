/**
 * Excalidraw 资源懒加载器
 *
 * Excalidraw 是 React 组件，且体积较大（约 8MB，含 React 运行时），
 * 因此单独打包为静态资源放在 web/excalidraw/ 下，只在打开手绘图项目时按需加载，
 * 不影响 ShowDoc 其他页面的加载速度。
 */

import { ref } from 'vue'
import { getStaticPath } from '@/utils/system'

export interface ExcalidrawBundle {
  mount: (container: HTMLElement, options?: any) => any
  unmount: (container: HTMLElement) => void
  getApi: (container: HTMLElement) => any
  exportToBlob: (opts: any) => Promise<Blob>
  exportToSvg: (opts: any) => Promise<SVGSVGElement>
  serializeAsJSON: (elements: any[], appState: any, type?: string) => string
  restore: (data: any, localAppState?: any, opts?: any) => any
  restoreElements: (elements: any[] | null, localElements?: any, opts?: any) => any[]
  MIME_TYPES: Record<string, string>
  THEME: Record<string, string>
}

// 全局只加载一次
let loadPromise: Promise<ExcalidrawBundle> | null = null

const BASE_DIR = 'excalidraw/'

export function useExcalidrawLoader() {
  const isLoaded = ref(!!(window as any).ExcalidrawBundle)
  const isLoading = ref(false)
  const loadError = ref('')

  const resolveUrl = (file: string) => {
    const base = getStaticPath() || './'
    const normalized = base.endsWith('/') ? base : base + '/'
    return `${normalized}${BASE_DIR}${file}`
  }

  const loadStylesheet = (): Promise<void> => {
    const href = resolveUrl('excalidraw.bundle.css')
    if (document.querySelector(`link[data-excalidraw-css="1"]`)) {
      return Promise.resolve()
    }
    return new Promise((resolve, reject) => {
      const link = document.createElement('link')
      link.rel = 'stylesheet'
      link.href = href
      link.dataset.excalidrawCss = '1'
      link.onload = () => resolve()
      link.onerror = () => reject(new Error(`Failed to load ${href}`))
      document.head.appendChild(link)
    })
  }

  const loadScript = (): Promise<void> => {
    const src = resolveUrl('excalidraw.bundle.js')
    return new Promise((resolve, reject) => {
      const existing = document.querySelector(
        `script[data-excalidraw-bundle="1"]`
      ) as HTMLScriptElement | null
      if (existing) {
        // 已经插入过：等它加载完
        if ((window as any).ExcalidrawBundle) {
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
      script.dataset.excalidrawBundle = '1'
      script.onload = () => resolve()
      script.onerror = () => reject(new Error(`Failed to load ${src}`))
      document.head.appendChild(script)
    })
  }

  const load = (): Promise<ExcalidrawBundle> => {
    const bundle = (window as any).ExcalidrawBundle as ExcalidrawBundle | undefined
    if (bundle) {
      isLoaded.value = true
      return Promise.resolve(bundle)
    }
    if (loadPromise) return loadPromise

    isLoading.value = true
    loadError.value = ''

    loadPromise = (async () => {
      await loadStylesheet()
      await loadScript()
      const loaded = (window as any).ExcalidrawBundle as ExcalidrawBundle
      if (!loaded) {
        throw new Error('Excalidraw bundle 未正确初始化')
      }
      isLoaded.value = true
      return loaded
    })()
      .catch((error) => {
        // 失败后允许重试
        loadPromise = null
        loadError.value = error?.message || String(error)
        throw error
      })
      .finally(() => {
        isLoading.value = false
      })

    return loadPromise
  }

  return { isLoaded, isLoading, loadError, load }
}
