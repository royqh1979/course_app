<?php use App\Services\Csrf; ?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <h1 class="h3 mb-3">修改密码</h1>
        <form method="post" action="?r=password">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="old_password">原密码</label>
                <input class="form-control" id="old_password" name="old_password" type="password" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_password">新密码</label>
                <input class="form-control" id="new_password" name="new_password" type="password" required minlength="8"
                       autocomplete="new-password">
                <div class="form-text">至少 8 位，须同时包含字母、数字和符号；仅限英文键盘可见字符（不含空格）。</div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="confirm_password">确认新密码</label>
                <input class="form-control" id="confirm_password" name="confirm_password" type="password" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary">保存</button>
            <p class="text-muted mt-3">修改成功后，除当前会话外，其他已登录会话将全部失效。</p>
        </form>
    </div>
</div>
