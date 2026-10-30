<?php
// 应用配置加载顺序：默认值 → config.local.php（本机覆盖，不入库）→ 环境变量（优先级最高）

$config = [
    'env' => 'production',
    'timezone' => 'Asia/Shanghai',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'class_app',
        'user' => 'class_app',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'storage_path' => dirname(__DIR__) . '/storage',
    'auth' => [
        // 登录失败限流：时间窗内最多失败次数
        'login_max_attempts' => 5,
        'login_window_seconds' => 600,
    ],
];

$localFile = __DIR__ . '/config.local.php';
if (is_file($localFile)) {
    $config = array_replace_recursive($config, require $localFile);
}

$envDbMap = ['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASS' => 'pass'];
foreach ($envDbMap as $envName => $key) {
    $value = getenv($envName);
    if ($value !== false && $value !== '') {
        $config['db'][$key] = $key === 'port' ? (int) $value : $value;
    }
}
$appEnv = getenv('APP_ENV');
if ($appEnv !== false && $appEnv !== '') {
    $config['env'] = $appEnv;
}

return $config;
