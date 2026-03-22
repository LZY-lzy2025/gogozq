<?php
// 后台异步执行爬虫，瞬间返回结果，不阻塞 Web 服务器！
exec("php /app/task.php > /app/manual_run.log 2>&1 &");
echo "<h2>🚀 抓取指令已成功发送到系统后台！</h2>";
echo "<p>爬虫正在默默努力中... 请不要重复刷新。</p>";
echo "<p>等待 1~2 分钟后，再去查看 <a href='/playlist.m3u'>playlist.m3u</a> 吧！</p>";
?>
