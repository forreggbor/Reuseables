/**
 * WYSIWYGEditor - Lightweight rich text editor without external dependencies
 *
 * A simple, customizable WYSIWYG editor that transforms a textarea into a rich text editor
 * using native browser APIs (contenteditable, execCommand).
 *
 * @package WYSIWYGEditor
 * @version 2.2.2
 * @license MIT
 */
class WYSIWYGEditor {
    /**
     * Whether styles have been injected into the document
     * @type {boolean}
     */
    static stylesInjected = false;

    /**
     * Default configuration options
     * @type {Object}
     */
    static defaults = {
        toolbar: [
            'bold', 'italic', 'underline', 'strikethrough', 'subscript', 'superscript', '|',
            'fontSize', 'fontName', '|',
            'textColor', 'bgColor', '|',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', '|',
            'ul', 'ol', 'blockquote', 'pre', '|',
            'link', 'unlink', '|',
            'alignLeft', 'alignCenter', 'alignRight', 'justifyFull', '|',
            'indent', 'outdent', '|',
            'hr', 'table', 'image', '|',
            'undo', 'redo', '|',
            'clearFormat', 'codeView'
        ],
        placeholder: '',
        pasteAsPlainText: false,
        minHeight: '200px',
        maxHeight: null,
        onChange: null,
        onFocus: null,
        onBlur: null,
        shortcuts: true,
        classPrefix: 'wysiwyg',
        linkTargetBlank: true,
        fontSizes: ['12px', '14px', '16px', '18px', '20px', '24px', '32px', '48px'],
        fontFamilies: [
            { label: 'Arial', value: 'Arial, sans-serif' },
            { label: 'Times New Roman', value: '"Times New Roman", serif' },
            { label: 'Georgia', value: 'Georgia, serif' },
            { label: 'Courier New', value: '"Courier New", monospace' },
            { label: 'Verdana', value: 'Verdana, sans-serif' },
            { label: 'Trebuchet MS', value: '"Trebuchet MS", sans-serif' }
        ],
        colorPalette: [
            '#000000', '#434343', '#666666', '#999999', '#cccccc', '#ffffff',
            '#ff0000', '#ff9900', '#ffff00', '#00ff00', '#00ffff', '#0000ff',
            '#9900ff', '#ff00ff', '#f4cccc', '#fce5cd', '#fff2cc', '#d9ead3',
            '#d0e0e3', '#cfe2f3', '#d9d2e9', '#ead1dc'
        ],
        tableDefaults: { rows: 3, cols: 3 },
        imageUpload: true,
        maxImageSize: 5242880,
        allowedImageTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
    };

    /**
     * Toolbar button definitions
     * @type {Object}
     */
    static toolbarButtons = {
        bold: { icon: '<b>B</b>', title: 'Bold (Ctrl+B)', command: 'bold' },
        italic: { icon: '<i>I</i>', title: 'Italic (Ctrl+I)', command: 'italic' },
        underline: { icon: '<u>U</u>', title: 'Underline (Ctrl+U)', command: 'underline' },
        strikethrough: { icon: '<s>S</s>', title: 'Strikethrough', command: 'strikeThrough' },
        subscript: { icon: 'X<sub>2</sub>', title: 'Subscript', command: 'subscript', custom: true },
        superscript: { icon: 'X<sup>2</sup>', title: 'Superscript', command: 'superscript', custom: true },
        h1: { icon: 'H1', title: 'Heading 1', command: 'formatBlock', value: 'h1' },
        h2: { icon: 'H2', title: 'Heading 2', command: 'formatBlock', value: 'h2' },
        h3: { icon: 'H3', title: 'Heading 3', command: 'formatBlock', value: 'h3' },
        h4: { icon: 'H4', title: 'Heading 4', command: 'formatBlock', value: 'h4' },
        h5: { icon: 'H5', title: 'Heading 5', command: 'formatBlock', value: 'h5' },
        h6: { icon: 'H6', title: 'Heading 6', command: 'formatBlock', value: 'h6' },
        blockquote: { icon: '&#8220;', title: 'Block Quote', command: 'formatBlock', value: 'blockquote' },
        pre: { icon: '&#9001;/&#9002;', title: 'Preformatted Block', command: 'formatBlock', value: 'pre' },
        ul: { icon: '&#8226;', title: 'Bullet List', command: 'insertUnorderedList' },
        ol: { icon: '1.', title: 'Numbered List', command: 'insertOrderedList' },
        hr: { icon: '&#8213;', title: 'Horizontal Rule', command: 'insertHorizontalRule' },
        link: { icon: '&#128279;', title: 'Insert Link (Ctrl+K)', command: 'link', custom: true },
        unlink: { icon: '&#10060;', title: 'Remove Link', command: 'unlink' },
        alignLeft: { icon: '&#8676;', title: 'Align Left', command: 'justifyLeft' },
        alignCenter: { icon: '&#8596;', title: 'Align Center', command: 'justifyCenter' },
        alignRight: { icon: '&#8677;', title: 'Align Right', command: 'justifyRight' },
        justifyFull: { icon: '&#9776;', title: 'Justify', command: 'justifyFull' },
        indent: { icon: '&#8680;', title: 'Increase Indent', command: 'indent', custom: true },
        outdent: { icon: '&#8678;', title: 'Decrease Indent', command: 'outdent', custom: true },
        undo: { icon: '&#8617;', title: 'Undo (Ctrl+Z)', command: 'undo' },
        redo: { icon: '&#8618;', title: 'Redo (Ctrl+Y)', command: 'redo' },
        clearFormat: { icon: 'T&#824;', title: 'Clear Formatting', command: 'removeFormat', custom: true },
        fontSize: { icon: 'A<small>&#9662;</small>', title: 'Font Size', command: 'fontSize', custom: true, type: 'dropdown' },
        fontName: { icon: 'F<small>&#9662;</small>', title: 'Font Family', command: 'fontName', custom: true, type: 'dropdown' },
        textColor: { icon: '<span style="border-bottom:3px solid #000">A</span>', title: 'Text Color', command: 'foreColor', custom: true, type: 'colorPicker' },
        bgColor: { icon: '<span style="background:#ff0;padding:0 2px">A</span>', title: 'Background Color', command: 'backColor', custom: true, type: 'colorPicker' },
        table: { icon: '&#9638;', title: 'Insert Table', command: 'insertTable', custom: true },
        image: { icon: '&#128247;', title: 'Insert Image', command: 'insertImage', custom: true },
        codeView: { icon: '&lt;/&gt;', title: 'View HTML Source', command: 'codeView', custom: true },
        '|': { type: 'separator' }
    };

    /**
     * Embedded CSS styles
     * @type {string}
     */
    static styles = `
        .wysiwyg-wrapper {
            border: 1px solid #ccc;
            border-radius: 4px;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .wysiwyg-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            padding: 8px;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
        }
        .wysiwyg-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            padding: 4px 8px;
            border: 1px solid transparent;
            border-radius: 4px;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            color: #333;
            transition: background-color 0.15s, border-color 0.15s;
        }
        .wysiwyg-btn:hover {
            background: #e0e0e0;
            border-color: #ccc;
        }
        .wysiwyg-btn:active {
            background: #d0d0d0;
        }
        .wysiwyg-btn-active {
            background: #d0d0d0;
            border-color: #999;
        }
        .wysiwyg-btn-disabled {
            opacity: 0.4;
            pointer-events: none;
        }
        .wysiwyg-separator {
            display: inline-block;
            width: 1px;
            height: 24px;
            margin: 4px 6px;
            background: #ccc;
        }
        .wysiwyg-editor {
            position: relative;
            padding: 12px;
            min-height: 200px;
            outline: none;
            overflow-y: auto;
            background: #fff;
            line-height: 1.6;
        }
        .wysiwyg-editor:empty:before {
            content: attr(data-placeholder);
            color: #999;
            pointer-events: none;
        }
        .wysiwyg-editor p {
            margin: 0 0 1em 0;
        }
        .wysiwyg-editor p:last-child {
            margin-bottom: 0;
        }
        .wysiwyg-editor h1, .wysiwyg-editor h2, .wysiwyg-editor h3 {
            margin: 0 0 0.5em 0;
            line-height: 1.3;
        }
        .wysiwyg-editor h1 { font-size: 2em; }
        .wysiwyg-editor h2 { font-size: 1.5em; }
        .wysiwyg-editor h3 { font-size: 1.17em; }
        .wysiwyg-editor ul, .wysiwyg-editor ol {
            margin: 0 0 1em 0;
            padding-left: 2em;
        }
        .wysiwyg-editor a {
            color: #0066cc;
            text-decoration: underline;
        }
        .wysiwyg-editor a:hover {
            color: #004499;
        }
        .wysiwyg-editor table {
            border-collapse: collapse;
            width: 100%;
            margin: 1em 0;
        }
        .wysiwyg-editor td, .wysiwyg-editor th {
            border: 1px solid #ccc;
            padding: 8px;
            min-width: 40px;
        }
        .wysiwyg-table-selected {
            outline: 2px solid #007bff;
            outline-offset: 2px;
        }
        .wysiwyg-table-toolbar {
            position: absolute;
            z-index: 1000;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            padding: 4px;
            display: flex;
            gap: 2px;
            flex-wrap: wrap;
            max-width: 320px;
        }
        .wysiwyg-table-toolbar-btn {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 4px 8px;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
        }
        .wysiwyg-table-toolbar-btn:hover {
            background: #e0e0e0;
        }
        .wysiwyg-table-toolbar-separator {
            width: 1px;
            background: #ddd;
            margin: 0 4px;
        }
        .wysiwyg-editor img {
            max-width: 100%;
            height: auto;
        }
        .wysiwyg-code-editor {
            width: 100%;
            min-height: 200px;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.5;
            border: none;
            padding: 12px;
            resize: vertical;
            box-sizing: border-box;
            outline: none;
            background: #fff;
        }
        .wysiwyg-dropdown-wrapper {
            position: relative;
            display: inline-block;
        }
        .wysiwyg-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 1000;
            min-width: 120px;
            max-height: 300px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            display: none;
        }
        .wysiwyg-dropdown-open {
            display: block;
        }
        .wysiwyg-dropdown-item {
            padding: 8px 12px;
            cursor: pointer;
            white-space: nowrap;
        }
        .wysiwyg-dropdown-item:hover {
            background: #f0f0f0;
        }
        .wysiwyg-color-picker {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 1000;
            padding: 8px;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            display: none;
        }
        .wysiwyg-color-picker-open {
            display: grid;
            grid-template-columns: repeat(6, 24px);
            gap: 4px;
        }
        .wysiwyg-color-swatch {
            width: 24px;
            height: 24px;
            border: 1px solid #ccc;
            border-radius: 2px;
            cursor: pointer;
            box-sizing: border-box;
        }
        .wysiwyg-color-swatch:hover {
            border-color: #333;
            transform: scale(1.1);
        }
        .wysiwyg-color-remove {
            grid-column: span 6;
            text-align: center;
            padding: 4px;
            cursor: pointer;
            border-top: 1px solid #eee;
            margin-top: 4px;
            font-size: 12px;
            color: #666;
        }
        .wysiwyg-color-remove:hover {
            background: #f0f0f0;
        }
        .wysiwyg-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        .wysiwyg-modal {
            position: relative;
            z-index: 10001;
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            min-width: 300px;
            max-width: 90vw;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .wysiwyg-modal-header {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .wysiwyg-modal-body {
            margin-bottom: 16px;
        }
        .wysiwyg-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }
        .wysiwyg-modal-row {
            margin-bottom: 12px;
        }
        .wysiwyg-modal-label {
            display: block;
            margin-bottom: 4px;
            font-weight: 500;
            font-size: 14px;
        }
        .wysiwyg-modal-input {
            display: block;
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
            position: relative;
            z-index: 1;
        }
        .wysiwyg-modal-input:focus {
            outline: none;
            border-color: #007bff;
        }
        .wysiwyg-modal-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .wysiwyg-modal-btn-primary {
            background: #007bff;
            color: #fff;
        }
        .wysiwyg-modal-btn-primary:hover {
            background: #0056b3;
        }
        .wysiwyg-modal-btn-secondary {
            background: #e0e0e0;
            color: #333;
        }
        .wysiwyg-modal-btn-secondary:hover {
            background: #d0d0d0;
        }
        .wysiwyg-modal-tabs {
            display: flex;
            border-bottom: 1px solid #ccc;
            margin-bottom: 16px;
        }
        .wysiwyg-modal-tab {
            padding: 8px 16px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
        }
        .wysiwyg-modal-tab:hover {
            background: #f5f5f5;
        }
        .wysiwyg-modal-tab-active {
            border-bottom-color: #007bff;
            color: #007bff;
        }
        .wysiwyg-modal-tab-content {
            display: none;
        }
        .wysiwyg-modal-tab-content-active {
            display: block;
        }
        .wysiwyg-image-selected {
            outline: 2px solid #007bff;
            outline-offset: 2px;
            cursor: pointer;
        }
        .wysiwyg-image-toolbar {
            position: absolute;
            z-index: 1000;
            background: #fff;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            padding: 4px;
            display: flex;
            gap: 4px;
        }
        .wysiwyg-image-toolbar-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            font-size: 12px;
        }
        .wysiwyg-image-toolbar-btn:hover {
            background: #f0f0f0;
        }
        .wysiwyg-image-resizer {
            position: absolute;
            z-index: 999;
            border: 1px dashed #007bff;
            pointer-events: none;
        }
        .wysiwyg-image-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #007bff;
            border: 1px solid #fff;
            pointer-events: all;
        }
        .wysiwyg-image-handle-se {
            right: -5px;
            bottom: -5px;
            cursor: se-resize;
        }
        .wysiwyg-image-handle-sw {
            left: -5px;
            bottom: -5px;
            cursor: sw-resize;
        }
        .wysiwyg-image-handle-ne {
            right: -5px;
            top: -5px;
            cursor: ne-resize;
        }
        .wysiwyg-image-handle-nw {
            left: -5px;
            top: -5px;
            cursor: nw-resize;
        }
    `;

    /**
     * Original textarea element
     * @type {HTMLTextAreaElement}
     */
    textarea;

    /**
     * Merged configuration
     * @type {Object}
     */
    config;

    /**
     * Wrapper container element
     * @type {HTMLDivElement}
     */
    wrapper;

    /**
     * Toolbar element
     * @type {HTMLDivElement}
     */
    toolbar;

    /**
     * Contenteditable editor element
     * @type {HTMLDivElement}
     */
    editor;

    /**
     * Code view textarea element
     * @type {HTMLTextAreaElement}
     */
    codeEditor;

    /**
     * Whether editor is in code view mode
     * @type {boolean}
     */
    isCodeView = false;

    /**
     * Saved selection range for restoring after popup interactions
     * @type {Range|null}
     */
    savedSelection = null;

    /**
     * Document click handler reference for cleanup
     * @type {Function|null}
     */
    documentClickHandler = null;

    /**
     * Currently selected image element
     * @type {HTMLImageElement|null}
     */
    selectedImage = null;

    /**
     * Image toolbar element
     * @type {HTMLElement|null}
     */
    imageToolbar = null;

    /**
     * Image resizer overlay element
     * @type {HTMLElement|null}
     */
    imageResizer = null;

    /**
     * Create a new WYSIWYGEditor instance
     *
     * @param {HTMLTextAreaElement|string} textarea - Textarea element or selector
     * @param {Object} options - Configuration options
     */
    constructor(textarea, options = {}) {
        if (typeof textarea === 'string') {
            textarea = document.querySelector(textarea);
        }

        if (!textarea || textarea.tagName !== 'TEXTAREA') {
            throw new Error('WYSIWYGEditor requires a textarea element');
        }

        this.textarea = textarea;
        this.config = { ...WYSIWYGEditor.defaults, ...options };

        // Resolve 'all' shorthand in toolbar to include all available buttons
        if (this.config.toolbar.includes('all')) {
            this.config.toolbar = [...WYSIWYGEditor.defaults.toolbar];
        }

        this.init();
    }

    /**
     * Initialize the editor
     * @private
     */
    init() {
        WYSIWYGEditor.injectStyles(this.config.classPrefix);
        this.buildWrapper();
        this.buildToolbar();
        this.buildEditor();
        this.buildCodeEditor();
        this.bindEvents();

        // Set initial content from textarea (sanitize any embedded editor UI)
        if (this.textarea.value) {
            this.editor.innerHTML = this.sanitizeEditorUI(this.textarea.value);
        }
    }

    /**
     * Inject CSS styles into the document
     * @param {string} prefix - CSS class prefix
     * @private
     */
    static injectStyles(prefix = 'wysiwyg') {
        if (WYSIWYGEditor.stylesInjected) return;

        const style = document.createElement('style');
        style.id = 'wysiwyg-editor-styles';
        style.textContent = WYSIWYGEditor.styles.replace(/\.wysiwyg-/g, `.${prefix}-`);
        document.head.appendChild(style);
        WYSIWYGEditor.stylesInjected = true;
    }

    /**
     * Build the wrapper container
     * @private
     */
    buildWrapper() {
        this.wrapper = document.createElement('div');
        this.wrapper.className = `${this.config.classPrefix}-wrapper`;

        // Insert wrapper before textarea and hide textarea
        this.textarea.parentNode.insertBefore(this.wrapper, this.textarea);
        this.textarea.style.display = 'none';
    }

    /**
     * Build the toolbar
     * @private
     */
    buildToolbar() {
        this.toolbar = document.createElement('div');
        this.toolbar.className = `${this.config.classPrefix}-toolbar`;

        this.config.toolbar.forEach(item => {
            const def = WYSIWYGEditor.toolbarButtons[item];
            if (!def) return;

            if (item === '|' || def.type === 'separator') {
                const sep = document.createElement('span');
                sep.className = `${this.config.classPrefix}-separator`;
                this.toolbar.appendChild(sep);
                return;
            }

            // Handle dropdown type buttons
            if (def.type === 'dropdown') {
                const wrapper = this.createDropdownButton(item, def);
                this.toolbar.appendChild(wrapper);
                return;
            }

            // Handle color picker type buttons
            if (def.type === 'colorPicker') {
                const wrapper = this.createColorPickerButton(item, def);
                this.toolbar.appendChild(wrapper);
                return;
            }

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `${this.config.classPrefix}-btn`;
            btn.dataset.command = def.command;
            if (def.value) btn.dataset.value = def.value;
            if (def.custom) btn.dataset.custom = 'true';
            btn.title = def.title;
            btn.innerHTML = def.icon;

            this.toolbar.appendChild(btn);
        });

        this.wrapper.appendChild(this.toolbar);
    }

    /**
     * Build the contenteditable editor area
     * @private
     */
    buildEditor() {
        this.editor = document.createElement('div');
        this.editor.className = `${this.config.classPrefix}-editor`;
        this.editor.contentEditable = 'true';

        // Use <p> tags for paragraphs instead of <div>
        document.execCommand('defaultParagraphSeparator', false, 'p');

        if (this.config.placeholder) {
            this.editor.dataset.placeholder = this.config.placeholder;
        }

        if (this.config.minHeight) {
            this.editor.style.minHeight = this.config.minHeight;
        }

        if (this.config.maxHeight) {
            this.editor.style.maxHeight = this.config.maxHeight;
        }

        this.wrapper.appendChild(this.editor);
    }

    /**
     * Build the code editor textarea for HTML source editing
     * @private
     */
    buildCodeEditor() {
        this.codeEditor = document.createElement('textarea');
        this.codeEditor.className = `${this.config.classPrefix}-code-editor`;
        this.codeEditor.style.display = 'none';

        if (this.config.minHeight) {
            this.codeEditor.style.minHeight = this.config.minHeight;
        }

        if (this.config.maxHeight) {
            this.codeEditor.style.maxHeight = this.config.maxHeight;
        }

        this.wrapper.appendChild(this.codeEditor);
    }

    /**
     * Create a dropdown button with menu
     *
     * @param {string} name - Button name (e.g., 'fontSize', 'fontName')
     * @param {Object} def - Button definition
     * @returns {HTMLElement} Wrapper element containing button and dropdown
     * @private
     */
    createDropdownButton(name, def) {
        const prefix = this.config.classPrefix;
        const wrapper = document.createElement('div');
        wrapper.className = `${prefix}-dropdown-wrapper`;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `${prefix}-btn`;
        btn.dataset.command = def.command;
        btn.dataset.custom = 'true';
        btn.dataset.dropdownTrigger = name;
        btn.title = def.title;
        btn.innerHTML = def.icon;

        const dropdown = document.createElement('div');
        dropdown.className = `${prefix}-dropdown`;
        dropdown.dataset.dropdown = name;

        // Populate dropdown items based on button type
        if (name === 'fontSize') {
            this.config.fontSizes.forEach(size => {
                const item = document.createElement('div');
                item.className = `${prefix}-dropdown-item`;
                item.dataset.value = size;
                item.textContent = size;
                item.style.fontSize = size;
                dropdown.appendChild(item);
            });
        } else if (name === 'fontName') {
            this.config.fontFamilies.forEach(font => {
                const item = document.createElement('div');
                item.className = `${prefix}-dropdown-item`;
                item.dataset.value = font.value;
                item.textContent = font.label;
                item.style.fontFamily = font.value;
                dropdown.appendChild(item);
            });
        }

        wrapper.appendChild(btn);
        wrapper.appendChild(dropdown);

        return wrapper;
    }

    /**
     * Create a color picker button with palette
     *
     * @param {string} name - Button name (e.g., 'textColor', 'bgColor')
     * @param {Object} def - Button definition
     * @returns {HTMLElement} Wrapper element containing button and color picker
     * @private
     */
    createColorPickerButton(name, def) {
        const prefix = this.config.classPrefix;
        const wrapper = document.createElement('div');
        wrapper.className = `${prefix}-dropdown-wrapper`;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `${prefix}-btn`;
        btn.dataset.command = def.command;
        btn.dataset.custom = 'true';
        btn.dataset.colorPickerTrigger = name;
        btn.title = def.title;
        btn.innerHTML = def.icon;

        const picker = document.createElement('div');
        picker.className = `${prefix}-color-picker`;
        picker.dataset.colorPicker = name;

        // Add color swatches
        this.config.colorPalette.forEach(color => {
            const swatch = document.createElement('div');
            swatch.className = `${prefix}-color-swatch`;
            swatch.style.backgroundColor = color;
            swatch.dataset.color = color;
            swatch.title = color;
            picker.appendChild(swatch);
        });

        // Add remove color option for background color
        if (name === 'bgColor') {
            const remove = document.createElement('div');
            remove.className = `${prefix}-color-remove`;
            remove.dataset.color = 'transparent';
            remove.textContent = 'Remove';
            picker.appendChild(remove);
        }

        wrapper.appendChild(btn);
        wrapper.appendChild(picker);

        return wrapper;
    }

    /**
     * Bind event listeners
     * @private
     */
    bindEvents() {
        const prefix = this.config.classPrefix;

        // Toolbar button clicks via event delegation
        this.toolbar.addEventListener('click', (e) => {
            // Handle dropdown trigger clicks
            const dropdownTrigger = e.target.closest('[data-dropdown-trigger]');
            if (dropdownTrigger) {
                e.preventDefault();
                e.stopPropagation();
                this.saveSelection();
                this.toggleDropdown(dropdownTrigger.dataset.dropdownTrigger);
                return;
            }

            // Handle dropdown item clicks
            const dropdownItem = e.target.closest(`.${prefix}-dropdown-item`);
            if (dropdownItem) {
                e.preventDefault();
                e.stopPropagation();
                const dropdown = dropdownItem.closest(`.${prefix}-dropdown`);
                const name = dropdown.dataset.dropdown;
                const value = dropdownItem.dataset.value;
                this.handleDropdownSelect(name, value);
                return;
            }

            // Handle color picker trigger clicks
            const colorTrigger = e.target.closest('[data-color-picker-trigger]');
            if (colorTrigger) {
                e.preventDefault();
                e.stopPropagation();
                this.saveSelection();
                this.toggleColorPicker(colorTrigger.dataset.colorPickerTrigger);
                return;
            }

            // Handle color swatch clicks
            const colorSwatch = e.target.closest(`.${prefix}-color-swatch, .${prefix}-color-remove`);
            if (colorSwatch) {
                e.preventDefault();
                e.stopPropagation();
                const picker = colorSwatch.closest(`.${prefix}-color-picker`);
                const name = picker.dataset.colorPicker;
                const color = colorSwatch.dataset.color;
                this.handleColorSelect(name, color);
                return;
            }

            // Handle regular button clicks
            const btn = e.target.closest('[data-command]');
            if (!btn) return;

            e.preventDefault();

            if (btn.dataset.custom === 'true') {
                this.handleCustomCommand(btn.dataset.command);
            } else {
                this.exec(btn.dataset.command, btn.dataset.value || null);
            }
        });

        // Content sync on input
        this.editor.addEventListener('input', () => {
            this.sync();
            if (this.config.onChange) {
                this.config.onChange(this.getContent());
            }
        });

        // Code editor sync on input
        this.codeEditor.addEventListener('input', () => {
            if (this.config.onChange) {
                this.config.onChange(this.codeEditor.value);
            }
        });

        // Keyboard shortcuts
        this.editor.addEventListener('keydown', (e) => this.handleKeyboard(e));

        // Paste handling
        this.editor.addEventListener('paste', (e) => this.handlePaste(e));

        // Focus/blur callbacks
        this.editor.addEventListener('focus', () => {
            if (this.config.onFocus) {
                this.config.onFocus();
            }
        });

        this.editor.addEventListener('blur', () => {
            if (this.config.onBlur) {
                this.config.onBlur();
            }
        });

        // Update toolbar state on selection change
        document.addEventListener('selectionchange', () => {
            if (this.editor.contains(document.getSelection().anchorNode)) {
                this.updateToolbarState();
            }
        });

        // Image click handler for editing
        this.editor.addEventListener('click', (e) => {
            const prefix = this.config.classPrefix;

            if (e.target.tagName === 'IMG') {
                e.preventDefault();
                this.selectImage(e.target);
                this.deselectTable();
            } else if (e.target.closest('table')) {
                const table = e.target.closest('table');
                const cell = e.target.closest('td, th');
                if (!e.target.closest(`.${prefix}-table-toolbar`)) {
                    this.selectTable(table, cell);
                    this.deselectImage();
                }
            } else {
                if (!e.target.closest(`.${prefix}-image-toolbar`)) {
                    this.deselectImage();
                }
                if (!e.target.closest(`.${prefix}-table-toolbar`)) {
                    this.deselectTable();
                }
            }
        });

        // Document click handler to close popups and deselect images/tables
        this.documentClickHandler = (e) => {
            if (!this.wrapper.contains(e.target)) {
                this.closeAllPopups();
                this.deselectImage();
                this.deselectTable();
            }
        };
        document.addEventListener('click', this.documentClickHandler);

        // Sync before form submission
        const form = this.textarea.closest('form');
        if (form) {
            form.addEventListener('submit', () => this.sync());
        }
    }

    /**
     * Execute a formatting command
     *
     * @param {string} command - The execCommand command name
     * @param {string|null} value - Optional value for the command
     */
    exec(command, value = null) {
        this.editor.focus();

        if (command === 'formatBlock' && value) {
            // Toggle off: if already in the requested block type, revert to <p>
            const currentBlock = document.queryCommandValue('formatBlock');
            if (currentBlock.toLowerCase() === value.toLowerCase()) {
                document.execCommand(command, false, '<p>');
            } else {
                document.execCommand(command, false, `<${value}>`);
            }
        } else {
            document.execCommand(command, false, value);
        }

        this.sync();
        this.updateToolbarState();
    }

    /**
     * Handle custom commands (like link insertion)
     *
     * @param {string} command - The custom command name
     * @private
     */
    handleCustomCommand(command) {
        switch (command) {
            case 'link':
                this.insertLink();
                break;
            case 'codeView':
                this.toggleCodeView();
                break;
            case 'insertTable':
                this.showTableModal();
                break;
            case 'insertImage':
                this.showImageModal();
                break;
            case 'subscript':
                this.toggleSubscript();
                break;
            case 'superscript':
                this.toggleSuperscript();
                break;
            case 'indent':
                this.applyIndent();
                break;
            case 'outdent':
                this.applyOutdent();
                break;
            case 'removeFormat':
                this.safeRemoveFormat();
                break;
        }
    }

    /**
     * Toggle subscript, removing superscript first to avoid conflicts
     * @private
     */
    toggleSubscript() {
        this.editor.focus();

        // Remove superscript first if active
        if (this.isInsideTag('sup')) {
            document.execCommand('superscript', false, null);
        }

        document.execCommand('subscript', false, null);
        this.sync();
        this.updateToolbarState();
    }

    /**
     * Toggle superscript, removing subscript first to avoid conflicts
     * @private
     */
    toggleSuperscript() {
        this.editor.focus();

        // Remove subscript first if active
        if (this.isInsideTag('sub')) {
            document.execCommand('subscript', false, null);
        }

        document.execCommand('superscript', false, null);
        this.sync();
        this.updateToolbarState();
    }

    /**
     * Check if current selection is inside a specific HTML tag
     *
     * @param {string} tagName - Tag name to check (lowercase)
     * @returns {boolean} True if selection is inside the tag
     * @private
     */
    isInsideTag(tagName) {
        const sel = window.getSelection();
        if (!sel.rangeCount) return false;

        let node = sel.anchorNode;
        while (node && node !== this.editor) {
            if (node.nodeType === Node.ELEMENT_NODE && node.tagName.toLowerCase() === tagName) {
                return true;
            }
            node = node.parentNode;
        }
        return false;
    }

    /**
     * Get the closest block-level parent element of the current selection
     *
     * @returns {HTMLElement|null} The closest block element or null
     * @private
     */
    getSelectedBlockElement() {
        const sel = window.getSelection();
        if (!sel.rangeCount) return null;

        let node = sel.anchorNode;
        if (node.nodeType === Node.TEXT_NODE) {
            node = node.parentElement;
        }

        const blockTags = ['P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
            'BLOCKQUOTE', 'PRE', 'LI', 'TD', 'TH'];

        while (node && node !== this.editor) {
            if (node.nodeType === Node.ELEMENT_NODE && blockTags.includes(node.tagName)) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    /**
     * Increase indentation using consistent margin-left CSS across all browsers
     * @private
     */
    applyIndent() {
        this.editor.focus();
        const block = this.getSelectedBlockElement();
        if (!block) return;

        const currentMargin = parseInt(getComputedStyle(block).marginLeft, 10) || 0;
        block.style.marginLeft = (currentMargin + 40) + 'px';

        this.sync();
        this.updateToolbarState();
    }

    /**
     * Decrease indentation using consistent margin-left CSS across all browsers
     * @private
     */
    applyOutdent() {
        this.editor.focus();
        const block = this.getSelectedBlockElement();
        if (!block) return;

        const currentMargin = parseInt(getComputedStyle(block).marginLeft, 10) || 0;
        const newMargin = Math.max(0, currentMargin - 40);

        if (newMargin === 0) {
            block.style.marginLeft = '';
            // Clean up empty style attribute
            if (!block.getAttribute('style')?.trim()) {
                block.removeAttribute('style');
            }
        } else {
            block.style.marginLeft = newMargin + 'px';
        }

        this.sync();
        this.updateToolbarState();
    }

    /**
     * Remove formatting while preserving links (Safari compatibility)
     *
     * Safari's native removeFormat also removes anchor elements.
     * This method saves links, removes formatting, then restores them.
     * @private
     */
    safeRemoveFormat() {
        this.editor.focus();
        const sel = window.getSelection();
        if (!sel.rangeCount) return;

        const range = sel.getRangeAt(0);
        const container = range.commonAncestorContainer;
        const scope = container.nodeType === Node.TEXT_NODE ? container.parentElement : container;

        // Collect all links within the selection range
        const links = [];
        const allAnchors = scope.querySelectorAll ? scope.querySelectorAll('a[href]') : [];
        allAnchors.forEach(a => {
            if (range.intersectsNode(a)) {
                links.push({
                    href: a.href,
                    target: a.target,
                    textContent: a.textContent
                });
            }
        });

        // Execute removeFormat
        document.execCommand('removeFormat', false, null);

        // Restore links that were removed by Safari
        if (links.length > 0) {
            const updatedContent = this.editor.innerHTML;
            links.forEach(link => {
                // Check if the link text still exists but is no longer wrapped in <a>
                const escapedText = link.textContent.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                const linkExists = this.editor.querySelector(`a[href="${CSS.escape(link.href)}"]`);
                if (!linkExists) {
                    // Find the text node and re-wrap it
                    const treeWalker = document.createTreeWalker(
                        this.editor, NodeFilter.SHOW_TEXT, null
                    );
                    while (treeWalker.nextNode()) {
                        const textNode = treeWalker.currentNode;
                        if (textNode.textContent.includes(link.textContent)) {
                            const newAnchor = document.createElement('a');
                            newAnchor.href = link.href;
                            if (link.target) newAnchor.target = link.target;
                            newAnchor.textContent = link.textContent;
                            textNode.parentNode.replaceChild(newAnchor, textNode);
                            break;
                        }
                    }
                }
            });
        }

        this.sync();
        this.updateToolbarState();
    }

    /**
     * Insert a link at the current selection
     */
    insertLink() {
        const selection = window.getSelection();
        const hasSelection = selection.toString().length > 0;

        const url = prompt('Enter URL:', 'https://');

        if (!url || url === 'https://') return;

        this.editor.focus();

        if (hasSelection) {
            document.execCommand('createLink', false, url);

            // Add target="_blank" if configured
            if (this.config.linkTargetBlank) {
                const links = this.editor.querySelectorAll(`a[href="${url}"]`);
                links.forEach(link => {
                    if (!link.target) {
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                    }
                });
            }
        } else {
            // No selection - insert link with URL as text
            const linkHtml = this.config.linkTargetBlank
                ? `<a href="${this.escapeHtml(url)}" target="_blank" rel="noopener noreferrer">${this.escapeHtml(url)}</a>`
                : `<a href="${this.escapeHtml(url)}">${this.escapeHtml(url)}</a>`;
            document.execCommand('insertHTML', false, linkHtml);
        }

        this.sync();
    }

    /**
     * Save the current selection for later restoration
     * @private
     */
    saveSelection() {
        const selection = window.getSelection();
        if (selection.rangeCount > 0) {
            this.savedSelection = selection.getRangeAt(0).cloneRange();
        }
    }

    /**
     * Restore the previously saved selection
     * @private
     */
    restoreSelection() {
        if (this.savedSelection) {
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(this.savedSelection);
        }
        this.editor.focus();
    }

    /**
     * Toggle a dropdown menu
     *
     * @param {string} name - The dropdown name
     * @private
     */
    toggleDropdown(name) {
        const prefix = this.config.classPrefix;
        const dropdown = this.toolbar.querySelector(`[data-dropdown="${name}"]`);

        if (!dropdown) return;

        const isOpen = dropdown.classList.contains(`${prefix}-dropdown-open`);

        // Close all popups first
        this.closeAllPopups();

        if (!isOpen) {
            dropdown.classList.add(`${prefix}-dropdown-open`);
        }
    }

    /**
     * Toggle a color picker
     *
     * @param {string} name - The color picker name
     * @private
     */
    toggleColorPicker(name) {
        const prefix = this.config.classPrefix;
        const picker = this.toolbar.querySelector(`[data-color-picker="${name}"]`);

        if (!picker) return;

        const isOpen = picker.classList.contains(`${prefix}-color-picker-open`);

        // Close all popups first
        this.closeAllPopups();

        if (!isOpen) {
            picker.classList.add(`${prefix}-color-picker-open`);
        }
    }

    /**
     * Handle dropdown item selection
     *
     * @param {string} name - The dropdown name
     * @param {string} value - The selected value
     * @private
     */
    handleDropdownSelect(name, value) {
        this.closeAllPopups();
        this.restoreSelection();

        if (name === 'fontSize') {
            this.applyFontSize(value);
        } else if (name === 'fontName') {
            document.execCommand('fontName', false, value);
        }

        this.sync();
        this.updateToolbarState();
    }

    /**
     * Apply font size using inline style span
     *
     * @param {string} size - The font size value (e.g., '16px')
     * @private
     */
    applyFontSize(size) {
        const selection = window.getSelection();
        if (!selection.rangeCount) return;

        const range = selection.getRangeAt(0);

        if (range.collapsed) {
            // No selection - insert a zero-width space in a styled span
            const span = document.createElement('span');
            span.style.fontSize = size;
            span.innerHTML = '&#8203;'; // Zero-width space
            range.insertNode(span);

            // Place cursor inside the span
            range.setStart(span.firstChild, 1);
            range.setEnd(span.firstChild, 1);
            selection.removeAllRanges();
            selection.addRange(range);
        } else {
            // Has selection - wrap in styled span
            const span = document.createElement('span');
            span.style.fontSize = size;

            try {
                range.surroundContents(span);
            } catch (e) {
                // If surroundContents fails (e.g., partial element selection),
                // use execCommand with insertHTML
                const contents = range.extractContents();
                span.appendChild(contents);
                range.insertNode(span);
            }

            // Select the new span contents
            range.selectNodeContents(span);
            selection.removeAllRanges();
            selection.addRange(range);
        }
    }

    /**
     * Handle color selection
     *
     * @param {string} name - The color picker name ('textColor' or 'bgColor')
     * @param {string} color - The selected color
     * @private
     */
    handleColorSelect(name, color) {
        this.closeAllPopups();
        this.restoreSelection();

        if (name === 'textColor') {
            document.execCommand('foreColor', false, color);
        } else if (name === 'bgColor') {
            if (color === 'transparent') {
                document.execCommand('removeFormat', false, null);
            } else {
                document.execCommand('backColor', false, color);
            }
        }

        this.sync();
        this.updateToolbarState();
    }

    /**
     * Close all open dropdowns and color pickers
     * @private
     */
    closeAllPopups() {
        const prefix = this.config.classPrefix;
        this.toolbar.querySelectorAll(`.${prefix}-dropdown-open`).forEach(el => {
            el.classList.remove(`${prefix}-dropdown-open`);
        });
        this.toolbar.querySelectorAll(`.${prefix}-color-picker-open`).forEach(el => {
            el.classList.remove(`${prefix}-color-picker-open`);
        });
    }

    /**
     * Toggle between WYSIWYG and code view modes
     */
    toggleCodeView() {
        const prefix = this.config.classPrefix;
        this.isCodeView = !this.isCodeView;

        if (this.isCodeView) {
            // Deselect any selected elements before switching
            this.deselectImage();
            this.deselectTable();

            // Switch to code view - use clean content without UI elements
            this.codeEditor.value = this.getCleanContent();
            this.editor.style.display = 'none';
            this.codeEditor.style.display = 'block';
            this.codeEditor.focus();

            // Disable all toolbar buttons except codeView
            this.toolbar.querySelectorAll(`.${prefix}-btn`).forEach(btn => {
                if (btn.dataset.command !== 'codeView') {
                    btn.classList.add(`${prefix}-btn-disabled`);
                } else {
                    btn.classList.add(`${prefix}-btn-active`);
                }
            });
        } else {
            // Switch back to WYSIWYG view (sanitize any embedded editor UI)
            this.editor.innerHTML = this.sanitizeEditorUI(this.codeEditor.value);
            this.codeEditor.style.display = 'none';
            this.editor.style.display = 'block';
            this.editor.focus();
            this.sync();

            // Enable all toolbar buttons
            this.toolbar.querySelectorAll(`.${prefix}-btn`).forEach(btn => {
                btn.classList.remove(`${prefix}-btn-disabled`);
                if (btn.dataset.command === 'codeView') {
                    btn.classList.remove(`${prefix}-btn-active`);
                }
            });
        }
    }

    /**
     * Show the table insertion modal
     */
    showTableModal() {
        const prefix = this.config.classPrefix;
        const defaults = this.config.tableDefaults;

        this.saveSelection();

        const content = `
            <div class="${prefix}-modal-header">Insert Table</div>
            <div class="${prefix}-modal-body">
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Rows</label>
                    <input type="number" class="${prefix}-modal-input" id="${prefix}-table-rows" value="${defaults.rows}" min="1" max="20">
                </div>
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Columns</label>
                    <input type="number" class="${prefix}-modal-input" id="${prefix}-table-cols" value="${defaults.cols}" min="1" max="20">
                </div>
            </div>
            <div class="${prefix}-modal-footer">
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-secondary" data-action="cancel">Cancel</button>
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-primary" data-action="insert">Insert</button>
            </div>
        `;

        this.showModal(content, (modal) => {
            const rows = parseInt(modal.querySelector(`#${prefix}-table-rows`).value, 10) || defaults.rows;
            const cols = parseInt(modal.querySelector(`#${prefix}-table-cols`).value, 10) || defaults.cols;
            this.insertTable(rows, cols);
        });
    }

    /**
     * Insert a table with the specified dimensions
     *
     * @param {number} rows - Number of rows
     * @param {number} cols - Number of columns
     */
    insertTable(rows, cols) {
        let html = '<table><tbody>';

        for (let r = 0; r < rows; r++) {
            html += '<tr>';
            for (let c = 0; c < cols; c++) {
                html += '<td>&nbsp;</td>';
            }
            html += '</tr>';
        }

        html += '</tbody></table>';

        this.restoreSelection();
        document.execCommand('insertHTML', false, html);
        this.sync();
    }

    /**
     * Show the image insertion modal
     */
    showImageModal() {
        const prefix = this.config.classPrefix;

        this.saveSelection();

        let content = `
            <div class="${prefix}-modal-header">Insert Image</div>
            <div class="${prefix}-modal-body">
        `;

        if (this.config.imageUpload) {
            content += `
                <div class="${prefix}-modal-tabs">
                    <div class="${prefix}-modal-tab ${prefix}-modal-tab-active" data-tab="url">URL</div>
                    <div class="${prefix}-modal-tab" data-tab="upload">Upload</div>
                </div>
                <div class="${prefix}-modal-tab-content ${prefix}-modal-tab-content-active" data-tab-content="url">
                    <div class="${prefix}-modal-row">
                        <label class="${prefix}-modal-label">Image URL</label>
                        <input type="url" class="${prefix}-modal-input" id="${prefix}-image-url" placeholder="https://example.com/image.jpg">
                    </div>
                </div>
                <div class="${prefix}-modal-tab-content" data-tab-content="upload">
                    <div class="${prefix}-modal-row">
                        <label class="${prefix}-modal-label">Select Image</label>
                        <input type="file" class="${prefix}-modal-input" id="${prefix}-image-file" accept="${this.config.allowedImageTypes.join(',')}">
                    </div>
                </div>
            `;
        } else {
            content += `
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Image URL</label>
                    <input type="url" class="${prefix}-modal-input" id="${prefix}-image-url" placeholder="https://example.com/image.jpg">
                </div>
            `;
        }

        content += `
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Alt Text</label>
                    <input type="text" class="${prefix}-modal-input" id="${prefix}-image-alt" placeholder="Image description">
                </div>
            </div>
            <div class="${prefix}-modal-footer">
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-secondary" data-action="cancel">Cancel</button>
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-primary" data-action="insert">Insert</button>
            </div>
        `;

        this.showModal(content, (modal) => {
            const url = modal.querySelector(`#${prefix}-image-url`);
            const file = modal.querySelector(`#${prefix}-image-file`);
            const alt = modal.querySelector(`#${prefix}-image-alt`).value || '';

            // Check which tab is active
            const activeTab = modal.querySelector(`.${prefix}-modal-tab-active`);
            const isUploadTab = activeTab && activeTab.dataset.tab === 'upload';

            if (isUploadTab && file && file.files.length > 0) {
                this.insertImageFromFile(file.files[0], alt);
            } else if (url && url.value) {
                this.insertImageFromUrl(url.value, alt);
            }
        }, (modal) => {
            // Setup tab switching
            const tabs = modal.querySelectorAll(`.${prefix}-modal-tab`);
            tabs.forEach(tab => {
                tab.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();

                    tabs.forEach(t => t.classList.remove(`${prefix}-modal-tab-active`));
                    tab.classList.add(`${prefix}-modal-tab-active`);

                    const tabName = tab.dataset.tab;
                    modal.querySelectorAll(`.${prefix}-modal-tab-content`).forEach(content => {
                        content.classList.remove(`${prefix}-modal-tab-content-active`);
                    });
                    modal.querySelector(`[data-tab-content="${tabName}"]`).classList.add(`${prefix}-modal-tab-content-active`);
                });
            });
        });
    }

    /**
     * Insert an image from a URL
     *
     * @param {string} url - The image URL
     * @param {string} alt - The alt text
     */
    insertImageFromUrl(url, alt = '') {
        const html = `<img src="${this.escapeHtml(url)}" alt="${this.escapeHtml(alt)}">`;
        this.restoreSelection();
        document.execCommand('insertHTML', false, html);
        this.sync();
    }

    /**
     * Insert an image from a file (converts to base64)
     *
     * @param {File} file - The image file
     * @param {string} alt - The alt text
     */
    insertImageFromFile(file, alt = '') {
        if (!this.config.allowedImageTypes.includes(file.type)) {
            alert('Invalid image type. Allowed: ' + this.config.allowedImageTypes.join(', '));
            return;
        }

        if (file.size > this.config.maxImageSize) {
            const maxMB = Math.round(this.config.maxImageSize / 1024 / 1024);
            alert(`Image too large. Maximum size: ${maxMB}MB`);
            return;
        }

        const reader = new FileReader();
        reader.onload = (e) => {
            const html = `<img src="${e.target.result}" alt="${this.escapeHtml(alt)}">`;
            this.restoreSelection();
            document.execCommand('insertHTML', false, html);
            this.sync();
        };
        reader.readAsDataURL(file);
    }

    /**
     * Show a modal dialog
     *
     * @param {string} content - The modal HTML content
     * @param {Function} onConfirm - Callback when confirm button is clicked
     * @param {Function} onSetup - Optional callback for additional setup after modal is created
     * @private
     */
    showModal(content, onConfirm, onSetup = null) {
        const prefix = this.config.classPrefix;

        const overlay = document.createElement('div');
        overlay.className = `${prefix}-modal-overlay`;

        const modal = document.createElement('div');
        modal.className = `${prefix}-modal`;
        modal.innerHTML = content;

        overlay.appendChild(modal);

        // Append to closest Bootstrap modal if inside one (to work with Bootstrap's focus trap)
        // Otherwise append to document.body
        const bootstrapModal = this.wrapper.closest('.modal');
        if (bootstrapModal) {
            bootstrapModal.appendChild(overlay);
        } else {
            document.body.appendChild(overlay);
        }

        // Prevent all input events from bubbling (fixes focus/click/typing issues)
        modal.querySelectorAll('input, textarea').forEach(input => {
            ['click', 'mousedown', 'mouseup', 'focus', 'keydown', 'keyup', 'keypress', 'input'].forEach(eventType => {
                input.addEventListener(eventType, (e) => e.stopPropagation());
            });
        });

        // Prevent modal from losing focus when clicking inside
        modal.addEventListener('mousedown', (e) => e.stopPropagation());

        // Run setup callback if provided
        if (onSetup) {
            onSetup(modal);
        }

        // Focus first input
        const firstInput = modal.querySelector('input');
        if (firstInput) {
            firstInput.focus();
        }

        // Handle button clicks (only on buttons with data-action)
        modal.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;

            const action = btn.dataset.action;
            if (action === 'cancel') {
                this.closeModal(overlay);
            } else if (action === 'insert') {
                onConfirm(modal);
                this.closeModal(overlay);
            }
        });

        // Handle ESC key
        const escHandler = (e) => {
            if (e.key === 'Escape') {
                this.closeModal(overlay);
                document.removeEventListener('keydown', escHandler);
            }
        };
        document.addEventListener('keydown', escHandler);

        // Handle click outside modal
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.closeModal(overlay);
            }
        });
    }

    /**
     * Close a modal dialog
     *
     * @param {HTMLElement} overlay - The modal overlay element
     * @private
     */
    closeModal(overlay) {
        overlay.remove();
    }

    /**
     * Select an image for editing
     *
     * @param {HTMLImageElement} img - The image element to select
     */
    selectImage(img) {
        // Deselect any previously selected image
        this.deselectImage();

        const prefix = this.config.classPrefix;
        this.selectedImage = img;
        img.classList.add(`${prefix}-image-selected`);

        // Create and show toolbar
        this.showImageToolbar(img);

        // Create and show resizer
        this.showImageResizer(img);
    }

    /**
     * Deselect the currently selected image
     */
    deselectImage() {
        if (!this.selectedImage) return;

        const prefix = this.config.classPrefix;
        this.selectedImage.classList.remove(`${prefix}-image-selected`);
        this.selectedImage = null;

        // Remove toolbar
        if (this.imageToolbar) {
            this.imageToolbar.remove();
            this.imageToolbar = null;
        }

        // Remove resizer
        if (this.imageResizer) {
            this.imageResizer.remove();
            this.imageResizer = null;
        }
    }

    /**
     * Show the image editing toolbar
     *
     * @param {HTMLImageElement} img - The image element
     * @private
     */
    showImageToolbar(img) {
        const prefix = this.config.classPrefix;

        this.imageToolbar = document.createElement('div');
        this.imageToolbar.className = `${prefix}-image-toolbar`;
        this.imageToolbar.innerHTML = `
            <button type="button" class="${prefix}-image-toolbar-btn" data-action="edit-alt" title="Edit alt text">Alt</button>
            <button type="button" class="${prefix}-image-toolbar-btn" data-action="resize-50" title="50% size">50%</button>
            <button type="button" class="${prefix}-image-toolbar-btn" data-action="resize-100" title="Original size">100%</button>
            <button type="button" class="${prefix}-image-toolbar-btn" data-action="delete" title="Delete image">&#10060;</button>
        `;

        // Position toolbar above the image
        this.wrapper.appendChild(this.imageToolbar);
        this.updateToolbarPosition(img);

        // Handle toolbar button clicks
        this.imageToolbar.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            const action = btn.dataset.action;
            this.handleImageAction(action);
        });
    }

    /**
     * Show the image resizer with drag handles
     *
     * @param {HTMLImageElement} img - The image element
     * @private
     */
    showImageResizer(img) {
        const prefix = this.config.classPrefix;

        this.imageResizer = document.createElement('div');
        this.imageResizer.className = `${prefix}-image-resizer`;
        this.imageResizer.innerHTML = `
            <div class="${prefix}-image-handle ${prefix}-image-handle-se" data-handle="se"></div>
            <div class="${prefix}-image-handle ${prefix}-image-handle-sw" data-handle="sw"></div>
            <div class="${prefix}-image-handle ${prefix}-image-handle-ne" data-handle="ne"></div>
            <div class="${prefix}-image-handle ${prefix}-image-handle-nw" data-handle="nw"></div>
        `;

        this.updateResizerPosition(img);

        // Handle resize drag
        this.imageResizer.querySelectorAll('[data-handle]').forEach(handle => {
            handle.addEventListener('mousedown', (e) => this.startImageResize(e, handle.dataset.handle));
        });

        this.wrapper.appendChild(this.imageResizer);
    }

    /**
     * Update the resizer position to match the image
     *
     * @param {HTMLImageElement} img - The image element
     * @private
     */
    updateResizerPosition(img) {
        if (!this.imageResizer) return;

        // Calculate position relative to the editor
        let left = img.offsetLeft;
        let top = img.offsetTop;

        // Walk up the offset parents until we reach the editor
        let parent = img.offsetParent;
        while (parent && parent !== this.editor) {
            left += parent.offsetLeft;
            top += parent.offsetTop;
            parent = parent.offsetParent;
        }

        // Add editor's offset within wrapper (accounts for main toolbar)
        left += this.editor.offsetLeft;
        top += this.editor.offsetTop;

        this.imageResizer.style.left = `${left}px`;
        this.imageResizer.style.top = `${top}px`;
        this.imageResizer.style.width = `${img.offsetWidth}px`;
        this.imageResizer.style.height = `${img.offsetHeight}px`;
    }

    /**
     * Start resizing an image
     *
     * @param {MouseEvent} e - The mousedown event
     * @param {string} handle - The handle position (se, sw, ne, nw)
     * @private
     */
    startImageResize(e, handle) {
        if (!this.selectedImage) return;

        e.preventDefault();
        e.stopPropagation();

        const img = this.selectedImage;
        const startX = e.clientX;
        const startY = e.clientY;
        const startWidth = img.offsetWidth;
        const startHeight = img.offsetHeight;
        const aspectRatio = startWidth / startHeight;

        const onMouseMove = (moveEvent) => {
            let deltaX = moveEvent.clientX - startX;
            let deltaY = moveEvent.clientY - startY;

            // Adjust delta based on handle position
            if (handle === 'nw' || handle === 'sw') {
                deltaX = -deltaX;
            }
            if (handle === 'nw' || handle === 'ne') {
                deltaY = -deltaY;
            }

            // Calculate new size maintaining aspect ratio
            let newWidth = startWidth + deltaX;
            let newHeight = newWidth / aspectRatio;

            // Minimum size
            if (newWidth < 50) {
                newWidth = 50;
                newHeight = newWidth / aspectRatio;
            }

            // Apply new size
            img.style.width = `${Math.round(newWidth)}px`;
            img.style.height = 'auto';

            // Update resizer position
            this.updateResizerPosition(img);
            this.updateToolbarPosition(img);
        };

        const onMouseUp = () => {
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            this.sync();
        };

        document.addEventListener('mousemove', onMouseMove);
        document.addEventListener('mouseup', onMouseUp);
    }

    /**
     * Update the toolbar position to match the image
     *
     * @param {HTMLImageElement} img - The image element
     * @private
     */
    updateToolbarPosition(img) {
        if (!this.imageToolbar) return;

        // Calculate position relative to the editor
        let left = img.offsetLeft;
        let top = img.offsetTop;

        // Walk up the offset parents until we reach the editor
        let parent = img.offsetParent;
        while (parent && parent !== this.editor) {
            left += parent.offsetLeft;
            top += parent.offsetTop;
            parent = parent.offsetParent;
        }

        // Add editor's offset within wrapper (accounts for main toolbar)
        left += this.editor.offsetLeft;
        top += this.editor.offsetTop;

        this.imageToolbar.style.left = `${left}px`;
        this.imageToolbar.style.top = `${top - 36}px`;
    }

    /**
     * Handle image toolbar actions
     *
     * @param {string} action - The action to perform
     * @private
     */
    handleImageAction(action) {
        if (!this.selectedImage) return;

        const img = this.selectedImage;

        switch (action) {
            case 'edit-alt':
                this.editImageAlt(img);
                break;
            case 'resize-50':
                img.style.width = '50%';
                img.style.height = 'auto';
                this.updateResizerPosition(img);
                this.updateToolbarPosition(img);
                this.sync();
                break;
            case 'resize-100':
                img.style.width = '';
                img.style.height = '';
                this.updateResizerPosition(img);
                this.updateToolbarPosition(img);
                this.sync();
                break;
            case 'delete':
                this.deleteImage(img);
                break;
        }
    }

    /**
     * Edit the alt text of an image
     *
     * @param {HTMLImageElement} img - The image element
     */
    editImageAlt(img) {
        const prefix = this.config.classPrefix;
        const currentAlt = img.alt || '';

        const content = `
            <div class="${prefix}-modal-header">Edit Alt Text</div>
            <div class="${prefix}-modal-body">
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Alt Text</label>
                    <input type="text" class="${prefix}-modal-input" id="${prefix}-edit-alt" value="${this.escapeHtml(currentAlt)}" placeholder="Image description">
                </div>
            </div>
            <div class="${prefix}-modal-footer">
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-secondary" data-action="cancel">Cancel</button>
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-primary" data-action="insert">Save</button>
            </div>
        `;

        this.showModal(content, (modal) => {
            const newAlt = modal.querySelector(`#${prefix}-edit-alt`).value;
            img.alt = newAlt;
            this.sync();
        });
    }

    /**
     * Delete the selected image
     *
     * @param {HTMLImageElement} img - The image element
     */
    deleteImage(img) {
        this.deselectImage();
        img.remove();
        this.sync();
    }

    /**
     * Select a table for editing
     *
     * @param {HTMLTableElement} table - The table element
     * @param {HTMLTableCellElement} cell - The clicked cell (optional)
     */
    selectTable(table, cell = null) {
        // Deselect any previously selected table
        this.deselectTable();

        const prefix = this.config.classPrefix;
        this.selectedTable = table;
        this.selectedCell = cell;
        table.classList.add(`${prefix}-table-selected`);

        // Create and show toolbar
        this.showTableToolbar(table);
    }

    /**
     * Deselect the currently selected table
     */
    deselectTable() {
        if (!this.selectedTable) return;

        const prefix = this.config.classPrefix;
        this.selectedTable.classList.remove(`${prefix}-table-selected`);
        this.selectedTable = null;
        this.selectedCell = null;

        // Remove toolbar
        if (this.tableToolbar) {
            this.tableToolbar.remove();
            this.tableToolbar = null;
        }
    }

    /**
     * Show the table editing toolbar
     *
     * @param {HTMLTableElement} table - The table element
     * @private
     */
    showTableToolbar(table) {
        const prefix = this.config.classPrefix;

        this.tableToolbar = document.createElement('div');
        this.tableToolbar.className = `${prefix}-table-toolbar`;
        this.tableToolbar.innerHTML = `
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="properties" title="Table properties">&#9881; Properties</button>
            <span class="${prefix}-table-toolbar-separator"></span>
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="row-above" title="Insert row above">&#8593; Row</button>
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="row-below" title="Insert row below">&#8595; Row</button>
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="col-left" title="Insert column left">&#8592; Col</button>
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="col-right" title="Insert column right">&#8594; Col</button>
            <span class="${prefix}-table-toolbar-separator"></span>
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="delete-row" title="Delete row">&#10060; Row</button>
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="delete-col" title="Delete column">&#10060; Col</button>
            <button type="button" class="${prefix}-table-toolbar-btn" data-action="delete-table" title="Delete table">&#10060; Table</button>
        `;

        // Position toolbar above the table
        this.wrapper.appendChild(this.tableToolbar);
        this.updateTableToolbarPosition(table);

        // Handle toolbar button clicks
        this.tableToolbar.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;

            e.preventDefault();
            e.stopPropagation();

            const action = btn.dataset.action;
            this.handleTableAction(action);
        });
    }

    /**
     * Update the table toolbar position
     *
     * @param {HTMLTableElement} table - The table element
     * @private
     */
    updateTableToolbarPosition(table) {
        if (!this.tableToolbar) return;

        // Calculate position relative to the editor
        let left = table.offsetLeft;
        let top = table.offsetTop;

        // Walk up the offset parents until we reach the editor
        let parent = table.offsetParent;
        while (parent && parent !== this.editor) {
            left += parent.offsetLeft;
            top += parent.offsetTop;
            parent = parent.offsetParent;
        }

        // Add editor's offset within wrapper (accounts for main toolbar)
        left += this.editor.offsetLeft;
        top += this.editor.offsetTop;

        this.tableToolbar.style.left = `${left}px`;
        this.tableToolbar.style.top = `${top - this.tableToolbar.offsetHeight - 5}px`;
    }

    /**
     * Handle table toolbar actions
     *
     * @param {string} action - The action to perform
     * @private
     */
    handleTableAction(action) {
        const table = this.selectedTable;
        const cell = this.selectedCell;

        if (!table) return;

        switch (action) {
            case 'properties':
                this.showTablePropertiesModal(table);
                break;
            case 'row-above':
                this.insertTableRow(table, cell, 'above');
                break;
            case 'row-below':
                this.insertTableRow(table, cell, 'below');
                break;
            case 'col-left':
                this.insertTableColumn(table, cell, 'left');
                break;
            case 'col-right':
                this.insertTableColumn(table, cell, 'right');
                break;
            case 'delete-row':
                this.deleteTableRow(table, cell);
                break;
            case 'delete-col':
                this.deleteTableColumn(table, cell);
                break;
            case 'delete-table':
                this.deleteTable(table);
                break;
        }
    }

    /**
     * Show the table properties modal
     *
     * @param {HTMLTableElement} table - The table element
     */
    showTablePropertiesModal(table) {
        const prefix = this.config.classPrefix;

        // Get current table styles
        const computedStyle = window.getComputedStyle(table);
        const cells = table.querySelectorAll('td, th');
        const firstCell = cells[0];
        const cellStyle = firstCell ? window.getComputedStyle(firstCell) : null;

        // Parse current values
        let borderWidth = '1';
        let borderColor = '#cccccc';
        let cellPadding = '8';
        let tableWidth = '100';

        if (cellStyle) {
            const borderMatch = cellStyle.borderWidth.match(/(\d+)/);
            if (borderMatch) borderWidth = borderMatch[1];
            borderColor = this.rgbToHex(cellStyle.borderColor) || '#cccccc';
            const paddingMatch = cellStyle.padding.match(/(\d+)/);
            if (paddingMatch) cellPadding = paddingMatch[1];
        }

        if (table.style.width) {
            const widthMatch = table.style.width.match(/(\d+)/);
            if (widthMatch) tableWidth = widthMatch[1];
        }

        const content = `
            <div class="${prefix}-modal-header">Table Properties</div>
            <div class="${prefix}-modal-body">
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Border Width (px)</label>
                    <input type="number" class="${prefix}-modal-input" id="${prefix}-table-border" value="${borderWidth}" min="0" max="10">
                </div>
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Border Color</label>
                    <input type="color" class="${prefix}-modal-input" id="${prefix}-table-border-color" value="${borderColor}" style="height: 36px; padding: 2px;">
                </div>
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Cell Padding (px)</label>
                    <input type="number" class="${prefix}-modal-input" id="${prefix}-table-padding" value="${cellPadding}" min="0" max="50">
                </div>
                <div class="${prefix}-modal-row">
                    <label class="${prefix}-modal-label">Table Width (%)</label>
                    <input type="number" class="${prefix}-modal-input" id="${prefix}-table-width" value="${tableWidth}" min="10" max="100">
                </div>
            </div>
            <div class="${prefix}-modal-footer">
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-secondary" data-action="cancel">Cancel</button>
                <button type="button" class="${prefix}-modal-btn ${prefix}-modal-btn-primary" data-action="insert">Apply</button>
            </div>
        `;

        this.showModal(content, (modal) => {
            const borderWidth = modal.querySelector(`#${prefix}-table-border`).value;
            const borderColor = modal.querySelector(`#${prefix}-table-border-color`).value;
            const cellPadding = modal.querySelector(`#${prefix}-table-padding`).value;
            const tableWidth = modal.querySelector(`#${prefix}-table-width`).value;

            this.applyTableProperties(table, {
                borderWidth: parseInt(borderWidth, 10),
                borderColor: borderColor,
                cellPadding: parseInt(cellPadding, 10),
                tableWidth: parseInt(tableWidth, 10)
            });
        });
    }

    /**
     * Apply properties to a table
     *
     * @param {HTMLTableElement} table - The table element
     * @param {Object} props - The properties to apply
     * @private
     */
    applyTableProperties(table, props) {
        const { borderWidth, borderColor, cellPadding, tableWidth } = props;

        table.style.width = `${tableWidth}%`;

        // Apply border and padding to all cells
        const cells = table.querySelectorAll('td, th');
        cells.forEach(cell => {
            cell.style.border = `${borderWidth}px solid ${borderColor}`;
            cell.style.padding = `${cellPadding}px`;
        });

        this.sync();
    }

    /**
     * Convert RGB color string to hex
     *
     * @param {string} rgb - RGB color string like "rgb(255, 0, 0)"
     * @returns {string} Hex color string like "#ff0000"
     * @private
     */
    rgbToHex(rgb) {
        const match = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
        if (!match) return null;

        const r = parseInt(match[1], 10).toString(16).padStart(2, '0');
        const g = parseInt(match[2], 10).toString(16).padStart(2, '0');
        const b = parseInt(match[3], 10).toString(16).padStart(2, '0');

        return `#${r}${g}${b}`;
    }

    /**
     * Insert a new row into the table
     *
     * @param {HTMLTableElement} table - The table element
     * @param {HTMLTableCellElement} cell - The reference cell
     * @param {string} position - 'above' or 'below'
     */
    insertTableRow(table, cell, position) {
        const row = cell ? cell.closest('tr') : table.querySelector('tr');
        if (!row) return;

        const colCount = row.cells.length;
        const newRow = document.createElement('tr');

        for (let i = 0; i < colCount; i++) {
            const td = document.createElement('td');
            td.innerHTML = '&nbsp;';
            // Copy styles from existing cells
            const existingCell = row.cells[0];
            if (existingCell) {
                td.style.border = existingCell.style.border || '';
                td.style.padding = existingCell.style.padding || '';
            }
            newRow.appendChild(td);
        }

        if (position === 'above') {
            row.parentNode.insertBefore(newRow, row);
        } else {
            row.parentNode.insertBefore(newRow, row.nextSibling);
        }

        this.sync();
    }

    /**
     * Insert a new column into the table
     *
     * @param {HTMLTableElement} table - The table element
     * @param {HTMLTableCellElement} cell - The reference cell
     * @param {string} position - 'left' or 'right'
     */
    insertTableColumn(table, cell, position) {
        const cellIndex = cell ? cell.cellIndex : 0;
        const rows = table.querySelectorAll('tr');

        rows.forEach(row => {
            const refCell = row.cells[cellIndex];
            if (!refCell) return;

            const isHeader = refCell.tagName === 'TH';
            const newCell = document.createElement(isHeader ? 'th' : 'td');
            newCell.innerHTML = '&nbsp;';
            // Copy styles from existing cells
            newCell.style.border = refCell.style.border || '';
            newCell.style.padding = refCell.style.padding || '';

            if (position === 'left') {
                row.insertBefore(newCell, refCell);
            } else {
                row.insertBefore(newCell, refCell.nextSibling);
            }
        });

        this.sync();
    }

    /**
     * Delete a row from the table
     *
     * @param {HTMLTableElement} table - The table element
     * @param {HTMLTableCellElement} cell - A cell in the row to delete
     */
    deleteTableRow(table, cell) {
        const row = cell ? cell.closest('tr') : null;
        if (!row) return;

        // Don't delete if it's the last row
        if (table.querySelectorAll('tr').length <= 1) {
            return;
        }

        row.remove();
        this.sync();
    }

    /**
     * Delete a column from the table
     *
     * @param {HTMLTableElement} table - The table element
     * @param {HTMLTableCellElement} cell - A cell in the column to delete
     */
    deleteTableColumn(table, cell) {
        if (!cell) return;

        const cellIndex = cell.cellIndex;
        const rows = table.querySelectorAll('tr');

        // Don't delete if it's the last column
        const firstRow = rows[0];
        if (firstRow && firstRow.cells.length <= 1) {
            return;
        }

        rows.forEach(row => {
            if (row.cells[cellIndex]) {
                row.cells[cellIndex].remove();
            }
        });

        this.sync();
    }

    /**
     * Delete a table
     *
     * @param {HTMLTableElement} table - The table element
     */
    deleteTable(table) {
        this.deselectTable();
        table.remove();
        this.sync();
    }

    /**
     * Handle keyboard shortcuts
     *
     * @param {KeyboardEvent} e - The keyboard event
     * @private
     */
    handleKeyboard(e) {
        if (!this.config.shortcuts) return;

        const isMac = navigator.platform.includes('Mac');
        const modifier = isMac ? e.metaKey : e.ctrlKey;

        if (!modifier) return;

        const shortcuts = {
            'b': 'bold',
            'i': 'italic',
            'u': 'underline'
        };

        const key = e.key.toLowerCase();

        // Handle Ctrl+K for link
        if (key === 'k') {
            e.preventDefault();
            this.insertLink();
            return;
        }

        // Handle Ctrl+Shift+Z for redo
        if (e.shiftKey && key === 'z') {
            e.preventDefault();
            this.exec('redo');
            return;
        }

        if (shortcuts[key]) {
            e.preventDefault();
            this.exec(shortcuts[key]);
        }
    }

    /**
     * Handle paste events
     *
     * @param {ClipboardEvent} e - The paste event
     * @private
     */
    handlePaste(e) {
        if (this.config.pasteAsPlainText) {
            e.preventDefault();
            const text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
            this.sync();
            return;
        }

        // For HTML paste, let default behavior happen but sync after
        setTimeout(() => {
            this.sanitizeContent();
            this.sync();
        }, 0);
    }

    /**
     * Sanitize the editor content (remove dangerous elements)
     * @private
     */
    sanitizeContent() {
        // Remove dangerous elements
        const dangerous = this.editor.querySelectorAll('script, style, link, meta, iframe, object, embed');
        dangerous.forEach(el => el.remove());

        // Remove event handlers from all elements
        this.editor.querySelectorAll('*').forEach(el => {
            [...el.attributes].forEach(attr => {
                if (attr.name.startsWith('on')) {
                    el.removeAttribute(attr.name);
                }
            });
        });
    }

    /**
     * Normalize content - convert div tags to p tags for consistency
     * @private
     */
    normalizeContent() {
        let changed = true;
        let iterations = 0;
        const maxIterations = 10;

        // Loop until no more changes (handles nested divs)
        while (changed && iterations < maxIterations) {
            changed = false;
            iterations++;

            this.editor.querySelectorAll('div').forEach(div => {
                // Unwrap divs that contain only a single block element
                const blockChild = div.querySelector(':scope > ul, :scope > ol, :scope > h1, :scope > h2, :scope > h3, :scope > h4, :scope > h5, :scope > h6, :scope > blockquote, :scope > p');
                if (blockChild && div.children.length === 1 && div.textContent.trim() === blockChild.textContent.trim()) {
                    div.replaceWith(blockChild);
                    changed = true;
                    return;
                }

                // Skip divs that contain nested block elements
                if (div.querySelector('div, p, ul, ol, h1, h2, h3, h4, h5, h6, blockquote')) {
                    return;
                }

                // Convert simple div to p
                const p = document.createElement('p');
                p.innerHTML = div.innerHTML;
                div.replaceWith(p);
                changed = true;
            });
        }
    }

    /**
     * Update toolbar button active states
     * @private
     */
    updateToolbarState() {
        const buttons = this.toolbar.querySelectorAll('[data-command]');
        const activeClass = `${this.config.classPrefix}-btn-active`;

        buttons.forEach(btn => {
            const command = btn.dataset.command;
            const value = btn.dataset.value;

            btn.classList.remove(activeClass);

            // Check formatBlock for headings, blockquote, pre
            if (command === 'formatBlock' && value) {
                const currentBlock = document.queryCommandValue('formatBlock');
                if (currentBlock.toLowerCase() === value.toLowerCase()) {
                    btn.classList.add(activeClass);
                }
            // DOM-based check for subscript (Firefox queryCommandState unreliable)
            } else if (command === 'subscript') {
                if (this.isInsideTag('sub')) {
                    btn.classList.add(activeClass);
                }
            // DOM-based check for superscript (Firefox queryCommandState unreliable)
            } else if (command === 'superscript') {
                if (this.isInsideTag('sup')) {
                    btn.classList.add(activeClass);
                }
            // CSS-based check for justifyFull (Safari queryCommandState unreliable)
            } else if (command === 'justifyFull') {
                const block = this.getSelectedBlockElement();
                if (block && getComputedStyle(block).textAlign === 'justify') {
                    btn.classList.add(activeClass);
                }
            } else {
                // Check if command is currently active
                try {
                    if (document.queryCommandState(command)) {
                        btn.classList.add(activeClass);
                    }
                } catch (e) {
                    // Some commands don't support queryCommandState
                }
            }
        });
    }

    /**
     * Get clean HTML content without editor UI elements
     *
     * @returns {string} Clean HTML content
     * @private
     */
    getCleanContent() {
        const prefix = this.config.classPrefix;

        // Clone the editor content
        const clone = this.editor.cloneNode(true);

        // Remove editor UI elements (toolbars, resizers, selection classes)
        clone.querySelectorAll(`.${prefix}-image-toolbar, .${prefix}-image-resizer, .${prefix}-table-toolbar`).forEach(el => el.remove());

        // Remove selection classes from elements
        clone.querySelectorAll(`.${prefix}-image-selected`).forEach(el => el.classList.remove(`${prefix}-image-selected`));
        clone.querySelectorAll(`.${prefix}-table-selected`).forEach(el => el.classList.remove(`${prefix}-table-selected`));

        return clone.innerHTML;
    }

    /**
     * Sanitize HTML to remove any embedded editor UI elements
     * This cleans up content that may have been saved with toolbars accidentally
     *
     * @param {string} html - The HTML content to sanitize
     * @returns {string} Sanitized HTML content
     * @private
     */
    sanitizeEditorUI(html) {
        const prefix = this.config.classPrefix;
        const temp = document.createElement('div');
        temp.innerHTML = html;

        // Remove any editor UI elements that were accidentally saved
        temp.querySelectorAll(`.${prefix}-image-toolbar, .${prefix}-image-resizer, .${prefix}-table-toolbar`).forEach(el => el.remove());

        // Remove selection classes
        temp.querySelectorAll(`.${prefix}-image-selected`).forEach(el => el.classList.remove(`${prefix}-image-selected`));
        temp.querySelectorAll(`.${prefix}-table-selected`).forEach(el => el.classList.remove(`${prefix}-table-selected`));

        return temp.innerHTML;
    }

    /**
     * Sync editor content to the hidden textarea
     */
    sync() {
        this.normalizeContent();
        this.textarea.value = this.getCleanContent();
    }

    /**
     * Get the current HTML content
     *
     * @returns {string} The editor HTML content
     */
    getContent() {
        return this.getCleanContent();
    }

    /**
     * Set the editor HTML content
     *
     * @param {string} html - The HTML content to set
     */
    setContent(html) {
        this.editor.innerHTML = this.sanitizeEditorUI(html);
        this.sync();
    }

    /**
     * Get the current plain text content
     *
     * @returns {string} The editor plain text content
     */
    getText() {
        return this.editor.textContent || this.editor.innerText;
    }

    /**
     * Focus the editor
     */
    focus() {
        this.editor.focus();
    }

    /**
     * Blur the editor
     */
    blur() {
        this.editor.blur();
    }

    /**
     * Check if the editor is empty
     *
     * @returns {boolean} True if the editor is empty
     */
    isEmpty() {
        const text = this.getText().trim();
        return text.length === 0;
    }

    /**
     * Destroy the editor and restore the original textarea
     */
    destroy() {
        // Remove document click handler
        if (this.documentClickHandler) {
            document.removeEventListener('click', this.documentClickHandler);
            this.documentClickHandler = null;
        }

        // Deselect any image
        this.deselectImage();

        // Move textarea back and show it
        this.wrapper.parentNode.insertBefore(this.textarea, this.wrapper);
        this.textarea.style.display = '';

        // Remove the wrapper
        this.wrapper.remove();

        // Clear references
        this.wrapper = null;
        this.toolbar = null;
        this.editor = null;
        this.codeEditor = null;
        this.savedSelection = null;
        this.selectedImage = null;
        this.imageToolbar = null;
        this.imageResizer = null;
    }

    /**
     * Escape HTML special characters
     *
     * @param {string} str - The string to escape
     * @returns {string} The escaped string
     * @private
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    /**
     * Factory method to create editor instances
     *
     * @param {string} selector - CSS selector for textarea(s)
     * @param {Object} options - Configuration options
     * @returns {WYSIWYGEditor|WYSIWYGEditor[]} Single editor or array of editors
     */
    static init(selector, options = {}) {
        const elements = document.querySelectorAll(selector);

        if (elements.length === 0) {
            throw new Error(`No elements found for selector: ${selector}`);
        }

        if (elements.length === 1) {
            return new WYSIWYGEditor(elements[0], options);
        }

        return Array.from(elements).map(el => new WYSIWYGEditor(el, options));
    }
}

// Export for different module systems
if (typeof module !== 'undefined' && module.exports) {
    module.exports = WYSIWYGEditor;
}
