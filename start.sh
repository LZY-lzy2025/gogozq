#!/bin/sh

echo "容器启动，执行首次抓取..."
php /app/index.php

echo "启动后台 Cron 定时任务..."
crond -b -l 8

echo "启动 PHP Web 服务..."
# Render 会自动分配 PORT 环境变量，默认使用分配的端口，否则使用 8000
PORT=${PORT:-8000}
php -S 0.0.0.0:$PORT -t /app
