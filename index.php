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
        
        // 拆分：改为优先按 playseek 参数分离，以支持主URL中使用 & 作为 RTP/RTSP 分隔符
        $primary_part = $raw_qs;
        $extra_qs = '';
        $playseek_key_pos = strpos($raw_qs, '&playseek=');
        if ($playseek_key_pos !== false) {
            $primary_part = substr($raw_qs, 0, $playseek_key_pos);
            $extra_qs = substr($raw_qs, $playseek_key_pos + 1); // 保留 "playseek=..." 作为额外参数
        }
        
        // 解析额外参数（例如 playseek）
        $extra_params = [];
        if ($extra_qs !== '') {
            parse_str($extra_qs, $extra_params);
        }
        $has_playseek = isset($extra_params['playseek']);
        $playseek_value = $has_playseek ? $extra_params['playseek'] : '';
        log_message("检测到playseek参数: " . ($has_playseek ? "是 ($playseek_value)" : "否"));
        
        // 主URL参数可能存在编码，需解码
        $url_param = urldecode($primary_part);
        log_message("解析到的URL参数: $url_param");
        
        // 解析主URL，提取代理基址、RTP路径与RTSP片段（支持 '#' 或 '&rtsp://' 分隔）
        $parts = parse_url($url_param);
        if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
            send_error_response(400, "URL格式错误：无法解析代理主机");
            return;
        }
        $proxy_base = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
        $rtp_path_raw = isset($parts['path']) ? $parts['path'] : '';
        $rtp_query_raw = isset($parts['query']) ? $parts['query'] : '';
        $rtsp_fragment_raw = '';

        // 优先使用 fragment（#）分隔；否则在 query 中查找 rtsp://（&rtsp:// 分隔）
        if (isset($parts['fragment']) && strpos($parts['fragment'], 'rtsp://') === 0) {
            $rtsp_fragment_raw = $parts['fragment'];
        } elseif (isset($parts['query'])) {
            $q = $parts['query'];
            $rtsp_pos = strpos($q, 'rtsp://');
            if ($rtsp_pos !== false) {
                $rtsp_fragment_raw = substr($q, $rtsp_pos);
                $rtp_query_raw = trim(substr($q, 0, $rtsp_pos), '&');
            }
        }
        
        // 构建目标URL
        if ($has_playseek) {
            $target_url = build_rtsp_url($proxy_base, $rtsp_fragment_raw, $playseek_value);
        } else {
            $target_url = build_rtp_url($proxy_base, $rtp_path_raw, $rtp_query_raw);
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
function build_rtp_url($proxy_base, $rtp_path_raw, $rtp_query_raw) {
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
    return $full_url;
}

// 构建RTSP回放URL
function build_rtsp_url($proxy_base, $rtsp_fragment_raw, $playseek_value) {
    // RTSP片段形如 'rtsp://10.254.192.94/PLTV/.../smil'
    if ($rtsp_fragment_raw === '' || strpos($rtsp_fragment_raw, 'rtsp://') !== 0) {
        log_message("警告: 在RTSP部分中未找到rtsp://前缀");
        return null;
    }
    $rtsp_path = ltrim(str_replace('rtsp://', 'rtsp/', $rtsp_fragment_raw), '/');
    $full_url = rtrim($proxy_base, '/') . '/' . $rtsp_path;
    // 根据是否已有查询参数选择连接符
    $connector = (strpos($full_url, '?') === false) ? '?' : '&';
    $full_url .= $connector . 'playseek=' . urlencode($playseek_value);
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