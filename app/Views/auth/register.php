<?php use App\Services\Csrf; ?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <h1 class="h3 mb-3">学生注册</h1>
        <p class="text-muted">学号与姓名需与教师导入的课程名单一致，注册后自动获得对应课程权限。</p>
        <form method="post" action="?r=register">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="student_no">学号</label>
                <input class="form-control" id="student_no" name="student_no" required maxlength="32"
                       placeholder="与名单一致的学号，区分前导零">
            </div>
            <div class="mb-3">
                <label class="form-label" for="name">姓名</label>
                <input class="form-control" id="name" name="name" required maxlength="50"
                       placeholder="与名单一致的姓名，注册后不可修改">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">密码</label>
                <input class="form-control" id="password" name="password" type="password" required minlength="8">
            </div>
            <div class="mb-3">
                <label class="form-label" for="confirm_password">确认密码</label>
                <input class="form-control" id="confirm_password" name="confirm_password" type="password" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary w-100">注册</button>
        </form>
        <p class="mt-3 text-center"><a href="?r=login">已有账号？返回登录</a></p>
    </div>
</div>
