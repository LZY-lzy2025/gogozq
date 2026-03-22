<?php
// 后台异步执行爬虫，瞬间返回结果，不阻塞 Web 服务器！
exec("php /app/task.php > /app/manual_run.log 2>&1 &");
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>指令已发送</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; text-align: center;}
        h2 { color: #28a745; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px;}
    </style>
</head>
<body>
    <h2>🚀 抓取指令已成功发送到系统后台！</h2>
    <p>爬虫正在后台默默提取直播源，您的网络访问已释放，不会出现卡顿。</p>
    <p>请耐心等待 <strong>1~2 分钟</strong> 后，再查看最新的播放列表。</p>
    <a href="/" class="btn">返回首页</a>
</body>
</html>
