<?php
/**
 * 直播流中间件 - PHP版本
 * 功能：根据playseek参数自动判断直播/回放模式并进行相应重定向
 */

// 设置错误处理
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'middleware_errors.log');

// 记录日志函数
function log_message($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    error_log($log_entry);
}

// 主处理函数
function process_request() {
    try {
        $full_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
        log_message("收到请求: $full_url");
        
        // 原始查询字符串可能包含两部分：
        // 1) 第一段为完整的URL（含代理、rtp路径、#rtsp片段）
        // 2) 其后可跟常规查询参数（例如 playseek=...）
        $raw_qs = $_SERVER['QUERY_STRING'] ?? '';
        if ($raw_qs === '') {
            send_error_response(400, "缺少URL参数");
            return;
        }

        parse_str($raw_qs, $qs_all);

        // 必填：proxy；rtp/rtsp至少一个
        if (!isset($qs_all['proxy'])) {
            send_error_response(400, "缺少必需参数：proxy");
            return;
        }
        $has_rtp = isset($qs_all['rtp']);
        $has_rtsp = isset($qs_all['rtsp']);
        if (!$has_rtp && !$has_rtsp) {
            send_error_response(400, "至少提供一个源参数：rtp 或 rtsp");
            return;
        }

        // 解析代理地址
        $proxy_url = urldecode($qs_all['proxy']);
        $p = parse_url($proxy_url);
        if ($p === false || !isset($p['scheme']) || !isset($p['host'])) {
            send_error_response(400, "代理地址格式错误：proxy");
            return;
        }
        $proxy_base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . '/';

        // 业务参数
        $has_playseek = isset($qs_all['playseek']);
        $playseek_value = $has_playseek ? $qs_all['playseek'] : '';
        $has_token = isset($qs_all['r2h-token']);
        $token_value = $has_token ? $qs_all['r2h-token'] : '';
        log_message("检测到r2h-token参数: " . ($has_token ? "是" : "否"));

        // 可选 fcc（直播专用）
        $has_fcc = isset($qs_all['fcc']);
        $fcc_value = $has_fcc ? $qs_all['fcc'] : '';
        log_message("检测到fcc参数: " . ($has_fcc ? "是 ($fcc_value)" : "否"));

        $rtp_param = $has_rtp ? urldecode($qs_all['rtp']) : '';
        $rtsp_param = $has_rtsp ? urldecode($qs_all['rtsp']) : '';

        // 选择逻辑
        if ($has_playseek && $has_rtsp) {
            // 回放优先：存在playseek且提供rtsp → RTSP并追加playseek
            $target_url = build_rtsp_url($proxy_base, $rtsp_param, $playseek_value, $has_token ? $token_value : null);
            log_message("选择RTSP(回放): proxy=$proxy_base, rtsp=$rtsp_param, playseek=$playseek_value" . ($has_token ? ", token=***" : ""));
        } elseif (!$has_playseek && $has_rtp) {
            // 无playseek且有rtp → 直播
            $target_url = build_rtp_url($proxy_base, $rtp_param, $has_fcc ? $fcc_value : null, $has_token ? $token_value : null);
            $fcc_log = ($has_fcc ? ", fcc=$fcc_value" : "");
            log_message("选择RTP(直播): proxy=$proxy_base, rtp=$rtp_param$fcc_log" . ($has_token ? ", token=***" : ""));
        } elseif ($has_rtsp) {
            // 仅rtsp（可能带或不带playseek）→ RTSP；不忽略playseek
            $target_url = build_rtsp_url($proxy_base, $rtsp_param, $has_playseek ? $playseek_value : null, $has_token ? $token_value : null);
            log_message("选择RTSP: proxy=$proxy_base, rtsp=$rtsp_param" . ($has_playseek ? ", playseek=$playseek_value" : "") . ($has_token ? ", token=***" : ""));
        } elseif ($has_rtp) {
            // 仅rtp且提供了playseek但没有rtsp → 忽略playseek，直播
            if ($has_playseek) {
                log_message("检测到playseek但未提供rtsp，已忽略playseek");
            }
            $target_url = build_rtp_url($proxy_base, $rtp_param, $has_fcc ? $fcc_value : null, $has_token ? $token_value : null);
            $fcc_log = ($has_fcc ? ", fcc=$fcc_value" : "");
            log_message("选择RTP(仅rtp): proxy=$proxy_base, rtp=$rtp_param$fcc_log" . ($has_token ? ", token=***" : ""));
        }

        if (empty($target_url)) {
            send_error_response(500, "构建目标URL失败");
            return;
        }

        log_message("重定向到: $target_url");
        header("Location: $target_url", true, 302);
        exit;
    } catch (Exception $e) {
        $error_message = "处理请求时发生异常: " . $e->getMessage();
        log_message($error_message);
        send_error_response(500, "内部服务器错误");
    }
}

// 构建RTP直播URL
function build_rtp_url($proxy_base, $rtp_path_raw, $fcc_value = null, $token_value = null) {
    // RTP路径形如 'rtp://239.1.2.47:10276'，需要转换为 'rtp/239.1.2.47:10276'
    if ($rtp_path_raw === '' || strpos($rtp_path_raw, 'rtp://') === false) {
        log_message("警告: 在RTP部分中未找到rtp://前缀");
        return null;
    }
    // 结构化解析 rtp 参数，仅使用 host:port，忽略其自身的查询部分，避免重复
    $rp = parse_url($rtp_path_raw);
    if ($rp === false || !isset($rp['scheme']) || strtolower($rp['scheme']) !== 'rtp' || !isset($rp['host'])) {
        log_message("警告: 在RTP部分中未找到有效的 rtp:// URL");
        return null;
    }

    $rtp_host_port = $rp['host'] . (isset($rp['port']) ? ':' . $rp['port'] : '');
    $rtp_path = 'rtp/' . $rtp_host_port;

    $full_url = rtrim($proxy_base, '/') . '/' . $rtp_path;

    // 追加一次 fcc（若存在）
    if (!empty($fcc_value)) {
        $full_url .= '?fcc=' . urlencode($fcc_value);
    }

    // 追加 r2h-token（避免重复）
    if (!empty($token_value) && strpos($full_url, 'r2h-token=') === false) {
        $connector = (strpos($full_url, '?') === false) ? '?' : '&';
        $full_url .= $connector . 'r2h-token=' . urlencode($token_value);
    }
    return $full_url;
}

// 构建RTSP回放URL
function build_rtsp_url($proxy_base, $rtsp_fragment_raw, $playseek_value = null, $token_value = null) {
    // RTSP片段形如 'rtsp://192.168.1.50/PLTV/.../smil'
    if ($rtsp_fragment_raw === '' || strpos($rtsp_fragment_raw, 'rtsp://') !== 0) {
        log_message("警告: 在RTSP部分中未找到rtsp://前缀");
        return null;
    }
    // 结构化解析 rtsp 参数，分离 host/path 与 query，保证 playseek 拼接规范
    $rp = parse_url($rtsp_fragment_raw);
    if ($rp === false || !isset($rp['scheme']) || strtolower($rp['scheme']) !== 'rtsp' || !isset($rp['host'])) {
        log_message("警告: 在RTSP部分中未找到有效的 rtsp:// URL");
        return null;
    }

    $path_part = (isset($rp['path']) ? $rp['path'] : '');
    $rtsp_path = 'rtsp/' . $rp['host'] . $path_part;

    $full_url = rtrim($proxy_base, '/') . '/' . ltrim($rtsp_path, '/');

    // 若源 rtsp URL 自带查询，先追加一次
    if (isset($rp['query']) && $rp['query'] !== '') {
        $full_url .= '?' . ltrim($rp['query'], "&?");
    }

    // 追加 playseek（仅当提供时）
    if ($playseek_value !== null && $playseek_value !== '') {
        $connector = (strpos($full_url, '?') === false) ? '?' : '&';
        $full_url .= $connector . 'playseek=' . urlencode($playseek_value);
    }

    // 追加 r2h-token（避免重复）
    if (!empty($token_value) && strpos($full_url, 'r2h-token=') === false) {
        $connector = (strpos($full_url, '?') === false) ? '?' : '&';
        $full_url .= $connector . 'r2h-token=' . urlencode($token_value);
    }
    return $full_url;
}

// 发送错误响应
function send_error_response($status_code, $message) {
    $status_texts = [
        400 => "Bad Request",
        404 => "Not Found",
        500 => "Internal Server Error"
    ];
    
    $status_text = isset($status_texts[$status_code]) ? $status_texts[$status_code] : "Unknown Status";
    
    // 设置响应头
    header("HTTP/1.1 $status_code $status_text");
    header("Content-Type: text/plain");
    header("Content-Length: " . strlen($message));
    
    // 输出响应体
    echo $message;
    exit;
}

// 执行主处理函数
process_request();

// 确保脚本结束
exit;
?>