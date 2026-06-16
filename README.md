# EnhancedMarkdown_Plus v3.9.1

Typecho 增强版 Markdown 解析器与实时编辑器插件，基于 [**EnhancedMarkdown**](https://blog.qgdnmb.xyz/2026/05/26/Typecho-Markdown.html) 已有的**Parsedown + ParsedownExtra** 解析引擎，并集成 **Editor.MD** ([Github](https://github.com/pandao/editor.md)) 的后台编辑器。

本插件不仅在**前台**提供了 Markdown 增强语法解析，还在**后台**替换了系统默认的编辑器，提供强大的分屏实时预览渲染支持，使编辑器的预览效果与前台展示高度一致。


## 核心特性

### 1. 双栏实时编辑器（Editor.md）
- **分屏预览**：后台撰写和修改文章/页面时，可选择开启分屏实时预览。
- **工具栏增强**：提供丰富的快捷图标（加粗、斜体、引用、标题、分割线、链接、表格等），并内置“插入摘要分割线（`<!--more-->`）”等 Typecho 特色功能。
- **图片粘贴上传**：支持从剪贴板直接粘贴图片上传至博客，并自动生成 Markdown 引用链接。
- **拖放上传/附件同步**：与 Typecho 默认的附件管理器高度同步，支持快捷插入。

### 2. 后台编辑器实时渲染
编辑器的分屏预览不仅支持标准 Markdown 语法，还专门针对插件的增强语法做了**实时后处理渲染**。无需保存或刷新即可直接在预览区域查看：
- **数学公式（KaTeX）**：实时排版渲染行内和块级公式。
- **图表（Mermaid）**：实时转换并渲染流程图、时序图、甘特图等。
- **代码高亮（Prism.js）**：自动为代码块应用精美的暗色主题容器，带有语言类别显示和“一键复制”按钮。
- **提示容器（:::tip）**：实时渲染带图标和不同主题颜色的警告、提示及危险块。
- **自定义小语法**：实时解析上标（`^`）、下标（`~`）、高亮（`==`）、任务列表（`- [x]`）等。

### 3. 全面的前台 Markdown 语法解析
- **标准 Markdown**：标题、粗斜体、链接、图片、列表、引用、分割线等。
- **GFM 扩展**：任务列表、删除线、GFM 完整表格等。
- **Markdown Extra 扩展**：脚注、定义列表、缩写词。
- **自定义增强**：
  - **上标/下标**：`^text^` $\rightarrow$ <sup>text</sup>，`~text~` $\rightarrow$ <sub>text</sub>。
  - **高亮标记**：`==text==` $\rightarrow$ <mark>text</mark>。
  - **TOC 目录**：支持在任意位置写入 `[toc]` 或 `[TOC]` 生成自动层级目录。
  - **图片尺寸**：支持 `![alt](url =WxH)` 语法指定宽高显示。
  - **容器/提示块**：支持 `:::tip/info/warning/danger/note/success/details/quote` 等容器，带自定义标题或默认图标。
  - **数学公式 (KaTeX)**：提供 CDN 网址和本地资源灵活切换，按需在包含公式的页面加载。
  - **代码高亮 (Prism.js)**：内置 300+ 语言着色，同样采用按需加载，零冗余开销。
  - **Mermaid 交互图表**：支持鼠标滚轮缩放、拖拽平移、一键还原视角。


## 插件安装

1. 下载本项目并解压，文件夹重命名为**EnhancedMarkdownPlus**，上传至 Typecho 的 `usr/plugins/` 目录。
2. 登录 Typecho 后台 $\rightarrow$ 控制台 $\rightarrow$ 插件。
3. 找到 **EnhancedMarkdownPlus** 并点击“启用”。
4. 点击“设置”配置各项功能开关和 CDN 路径。


## 文件结构

```
EnhancedMarkdownPlus/
├── Plugin.php          # 插件主入口（Hook注册、后台编辑器生成与渲染）
├── Parser.php          # 增强解析器（继承 ParsedownExtra）
├── Parsedown.php       # Parsedown 核心解析类
├── ParsedownExtra.php  # Parsedown Extra 扩展类
├── css/                # Editor.md 编辑器 CSS 样式文件
├── fonts/              # Font Awesome 字体及编辑器字体
├── images/             # 编辑器所需图片（含 Loading 图）
├── js/                 # Editor.md 及 Emoji 依赖的 JS
├── lib/                # Editor.md 所需的 CodeMirror、marked 等底层依赖
├── plugins/            # Editor.md 插件对话框资源
├── katex/              # KaTeX 数学公式渲染本地资源
├── prism/              # Prism.js 语法高亮本地资源
├── mermaid.min.js      # Mermaid.js 图表渲染本地资源
└── README.md           # 本说明文档
```


## 配置项说明

在后台设置中，您可以对以下选项进行个性化配置：
- **TOC 目录支持**：启用后支持 `[toc]` 标记生成文章层级目录。
- **标题锚点 ID 格式**：可选 Slug 格式（推荐，中文友好且防冲突）或数字格式。
- **语法小工具**：包含任务列表、上标、下标、高亮、提示容器、图片尺寸的开关。
- **数学公式支持 (KaTeX)**：可选择使用**插件本地文件**或配置**自定义 CDN 基础路径**加载核心样式及脚本。
- **Mermaid 图表支持**：支持选择使用本地文件或 CDN 加载 Mermaid。
- **代码语法高亮 (Prism.js)**：一键开启或关闭前端代码高亮渲染。
- **启用 Emoji 表情**：启用后，编辑器内可快速输入 Emoji，前台文章页也将支持表情符号自动转换为图片。


## 更新日志

### v3.9.1 (2026-06-16)
- **后台编辑器替换**：集成 `Editor.md` 分屏 Markdown 编辑器。
- **实时渲染升级**：重构并实现后台编辑器预览区的 `onpreviewed` 渲染逻辑。实时提取并重置 Mermaid 代码块、执行 KaTeX 数学排版、高亮 Prism.js 代码块并渲染暗色顶栏与复制按钮，同时实时转换上标、下标、高亮和自定义警告提示框。
- **图片粘贴支持**：支持后台粘贴图片直接上传并引用。
- **Emoji 支持**：加入 Emoji 配置项，支持前后端一致的表情符号转图片渲染。
- **彻底防报错设计**：全部使用 `EnhancedMarkdownPlus` 命名空间及配置库引用，支持与旧版插件共存而不产生类重定义冲突。


## 许可证

基于 **Apache 2.0** 开源发布。
