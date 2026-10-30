<div class="row justify-content-center">
    <div class="col-md-6 text-center">
        <h1 class="display-4"><?= e((string) ($code ?? '')) ?></h1>
        <p class="lead"><?= e($message ?? '') ?></p>
        <a href="?r=home" class="btn btn-outline-primary">返回首页</a>
    </div>
</div>
