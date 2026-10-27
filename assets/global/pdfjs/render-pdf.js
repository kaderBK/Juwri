// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * Shared "files -> PNG image Blobs" helper. Vendored pdf.js (no CDN).
 * Used by: menu-scan (multi-page import), pageview (Mode B display), studio.
 *
 * window.renderFilesToImages(files, { maxPages, onProgress }) -> Promise<Blob[]>
 *   - image files pass through unchanged
 *   - each PDF is rasterised page-by-page (client-side) to PNG blobs
 *   - onProgress(pageNum, totalPages) fires per rendered PDF page
 */
(function () {
    var LIB = '/assets/global/pdfjs/pdf.min.js';
    var WORKER = '/assets/global/pdfjs/pdf.worker.min.js';

    function ensurePdfjs() {
        if (window.pdfjsLib) {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER;
            return Promise.resolve(window.pdfjsLib);
        }
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = LIB;
            s.onload = function () {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = WORKER;
                resolve(window.pdfjsLib);
            };
            s.onerror = function () { reject(new Error('pdfjs failed to load')); };
            document.head.appendChild(s);
        });
    }

    function pdfToImages(file, opts) {
        var maxPages = (opts && opts.maxPages) || 15;
        var onProgress = (opts && opts.onProgress) || function () {};
        return ensurePdfjs().then(function (pdfjsLib) {
            return file.arrayBuffer().then(function (buf) {
                return pdfjsLib.getDocument({ data: buf }).promise.then(function (pdf) {
                    var n = Math.min(pdf.numPages, maxPages);
                    var out = [];
                    var chain = Promise.resolve();
                    for (var i = 1; i <= n; i++) {
                        (function (pageNum) {
                            chain = chain.then(function () {
                                onProgress(pageNum, n);
                                return pdf.getPage(pageNum).then(function (page) {
                                    var vp = page.getViewport({ scale: 2 });
                                    var canvas = document.createElement('canvas');
                                    canvas.width = vp.width;
                                    canvas.height = vp.height;
                                    return page.render({
                                        canvasContext: canvas.getContext('2d'),
                                        viewport: vp
                                    }).promise.then(function () {
                                        return new Promise(function (r) {
                                            canvas.toBlob(function (b) { out.push(b); r(); }, 'image/png');
                                        });
                                    });
                                });
                            });
                        })(i);
                    }
                    return chain.then(function () { return out; });
                });
            });
        });
    }

    // Render a stored PDF (by URL) directly into page <section> elements appended
    // to a container. Used by the Page View template's Mode B (uploaded PDF display).
    window.renderPdfUrlToPages = function (url, container, opts) {
        var scale = (opts && opts.scale) || 2;
        var maxPages = (opts && opts.maxPages) || 40;
        return ensurePdfjs().then(function (pdfjsLib) {
            return pdfjsLib.getDocument(url).promise.then(function (pdf) {
                var n = Math.min(pdf.numPages, maxPages);
                var chain = Promise.resolve();
                for (var i = 1; i <= n; i++) {
                    (function (pageNum) {
                        chain = chain.then(function () {
                            return pdf.getPage(pageNum).then(function (page) {
                                var vp = page.getViewport({ scale: scale });
                                var section = document.createElement('section');
                                section.className = 'pv-page' + (pageNum === 1 ? ' is-active' : '');
                                section.setAttribute('aria-label', 'Page ' + pageNum);
                                var canvas = document.createElement('canvas');
                                canvas.width = vp.width; canvas.height = vp.height;
                                canvas.style.maxWidth = '100%'; canvas.style.height = 'auto';
                                section.appendChild(canvas);
                                container.appendChild(section);
                                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                            });
                        });
                    })(i);
                }
                return chain;
            });
        });
    };

    window.renderFilesToImages = function (files, opts) {
        var arr = Array.prototype.slice.call(files || []);
        var out = [];
        return arr.reduce(function (chain, f) {
            return chain.then(function () {
                if (f.type === 'application/pdf' || /\.pdf$/i.test(f.name || '')) {
                    return pdfToImages(f, opts).then(function (blobs) { out = out.concat(blobs); });
                }
                out.push(f); // already an image
            });
        }, Promise.resolve()).then(function () { return out; });
    };
})();
