# PHP 直播流中间件（命名参数单链接）

本项目是一个基于 PHP 的轻量中间件，使用“命名参数的单链接”同时携带直播与回放源。根据是否存在 `playseek` 自动选择目标源并重定向到上级代理。

- 直播（无 `playseek`）：重定向到 `<代理基址>/rtp/...`
- 回放（有 `playseek`）：重定向到 `<代理基址>/rtsp/...?...playseek=...`
- `r2h-token`：作为上级代理的验证参数，会被自动追加到重定向 URL 末尾（若目标 URL 已包含该参数则不重复）
- `fcc`（可选，直播专用）：若提供，将以 `?fcc=...` 形式追加在组播地址后；未提供则不追加
- 服务监听 `0.0.0.0:8888`

## 请求格式
- 直播（含 `fcc` 与 `r2h-token`）：
  - `https://rtprtsp.wjkk.top:8/?proxy=https://itv.wjkk.top:8&rtp=rtp://225.1.2.47:10276&rtsp=rtsp://10.254.192.94/PLTV/88888888/224/3221225621/10000100000000060000000000009742_0.smil&fcc=10.254.185.70:15970&r2h-token=key123456`
  - 重定向到：`https://itv.wjkk.top:8/rtp/225.1.2.47:10276?fcc=10.254.185.70%3A15970&r2h-token=key123456`
- 回放（含 `playseek` 与 `r2h-token`）：
  - `https://rtprtsp.wjkk.top:8/?proxy=https://itv.wjkk.top:8&rtp=rtp://225.1.2.47:10276&rtsp=rtsp://10.254.192.94/PLTV/88888888/224/3221225621/10000100000000060000000000009742_0.smil&playseek=3600&r2h-token=key123456`
  - 重定向到：`https://itv.wjkk.top:8/rtsp/10.254.192.94/PLTV/88888888/224/3221225621/10000100000000060000000000009742_0.smil?playseek=3600&r2h-token=key123456`

## 工作原理
- 必要参数：
  - `proxy`：上级代理基址，例如 `https://itv.wjkk.top:8`
  - `rtp`：直播源，例如 `rtp://225.1.2.47:10276`
  - `rtsp`：回放源，例如 `rtsp://10.254.192.94/PLTV/.../smil`
- 选择逻辑：
  - 存在 `playseek` → 选择 `rtsp` 并在目标 URL 追加 `playseek`
  - 不存在 `playseek` → 选择 `rtp`
- 附加参数：
  - `r2h-token` 作为代理验证参数，若存在，会自动追加到最终重定向 URL 的查询字符串末尾（防重复）
  - `fcc`（可选，直播专用），若提供则追加一次 `?fcc=...`

## 安全与认证
- `r2h-token` 是上级代理的验证参数，应视为敏感信息：
  - 默认代码在日志中仅记录“是否存在 token”，不打印明文。
  - 推荐通过 HTTPS 调用中间件与上级代理。
  - 在反向代理层可对敏感查询参数做访问控制与日志脱敏。

## 快速开始与 Docker/CI
- 本地运行：`php -S 0.0.0.0:8888 -t . index.php` 或 `./start_php_middleware.bat`
- Docker 构建：`docker build -t rtp-rstp:local .`，运行：`docker run --rm -p 8888:8888 rtp-rstp:local`
- Compose：`docker compose up -d`
- GHCR：推送到 `main` 自动构建并发布到 `ghcr.io/<你的用户名>/<仓库名>:latest`

## 故障排除
- 400：缺少必需参数 `proxy` / `rtp` / `rtsp` 或格式错误
- 500：构建目标 URL 失败，请查看 `middleware_errors.log`
- PowerShell：使用 `curl.exe` 并用双引号包裹 URL，避免 `&` 被解释