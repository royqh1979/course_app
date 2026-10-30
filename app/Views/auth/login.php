<?php use App\Services\Csrf; ?>
<div class="row justify-content-center">
    <div class="col-md-5">
        <h1 class="h3 mb-3">登录</h1>
        <form method="post" action="?r=login">
            <?= Csrf::field() ?>
            <div class="mb-3">
                <label class="form-label" for="user_type">身份</label>
                <select class="form-select" id="user_type" name="user_type">
                    <option value="student">学生</option>
                    <option value="admin">教师（管理员）</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="identifier">账号</label>
                <input class="form-control" id="identifier" name="identifier" required
                       placeholder="学生填学号，教师填用户名">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">密码</label>
                <input class="form-control" id="password" name="password" type="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">登录</button>
        </form>
        <p class="mt-3 text-center"><a href="?r=register">学生注册新账号</a></p>
    </div>
</div>
