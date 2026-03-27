<?php
// ==========================================
// 自动化爬虫设置 (双分类列表，精准提取球队名，前1.5小时后30分钟过滤，昨日保留)
// ==========================================
set_time_limit(0);
ignore_user_abort(true);

// 设置北京时间
date_default_timezone_set('Asia/Shanghai');
$nowTimestamp = time(); 
$currentTimeStr = date('Y-m-d H:i:s', $nowTimestamp);
$todayDate = date('Y-m-d'); // 当天日期
$todayStartTimestamp = strtotime($todayDate . ' 00:00:00'); // 今天0点时间戳

// 【重要】定义保留底线：昨天晚上 20:00 的时间戳
$retentionCutoff = $todayStartTimestamp - (4 * 3600); 

// 【修改】定义时间窗口：前 1.5 小时 (5400秒)，后 30 分钟 (1800秒)
$timeWindowBefore = 90 * 60; // 90分钟
$timeWindowAfter = 30 * 60;  // 30分钟

// 日志记录函数
function writeLog($msg) {
    global $currentTimeStr;
    $logEntry = "[{$currentTimeStr}] {$msg}\n";
    echo $logEntry . "<br>"; 
    file_put_contents('scraper_log.txt', $logEntry, FILE_APPEND);
}

/**
 * 封装 cURL 请求
 */
function getHtml($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // 加上更逼真的 UA 伪装
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $html = curl_exec($ch);
    curl_close($ch);
    return $html;
}

/**
 * 计算 4 小时分组
 */
function getTimeBlock($timeStr) {
    if (!$timeStr) return "未知时间";
    $hour = (int)substr($timeStr, 0, 2);
    if ($hour >= 0 && $hour < 4) return "00:00-04:00";
    if ($hour >= 4 && $hour < 8) return "04:00-08:00";
    if ($hour >= 8 && $hour < 12) return "08:00-12:00";
    if ($hour >= 12 && $hour < 16) return "12:00-16:00";
    if ($hour >= 16 && $hour < 20) return "16:00-20:00";
    if ($hour >= 20 && $hour <= 23) return "20:00-24:00";
    return "未知时间";
}

// ==========================================
// 步骤 1：精准解析已有源，利用真实时间戳过滤
// ==========================================
$m3uFile = "playlist.m3u";
$txtFile = "live_links.txt";
$logFile = "scraper_log.txt";

writeLog("--- 定时抓取任务启动 ---");

$allItems = [];
$existingUrls = []; // 用来去重

if (file_exists($m3uFile)) {
    $m3uLines = file($m3uFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    for ($i = 0; $i < count($m3uLines); $i++) {
        $line = trim($m3uLines[$i]);
        if (strpos($line, '#EXTINF') === 0) {
            $url = isset($m3uLines[$i+1]) ? trim($m3uLines[$i+1]) : '';
            if (preg_match('/group-title="([^"]+)", \[(\d{2}):(\d{2})\] (.*)/', $line, $m)) {
                $block = str_replace('昨日 ', '', $m[1]);
                $timeStr = "{$m[2]}:{$m[3]}";
                $title = $m[4];
                
                $itemDate = $todayDate; // 默认值
                if (preg_match('/\((\d{4}-\d{2}-\d{2})\s*\)/', $title, $dateMatch)) {
                    $itemDate = $dateMatch[1];
                }
                
                $itemTimestamp = strtotime("{$itemDate} {$timeStr}:00");
                
                // 如果早于昨天晚上 20:00，无情抛弃
                if ($itemTimestamp < $retentionCutoff) {
                    $i++; // 跳过 URL 行
                    continue;
                }
                
                $isYesterday = ($itemTimestamp < $todayStartTimestamp) ? 1 : 0;
                
                $allItems[] = [
                    'block' => $block,
                    'time' => $timeStr,
                    'title' => $title,
                    'url' => $url,
                    'timestamp' => $itemTimestamp,
                    'diff' => abs($itemTimestamp - $nowTimestamp),
                    'is_yesterday' => $isYesterday
                ];
                $existingUrls[] = $url;
            }
            $i++; // 步进跳过 URL 行
        }
    }
}

// ==========================================
// 步骤 2：访问列表页，提取并过滤比赛
// ==========================================
$baseUrl = "https://www.gogozq.cc";

// 包含足球和篮球分类
$listUrls = [
    "https://www.gogozq.cc/category/zuqiu",
    "https://www.gogozq.cc/category/lanqiu"
];

$matchesData = [];
$tagPattern = '/<a[^>]*class="clearfix\s*"[^>]*>.*?<\/a>/is';

// 遍历分类页面抓取
foreach ($listUrls as $listUrl) {
    writeLog("正在解析分类页面: {$listUrl}");
    $listHtml = getHtml($listUrl);
    preg_match_all($tagPattern, $listHtml, $tagMatches);

    if (!empty($tagMatches[0])) {
        foreach ($tagMatches[0] as $tag) {
            preg_match('/data-time="([^"]+)"/i', $tag, $dateMatch); 
            preg_match('/(\d{2}:\d{2})/i', $tag, $timeMatch); 
            
            if (!empty($dateMatch[1]) && !empty($timeMatch[1])) {
                $matchDate = $dateMatch[1];
                $matchTime = $timeMatch[1];
                $matchTimestamp = strtotime("{$matchDate} {$matchTime}:00");
                
                // 【核心修改】过滤：前 1.5 小时，后 30 分钟
                if ($matchTimestamp >= ($nowTimestamp - $timeWindowBefore) && $matchTimestamp <= ($nowTimestamp + $timeWindowAfter)) {
                    preg_match('/href="([^"]+)"/i', $tag, $hrefMatch);
                    
                    if (!empty($hrefMatch[1])) {
                        
                        // 精准定位 HTML 节点内的球队名称
                        $homeTeam = "未知主队";
                        $awayTeam = "未知客队";
                        
                        // 提取主队 (匹配 class 包含 team zhudui 的 div 里面的 p 标签)
                        if (preg_match('/class=["\']team\s+zhudui[^"\']*["\'].*?<p>\s*([^<]+?)\s*<\/p>/is', $tag, $mHome)) {
                            $homeTeam = trim($mHome[1]);
                        }
                        // 提取客队 (匹配 class 包含 team kedui 的 div 里面的 p 标签)
                        if (preg_match('/class=["\']team\s+kedui[^"\']*["\'].*?<p>\s*([^<]+?)\s*<\/p>/is', $tag, $mAway)) {
                            $awayTeam = trim($mAway[1]);
                        }
                        
                        // 拼接标准标题
                        $cleanTitle = $homeTeam . '-vs-' . $awayTeam;
                        
                        // 补齐日期后缀 
                        if (strpos($cleanTitle, $matchDate) === false) {
                            $cleanTitle .= "({$matchDate})";
                        }

                        $matchesData[] = [
                            'url' => $hrefMatch[1],
                            'title' => $cleanTitle,
                            'time' => $matchTime,
                            'block' => getTimeBlock($matchTime),
                            'timestamp' => $matchTimestamp
                        ];
                    }
                }
            }
        }
    }
    // 稍微停顿一下，防止两个列表页请求过快被拦截
    usleep(500000); 
}

$totalFound = count($matchesData);
writeLog("在规定的时间窗口（前 1.5 小时至后 30 分钟）内，共找到 {$totalFound} 场比赛 (包含足球和篮球)，开始验证并抓取源...");

$successCount = 0;
$skipCount = 0;

// ==========================================
// 步骤 3：遍历提取直播源并存入数组
// ==========================================
foreach ($matchesData as $index => $match) {
    $fullLink = strpos($match['url'], 'http') === 0 ? $match['url'] : $baseUrl . $match['url'];
    $detailHtml = getHtml($fullLink);

    $m3u8Pattern = '/src:\s*[\'"]([^\'"]+\.m3u8[^\'"]*)[\'"]/i';

    if (preg_match($m3u8Pattern, $detailHtml, $m3u8Match)) {
        $rawM3u8Url = $m3u8Match[1];
        $parsedUrl = parse_url($rawM3u8Url);
        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] : 'https';
        $host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
        $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        
        if ($host && $path) {
            $cleanM3u8Url = $scheme . '://' . $host . $path;
            $cleanM3u8Url = str_replace('adaptive', '1080p', $cleanM3u8Url);

            if (in_array($cleanM3u8Url, $existingUrls)) {
                echo "<span style='color:orange;'>[跳过] 源已存在: {$match['title']}</span><br>\n";
                $skipCount++;
                continue; 
            }

            $isYesterday = ($match['timestamp'] < $todayStartTimestamp) ? 1 : 0;

            $allItems[] = [
                'block' => $match['block'],
                'time' => $match['time'],
                'title' => $match['title'],
                'url' => $cleanM3u8Url,
                'timestamp' => $match['timestamp'],
                'diff' => abs($match['timestamp'] - $nowTimestamp),
                'is_yesterday' => $isYesterday
            ];
            $existingUrls[] = $cleanM3u8Url;

            echo "<span style='color:green;'>[新增] 成功提取: {$match['title']}</span><br>\n";
            $successCount++;
        }
    }
    // 随机延迟防封
    usleep(rand(500000, 1000000));
}

// ==========================================
// 步骤 4：排序写入
// ==========================================
$m3uHandle = fopen($m3uFile, 'w');
$txtHandle = fopen($txtFile, 'w');

fwrite($m3uHandle, "#EXTM3U\n");
fwrite($m3uHandle, "# DATE: {$todayDate}\n");

if (!empty($allItems)) {
    // 排序逻辑
    usort($allItems, function($a, $b) use ($todayStartTimestamp) {
        $isYesterdayA = ($a['timestamp'] < $todayStartTimestamp) ? 1 : 0;
        $isYesterdayB = ($b['timestamp'] < $todayStartTimestamp) ? 1 : 0;

        if ($isYesterdayA !== $isYesterdayB) {
            return $isYesterdayA <=> $isYesterdayB;
        }
        return $a['diff'] <=> $b['diff'];
    });

    foreach ($allItems as $item) {
        $finalBlock = $item['is_yesterday'] ? "昨日 " . $item['block'] : $item['block'];

        fwrite($m3uHandle, sprintf("#EXTINF:-1 group-title=\"%s\", [%s] %s\n", $finalBlock, $item['time'], $item['title']));
        fwrite($m3uHandle, $item['url'] . "\n");
        
        fwrite($txtHandle, "[{$finalBlock}] {$item['title']} : {$item['url']}\n");
    }
} else {
    // 写入防空提示
    fwrite($m3uHandle, "#EXTINF:-1 group-title=\"提示\", [00:00] 当前时段暂无符合条件的比赛\nhttp://127.0.0.1/empty.m3u8\n");
    fwrite($txtHandle, "当前时段暂无符合条件的比赛\n");
}

fclose($m3uHandle);
fclose($txtHandle);

writeLog("任务完成！已遍历双分类，共新增 {$successCount} 场，跳过 {$skipCount} 场。");
writeLog(str_repeat("=", 40));
?>
