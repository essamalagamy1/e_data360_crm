/* Invoice actions shared by the staff screen, the print page and the client's link.

   Files are drawn by the browser's own text engine (an SVG foreignObject on a canvas),
   not by a page-to-image library, because those libraries break Arabic: they lose the
   spaces between words and misplace numbers. No library is needed at all. If a browser
   cannot do this, the print dialog opens instead, and "Save as PDF" there gives a
   perfect file with selectable text. */
(function () {
    'use strict';

    var sheet = document.getElementById('invoiceSheet');
    var tools = document.getElementById('invoiceTools');
    if (!sheet || !tools) { return; }

    var fileName = tools.dataset.name || 'invoice';
    var XHTML = 'http://www.w3.org/1999/xhtml';
    var A4_WIDTH = 794;
    var A4_HEIGHT = 1123;

    function blobToDataUrl(blob) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function () { resolve(reader.result); };
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });
    }
    function fetchAsDataUrl(url) {
        return fetch(url).then(function (response) {
            if (!response.ok) { throw new Error('Could not load ' + url); }
            return response.blob();
        }).then(blobToDataUrl);
    }

    /* The page's stylesheets with their fonts built in: an SVG image cannot load anything else. */
    var styleText = null;
    function loadStyles() {
        if (styleText) { return styleText; }
        var links = Array.prototype.filter.call(document.querySelectorAll('link[rel="stylesheet"]'), function (link) {
            return /edata\/css\/(edata|invoice)\.css/.test(link.href);
        });
        styleText = Promise.all(links.map(function (link) {
            return fetch(link.href).then(function (response) { return response.text(); }).then(function (css) {
                var found = css.match(/url\("[^"]+\.ttf"\)/g) || [];
                var unique = found.filter(function (item, index) { return found.indexOf(item) === index; });
                return Promise.all(unique.map(function (item) {
                    var relative = item.slice(5, -2);
                    return fetchAsDataUrl(new URL(relative, link.href).href).then(function (data) {
                        css = css.split(item).join('url("' + data + '")');
                    });
                })).then(function () { return css; });
            });
        })).then(function (parts) { return parts.join('\n'); });
        return styleText;
    }

    function inlineImages(copy) {
        return Promise.all(Array.prototype.map.call(copy.querySelectorAll('img'), function (image) {
            image.removeAttribute('onerror');
            return fetchAsDataUrl(image.src).then(function (data) {
                image.setAttribute('src', data);
            }).catch(function () {
                image.remove();
            });
        }));
    }

    /* Lays a copy out at page width offscreen, so a phone makes the same file as a laptop. */
    function measure(extraClass) {
        var copy = sheet.cloneNode(true);
        copy.removeAttribute('id');
        copy.classList.add(extraClass);
        var host = document.createElement('div');
        host.style.cssText = 'position:fixed;top:0;left:-9999px;width:' + A4_WIDTH + 'px;visibility:hidden;pointer-events:none';
        host.appendChild(copy);
        document.body.appendChild(host);

        var top = copy.getBoundingClientRect().top;
        var bottomOf = function (element) { return element.getBoundingClientRect().bottom - top; };
        var info = {
            height: Math.ceil(copy.getBoundingClientRect().height),
            breaks: Array.prototype.map.call(copy.querySelectorAll('.inv-head, .inv-hero, .inv-meta, .inv-lines tr, .inv-sum, .inv-block, .inv-foot'), bottomOf).sort(function (a, b) { return a - b; }),
            header: null
        };
        var head = copy.querySelector('.inv-lines thead');
        var table = copy.querySelector('.inv-lines');
        if (head) {
            info.header = { top: head.getBoundingClientRect().top - top, bottom: bottomOf(head), tableBottom: bottomOf(table) };
        }
        var hero = copy.querySelector('.inv-hero');
        info.probe = hero ? (hero.getBoundingClientRect().top - top + 20) : 30;
        host.remove();

        return { copy: copy, info: info };
    }

    function render(extraClass, scale) {
        var measured = measure(extraClass);
        var info = measured.info;

        return Promise.all([document.fonts.ready, loadStyles(), inlineImages(measured.copy)]).then(function (results) {
            var wrapper = document.createElementNS(XHTML, 'div');
            wrapper.setAttribute('dir', document.documentElement.getAttribute('dir') || 'rtl');
            wrapper.setAttribute('lang', document.documentElement.getAttribute('lang') || 'ar');
            wrapper.setAttribute('class', 'ed-app');
            wrapper.setAttribute('style', 'width:' + A4_WIDTH + 'px;background:#fff');
            var style = document.createElementNS(XHTML, 'style');
            style.textContent = results[1];
            wrapper.appendChild(style);
            wrapper.appendChild(measured.copy);

            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + A4_WIDTH + '" height="' + info.height + '">' +
                '<foreignObject width="100%" height="100%">' + new XMLSerializer().serializeToString(wrapper) + '</foreignObject></svg>';

            return new Promise(function (resolve, reject) {
                var image = new Image();
                image.onload = function () { resolve(image); };
                image.onerror = reject;
                image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
            });
        }).then(function (image) {
            var canvas = document.createElement('canvas');
            canvas.width = A4_WIDTH * scale;
            canvas.height = info.height * scale;
            var context = canvas.getContext('2d');
            var draw = function () {
                context.fillStyle = '#fff';
                context.fillRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
            };
            draw();
            // Some browsers finish applying embedded fonts just after the first paint.
            return new Promise(function (resolve) { setTimeout(resolve, 180); }).then(function () {
                draw();
                // Reading a pixel also proves the browser allowed the drawing. A blank result
                // must become the print fallback, never a blank file sent to a client.
                var row = context.getImageData(0, Math.round(info.probe * scale), canvas.width, 1).data;
                var inked = false;
                for (var i = 0; i < row.length; i += 4) {
                    if (row[i] < 200) { inked = true; break; }
                }
                if (!inked) { throw new Error('The browser drew an empty page.'); }
                return { canvas: canvas, info: info, scale: scale };
            });
        });
    }

    /* Cuts the drawing into A4 pages, always between rows, repeating the table header. */
    function slice(result) {
        var info = result.info;
        var scale = result.scale;
        var pad = 38;
        var pages = [];
        var start = 0;

        if (info.height <= A4_HEIGHT + 2) {
            return [{ from: 0, to: info.height, offset: 0, header: false }];
        }
        while (start < info.height - 1) {
            var first = start === 0;
            var room = A4_HEIGHT - (first ? 0 : pad) - pad;
            var header = !first && info.header && start >= info.header.bottom && start < info.header.tableBottom;
            if (header) { room -= (info.header.bottom - info.header.top); }

            var end = start + room;
            if (end >= info.height) {
                end = info.height;
            } else {
                var best = null;
                info.breaks.forEach(function (position) {
                    if (position > start + 1 && position <= end) { best = position; }
                });
                if (best !== null) { end = best; }
            }
            pages.push({ from: start, to: end, first: first, header: header });
            start = end;
        }

        return pages.map(function (page) {
            var canvas = document.createElement('canvas');
            canvas.width = A4_WIDTH * scale;
            canvas.height = A4_HEIGHT * scale;
            var context = canvas.getContext('2d');
            context.fillStyle = '#fff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            var y = page.first ? 0 : pad;
            var copy = function (from, to) {
                context.drawImage(result.canvas, 0, from * scale, canvas.width, (to - from) * scale, 0, y * scale, canvas.width, (to - from) * scale);
                y += to - from;
            };
            if (page.header) { copy(info.header.top, info.header.bottom); }
            copy(page.from, page.to);
            return canvas;
        });
    }

    function canvasToBytes(canvas) {
        return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', 0.94); }).then(function (blob) {
            return blob.arrayBuffer();
        }).then(function (buffer) {
            return { bytes: new Uint8Array(buffer), width: canvas.width, height: canvas.height };
        });
    }

    /* A small PDF writer: one JPEG per A4 page is all this file needs. */
    function buildPdf(images, title) {
        var parts = [];
        var offset = 0;
        var offsets = [];
        var ascii = function (text) {
            var bytes = new Uint8Array(text.length);
            for (var i = 0; i < text.length; i++) { bytes[i] = text.charCodeAt(i) & 255; }
            return bytes;
        };
        var add = function (data) {
            var bytes = typeof data === 'string' ? ascii(data) : data;
            parts.push(bytes);
            offset += bytes.length;
        };
        var object = function (id, body) {
            offsets[id] = offset;
            add(id + ' 0 obj\n' + body + '\nendobj\n');
        };

        add('%PDF-1.4\n%ÿÿÿÿ\n');
        var kids = images.map(function (image, index) { return (4 + index * 3) + ' 0 R'; }).join(' ');
        object(1, '<< /Type /Catalog /Pages 2 0 R >>');
        object(2, '<< /Type /Pages /Kids [' + kids + '] /Count ' + images.length + ' >>');
        object(3, '<< /Title (' + title.replace(/[^\x20-\x7E]/g, '').replace(/[()\\]/g, '') + ') /Producer (E-Data 360) >>');

        images.forEach(function (image, index) {
            var pageId = 4 + index * 3;
            var imageId = pageId + 1;
            var contentId = pageId + 2;
            var drawing = 'q 595.28 0 0 841.89 0 0 cm /Im0 Do Q';
            object(pageId, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /XObject << /Im0 ' + imageId + ' 0 R >> >> /Contents ' + contentId + ' 0 R >>');
            offsets[imageId] = offset;
            add(imageId + ' 0 obj\n<< /Type /XObject /Subtype /Image /Width ' + image.width + ' /Height ' + image.height + ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' + image.bytes.length + ' >>\nstream\n');
            add(image.bytes);
            add('\nendstream\nendobj\n');
            object(contentId, '<< /Length ' + drawing.length + ' >>\nstream\n' + drawing + '\nendstream');
        });

        var total = 4 + images.length * 3;
        var xref = offset;
        var table = 'xref\n0 ' + total + '\n0000000000 65535 f \n';
        for (var id = 1; id < total; id++) {
            table += String(offsets[id]).padStart(10, '0') + ' 00000 n \n';
        }
        add(table + 'trailer\n<< /Size ' + total + ' /Root 1 0 R /Info 3 0 R >>\nstartxref\n' + xref + '\n%%EOF\n');

        return new Blob(parts, { type: 'application/pdf' });
    }

    function saveBlob(blob, name) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }

    function makePdf() {
        return render('inv--a4', 2.5).then(function (result) {
            var pages = slice(result);
            var canvases = pages[0] instanceof HTMLCanvasElement ? pages : [result.canvas];
            return Promise.all(canvases.map(canvasToBytes));
        }).then(function (images) {
            var blob = buildPdf(images, fileName);
            window.edInvoiceLastFile = blob;
            saveBlob(blob, fileName + '.pdf');
        });
    }

    /* A phone offers its share sheet, so the picture can go straight to a chat. */
    function makeImage() {
        return render('inv--image', 2).then(function (result) {
            return new Promise(function (resolve) { result.canvas.toBlob(resolve, 'image/png'); });
        }).then(function (blob) {
            window.edInvoiceLastFile = blob;
            var name = fileName + '.png';
            var file = window.File ? new File([blob], name, { type: 'image/png' }) : null;
            if (file && navigator.canShare && navigator.maxTouchPoints > 0 && navigator.canShare({ files: [file] })) {
                return navigator.share({ files: [file] }).catch(function (error) {
                    if (error && error.name !== 'AbortError') { saveBlob(blob, name); }
                });
            }
            saveBlob(blob, name);
        });
    }

    function whileBusy(button, work) {
        var label = button.querySelector('[data-label]');
        var original = label ? label.textContent : '';
        button.disabled = true;
        if (label) { label.textContent = tools.dataset.busy || original; }
        function done() {
            button.disabled = false;
            if (label) { label.textContent = original; }
        }
        return work().then(done, function (error) {
            done();
            if (window.console) { console.error(error); }
            window.alert(tools.dataset.failed || 'Choose "Save as PDF" in the print window.');
            window.print();
        });
    }

    function copyLink(button) {
        var value = button.dataset.invCopy;
        var label = button.querySelector('[data-label]');
        var original = label ? label.textContent : '';
        function confirmCopied() {
            if (label) { label.textContent = tools.dataset.copied || original; }
            button.classList.add('is-done');
            setTimeout(function () {
                if (label) { label.textContent = original; }
                button.classList.remove('is-done');
            }, 1800);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(confirmCopied);
            return;
        }
        var field = document.createElement('textarea');
        field.value = value;
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        field.remove();
        confirmCopied();
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-inv-pdf], [data-inv-png], [data-inv-print], [data-inv-copy]');
        if (!button) { return; }
        if (button.hasAttribute('data-inv-pdf')) { whileBusy(button, makePdf); }
        if (button.hasAttribute('data-inv-png')) { whileBusy(button, makeImage); }
        if (button.hasAttribute('data-inv-print')) { window.print(); }
        if (button.hasAttribute('data-inv-copy')) { copyLink(button); }
    });

    var auto = tools.dataset.auto;
    if (auto === 'pdf') {
        var pdfButton = document.querySelector('[data-inv-pdf]');
        if (pdfButton) { whileBusy(pdfButton, makePdf); }
    }
    if (auto === 'print') {
        document.fonts.ready.then(function () { window.print(); });
    }
})();
