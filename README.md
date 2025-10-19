# PHP 直播流中间件（命名参数单链接）

本项目是一个基于 PHP 的轻量中间件，使用“命名参数的单链接”同时携带直播与回放源。根据是否存在 `playseek` 自动选择目标源并重定向到上级代理。

- 直播（无 `playseek`）：重定向到 `<代理基址>/rtp/...`
- 回放（有 `playseek`）：重定向到 `<代理基址>/rtsp/...?...playseek=...`
- `r2h-token`：作为上级代理的验证参数，会被自动追加到重定向 URL 末尾（若目标 URL 已包含该参数则不重复）
- 服务监听 `0.0.0.0:8888`

## 工作原理
- 统一采用“命名参数单链接”，不再使用特殊分隔符拼接主 URL（旧格式已移除）。
- 必要参数：
  - `proxy`：上级代理基址，例如 `http://192.168.1.2:7890`
  - `rtp`：直播源，例如 `rtp://225.1.2.47:10276`（可带查询 `?fcc=...`）
  - `rtsp`：回放源，例如 `rtsp://10.254.192.94/PLTV/.../smil`
- 选择逻辑：
  - 存在 `playseek` → 选择 `rtsp` 并在目标 URL 追加 `playseek`
  - 不存在 `playseek` → 选择 `rtp`
- 附加参数：
  - `r2h-token` 作为代理验证参数，若存在，会自动追加到最终重定向 URL 的查询字符串末尾（防重复）

## 请求格式
- 直播（无 `playseek`）：
  - `http://localhost:8888/?proxy=http://192.168.1.2:7890&rtp=rtp://225.1.2.47:10276&rtsp=rtsp://10.254.192.94/PLTV/.../smil&r2h-token=abc123`
  - 重定向到：`http://192.168.1.2:7890/rtp/225.1.2.47:10276?r2h-token=abc123`
- 回放（有 `playseek`）：
  - `http://localhost:8888/?proxy=http://192.168.1.2:7890&rtp=rtp://225.1.2.47:10276&rtsp=rtsp://10.254.192.94/PLTV/.../smil&playseek=3600&r2h-token=abc123`
  - 重定向到：`http://192.168.1.2:7890/rtsp/10.254.192.94/PLTV/.../smil?playseek=3600&r2h-token=abc123`

## 安全与认证
- `r2h-token` 是上级代理的验证参数，应视为敏感信息：
  - 默认代码在日志中仅记录“是否存在 token”，不打印明文；如需更严格可屏蔽所有与 token 相关的日志。
  - 推荐通过 HTTPS 调用中间件与上级代理，避免中间人攻击。
  - 在反向代理层可对敏感查询参数做访问控制与日志脱敏。

## 快速开始（本地 Windows）
- 便携 PHP 方式：
  - 在项目根目录创建 `php/` 目录，将官方 Windows 版 PHP Zip 解压至其中，保证存在 `php\php.exe`
  - 启动脚本：在项目根目录运行 `./start_php_middleware.bat`
  - 访问：`http://localhost:8888/`
- 已安装 PHP 的直接命令：
  - `php -S 0.0.0.0:8888 -t . index.php`

## Docker 使用
- 本地构建：
  - `docker build -t rtp-rstp:local .`
- 运行容器：
  - `docker run --rm -p 8888:8888 rtp-rstp:local`
- Docker Compose：
  - 启动（Compose v2）：`docker compose up -d`
  - 停止：`docker compose down`
  - 查看日志：`docker compose logs -f`
- GitHub Actions（GHCR）：
  - 推送到 `main` 后自动构建并发布到 `ghcr.io/<你的用户名>/<仓库名>:latest`（工作流在 `.github/workflows/docker-image.yml`）
  - 拉取：`docker pull ghcr.io/<你的用户名>/<仓库名>:latest`
  - 运行：`docker run --rm -p 8888:8888 ghcr.io/<你的用户名>/<仓库名>:latest`

## 目录与文件
- 核心代码：`index.php`
- 启动脚本（本地）：`start_php_middleware.bat`
- 容器构建：`Dockerfile`、`.dockerignore`
- CI/CD：`.github/workflows/docker-image.yml`
- 文档：`README.md`
- 忽略：`.gitignore`（忽略 `php/` 便携目录、日志等）

## 注意事项
- 仅支持“命名参数单链接”的新格式；旧的主 URL 分隔格式（如 `#rtsp://` 或 `&rtsp://`）已移除
- 参数建议使用 URL 编码，或在命令行中用双引号包裹整段 URL
- PowerShell 使用 `curl.exe` 并用双引号包裹 URL，避免 `&` 被解释为命令分隔符
- `rtp` 参数若包含查询（例如 `?fcc=...`），会被保留并拼至直播重定向 URL 中
- `r2h-token` 会自动追加到目标 URL 查询末尾；如果目标已含该参数，不会重复追加

## 日志记录
- 错误日志文件：`middleware_errors.log`
- 记录内容：时间戳、原始请求参数、选择模式（直播/回放）、重定向目标、异常信息
- 建议：对 token 做日志脱敏处理（不打印明文）

## 故障排除
- 400：缺少必需参数 `proxy` / `rtp` / `rtsp` 或格式错误
- 500：构建目标 URL 失败，请查看 `middleware_errors.log` 获取详情
- 无法启动：本机缺少 PHP 或端口占用；可使用 Docker 或修改端口
- PowerShell 下 `curl` 混淆：请使用 `curl.exe`，并用双引号包裹完整 URL

## 变更说明
- 统一为“命名参数单链接”：`proxy`、`rtp`、`rtsp`、`playseek`、`r2h-token`
- 移除旧格式（主 URL + 分隔符），提升跨环境兼容性与可维护性