<?php
// 复制为 config.local.php 并填写本机实际值（config.local.php 不入库）
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'class_app',
        'user' => 'class_app',
        'pass' => '填写数据库密码',
    ],
    'app' => [
        // 站点对外地址，学生手机扫码访问注册页时使用；
        // 填学生手机能访问到的地址，如 http://192.168.1.10:8080 或 https://course.example.com
        'base_url' => '',
    ],
];
