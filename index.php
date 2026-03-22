<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPTV 直播源系统</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; line-height: 1.6; }
        a { text-decoration: none; color: #007bff; font-weight: bold; }
        a:hover { text-decoration: underline; }
        li { margin-bottom: 10px; }
        .trigger-btn { display: inline-block; padding: 10px 15px; background: #28a745; color: white; border-radius: 5px; margin-top: 15px;}
    </style>
</head>
<body>
    <h2>✅ IPTV 后台抓取服务运行中</h2>
    <p>抓取任务已配置在系统后台运行，每 30 分钟自动静默更新，不影响 Web 访问速度。</p>
    <ul>
        <li><a href="/playlist.m3u" target="_blank">📥 播放列表 (playlist.m3u)</a></li>
        <li><a href="/live_links.txt" target="_blank">📄 文本源 (live_links.txt)</a></li>
        <li><a href="/scraper_log.txt" target="_blank">📝 运行日志 (scraper_log.txt)</a></li>
    </ul>
    
    <hr style="margin: 30px 0;">
    <h3>想要手动立即更新源？</h3>
    <p style="color:gray; font-size:14px;">点击下方按钮会发送指令给后台执行，页面不会卡死转圈，点完后等 1-2 分钟即可。</p>
    <a href="/trigger.php" class="trigger-btn">🚀 立即发送手动抓取指令</a>
</body>
</html>
