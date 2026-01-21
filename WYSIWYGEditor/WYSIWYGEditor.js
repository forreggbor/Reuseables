/**
 * WYSIWYGEditor - Lightweight rich text editor without external dependencies
 *
 * A simple, customizable WYSIWYG editor that transforms a textarea into a rich text editor
 * using native browser APIs (contenteditable, execCommand).
 *
 * @package WYSIWYGEditor
 * @version 1.0.1
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
            'bold', 'italic', 'underline', 'strikethrough', '|',
            'h1', 'h2', 'h3', '|',
            'ul', 'ol', '|',
            'link', 'unlink', '|',
            'alignLeft', 'alignCenter', 'alignRight', '|',
            'undo', 'redo', '|',
            'clearFormat'
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
        linkTargetBlank: true
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
        h1: { icon: 'H1', title: 'Heading 1', command: 'formatBlock', value: 'h1' },
        h2: { icon: 'H2', title: 'Heading 2', command: 'formatBlock', value: 'h2' },
        h3: { icon: 'H3', title: 'Heading 3', command: 'formatBlock', value: 'h3' },
        ul: { icon: '&#8226;', title: 'Bullet List', command: 'insertUnorderedList' },
        ol: { icon: '1.', title: 'Numbered List', command: 'insertOrderedList' },
        link: { icon: '&#128279;', title: 'Insert Link (Ctrl+K)', command: 'link', custom: true },
        unlink: { icon: '&#10060;', title: 'Remove Link', command: 'unlink' },
        alignLeft: { icon: '&#8676;', title: 'Align Left', command: 'justifyLeft' },
        alignCenter: { icon: '&#8596;', title: 'Align Center', command: 'justifyCenter' },
        alignRight: { icon: '&#8677;', title: 'Align Right', command: 'justifyRight' },
        undo: { icon: '&#8617;', title: 'Undo (Ctrl+Z)', command: 'undo' },
        redo: { icon: '&#8618;', title: 'Redo (Ctrl+Y)', command: 'redo' },
        clearFormat: { icon: 'T&#824;', title: 'Clear Formatting', command: 'removeFormat' },
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
        .wysiwyg-separator {
            display: inline-block;
            width: 1px;
            height: 24px;
            margin: 4px 6px;
            background: #ccc;
        }
        .wysiwyg-editor {
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
        this.bindEvents();

        // Set initial content from textarea
        if (this.textarea.value) {
            this.editor.innerHTML = this.textarea.value;
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
     * Bind event listeners
     * @private
     */
    bindEvents() {
        // Toolbar button clicks via event delegation
        this.toolbar.addEventListener('click', (e) => {
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
            document.execCommand(command, false, `<${value}>`);
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
        if (command === 'link') {
            this.insertLink();
        }
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

        buttons.forEach(btn => {
            const command = btn.dataset.command;
            const value = btn.dataset.value;

            btn.classList.remove(`${this.config.classPrefix}-btn-active`);

            // Check formatBlock for headings
            if (command === 'formatBlock' && value) {
                const currentBlock = document.queryCommandValue('formatBlock');
                if (currentBlock.toLowerCase() === value.toLowerCase()) {
                    btn.classList.add(`${this.config.classPrefix}-btn-active`);
                }
            } else {
                // Check if command is currently active
                try {
                    if (document.queryCommandState(command)) {
                        btn.classList.add(`${this.config.classPrefix}-btn-active`);
                    }
                } catch (e) {
                    // Some commands don't support queryCommandState
                }
            }
        });
    }

    /**
     * Sync editor content to the hidden textarea
     */
    sync() {
        this.normalizeContent();
        this.textarea.value = this.editor.innerHTML;
    }

    /**
     * Get the current HTML content
     *
     * @returns {string} The editor HTML content
     */
    getContent() {
        return this.editor.innerHTML;
    }

    /**
     * Set the editor HTML content
     *
     * @param {string} html - The HTML content to set
     */
    setContent(html) {
        this.editor.innerHTML = html;
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
        // Move textarea back and show it
        this.wrapper.parentNode.insertBefore(this.textarea, this.wrapper);
        this.textarea.style.display = '';

        // Remove the wrapper
        this.wrapper.remove();

        // Clear references
        this.wrapper = null;
        this.toolbar = null;
        this.editor = null;
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
