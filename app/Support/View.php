<?php

declare(strict_types=1);

namespace App\Support;

final class View
{
    /**
     * 渲染模板：app/Views/$name.php，自动包一层 layout。
     * $data 中的变量 extract 进模板作用域，模板内输出用户内容必须用 e() 转义。
     */
    public static function render(string $name, array $data = []): void
    {
        $file = dirname(__DIR__) . '/Views/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("模板不存在: {$name}");
        }
        extract($data, EXTR_SKIP);
        require dirname(__DIR__) . '/Views/layout.php';
    }
}
