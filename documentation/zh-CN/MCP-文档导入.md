# 通过 MCP 导入 PDF / Word / HTML

ShowDoc 的 MCP 服务新增了四个导入工具，可以把文件直接导入成页面，效果与网页端的「导入」按钮完全一致。

| 工具 | 作用 | 产出页面 |
| --- | --- | --- |
| `import_pdf` | 导入 PDF | 内嵌 PDF.js 阅读器页面 |
| `import_word` | 导入 Word（.docx/.doc） | 可在线编辑的 Word 页面 |
| `import_html` | 导入 HTML | 沙箱 iframe 渲染的独立页面 |
| `import_markdown` | 导入 Markdown | 普通文档页面（同名覆盖） |

> 需要重新部署后端并重连 MCP 客户端后，新工具才会出现在工具列表里。

## 通用约定

- 所有工具都需要 `item_id`，且当前 Token 必须对项目有**写权限**
- `cat_id` 可选，不传则导入到根目录
- 同名页面会被覆盖更新（与网页端行为一致）
- Word 文件存储在同名页面对应的 `server/data/word/` 下，不在数据库里
- `import_pdf` / `import_word` 用 `file_name` + `file_base64`（Base64 编码的文件内容）
- 单文件上限 50MB（Base64 编码后字符串会更大）

## 示例

### 导入 PDF

```json
{
  "name": "import_pdf",
  "arguments": {
    "item_id": 123,
    "cat_id": 45,
    "file_name": "验收报告.pdf",
    "file_base64": "<Base64>"
  }
}
```

### 导入 Word

```json
{
  "name": "import_word",
  "arguments": {
    "item_id": 123,
    "file_name": "项目实施方案.docx",
    "file_base64": "<Base64>"
  }
}
```

返回结果中的 `warning` 字段会提示服务端是否已配置 OnlyOffice —— 未配置时页面只能下载，不能在线编辑。

### 导入 HTML

可以直接传源码，省去 Base64 编码：

```json
{
  "name": "import_html",
  "arguments": {
    "item_id": 123,
    "page_title": "部署手册",
    "html": "<!DOCTYPE html><html>...</html>"
  }
}
```

也可以传文件：

```json
{
  "name": "import_html",
  "arguments": {
    "item_id": 123,
    "file_name": "index.html",
    "file_base64": "<Base64>"
  }
}
```

### 导入 Markdown

```json
{
  "name": "import_markdown",
  "arguments": {
    "item_id": 123,
    "cat_id": 45,
    "page_title": "接口说明",
    "content": "# 接口说明\n\n..."
  }
}
```

## 与 upload_attachment 的区别

`upload_attachment` 只是把文件存为**附件**，不会生成可阅读的页面。
如果只是想在文档里挂一个下载链接，用 `upload_attachment`；如果要生成可阅读/可编辑的页面，用上面四个导入工具。

## 返回结构

```json
{
  "page_id": 456,
  "item_id": 123,
  "page_title": "验收报告",
  "type": "pdf",
  "message": "导入成功"
}
```
