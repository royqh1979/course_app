/*
 * 上传进度条（渐进增强）：
 * 拦截带 data-upload-progress 的表单，改用 XHR 提交并显示 Bootstrap 进度条。
 * 不支持 FormData/xhr.upload 的浏览器静默退回原生表单提交。
 * 无论走哪条路径，服务端/页面都必须给出含文件名的显式结果提示。
 */
(function () {
    'use strict';

    function supported() {
        if (!window.FormData || !window.XMLHttpRequest) {
            return false;
        }
        var xhr = new XMLHttpRequest();
        return !!xhr.upload;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        node.className = className;
        if (text) {
            node.appendChild(document.createTextNode(text));
        }
        return node;
    }

    function bind(form) {
        var box = el('div', 'upload-box d-none mt-2');
        var track = el('div', 'progress');
        track.setAttribute('role', 'progressbar');
        var bar = el('div', 'progress-bar progress-bar-striped progress-bar-animated');
        bar.style.width = '0%';
        bar.appendChild(document.createTextNode('0%'));
        track.appendChild(bar);
        var result = el('div', 'mt-2');
        box.appendChild(track);
        box.appendChild(result);
        form.appendChild(box);

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var submitButtons = form.querySelectorAll('[type="submit"]');
            var i;
            for (i = 0; i < submitButtons.length; i++) {
                submitButtons[i].disabled = true;
            }
            result.innerHTML = '';
            box.className = 'upload-box mt-2';
            bar.style.width = '0%';
            bar.firstChild.nodeValue = '0%';

            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable) {
                    return;
                }
                var pct = Math.round((ev.loaded / ev.total) * 100);
                bar.style.width = pct + '%';
                bar.firstChild.nodeValue = pct + '%';
            });

            xhr.addEventListener('load', function () {
                var res = null;
                try {
                    res = JSON.parse(xhr.responseText);
                } catch (ignore) {
                    res = null;
                }
                if (res && res.ok) {
                    bar.className = 'progress-bar bg-success';
                    bar.style.width = '100%';
                    bar.firstChild.nodeValue = '100%';
                    result.innerHTML = '';
                    result.appendChild(el('div', 'alert alert-success mb-0', res.success || '上传成功'));
                    window.setTimeout(function () {
                        window.location.href = res.redirect || window.location.href;
                    }, 900);
                    return;
                }
                box.className = 'upload-box d-none mt-2';
                result.innerHTML = '';
                box.className = 'upload-box mt-2';
                result.appendChild(el('div', 'alert alert-danger mb-0', (res && res.error) || '上传失败，请重试。'));
                for (i = 0; i < submitButtons.length; i++) {
                    submitButtons[i].disabled = false;
                }
            });

            xhr.addEventListener('error', function () {
                result.innerHTML = '';
                result.appendChild(el('div', 'alert alert-danger mb-0', '网络错误，上传失败，请重试。'));
                for (var j = 0; j < submitButtons.length; j++) {
                    submitButtons[j].disabled = false;
                }
            });

            xhr.send(new FormData(form));
        });
    }

    function init() {
        if (!supported()) {
            return; // 渐进增强：不支持的浏览器退回原生提交
        }
        var forms = document.querySelectorAll('form[data-upload-progress]');
        for (var i = 0; i < forms.length; i++) {
            bind(forms[i]);
        }
    }

    /*
     * 标题自动填写（渐进增强）：带 data-autotitle="<标题输入框选择器>" 的文件框，
     * 选择文件后若目标标题为空，则以文件名（去最后一段扩展名）填入；标题已有内容则不覆盖。
     */
    function initAutoTitle() {
        var inputs = document.querySelectorAll('input[type="file"][data-autotitle]');
        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                input.addEventListener('change', function () {
                    var target = document.querySelector(input.getAttribute('data-autotitle'));
                    if (!target || target.value !== '') {
                        return;
                    }
                    var file = input.files && input.files[0];
                    if (!file) {
                        return;
                    }
                    target.value = String(file.name).replace(/\.[^.]+$/, '');
                });
            })(inputs[i]);
        }
    }

    /*
     * 批量上传（渐进增强）：form[data-upload-batch] 的文件框可多选，表单内 [data-dropzone] 可拖入。
     * 选择/拖入多个文件时，逐文件串行向表单 action 提交（每个文件各生成一条独立课件/模板记录，
     * 标题=该文件名去扩展名），逐条显示结果，全部完成后刷新页面；
     * 单文件选择不拦截，仍走既有路径（自动填标题/进度条/原生提交）。
     */
    function bindBatch(form) {
        var fileInput = form.querySelector('input[type="file"]');
        if (!fileInput) {
            return;
        }
        var dropzone = form.querySelector('[data-dropzone]');
        var submitButtons = form.querySelectorAll('[type="submit"]');
        var queue = [];
        var running = false;
        var resultBox = null;

        function ensureBox() {
            if (!resultBox) {
                resultBox = el('div', 'col-12 batch-results mt-2');
                form.appendChild(resultBox);
            }
            return resultBox;
        }

        function stem(name) {
            return String(name).replace(/\.[^.]+$/, '');
        }

        function addRow(name) {
            var row = el('div', 'small mb-1', '⏳ ' + name + ' …');
            ensureBox().appendChild(row);
            return row;
        }

        function setRow(row, ok, text) {
            row.className = 'small mb-1 ' + (ok ? 'text-success' : 'text-danger');
            row.firstChild.nodeValue = (ok ? '✓ ' : '✗ ') + text;
        }

        function setButtons(disabled) {
            for (var j = 0; j < submitButtons.length; j++) {
                submitButtons[j].disabled = disabled;
            }
        }

        function enqueue(files, viaDrop) {
            var list = [];
            for (var k = 0; k < files.length; k++) {
                list.push(files[k]);
            }
            if (list.length === 0 || (list.length === 1 && !viaDrop)) {
                return; // 单文件走既有路径
            }
            setButtons(true);
            queue = queue.concat(list);
            if (!running) {
                run();
            }
        }

        function run() {
            if (queue.length === 0) {
                running = false;
                setButtons(false);
                window.setTimeout(function () {
                    window.location.reload();
                }, 1200);
                return;
            }
            running = true;
            var file = queue.shift();
            var row = addRow(file.name);

            var data = new FormData();
            // 克隆表单字段（_csrf、course_id/folder_id、description 等）；file 与 title 单独处理
            var fields = form.querySelectorAll('input, textarea, select');
            for (var m = 0; m < fields.length; m++) {
                var field = fields[m];
                if (!field.name || field === fileInput || field.type === 'submit' || field.name === 'title') {
                    continue;
                }
                if (field.type === 'checkbox' || field.type === 'radio') {
                    if (field.checked) {
                        data.append(field.name, field.value);
                    }
                    continue;
                }
                data.append(field.name, field.value);
            }
            data.append('title', stem(file.name));
            data.append(fileInput.name || 'file', file);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.addEventListener('load', function () {
                var res = null;
                try {
                    res = JSON.parse(xhr.responseText);
                } catch (ignore) {
                    res = null;
                }
                if (res && res.ok) {
                    setRow(row, true, res.success || (file.name + ' 上传成功'));
                } else {
                    setRow(row, false, file.name + '：' + ((res && res.error) || ('上传失败（HTTP ' + xhr.status + '）')));
                }
                run();
            });
            xhr.addEventListener('error', function () {
                setRow(row, false, file.name + '：网络错误，上传失败');
                run();
            });
            xhr.send(data);
        }

        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 1) {
                enqueue(fileInput.files, false);
                fileInput.value = ''; // 已入队，避免再次点击提交重复上传
            }
        });

        if (dropzone) {
            var baseClass = dropzone.className;
            dropzone.addEventListener('dragover', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                dropzone.className = baseClass + ' bg-light';
            });
            dropzone.addEventListener('dragleave', function (ev) {
                ev.preventDefault();
                dropzone.className = baseClass;
            });
            dropzone.addEventListener('drop', function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                dropzone.className = baseClass;
                if (ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files.length) {
                    enqueue(ev.dataTransfer.files, true);
                }
            });
        }
    }

    function initBatch() {
        if (!window.FormData || !window.XMLHttpRequest) {
            return;
        }
        var forms = document.querySelectorAll('form[data-upload-batch]');
        for (var i = 0; i < forms.length; i++) {
            bindBatch(forms[i]);
        }
    }

    /*
     * 备课区批量导入（渐进增强）：form[data-import-batch] 内勾选一个以上条目时，
     * 串行队列逐条向既有导入端点发 XHR（每次一个 template_id，各成一份独立课程副本），
     * 在对应条目旁显示进度与结果，全部完成后刷新页面；勾选 ≤1 个时走原生提交。
     */
    function bindImportBatch(form) {
        var boxes = form.querySelectorAll('input[name="template_ids[]"]');
        if (!boxes.length) {
            return;
        }
        var csrf = form.querySelector('input[name="_csrf"]');
        var courseId = form.querySelector('input[name="course_id"]');
        var typeInput = form.querySelector('input[name="type"]');

        function setStatus(box, text, ok) {
            var label = box.closest ? box.closest('label') : box.parentNode;
            if (!label) {
                return;
            }
            var status = label.querySelector('.import-status');
            if (!status) {
                status = el('div', 'import-status small text-muted w-100', text);
                label.appendChild(status);
            }
            status.firstChild.nodeValue = text;
            status.className = 'import-status small w-100 ' + (ok === undefined ? 'text-muted' : (ok ? 'text-success' : 'text-danger'));
        }

        form.addEventListener('submit', function (event) {
            var checked = [];
            for (var i = 0; i < boxes.length; i++) {
                if (boxes[i].checked) {
                    checked.push(boxes[i]);
                }
            }
            if (checked.length <= 1) {
                return; // 单个（或零个）走原生提交，由服务端提示
            }
            event.preventDefault();
            var button = form.querySelector('[type="submit"]');
            if (button) {
                button.disabled = true;
            }
            var index = 0;

            function next() {
                if (index >= checked.length) {
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 1200);
                    return;
                }
                var box = checked[index];
                index++;
                setStatus(box, '⏳ 导入中…');
                var data = new FormData();
                data.append('_csrf', csrf ? csrf.value : '');
                data.append('course_id', courseId ? courseId.value : '');
                data.append('type', typeInput ? typeInput.value : '');
                data.append('template_id', box.value);

                var xhr = new XMLHttpRequest();
                xhr.open('POST', form.getAttribute('action'), true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.addEventListener('load', function () {
                    var res = null;
                    try {
                        res = JSON.parse(xhr.responseText);
                    } catch (ignore) {
                        res = null;
                    }
                    if (res && res.ok) {
                        setStatus(box, '✓ ' + res.success, true);
                    } else {
                        setStatus(box, '✗ ' + ((res && res.error) || ('导入失败（HTTP ' + xhr.status + '）')), false);
                    }
                    next();
                });
                xhr.addEventListener('error', function () {
                    setStatus(box, '✗ 网络错误，导入失败', false);
                    next();
                });
                xhr.send(data);
            }

            next();
        });
    }

    function initImportBatch() {
        if (!window.FormData || !window.XMLHttpRequest) {
            return;
        }
        var forms = document.querySelectorAll('form[data-import-batch]');
        for (var i = 0; i < forms.length; i++) {
            bindImportBatch(forms[i]);
        }
    }

    /*
     * 无刷新行操作（渐进增强）：表单带 data-async-action 时，XHR 提交（服务端返回 JSON），
     * 成功后静默重取当前页并仅替换 data-async-target 指向的面板——不跳转、保留滚动位置。
     * 不支持 XHR 时退回原生提交（服务端 flash + 跳转，行为如旧）。
     * 用事件委托（绑定在 document），面板替换后无需重新绑定。
     */
    function initAsyncActions() {
        if (!window.FormData || !window.XMLHttpRequest) {
            return;
        }
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.getAttribute || !form.hasAttribute('data-async-action')) {
                return;
            }
            event.preventDefault();
            var button = form.querySelector('[type="submit"]');
            if (button) {
                button.disabled = true;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.getAttribute('action'), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.addEventListener('load', function () {
                if (button) {
                    button.disabled = false;
                }
                var res = null;
                try {
                    res = JSON.parse(xhr.responseText);
                } catch (ignore) {
                    res = null;
                }
                if (res && res.ok) {
                    refreshPanel(form.getAttribute('data-async-target'), res.success);
                } else {
                    showAsyncResult(form, (res && res.error) || ('操作失败（HTTP ' + xhr.status + '）'), false);
                }
            });
            xhr.addEventListener('error', function () {
                if (button) {
                    button.disabled = false;
                }
                showAsyncResult(form, '网络错误，操作失败', false);
            });
            xhr.send(new FormData(form));
        });
    }

    /** 成功后重取当前页并替换目标面板，再显示一条瞬态成功提示 */
    function refreshPanel(targetSelector, message) {
        var target = targetSelector ? document.querySelector(targetSelector) : null;
        if (!target) {
            window.location.reload();
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.location.href, true);
        xhr.addEventListener('load', function () {
            if (xhr.status !== 200) {
                window.location.reload();
                return;
            }
            var doc = null;
            try {
                doc = new DOMParser().parseFromString(xhr.responseText, 'text/html');
            } catch (ignore) {
                doc = null;
            }
            var fresh = doc && doc.querySelector(targetSelector);
            if (!fresh) {
                window.location.reload();
                return;
            }
            target.parentNode.replaceChild(document.importNode(fresh, true), target);
            showAsyncResult(document.querySelector(targetSelector), message, true);
        });
        xhr.addEventListener('error', function () {
            window.location.reload();
        });
        xhr.send();
    }

    /** 在面板（或表单）顶部/旁边插入一条瞬态结果提示 */
    function showAsyncResult(anchor, text, ok) {
        var holder = anchor;
        if (anchor && anchor.hasAttribute && anchor.hasAttribute('data-async-action')) {
            holder = anchor.parentNode;
        }
        var panel = holder && holder.closest ? holder.closest('[id$="-panel"]') : null;
        var box = document.querySelector('#handout-panel .async-result');
        var node = el('div', 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' async-result py-1 px-2 small mb-2', text);
        if (panel && box && box.parentNode === panel) {
            panel.replaceChild(node, box);
        } else if (panel) {
            panel.insertBefore(node, panel.firstChild);
        } else if (holder && holder.parentNode) {
            holder.parentNode.insertBefore(node, holder);
        }
        window.setTimeout(function () {
            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        }, 2500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
            initAutoTitle();
            initBatch();
            initImportBatch();
            initAsyncActions();
        });
    } else {
        init();
        initAutoTitle();
        initBatch();
        initImportBatch();
        initAsyncActions();
    }
})();
