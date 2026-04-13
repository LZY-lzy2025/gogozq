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

## Render 部署

### 方式一：使用 Docker（推荐）

1. 在 Render 创建 **Web Service**，选择当前仓库。
2. Render 会自动识别 `Dockerfile` 并构建镜像。
3. 在 Render 环境变量中添加：
   - `TZ=Asia/Shanghai`
   - （可选）`PORT`：不填也可以，Render 会自动注入。
4. 部署后访问根路径 `/` 验证服务启动。

> 说明：容器默认执行 `/app/start.sh`，脚本会启动 Go 二进制 `/app/gogozq`。

### 方式二：不使用 Docker（Native Runtime）

如果你在 Render 选择 Native Runtime，可配置：

- **Build Command**
  ```bash
  go build -o gogozq ./main.go
  ```
- **Start Command**
  ```bash
  ./gogozq
  ```


### Render 使用公共 GHCR 镜像部署

如果你不想让 Render 在每次部署时重新构建，可以直接拉取公共镜像：

1. 先在 GitHub 仓库启用 Actions，使用 `.github/workflows/deploy.yml` 自动推送镜像到 GHCR。
2. 将 GHCR 包设置为 **Public**（GitHub Packages 页面里可修改可见性）。
3. 在 Render 创建 **Web Service** 时选择 **Docker Image**，镜像地址填：

   - `ghcr.io/<你的GitHub用户名或组织>/<仓库名>:latest`

4. 环境变量建议：
   - `TZ=Asia/Shanghai`
   - `PORT` 可不填（Render 会自动注入）

也可以手动推送（本地一次性）：

```bash
docker build -t ghcr.io/<owner>/<repo>:latest .
echo $GITHUB_TOKEN | docker login ghcr.io -u <github_username> --password-stdin
docker push ghcr.io/<owner>/<repo>:latest
```

### 持久化文件建议（重要）

服务会写入以下文件：

- `playlist.m3u`
- `live_links.txt`
- `scraper_log.txt`
- `task.lock`（运行中锁文件）

Render 实例重启后临时文件系统会重置。若你希望输出长期保留，建议：

1. 使用 Render Disk（持久化磁盘）挂载到应用目录；或
2. 将输出同步到对象存储（如 S3）/数据库。

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

> 说明：当前部署入口是 Go（二进制 `gogozq` + `start.sh`），不是 PHP。  
> 如果你确定不再回退到 PHP，可删除这些历史 PHP 文件以减少维护成本。

## 注意事项

- 目标站点结构变化会影响正则解析，必要时需要更新解析规则。
- 如果部署环境有严格网络策略，请确保可访问目标站点并允许 HTTPS。
