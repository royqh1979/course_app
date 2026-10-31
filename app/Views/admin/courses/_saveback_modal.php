<?php
/**
 * 存入备课区模态框（课程讲义页/作业页共用，upload.js initSaveBack 驱动）。
 * 变量：$saveBackFolderOptions（['handout' => 选项数组, 'assignment' => 选项数组]，每项 [id, name, depth]）
 * 行内触发器：.save-back-trigger（data-sb-type / data-sb-id / data-sb-title，默认 d-none，JS 启动后显示）
 * 无 JS 兜底：调用处另配 <noscript> 经典直存表单。
 */
?>
<div class="modal fade" id="saveBackModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">存入备课区</h5>
                <button type="button" class="btn-close" data-sb-close aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">将《<strong id="sb-title"></strong>》存入备课区，请选择目标文件夹：</p>
                <?php foreach (['handout' => '课件', 'assignment' => '作业'] as $sbType => $sbLabel): ?>
                <div id="sb-folder-wrap-<?= $sbType ?>" class="mb-2 d-none">
                    <label class="form-label">目标文件夹（<?= $sbLabel ?>）</label>
                    <select id="sb-folder-<?= $sbType ?>" class="form-select">
                        <option value="">根目录</option>
                        <?php foreach (($saveBackFolderOptions[$sbType] ?? []) as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"><?= str_repeat('　', $option['depth'] - 1) ?><?= e($option['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
                <div id="sb-error" class="alert alert-danger d-none py-1 px-2 small mb-2"></div>
                <div id="sb-conflict" class="d-none">
                    <div class="alert alert-warning py-2 mb-2" id="sb-conflict-message"></div>
                    <div class="d-flex gap-2 flex-wrap mb-2">
                        <button type="button" id="sb-overwrite" class="btn btn-sm btn-primary">覆盖</button>
                        <button type="button" id="sb-rename-toggle" class="btn btn-sm btn-outline-secondary">改名存入</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-sb-close>放弃</button>
                    </div>
                    <div id="sb-rename-box" class="d-none">
                        <input id="sb-new-title" class="form-control mb-2" maxlength="200">
                        <button type="button" id="sb-rename-submit" class="btn btn-sm btn-primary">以新标题存入</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer" id="sb-footer">
                <button type="button" class="btn btn-secondary" data-sb-close>取消</button>
                <button type="button" id="sb-submit" class="btn btn-primary">存入</button>
            </div>
        </div>
    </div>
</div>
