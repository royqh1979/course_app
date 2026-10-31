# AGENTS.md

本文件供 AI 编码代理阅读，介绍本项目的背景、约定与开发要点。

## 项目概述

本项目是一个**课程讲义与作业管理系统**（教学网站），供一名教师管理多门课程：

- 教师发布和更新课程讲义、发布作业。
- 学生注册后，根据课程名单**实时匹配**自动获得课程访问权限。
- 学生在线提交作业；教师统计提交情况、导出 Excel、下载单个或打包全部作业。
- 保留登录、密码及关键管理操作日志。

首版只有一名教师管理员，**不实现**多教师、在线 Office 编辑、消息通知、讨论区和完整作业版本历史。（成绩批改在原需求"不实现成绩评定"基础上应用户要求追加，规则：教师可随时修改批改结果；批改后学生不能再修改该次提交。）

### 当前状态（重要）

**第一阶段（基础与权限）已完成**：管理员/学生登录与密码管理、登录限流、课程管理与归档、手工名单维护、学生课程权限实时查询。

**第二阶段（名单与账号）已完成**：学生注册、名单 Excel 标准模板下载、名单导入（上传预校验→确认两段式，错误/重复定位到 Excel 行号，事务增量导入）、未匹配账号查询、学生归档/恢复/重置密码/安全删除。

**注册二维码与全站响应式已完成**：教师端 `?r=admin/qrcode` 展示注册二维码（服务端 SVG，地址取 `app.base_url` 配置或按请求推导）；全站响应式布局（可折叠导航、表格横向滚动）；Bootstrap CSS/JS 已本地化到 `public/assets/vendor/bootstrap/`，**全站不依赖任何外部公共库/CDN**。

**第三阶段（文件与教学闭环）已完成**：`FileStorage`（UUID v7 命名、私有存储、后缀→大小→MIME 校验）、讲义上传/发布/下线/替换（先存新后删旧）、作业新建/编辑（任何时间可改截止时间）/发布/关闭与多附件、学生提交/重交/补交（默认允许补交）、三类文件统一下载权限复检。**迟交不存标志，统一按 `last_submitted_at > deadline_at` 实时计算**（`submissions.is_late` 列已从 schema 移除；旧开发库残留该列无害，可手动 DROP）。

**作业批改已完成**：`?r=admin/assignments/submissions&id=` 提交列表内嵌批改表单（分数 0–100 / 评语，至少一项）；`submissions` 表含 `score/comment/graded_at`。**批改后该提交对学生锁定**（不能再重交，提交表单隐藏，服务端拒绝）；**教师可随时修改批改结果**（`graded_at` 刷新，审计区分 `submission.grade/regrade`）；学生作业页展示分数与评语。

**列表分页已完成**：`App\Support\Pagination` + 视图局部模板 `app/Views/_pagination.php`；课程/学生/作业提交列表分页，课程详情页讲义/作业/名单三表独立页码参数（`hp`/`ap`/`rp`）；每页条数可选（10/20/50/100，`per_page` 参数保留），未指定时按 UA 判定**手机 20、电脑 50**；页码越界自动回落最后一页；翻页保留筛选条件。

**文件操作事务化已完成**：`FileStorage::transactional()` 实现"事务 + 补偿"——回调内 DB 写入用 `Database::transaction()` 包裹，`store()` 的新文件登记入册、异常时补偿删除，`delete()` 延后到事务提交后执行。凡伴随 DB 写入的文件操作必须走该方法（讲义/附件/提交/备课区已全部接入）。

**备课区已完成**：课件/作业库（`admin/library`，3 张 `library_*` 表 + `library_folders`）。**导入 = 逻辑快照**：复制业务行、共享同一 `files` 行（`file_id` 引用），模板与课程副本互不影响；模板修改/删除只影响之后的导入；支持课程条目"存入备课区"——模态框选择目标文件夹，**同标题冲突可三选：覆盖（替换既有条目，旧文件引用计数回收）/ 改名存入 / 放弃**；无 JS 时退回直存根目录（同名即报错）。**文件删除按引用计数**：`FileRepository::countReferences()` 统算 5 张引用表，归零才删行删盘；学生提交永不共享。**文件夹树（2026-10 增量）**：课件/作业**两棵按类型独立的树**（`item_type` 区分），深度 ≤3 层；文件夹建/改名/删除（**仅空文件夹**——无子文件夹且无条目）；条目经编辑页移入移出；列表页/导入页左侧树浏览（递归计数徽标 + 面包屑）。历史故障注记：曾因病式分页路径致备课区首页 500，拆页后修复，feature 测试已补全部页面 200 断言防回归。**前端交互约定（2026-11 增量）**：行操作（发布/下线/删除/关闭等）统一 `data-async-action` 无刷新（XHR + 成功后替换 `#[xxx]-panel` 面板，滚动位置保留）；上传/导入/存回等批量与对话框走 `upload.js` 各 `init*` 模块（渐进增强，`noscript` 兜底原生表单）；静态资源经 `asset()` 带 `?v=filemtime`，Nginx 对 `/course_app/assets/` 配 immutable 长缓存。

**第四阶段（统计导出）已完成**：提交统计页（**以当前名单为基准**，含未注册学生；`admin/assignments/stats`）、Excel 导出（同口径、用户内容全部显式文本写入防公式注入）、ZIP 打包（**全部有效提交**，含已移除名单学生的提交；包内文件名"班级_学号_姓名_原文件名"、非法字符清理、重名加序号、500MB 上限 `export.zip_max_size`）。**日志查看**：`admin/logs/auth` / `admin/logs/audit`（筛选+分页）。**上传进度**：`public/assets/js/upload.js`（ES5 渐进增强 XHR 进度条，不支持退回原生表单；上传端点带 `X-Requested-With` 时返回 JSON），两种路径均有含文件名的显式结果提示。**孤立文件检查**：`php scripts/check_orphan_files.php [--delete]`。

**页面拆分（2026-10 增量）**：教师课程详情由"一页四区块"拆为**四个独立页面**（`admin/courses/handouts|assignments|roster|groups`，共用头部 + tabs 子导航，页码统一 `page`；分组页教师只读）；备课区拆为课件/作业两个独立列表页（左侧文件夹树）；学生课程由"一页三区块"拆为**三个独立页面**（`student/course/handouts|assignments|groups`，小组区块整体移至 groups 页，`student/group` 管理页不变）。旧路由 `admin/courses/show`、`admin/library`、`student/course` 保留为跳转到新首页。

**小组作业已完成**：作业级 `is_group` 开关；**小组属于课程**（`course_groups`/`course_group_members`，一课一组），创建者为组长、加组需组长审批；课程级人数上下限（**上限加入时检查、下限提交时检查**，`courses.group_min/max_members`）；**只有组长能提交**（组员页面提示"只有组长能提交小组作业"）；提交记 `submissions.group_id`；**成绩共享**：统计/导出/学生端把组提交的分数映射给全组（未批改分数为空白），批改页按组聚合，ZIP 包内文件名用"组名_组长学号_组长姓名_原文件名"；组长不能退出（只能转让/解散），已有提交的组不能解散；教师课程页只读查看分组情况。

三层自动化测试（`composer test`，178 个用例）全部通过，验收标准 14 条全覆盖。

- 需求唯一权威来源：`课程讲义与作业管理系统需求方案.md`（中文，约 550 行）。任何与该文档冲突的实现决定都应先与用户确认。
- 已实现代码按方案的原生 PHP 分层组织：`public/index.php` 前端控制器 + `app/`（Controllers / Repositories / Services / Support / Views）。
- 数据库：`database/schema.sql` 已建好首版全部 11 张表（含后续阶段用的 `files`、`handouts`、`assignments` 等）。本机 MariaDB 11.5（MySQL 兼容），库名 `class_app`（生产）/ `class_app_test`（测试），账号 `class_app` **无建库权限**，脚本只建表不建库。
- 已上线部署（2026-10）：本机 Debian 13 + Nginx 1.26 + PHP 8.4-FPM（Apache 已停用保留可回滚），学生入口 `https://www.royqh.net/course_app/`（子目录方式，`/var/www/course_app` 符号链接到 `public/`），Let's Encrypt 证书 certbot 自动续期，每日备份 + 每周孤立文件清理 cron。详见 `部署说明.md` 附录。四个阶段功能已全部完成。
- 测试与验收标准（方案第十一节）的对照表维护在 `tests/README.md`，新增功能时同步更新。

## 技术栈（需求方案规定）

- **运行环境**：Linux 云服务器，Nginx + PHP-FPM，PHP 8.x（安全维护期内版本），MySQL 8.0 或兼容版本。
- 数据库字符集 `utf8mb4`；全站 HTTPS；系统时区 `Asia/Shanghai`。
- **开发方式**：原生 PHP，不使用完整框架，按简单 MVC / 分层结构组织：
  - 控制器：处理请求、权限检查和页面跳转。
  - 数据访问层：封装数据库操作。
  - 页面模板：PHP + HTML，配合 Bootstrap 或轻量 CSS。
  - 公共服务：认证、上传、下载、日志、Excel 和 ZIP 处理。
- **Composer 依赖**（按方案选型）：
  - PDO（连接 MySQL，必须使用预处理语句）
  - `phpoffice/phpspreadsheet`（导入 `.xlsx`/`.xls`，导出 `.xlsx`）
  - `ramsey/uuid`（生成 UUID v7 作为磁盘文件名）
  - ZipArchive（打包下载作业）
  - `chillerlan/php-qrcode`（服务端生成注册二维码 SVG）
- **前端资源**：Bootstrap 5.3.3 CSS/JS 本地化在 `public/assets/vendor/bootstrap/`，禁止改用外部 CDN。

## 目录结构（实现时按此规划）

部署路径假设为 `/var/www/course_app`：

```text
/var/www/course_app/
├── public/          # Nginx 网站根目录（index.php、assets/）
├── app/             # PHP 业务代码
├── config/          # 配置
└── storage/         # 私有文件，禁止 URL 直接访问
    ├── courses/<课程ID>/handouts/
    ├── courses/<课程ID>/assignments/<作业ID>/attachments/
    ├── courses/<课程ID>/assignments/<作业ID>/submissions/
    ├── imports/     # 名单导入临时文件（导入完成后及时删除）
    ├── exports/     # 临时 Excel/ZIP（定期清理）
    └── tmp/
```

目录使用课程 ID / 作业 ID（不用名称），避免改名移动文件和中文路径问题。开发仓库落地时应保持这一分层；具体的 `app/` 内部分层（控制器、数据访问、模板、公共服务）按需求方案第二节组织。

## 核心设计约定（实现必须遵守）

这些规则来自需求方案，是项目的“业务宪法”：

- **账号分表**：`admins` 与 `students` 独立；管理员仅由安装脚本/命令行创建，不开放注册。
- **名单不关联账号**：`course_roster` 只存 `(course_id, student_no, name, class_name)`，不存 `student_id`，不持久化匹配结果。
- **权限实时查询**：学生课程权限按“学号 + 姓名同时匹配名单”实时 SQL 查询（方案第四节给出 SQL）；名单变动后权限即时生效，注册后无需额外匹配操作。
- **服务端权限复检**：访问具体课程、讲义、作业、附件、下载文件时必须在服务端重新检查权限，不能只隐藏页面入口。
- **提交模式**：每名学生对每项作业仅保留一份当前有效提交（`(assignment_id, student_id)` 唯一）；截止前可重新提交并替换旧文件；先保存新文件并更新数据库成功，再清理旧文件。
- **迟交实时计算**：不存 `is_late` 字段；`迟交 = deadline_at 非空 且 last_submitted_at > deadline_at`，所有页面与统计统一按此实时计算；教师改期后自动生效，禁止写"重算迟交标志"类逻辑。
- **补交默认允许**：`assignments.allow_late` 默认 1（截止后仍可提交并记为迟交），教师可按作业关闭；作业任何字段（含已过的截止时间）任何状态可编辑，改后实时生效。
- **小组作业**：小组是课程级（一课一组，加组需组长审批，pending/active 都算占用）；作业级 `is_group` 开关；只有组长能提交；成绩按组共享（取组提交映射全组，未批改空白）；人数上限加入时检查、下限提交时检查；组长不能退出、有提交的组不能解散。
- **统计基准**：提交统计以**当前课程名单**为基准（含未注册学生），不以已注册学生为基准；名单移除的学生历史提交保留但不计入统计。
- **归档语义**：课程/学生归档均不删除数据；归档学生不能登录；归档课程不可再发布或提交，但教师可查历史。首版不提供删除课程功能；学生账号仅当未匹配名单且无提交记录时才可物理删除。

## 文件存储约定

- 文件存磁盘不入库；`files` 表保存 `original_name`、`stored_path`（相对 `storage/`）、`extension`、`mime_type`、`size`、`sha256`（可选）、`created_at`。不存服务器绝对路径。
- 磁盘文件名 = **UUID v7 + 白名单校验后的扩展名**；下载时不暴露 UUID 文件名。
- 所有私有文件经 PHP 下载程序提供：检查登录 → 账号未归档 → 学生实时课程权限 → 文件归属关系 → 按 `stored_path` 定位 → 以原始文件名（或规定格式）输出。
- 上传校验顺序：后缀白名单 → 大小限制 → MIME 检查；学生提交按后缀白名单（doc/docx/xls/xlsx/ppt/pptx/zip）判断。
- 替换文件先存新后删旧；定期检查无数据库记录的孤立文件。
- **文件多步写入必须走 `FileStorage::transactional()`**（DB 事务 + 新建文件补偿删除 + 旧文件延后删除）；一个物理文件 = `files` 一行，可多处 `file_id` 引用，删除走 `countReferences()` 引用计数。

## 密码与安全要求

- 密码用 `password_hash()`（可指定 `PASSWORD_BCRYPT`）保存，`password_verify()` 验证，必要时 `password_needs_rehash()`；**禁止** MD5/SHA1/裸 SHA256。
- **新密码强度策略**（`PasswordService::validatePolicy()`，注册/改密/CLI 建号统一执行）：长度 ≥8；**大写字母、小写字母、数字、符号四类中至少三类**；且只允许 ASCII 可见字符（0x21–0x7E，不含空格/中文）；教师重置学生密码生成的临时随机密码也满足该策略。注册/改密页面须有可见的要求提示文字。
- 日志中不得记录密码、密码哈希或完整 Session 信息。
- 全部数据库操作使用 PDO 预处理语句；页面输出做 HTML 转义（防 XSS）；写操作表单加 CSRF 防护。
- Cookie 设置 `HttpOnly`、`Secure`、适当 `SameSite`；登录成功后重新生成 Session ID；登录失败做限流。
- 上传目录禁止执行脚本；不使用 `777` 权限；Nginx / PHP / 业务层上传大小限制保持一致。
- Excel 导出时处理以 `=`、`+`、`-`、`@` 开头的用户内容，防止公式注入。
- 审计：`auth_logs` 记录登录/退出/密码操作（含用户类型、IP、User-Agent、结果）；`audit_logs` 记录课程、名单、讲义、作业、账号、文件等关键管理操作。

## 数据表（首版 11 张）

`admins`、`students`、`courses`、`course_roster`、`files`、`handouts`、`assignments`、`assignment_attachments`、`submissions`、`auth_logs`、`audit_logs`。

关键约束：`admins.username` 唯一；`students.student_no` 唯一；`(course_id, student_no)` 名单内唯一；`(assignment_id, student_id)` 提交表唯一；为名单、课程、作业、日志查询建索引。

## 构建、测试与部署

- 依赖：`composer install`（Composer 2 + PHP 8.3+，`composer.lock` 需提交）。测试框架 PHPUnit 12（require-dev）。
- 测试：`composer test` 跑全部；`php vendor/bin/phpunit --testsuite unit|integration|feature` 分套件运行。测试库为 `class_app_test`（每次运行自动重建表结构；功能测试自动启动 `php -S 127.0.0.1:8123`）。
- 数据库初始化：`php scripts/install_db.php`（幂等，只建表）；账号创建：`php scripts/create_admin.php` / `php scripts/create_student.php`；改管理员密码：`php scripts/change_admin_password.php <用户名> <新密码>`（校验密码强度策略并使其他会话失效）；备课区文件夹升级：`php scripts/migrate_library_folders.php`（幂等迁移）。
- 本机配置：`config/config.local.php`（gitignore，真实凭证只放这里）；通用默认在 `config/config.php`，可被环境变量 `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS` 覆盖。
- 部署目标：Nginx + PHP-FPM，`public/` 为站点根目录，`storage/` 在公开目录之外。**云主机部署步骤见 `部署说明.md`**（环境、Nginx/PHP 配置、HTTPS、cron 备份与孤立文件清理、安全清单、冒烟验证）。
- 运维要求：定期同时备份 MySQL 与 `storage/courses/`；异地/对象存储备份；监控磁盘空间。

## 开发顺序（需求方案规定）

1. **基础与权限**：环境、数据库、PDO、管理员初始化、登录、密码、日志、课程管理、实时课程权限查询。
2. **名单与账号**：Excel 模板、名单导入校验（预校验显示总行/有效/错误/重复行，错误定位到 Excel 行号，事务回滚，增量导入）、学生注册、未匹配账号查询、归档/恢复/安全删除。
3. **文件与教学闭环**：UUID v7 命名、私有存储、讲义上传发布、作业发布、附件、学生提交。
4. **统计导出与上线**：提交统计、Excel 导出、单个下载、ZIP 打包（文件名“班级_学号_姓名_原文件名”，清理非法字符、重名加序号）、权限测试、安全检查、备份部署。

## 文档与语言约定

- 项目文档使用**中文**；代码注释、提交信息及与用户沟通均建议使用中文。
- 需求方案（`课程讲义与作业管理系统需求方案.md`）是唯一权威需求来源；第十一节列有 14 条首版验收标准，实现完成度应以此核对。
