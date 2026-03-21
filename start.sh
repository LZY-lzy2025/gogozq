#!/bin/sh

echo "容器启动，后台执行首次抓取 (不阻塞启动)..."
php /app/task.php > /app/startup.log 2>&1 &

echo "启动后台 Cron 定时任务..."
crond -b -l 8

echo "启动 PHP Web 服务..."
PORT=${PORT:-8000}
php -S 0.0.0.0:$PORT -t /app
