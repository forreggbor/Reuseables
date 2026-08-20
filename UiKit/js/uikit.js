/**
 * UiKit — shared core.
 * @version 1.00.00
 *
 * Project-independent: no host globals are read (no #appConfig, no
 * window.CSRF_TOKEN, no __()), no network requests, no dependency on any
 * host CSS framework. A host page wires host-specific text into UiKit via
 * configure() before any component is used; every component still works
 * with its own English built-in defaults if configure() is never called.
 *
 * Individual components (ui-toast.js, ui-dialog.js, ...) are separate files
 * that attach themselves to the same `window.UiKit` object and may call the
 * internal `UiKit._label()` helper below.
 */
(function (global) {
    'use strict';

    var labels = {};

    /**
     * One-time (or repeatable) host configuration. Currently accepts only
     * `labels` — a flat map of label keys to host-provided translated text,
     * overriding a component's built-in English default for that key.
     *
     * @param {{labels?: Object<string, string>}} options
     */
    function configure(options) {
        options = options || {};
        if (options.labels) {
            for (var key in options.labels) {
                if (Object.prototype.hasOwnProperty.call(options.labels, key)) {
                    labels[key] = options.labels[key];
                }
            }
        }
    }

    /**
     * Resolve a label: host-configured override, else the component's own
     * built-in default.
     *
     * @param {string} key
     * @param {string} fallback
     * @returns {string}
     */
    function label(key, fallback) {
        return Object.prototype.hasOwnProperty.call(labels, key) ? labels[key] : fallback;
    }

    global.UiKit = global.UiKit || {};
    global.UiKit.configure = configure;
    global.UiKit._label = label;
})(window);
