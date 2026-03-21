<?php
// 纯导航页，秒开，不阻塞单线程服务器
echo "<h2>✅ 抓取服务运行中</h2>";
echo "<ul>";
echo "<li><a href='/playlist.m3u' target='_blank'>📥 播放列表 (playlist.m3u)</a></li>";
echo "<li><a href='/live_links.txt' target='_blank'>📄 文本源 (live_links.txt)</a></li>";
echo "<li><a href='/scraper_log.txt' target='_blank'>📝 运行日志 (scraper_log.txt)</a></li>";
echo "</ul>";
echo "<p style='color:gray; font-size:12px;'>爬虫由后台 Cron 定时触发，首页不再执行抓取任务。</p>";
?>
