FROM golang:1.24-alpine AS build
WORKDIR /src
COPY . .
RUN go build -o /out/gogozq ./main.go

FROM alpine:3.20
RUN apk add --no-cache tzdata ca-certificates
ENV TZ=Asia/Shanghai
WORKDIR /app
COPY --from=build /out/gogozq /app/gogozq
COPY start.sh /app/start.sh
RUN chmod +x /app/start.sh /app/gogozq
EXPOSE 8000
CMD ["/app/start.sh"]
