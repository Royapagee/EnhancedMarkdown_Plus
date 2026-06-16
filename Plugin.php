<?php

/**
 * 增强版Markdown解析器+实时编辑器插件，基于EnhancedMarkdown和EditorMD二次修改，支持后台编辑器实时渲染。
 * 
 * @package EnhancedMarkdownPlus
 * @author 罗伊
 * @version 3.9.1
 * @link https://www.roysgensokyo.space/
 */

namespace TypechoPlugin\EnhancedMarkdownPlus;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Widget\Options;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

// Parsedown 解析引擎（在命名空间外加载，确保全局可用）
require_once __DIR__ . '/Parsedown.php';
require_once __DIR__ . '/ParsedownExtra.php';

/**
 * EnhancedMarkdownPlus 插件主入口类
 */
class Plugin implements PluginInterface
{
    /**
     * 插件资产文件基础路径（相对于插件目录）
     */
    const ASSETS_SUBDIR = 'usr/plugins/EnhancedMarkdownPlus';

    /**
     * 按需加载标志：当前请求是否包含数学公式元素
     */
    private static $hasMath = false;

    /**
     * 按需加载标志：当前请求是否包含 Mermaid 图表元素
     */
    private static $hasMermaid = false;

    /**
     * 按需加载标志：当前请求是否包含代码块元素
     */
    private static $hasCodeBlock = false;

    /**
     * 激活插件
     */
    public static function activate()
    {
        // 文章 Markdown 解析 hook
        \Typecho\Plugin::factory('Widget\Base\Contents')->markdown = [__CLASS__, 'parse'];

        // 评论 Markdown 解析 hook
        \Typecho\Plugin::factory('Widget\Base\Comments')->markdown = [__CLASS__, 'parse'];

        // 自定义 CSS 输出到文章页面头部
        \Typecho\Plugin::factory('Widget\Archive')->header = [__CLASS__, 'injectStyles'];

        // JS 脚本输出到文章页面底部
        \Typecho\Plugin::factory('Widget\Archive')->footer = [__CLASS__, 'injectScripts'];

        // 后台编辑器替换 hook
        \Typecho\Plugin::factory('admin/write-post.php')->richEditor = [__CLASS__, 'editor'];
        \Typecho\Plugin::factory('admin/write-page.php')->richEditor = [__CLASS__, 'editor'];
    }

    /**
     * 停用插件
     */
    public static function deactivate()
    {
    }

    /**
     * 插件配置面板
     */
    public static function config(Form $form)
    {
        // TOC目录开关
        $toc = new Radio(
            'toc',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('TOC目录支持'),
            _t('启用后可使用 [toc] 标记自动生成文章目录，标题自动添加锚点ID')
        );
        $form->addInput($toc);

        // 任务列表开关
        $taskList = new Radio(
            'taskList',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('任务列表支持'),
            _t('启用后支持 - [x] 和 - [ ] 语法生成复选框列表')
        );
        $form->addInput($taskList);

        // 上标开关
        $sup = new Radio(
            'sup',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('上标语法支持'),
            _t('启用后支持 ^text^ 语法生成上标 <sup>text</sup>')
        );
        $form->addInput($sup);

        // 下标开关
        $sub = new Radio(
            'sub',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('下标语法支持'),
            _t('启用后支持 ~text~ 语法生成下标 <sub>text</sub>（注意与删除线 ~~text~~ 的区别）')
        );
        $form->addInput($sub);

        // 高亮标记开关
        $mark = new Radio(
            'mark',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('高亮标记支持'),
            _t('启用后支持 ==text== 语法生成高亮标记 <mark>text</mark>')
        );
        $form->addInput($mark);

        // 容器/提示块开关
        $container = new Radio(
            'container',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('容器/提示块支持'),
            _t('启用后支持 :::tip、:::info、:::warning、:::danger、:::note、:::success 等容器块语法')
        );
        $form->addInput($container);

        // 图片尺寸开关
        $imageSize = new Radio(
            'imageSize',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('图片尺寸支持'),
            _t('启用后支持 ![alt](url =WxH) 语法设置图片宽高')
        );
        $form->addInput($imageSize);

        // 数学公式开关
        $math = new Radio(
            'math',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('数学公式支持（KaTeX）'),
            _t('启用后支持 $...$ 行内公式和 $$...$$ 块级公式，由 KaTeX 前端渲染')
        );
        $form->addInput($math);

        // Mermaid 图表开关
        $mermaid = new Radio(
            'mermaid',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('Mermaid 图表支持'),
            _t('启用后支持 ```mermaid 代码块渲染流程图、时序图、甘特图等')
        );
        $form->addInput($mermaid);

        // 代码语法高亮开关
        $highlight = new Radio(
            'highlight',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('代码语法高亮（Prism.js）'),
            _t('启用后自动为 ```language 代码块添加语法着色，支持 200+ 编程语言。')
        );
        $form->addInput($highlight);

        // KaTeX 资源来源选择
        $katexSource = new Radio(
            'katexSource',
            array('local' => _t('插件本地文件'), 'cdn' => _t('CDN 网址')),
            'local',
            _t('KaTeX 资源来源'),
            _t('选择 KaTeX 数学公式渲染库的加载方式。')
        );
        $form->addInput($katexSource);

        // KaTeX CDN 基础路径
        $katexCdnBase = new Text(
            'katexCdnBase',
            NULL,
            'https://cdn.jsdelivr.net/npm/katex@0.17.0/dist/',
            _t('KaTeX CDN 基础路径'),
            _t('仅在 KaTeX 资源来源选择"CDN 网址"时生效。')
        );
        $form->addInput($katexCdnBase);

        // Mermaid 资源来源选择
        $mermaidSource = new Radio(
            'mermaidSource',
            array('local' => _t('插件本地文件'), 'cdn' => _t('CDN 网址')),
            'local',
            _t('Mermaid 资源来源'),
            _t('选择 Mermaid 图表渲染库的加载方式。')
        );
        $form->addInput($mermaidSource);

        // Mermaid CDN 基础路径
        $mermaidCdnBase = new Text(
            'mermaidCdnBase',
            NULL,
            'https://cdn.jsdelivr.net/npm/mermaid@11.15.0/dist/',
            _t('Mermaid CDN 基础路径'),
            _t('仅在 Mermaid 资源来源选择"CDN 网址"时生效。')
        );
        $form->addInput($mermaidCdnBase);

        // 标题 ID 格式开关
        $slugId = new Radio(
            'slugId',
            array('1' => _t('Slug 格式（推荐）'), '0' => _t('数字格式（toc-N）')),
            '1',
            _t('标题锚点 ID 格式'),
            _t('仅在 TOC 目录启用时生效。')
        );
        $form->addInput($slugId);

        // Emoji 表情支持
        $emoji = new Radio(
            'emoji',
            array('1' => _t('启用'), '0' => _t('禁用')),
            '1',
            _t('启用 Emoji 表情'),
            _t('启用后可在编辑器里插入 Emoji 表情符号，前台会加载 JS 将表情符号转为表情图片')
        );
        $form->addInput($emoji);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 解析 Markdown 文本
     */
    public static function parse($text, $lastResult = null)
    {
        require_once __DIR__ . '/Parser.php';

        static $parser;
        if (empty($parser)) {
            $parser = new Parser();
        }

        $options = Options::alloc()->plugin('EnhancedMarkdownPlus');

        $parser->configure(array(
            'toc'       => $options->toc ?: '0',
            'taskList'  => $options->taskList ?: '0',
            'sup'       => $options->sup ?: '0',
            'sub'       => $options->sub ?: '0',
            'mark'      => $options->mark ?: '0',
            'container' => $options->container ?: '0',
            'imageSize' => $options->imageSize ?: '0',
            'math'      => $options->math ?: '0',
            'mermaid'   => $options->mermaid ?: '0',
            'slugId'    => $options->slugId ?: '0',
            'highlight' => $options->highlight ?: '0',
        ));

        $parser->init();

        $html = $parser->makeHtml($text);

        if ($options->math !== '0') {
            self::$hasMath = self::$hasMath
                || strpos($html, 'class="math-block"') !== false
                || strpos($html, 'class="math-inline"') !== false;
        }
        if ($options->mermaid !== '0') {
            self::$hasMermaid = self::$hasMermaid
                || strpos($html, 'class="mermaid"') !== false;
        }

        if ($options->highlight !== '0') {
            self::$hasCodeBlock = self::$hasCodeBlock
                || preg_match('/<code class="language-[\w-]+"/i', $html);
        }

        $html = preg_replace(
            '/<code class="language-([\w-]+)"/i',
            '<code class="lang-$1"',
            $html
        );

        $html = preg_replace(
            '/<pre class="actual-code-content"><code class="lang-/',
            '<pre class="actual-code-content line-numbers"><code class="lang-',
            $html
        );

        return str_replace(
            '<p><!--more--></p>',
            '<!--more-->',
            $html
        );
    }

    /**
     * 获取资源文件的完整 URL
     */
    private static function getResourceUrl(string $sourceOption, string $cdnBaseOption, string $localPath, string $fileName): string
    {
        $options = Options::alloc()->plugin('EnhancedMarkdownPlus');
        $source = $options->$sourceOption ?: 'local';

        if ($source === 'cdn') {
            $cdnBase = $options->$cdnBaseOption ?: '';
            if (empty($cdnBase)) {
                return '/' . self::ASSETS_SUBDIR . $localPath;
            }
            return rtrim($cdnBase, '/') . '/' . $fileName;
        }

        return '/' . self::ASSETS_SUBDIR . $localPath;
    }

    /**
     * 注入增强语法的 CSS 样式
     */
    public static function injectStyles($header = null, $archive = null)
    {
        $options = Options::alloc()->plugin('EnhancedMarkdownPlus');
        $baseUrl = '/' . self::ASSETS_SUBDIR;

        if ($options->emoji === '1') {
            echo '<link rel="stylesheet" href="' . $baseUrl . '/css/emojify.min.css">' . "\n";
        }

        $css = <<<'STYLE'
<style>
/* EnhancedMarkdown 增强语法样式 */
.task-list-item {
    list-style: none !important;
    margin-left: -1.5em;
}
.task-list-checkbox {
    margin-right: 0.5em;
    vertical-align: middle;
    position: relative;
    top: -1px;
}
.toc {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 6px;
    padding: 15px 20px;
    margin-bottom: 20px;
}
.toc-title {
    font-weight: bold;
    font-size: 1.1em;
    margin: 0 0 10px 0 !important;
    color: #495057;
}
.toc ul {
    list-style: none !important;
    padding-left: 0 !important;
    margin: 0 !important;
}
.toc-list {
    counter-reset: toc-level;
}
.toc-sublist {
    padding-left: 20px !important;
    margin-top: 4px !important;
}
.toc-item {
    padding: 3px 0;
}
.toc-item a {
    color: #0366d6;
    text-decoration: none;
    font-size: 0.95em;
    line-height: 1.6;
}
.toc-item a:hover {
    text-decoration: underline;
}
.toc-h1 { font-weight: bold; }
.toc-h2 { font-weight: 600; }
.toc-h3 { }
.toc-h4 { font-size: 0.9em; color: #666; }

.custom-container {
    padding: 15px 20px;
    border-radius: 6px;
    margin: 16px 0;
    border-left: 4px solid;
}
.custom-container .container-title {
    font-weight: bold;
    margin: 0 0 8px 0 !important;
    font-size: 1em;
}
.custom-container p:last-child {
    margin-bottom: 0;
}
.container-tip {
    background: #e8f5e9;
    border-color: #4caf50;
    color: #2e7d32;
}
.container-tip .container-title { color: #1b5e20; }

.container-info {
    background: #e3f2fd;
    border-color: #2196f3;
    color: #1565c0;
}
.container-info .container-title { color: #0d47a1; }

.container-warning {
    background: #fff8e1;
    border-color: #ff9800;
    color: #e65100;
}
.container-warning .container-title { color: #bf360c; }

.container-danger {
    background: #fce4ec;
    border-color: #f44336;
    color: #c62828;
}
.container-danger .container-title { color: #b71c1c; }

.container-note {
    background: #f3e5f5;
    border-color: #9c27b0;
    color: #6a1b9a;
}
.container-note .container-title { color: #4a148c; }

.container-success {
    background: #e8f5e9;
    border-color: #66bb6a;
    color: #2e7d32;
}
.container-success .container-title { color: #1b5e20; }

.container-details {
    background: #efebe9;
    border-color: #795548;
    color: #4e342e;
}
.container-details .container-title { color: #3e2723; }

.container-quote {
    background: #fafafa;
    border-color: #9e9e9e;
    color: #424242;
}
.container-quote .container-title { color: #212121; }

sup { font-size: 0.75em; vertical-align: super; }
sub { font-size: 0.75em; vertical-align: sub; }
mark {
    background: #fff3a8;
    padding: 1px 4px;
    border-radius: 2px;
}

.math-block {
    margin: 1em 0;
    text-align: center;
    overflow-x: auto;
}

.mermaid {
    margin: 1em 0;
    text-align: center;
}

.mermaid-zoom-wrapper {
    position: relative;
    overflow: hidden;
    margin: 1em 0;
    padding: 0;
    border: none;
    background: transparent;
    cursor: grab;
    touch-action: none;
}
.mermaid-zoom-wrapper:active {
    cursor: grabbing;
}
.mermaid-zoom-wrapper .mermaid {
    margin: 0;
    transform-origin: 0 0;
    transition: none;
    user-select: none;
    -webkit-user-select: none;
}
.mermaid-zoom-wrapper .mermaid.zoom-transition {
    transition: transform 0.3s ease;
}

.mermaid-zoom-reset-btn {
    position: absolute;
    top: 8px;
    right: 8px;
    z-index: 10;
    background: rgba(255, 255, 255, 0.85);
    border: 1px solid rgba(0, 0, 0, 0.15);
    border-radius: 4px;
    padding: 4px;
    cursor: pointer;
    color: #555;
    user-select: none;
    -webkit-user-select: none;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
}
.mermaid-zoom-reset-btn:hover {
    background: rgba(255, 255, 255, 1);
    border-color: #999;
    color: #333;
}
.mermaid-zoom-reset-btn svg {
    width: 18px;
    height: 18px;
}

.footnotes {
    margin-top: 2em;
    padding-top: 1em;
    border-top: 1px solid #e1e4e8;
    font-size: 0.9em;
    color: #586069;
}
.footnotes hr {
    display: none;
}
.footnotes ol {
    padding-left: 1.5em;
}
.footnotes li {
    margin-bottom: 0.4em;
    line-height: 1.6;
}
.footnote-ref {
    font-size: 0.85em;
    vertical-align: super;
    line-height: 0;
}
.footnote-ref a {
    color: #0366d6;
    text-decoration: none;
}
.footnote-ref a:hover {
    text-decoration: underline;
}
.footnote-backref {
    font-size: 0.85em;
    color: #0366d6;
    text-decoration: none;
    margin-left: 4px;
}
.footnote-backref:hover {
    text-decoration: underline;
}

.code-block-wrapper {
    position: relative;
    background-color: #1e1e1e;
    border-radius: 6px;
    padding: 0;
    margin: 20px 0;
    font-family: 'Consolas', 'Courier New', monospace;
}

.code-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 12px;
    background-color: #2d2d2d;
    border-radius: 6px 6px 0 0;
    user-select: none;
}

.code-title {
    color: #a9a9a9;
    font-size: 12px;
    font-weight: normal;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-right: 15px;
}

.lang-copy-btn {
    color: #858585;
    font-size: 12px;
    background: transparent;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: color 0.2s;
    flex-shrink: 0;
}

.lang-copy-btn:hover {
    color: #c4c4c4;
}

.lang-copy-btn .separator {
    margin: 0 2px;
}

.code-block-wrapper pre.actual-code-content {
    padding: 16px;
    margin: 0;
    color: #d4d4d4;
    overflow-x: auto;
    font-size: 14px;
    line-height: 1.5;
    background: transparent;
    border: none;
    border-radius: 0 0 6px 6px;
}
</style>
STYLE;
        echo $css;

        if (self::$hasMath) {
            $katexCssUrl = self::getResourceUrl('katexSource', 'katexCdnBase', '/katex/katex.min.css', 'katex.min.css');
            echo '<link rel="stylesheet" href="' . $katexCssUrl . '">' . "\n";
        }

        if (self::$hasCodeBlock) {
            echo '<link rel="stylesheet" href="' . $baseUrl . '/prism/prism.css">' . "\n";
        }
    }

    /**
     * 注入 KaTeX、Mermaid 和 Prism.js 前端渲染脚本
     */
    public static function injectScripts($footer = null, $archive = null)
    {
        $options = Options::alloc()->plugin('EnhancedMarkdownPlus');
        $baseUrl = '/' . self::ASSETS_SUBDIR;

        if (self::$hasMath) {
            $katexJsUrl = self::getResourceUrl('katexSource', 'katexCdnBase', '/katex/katex.min.js', 'katex.min.js');
            $autoRenderUrl = self::getResourceUrl('katexSource', 'katexCdnBase', '/katex/auto-render.min.js', 'contrib/auto-render.min.js');

            echo '<script defer src="' . $katexJsUrl . '"></script>' . "\n";
            echo '<script defer src="' . $autoRenderUrl . '"'
                . ' onload="renderMathInElement(document.body, {'
                . 'delimiters: ['
                . '{left: \'\\\\(\', right: \'\\\\)\', display: false},'
                . '{left: \'\\\\[\', right: \'\\\\]\', display: true},'
                . '{left: \'$\', right: \'$\', display: false},'
                . '{left: \'$$\', right: \'$$\', display: true}'
                . '],'
                . 'ignoredTags: [\'script\', \'noscript\', \'style\', \'textarea\', \'pre\', \'code\', \'option\']'
                . '});"'
                . '></script>' . "\n";
        }

        if (self::$hasMermaid) {
            $mermaidJsUrl = self::getResourceUrl('mermaidSource', 'mermaidCdnBase', '/mermaid.min.js', 'mermaid.min.js');
            echo '<script src="' . $mermaidJsUrl . '"></script>' . "\n";
            echo '<script>mermaid.initialize({startOnLoad:true,theme:\'default\'});</script>' . "\n";

            // Mermaid 图表缩放交互脚本
            echo '<script>'
                . '(function(){'
                . 'function wrapMermaid(el){'
                . 'if(el.closest(\'.mermaid-zoom-wrapper\'))return;'
                . 'var w=document.createElement(\'div\');'
                . 'w.className=\'mermaid-zoom-wrapper\';'
                . 'var b=document.createElement(\'button\');'
                . 'b.className=\'mermaid-zoom-reset-btn\';'
                . 'b.innerHTML=\'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"></path></svg>\';'
                . 'w.appendChild(b);'
                . 'el.parentNode.insertBefore(w,el);'
                . 'w.appendChild(el);'
                . 'var s=1,tx=0,ty=0,initH=0;'
                . 'function updateTransform(){'
                . 'el.style.transform=\'translate(\'+tx+\'px,\'+ty+\'px) scale(\'+s+\')\';'
                . '}'
                . 'function getLocalPos(e){'
                . 'var rect=w.getBoundingClientRect();'
                . 'var cx=e.touches?e.touches[0].clientX:e.clientX;'
                . 'var cy=e.touches?e.touches[0].clientY:e.clientY;'
                . 'return{x:cx-rect.left,y:cy-rect.top};'
                . '}'
                . 'w.addEventListener(\'wheel\',function(e){'
                . 'e.preventDefault();'
                . 'var p=getLocalPos(e);'
                . 'var f=e.deltaY>0?0.9:1.1;'
                . 'var ns=s*f;'
                . 'if(ns<0.05||ns>20)return;'
                . 'tx=p.x*(1-f)+tx*f;'
                . 'ty=p.y*(1-f)+ty*f;'
                . 's=ns;'
                . 'updateTransform();'
                . '},{passive:false});'
                . 'var dragging=false,dx=0,dy=0;'
                . 'w.addEventListener(\'mousedown\',function(e){'
                . 'if(e.target===b)return;'
                . 'dragging=true;dx=e.clientX;dy=e.clientY;'
                . '});'
                . 'document.addEventListener(\'mousemove\',function(e){'
                . 'if(!dragging)return;'
                . 'tx+=(e.clientX-dx);'
                . 'ty+=(e.clientY-dy);'
                . 'dx=e.clientX;dy=e.clientY;'
                . 'updateTransform();'
                . '});'
                . 'document.addEventListener(\'mouseup\',function(){dragging=false;});'
                . 'w.addEventListener(\'touchstart\',function(e){'
                . 'if(e.touches.length===1&&!pinching){'
                . 'dx=e.touches[0].clientX;dy=e.touches[0].clientY;'
                . '}'
                . '},{passive:true});'
                . 'w.addEventListener(\'touchmove\',function(e){'
                . 'if(e.touches.length===1&&!pinching){'
                . 'e.preventDefault();'
                . 'tx+=(e.touches[0].clientX-dx);'
                . 'ty+=(e.touches[0].clientY-dy);'
                . 'dx=e.touches[0].clientX;dy=e.touches[0].clientY;'
                . 'updateTransform();'
                . '}'
                . '},{passive:false});'
                . 'var lastPinchDist=0,pinching=false,pinchMid={x:0,y:0};'
                . 'w.addEventListener(\'touchstart\',function(e){'
                . 'if(e.touches.length===2){'
                . 'pinching=true;'
                . 'pinchMid={x:(e.touches[0].clientX+e.touches[1].clientX)/2,y:(e.touches[0].clientY+e.touches[1].clientY)/2};'
                . 'lastPinchDist=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);'
                . '}'
                . '},{passive:true});'
                . 'w.addEventListener(\'touchmove\',function(e){'
                . 'if(e.touches.length===2&&pinching){'
                . 'e.preventDefault();'
                . 'var d=Math.hypot(e.touches[0].clientX-e.touches[1].clientX,e.touches[0].clientY-e.touches[1].clientY);'
                . 'if(lastPinchDist>0){'
                . 'var f=d/lastPinchDist;'
                . 'var ns=s*f;'
                . 'if(ns>=0.05&&ns<=20){'
                . 'var rect=w.getBoundingClientRect();'
                . 'var mx=pinchMid.x-rect.left,my=pinchMid.y-rect.top;'
                . 'tx=mx*(1-f)+tx*f;'
                . 'ty=my*(1-f)+ty*f;'
                . 's=ns;'
                . 'updateTransform();'
                . '}'
                . '}'
                . 'lastPinchDist=d;'
                . 'pinchMid={x:(e.touches[0].clientX+e.touches[1].clientX)/2,y:(e.touches[0].clientY+e.touches[1].clientY)/2};'
                . '}'
                . '},{passive:false});'
                . 'w.addEventListener(\'touchend\',function(){pinching=false;lastPinchDist=0;},{passive:true});'
                . 'b.addEventListener(\'click\',function(e){'
                . 'e.stopPropagation();'
                . 's=1;tx=0;ty=0;'
                . 'el.classList.add(\'zoom-transition\');'
                . 'updateTransform();'
                . 'setTimeout(function(){el.classList.remove(\'zoom-transition\');},300);'
                . '});'
                . 'setTimeout(function(){'
                . 'initH=el.getBoundingClientRect().height;'
                . 'w.style.height=initH+\'px\';'
                . '},100);'
                . '}'
                . 'function initZoom(){'
                . 'document.querySelectorAll(\'.mermaid[data-processed]\').forEach(function(el){'
                . 'if(!el.closest(\'.mermaid-zoom-wrapper\'))wrapMermaid(el);'
                . '});'
                . '}'
                . 'initZoom();'
                . 'var obs=new MutationObserver(function(muts){'
                . 'muts.forEach(function(m){'
                . 'm.addedNodes.forEach(function(n){'
                . 'if(n.nodeType===1){'
                . 'if(n.classList&&n.classList.contains(\'mermaid\')&&n.hasAttribute(\'data-processed\')){'
                . 'if(!n.closest(\'.mermaid-zoom-wrapper\'))wrapMermaid(n);'
                . '}'
                . 'var els=n.querySelectorAll?n.querySelectorAll(\'.mermaid[data-processed]\'):[];'
                . 'els.forEach(function(el){'
                . 'if(!el.closest(\'.mermaid-zoom-wrapper\'))wrapMermaid(el);'
                . '});'
                . '}'
                . '});'
                . '});'
                . '});'
                . 'obs.observe(document.body,{childList:true,subtree:true});'
                . 'setTimeout(initZoom,3000);'
                . '})();'
                . '</script>' . "\n";
        }

        if (self::$hasCodeBlock) {
            echo '<script src="' . $baseUrl . '/prism/prism.js"></script>' . "\n";
            echo '<script>'
                . 'function copyCode(button){'
                . 'var wrapper=button.closest(\'.code-block-wrapper\');'
                . 'var codeElement=wrapper.querySelector(\'code\');'
                . 'var copyTextSpan=button.querySelector(\'.copy-text\');'
                . 'navigator.clipboard.writeText(codeElement.innerText).then(function(){'
                . 'var originalText=copyTextSpan.innerText;'
                . 'copyTextSpan.innerText=\'已复制\';'
                . 'setTimeout(function(){copyTextSpan.innerText=originalText},2000)'
                . '})}'
                . '</script>' . "\n";
        }

        if ($options->emoji === '1') {
            echo '<script>window.jQuery || document.write(unescape(\'%3Cscript src="' . $baseUrl . '/lib/jquery.min.js"%3E%3C/script%3E\'));</script>' . "\n";
            echo '<script src="' . $baseUrl . '/js/emojify.min.js"></script>' . "\n";
            echo '<script>'
                . '$(function() {'
                . 'emojify.setConfig({'
                . 'img_dir: "//cdn.staticfile.org/emoji-cheat-sheet/1.0.0",'
                . 'blacklist: {'
                . 'ids: [],'
                . 'classes: ["no-emojify"],'
                . 'elements: ["^script$", "^textarea$", "^pre$", "^code$"]'
                . '}'
                . '});'
                . 'emojify.run();'
                . '});'
                . '</script>' . "\n";
        }
    }

    /**
     * 插入编辑器 (后台编辑页面实时渲染支持)
     */
    public static function editor()
    {
        $options = Helper::options();
        $pluginOptions = Options::alloc()->plugin('EnhancedMarkdownPlus');
        $baseUrl = $options->pluginUrl . '/EnhancedMarkdownPlus';
        
        // CSS 依赖
        echo '<link rel="stylesheet" href="' . $baseUrl . '/css/editormd.min.css" />' . "\n";
        
        if ($pluginOptions->highlight !== '0') {
            echo '<link rel="stylesheet" href="' . $baseUrl . '/prism/prism.css" />' . "\n";
        }
        
        if ($pluginOptions->math !== '0') {
            $katexCssUrl = self::getResourceUrl('katexSource', 'katexCdnBase', '/katex/katex.min.css', 'katex.min.css');
            echo '<link rel="stylesheet" href="' . $katexCssUrl . '" />' . "\n";
        }
        
        // 自定义样式 rules
        ?>
        <style>
        /* EnhancedMarkdown 增强语法样式 */
        .task-list-item { list-style: none !important; margin-left: -1.5em; }
        .task-list-checkbox { margin-right: 0.5em; vertical-align: middle; position: relative; top: -1px; }
        .toc { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 15px 20px; margin-bottom: 20px; }
        .toc-title { font-weight: bold; font-size: 1.1em; margin: 0 0 10px 0 !important; color: #495057; }
        .toc ul { list-style: none !important; padding-left: 0 !important; margin: 0 !important; }
        .toc-list { counter-reset: toc-level; }
        .toc-sublist { padding-left: 20px !important; margin-top: 4px !important; }
        .toc-item { padding: 3px 0; }
        .toc-item a { color: #0366d6; text-decoration: none; font-size: 0.95em; line-height: 1.6; }
        .toc-item a:hover { text-decoration: underline; }
        .toc-h1 { font-weight: bold; }
        .toc-h2 { font-weight: 600; }
        .toc-h3 { }
        .toc-h4 { font-size: 0.9em; color: #666; }
        .custom-container { padding: 15px 20px; border-radius: 6px; margin: 16px 0; border-left: 4px solid; }
        .custom-container .container-title { font-weight: bold; margin: 0 0 8px 0 !important; font-size: 1em; }
        .custom-container p:last-child { margin-bottom: 0; }
        .container-tip { background: #e8f5e9; border-color: #4caf50; color: #2e7d32; }
        .container-tip .container-title { color: #1b5e20; }
        .container-info { background: #e3f2fd; border-color: #2196f3; color: #1565c0; }
        .container-info .container-title { color: #0d47a1; }
        .container-warning { background: #fff8e1; border-color: #ff9800; color: #e65100; }
        .container-warning .container-title { color: #bf360c; }
        .container-danger { background: #fce4ec; border-color: #f44336; color: #c62828; }
        .container-danger .container-title { color: #b71c1c; }
        .container-note { background: #f3e5f5; border-color: #9c27b0; color: #6a1b9a; }
        .container-note .container-title { color: #4a148c; }
        .container-success { background: #e8f5e9; border-color: #66bb6a; color: #2e7d32; }
        .container-success .container-title { color: #1b5e20; }
        .container-details { background: #efebe9; border-color: #795548; color: #4e342e; }
        .container-details .container-title { color: #3e2723; }
        .container-quote { background: #fafafa; border-color: #9e9e9e; color: #424242; }
        .container-quote .container-title { color: #212121; }
        sup { font-size: 0.75em; vertical-align: super; }
        sub { font-size: 0.75em; vertical-align: sub; }
        mark { background: #fff3a8; padding: 1px 4px; border-radius: 2px; }
        .math-block { margin: 1em 0; text-align: center; overflow-x: auto; }
        .mermaid { margin: 1em 0; text-align: center; }
        .mermaid-zoom-wrapper { position: relative; overflow: hidden; margin: 1em 0; padding: 0; border: none; background: transparent; cursor: grab; touch-action: none; }
        .mermaid-zoom-wrapper:active { cursor: grabbing; }
        .mermaid-zoom-wrapper .mermaid { margin: 0; transform-origin: 0 0; transition: none; user-select: none; -webkit-user-select: none; }
        .mermaid-zoom-wrapper .mermaid.zoom-transition { transition: transform 0.3s ease; }
        .mermaid-zoom-reset-btn { position: absolute; top: 8px; right: 8px; z-index: 10; background: rgba(255, 255, 255, 0.85); border: 1px solid rgba(0, 0, 0, 0.15); border-radius: 4px; padding: 4px; cursor: pointer; color: #555; user-select: none; -webkit-user-select: none; line-height: 1; display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; }
        .mermaid-zoom-reset-btn:hover { background: rgba(255, 255, 255, 1); border-color: #999; color: #333; }
        .mermaid-zoom-reset-btn svg { width: 18px; height: 18px; }
        .footnotes { margin-top: 2em; padding-top: 1em; border-top: 1px solid #e1e4e8; font-size: 0.9em; color: #586069; }
        .footnotes hr { display: none; }
        .footnotes ol { padding-left: 1.5em; }
        .footnotes li { margin-bottom: 0.4em; line-height: 1.6; }
        .footnote-ref { font-size: 0.85em; vertical-align: super; line-height: 0; }
        .footnote-ref a { color: #0366d6; text-decoration: none; }
        .footnote-ref a:hover { text-decoration: underline; }
        .footnote-backref { font-size: 0.85em; color: #0366d6; text-decoration: none; margin-left: 4px; }
        .footnote-backref:hover { text-decoration: underline; }
        .code-block-wrapper { position: relative; background-color: #1e1e1e; border-radius: 6px; padding: 0; margin: 20px 0; font-family: 'Consolas', 'Courier New', monospace; }
        .code-top-bar { display: flex; justify-content: space-between; align-items: center; padding: 6px 12px; background-color: #2d2d2d; border-radius: 6px 6px 0 0; user-select: none; }
        .code-title { color: #a9a9a9; font-size: 12px; font-weight: normal; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-right: 15px; }
        .lang-copy-btn { color: #858585; font-size: 12px; background: transparent; border: none; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: color 0.2s; flex-shrink: 0; }
        .lang-copy-btn:hover { color: #c4c4c4; }
        .lang-copy-btn .separator { margin: 0 2px; }
        .code-block-wrapper pre.actual-code-content { padding: 16px; margin: 0; color: #d4d4d4; overflow-x: auto; font-size: 14px; line-height: 1.5; background: transparent; border: none; border-radius: 0 0 6px 6px; }
        
        /* Fix Editor.md preview area background and layout issue in admin */
        .editormd-html-preview, .editormd-preview-container { background: #fff; color: #333; }
        </style>
        <?php
        
        // JS 依赖
        echo '<script>var uploadURL = "' . Helper::security()->index('/action/upload?cid=CID') . '";</script>' . "\n";
        echo '<script src="' . $baseUrl . '/js/editormd.min.js"></script>' . "\n";
        
        if ($pluginOptions->highlight !== '0') {
            echo '<script src="' . $baseUrl . '/prism/prism.js"></script>' . "\n";
        }
        
        if ($pluginOptions->math !== '0') {
            $katexJsUrl = self::getResourceUrl('katexSource', 'katexCdnBase', '/katex/katex.min.js', 'katex.min.js');
            $autoRenderUrl = self::getResourceUrl('katexSource', 'katexCdnBase', '/katex/auto-render.min.js', 'contrib/auto-render.min.js');
            echo '<script src="' . $katexJsUrl . '"></script>' . "\n";
            echo '<script src="' . $autoRenderUrl . '"></script>' . "\n";
        }
        
        if ($pluginOptions->mermaid !== '0') {
            $mermaidJsUrl = self::getResourceUrl('mermaidSource', 'mermaidCdnBase', '/mermaid.min.js', 'mermaid.min.js');
            echo '<script src="' . $mermaidJsUrl . '"></script>' . "\n";
            echo '<script>mermaid.initialize({startOnLoad:false,theme:\'default\'});</script>' . "\n";
        }
        
        ?>
        <script>
        function copyCode(button) {
            var wrapper = button.closest('.code-block-wrapper');
            var codeElement = wrapper.querySelector('code');
            var copyTextSpan = button.querySelector('.copy-text');
            navigator.clipboard.writeText(codeElement.innerText).then(function() {
                var originalText = copyTextSpan.innerText;
                copyTextSpan.innerText = '已复制';
                setTimeout(function() { copyTextSpan.innerText = originalText; }, 2000);
            });
        }

        $(document).ready(function() {
            var textarea = $('#text').parent("p");
            var isMarkdown = $('[name=markdown]').val() ? 1 : 0;
            if (!isMarkdown) {
                var notice = $('<div class="message notice"><?php _e('本文Markdown解析已禁用！'); ?> '
                    + '<button class="btn btn-xs primary yes"><?php _e('启用'); ?></button> '
                    + '<button class="btn btn-xs no"><?php _e('保持禁用'); ?></button></div>')
                    .hide().insertBefore(textarea).slideDown();

                $('.yes', notice).click(function () {
                    notice.remove();
                    $('<input type="hidden" name="markdown" value="1" />').appendTo('.submit');
                });

                $('.no', notice).click(function () {
                    notice.remove();
                });
            }

            $('#text').wrap("<div id='text-editormd'></div>");
            postEditormd = editormd("text-editormd", {
                width: "100%",
                height: 640,
                path: '<?php echo $baseUrl; ?>/lib/',
                toolbarAutoFixed: false,
                htmlDecode: true,
                emoji: <?php echo $pluginOptions->emoji ? 'true' : 'false'; ?>,
                tex: false, // We render math ourselves via auto-render.js in onpreviewed
                toc: true,
                tocm: true,
                taskList: <?php echo $pluginOptions->taskList ? 'true' : 'false'; ?>,
                flowChart: false,
                sequenceDiagram: false,
                toolbarIcons: function () {
                    return ["undo", "redo", "|", "bold", "del", "italic", "quote", "h1", "h2", "h3", "h4", "|", "list-ul", "list-ol", "hr", "|", "link", "reference-link", "image", "code", "preformatted-text", "code-block", "table", "datetime"<?php echo $pluginOptions->emoji ? ', "emoji"' : ''; ?>, "html-entities", "more", "|", "goto-line", "watch", "preview", "fullscreen", "clear", "|", "help", "info", "|", "isMarkdown"]
                },
                toolbarIconsClass: {
                    more: "fa-newspaper-o",
                    isMarkdown: "fa-power-off fun"
                },
                toolbarHandlers: {
                    more: function (cm, icon, cursor, selection) {
                        cm.replaceSelection("<!--more-->");
                    },
                    isMarkdown: function (cm, icon, cursor, selection) {
                        if (!$("div.message.notice").html()) {
                            var isMarkdown = $('[name=markdown]').val() ? $('[name=markdown]').val() : 0;
                            if (isMarkdown == 1) {
                                var notice = $('<div class="message notice"><?php _e('本文Markdown解析已启用！'); ?> '
                                    + '<button class="btn btn-xs no"><?php _e('禁用'); ?></button> '
                                    + '<button class="btn btn-xs primary yes"><?php _e('保持启用'); ?></button></div>')
                                    .hide().insertBefore(textarea).slideDown();

                                $('.yes', notice).click(function () {
                                    notice.remove();
                                });

                                $('.no', notice).click(function () {
                                    notice.remove();
                                    $("[name=markdown]").val(0);
                                    postEditormd.unwatch();
                                });
                            } else {
                                var notice = $('<div class="message notice"><?php _e('本文Markdown解析已禁用！'); ?> '
                                    + '<button class="btn btn-xs primary yes"><?php _e('启用'); ?></button> '
                                    + '<button class="btn btn-xs no"><?php _e('保持禁用'); ?></button></div>')
                                    .hide().insertBefore(textarea).slideDown();

                                $('.yes', notice).click(function () {
                                    notice.remove();
                                    postEditormd.watch();
                                    if (!$("[name=markdown]").val())
                                        $('<input type="hidden" name="markdown" value="1" />').appendTo('.submit');
                                    else
                                        $("[name=markdown]").val(1);
                                });

                                $('.no', notice).click(function () {
                                    notice.remove();
                                });
                            }
                        }
                    }
                },
                lang: {
                    toolbar: {
                        more: "插入摘要分隔符",
                        isMarkdown: "非Markdown模式"
                    }
                },
                onpreviewed: function() {
                    var previewEl = this.preview[0];
                    var html = previewEl.innerHTML;
                    
                    // 1. Process custom containers :::tip etc.
                    var containerTypes = {
                        'tip': '💡', 'info': 'ℹ️', 'warning': '⚠️', 'danger': '🚫',
                        'note': '📝', 'details': '📋', 'success': '✅', 'quote': '💬'
                    };
                    var containerClasses = {
                        'tip': 'container-tip', 'info': 'container-info', 'warning': 'container-warning',
                        'danger': 'container-danger', 'note': 'container-note', 'details': 'container-details',
                        'success': 'container-success', 'quote': 'container-quote'
                    };
                    
                    // Replace opening container tags
                    html = html.replace(/<p>\s*:::([a-zA-Z]+)(?:\[([^\]]*)\])?(?:\s+(.*))?\s*<\/p>/g, function(match, type, bracketTitle, spaceTitle) {
                        var lowerType = type.toLowerCase();
                        if (containerClasses[lowerType]) {
                            var title = bracketTitle || spaceTitle || '';
                            var icon = containerTypes[lowerType] || '';
                            var titleHtml = title ? '<p class="container-title">' + icon + ' ' + title + '</p>' : '';
                            return '<div class="custom-container ' + containerClasses[lowerType] + '">' + titleHtml;
                        }
                        return match;
                    });
                    
                    // Replace closing container tags
                    html = html.replace(/<p>\s*:::\s*<\/p>/g, '</div>');
                    
                    // 2. Superscript, Subscript, Highlight
                    html = html.replace(/\^([^\^\n]+)\^/g, '<sup>$1</sup>');
                    html = html.replace(/~([^~\n]+)~/g, '<sub>$1</sub>');
                    html = html.replace(/==([^=\n]+)==/g, '<mark>$1</mark>');
                    
                    previewEl.innerHTML = html;

                    // 3. Process Mermaid blocks
                    $(previewEl).find('pre code.lang-mermaid, pre code.language-mermaid').each(function() {
                        var $code = $(this);
                        var $pre = $code.parent();
                        var codeText = $code.text();
                        var $mermaidDiv = $('<div class="mermaid"></div>').text(codeText);
                        $pre.replaceWith($mermaidDiv);
                    });
                    
                    if (window.mermaid && <?php echo $pluginOptions->mermaid ? 'true' : 'false'; ?>) {
                        try {
                            mermaid.init(undefined, $(previewEl).find('.mermaid:not([data-processed])'));
                        } catch (e) {
                            console.error("Mermaid error:", e);
                        }
                    }
                    
                    // 4. Process Prism blocks (Syntax Highlighting)
                    $(previewEl).find('pre code').each(function() {
                        var $code = $(this);
                        if ($code.closest('.code-block-wrapper').length > 0 || $code.hasClass('lang-mermaid') || $code.hasClass('language-mermaid')) {
                            return;
                        }
                        var $pre = $code.parent();
                        var className = $code.attr('class') || '';
                        var langName = '';
                        var match = className.match(/(?:lang|language)-([\w-]+)/);
                        if (match) {
                            langName = match[1].charAt(0).toUpperCase() + match[1].slice(1);
                        }
                        $pre.addClass('actual-code-content line-numbers');
                        var $wrapper = $('<div class="code-block-wrapper"></div>');
                        $pre.wrap($wrapper);
                        var $topBar = $('<div class="code-top-bar">'
                            + '<span class="code-title"></span>'
                            + '<button class="lang-copy-btn" onclick="copyCode(this)">'
                            + '<span class="lang-name">' + langName + '</span>'
                            + '<span class="separator">|</span>'
                            + '<span class="copy-text">复制</span>'
                            + '</button>'
                            + '</div>');
                        $pre.before($topBar);
                    });
                    
                    if (window.Prism && <?php echo $pluginOptions->highlight ? 'true' : 'false'; ?>) {
                        try {
                            Prism.highlightAllUnder(previewEl);
                        } catch (e) {
                            console.error("Prism error:", e);
                        }
                    }

                    // 5. Process KaTeX
                    if (window.renderMathInElement && <?php echo $pluginOptions->math ? 'true' : 'false'; ?>) {
                        try {
                            renderMathInElement(previewEl, {
                                delimiters: [
                                    {left: '\\(', right: '\\)', display: false},
                                    {left: '\\[', right: '\\]', display: true},
                                    {left: '$', right: '$', display: false},
                                    {left: '$$', right: '$$', display: true}
                                ],
                                ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code', 'option']
                            });
                        } catch (e) {
                            console.error("KaTeX error:", e);
                        }
                    }
                }
            });

            // Image & attachment inserting optimization
            Typecho.insertFileToEditor = function (file, url, isImage) {
                var html = isImage ? '![' + file + '](' + url + ')' : '[' + file + '](' + url + ')';
                postEditormd.insertValue(html);
            };

            // Clipboard paste uploading support
            $(document).on('paste', function(event) {
                event = event.originalEvent;
                var cbd = event.clipboardData;
                var ua = window.navigator.userAgent;
                if (!(event.clipboardData && event.clipboardData.items)) {
                    return;
                }

                if (cbd.items && cbd.items.length === 2 && cbd.items[0].kind === "string" && cbd.items[1].kind === "file" &&
                    cbd.types && cbd.types.length === 2 && cbd.types[0] === "text/plain" && cbd.types[1] === "Files" &&
                    ua.match(/Macintosh/i) && Number(ua.match(/Chrome\/(\d{2})/i)[1]) < 49){
                    return;
                }

                var itemLength = cbd.items.length;
                if (itemLength == 0) return;
                if (itemLength == 1 && cbd.items[0].kind == 'string') return;

                if ((itemLength == 1 && cbd.items[0].kind == 'file') || itemLength > 1) {
                    for (var i = 0; i < cbd.items.length; i++) {
                        var item = cbd.items[i];
                        if (item.kind == "file") {
                            var blob = item.getAsFile();
                            if (blob.size === 0) return;
                            var ext = 'jpg';
                            switch(blob.type) {
                                case 'image/jpeg':
                                case 'image/pjpeg':
                                    ext = 'jpg';
                                    break;
                                case 'image/png':
                                    ext = 'png';
                                    break;
                                case 'image/gif':
                                    ext = 'gif';
                                    break;
                            }
                            var formData = new FormData();
                            formData.append('blob', blob, Math.floor(new Date().getTime() / 1000) + '.' + ext);
                            var uploadingText = '![图片上传中(' + i + ')...]';
                            var uploadFailText = '![图片上传失败(' + i + ')]';
                            postEditormd.insertValue(uploadingText);
                            $.ajax({
                                method: 'post',
                                url: uploadURL.replace('CID', $('input[name="cid"]').val()),
                                data: formData,
                                contentType: false,
                                processData: false,
                                success: function(data) {
                                    if (data[0]) {
                                        postEditormd.setValue(postEditormd.getValue().replace(uploadingText, '![](' + data[0] + ')'));
                                    } else {
                                        postEditormd.setValue(postEditormd.getValue().replace(uploadingText, uploadFailText));
                                    }
                                },
                                error: function() {
                                    postEditormd.setValue(postEditormd.getValue().replace(uploadingText, uploadFailText));
                                }
                            });
                        }
                    }
                }
            });
        });
        </script>
        <?php
    }
}
