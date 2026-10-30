# 测试说明

测试分三层，全部通过 `composer test` 运行：

| 套件 | 目录 | 依赖 | 内容 |
|---|---|---|---|
| unit | `tests/Unit` | 无 | 纯逻辑：密码哈希、CSRF、输入校验、限流判定、名单 Excel 解析与预校验 |
| integration | `tests/Integration` | `class_app_test` | Repository CRUD 与唯一约束、实时权限 SQL、日志写入、AuthService、名单增量导入与事务回滚、学生筛选/未匹配查询；每用例事务回滚 |
| feature | `tests/Feature` | `class_app_test` + 内置服务器 | 完整 HTTP 流程：登录/退出/限流、学生注册、权限复检、CSRF、改密会话失效、名单 Excel 上传预校验与确认导入、学生账号管理、注册二维码、响应式导航与资源本地化、讲义上传发布与下载、作业发布与附件、学生提交/重交/补交、教师批改与批改后锁定、列表分页与设备默认条数、备课区模板/导入/存回与共享文件语义、提交统计与 Excel/ZIP 导出、XHR 上传 JSON 分支、日志查看、小组作业全流程（建组/审批/上下限/组长提交/成绩共享） |

## 与首版验收标准的对照（需求方案第十一节）

| 验收标准 | 覆盖测试 | 状态 |
|---|---|---|
| 1. 管理员和学生账号分表，分别正常登录 | `Feature/AuthFlowTest`、`Integration/AuthServiceTest` | ✅ 已覆盖 |
| 2. 姓名和学号都一致才能看到课程 | `Integration/CoursePermissionTest`、`Feature/PermissionTest` | ✅ 已覆盖 |
| 3. 名单导入或注册后无需额外匹配操作 | `Feature/PermissionTest::testRosterGrantAndRevokeTakeEffectImmediatelyOverHttp`、`Feature/RosterImportTest::testFullImportFlowWithPreviewAndConfirm`、`Feature/RegistrationTest::testRegisteredStudentGetsCourseAccessImmediately`、`Integration/RosterImportTest::testImportedRosterGrantsPermissionImmediately` | ✅ 已覆盖 |
| 4. 学生不能通过直接输入 URL 访问其他课程或文件 | `Feature/PermissionTest::testStudentCannotAccessOtherCourseByDirectUrl` | ✅ 已覆盖（课程级；文件级属第三阶段） |
| 5. 归档学生不能登录，历史数据保留 | `Feature/AuthFlowTest::testArchivedStudentCannotLogin`、`Feature/AdminStudentTest::testArchiveAndRestoreOverHttp` | ✅ 已覆盖 |
| 6. 未发布讲义和草稿作业不对学生可见 | `Feature/HandoutFlowTest::testHandoutPublishFlowAndStudentDownload`、`Feature/SubmissionFlowTest::testAssignmentPublishAndVisibleToStudent`、`Integration/TeachingFlowTest` | ✅ 已覆盖 |
| 7. 不允许的文件后缀和超大文件会被拒绝 | `Unit/FileStorageTest`、`Feature/HandoutFlowTest::testHandoutRejectsBadExtension`、`Feature/SubmissionFlowTest::testSubmitRejectsBadExtensionAndOversize` | ✅ 已覆盖 |
| 8. 磁盘文件 UUID v7 命名，数据库保留原始文件名 | `Unit/FileStorageTest::testStoreGeneratesUuidV7NameAndMetadata`、`Feature/HandoutFlowTest`、`Feature/SubmissionFlowTest::testSubmitAndResubmitKeepsOnlyLatest` | ✅ 已覆盖 |
| 9. 学生重复提交后教师看到最新有效提交 | `Feature/SubmissionFlowTest::testSubmitAndResubmitKeepsOnlyLatest`、`Integration/TeachingFlowTest::testSubmissionInsertReplaceAndLateComputed` | ✅ 已覆盖 |
| 10. 未注册但列入名单的学生出现在未提交名单 | `Integration/StatsExportTest::testStatsBasedOnCurrentRosterIncludingUnregistered`、`Feature/StatsExportFlowTest::testStatsPageShowsRosterBasedNumbers` | ✅ 已覆盖 |
| 11. Excel 导出人数与页面统计一致 | `Feature/StatsExportFlowTest::testExcelExportMatchesStats` | ✅ 已覆盖 |
| 12. ZIP 包含全部有效提交，文件名不冲突 | `Feature/StatsExportFlowTest::testZipContainsAllSubmissionsWithFormattedNames`、`testZipEntryNameCollisionGetsSuffix` | ✅ 已覆盖 |
| 13. 登录和密码操作均有日志，日志不包含密码 | `Integration/AuthServiceTest::testAuthLogsNeverContainPassword`、`Integration/LogRepositoryTest` | ✅ 已覆盖 |
| 14. 课程归档后历史可查、不能继续提交 | `Integration/CoursePermissionTest::testArchivedCourseInvisibleToStudent`、`Feature/SubmissionFlowTest::testSubmitBlockedWhenCourseArchivedOrAssignmentClosed` | ✅ 已覆盖 |

新增功能时同步更新本对照表。
