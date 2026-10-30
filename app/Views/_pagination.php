<?php
/**
 * 分页条局部模板。
 * 需要变量：
 * @var \App\Support\Pagination $pagination
 * @var string $route     路由名，如 'admin/courses'
 * @var array  $params    需要保留的其他查询参数（不含 r、页码、per_page）
 * @var string $pageParam 页码参数名：page / hp / ap / rp
 */
use App\Support\Pagination;

if (!isset($pagination, $route, $params, $pageParam) || $pagination->total === 0) {
    return;
}

$paginationLink = static function (int $page) use ($route, $params, $pageParam, $pagination): string {
    return '?' . http_build_query(array_merge(
        ['r' => $route],
        $params,
        [$pageParam => $page, 'per_page' => $pagination->perPage]
    ));
};

$from = max(1, $pagination->page - 2);
$to = min($pagination->pages, $pagination->page + 2);
?>
<nav class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2" aria-label="分页">
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item<?= $pagination->page <= 1 ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e($paginationLink($pagination->page - 1)) ?>">上一页</a>
        </li>
        <?php if ($from > 1): ?>
        <li class="page-item"><a class="page-link" href="<?= e($paginationLink(1)) ?>">1</a></li>
        <?php if ($from > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($p = $from; $p <= $to; $p++): ?>
        <li class="page-item<?= $p === $pagination->page ? ' active' : '' ?>">
            <a class="page-link" href="<?= e($paginationLink($p)) ?>"><?= $p ?></a>
        </li>
        <?php endfor; ?>
        <?php if ($to < $pagination->pages): ?>
        <?php if ($to < $pagination->pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <li class="page-item"><a class="page-link" href="<?= e($paginationLink($pagination->pages)) ?>"><?= $pagination->pages ?></a></li>
        <?php endif; ?>
        <li class="page-item<?= $pagination->page >= $pagination->pages ? ' disabled' : '' ?>">
            <a class="page-link" href="<?= e($paginationLink($pagination->page + 1)) ?>">下一页</a>
        </li>
    </ul>
    <span class="text-muted small">共 <?= $pagination->total ?> 条，第 <?= $pagination->page ?> / <?= $pagination->pages ?> 页</span>
    <form method="get" class="d-flex align-items-center gap-1 mb-0">
        <input type="hidden" name="r" value="<?= e($route) ?>">
        <?php foreach ($params as $name => $value): ?>
        <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
        <?php endforeach; ?>
        <label class="text-muted small text-nowrap" for="per_page_<?= e($pageParam) ?>">每页</label>
        <select class="form-select form-select-sm w-auto" id="per_page_<?= e($pageParam) ?>" name="per_page"
                onchange="this.form.submit()">
            <?php foreach (Pagination::ALLOWED_PER_PAGE as $size): ?>
            <option value="<?= $size ?>"<?= $size === $pagination->perPage ? ' selected' : '' ?>><?= $size ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">应用</button></noscript>
    </form>
</nav>
