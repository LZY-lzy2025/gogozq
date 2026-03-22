# 使用轻量级的 PHP Alpine 镜像
FROM php:8.2-cli-alpine

# 安装时区数据并设置北京时间
RUN apk add --no-cache tzdata
ENV TZ=Asia/Shanghai

# 设置工作目录
WORKDIR /app

# 将当前目录下的所有文件复制到容器的 /app 目录
COPY . /app

# 赋予启动脚本执行权限
RUN chmod +x /app/start.sh

# 暴露端口 (Render 实际使用时会动态绑定)
EXPOSE 8000

# 启动容器时执行脚本
CMD ["/app/start.sh"]
