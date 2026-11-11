# Word 在线编辑（OnlyOffice）部署说明

ShowDoc 的 Word 上传与在线编辑依赖独立的 **OnlyOffice Document Server**。
ShowDoc 本身只负责存储 `.docx` 文件、鉴权与保存回调，真正的排版渲染和编辑由文档服务器完成。

## 一、架构与数据流

```
浏览器 ──加载 api.js──▶ OnlyOffice Document Server
   │                          │
   │                          │ ① 拉取文档
   │                          ▼
   └──ShowDoc 页面──▶ ShowDoc 后端（/api/word/download，签名 URL）
                              ▲
                              │ ② 保存回调 /api/word/callback
                              │
                              └── 文件落盘 server/data/word/
```

要点：

- 文档文件存放在 `server/data/word/`，**不在静态目录**，无法被直接下载
- OnlyOffice 既需要能被浏览器访问，也需要能反向访问 ShowDoc 的地址
- 保存由文档服务器回调触发，前端不参与写入

## 二、部署 OnlyOffice Document Server

推荐使用 Docker（官方镜像）：

```bash
docker run -i -t -d --name onlyoffice \
  -p 8080:80 \
  --restart=always \
  -e JWT_ENABLED=true \
  -e JWT_SECRET='请替换为随机强密钥' \
  -v /opt/onlyoffice/data:/var/www/onlyoffice/Data \
  -v /opt/onlyoffice/logs:/var/log/onlyoffice \
  onlyoffice/documentserver
```

资源建议：**2 核 CPU、2GB 内存以上**，磁盘预留 40GB。

如果没有 Docker，也可以用官方提供的 Linux 原生安装包（EL8/EL9 支持），但依赖较多，推荐 Docker。

## 三、在 ShowDoc 后台配置

进入 **管理后台 → 系统设置 → 其他设置**，填写：

| 配置项 | 说明 | 示例 |
| --- | --- | --- |
| OnlyOffice 文档服务器地址 | 浏览器可访问的文档服务器地址 | `http://192.168.1.10:8080` |
| OnlyOffice JWT 密钥 | 必须与容器 `JWT_SECRET` 完全一致 | 与启动参数一致 |

留空文档服务器地址即关闭 Word 在线编辑，此时 Word 页面只能下载文件。

## 四、网络与域名要求

1. **浏览器 → 文档服务器**：用户浏览器要能打开 `http://文档服务器:8080`，否则编辑器加载不出来。
2. **文档服务器 → ShowDoc**：文档服务器要能访问 ShowDoc 的 `site_url`，否则拉不到文档、也回调不了保存。
   - 如果 ShowDoc 只在公网可访问，请在后台「站点地址」填公网地址
   - 如果两者不在同一网络，需要打通网络或做端口映射
3. **Nginx 反向代理**：如果文档服务器放在 Nginx 后面，必须关闭对 `Content-Security-Policy` 的过滤，并允许 WebSocket 升级，否则编辑器会白屏。

## 五、升级与备份

- **ShowDoc 升级**：`update.sh` 已排除 `server/data/`，Word 文件不会被覆盖；`install.sh` 也会自动创建并授权该目录
- **数据备份**：除 `Sqlite/showdoc.db.php` 外，还需备份 `server/data/word/`（Word 文档本体）和 `Public/Uploads/`（其他附件）
- **OnlyOffice 升级**：按官方文档升级镜像即可，不影响 ShowDoc 侧数据

## 六、常见问题

**编辑器白屏 / 一直转圈**
检查浏览器控制台是否能加载 `文档服务器/web-apps/apps/api/documents/api.js`，通常是地址填错或防火墙拦截。

**提示"文档无法保存"**
文档服务器访问不到 ShowDoc 的 `callbackUrl`。可在文档服务器容器内执行：
```bash
curl -I http://你的showdoc地址/server/index.php?s=/api/word/status
```

**提示 "The document security token is not correctly formed"**
后台填的 JWT 密钥与文档服务器 `JWT_SECRET` 不一致。

**字体显示与 Word 不一致**
文档服务器缺少对应字体，可在容器内安装字体后重启服务。这是 OnlyOffice 的通用限制，不影响内容正确性。

**中文乱码**
确认文档服务器与 ShowDoc 的字符集一致（均为 UTF-8），一般不会出现。
