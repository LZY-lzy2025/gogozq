<?php
// ==========================================
// 自动化爬虫设置 (双分类列表，精准提取球队名，仅前1.5小时过滤，昨日保留)
// 进阶优化版：增加 cURL 多线程并发 (3并发，0.5秒防封间隔)
// ==========================================
set_time_limit(0);
ignore_user_abort(true);

// 设置北京时间
date_default_timezone_set('Asia/Shanghai');
$nowTimestamp = time(); 
$currentTimeStr = date('Y-m-d H:i:s', $nowTimestamp);
$todayDate = date('Y-m-d'); // 当天日期
$todayStartTimestamp = strtotime($todayDate . ' 00:00:00'); // 今天0点时间戳

// 定义保留底线：昨天晚上 20:00 的时间戳
$retentionCutoff = $todayStartTimestamp - (4 * 3600); 

// 定义时间窗口：仅前 1.5 小时 (5400秒) 至当前时间
$timeWindowBefore = 45 * 60; // 90分钟

// 日志记录函数
function writeLog($msg) {
    global $currentTimeStr;
    $logEntry = "[{$currentTimeStr}] {$msg}\n";
    echo $logEntry . "<br>"; 
    file_put_contents('scraper_log.txt', $logEntry, FILE_APPEND);
}

/**
 * 封装单线程 cURL 请求 (用于轻量级的列表页抓取)
 */
function getHtml($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // 开启 GZIP 支持，加快网页下载速度
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 增加连接超时限制
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

writeLog("--- 定时并发抓取任务启动 ---");

$allItems = [];
$existingUrls = [];   // 用于双保险去重
$existingTitles = []; // 用于前置拦截去重，存储已有的比赛标题

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
                
                $existingUrls[$url] = true;
                $existingTitles[$title] = true; 
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
$skipCount = 0; 

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
                
                // 过滤：仅抓取前 1.5 小时到当前时间的比赛
                if ($matchTimestamp >= ($nowTimestamp - $timeWindowBefore) && $matchTimestamp <= $nowTimestamp) {
                    preg_match('/href="([^"]+)"/i', $tag, $hrefMatch);
                    
                    if (!empty($hrefMatch[1])) {
                        $homeTeam = "未知主队";
                        $awayTeam = "未知客队";
                        
                        // 提取主队
                        if (preg_match('/class=["\']team\s+zhudui[^"\']*["\'].*?<p>\s*([^<]+?)\s*<\/p>/is', $tag, $mHome)) {
                            $homeTeam = trim($mHome[1]);
                        }
                        // 提取客队
                        if (preg_match('/class=["\']team\s+kedui[^"\']*["\'].*?<p>\s*([^<]+?)\s*<\/p>/is', $tag, $mAway)) {
                            $awayTeam = trim($mAway[1]);
                        }
                        
                        // 拼接标准标题
                        $cleanTitle = $homeTeam . '-vs-' . $awayTeam;
                        
                        // 补齐日期后缀 
                        if (strpos($cleanTitle, $matchDate) === false) {
                            $cleanTitle .= "({$matchDate})";
                        }

                        // 前置拦截：已存在则跳过
                        if (isset($existingTitles[$cleanTitle])) {
                            echo "<span style='color:orange;'>[本地已存] 前置跳过无需重复抓取: {$cleanTitle}</span><br>\n";
                            $skipCount++;
                            continue;
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
writeLog("在规定的时间窗口内，发现 {$totalFound} 场新比赛需抓取源，另前置跳过 {$skipCount} 场已知比赛...");

$successCount = 0;
$urlSkipCount = 0;

// ==========================================
// 步骤 3：控制并发数的多线程提取 (3线程，0.5秒间隔)
// ==========================================
if ($totalFound > 0) {
    $concurrencyLimit = 3;   // 最大并发数
    $sleepInterval = 500000; // 批次间隔：0.5秒 (500000微秒)

    // 将需要抓取的数据按分组切割
    $matchChunks = array_chunk($matchesData, $concurrencyLimit, true);

    foreach ($matchChunks as $chunkIndex => $chunk) {
        $mh = curl_multi_init();
        $curlArray = [];

        // 1. 批量初始化当前批次的 cURL 句柄
        foreach ($chunk as $index => $match) {
            $fullLink = strpos($match['url'], 'http') === 0 ? $match['url'] : $baseUrl . $match['url'];
            
            $curlArray[$index] = curl_init();
            curl_setopt($curlArray[$index], CURLOPT_URL, $fullLink);
            curl_setopt($curlArray[$index], CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlArray[$index], CURLOPT_ENCODING, 'gzip, deflate');
            curl_setopt($curlArray[$index], CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            curl_setopt($curlArray[$index], CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curlArray[$index], CURLOPT_TIMEOUT, 10); 
            curl_setopt($curlArray[$index], CURLOPT_CONNECTTIMEOUT, 5); 
            
            curl_multi_add_handle($mh, $curlArray[$index]);
        }

        // 2. 并发执行当前批次的请求
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh); // 阻塞等待事件，降低 CPU 负载
            }
        } while ($active && $status == CURLM_OK);

        // 3. 处理当前批次的返回数据
        foreach ($chunk as $index => $match) {
            $detailHtml = curl_multi_getcontent($curlArray[$index]);
            $m3u8Pattern = '/src:\s*[\'"]([^\'"]+\.m3u8[^\'"]*)[\'"]/i';

            if (!empty($detailHtml) && preg_match($m3u8Pattern, $detailHtml, $m3u8Match)) {
                $rawM3u8Url = $m3u8Match[1];
                $parsedUrl = parse_url($rawM3u8Url);
                $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] : 'https';
                $host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
                $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
                
                if ($host && $path) {
                    $cleanM3u8Url = $scheme . '://' . $host . $path;
                    $cleanM3u8Url = str_replace('adaptive', '1080p', $cleanM3u8Url);

                    if (isset($existingUrls[$cleanM3u8Url])) {
                        echo "<span style='color:orange;'>[源已存在] 双重校验跳过: {$match['title']}</span><br>\n";
                        $urlSkipCount++;
                    } else {
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
                        $existingUrls[$cleanM3u8Url] = true;
                        echo "<span style='color:green;'>[新增] 成功提取: {$match['title']}</span><br>\n";
                        $successCount++;
                    }
                }
            }
            
            // 释放当前句柄资源
            curl_multi_remove_handle($mh, $curlArray[$index]);
            curl_close($curlArray[$index]);
        }
        
        // 关闭多线程句柄
        curl_multi_close($mh);

        // 4. 当前批次处理完毕后，强制休眠防封 (最后一批无需休眠)
        if ($chunkIndex < count($matchChunks) - 1) {
            usleep($sleepInterval);
        }
    }
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

$totalSkip = $skipCount + $urlSkipCount;
writeLog("任务完成！共新增 {$successCount} 场。前置跳过 {$skipCount} 场，源去重跳过 {$urlSkipCount} 场。");
writeLog(str_repeat("=", 40));
?>
