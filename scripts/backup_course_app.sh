#!/bin/bash
set -euo pipefail
umask 077

SCRIPT_PATH=$(readlink -f "${BASH_SOURCE[0]}")
APP_ROOT="${COURSE_APP_ROOT:-$(cd "$(dirname "$SCRIPT_PATH")/.." && pwd)}"
DEST="${COURSE_APP_BACKUP_DEST:-/home/www-data/backup}"
LOG_DIR="${COURSE_APP_LOG_DIR:-/home/www-data/log}"
LOG_FILE="$LOG_DIR/course_app_backup.log"
DATE=$(date +%F_%H%M%S)

for command in php mysqldump gzip tar; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "缺少命令：$command" >&2
        exit 1
    fi
done

for dir in "$DEST" "$LOG_DIR"; do
    if [[ ! -d "$dir" ]]; then
        echo "目录不存在：$dir" >&2
        exit 1
    fi
    if [[ ! -w "$dir" ]]; then
        echo "目录不可写：$dir" >&2
        exit 1
    fi
done
if [[ -e "$LOG_FILE" && ! -w "$LOG_FILE" ]]; then
    echo "日志文件不可写：$LOG_FILE" >&2
    exit 1
fi
exec >> "$LOG_FILE" 2>&1

if [[ ! -f "$APP_ROOT/config/config.php" || ! -d "$APP_ROOT/storage" ]]; then
    echo "应用目录无效：$APP_ROOT" >&2
    exit 1
fi

WORK=$(mktemp -d "$DEST/.backup_${DATE}_XXXXXX")
trap 'rm -rf "$WORK"' EXIT

APP_CONFIG=$(php -r '
    $config = require $argv[1] . "/config/config.php";
    $db = $config["db"];
    $quote = static fn(string $value): string => "\"" . addcslashes($value, "\\\"\n\r\t") . "\"";
    $lines = [
        "[client]",
        "host=" . $quote((string) $db["host"]),
        "port=" . (int) $db["port"],
        "user=" . $quote((string) $db["user"]),
        "password=" . $quote((string) $db["pass"]),
    ];
    if (file_put_contents($argv[2], implode("\n", $lines) . "\n") === false) {
        fwrite(STDERR, "无法写入临时数据库配置\n");
        exit(1);
    }
    echo $db["name"], "\n", rtrim((string) $config["storage_path"], "/\\");
' "$APP_ROOT" "$WORK/db.cnf")
mapfile -t CONFIG_LINES <<< "$APP_CONFIG"
DB_NAME=${CONFIG_LINES[0]}
STORAGE_PATH=${CONFIG_LINES[1]}

if [[ ! -d "$STORAGE_PATH" ]]; then
    echo "存储目录不存在：$STORAGE_PATH" >&2
    exit 1
fi

DB_ARCHIVE="db_${DATE}.sql.gz"
STORAGE_ARCHIVE="storage_${DATE}.tar.gz"

mysqldump --defaults-extra-file="$WORK/db.cnf" \
    --single-transaction --quick --routines --events --hex-blob \
    --default-character-set=utf8mb4 "$DB_NAME" | gzip > "$WORK/$DB_ARCHIVE"
gzip -t "$WORK/$DB_ARCHIVE"
mv "$WORK/$DB_ARCHIVE" "$DEST/$DB_ARCHIVE"

tar -czf "$WORK/$STORAGE_ARCHIVE" -C "$(dirname "$STORAGE_PATH")" \
    --transform='s|^[^/]*|storage|' "$(basename "$STORAGE_PATH")"
tar -tzf "$WORK/$STORAGE_ARCHIVE" >/dev/null
mv "$WORK/$STORAGE_ARCHIVE" "$DEST/$STORAGE_ARCHIVE"

find "$DEST" -maxdepth 1 -type f \
    \( -name 'db_*.sql.gz' -o -name 'storage_*.tar.gz' \) \
    -mtime +14 -delete

printf '数据库备份：%s（%s 字节）\n' "$DEST/$DB_ARCHIVE" "$(stat -c %s "$DEST/$DB_ARCHIVE")"
printf '文件备份：%s（%s 字节）\n' "$DEST/$STORAGE_ARCHIVE" "$(stat -c %s "$DEST/$STORAGE_ARCHIVE")"
