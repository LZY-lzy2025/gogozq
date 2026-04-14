package main

import (
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	baseURL             = "https://www.gogozq.cc"
	playlistFile        = "playlist.m3u"
	liveLinksFile       = "live_links.txt"
	logFile             = "scraper_log.txt"
	lockFile            = "task.lock"
	retentionHours      = 4
	timeWindowMinutes   = 45
	listFetchTimeout    = 10 * time.Second
	detailFetchTimeout  = 10 * time.Second
	listFetchRetries    = 3
	listRetryWait       = 2 * time.Second
	detailFetchRetries  = 2
	detailRetryWait     = 1 * time.Second
	maxDetailConcurrent = 6
)

var (
	taskMu      sync.Mutex
	taskRunning bool
)

type matchCandidate struct {
	Title     string
	URL       string
	Time      string
	Block     string
	Timestamp time.Time
}

type item struct {
	Block      string
	Time       string
	Title      string
	URL        string
	Timestamp  time.Time
	DiffSecond int64
	IsYest     bool
}

func main() {
	port := os.Getenv("PORT")
	if port == "" {
		port = "8000"
	}

	go func() {
		if err := runScrape(context.Background()); err != nil {
			appendLog("startup task failed: " + err.Error())
		}
	}()

	http.HandleFunc("/", indexHandler)
	http.HandleFunc("/trigger", triggerHandler)
	http.HandleFunc("/task", taskHandler)
	http.HandleFunc("/playlist.m3u", staticFileHandler(playlistFile, "audio/x-mpegurl"))
	http.HandleFunc("/live_links.txt", staticFileHandler(liveLinksFile, "text/plain; charset=utf-8"))
	http.HandleFunc("/scraper_log.txt", staticFileHandler(logFile, "text/plain; charset=utf-8"))

	log.Printf("server listening on :%s", port)
	if err := http.ListenAndServe(":"+port, nil); err != nil {
		log.Fatal(err)
	}
}

func indexHandler(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	_, _ = io.WriteString(w, `<h2>✅ 抓取服务运行中 (Go)</h2>
<ul>
<li><a href='/playlist.m3u' target='_blank'>📥 播放列表 (playlist.m3u)</a></li>
<li><a href='/live_links.txt' target='_blank'>📄 文本源 (live_links.txt)</a></li>
<li><a href='/scraper_log.txt' target='_blank'>📝 运行日志 (scraper_log.txt)</a></li>
<li><a href='/trigger' target='_blank'>🚀 手动触发抓取 (/trigger)</a></li>
</ul>
<p style='color:gray; font-size:12px;'>抓取任务在后台运行，不阻塞页面访问。</p>`)
}

func triggerHandler(w http.ResponseWriter, _ *http.Request) {
	if !startTaskIfIdle() {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = io.WriteString(w, "<h2>⏳ 已有任务在运行，请稍后再试。</h2>")
		return
	}
	go func() {
		defer finishTask()
		if err := runScrape(context.Background()); err != nil {
			appendLog("manual task failed: " + err.Error())
		}
	}()
	w.Header().Set("Content-Type", "text/html; charset=utf-8")
	_, _ = io.WriteString(w, "<h2>🚀 抓取任务已后台启动</h2><p>1~2 分钟后查看 playlist.m3u。</p>")
}

func taskHandler(w http.ResponseWriter, _ *http.Request) {
	if !startTaskIfIdle() {
		http.Error(w, "task already running", http.StatusTooManyRequests)
		return
	}
	defer finishTask()
	if err := runScrape(context.Background()); err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}
	_, _ = io.WriteString(w, "ok")
}

func staticFileHandler(name, contentType string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		b, err := os.ReadFile(filepath.Clean(name))
		if err != nil {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", contentType)
		_, _ = w.Write(b)
	}
}

func startTaskIfIdle() bool {
	taskMu.Lock()
	defer taskMu.Unlock()
	if taskRunning {
		return false
	}
	taskRunning = true
	return true
}

func finishTask() {
	taskMu.Lock()
	taskRunning = false
	taskMu.Unlock()
}

func runScrape(ctx context.Context) error {
	unlock, err := acquireFileLock(lockFile)
	if err != nil {
		appendLog("检测到已有抓取任务在运行，本次跳过")
		return nil
	}
	defer unlock()

	loc, _ := time.LoadLocation("Asia/Shanghai")
	now := time.Now().In(loc)
	todayStart := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, loc)
	retentionCutoff := todayStart.Add(-retentionHours * time.Hour)
	timeWindowStart := now.Add(-timeWindowMinutes * time.Minute)

	appendLog("--- Go 抓取任务启动 ---")

	allItems, existingURLs, existingTitles := loadExistingItems(loc, now, todayStart, retentionCutoff)

	listURLs := []string{baseURL + "/category/zuqiu", baseURL + "/category/lanqiu"}
	listBodies := fetchURLs(ctx, listURLs, listFetchTimeout, 2, listFetchRetries, listRetryWait, true)

	candidates := make([]matchCandidate, 0, 64)
	skipCount := 0
	for _, u := range listURLs {
		body := listBodies[u]
		if body == "" {
			appendLog("分类页抓取失败: " + u)
			continue
		}
		for _, c := range parseListCandidates(body, timeWindowStart, now, loc) {
			if existingTitles[c.Title] {
				skipCount++
				continue
			}
			candidates = append(candidates, c)
		}
	}
	appendLog(fmt.Sprintf("发现 %d 场候选，前置标题跳过 %d", len(candidates), skipCount))

	detailURLs := make([]string, 0, len(candidates))
	candByURL := make(map[string]matchCandidate, len(candidates))
	for _, c := range candidates {
		full := c.URL
		if !strings.HasPrefix(full, "http") {
			full = baseURL + full
		}
		detailURLs = append(detailURLs, full)
		candByURL[full] = c
	}
	detailBodies := fetchURLs(ctx, detailURLs, detailFetchTimeout, maxDetailConcurrent, detailFetchRetries, detailRetryWait, true)

	successCount := 0
	urlSkipCount := 0
	for _, du := range detailURLs {
		body := detailBodies[du]
		streamURL := extractM3U8(body)
		if streamURL == "" {
			continue
		}
		cleanURL := normalizeStreamURL(streamURL)
		if cleanURL == "" {
			continue
		}
		if existingURLs[cleanURL] {
			urlSkipCount++
			continue
		}
		c := candByURL[du]
		isY := c.Timestamp.Before(todayStart)
		allItems = append(allItems, item{
			Block:      c.Block,
			Time:       c.Time,
			Title:      c.Title,
			URL:        cleanURL,
			Timestamp:  c.Timestamp,
			DiffSecond: int64(now.Sub(c.Timestamp).Abs() / time.Second),
			IsYest:     isY,
		})
		existingURLs[cleanURL] = true
		successCount++
	}

	sort.Slice(allItems, func(i, j int) bool {
		if allItems[i].IsYest != allItems[j].IsYest {
			return !allItems[i].IsYest
		}
		return allItems[i].DiffSecond < allItems[j].DiffSecond
	})

	if err := writeOutputs(allItems, now.Format("2006-01-02")); err != nil {
		return err
	}
	appendLog(fmt.Sprintf("任务完成：新增 %d，标题跳过 %d，URL 跳过 %d", successCount, skipCount, urlSkipCount))
	return nil
}

func acquireFileLock(path string) (func(), error) {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0o644)
	if err != nil {
		if errors.Is(err, os.ErrExist) {
			return nil, err
		}
		return nil, err
	}
	_, _ = f.WriteString(strconv.FormatInt(time.Now().Unix(), 10))
	unlock := func() {
		_ = f.Close()
		_ = os.Remove(path)
	}
	return unlock, nil
}

func appendLog(msg string) {
	line := fmt.Sprintf("[%s] %s\n", time.Now().Format("2006-01-02 15:04:05"), msg)
	fmt.Print(line)
	f, err := os.OpenFile(logFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
	if err != nil {
		return
	}
	defer f.Close()
	_, _ = f.WriteString(line)
}

func loadExistingItems(loc *time.Location, now, todayStart, retentionCutoff time.Time) ([]item, map[string]bool, map[string]bool) {
	items := make([]item, 0, 256)
	existingURLs := map[string]bool{}
	existingTitles := map[string]bool{}

	b, err := os.ReadFile(playlistFile)
	if err != nil {
		return items, existingURLs, existingTitles
	}
	s := bufio.NewScanner(bytes.NewReader(b))
	lines := []string{}
	for s.Scan() {
		line := strings.TrimSpace(s.Text())
		if line != "" {
			lines = append(lines, line)
		}
	}
	infRe := regexp.MustCompile(`group-title="([^"]+)", \[(\d{2}):(\d{2})\] (.+)$`)
	dateRe := regexp.MustCompile(`\((\d{4}-\d{2}-\d{2})\)`)
	for i := 0; i < len(lines); i++ {
		line := lines[i]
		if !strings.HasPrefix(line, "#EXTINF") {
			continue
		}
		if i+1 >= len(lines) {
			break
		}
		url := lines[i+1]
		i++
		m := infRe.FindStringSubmatch(line)
		if len(m) != 5 {
			continue
		}
		block := strings.TrimPrefix(m[1], "昨日 ")
		timeStr := m[2] + ":" + m[3]
		title := m[4]
		dateStr := now.Format("2006-01-02")
		if dm := dateRe.FindStringSubmatch(title); len(dm) == 2 {
			dateStr = dm[1]
		}
		ts, err := time.ParseInLocation("2006-01-02 15:04:05", dateStr+" "+timeStr+":00", loc)
		if err != nil || ts.Before(retentionCutoff) {
			continue
		}
		items = append(items, item{
			Block:      block,
			Time:       timeStr,
			Title:      title,
			URL:        url,
			Timestamp:  ts,
			DiffSecond: int64(now.Sub(ts).Abs() / time.Second),
			IsYest:     ts.Before(todayStart),
		})
		existingURLs[url] = true
		existingTitles[title] = true
	}
	return items, existingURLs, existingTitles
}

func fetchURLs(
	ctx context.Context,
	urls []string,
	timeout time.Duration,
	maxConcurrent int,
	retries int,
	wait time.Duration,
	logRetry bool,
) map[string]string {
	if retries < 1 {
		retries = 1
	}
	if wait < 0 {
		wait = 0
	}

	results := make(map[string]string, len(urls))
	if len(urls) == 0 {
		return results
	}
	if maxConcurrent < 1 {
		maxConcurrent = 1
	}
	client := &http.Client{Timeout: timeout}
	sem := make(chan struct{}, maxConcurrent)
	var wg sync.WaitGroup
	var mu sync.Mutex

	for _, rawURL := range urls {
		u := rawURL
		wg.Add(1)
		go func() {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()

			for attempt := 1; attempt <= retries; attempt++ {
				req, err := http.NewRequestWithContext(ctx, http.MethodGet, u, nil)
				if err != nil {
					return
				}
				req.Header.Set("User-Agent", "Mozilla/5.0")
				req.Header.Set("Accept-Encoding", "gzip")

				resp, err := client.Do(req)
				if err == nil {
					body := ""
					if resp.StatusCode >= 200 && resp.StatusCode < 300 {
						body, err = readMaybeGzip(resp.Body, resp.Header.Get("Content-Encoding"))
					}
					resp.Body.Close()
					if err == nil && body != "" {
						mu.Lock()
						results[u] = body
						mu.Unlock()
						return
					}
				}

				if attempt < retries && wait > 0 {
					if logRetry {
						appendLog(fmt.Sprintf("请求失败，等待后重试 (%d/%d)", attempt, retries))
					}
					select {
					case <-ctx.Done():
						return
					case <-time.After(wait):
					}
				}
			}
		}()
	}

	wg.Wait()
	return results
}

func readMaybeGzip(r io.Reader, encoding string) (string, error) {
	if strings.Contains(strings.ToLower(encoding), "gzip") {
		gr, err := gzip.NewReader(r)
		if err != nil {
			return "", err
		}
		defer gr.Close()
		b, err := io.ReadAll(gr)
		return string(b), err
	}
	b, err := io.ReadAll(r)
	return string(b), err
}

func parseListCandidates(html string, winStart, now time.Time, loc *time.Location) []matchCandidate {
	tagRe := regexp.MustCompile(`(?is)<a[^>]*class="clearfix\s*"[^>]*>.*?</a>`)
	timeRe := regexp.MustCompile(`(\d{2}:\d{2})`)
	dateRe := regexp.MustCompile(`data-time="([^"]+)"`)
	hrefRe := regexp.MustCompile(`href="([^"]+)"`)
	homeRe := regexp.MustCompile(`(?is)class=["']team\s+zhudui[^"']*["'].*?<p>\s*([^<]+?)\s*</p>`)
	awayRe := regexp.MustCompile(`(?is)class=["']team\s+kedui[^"']*["'].*?<p>\s*([^<]+?)\s*</p>`)

	cands := make([]matchCandidate, 0, 64)
	for _, tag := range tagRe.FindAllString(html, -1) {
		dm := dateRe.FindStringSubmatch(tag)
		tm := timeRe.FindStringSubmatch(tag)
		hm := hrefRe.FindStringSubmatch(tag)
		if len(dm) != 2 || len(tm) != 2 || len(hm) != 2 {
			continue
		}
		ts, err := time.ParseInLocation("2006-01-02 15:04:05", dm[1]+" "+tm[1]+":00", loc)
		if err != nil || ts.Before(winStart) || ts.After(now) {
			continue
		}
		home := "未知主队"
		away := "未知客队"
		if m := homeRe.FindStringSubmatch(tag); len(m) == 2 {
			home = strings.TrimSpace(m[1])
		}
		if m := awayRe.FindStringSubmatch(tag); len(m) == 2 {
			away = strings.TrimSpace(m[1])
		}
		title := fmt.Sprintf("%s-vs-%s(%s)", home, away, dm[1])
		cands = append(cands, matchCandidate{Title: title, URL: hm[1], Time: tm[1], Block: getTimeBlock(tm[1]), Timestamp: ts})
	}
	return cands
}

func extractM3U8(html string) string {
	re := regexp.MustCompile(`src:\s*["']([^"']+\.m3u8[^"']*)["']`)
	m := re.FindStringSubmatch(html)
	if len(m) != 2 {
		return ""
	}
	return m[1]
}

func normalizeStreamURL(raw string) string {
	u, err := url.Parse(raw)
	if err != nil || u.Host == "" || u.Path == "" {
		return ""
	}
	clean := fmt.Sprintf("%s://%s%s", u.Scheme, u.Host, u.Path)
	if strings.HasPrefix(clean, "://") {
		clean = "https" + clean
	}
	return strings.ReplaceAll(clean, "adaptive", "1080p")
}

func getTimeBlock(timeStr string) string {
	if len(timeStr) < 2 {
		return "未知时间"
	}
	hour, _ := strconv.Atoi(timeStr[:2])
	switch {
	case hour < 4:
		return "00:00-04:00"
	case hour < 8:
		return "04:00-08:00"
	case hour < 12:
		return "08:00-12:00"
	case hour < 16:
		return "12:00-16:00"
	case hour < 20:
		return "16:00-20:00"
	case hour <= 23:
		return "20:00-24:00"
	default:
		return "未知时间"
	}
}

func writeOutputs(items []item, today string) error {
	var m3u strings.Builder
	var txt strings.Builder
	m3u.WriteString("#EXTM3U\n")
	m3u.WriteString("# DATE: " + today + "\n")

	if len(items) == 0 {
		m3u.WriteString("#EXTINF:-1 group-title=\"提示\", [00:00] 当前时段暂无符合条件的比赛\nhttp://127.0.0.1/empty.m3u8\n")
		txt.WriteString("当前时段暂无符合条件的比赛\n")
	} else {
		for _, it := range items {
			block := it.Block
			if it.IsYest {
				block = "昨日 " + block
			}
			m3u.WriteString(fmt.Sprintf("#EXTINF:-1 group-title=\"%s\", [%s] %s\n", block, it.Time, it.Title))
			m3u.WriteString(it.URL + "\n")
			txt.WriteString(fmt.Sprintf("[%s] %s : %s\n", block, it.Title, it.URL))
		}
	}

	if err := os.WriteFile(playlistFile, []byte(m3u.String()), 0o644); err != nil {
		return err
	}
	if err := os.WriteFile(liveLinksFile, []byte(txt.String()), 0o644); err != nil {
		return err
	}
	return nil
}
