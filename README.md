# gogozq 抓取服务（Go 版本）

这是一个基于 Go 的直播源抓取服务，提供：

- 后台抓取任务（并发抓取分类页 + 详情页）
- 输出 `playlist.m3u` 与 `live_links.txt`
- 运行日志 `scraper_log.txt`
- HTTP 接口用于查看文件与手动触发任务

## 接口

默认端口：`8000`

- `/`：导航页
- `/trigger`：后台触发一次抓取（若已有任务运行会提示稍后）
- `/task`：同步执行抓取（调试用途）
- `/playlist.m3u`：M3U 播放列表
- `/live_links.txt`：文本链接列表
- `/scraper_log.txt`：抓取日志

## 本地运行

需要 Go 1.22+

```bash
go run .
```

或构建后运行：

```bash
go build -o gogozq ./main.go
./gogozq
```

## Docker 运行

```bash
docker build -t gogozq:latest .
docker run --rm -p 8000:8000 -e TZ=Asia/Shanghai gogozq:latest
```

## 抓取逻辑概要

1. 读取历史 `playlist.m3u`，构建已存在标题与 URL 集合用于去重。
2. 抓取足球/篮球分类页，筛选最近 90 分钟内比赛。
3. 并发抓取详情页，提取 `m3u8` 并做 URL 规范化。
4. 合并旧数据与新数据，按时间差排序后写回输出文件。
5. 使用进程内状态锁 + `task.lock` 文件锁避免并发重复执行。

## 目录说明

- `main.go`：Go 服务与抓取逻辑主实现
- `Dockerfile`：多阶段构建（构建镜像 + 轻量运行镜像）
- `start.sh`：容器启动脚本
- `task.php` / `index.php` / `trigger.php`：历史 PHP 版本文件（当前容器默认不再使用）

## 注意事项

- 目标站点结构变化会影响正则解析，必要时需要更新解析规则。
- 如果部署环境有严格网络策略，请确保可访问目标站点并允许 HTTPS。
