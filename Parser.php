<?php

namespace TypechoPlugin\EnhancedMarkdownPlus;

/**
 * EnhancedMarkdown 增强解析器（基于 Parsedown + ParsedownExtra）
 *
 * 完全基于 Parsedown 解析引擎，支持标准 Markdown + GFM + Extra + 自定义扩展语法。
 * 不再继承 HyperDown，彻底解决占位符冲突和显示故障问题。
 *
 * 支持语法：
 * - 标准 Markdown：标题/加粗/斜体/链接/图片/代码/列表/引用/分割线/表格
 * - GFM 扩展：表格（完整）、删除线 ~~text~~、任务列表 - [x] / - [ ]
 * - Extra 扩展：脚注 [^1]、定义列表、缩写词 *[ABBR]: definition
 * - 自定义扩展：上标 ^text^、下标 ~text~、高亮 ==text==
 * - 自定义扩展：TOC [toc]、容器 :::type、图片尺寸 ![alt](url =WxH)
 *
 * @copyright 2026
 * @license BSD License
 */
class Parser extends \ParsedownExtra
{
    /* ========== 功能开关 ========== */

    /** @var bool TOC目录 */
    protected $tocEnabled = false;

    /** @var bool 任务列表 */
    protected $taskListEnabled = true;

    /** @var bool 上标 ^text^ */
    protected $supEnabled = true;

    /** @var bool 下标 ~text~ */
    protected $subEnabled = true;

    /** @var bool 高亮标记 ==text== */
    protected $markEnabled = true;

    /** @var bool 容器/提示块 :::type */
    protected $containerEnabled = true;

    /** @var bool 图片尺寸 ![alt](url =WxH) */
    protected $imageSizeEnabled = true;

    /** @var bool 数学公式 $...$ 和 $$...$$ */
    protected $mathEnabled = true;

    /** @var bool Mermaid 图表 */
    protected $mermaidEnabled = true;

    /** @var bool 代码语法高亮（Prism.js 前端渲染） */
    protected $highlightEnabled = true;

    /** @var bool 标题 ID 使用 slug 格式（false 时回退到 toc-N 数字格式） */
    protected $slugIdEnabled = true;

    /**
     * 自动解析内部 Markdown 的 HTML 块级标签白名单
     *
     * 列表中的标签内容会自动经过 Markdown 解析器处理，
     * 不在列表中的标签内容保持原样输出。
     *
     * 安全说明：仅包含内容型标签，不包含 <script>/<style>/<pre> 等。
     * 围栏代码块和缩进代码块走独立的解析路径，不受此影响。
     * 数学公式占位符使用 \x00 控制字符，不会被 Parsedown 解析。
     *
     * @since v3.5.0 新增
     * @var array
     */
    protected $autoMarkdownTags = ['center', 'details', 'summary', 'div', 'section', 'article', 'aside', 'main', 'figure', 'figcaption'];

    /* ========== TOC 数据 ========== */

    /** @var array TOC 条目 */
    protected $tocItems = [];

    /**
     * 已使用的标题 ID 集合（用于去重）
     *
     * 当多个标题生成相同 slug 时，自动追加序号后缀避免重复。
     * 每次makeHtml()调用时重置。
     *
     * @since v3.5.2 新增（BUG-3 修复）
     * @var array
     */
    protected $usedHeadingIds = [];

    /** @var string TOC 占位符 */
    const TOC_PLACEHOLDER = "\x00TOC_PLACEHOLDER\x00";

    /** @var string 数学公式块级占位符前缀 */
    const MATH_BLOCK_PREFIX = "\x00MATH_BLOCK_";

    /** @var string 数学公式行内占位符前缀 */
    const MATH_INLINE_PREFIX = "\x00MATH_INLINE_";

    /** @var string 占位符后缀 */
    const MATH_SUFFIX = "\x00";

    /* ========== 数学公式数据 ========== */

    /** @var array 数学公式占位符 → 原始公式内容 */
    protected $mathPlaceholders = [];

    /* ========== 容器类型定义 ========== */

    /** @var array 容器类型 → CSS类名映射 */
    protected static $containerTypes = [
        'tip'     => 'container-tip',
        'info'    => 'container-info',
        'warning' => 'container-warning',
        'danger'  => 'container-danger',
        'note'    => 'container-note',
        'details' => 'container-details',
        'success' => 'container-success',
        'quote'   => 'container-quote',
    ];

    /** @var array 容器类型 → 图标映射 */
    protected static $containerIcons = [
        'tip' => '💡', 'info' => 'ℹ️', 'warning' => '⚠️', 'danger' => '🚫',
        'note' => '📝', 'details' => '📋', 'success' => '✅', 'quote' => '💬',
    ];

    /* ========== 初始化 ========== */

    /**
     * 构造函数：注册自定义语法规则
     */
    public function __construct()
    {
        parent::__construct();

        // GFM 支持：使用父类 Parsedown 的 setter 方法设置换行模式
        // 修复 PHP 8.2+ 弃用警告：原代码 $this->BreaksEnabled（大写 B）与父类
        // protected $breaksEnabled（小写 b）大小写不一致，被当作动态属性创建。
        // 修改日期：2026-05-27
        $this->setBreaksEnabled(true);

        // 注册自定义块级元素
        // ':' 字符开头的行触发容器解析（:::type）
        if (!isset($this->BlockTypes[':'])) {
            $this->BlockTypes[':'] = [];
        }
        // 修复 Bug #2：使用 array_unshift 将 Container 注册为最高优先级
        // ParsedownExtra 已注册 DefinitionList 到 ':' 字符，默认追加 [] 会导致
        // Container 排在 DefinitionList 之后。当容器前紧挨段落时，DefinitionList
        // 会错误拦截 :::info 行（它不检查行内容，只检查前一个 Block 类型）。
        // array_unshift 确保 Container 先被尝试，其正则仅匹配 :::type，不影响正常定义列表。
        // 修改日期：2026-05-26
        array_unshift($this->BlockTypes[':'], 'Container');

        // 注册自定义行内元素
        // '^' 触发上标解析
        $this->InlineTypes['^'] = ['Superscript'];
        // '=' 触发高亮标记解析
        $this->InlineTypes['='] = ['Highlight'];

        // 下标 '~'：ParsedownExtra 已用 ~ 做删除线，我们添加 Subscript
        // 注意优先级：~~删除线~~ 在前，~下标~ 在后
        if (!in_array('Subscript', $this->InlineTypes['~'] ?? [])) {
            $this->InlineTypes['~'][] = 'Subscript';
        }

        // 扩展行内标记字符列表（Parsedown 只扫描此列表中的字符）
        // 原始列表：'!*_&[:<`~\'
        // 新增：^（上标）、=（高亮）
        $this->inlineMarkerList .= '^=';
    }

    /**
     * 配置功能开关
     *
     * @param array $options 键值对：toc, taskList, sup, sub, mark, container, imageSize, math, mermaid
     */
    public function configure(array $options): void
    {
        $map = [
            'toc'       => 'tocEnabled',
            'taskList'  => 'taskListEnabled',
            'sup'       => 'supEnabled',
            'sub'       => 'subEnabled',
            'mark'      => 'markEnabled',
            'container' => 'containerEnabled',
            'imageSize' => 'imageSizeEnabled',
            'math'      => 'mathEnabled',
            'mermaid'   => 'mermaidEnabled',
            'highlight' => 'highlightEnabled',
            'slugId'    => 'slugIdEnabled',
        ];
        foreach ($map as $key => $prop) {
            if (isset($options[$key])) {
                $this->$prop = (bool) $options[$key];
            }
        }
    }

    /**
     * 初始化解析器（保持接口兼容）
     */
    public function init(): void
    {
        // Parsedown 不需要额外初始化，此方法保留为接口兼容
    }

    /**
     * 解析 Markdown 文本为 HTML（主入口）
     *
     * @param string $text Markdown 文本
     * @return string HTML
     */
    public function makeHtml($text): string
    {
        // 重置 TOC 数据、数学公式数据和标题 ID 去重集合
        $this->tocItems = [];
        $this->mathPlaceholders = [];
        $this->usedHeadingIds = [];

        // 预处理：提取 [toc] 标记（在 Parsedown 解析前替换为占位符）
        if ($this->tocEnabled) {
            $text = $this->preprocessTocMarker($text);
        }

        // 预处理：提取数学公式（保护 $...$ 和 $$...$$ 不被 Parsedown 解析）
        if ($this->mathEnabled) {
            $text = $this->preprocessMath($text);
        }

        // Parsedown 核心解析
        $html = parent::text($text);

        // 后处理：TOC 替换
        if ($this->tocEnabled) {
            $html = $this->postprocessToc($html);
        }

        // 后处理：数学公式替换
        if ($this->mathEnabled) {
            $html = $this->postprocessMath($html);
        }

        return $html;
    }

    /**
     * text() 别名（接口兼容）
     */
    public function parse($text): string
    {
        return $this->makeHtml($text);
    }

    /* ========== 预处理/后处理 ========== */

    /**
     * 预处理：将 [toc] 替换为占位符
     */
    protected function preprocessTocMarker(string $text): string
    {
        return preg_replace('/^\s*\[toc\]\s*$/im', self::TOC_PLACEHOLDER, $text);
    }

    /**
     * 后处理：将 TOC 占位符替换为实际 HTML
     *
     * 修复 #2：移除了对 injectHeadingIds() 的调用。
     * blockHeader() / blockSetextHeader() 已在 Parsedown 元素层面设置了 id 属性，
     * Parsedown 渲染时自动输出 <hN id="toc-X">，无需后处理正则注入。
     * 原 injectHeadingIds() 正则存在 `< ` 多余空格 bug 且逻辑冗余，已删除。
     */
    protected function postprocessToc(string $html): string
    {
        // 替换 TOC 占位符
        $tocHtml = $this->renderToc();
        $html = str_replace('<p>' . self::TOC_PLACEHOLDER . '</p>', $tocHtml, $html);
        $html = str_replace(self::TOC_PLACEHOLDER, $tocHtml, $html);

        return $html;
    }

    /* ========== 数学公式预处理/后处理 ========== */

    /**
     * 预处理：将数学公式替换为占位符，保护其不被 Parsedown 解析
     *
     * 处理顺序：先处理块级公式 $$...$$，再处理行内公式 $...$
     * 避免块级公式被误匹配为行内公式。
     *
     * 转义支持：\$ 不会被识别为公式定界符
     *
     * @since v3.3.0 新增
     */
    protected function preprocessMath(string $text): string
    {
        // 1. 块级公式：独占一行的 $$...$$
        //    匹配模式：行首可选空白，$$ 包裹，内容可跨行（非贪婪）
        $text = preg_replace_callback(
            '/^\s*\$\$([\s\S]+?)\$\$\s*$/m',
            function ($matches) {
                $index = count($this->mathPlaceholders);
                $placeholder = self::MATH_BLOCK_PREFIX . $index . self::MATH_SUFFIX;
                $this->mathPlaceholders[$placeholder] = trim($matches[1]);
                return $placeholder;
            },
            $text
        );

        // 2. 行内公式：$...$（非转义的 $）
        //    排除：\$（转义）、$$（已处理）、内容含换行（块级）
        $text = preg_replace_callback(
            '/(?<!\\\\)\$(?!\$)([^\$\n]+?)(?<!\\\\)\$/',
            function ($matches) {
                $index = count($this->mathPlaceholders);
                $placeholder = self::MATH_INLINE_PREFIX . $index . self::MATH_SUFFIX;
                $this->mathPlaceholders[$placeholder] = $matches[1];
                return $placeholder;
            },
            $text
        );

        return $text;
    }

    /**
     * 后处理：将数学公式占位符替换为 HTML 标签
     *
     * 块级公式 → <div class="math-block">公式内容</div>
     * 行内公式 → <span class="math-inline">公式内容</span>
     *
     * 公式内容通过 htmlspecialchars 转义，由前端 KaTeX 渲染。
     *
     * @since v3.3.0 新增
     */
    protected function postprocessMath(string $html): string
    {
        foreach ($this->mathPlaceholders as $placeholder => $formula) {
            $escapedFormula = htmlspecialchars($formula, ENT_QUOTES, 'UTF-8');

            // 判断块级还是行内
            if (strpos($placeholder, 'MATH_BLOCK') !== false) {
                // 块级公式：使用 \[...\] 定界符，与 KaTeX auto-render 的 display 模式匹配
                // 修复 v6.0：原实现缺少定界符，导致 auto-render 无法识别公式内容
                $replacement = '<div class="math-block">\[' . $escapedFormula . '\]</div>';
                $html = str_replace('<p>' . $placeholder . '</p>', $replacement, $html);
                $html = str_replace($placeholder, $replacement, $html);
            } else {
                // 行内公式：使用 \(...\) 定界符，与 KaTeX auto-render 的 inline 模式匹配
                // 修复 v6.0：原实现缺少定界符，导致 auto-render 无法识别公式内容
                $replacement = '<span class="math-inline">\(' . $escapedFormula . '\)</span>';
                $html = str_replace($placeholder, $replacement, $html);
            }
        }

        return $html;
    }

    /* ========== Mermaid 图表 ========== */

    /**
     * 重写围栏代码块初始解析：提取代码块标题参数
     *
     * 从 info string 中提取 title="..." 参数，存储到 Block 数组中
     * 供 blockFencedCodeComplete 使用。
     *
     * 实现：在调用父类解析前，临时移除 title="..." 参数，
     * 使父类正则能正确识别语言标识。解析后恢复原始文本。
     *
     * 安全说明：$Line 是按值传递的数组，修改不影响外部状态。
     *
     * @since v3.5.0 新增
     */
    protected function blockFencedCode($Line)
    {
        // 从行文本提取 title="..." 或 title='...' 参数
        $title = '';
        if (preg_match('/title=["\']([^"\']*)["\']/', $Line['text'], $titleMatch)) {
            $title = $titleMatch[1];
            // 移除 title 参数以便父类正则正确匹配语言标识
            // 父类正则类似: /^`{3,}[ ]*([\w-]+)?[ ]*$/，无法匹配含 title 的 info string
            $Line['text'] = preg_replace('/\s*title=["\'][^"\']*["\']/', '', $Line['text']);
        }

        $Block = parent::blockFencedCode($Line);

        // 将标题存储到 Block 中（供 blockFencedCodeComplete 读取）
        if ($Block !== null && $title !== '') {
            $Block['codeBlockTitle'] = $title;
        }

        return $Block;
    }

    /**
     * 重写围栏代码块完成处理：识别 mermaid 语言标识 + 暗色主题统一包裹
     *
     * 当代码块语言为 mermaid 时，输出 <div class="mermaid"> 而非 <pre><code>，
     * 以便前端 Mermaid.js 自动渲染为图表。
     *
     * 所有非 Mermaid 代码块（无论有无标题）统一包裹为暗色主题容器结构：
     * <div class="code-block-wrapper">
     *   <div class="code-top-bar">（语言名 + 复制按钮 + 可选标题）</div>
     *   <pre class="actual-code-content"><code class="language-xxx">...</code></pre>
     * </div>
     *
     * 修改日期：2026-05-27
     * 修改原因：v3.7.0 代码块暗色主题重构，统一所有代码块视觉外观
     *
     * @since v3.3.0 新增
     * @since v3.7.0 重构为暗色主题统一包裹结构
     */
    protected function blockFencedCodeComplete($Block)
    {
        $Block = parent::blockFencedCodeComplete($Block);

        // Parsedown 围栏代码块结构为 element.element 嵌套：
        //   $Block['element']             → ['name' => 'pre', 'element' => [...]]
        //   $Block['element']['element']  → ['name' => 'code', 'text' => '...', 'attributes' => [...]]
        $innerElement = $Block['element']['element'] ?? [];
        $language = $innerElement['attributes']['class'] ?? '';

        // --- 1. Mermaid 图表处理（保持不变） ---
        if ($this->mermaidEnabled && preg_match('/(?:^|-)mermaid$/', $language)) {
            $code = $innerElement['text'] ?? '';
            if (is_array($code)) {
                $code = $innerElement['handler']['argument'] ?? '';
            }

            $Block['element'] = [
                'name'       => 'div',
                'attributes' => ['class' => 'mermaid'],
                'rawHtml'    => htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
                'allowRawHtmlInSafeMode' => true,
            ];

            return $Block;
        }

        // --- 2. 所有非 Mermaid 代码块：统一包裹暗色主题容器 ---
        // 从 language-xxx 提取语言名用于顶栏显示（首字母大写）
        $langName = '';
        if (preg_match('/language-([\w-]+)$/', $language, $langMatch)) {
            $langName = ucfirst($langMatch[1]);
        }

        // 读取标题（通过 title="xxx" 语法传入）
        $titleText = isset($Block['codeBlockTitle']) && $Block['codeBlockTitle'] !== ''
            ? htmlspecialchars($Block['codeBlockTitle'], ENT_QUOTES, 'UTF-8')
            : '';

        // 构建顶栏 HTML（标题 + 语言名 + 复制按钮）
        $topBarHtml = '<div class="code-top-bar">' . "\n"
            . '<span class="code-title">' . $titleText . '</span>' . "\n"
            . '<button class="lang-copy-btn" onclick="copyCode(this)">' . "\n"
            . '<span class="lang-name">' . $langName . '</span>' . "\n"
            . '<span class="separator">|</span>' . "\n"
            . '<span class="copy-text">复制</span>' . "\n"
            . '</button>' . "\n"
            . '</div>' . "\n";

        // 将 Parsedown 元素结构渲染为 HTML
        $codeHtml = $this->element($Block['element']);

        // 为 <pre> 添加 actual-code-content 类（用于暗色主题样式定位）
        $codeHtml = preg_replace(
            '/<pre><code/',
            '<pre class="actual-code-content"><code',
            $codeHtml
        );

        // 包裹为暗色主题容器
        $Block['element'] = [
            'name'       => 'div',
            'attributes' => ['class' => 'code-block-wrapper'],
            'rawHtml'    => $topBarHtml . $codeHtml,
            'allowRawHtmlInSafeMode' => true,
        ];

        return $Block;
    }

    /* ========== HTML 块级元素内 Markdown 解析 ========== */

    /**
     * 重写 HTML 块级元素完成处理：自动解析白名单标签内的 Markdown
     *
     * 问题：Parsedown 将 HTML 块级元素（<center>、<details> 等）的内容作为原始 HTML 输出，
     * 不解析其中的 Markdown 语法。例如 <center>**加粗**</center> 会原样输出星号。
     *
     * 解决方案：对 $autoMarkdownTags 白名单中的标签，提取其内部内容并通过 text() 解析 Markdown。
     * 仅在内部内容包含 Markdown 语法标记时才调用 text()，纯 HTML 内容跳过解析，
     * 避免不必要的递归调用导致 HTML 实体编码异常。
     *
     * 安全保护：
     * - 白名单机制：仅处理 center/details/summary/div 等内容型标签
     * - 不处理 script/style/pre 等标签
     * - 围栏代码块和缩进代码块走独立的解析路径（blockFencedCode/blockCode），不受影响
     * - 数学公式占位符使用 \x00 控制字符，text() 不会解析
     * - Markdown 语法检测：纯 HTML 内容跳过 text() 调用，避免 HTML 被错误编码
     *
     * DefinitionData 保护：
     * - 与 blockContainerComplete 相同的保存/恢复策略
     * - 防止 text() 内部的 DefinitionData = array() 清空脚注和引用定义
     *
     * 修复 v3.5.1：增加 containsMarkdownSyntax() 检测，纯 HTML 内容不再调用 text()，
     * 解决 <div style="...">文本</div><audio>...</audio> 等 HTML 被错误包裹为
     * <pre><code> 转义输出的问题。
     *
     * @since v3.5.0 新增
     * @since v3.5.1 增加 Markdown 语法检测，纯 HTML 内容跳过 text() 调用
     */
    protected function blockMarkupComplete($Block)
    {
        $Block = parent::blockMarkupComplete($Block);

        if (!$Block || !isset($Block['element']['rawHtml'])) {
            return $Block;
        }

        $rawHtml = $Block['element']['rawHtml'];

        // 检查 rawHtml 是否以白名单标签开头
        foreach ($this->autoMarkdownTags as $tag) {
            $openTagPrefix = '<' . $tag;
            if (stripos($rawHtml, $openTagPrefix) !== 0) {
                continue;
            }

            // 定位开放标签结束位置（跳过属性）
            $openTagEnd = strpos($rawHtml, '>');
            if ($openTagEnd === false) {
                continue;
            }

            // 定位闭合标签位置（使用 strripos 从末尾查找，处理嵌套）
            $closeTag = '</' . $tag . '>';
            $closePos = strripos($rawHtml, $closeTag);
            if ($closePos === false) {
                continue;
            }

            // 提取标签内部内容（未解析的原始文本）
            $innerContent = substr($rawHtml, $openTagEnd + 1, $closePos - $openTagEnd - 1);

            // 内容为空则跳过解析
            if (trim($innerContent) === '') {
                break;
            }

            // v3.5.1 修复：检测内部内容是否包含 Markdown 语法标记
            // 纯 HTML 内容（如 <div>文本</div> 或嵌套 HTML 标签）不需要 Markdown 解析，
            // 直接保留原始 HTML 输出。跳过 text() 可避免 HTML 被实体编码为
            // <...> 并包裹在 <pre><code> 中的问题。
            if (!$this->containsMarkdownSyntax($innerContent)) {
                break;
            }

            // 保存 DefinitionData（防止 text() 内部重置）
            $savedDefinitionData = $this->DefinitionData;

            // 解析内部内容为 Markdown HTML
            $parsedContent = $this->text($innerContent);

            // 合并子解析产生的定义回主数据（与 blockContainerComplete 策略一致）
            if (isset($this->DefinitionData['Footnote']) && isset($savedDefinitionData['Footnote'])) {
                foreach ($this->DefinitionData['Footnote'] as $name => $data) {
                    if (!isset($savedDefinitionData['Footnote'][$name])) {
                        $savedDefinitionData['Footnote'][$name] = $data;
                    }
                }
            }
            if (isset($this->DefinitionData['Reference'])) {
                $savedDefinitionData['Reference'] = array_merge(
                    $this->DefinitionData['Reference'],
                    $savedDefinitionData['Reference'] ?? []
                );
            }
            if (isset($this->DefinitionData['Abbreviation'])) {
                $savedDefinitionData['Abbreviation'] = array_merge(
                    $this->DefinitionData['Abbreviation'],
                    $savedDefinitionData['Abbreviation'] ?? []
                );
            }
            $this->DefinitionData = $savedDefinitionData;

            // 重建 HTML：开放标签 + 解析后内容 + 闭合标签
            $openTag = substr($rawHtml, 0, $openTagEnd + 1);
            $Block['element']['rawHtml'] = $openTag . "\n" . $parsedContent . "\n" . $closeTag;

            break; // 已处理，跳出白名单循环
        }

        return $Block;
    }

    /**
     * 检测文本是否包含 Markdown 语法标记
     *
     * 用于 blockMarkupComplete() 中判断 HTML 块级元素的内部内容是否需要 Markdown 解析。
     * 纯 HTML 内容（不含 Markdown 语法）跳过 text() 调用，避免 HTML 被错误编码。
     *
     * 检测范围：
     * - 行内标记：**粗体**、*斜体*、~~删除线~~、`代码`、[链接](url)、![图片](url)
     * - 自定义标记：^上标^、==高亮==、$公式$
     * - 块级标记：# 标题、-或*或+列表、> 引用、代码块(```)、--- 分割线
     * - Extra 标记：[^脚注]、[引用定义]
     *
     * 注意：此方法为启发式检测，可能存在少量假阴性（漏检），
     * 但漏检的结果是 Markdown 语法不被解析（以原样文本输出），不会导致页面错误。
     *
     * @param string $text 待检测的文本内容
     * @return bool 是否包含 Markdown 语法标记
     *
     * @since v3.5.1 新增
     */
    protected function containsMarkdownSyntax(string $text): bool
    {
        // 去除 HTML 标签后检测纯文本中的 Markdown 标记
        // 避免将 HTML 属性值（如 style="color: #fff"）中的 #、* 等误判为 Markdown
        $plainText = strip_tags($text);

        // 如果去除 HTML 标签后内容为空或仅空白，说明是纯 HTML 结构，无需解析
        if (trim($plainText) === '') {
            return false;
        }

        // 行内 Markdown 标记检测
        // **粗体**、*斜体*、__下划线__、_斜体_
        if (preg_match('/\*\*.+?\*\*|\*.+?\*|__.+?__|_.+?_/', $plainText)) {
            return true;
        }
        // ~~删除线~~
        if (preg_match('/~~.+?~~/', $plainText)) {
            return true;
        }
        // `代码` 和 ``代码``
        if (preg_match('/`[^`]+`/', $plainText)) {
            return true;
        }
        // [链接文字](url) 和 ![图片](url)
        if (preg_match('/!?[[\]].+?[)\]]/', $plainText)) {
            return true;
        }
        // ^上标^
        if (preg_match('/\^[^^\n]+\^/', $plainText)) {
            return true;
        }
        // ==高亮==
        if (preg_match('/==.+?==/', $plainText)) {
            return true;
        }
        // $公式$（行内数学）
        if (preg_match('/(?<!\\\\)\$[^\$\n]+?(?<!\\\\)\$/', $plainText)) {
            return true;
        }

        // 块级 Markdown 标记检测（行首模式）
        // # 标题（# ~ ######）
        if (preg_match('/^#{1,6}\s/m', $plainText)) {
            return true;
        }
        // 无序列表（-、*、+ 后跟空格）
        if (preg_match('/^[-*+]\s/m', $plainText)) {
            return true;
        }
        // 有序列表（1. 等）
        if (preg_match('/^\d+\.\s/m', $plainText)) {
            return true;
        }
        // 引用块（>）
        if (preg_match('/^>\s/m', $plainText)) {
            return true;
        }
        // 围栏代码块（```）
        if (preg_match('/^```/m', $plainText)) {
            return true;
        }
        // 分割线（---、***、___）
        if (preg_match('/^[-*_]{3,}\s*$/m', $plainText)) {
            return true;
        }
        // 脚注引用 [^1]
        if (preg_match('/\[\^.+?\]/', $plainText)) {
            return true;
        }

        return false;
    }

    /* ========== 标题增强（TOC 锚点） ========== */

    /**
     * 重写标题处理：收集 TOC 数据并生成 ID
     *
     * Parsedown 的 heading 处理会调用此方法，我们在其中收集标题信息。
     */
    protected function blockHeader($Line)
    {
        $block = parent::blockHeader($Line);
        if (!$block || !$this->tocEnabled) {
            return $block;
        }

        // 从 element 结构中提取标题级别和文本
        if (isset($block['element'])) {
            $level = (int) substr($block['element']['name'], 1); // h1→1, h2→2...
            $text = $this->extractText($block['element']);

            // 根据配置选择 ID 格式：
            // - slugId=1（默认）：slug 格式，如 "hello-world"（推荐，可读可预测）
            // - slugId=0：旧版数字格式 toc-N（兼容旧主题）
            // 独立运行：两种格式均不依赖主题
            $id = $this->slugIdEnabled
                ? $this->generateSlug($text)
                : 'toc-' . count($this->tocItems);

            $this->tocItems[] = [
                'level' => $level,
                'text'  => $text,
                'id'    => $id,
            ];

            // 注入 ID 属性
            $block['element']['attributes'] = ['id' => $id];
        }

        return $block;
    }

    /**
     * Setext 风格标题（===和---下划线）的 TOC 支持
     *
     * 修复 #1：参数类型声明从 `array $Block = null` 改为 `?array $Block = null`，
     * 与父类 ParsedownExtra::blockSetextHeader($Line, ?array $Block = null) 保持一致，
     * 避免 PHP 8.0+ 环境下因 null 传入 array 类型参数触发 TypeError。
     *
     * @since v3.2.0 修复签名类型不匹配
     */
    protected function blockSetextHeader($Line, ?array $Block = null)
    {
        $block = parent::blockSetextHeader($Line, $Block);
        if (!$block || !$this->tocEnabled) {
            return $block;
        }

        if (isset($block['element'])) {
            $level = (int) substr($block['element']['name'], 1);
            $text = $this->extractText($block['element']);

            // 与 blockHeader() 一致：根据配置选择 ID 格式
            $id = $this->slugIdEnabled
                ? $this->generateSlug($text)
                : 'toc-' . count($this->tocItems);

            $this->tocItems[] = [
                'level' => $level,
                'text'  => $text,
                'id'    => $id,
            ];

            $block['element']['attributes'] = ['id' => $id];
        }

        return $block;
    }

    /**
     * 从标题文本生成 slug 格式 ID（含去重）
     *
     * 生成规则：
     * 1. 去除 HTML 标签，保留纯文本
     * 2. 转为小写
     * 3. 非字母数字字符替换为连字符（中文保留原文）
     * 4. 合并连续连字符，去除首尾连字符
     * 5. 若 ID 已存在则追加 -2、-3 等后缀
     *
     * 示例：
     * - "为什么需要这个插件" → "为什么需要这个插件"
     * - "Hello World" → "hello-world"
     * - "Step 1: 安装" → "step-1-安装"
     * - 重复的 "Hello World" → "hello-world-2"
     *
     * 独立运行：不依赖主题或外部库，纯 PHP 实现
     *
     * @param string $text 标题纯文本
     * @return string 唯一的 slug ID
     *
     * @since v3.5.2 新增（BUG-3 修复）
     */
    protected function generateSlug(string $text): string
    {
        // 1. 去除 HTML 标签（标题可能含行内元素如 code）
        $slug = strip_tags($text);

        // 2. 转为小写（仅影响拉丁字母，中文不受影响）
        $slug = strtolower($slug);

        // 3. 将非字母数字、非中文、非连字符的字符替换为连字符
        //    \p{Han} 匹配中文字符，\p{L} 匹配所有字母（含中文）
        $slug = preg_replace('/[^\p{L}\p{N}-]/u', '-', $slug);

        // 4. 合并连续连字符，去除首尾连字符
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        // 5. 空文本兜底（如纯符号标题）
        if ($slug === '') {
            $slug = 'heading';
        }

        // 6. 去重：若 ID 已使用，追加递增后缀
        $baseSlug = $slug;
        $counter = 2;
        while (isset($this->usedHeadingIds[$slug])) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        // 7. 标记为已使用
        $this->usedHeadingIds[$slug] = true;

        return $slug;
    }

    /**
     * 从 Parsedown element 结构递归提取纯文本
     *
     * Parsedown 的标题元素使用 handler.argument 存储原始文本，
     * 而非直接的 text 属性，因此需要兼容两种结构。
     */
    protected function extractText(array $element): string
    {
        // 直接 text 属性
        if (isset($element['text'])) {
            if (is_array($element['text'])) {
                $texts = [];
                foreach ($element['text'] as $child) {
                    $texts[] = is_array($child) ? $this->extractText($child) : (string) $child;
                }
                return implode('', $texts);
            }
            return (string) $element['text'];
        }

        // Parsedown handler 结构：['handler' => ['function' => 'lineElements', 'argument' => $text, ...]]
        if (isset($element['handler']['argument'])) {
            return (string) $element['handler']['argument'];
        }

        // 嵌套 element
        if (isset($element['element']) && is_array($element['element'])) {
            return $this->extractText($element['element']);
        }

        // content 兜底
        if (isset($element['content'])) {
            return (string) $element['content'];
        }

        return '';
    }

    /**
     * 渲染 TOC 目录 HTML
     *
     * 使用栈结构生成符合 HTML 规范的嵌套（<ul> → <li> → <ul>），
     * 并归一化层级确保相邻级差不超过1，避免标签不匹配。
     *
     * 修复：原实现 $prevLevel 初始值为 0 未考虑根列表已代表第 1 层，
     *       导致 </ul> 闭合不匹配（#1）；<ul> 直接嵌套 <ul> 不符合 HTML 规范（#7）。
     *
     * @since v3.1.0 重写
     */
    protected function renderToc(): string
    {
        if (empty($this->tocItems)) {
            return '';
        }

        $html = '<div class="toc">' . "\n";
        $html .= '<p class="toc-title">目录</p>' . "\n";

        // 归一化标题层级：确保相邻级差不超过 1，防止跳级导致无效嵌套
        $normalizedItems = $this->normalizeTocLevels($this->tocItems);

        // 栈追踪：$depth 表示当前已打开的 <ul> 层数（0 = 根未打开）
        $depth = 0;

        foreach ($normalizedItems as $item) {
            $level = $item['normalizedLevel'];

            if ($level > $depth) {
                // 深入一级：在当前 <li> 内打开子 <ul>
                $cssClass = ($depth === 0) ? 'toc-list' : 'toc-sublist';
                $html .= '<ul class="' . $cssClass . '">' . "\n";
                $depth++;
            } elseif ($level < $depth) {
                // 回退到上层：关闭多余的 <li></ul> 对
                while ($depth > $level) {
                    $html .= "</li>\n</ul>\n";
                    $depth--;
                }
                // 关闭同级的上一个 <li>
                $html .= "</li>\n";
            } else {
                // 同级：关闭上一个 <li>
                $html .= "</li>\n";
            }

            $html .= '<li class="toc-item toc-h' . $item['level'] . '">';
            $html .= '<a href="#' . $item['id'] . '">' . htmlspecialchars($item['text']) . '</a>';
        }

        // 关闭所有剩余标签（从最内层到根）
        while ($depth > 0) {
            $html .= "</li>\n</ul>\n";
            $depth--;
        }

        $html .= "</div>\n";
        return $html;
    }

    /**
     * 归一化 TOC 层级，确保每级差不大于 1
     *
     * 跳级场景（如 H1 直接到 H3）会被压缩为逐级递增（H1 → H2），
     * 保证生成的 HTML 嵌套始终有效。
     *
     * @param array $items 原始 TOC 条目（含 level/text/id）
     * @return array 归一化后的条目（额外含 normalizedLevel）
     */
    protected function normalizeTocLevels(array $items): array
    {
        $result = [];
        $currentDepth = 0;
        $minLevel = min(array_column($items, 'level'));

        foreach ($items as $item) {
            // 相对层级：最低标题为 1
            $relativeLevel = $item['level'] - $minLevel + 1;

            // 每次最多深入一级（防止跳级导致无效 HTML 嵌套）
            if ($relativeLevel > $currentDepth + 1) {
                $normalizedLevel = $currentDepth + 1;
            } else {
                $normalizedLevel = $relativeLevel;
            }

            $currentDepth = $normalizedLevel;
            $item['normalizedLevel'] = $normalizedLevel;
            $result[] = $item;
        }

        return $result;
    }

    /* ========== 容器/提示块 (:::type) ========== */

    /**
     * 容器块：开始匹配 :::type [title]
     */
    protected function blockContainer($Line)
    {
        if (!$this->containerEnabled) {
            return;
        }

        // 匹配 :::type、:::type title 或 :::type[title]
        // v3.5.0 扩展正则支持 Docusaurus Admonition 方括号标题语法 :::note[自定义标题]
        if (preg_match('/^:::([a-zA-Z]+)(?:\[([^\]]*)\])?(?:\s+(.*))?$/', $Line['text'], $matches)) {
            $type = strtolower($matches[1]);
            if (!isset(self::$containerTypes[$type])) {
                return;
            }

            // $matches[2] = 方括号内标题（如 :::note[标题]），$matches[3] = 空格后标题（如 :::note 标题）
            // 方括号标题优先，其次空格标题
            $bracketTitle = isset($matches[2]) && $matches[2] !== '' ? trim($matches[2]) : '';
            $spaceTitle = isset($matches[3]) ? trim($matches[3]) : '';
            $title = $bracketTitle !== '' ? $bracketTitle : $spaceTitle;
            $cssClass = self::$containerTypes[$type];
            $icon = self::$containerIcons[$type];

            return [
                'element' => [
                    'name'       => 'div',
                    'attributes' => ['class' => 'custom-container ' . $cssClass],
                    'text'       => '',
                ],
                'containerTitle' => $title,
                'containerIcon'  => $icon,
                'containerType'  => $type,
                'content'        => '',
                'complete'       => false,
            ];
        }
    }

    /**
     * 容器块：后续行处理
     */
    protected function blockContainerContinue($Line, $Block)
    {
        // 检查闭合标记 :::
        if (isset($Block['complete']) && $Block['complete']) {
            return;
        }

        if (preg_match('/^:::\s*$/', $Line['text'])) {
            // 容器闭合
            $Block['complete'] = true;
            return $Block;
        }

        // 收集内容行
        $Block['content'] .= ($Block['content'] !== '' ? "\n" : '') . $Line['body'];
        return $Block;
    }

    /**
     * 容器块：完成，渲染最终 HTML
     *
     * 修复 #2：保存/恢复 DefinitionData，防止 $this->text() 重置脚注和引用定义。
     *
     * Parsedown::text() 内部会执行 $this->DefinitionData = array()，导致：
     * - 容器外已收集的脚注定义（[^1]: ...）在容器内不可用
     * - 容器内产生的引用定义（[ref]: url）无法在容器外使用
     *
     * 解决方案：在递归调用 text() 前保存 DefinitionData，调用后合并回主数据。
     * 使用 array_merge 保留主数据和子解析各自产生的定义。
     *
     * @since v3.2.0 修复 DefinitionData 重置副作用
     */
    protected function blockContainerComplete($Block)
    {
        // 标题部分
        $titleHtml = '';
        if (!empty($Block['containerTitle'])) {
            $titleHtml = '<p class="container-title">'
                . $Block['containerIcon'] . ' '
                . htmlspecialchars($Block['containerTitle'])
                . '</p>' . "\n";
        }

        // 保存当前 DefinitionData（脚注、引用、缩写定义）
        // 避免被 text() 内部的 $this->DefinitionData = array() 清空
        $savedDefinitionData = $this->DefinitionData;

        // 解析容器内容（支持嵌套 Markdown）
        $contentHtml = $this->text($Block['content'] ?? '');

        // 合并子解析产生的定义回主数据（保留容器内外各自收集的定义）
        // 对于脚注：需保留 count/number 计数状态，以主数据优先
        if (isset($this->DefinitionData['Footnote']) && isset($savedDefinitionData['Footnote'])) {
            // 主数据中的脚注保留原样（含正确的计数状态），补充子解析新增的脚注
            foreach ($this->DefinitionData['Footnote'] as $name => $data) {
                if (!isset($savedDefinitionData['Footnote'][$name])) {
                    $savedDefinitionData['Footnote'][$name] = $data;
                }
            }
        }

        if (isset($this->DefinitionData['Reference'])) {
            // 引用定义：子解析新增的补充进来
            $savedDefinitionData['Reference'] = array_merge(
                $this->DefinitionData['Reference'],
                $savedDefinitionData['Reference'] ?? []
            );
        }

        if (isset($this->DefinitionData['Abbreviation'])) {
            // 缩写定义：子解析新增的补充进来
            $savedDefinitionData['Abbreviation'] = array_merge(
                $this->DefinitionData['Abbreviation'],
                $savedDefinitionData['Abbreviation'] ?? []
            );
        }

        // 恢复合并后的 DefinitionData
        $this->DefinitionData = $savedDefinitionData;

        // 修复 Bug #1：移除 blockContainer() 初始化时设置的空 text 属性
        // Parsedown element() 渲染逻辑中 isset($Element['text']) 优先于
        // isset($Element['rawHtml'])，空字符串 '' 的 text 会导致 rawHtml
        // 分支永远不会执行，容器内容被渲染为空字符串。
        // 修改日期：2026-05-26
        unset($Block['element']['text']);

        $Block['element']['rawHtml'] = $titleHtml . $contentHtml;
        $Block['element']['allowRawHtmlInSafeMode'] = true;

        return $Block;
    }

    /* ========== 行内元素：上标 ^text^ ========== */

    /**
     * 上标标记 ^text^ → <sup>text</sup>
     */
    protected function inlineSuperscript($Excerpt)
    {
        if (!$this->supEnabled) {
            return;
        }

        if (preg_match('/^\^([^\^\n]+)\^/', $Excerpt['text'], $matches)) {
            return [
                'extent'  => strlen($matches[0]),
                'element' => [
                    'name' => 'sup',
                    'text' => $matches[1],
                ],
            ];
        }
    }

    /* ========== 行内元素：下标 ~text~（单个波浪线） ========== */

    /**
     * 下标标记 ~text~ → <sub>text</sub>
     * 仅匹配单个 ~（不匹配 ~~删除线~~）
     */
    protected function inlineSubscript($Excerpt)
    {
        if (!$this->subEnabled) {
            return;
        }

        // 匹配单个 ~ 包裹的内容：前面不是 ~，后面也不是 ~
        if (preg_match('/^~(?!\~)([^~\n]+)(?<!\~)~/', $Excerpt['text'], $matches)) {
            return [
                'extent'  => strlen($matches[0]),
                'element' => [
                    'name' => 'sub',
                    'text' => $matches[1],
                ],
            ];
        }
    }

    /* ========== 行内元素：高亮标记 ==text== ========== */

    /**
     * 高亮标记 ==text== → <mark>text</mark>
     */
    protected function inlineHighlight($Excerpt)
    {
        if (!$this->markEnabled) {
            return;
        }

        if (preg_match('/^={2}([^=\n]+)={2}/', $Excerpt['text'], $matches)) {
            return [
                'extent'  => strlen($matches[0]),
                'element' => [
                    'name' => 'mark',
                    'text' => $matches[1],
                ],
            ];
        }
    }

    /* ========== 图片尺寸增强 ========== */

    /**
     * 重写图片解析：支持 ![alt](url =WxH) 语法
     *
     * 修复：当 URL 与 =WxH 之间存在空格时（如 ![alt](url =300x200)），
     * Parsedown 的 inlineLink URL 正则无法匹配（因为 URL 部分不允许空格），
     * 导致整个图片不被解析。解决方案：在调用父类前预处理 Excerpt，
     * 先剥离尺寸参数，使父类能正常解析标准 Markdown 图片语法，
     * 然后在返回结果中注入 width/height 属性并修正 extent 偏移量。
     *
     * 支持格式：
     *   ![alt](url=300x200)    宽300 高200
     *   ![alt](url =300x200)   空格分隔（修复前不可用）
     *   ![alt](url=300x)       仅宽度
     *   ![alt](url=x200)       仅高度
     */
    protected function inlineImage($Excerpt)
    {
        // 图片尺寸功能未启用时，直接使用父类解析
        if (!$this->imageSizeEnabled) {
            return parent::inlineImage($Excerpt);
        }

        // 预处理：提取并剥离 =WxH 尺寸参数
        $sizeParams = null;
        $originalTextLength = strlen($Excerpt['text']);

        // 精确定位：仅在 ](...) 图片 URL 括号内查找 =WxH，避免误匹配图片后的文本
        // Excerpt['text'] 格式示例："![alt](url =300x200) more text"
        // 1. 找到 ]( 标记图片 URL 起始
        // 2. 找到对应的 ) 标记 URL 结束
        // 3. 在 URL 内容末尾匹配 =WxH 并剥离
        $parenOpen = strpos($Excerpt['text'], '](');
        if ($parenOpen !== false) {
            $parenClose = strpos($Excerpt['text'], ')', $parenOpen + 2);
            if ($parenClose !== false) {
                $urlContent = substr($Excerpt['text'], $parenOpen + 2, $parenClose - $parenOpen - 2);
                // 检查 URL 内容末尾是否有 =WxH 尺寸参数（用 $ 锚定末尾）
                if (preg_match('/\s*=\s*(\d*)(?:x(\d*))\s*$/', $urlContent, $sizeMatch)) {
                    $width = $sizeMatch[1] !== '' ? $sizeMatch[1] : null;
                    $height = $sizeMatch[2] !== '' ? $sizeMatch[2] : null;
                    if ($width || $height) {
                        $sizeParams = ['width' => $width, 'height' => $height];
                    }
                    // 从 URL 内容中移除尺寸参数，保留纯 URL
                    $cleanUrl = preg_replace('/\s*=\s*(\d*)(?:x(\d*))\s*$/', '', $urlContent);
                    // 重建 Excerpt 文本：前缀 + ]( + 清理后URL + ) + 后缀
                    $Excerpt['text'] = substr($Excerpt['text'], 0, $parenOpen + 2)
                        . $cleanUrl . ')'
                        . substr($Excerpt['text'], $parenClose + 1);
                }
            }
        }

        // 调用父类解析标准 Markdown 图片语法
        $inline = parent::inlineImage($Excerpt);
        if (!$inline) {
            return $inline;
        }

        // 修正 extent：补偿被剥离的尺寸参数字符长度
        $strippedLength = $originalTextLength - strlen($Excerpt['text']);
        if ($strippedLength > 0) {
            $inline['extent'] += $strippedLength;
        }

        // 注入 width/height HTML 属性
        if ($sizeParams) {
            if (!isset($inline['element']['attributes'])) {
                $inline['element']['attributes'] = [];
            }
            if ($sizeParams['width']) {
                $inline['element']['attributes']['width'] = $sizeParams['width'];
            }
            if ($sizeParams['height']) {
                $inline['element']['attributes']['height'] = $sizeParams['height'];
            }
        }

        return $inline;
    }

    /* ========== 任务列表 ========== */

    /**
     * 重写列表完成处理：为任务列表项添加 CSS class
     *
     * Parsedown 的列表结构使用 element.elements（li 定义数组），
     * 每个 li 通过 handler.argument 传递文本行给 li() 方法。
     * 此处在列表完成时检查并标记任务列表项。
     */
    protected function blockListComplete(array $Block)
    {
        $Block = parent::blockListComplete($Block);

        if (!$this->taskListEnabled || !isset($Block['element']['elements'])) {
            return $Block;
        }

        // 遍历所有 li 元素，检查是否为任务列表项
        foreach ($Block['element']['elements'] as &$liElement) {
            if (!isset($liElement['handler']['argument'])) {
                continue;
            }
            $args = $liElement['handler']['argument'];
            // 检查第一个非空行是否为任务标记
            foreach ($args as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (preg_match('/^\[(?:x| )\]\s*/i', $line)) {
                    if (!isset($liElement['attributes'])) {
                        $liElement['attributes'] = [];
                    }
                    $liElement['attributes']['class'] = 'task-list-item';
                }
                break;
            }
        }

        return $Block;
    }

    /**
     * 重写 li 处理器：识别并转换任务复选框
     *
     * Parsedown 的 li handler 接收文本行数组，返回渲染后的 Element 数组。
     * 在调用父方法前，检查并替换 [x]/[ ] 标记为 HTML 复选框。
     */
    protected function li($lines)
    {
        $isTask = false;
        $checkboxHtml = '';

        if ($this->taskListEnabled && !empty($lines)) {
            // 查找第一个非空行
            foreach ($lines as $idx => $line) {
                if (trim($line) !== '') {
                    if (preg_match('/^(\[x\])\s*/i', $line, $m)) {
                        $checkboxHtml = '<input type="checkbox" class="task-list-checkbox" checked disabled> ';
                        $lines[$idx] = substr($line, strlen($m[0]));
                        $isTask = true;
                    } elseif (preg_match('/^(\[ \])\s*/', $line, $m)) {
                        $checkboxHtml = '<input type="checkbox" class="task-list-checkbox" disabled> ';
                        $lines[$idx] = substr($line, strlen($m[0]));
                        $isTask = true;
                    }
                    break;
                }
            }
        }

        // 调用父类 li 处理器解析行内容
        $Elements = parent::li($lines);

        // 在元素数组前插入复选框 HTML
        if ($isTask && !empty($Elements)) {
            array_unshift($Elements, [
                'rawHtml' => $checkboxHtml,
                'allowRawHtmlInSafeMode' => true,
            ]);
        }

        return $Elements;
    }
}
