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

        // 新格式：命名参数模式（单链接同时携带直播与回放源）
        parse_str($raw_qs, $qs_all);

        // 必填参数校验：proxy、rtp、rtsp
        if (!isset($qs_all['proxy']) || !isset($qs_all['rtp']) || !isset($qs_all['rtsp'])) {
            send_error_response(400, "缺少必需参数：proxy、rtp、rtsp");
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

        // 解析业务参数
        $has_playseek = isset($qs_all['playseek']);
        $playseek_value = $has_playseek ? $qs_all['playseek'] : '';
        $has_token = isset($qs_all['r2h-token']);
        $token_value = $has_token ? $qs_all['r2h-token'] : '';

        $rtp_param = urldecode($qs_all['rtp']);
        $rtsp_param = urldecode($qs_all['rtsp']);

        // 若 rtp 参数带了查询（例如 ?fcc=...），解析出原始 query
        $rtp_query_raw = '';
        $rp = parse_url($rtp_param);
        if ($rp !== false && isset($rp['query'])) {
            $rtp_query_raw = $rp['query'];
        }

        // 根据 playseek 决定直播或回放
        if ($has_playseek) {
            $target_url = build_rtsp_url($proxy_base, $rtsp_param, $playseek_value, $has_token ? $token_value : null);
            log_message("命名参数模式-回放: proxy=$proxy_base, rtsp=$rtsp_param, playseek=$playseek_value" . ($has_token ? ", token=$token_value" : ""));
        } else {
            $target_url = build_rtp_url($proxy_base, $rtp_param, $rtp_query_raw, $has_token ? $token_value : null);
            log_message("命名参数模式-直播: proxy=$proxy_base, rtp=$rtp_param" . (!empty($rtp_query_raw) ? ", rtp_query=$rtp_query_raw" : "") . ($has_token ? ", token=$token_value" : ""));
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
function build_rtp_url($proxy_base, $rtp_path_raw, $rtp_query_raw, $token_value = null) {
    // rtP路径形如 'rtp://225.1.2.47:10276'，需要转换为 'rtp/225.1.2.47:10276'
    if ($rtp_path_raw === '' || strpos($rtp_path_raw, 'rtp://') === false) {
        log_message("警告: 在RTP部分中未找到rtp://前缀");
        return null;
    }
    $rtp_path = ltrim(str_replace('rtp://', 'rtp/', $rtp_path_raw), '/');
    $full_url = rtrim($proxy_base, '/') . '/' . $rtp_path;
    if (!empty($rtp_query_raw)) {
        $full_url .= '?' . $rtp_query_raw;
    }
    if (!empty($token_value) && strpos($full_url, 'r2h-token=') === false) {
        $connector = (strpos($full_url, '?') === false) ? '?' : '&';
        $full_url .= $connector . 'r2h-token=' . urlencode($token_value);
    }
    return $full_url;
}

// 构建RTSP回放URL
function build_rtsp_url($proxy_base, $rtsp_fragment_raw, $playseek_value, $token_value = null) {
    // RTSP片段形如 'rtsp://10.254.192.94/PLTV/.../smil'
    if ($rtsp_fragment_raw === '' || strpos($rtsp_fragment_raw, 'rtsp://') !== 0) {
        log_message("警告: 在RTSP部分中未找到rtsp://前缀");
        return null;
    }
    $rtsp_path = ltrim(str_replace('rtsp://', 'rtsp/', $rtsp_fragment_raw), '/');
    $full_url = rtrim($proxy_base, '/') . '/' . $rtsp_path;
    $connector = (strpos($full_url, '?') === false) ? '?' : '&';
    $full_url .= $connector . 'playseek=' . urlencode($playseek_value);
    if (!empty($token_value) && strpos($full_url, 'r2h-token=') === false) {
        $full_url .= '&r2h-token=' . urlencode($token_value);
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