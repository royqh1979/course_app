# 课程讲义与作业管理系统

供一名教师管理多门课程的教学网站：发布讲义与作业、学生按课程名单实时匹配获得访问权限、在线提交作业、统计与导出。需求细节见《课程讲义与作业管理系统需求方案.md》。

**当前进度：第一阶段（基础与权限）已完成**，含管理员/学生登录、密码管理、登录限流、课程管理与归档、手工名单维护、学生课程权限实时查询，以及单元 / 集成 / HTTP 功能三层自动化测试。

## 环境要求

- PHP 8.3+（本机开发使用 8.4，启用扩展：`pdo_mysql`、`mbstring`、`gd`、`zip`、`xml`、`fileinfo`）
- Composer 2
- MySQL 8.0 或兼容版本（本机使用 MariaDB 11.5），数据库 `class_app`（生产）与 `class_app_test`（测试）
- 本机 PHP 安装在 `D:\tools\php`；运行 `composer` 前请把该目录加入 `PATH`

## 安装与启动

```bash
composer install
cp config/config.example.php config/config.local.php   # 填写数据库账号密码
php scripts/install_db.php                              # 初始化 11 张表（幂等）
php scripts/create_admin.php <用户名> <密码>             # 创建教师管理员
php scripts/create_student.php <学号> <姓名> <密码>      # 创建学生（自助注册属第二阶段）
php -S 127.0.0.1:8000 -t public                         # 本地开发服务器
```

浏览器访问 `http://127.0.0.1:8000`。生产部署时 Nginx 站点根目录指向 `public/`，`storage/` 不在公开目录内。

## 自动化测试

```bash
composer test                              # 全部测试（56 个用例）
php vendor/bin/phpunit --testsuite unit         # 单元测试（不依赖数据库）
php vendor/bin/phpunit --testsuite integration  # 集成测试（连测试库，事务回滚）
php vendor/bin/phpunit --testsuite feature      # HTTP 功能测试（自动起内置服务器）
```

- 测试使用独立数据库 `class_app_test`（可用环境变量 `TEST_DB_NAME` 覆盖），每次运行自动重建表结构；引导脚本会校验库名必须以 `_test` 结尾，防止误清生产库。
- 功能测试通过 `proc_open` 启动 `php -S 127.0.0.1:8123` 并注入 `DB_NAME=class_app_test`，无需手工起服务。
- 测试与验收标准的对应关系见 `tests/README.md`。

## 目录结构

```text
public/index.php       # 前端控制器与路由（Nginx 站点根目录）
app/
  Controllers/         # 请求处理、权限检查与跳转
  Repositories/        # 数据访问层（PDO 预处理）
  Services/            # 认证、密码、CSRF、限流等公共服务
  Support/             # 配置、PDO、Session、视图、校验
  Views/               # PHP + HTML 模板（Bootstrap CDN）
config/                # config.php 通用配置；config.local.php 本机覆盖（不入库）
database/schema.sql    # 首版 11 张表（utf8mb4，只建表不建库）
scripts/               # CLI：建表、创建管理员/学生
storage/               # 私有文件目录（不入库，后续阶段使用）
tests/                 # Unit / Integration / Feature 三层测试
```

## 安全要点

- 密码一律 `password_hash()`（BCRYPT）存储，`password_verify()` 验证，必要时自动 `password_needs_rehash()` 升级
- 登录成功重新生成 Session ID；连续失败按账号限流；改密后其他会话失效（`session_version`）
- 全站 PDO 预处理语句；页面输出经 `e()` HTML 转义；写操作表单强制 CSRF 校验
- 学生课程权限按“学号 + 姓名”实时查询，访问课程时服务端复检；归档学生禁止登录
- `auth_logs` / `audit_logs` 记录登录与管理操作，不记录密码、哈希或 Session
