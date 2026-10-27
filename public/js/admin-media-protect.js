/**
 * Admin Media Protection Engine (module-agnostic).
 * - Blocks drag / right-click / middle-click on all media.
 * - Repaints display thumbnails onto <canvas> (no <img> to drag/open/save).
 * - Shields form-preview images with a revoke-on-paint blob + pointer-events:none.
 * - Hardens <video> (no download/PiP/remote playback).
 * - Re-applies to AJAX-injected DOM via MutationObserver.
 */
(function () {
    'use strict';

    var blobCache = new Map();
    var pendingFetches = new Map();

    // 0. CSS that disables native dragging / selection of media.
    (function injectProtectionStyles() {
        if (document.getElementById('admin-media-protect-style')) return;
        var css = 'img,video,canvas{'
            + '-webkit-user-drag:none !important;-khtml-user-drag:none !important;'
            + '-moz-user-drag:none !important;-o-user-drag:none !important;user-drag:none !important;'
            + '-webkit-user-select:none !important;-moz-user-select:none !important;'
            + '-ms-user-select:none !important;user-select:none !important;'
            + '-webkit-touch-callout:none !important;}';
        var style = document.createElement('style');
        style.id = 'admin-media-protect-style';
        style.appendChild(document.createTextNode(css));
        (document.head || document.documentElement).appendChild(style);
    })();

    // 1. Drag prevention (capture phase).
    document.addEventListener('dragstart', function (e) {
        var t = e.target;
        if (t && t.nodeType === 1 && (t.tagName === 'IMG' || t.tagName === 'VIDEO' || t.tagName === 'CANVAS'
            || t.closest('img, video, canvas, .view-more-btn, .dropify-render'))) {
            e.preventDefault(); e.stopPropagation(); return false;
        }
    }, true);

    // 2. Right-click prevention on media.
    document.addEventListener('contextmenu', function (e) {
        var t = e.target;
        if (t && t.nodeType === 1 && (t.tagName === 'IMG' || t.tagName === 'VIDEO' || t.tagName === 'CANVAS'
            || t.closest('.view-more-btn, .dropify-render'))) {
            e.preventDefault(); e.stopPropagation(); return false;
        }
    }, true);

    // 3. Middle-click prevention.
    document.addEventListener('auxclick', function (e) {
        var t = e.target;
        if (e.button === 1 && t && (t.tagName === 'IMG' || t.tagName === 'VIDEO'
            || t.closest('a:has(img), a:has(video)'))) {
            e.preventDefault(); e.stopPropagation(); return false;
        }
    }, true);

    function protectMediaElement(el) {
        if (!el || el.nodeType !== 1) return;
        if (el.getAttribute('draggable') !== 'false') el.setAttribute('draggable', 'false');

        var tag = el.tagName.toUpperCase();
        if (tag !== 'IMG' && tag !== 'VIDEO') return;

        var src = el.getAttribute('src');
        if (!src) return;
        if (src.indexOf('blob:') === 0 || src.indexOf('data:') === 0) return;

        var isProtectedMedia = src.indexOf('/secure-media') !== -1 || src.indexOf('/upload/') !== -1
            || src.indexOf('upload/') !== -1
            || /\.(png|jpe?g|webp|gif|svg|mp4|mov|avi|wmv)(\?.*)?$/i.test(src);
        if (!isProtectedMedia) return;

        if (el.hasAttribute('data-path')) el.removeAttribute('data-path');
        if (el.hasAttribute('data-original-src')) el.removeAttribute('data-original-src');

        if (tag === 'VIDEO') {
            el.setAttribute('controlsList', 'nodownload noplaybackrate noremoteplayback');
            el.setAttribute('disablePictureInPicture', '');
            el.setAttribute('disableRemotePlayback', '');
        }

        if (tag === 'IMG' && !el.closest('a, button, label, .dropify, .dropify-wrapper, .view-more-btn')) {
            el.style.setProperty('pointer-events', 'none', 'important');
        }

        var isImage = (tag === 'IMG');
        var canConvert = isImage && !el.id &&
            !el.closest('form, a, button, label, .dropify, .dropify-wrapper, .dropify-render, .view-more-btn');

        function paintCanvasFromBlob(blob) {
            var objectUrl = URL.createObjectURL(blob);
            var image = new Image();
            image.onload = function () {
                var rect = el.getBoundingClientRect();
                var cssW = Math.round(rect.width) || el.width || 50;
                var cssH = Math.round(rect.height) || el.height || 50;
                var dpr = window.devicePixelRatio || 1;

                var canvas = document.createElement('canvas');
                canvas.className = el.className;
                var styleAttr = el.getAttribute('style') || '';
                canvas.setAttribute('style', styleAttr);
                if (!/(^|;)\s*width\s*:/.test(styleAttr)) canvas.style.width = cssW + 'px';
                if (!/(^|;)\s*height\s*:/.test(styleAttr)) canvas.style.height = cssH + 'px';
                canvas.style.setProperty('pointer-events', 'none', 'important');
                canvas.style.setProperty('-webkit-user-drag', 'none', 'important');
                canvas.style.setProperty('user-select', 'none', 'important');
                canvas.setAttribute('draggable', 'false');

                canvas.width = Math.max(1, Math.round(cssW * dpr));
                canvas.height = Math.max(1, Math.round(cssH * dpr));

                var iw = image.naturalWidth, ih = image.naturalHeight;
                if (iw && ih) {
                    var ctx = canvas.getContext('2d');
                    var scale = Math.max(canvas.width / iw, canvas.height / ih);
                    var dw = iw * scale, dh = ih * scale;
                    ctx.drawImage(image, (canvas.width - dw) / 2, (canvas.height - dh) / 2, dw, dh);
                }
                URL.revokeObjectURL(objectUrl);
                if (el.parentNode) el.parentNode.replaceChild(canvas, el);
            };
            image.onerror = function () { URL.revokeObjectURL(objectUrl); };
            image.src = objectUrl;
        }

        function applyBlob(blob) {
            if (!blob) return;
            if (el.getAttribute('src') !== src) return;
            if (canConvert) { paintCanvasFromBlob(blob); return; }

            var objectUrl = URL.createObjectURL(blob);
            if (isImage) {
                var revoke = function () {
                    URL.revokeObjectURL(objectUrl);
                    el.removeEventListener('load', revoke);
                    el.removeEventListener('error', revoke);
                };
                el.addEventListener('load', revoke);
                el.addEventListener('error', revoke);
            }
            el.src = objectUrl;
        }

        if (blobCache.has(src)) { applyBlob(blobCache.get(src)); return; }

        var fetchPromise = pendingFetches.get(src);
        if (!fetchPromise) {
            fetchPromise = fetch(src, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (res) { if (!res.ok) throw new Error(res.status); return res.blob(); })
                .then(function (blob) { blobCache.set(src, blob); pendingFetches.delete(src); return blob; })
                .catch(function () { pendingFetches.delete(src); return null; });
            pendingFetches.set(src, fetchPromise);
        }
        fetchPromise.then(function (blob) { applyBlob(blob); });
    }

    function scanAndProtect(root) {
        var list = (root || document).querySelectorAll('img, video');
        for (var i = 0; i < list.length; i++) protectMediaElement(list[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scanAndProtect(document); });
    } else {
        scanAndProtect(document);
    }

    if (window.MutationObserver) {
        new MutationObserver(function (mutations) {
            for (var m = 0; m < mutations.length; m++) {
                var mu = mutations[m];
                if (mu.type === 'childList') {
                    for (var n = 0; n < mu.addedNodes.length; n++) {
                        var node = mu.addedNodes[n];
                        if (node.nodeType === 1) {
                            if (node.tagName === 'IMG' || node.tagName === 'VIDEO') protectMediaElement(node);
                            else if (node.querySelectorAll) scanAndProtect(node);
                        }
                    }
                } else if (mu.type === 'attributes' && mu.attributeName === 'src') {
                    var t = mu.target;
                    if (t && (t.tagName === 'IMG' || t.tagName === 'VIDEO')) {
                        var cs = t.getAttribute('src');
                        if (cs && cs.indexOf('blob:') !== 0 && cs.indexOf('data:') !== 0) protectMediaElement(t);
                    }
                }
            }
        }).observe(document.documentElement || document.body,
            { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
    }

    // 4. Hardened video playback (id-based blob streaming — no media URL in the DOM).
    //    A ".video-btn[data-stream-url]" trigger POSTs (auth + CSRF) to an endpoint that
    //    streams the bytes back; we play them from a blob in a locked-down <video> overlay.
    (function setupVideoPlayer() {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
        var overlay, videoEl, spinnerEl, currentBlobUrl = null, inFlight = null;

        function buildOverlay() {
            overlay = document.createElement('div');
            overlay.id = 'amp-video-overlay';
            overlay.setAttribute('style', 'position:fixed;inset:0;z-index:99999;display:none;'
                + 'align-items:center;justify-content:center;background:rgba(0,0,0,.85);');

            var box = document.createElement('div');
            box.setAttribute('style', 'position:relative;max-width:92vw;max-height:92vh;');

            videoEl = document.createElement('video');
            videoEl.setAttribute('controls', '');
            videoEl.setAttribute('controlsList', 'nodownload noplaybackrate noremoteplayback');
            videoEl.setAttribute('disablePictureInPicture', '');
            videoEl.setAttribute('disableRemotePlayback', '');
            videoEl.setAttribute('playsinline', '');
            videoEl.setAttribute('draggable', 'false');
            videoEl.setAttribute('style', 'max-width:92vw;max-height:88vh;border-radius:10px;background:#000;outline:none;display:block;');
            videoEl.addEventListener('contextmenu', function (e) { e.preventDefault(); return false; });

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.innerHTML = '&times;';
            closeBtn.setAttribute('style', 'position:absolute;top:-16px;right:-16px;width:38px;height:38px;'
                + 'border-radius:50%;border:none;background:#fff;color:#111;font-size:22px;line-height:1;'
                + 'cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.5);');
            closeBtn.addEventListener('click', closePlayer);

            spinnerEl = document.createElement('div');
            spinnerEl.id = 'amp-video-spinner';
            spinnerEl.textContent = 'Loading…';
            spinnerEl.setAttribute('style', 'position:absolute;inset:0;display:flex;align-items:center;'
                + 'justify-content:center;color:#fff;font:14px sans-serif;pointer-events:none;');

            box.appendChild(videoEl);
            box.appendChild(closeBtn);
            box.appendChild(spinnerEl);
            overlay.appendChild(box);

            overlay.addEventListener('click', function (e) { if (e.target === overlay) closePlayer(); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay && overlay.style.display !== 'none') closePlayer();
            });

            (document.body || document.documentElement).appendChild(overlay);
        }

        function closePlayer() {
            if (!overlay) return;
            try { videoEl.pause(); } catch (e) {}
            inFlight = null;
            videoEl.removeAttribute('src');
            try { videoEl.load(); } catch (e) {}
            if (currentBlobUrl) { URL.revokeObjectURL(currentBlobUrl); currentBlobUrl = null; }
            overlay.style.display = 'none';
        }

        function openPlayer(url) {
            if (!url) return;
            if (!overlay) buildOverlay();
            spinnerEl.textContent = 'Loading…';
            spinnerEl.style.display = 'flex';
            overlay.style.display = 'flex';

            var token = {};
            inFlight = token;

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (res) { if (!res.ok) throw new Error(res.status); return res.blob(); })
                .then(function (blob) {
                    if (inFlight !== token) return; // player was closed / superseded
                    if (currentBlobUrl) URL.revokeObjectURL(currentBlobUrl);
                    currentBlobUrl = URL.createObjectURL(blob);
                    videoEl.src = currentBlobUrl;
                    spinnerEl.style.display = 'none';
                    var p = videoEl.play();
                    if (p && p.catch) p.catch(function () {});
                })
                .catch(function () {
                    if (inFlight !== token) return;
                    spinnerEl.textContent = 'Unable to load video.';
                });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('.video-btn[data-stream-url]') : null;
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                openPlayer(btn.getAttribute('data-stream-url'));
            }
        }, true);
    })();

    window.AdminMediaProtection = { scan: scanAndProtect, protect: protectMediaElement };
})();
