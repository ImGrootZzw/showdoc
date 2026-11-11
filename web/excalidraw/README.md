# Excalidraw 静态资源构建说明

`web/excalidraw/` 下的文件是由 `@excalidraw/excalidraw` 单独打包的独立 bundle，
不参与 `web_src` 的 Vite 构建（Excalidraw 是 React 组件，需要与 Vue 主应用隔离）。

## 为什么单独打包

- Excalidraw 依赖 React，而 ShowDoc 前端是 Vue 3，直接引入会污染主应用依赖树
- 体积约 8MB（含 React 运行时），放进主包会拖慢所有页面的首次加载
- 因此打成 IIFE，暴露 `window.ExcalidrawBundle`，由
  `web_src/src/views/item/show/ShowExcalidrawItem/utils/bundleLoader.ts`
  在打开手绘图项目时才按需加载

## 重新构建步骤

```bash
mkdir -p /tmp/exc && cd /tmp/exc
npm init -y
npm i @excalidraw/excalidraw@0.18.1         # react/react-dom 会作为 peer 依赖一并安装
npm i -D esbuild
```

创建 `entry.jsx`（注意 CSS 必须用相对路径引用，包内 exports 未导出该子路径）：

```jsx
import React from 'react'
import { createRoot } from 'react-dom/client'
import * as Exc from '@excalidraw/excalidraw'
import './node_modules/@excalidraw/excalidraw/dist/prod/index.css'

const roots = new WeakMap()
window.ExcalidrawBundle = {
  mount(container, options = {}) { /* 见仓库历史版本 */ },
  unmount(container) { /* ... */ },
  exportToBlob: Exc.exportToBlob,
  exportToSvg: Exc.exportToSvg,
  serializeAsJSON: Exc.serializeAsJSON,
  restoreElements: Exc.restoreElements,
  MIME_TYPES: Exc.MIME_TYPES,
  THEME: Exc.THEME,
}
```

打包命令：

```bash
npx esbuild entry.jsx --bundle --format=iife \
  --outfile=dist/excalidraw.bundle.js \
  --loader:.woff2=file --loader:.woff=file --loader:.ttf=file --loader:.svg=dataurl \
  --asset-names=assets/[name]-[hash] \
  --minify --define:process.env.NODE_ENV='"production"'

cp -r dist/* <showdoc>/web/excalidraw/
```

## 注意事项

- CSS 内的字体引用是 `./assets/xxx.woff2` 相对路径，**必须**保持
  `excalidraw.bundle.css` 与 `assets/` 目录的相对位置不变
- bundle 内没有任何以 `/` 开头的绝对路径，因此支持 ShowDoc 的二级目录部署
- 升级 Excalidraw 版本后，场景 JSON 的 `version` 字段可能变化，
  `restoreElements` 会自动做兼容迁移
