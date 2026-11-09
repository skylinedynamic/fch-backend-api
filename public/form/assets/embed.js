/*
 * Four Corner Funding embedded application - automatic height.
 *
 * Pasted by a partner with the embed code, next to an
 * <iframe data-fch-embed src=".../form/{key}">. The form posts its content
 * height and where to scroll (resources/views/embed-form/index.blade.php,
 * source 'fch-embed-form'); this sizes the iframe to the form so it never
 * scrolls inside itself. The form also posts a bare "application confirmed"
 * signal for the website's analytics, which this script ignores. Never field values, and only
 * from an iframe on this page, served from this script's own origin.
 */
(function () {
    'use strict';

    var SOURCE = 'fch-embed-form';
    var MIN_HEIGHT = 600;
    var MAX_HEIGHT = 20000;
    // Room left above a scrolled-to field for a sticky site header.
    var SCROLL_OFFSET = 100;

    if (window.__fchEmbedFormLoaded) return;
    window.__fchEmbedFormLoaded = true;

    var script = document.currentScript;
    var formOrigin = null;
    try {
        formOrigin = new URL(script && script.src ? script.src : '').origin;
    } catch (e) {
        return;
    }

    function frameFor(source) {
        var frames = document.querySelectorAll('iframe[data-fch-embed]');
        for (var i = 0; i < frames.length; i++) {
            if (frames[i].contentWindow === source) return frames[i];
        }
        return null;
    }

    function isNumber(value) {
        return typeof value === 'number' && isFinite(value);
    }

    // Introduce this page to each form, so it reports its height even when the
    // browser sent it no referrer to learn this page's origin from.
    function sayHello(frame) {
        if (!frame.contentWindow || !formLoaded(frame)) return;
        frame.contentWindow.postMessage({ source: SOURCE, type: 'hello' }, formOrigin);
    }

    // Until the form arrives the iframe holds a blank page of this page's own
    // origin, which this page can read; the loaded form cannot be read.
    function formLoaded(frame) {
        try {
            return !frame.contentWindow.location.href;
        } catch (e) {
            return true;
        }
    }

    function greet(frame) {
        if (frame.__fchEmbedGreeted) return;
        frame.__fchEmbedGreeted = true;
        frame.addEventListener('load', function () { sayHello(frame); });
        sayHello(frame);
    }

    function greetAll() {
        var frames = document.querySelectorAll('iframe[data-fch-embed]');
        for (var i = 0; i < frames.length; i++) greet(frames[i]);
    }

    greetAll();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', greetAll);
    }

    window.addEventListener('message', function (event) {
        if (event.origin !== formOrigin) return;

        var data = event.data;
        if (!data || typeof data !== 'object' || data.source !== SOURCE) return;

        var frame = frameFor(event.source);
        if (!frame) return;

        if (data.type === 'resize' && isNumber(data.height)) {
            var height = Math.min(MAX_HEIGHT, Math.max(MIN_HEIGHT, Math.ceil(data.height)));
            frame.style.height = height + 'px';
            // The attribute too, so the page's inspector shows the real height.
            frame.setAttribute('height', String(height));
            frame.setAttribute('scrolling', 'no');
            return;
        }

        if (data.type === 'scroll' && isNumber(data.top)) {
            var frameTop = frame.getBoundingClientRect().top + window.pageYOffset;
            var top = Math.max(0, frameTop + data.top - SCROLL_OFFSET);
            try {
                window.scrollTo({ top: top, behavior: 'smooth' });
            } catch (e) {
                window.scrollTo(0, top);
            }
        }
    });
})();
