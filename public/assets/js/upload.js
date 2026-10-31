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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init();
            initAutoTitle();
        });
    } else {
        init();
        initAutoTitle();
    }
})();
