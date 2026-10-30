<?php
/**
 * 备课区文件夹树侧栏。
 * 变量：$folders（平铺）、$folderCounts（folder_id => 直接条目数）、$rootCount（根目录条目数）、
 *       $currentFolderId、$linkBuilder（callable：( ?int $folderId): string）。
 */
$byParent = [];
foreach ($folders as $folder) {
    $byParent[$folder['parent_id'] ?? 0][] = $folder;
}
$totalCount = function (int $id) use (&$totalCount, $byParent, $folderCounts): int {
    $sum = $folderCounts[$id] ?? 0;
    foreach ($byParent[$id] ?? [] as $child) {
        $sum += $totalCount((int) $child['id']);
    }
    return $sum;
};
$renderNodes = function (?int $parentId) use (&$renderNodes, $byParent, $currentFolderId, $linkBuilder, $totalCount): void {
    foreach ($byParent[$parentId ?? 0] ?? [] as $folder) {
        $id = (int) $folder['id'];
        $active = $currentFolderId === $id;
        echo '<li class="list-group-item px-2 py-1 border-0">';
        echo '<a class="text-decoration-none text-truncate d-inline-block ' . ($active ? 'fw-bold' : '') . '" href="'
            . e($linkBuilder($id)) . '">📁 ' . e($folder['name'])
            . ' <span class="badge rounded-pill bg-secondary">' . $totalCount($id) . '</span></a>';
        if (!empty($byParent[$id])) {
            echo '<ul class="list-group list-group-flush ms-2">';
            $renderNodes($id);
            echo '</ul>';
        }
        echo '</li>';
    }
};
?>
<div class="card">
    <div class="card-header py-2 fw-bold">文件夹</div>
    <ul class="list-group list-group-flush small">
        <li class="list-group-item px-2 py-1">
            <a class="text-decoration-none <?= $currentFolderId === null ? 'fw-bold' : '' ?>" href="<?= e($linkBuilder(null)) ?>">📁 根目录</a>
            <span class="badge rounded-pill bg-secondary"><?= (int) $rootCount ?></span>
        </li>
        <?php $renderNodes(null); ?>
    </ul>
</div>
