<div class="row justify-content-center">
    <div class="col-md-6 text-center">
        <h1 class="h3 mb-3">学生注册二维码</h1>
        <p class="text-muted">学生使用手机或微信扫描下方二维码，即可打开注册页面注册账号。</p>
        <div class="border rounded p-3 d-inline-block bg-white mb-3 qr-box">
            <?= $qrSvg ?>
        </div>
        <p>
            注册地址：<a href="<?= e($registerUrl) ?>"><?= e($registerUrl) ?></a>
        </p>
        <p class="text-muted small">
            可投屏展示或使用浏览器打印本页张贴。若二维码指向的地址学生手机无法访问，
            请在 <code>config/config.local.php</code> 中配置 <code>app.base_url</code> 为学生可访问的地址。
        </p>
        <a class="btn btn-outline-secondary" href="?r=admin/courses">返回课程管理</a>
    </div>
</div>
<style>
.qr-box svg { width: 100%; max-width: 320px; height: auto; display: block; }
@media print {
    nav, .btn, form { display: none !important; }
}
</style>
