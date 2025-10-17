# PHP 直播流中间件（RTP/RTSP 路由）

本项目提供一个基于 PHP 的轻量中间件，根据请求中的 `playseek` 参数自动在直播与回放间切换，并将原始 IPTV URL 路由到上级代理的 `rtp/` 或 `rtsp/` 路径。

- 直播（无 `playseek`）：重定向到 `<代理基址>/rtp/...`
- 回放（有 `playseek`）：重定向到 `<代理基址>/rtsp/...?...playseek=...`
- 服务默认监听 `0.0.0.0:8888`

## 工作原理
- 请求格式：将“主 URL + 参数”放在查询字符串中。
  - 主 URL 示例：`http://<代理主机:端口>/rtp://225.1.2.47:10276?fcc=...#rtsp://10.254.192.94/PLTV/.../smil`
  - 注意：`#` 必须 URL 编码为 `%23`，否则浏览器/客户端不会把 `#` 后内容发送到服务器。
- 解析逻辑：
  - 解析主 URL 的 `scheme://host[:port]/` 作为上级代理基址（动态，无需硬编码）。
  - `rtp://...` 作为直播路径，拼接为 `rtp/...`。
  - `rtsp://...` 作为回放路径，拼接为 `rtsp/...`。
  - 若额外参数里有 `playseek`，则走回放；否则走直播。

## 快速开始（本地 Windows）
- 准备：将 Windows 版便携 PHP 解压到项目 `php/` 目录，使其中包含 `php.exe`。
- 启动：在项目根目录执行 `./start_php_middleware.bat`
- 访问：`http://localhost:8888/`

## 请求示例
- 直播（无 `playseek`）：
  - `curl -I "http://localhost:8888/?http://192.168.1.2:7890/rtp://225.1.2.47:10276?fcc=10.254.185.70:15970%23rtsp://10.254.192.94/PLTV/88888888/224/3221225621/10000100000000060000000000009742_0.smil"`
  - 重定向到：`http://192.168.1.2:7890/rtp/225.1.2.47:10276?fcc=10.254.185.70:15970`
- 回放（含 `playseek`）：
  - `curl -I "http://localhost:8888/?http://192.168.1.2:7890/rtp://225.1.2.47:10276?fcc=10.254.185.70:15970%23rtsp://10.254.192.94/PLTV/88888888/224/3221225621/10000100000000060000000000009742_0.smil&playseek=3600"`
  - 重定向到：`http://192.168.1.2:7890/rtsp/10.254.192.94/PLTV/.../smil?playseek=3600`

## Docker 使用
- 本地构建：
  - `docker build -t rtp-rstp:local .`
- 运行容器：
  - `docker run --rm -p 8888:8888 rtp-rstp:local`
- 测试请求：同上（把 `localhost:8888` 端口映射到容器）。
- GitHub Actions（GHCR）：已提供工作流 `.github/workflows/docker-image.yml`，推送到 `main` 分支后自动构建并发布镜像到 `ghcr.io/<你的用户名>/<仓库名>:latest`。
  - 拉取：`docker pull ghcr.io/<你的用户名>/<仓库名>:latest`
  - 运行：`docker run --rm -p 8888:8888 ghcr.io/<你的用户名>/<仓库名>:latest`

## 目录与文件
- 核心代码：`index.php`
- 启动脚本（本地）：`start_php_middleware.bat`
- 容器构建：`Dockerfile`、`.dockerignore`
- CI/CD：`.github/workflows/docker-image.yml`
- 文档：`README_PHP.md`、`README_DOCKER.md`
- 忽略：`.gitignore`（忽略 `php/` 便携目录、日志等）

## 注意事项
- URL 格式说明
- 主 URL 放在查询字符串的第一个片段（直到第一个 `&`）。后续为普通参数，例如 `playseek=...`。
- 示例主 URL：
  - `http://<代理主机:端口>/rtp://225.1.2.47:10276?fcc=...%23rtsp://10.254.192.94/PLTV/.../smil`
  - 主 URL 示例：`http://<代理主机:端口>/rtp://225.1.2.47:10276?fcc=...&rtsp://10.254.192.94/PLTV/.../smil`
- 说明：
  - `#` 必须编码为 `%23` 才能随查询字符串一起发送到服务端。
  - 无 `playseek` → 走直播（`rtp/`）；有 `playseek` → 走回放（`rtsp/`）。
+   - 主 URL 中以 `&rtsp://` 作为直播与回放分界；`playseek` 等常规参数请放在主 URL 之后（例如 `&playseek=3600`）。
+   - 使用 PowerShell 时请运行 `curl.exe` 并用双引号包裹整段 URL，避免 `&` 被解释成命令分隔符。

## 日志记录
- 错误日志文件：`middleware_errors.log`（由 `index.php` 的 `error_log` 设置生成）。
- 记录内容：时间戳、原始请求 URL、`playseek` 检测结果、重定向目标、异常信息。
- 容器运行时日志输出到标准输出；如需持久化日志可挂载卷或修改日志路径。

## Windows 本地启动
- 使用脚本：`./start_php_middleware.bat`
  - 优先使用项目目录下 `php\php.exe`（便携版），否则尝试系统 PATH 中的 `php`。
  - 若启动失败，请在项目 `php/` 目录放置官方 ZIP 解压后的 `php.exe`。
- 直接命令（已安装 PHP）：
  - `php -S 0.0.0.0:8888 -t . index.php`

## 故障排除
- 服务无法启动：确保存在可用的 `php.exe` 或使用 Docker 方式。
- 请求 400：检查主 URL 是否完整且是否对 `#` 做了 `%23` 编码。
- 请求 500：查看 `middleware_errors.log` 获取异常详情。
- 重定向异常：确认 `playseek` 是否正确传递，且上级代理可用。
- PowerShell 下 `curl` 混淆：请使用 `curl.exe`，并用双引号包裹整段 URL（避免 `&` 被解释）。
- 上级代理地址由主 URL 动态解析（例如 `http://192.168.1.2:7890/...`），无需在代码里写死。
- 生产环境可通过反向代理将容器的 `8888` 暴露为你的公开地址（如 `http://192.168.1.100:8888`）。

## 便携版 PHP 说明
- 适用场景：本机未安装 PHP 或不希望改动系统 PATH。
- 准备步骤：
  - 在项目根目录创建 `php/` 目录（已存在可跳过）。
  - 将官方 Windows 版 PHP 的 Zip 包解压到 `php/`，确保存在 `php\php.exe`。
  - 启动脚本会优先使用 `php\php.exe`，无需系统安装。
- 约定与忽略：
  - `php/` 为本地运行的便携目录，已在 `.gitignore` 与 `.dockerignore` 中忽略，不会被提交或打包到镜像。
- 常见问题：
  - 启动报错“PHP not found”：确认 `php\php.exe` 路径正确；或改用 Docker。
  - PowerShell 使用 `curl` 时出现交互提示：请使用 `curl.exe` 并用双引号包裹完整 URL。
  - 端口占用：修改脚本或 Docker 端口映射为其他端口。

## Docker 与 CI/CD（汇总）
- 本地构建与运行：
  - 构建：`docker build -t rtp-rstp:local .`
  - 运行：`docker run --rm -p 8888:8888 rtp-rstp:local`
- GHCR 自动构建与发布：
  - 工作流：`.github/workflows/docker-image.yml`（推送 `main` 自动构建并推送到 `ghcr.io/<你的用户名>/<仓库名>`）。
  - 拉取：`docker pull ghcr.io/<你的用户名>/<仓库名>:latest`
  - 运行：`docker run --rm -p 8888:8888 ghcr.io/<你的用户名>/<仓库名>:latest`
- 额外说明：
  - 容器内服务监听 `0.0.0.0:8888`；`php/` 便携目录不会进入镜像。
  - 如需公开访问，可在反向代理层映射到 `http://192.168.1.100:8888`。