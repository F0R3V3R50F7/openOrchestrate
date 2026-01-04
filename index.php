<?php
/* openOrchestrate - Llama.cpp Frontend   *
 * MPL-2.0 https://mozilla.org/MPL/2.0/   *
 * @version 0.9-R8 (Pre-Release)          *
 * © TechnologystLabs - 2026              */

/* ===== CORE UTILITIES ===== */
define('DEFAULT_CONFIG', [
    // Expert slots
    'expert_1_model' => '', 'expert_1_name' => 'Text', 'expert_1_enabled' => true, 'expert_1_system_prompt' => 'You are an expert in writing, editing, summarising, and analysing text with a focus on clarity and precision.',
    'expert_2_model' => '', 'expert_2_name' => 'Code', 'expert_2_enabled' => false, 'expert_2_system_prompt' => 'You are a senior software engineer specialising in correct, efficient, and maintainable code.',
    'expert_3_model' => '', 'expert_3_name' => 'Medical', 'expert_3_enabled' => false, 'expert_3_system_prompt' => 'You are a medical expert explaining human biology, diseases, diagnostics, and treatments using established knowledge.',
    'expert_4_model' => '', 'expert_4_name' => 'Electrical', 'expert_4_enabled' => false, 'expert_4_system_prompt' => 'You are an expert electrician specialising in residential, commercial, and industrial electrical systems.',
    'expert_5_model' => '', 'expert_5_name' => 'Vehicle', 'expert_5_enabled' => false, 'expert_5_system_prompt' => 'You are an expert vehicle mechanic specialising in automotive systems and fault diagnosis.',
    'expert_6_model' => '', 'expert_6_name' => 'Law', 'expert_6_enabled' => false, 'expert_6_system_prompt' => 'You are a legal expert specialising in clear explanation of laws, legal concepts, and procedures.',
    'expert_7_model' => '', 'expert_7_name' => 'Expert 7', 'expert_7_enabled' => false, 'expert_7_system_prompt' => 'You are a helpful assistant. Provide detailed, accurate responses.',
    'expert_8_model' => '', 'expert_8_name' => 'Expert 8', 'expert_8_enabled' => false, 'expert_8_system_prompt' => 'You are a helpful assistant. Provide detailed, accurate responses.',
    'expert_9_model' => '', 'expert_9_name' => 'Expert 9', 'expert_9_enabled' => false, 'expert_9_system_prompt' => 'You are a helpful assistant. Provide detailed, accurate responses.',
    'expert_10_model' => '', 'expert_10_name' => 'Expert 10', 'expert_10_enabled' => false, 'expert_10_system_prompt' => 'You are a helpful assistant. Provide detailed, accurate responses.',
    // Auxiliary model
    'aux_model' => '',
    'aux_cpu_only' => true, 'aux_context_length' => 4096, 'aux_port' => 8081, 'expert_port' => 8080,
    'velocity_enabled' => true, 'velocity_threshold' => 45, 'velocity_char_threshold' => 1500,
    'velocity_index_prompt' => 'Create a brief, descriptive title (max 10 words) that captures the key topic or intent of this message. Return ONLY the title, nothing else.',
    'velocity_recall_prompt' => 'Given the user\'s new message, determine which archived conversation topic (if any) is most relevant and should be recalled to provide better context. If one topic is clearly relevant, respond with ONLY the number in brackets (e.g., 0 or 3). If no topic is relevant, respond with: NULL',
    'enable_pruning' => true, 'prune_threshold' => 1500,
    'prune_prompt' => 'Condense this message to only the essential information in 2-3 sentences:'
]);

// ===== LOGGING HELPER =====
function write_debug_log($type, $message, $data = null) {
    global $governorDir;
    
    // Route PRUNE_* logs to pruning.log, everything else to debug_switching.log
    if (strpos($type, 'PRUNE_') === 0) {
        $logFile = "$governorDir/pruning.log";
    } else {
        $logFile = "$governorDir/debug_switching.log";
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$type] $message";
    if ($data !== null) {
        $logEntry .= "\n" . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    $logEntry .= "\n" . str_repeat('-', 80) . "\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

function mm_to_utf8($v) {
    if (is_array($v)) return array_map('mm_to_utf8', $v);
    if (!is_string($v)) return $v;
    if (preg_match('//u', $v)) return $v;
    
    $encodings = ['Windows-1252', 'ISO-8859-1', 'UTF-8'];
    foreach ($encodings as $enc) {
        $converted = @iconv($enc, 'UTF-8//IGNORE', $v);
        if ($converted !== false && $converted !== '') return $converted;
    }
    return '';
}

function mm_safe_json_encode($data): string {
    $json = json_encode(mm_to_utf8($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return $json !== false ? $json : json_encode(['success' => false, 'error' => 'JSON encode failed'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function mm_json_response($payload, int $status = 200): void {
    http_response_code($status);
    !headers_sent() && header('Content-Type: application/json; charset=utf-8');
    ob_get_length() && @ob_clean();
    echo mm_safe_json_encode($payload);
    exit;
}

function mm_ps_quote(string $s): string {
    return "'" . str_replace("'", "''", $s) . "'";
}

function mm_start_process_windows(string $exe, array $args, string $logFile): int {
    $exe = str_replace('/', '\\', realpath($exe) ?: $exe);
    $logFile = str_replace('/', '\\', realpath($logFile) ?: $logFile);
    
    $cmdArgs = [];
    foreach ($args as $k => $v) {
        if (is_int($k)) {
            $cmdArgs[] = mm_ps_quote((string)$v);
        } elseif ($v === true) {
            $cmdArgs[] = mm_ps_quote($k);
        } elseif ($v !== false) {
            $cmdArgs[] = mm_ps_quote($k) . ' ' . mm_ps_quote((string)$v);
        }
    }
    
    $cmd = "powershell -NoProfile -ExecutionPolicy Bypass -Command " .
           mm_ps_quote("\$p = Start-Process -FilePath " . mm_ps_quote($exe) .
           " -ArgumentList '" . implode(' ', $cmdArgs) . "'" .
           " -RedirectStandardOutput " . mm_ps_quote($logFile) .
           " -RedirectStandardError " . mm_ps_quote($logFile) .
           " -PassThru -WindowStyle Hidden; echo \$p.Id");

    exec($cmd, $out, $rc);
    $pid = (int)trim(end($out) ?: '0');
    return $rc === 0 && $pid > 0 ? $pid : 0;
}

function error_response($message, $extra = []) {
    return ['success' => false, 'error' => $message] + $extra;
}

/* ===== CONFIGURATION LOADERS ===== */
function load_config() {
    global $governorDir;
    $configFile = "$governorDir/config.json";
    $config = DEFAULT_CONFIG;
    
    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true) ?: [];
        $config = array_merge($config, $loaded);
    } else {
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
    
    return $config;
}

function load_governor_config() { return load_config(); }

/* ===== MODEL ARCHITECTURE SNIFFING ===== */
function sniff_model_architecture($logFile) {
    if (!file_exists($logFile)) {
        return null;
    }
    
    $logContents = file_get_contents($logFile);
    if (empty($logContents)) {
        return null;
    }
    
    $arch = [
        'n_layer' => null,
        'n_embd' => null,
        'n_head' => null,
        'n_head_kv' => null,
        'n_ctx_train' => null,
        'n_swa' => null,
        'architecture' => null
    ];
    
    // Detect architecture type (gemma2, gemma3, llama, etc.)
    if (preg_match('/general\.architecture\s+str\s+=\s+(\w+)/', $logContents, $matches)) {
        $arch['architecture'] = $matches[1];
    }
    
    // Get the prefix based on architecture
    $prefix = $arch['architecture'] ?? 'llama';
    
    // Extract layer count (block_count)
    $patterns = [
        "/{$prefix}\.block_count\s+u32\s+=\s+(\d+)/",
        "/n_layer\s+=\s+(\d+)/"
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $logContents, $matches)) {
            $arch['n_layer'] = (int)$matches[1];
            break;
        }
    }
    
    // Extract embedding dimension
    $patterns = [
        "/{$prefix}\.embedding_length\s+u32\s+=\s+(\d+)/",
        "/n_embd\s+=\s+(\d+)/"
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $logContents, $matches)) {
            $arch['n_embd'] = (int)$matches[1];
            break;
        }
    }
    
    // Extract head counts for GQA calculation
    $patterns = [
        "/{$prefix}\.attention\.head_count\s+u32\s+=\s+(\d+)/",
        "/n_head\s+=\s+(\d+)/"
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $logContents, $matches)) {
            $arch['n_head'] = (int)$matches[1];
            break;
        }
    }
    
    $patterns = [
        "/{$prefix}\.attention\.head_count_kv\s+u32\s+=\s+(\d+)/",
        "/n_head_kv\s+=\s+(\d+)/"
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $logContents, $matches)) {
            $arch['n_head_kv'] = (int)$matches[1];
            break;
        }
    }
    
    // Extract training context length
    $patterns = [
        "/{$prefix}\.context_length\s+u32\s+=\s+(\d+)/",
        "/n_ctx_train\s+=\s+(\d+)/"
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $logContents, $matches)) {
            $arch['n_ctx_train'] = (int)$matches[1];
            break;
        }
    }
    
    // Extract sliding window size (if present)
    $patterns = [
        "/{$prefix}\.attention\.sliding_window\s+u32\s+=\s+(\d+)/",
        "/n_swa\s+=\s+(\d+)/"
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $logContents, $matches)) {
            $arch['n_swa'] = (int)$matches[1];
            break;
        }
    }
    
    // Calculate GQA ratio
    if ($arch['n_head'] && $arch['n_head_kv']) {
        $arch['gqa_ratio'] = $arch['n_head'] / $arch['n_head_kv'];
    }
    
    // Only return if we got the critical values
    if ($arch['n_layer'] && $arch['n_embd'] && $arch['n_head'] && $arch['n_head_kv']) {
        return $arch;
    }
    
    return null;
}

function calculate_precise_context($arch, $vramTotalMB, $modelSizeMB) {
    if (!$arch || !$arch['n_layer'] || !$arch['n_embd']) {
        return null;
    }
    
    $vramUsableMB = $vramTotalMB * 0.85; // 15% headroom
    $vramAfterModelMB = $vramUsableMB - $modelSizeMB;
    
    if ($vramAfterModelMB < 100) {
        return 512; // Minimum
    }
    
    // Precise KV cache calculation using real architecture
    $n_parallel = 4; // llama-server default
    $n_layer = $arch['n_layer'];
    $n_embd = $arch['n_embd'];
    $gqa_ratio = $arch['gqa_ratio'] ?? 1.0;
    
    // KV cache: 2 (K+V) * layers * embedding_dim * 2 bytes (FP16)
    // Divided by GQA ratio for memory reduction
    $bytesPerTokenBase = 2 * $n_layer * $n_embd * 2;
    $bytesPerToken = $bytesPerTokenBase / $gqa_ratio;
    $bytesPerContextToken = $bytesPerToken * $n_parallel;
    
    $vramForKvCacheMB = $vramAfterModelMB * 0.9; // 10% overhead reserve
    $vramForKvCacheBytes = $vramForKvCacheMB * 1024 * 1024;
    
    $maxContext = floor($vramForKvCacheBytes / $bytesPerContextToken);
    
    // Sliding window models use DUAL KV caches (non-SWA + SWA)
    // This doubles memory usage - reduce by 50%
    if (isset($arch['n_swa']) && $arch['n_swa'] > 0) {
        $maxContext = floor($maxContext * 0.5);
    }
    
    // Respect model's training context limit
    if ($arch['n_ctx_train']) {
        $maxContext = min($maxContext, $arch['n_ctx_train']);
    }
    
    // Clamp to reasonable bounds
    $maxContext = max(512, min(131072, $maxContext));
    
    // Align to 256
    $maxContext = floor($maxContext / 256) * 256;
    
    return $maxContext;
}

/* ===== CONTEXT CALIBRATION SYSTEM ===== */
function load_context_cache() {
    global $governorDir;
    $cacheFile = "$governorDir/context_cache.json";
    
    if (!file_exists($cacheFile)) {
        return [];
    }
    
    $data = json_decode(file_get_contents($cacheFile), true);
    return $data ?: [];
}

function save_context_cache($cache) {
    global $governorDir;
    $cacheFile = "$governorDir/context_cache.json";
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
}

function calibrate_model_context($model, $vramInfo) {
    global $modelsDir, $governorDir;
    
    @set_time_limit(300);
    
    $modelPath = realpath("$modelsDir/$model");
    if (!$modelPath || !file_exists($modelPath)) {
        return ['success' => false, 'error' => 'Model not found'];
    }
    
    $modelSizeMB = filesize($modelPath) / (1024 * 1024);
    $vramTotalMB = $vramInfo['total'] ?? 0;
    
    if (!$vramTotalMB || $vramTotalMB <= 0) {
        return ['success' => false, 'error' => 'VRAM info not available'];
    }
    
    $port = 8080;
    $conservativeContext = 2048;
    
    // Step 1: Start with conservative context to sniff architecture
    stop_llama_server('expert');
    sleep(2);
    
    $result = start_llama_server('expert', $model, $port, false, $conservativeContext);
    
    if (!$result['success']) {
        return ['success' => false, 'error' => 'Failed to start server for calibration'];
    }
    
    sleep(5);
    
    // Step 2: Sniff architecture from log
    $logFile = "$governorDir/expert_server.log";
    $arch = sniff_model_architecture($logFile);
    
    if (!$arch) {
        stop_llama_server('expert');
        return [
            'success' => true,
            'max_context' => $conservativeContext,
            'method' => 'conservative_fallback'
        ];
    }
    
    // Step 3: Calculate theoretical maximum
    $theoreticalMax = calculate_precise_context($arch, $vramTotalMB, $modelSizeMB);
    
    if (!$theoreticalMax) {
        stop_llama_server('expert');
        return [
            'success' => true,
            'max_context' => $conservativeContext,
            'method' => 'architecture_incomplete',
            'architecture' => $arch
        ];
    }
    
    // Step 4: Binary search for actual maximum
    $minContext = $conservativeContext;
    $maxSearchContext = $arch['n_ctx_train'] ?? 131072;
    if ($theoreticalMax && $theoreticalMax > 0) {
        $maxSearchContext = min($theoreticalMax * 10, $maxSearchContext);
    }
    $maxContext = $maxSearchContext;
    $attempts = 0;
    $maxAttempts = 5;
    $lastWorking = $conservativeContext;
    
    while ($attempts < $maxAttempts && $maxContext - $minContext > 512) {
        $attempts++;
        
        if ($attempts == 1) {
            $testContext = min($conservativeContext * 10, $maxContext);
        } else {
            $testContext = floor(($minContext + $maxContext) / 2);
            $testContext = floor($testContext / 256) * 256;
        }
        
        stop_llama_server('expert');
        sleep(2);
        
        $result = start_llama_server('expert', $model, $port, false, $testContext);
        
        if (!$result['success']) {
            $maxContext = $testContext - 256;
            continue;
        }
        
        // Wait and check if healthy
        $healthy = false;
        for ($i = 0; $i < 15; $i++) {
            sleep(1);
            $health = check_server_health($port);
            if ($health['healthy']) {
                $healthy = true;
                break;
            }
        }
        
        if ($healthy) {
            $lastWorking = $testContext;
            $minContext = $testContext + 256;
        } else {
            $maxContext = $testContext - 256;
        }
    }
    
    stop_llama_server('expert');
    
    return [
        'success' => true,
        'max_context' => $lastWorking,
        'architecture' => $arch,
        'theoretical_max' => $theoreticalMax,
        'attempts' => $attempts,
        'method' => 'binary_search'
    ];
}

/* ===== AUX MODEL CLIENT ===== */
function aux_chat_request($prompt, $options = []) {
    @set_time_limit(300);
    
    $maxTokens = $options['max_tokens'] ?? 512;
    $temperature = $options['temperature'] ?? 0.3;
    $maxWaitTime = $options['max_wait'] ?? 180;
    $pollInterval = $options['poll_interval'] ?? 2;
    
    $govConfig = load_governor_config();
    $auxPort = $govConfig['aux_port'] ?? 8081;
    
    // LOG: Request initiated
    write_debug_log('PRUNE_REQUEST', 'Aux chat request initiated', [
        'prompt_length' => strlen($prompt),
        'prompt_preview' => substr($prompt, 0, 200) . (strlen($prompt) > 200 ? '...' : ''),
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
        'aux_port' => $auxPort
    ]);
    
    $postBody = [
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
        'stream' => false
    ];
    
    $jsonBody = json_encode($postBody);
    if ($jsonBody === false) {
        write_debug_log('PRUNE_ERROR', 'JSON encode failed', [
            'error' => json_last_error_msg()
        ]);
        return ['success' => false, 'error' => 'JSON encode failed: ' . json_last_error_msg()];
    }
    
    // LOG: JSON payload prepared
    write_debug_log('PRUNE_REQUEST', 'JSON payload prepared', [
        'json_length' => strlen($jsonBody),
        'payload_preview' => substr($jsonBody, 0, 300) . (strlen($jsonBody) > 300 ? '...' : '')
    ]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "http://127.0.0.1:$auxPort/v1/chat/completions",
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $maxWaitTime,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch);
    
    $startTime = time();
    $running = null;
    $lastHealthCheck = 0;
    
    do {
        $status = curl_multi_exec($mh, $running);
        
        $now = time();
        if ($now - $lastHealthCheck >= $pollInterval) {
            $lastHealthCheck = $now;
            $elapsed = $now - $startTime;
            
            $health = aux_check_health($auxPort);
            
            if ($health['status'] === 'error') {
                curl_multi_remove_handle($mh, $ch);
                curl_multi_close($mh);
                curl_close($ch);
                return ['success' => false, 'error' => 'Aux server stopped responding'];
            }
            
            if ($elapsed > $maxWaitTime) {
                curl_multi_remove_handle($mh, $ch);
                curl_multi_close($mh);
                curl_close($ch);
                return ['success' => false, 'error' => "Request timed out after {$maxWaitTime}s"];
            }
        }
        
        if ($running) {
            curl_multi_select($mh, 1);
        }
    } while ($running && $status == CURLM_OK);
    
    $result = curl_multi_getcontent($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_multi_remove_handle($mh, $ch);
    curl_multi_close($mh);
    curl_close($ch);
    
    // LOG: Response received
    write_debug_log('PRUNE_RESPONSE', 'Curl request completed', [
        'http_code' => $httpCode,
        'curl_error' => $error ?: 'none',
        'response_length' => $result !== false ? strlen($result) : 0,
        'response_preview' => $result !== false ? substr($result, 0, 500) . (strlen($result) > 500 ? '...' : '') : 'false'
    ]);
    
    if ($httpCode === 200 && $result !== false) {
        $response = json_decode($result, true);
        $content = trim($response['choices'][0]['message']['content'] ?? '');
        
        // LOG: Successful response parsed
        write_debug_log('PRUNE_SUCCESS', 'Response parsed successfully', [
            'content_length' => strlen($content),
            'content_preview' => substr($content, 0, 200) . (strlen($content) > 200 ? '...' : ''),
            'full_response_structure' => array_keys($response)
        ]);
        
        if (!empty($content)) {
            return ['success' => true, 'output' => $content];
        }
        
        write_debug_log('PRUNE_ERROR', 'Empty response from model', [
            'response_structure' => $response
        ]);
        return ['success' => false, 'error' => 'Empty response from model'];
    }
    
    write_debug_log('PRUNE_ERROR', 'Request failed', [
        'http_code' => $httpCode,
        'error' => $error ?: "HTTP $httpCode",
        'response' => $result
    ]);
    
    return ['success' => false, 'error' => $error ?: "HTTP $httpCode"];
}

function aux_check_health($port) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "http://127.0.0.1:$port/health",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return ['status' => 'idle', 'healthy' => true];
    } elseif ($httpCode === 503) {
        return ['status' => 'processing', 'healthy' => true];
    } else {
        return ['status' => 'error', 'healthy' => false];
    }
}

/* ===== PORT & HEALTH CHECKS ===== */
function is_port_open($port) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

function check_server_health($port) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "http://127.0.0.1:$port/health",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2,
        CURLOPT_CONNECTTIMEOUT => 1,
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['running' => $httpCode === 200 || $httpCode === 503, 'healthy' => $httpCode === 200];
}

/* ===== SERVER LIFECYCLE HELPERS ===== */
function start_llama_server($type, $model, $port, $cpuOnly, $contextSize = 0) {
    global $modelsDir, $governorDir;
    
    // FLUSH OLD LOG FILE BEFORE STARTING - critical for accurate metadata sniffing
    $logFile = __DIR__ . "/governor/{$type}_server.log";
    if (file_exists($logFile)) {
        @unlink($logFile);
        write_debug_log('INFO', "Flushed old server log", ['path' => $logFile]);
    }
    
    write_debug_log('START_SERVER', "Starting $type server", [
        'model' => $model,
        'port' => $port,
        'cpu_only' => $cpuOnly,
        'context_size' => $contextSize
    ]);
    
    $modelPath = realpath("$modelsDir/$model");
    if (!$modelPath) {
        write_debug_log('ERROR', "Model not found: $model");
        return ['success' => false, 'error' => "Model not found: $model"];
    }
    
    write_debug_log('INFO', "Model path resolved", ['path' => $modelPath]);
    
    $llamaServer = __DIR__ . '/llama-server.exe';
    if (!file_exists($llamaServer)) {
        write_debug_log('ERROR', "llama-server.exe not found at " . $llamaServer);
        return ['success' => false, 'error' => 'llama-server.exe not found'];
    }
    
    write_debug_log('INFO', "llama-server.exe found", ['path' => $llamaServer]);
    
    $ctx = $contextSize > 0 ? $contextSize : ($type === 'aux' ? 2048 : 4096);
    $ngl = $cpuOnly ? 0 : 99;
    $threads = 6;
    
    $args = [
        "-m" => "\"$modelPath\"",
        "--port" => $port,
        "--host" => "127.0.0.1",
        "-ngl" => $ngl,
        "-c" => $ctx,
        "-t" => $threads
    ];
    
    // For CPU-only servers, prevent GPU KV cache to avoid conflicts
    if ($cpuOnly) {
        $args['--split-mode'] = "none";
    }
    
    if ($type === 'aux' && !$cpuOnly) {
        $args['--split'] = "30:70";
    }
    
    write_debug_log('INFO', "Server arguments prepared", $args);
    
    $logFile = __DIR__ . "/governor/{$type}_server.log";
    
    $command = "\"$llamaServer\"";
    foreach ($args as $k => $v) {
        $command .= " $k $v";
    }
    
    write_debug_log('INFO', "Command to execute", ['command' => $command]);
    
    $batchFile = __DIR__ . "/governor/start_{$type}.bat";
    $batchFileWin = str_replace('/', '\\', $batchFile);
    $pidFile = __DIR__ . "/governor/{$type}_watchdog.pid";
    $pidFileWin = str_replace('/', '\\', $pidFile);
    
    $logFileWin = str_replace('/', '\\', $logFile);
    $watchdogBat = "@echo off\r\nsetlocal enabledelayedexpansion\r\n";
    $watchdogBat .= "start \"llama-{$type}\" /B $command >> \"$logFileWin\" 2>&1\r\n";
    $watchdogBat .= "timeout /t 3 /nobreak >nul\r\n";
    $watchdogBat .= "set LLAMA_PID=\r\n";
    $watchdogBat .= "for /f \"tokens=5\" %%a in ('netstat -ano ^| find \":$port \" ^| find \"LISTENING\"') do (\r\n";
    $watchdogBat .= "    echo %%a > \"$pidFileWin\"\r\n";
    $watchdogBat .= "    set LLAMA_PID=%%a\r\n";
    $watchdogBat .= "    goto :found_pid\r\n";
    $watchdogBat .= ")\r\n";
    $watchdogBat .= ":found_pid\r\n";
    $watchdogBat .= "if not defined LLAMA_PID (\r\n";
    $watchdogBat .= "    echo Failed to capture PID\r\n";
    $watchdogBat .= "    exit /b 1\r\n";
    $watchdogBat .= ")\r\n";
    $watchdogBat .= ":watchloop\r\n";
    $watchdogBat .= "tasklist /fi \"imagename eq openOrchestrate.exe\" 2>nul | find /i \"openOrchestrate.exe\" >nul\r\n";
    $watchdogBat .= "if errorlevel 1 (\r\n";
    $watchdogBat .= "    taskkill /f /pid !LLAMA_PID! >nul 2>&1\r\n";
    $watchdogBat .= "    exit /b\r\n";
    $watchdogBat .= ")\r\n";
    $watchdogBat .= "timeout /t 2 /nobreak >nul\r\n";
    $watchdogBat .= "goto watchloop\r\n";
    file_put_contents($batchFile, $watchdogBat);
    
    $vbsFile = __DIR__ . "/governor/launch_{$type}.vbs";
    file_put_contents($vbsFile, 'CreateObject("WScript.Shell").Run "' . $batchFileWin . '", 0, False');
    
    write_debug_log('INFO', "VBS launcher created, executing", ['vbs_file' => $vbsFile]);
    
    exec("wscript \"$vbsFile\"");
    
    write_debug_log('INFO', "Process launched via wscript, waiting 2 seconds");
    
    sleep(2);
    
    // Check multiple possible log file locations
    $logPaths = [
        $logFile,
        __DIR__ . "/governor/{$type}_server.log",
        __DIR__ . "/{$type}_server.log"
    ];
    
    $logFound = false;
    foreach ($logPaths as $possibleLog) {
        if (file_exists($possibleLog)) {
            $logContents = file_get_contents($possibleLog);
            write_debug_log('SERVER_LOG', "Initial server output from $possibleLog", ['log' => substr($logContents, 0, 2000)]);
            $logFound = true;
            break;
        }
    }
    
    if (!$logFound) {
        write_debug_log('WARNING', "Server log file not found in any location", ['checked_paths' => $logPaths]);
    }
    
    $pid = 0;
    
    $output = [];
    exec('tasklist /FI "IMAGENAME eq llama-server.exe" /FO CSV 2>&1', $output);
    
    write_debug_log('INFO', "Process search output", $output);
    
    foreach ($output as $line) {
        if (preg_match('/"llama-server\.exe","(\d+)"/', $line, $matches)) {
            $foundPid = (int)$matches[1];
            $cmdOutput = [];
            exec("wmic process where \"ProcessId=$foundPid\" get CommandLine 2>&1", $cmdOutput);
            $cmdLine = implode(' ', $cmdOutput);
            
            write_debug_log('INFO', "Found llama-server process", [
                'pid' => $foundPid,
                'command_line' => $cmdLine
            ]);
            
            if (strpos($cmdLine, "--port $port") !== false) {
                $pid = $foundPid;
                file_put_contents("$governorDir/{$type}.pid", $pid);
                write_debug_log('SUCCESS', "Matched server on correct port", ['pid' => $pid, 'port' => $port]);
                break;
            } else {
                write_debug_log('INFO', "Process on different port, continuing search");
            }
        }
    }
    
    // Wait a bit more and check server log again
    sleep(3);
    
    // Try to read the server log from the standard location
    $serverLogPath = __DIR__ . "/governor/{$type}_server.log";
    if (file_exists($serverLogPath)) {
        $logContents = file_get_contents($serverLogPath);
        write_debug_log('SERVER_LOG', "Full server output after 5 seconds", ['log' => $logContents]);
    } else {
        write_debug_log('WARNING', "Server log still not found", ['path' => $serverLogPath]);
        // Try to check what files ARE in the governor directory
        $govFiles = glob(__DIR__ . "/governor/*.log");
        write_debug_log('INFO', "Available log files in governor directory", ['files' => $govFiles]);
    }
    
    // Check if port is actually open
    $portOpen = is_port_open($port);
    write_debug_log('PORT_CHECK', "Port status", ['port' => $port, 'open' => $portOpen]);
    
    // Check server health
    $health = check_server_health($port);
    write_debug_log('HEALTH_CHECK', "Server health", $health);
    
    $result = [
        'success' => true,
        'port' => $port,
        'type' => $type,
        'model' => $model,
        'pid' => $pid
    ];
    
    write_debug_log('START_COMPLETE', "Server start sequence completed", $result);
    
    return $result;
}

function stop_llama_server($type) {
    global $governorDir;
    
    write_debug_log('STOP_SERVER', "Stopping server", ['type' => $type]);
    
    $stopped = [];
    $allSuccess = true;
    
    $auxPidFile = "$governorDir/aux.pid";
    $expertPidFile = "$governorDir/expert.pid";
    
    $auxPid = file_exists($auxPidFile) ? (int)file_get_contents($auxPidFile) : 0;
    $expertPid = file_exists($expertPidFile) ? (int)file_get_contents($expertPidFile) : 0;
    
    $stopAux = ($type === 'all' || $type === 'aux');
    $stopExpert = ($type === 'all' || $type === 'expert');
    
    if ($stopAux && $auxPid > 0) {
        $output = [];
        $returnCode = 0;
        exec("taskkill /F /PID $auxPid 2>&1", $output, $returnCode);
        if ($returnCode === 0) {
            @unlink($auxPidFile);
            $stopped[] = ['type' => 'aux', 'pid' => $auxPid];
        } else {
            $config = load_governor_config();
            $auxPort = $config['aux_port'] ?? 8081;
            exec("for /f \"tokens=5\" %a in ('netstat -ano ^| find \":$auxPort \"') do taskkill /F /PID %a 2>&1");
            $allSuccess = false;
        }
    }
    
    if ($stopExpert && $expertPid > 0) {
        $output = [];
        $returnCode = 0;
        exec("taskkill /F /PID $expertPid 2>&1", $output, $returnCode);
        write_debug_log('INFO', "Expert server kill attempt", [
            'pid' => $expertPid,
            'return_code' => $returnCode,
            'output' => $output
        ]);
        if ($returnCode === 0) {
            @unlink($expertPidFile);
            $stopped[] = ['type' => 'expert', 'pid' => $expertPid];
        } else {
            $config = load_governor_config();
            $expertPort = $config['expert_port'] ?? 8080;
            exec("for /f \"tokens=5\" %a in ('netstat -ano ^| find \":$expertPort \"') do taskkill /F /PID %a 2>&1");
            $allSuccess = false;
        }
    }
    
    if ($type === 'all') {
        sleep(1);
        exec('taskkill /F /IM llama-server.exe 2>&1');
    }
    
    return [
        'success' => $allSuccess,
        'stopped' => $stopped,
        'message' => empty($stopped) ? 'No servers were running' : 'Servers stopped'
    ];
}

function detect_running_servers() {
    global $governorDir;
    $status = [];
    
    foreach (['aux', 'expert'] as $serverType) {
        $config = load_governor_config();
        $port = $serverType === 'aux' ? ($config['aux_port'] ?? 8081) : ($config['expert_port'] ?? 8080);
        $health = check_server_health($port);
        
        $status[] = [
            'type' => $serverType,
            'running' => $health['running'],
            'healthy' => $health['healthy'],
            'port' => $port
        ];
    }
    
    return $status;
}

// ===== RUNTIME INITIALIZATION =====
$chatsDir = 'chats';
$governorDir = 'governor';
$modelsDir = 'models';

foreach ([$chatsDir, $governorDir] as $dir) {
    !is_dir($dir) && mkdir($dir, 0755, true);
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
!headers_sent() && header('X-Content-Type-Options: nosniff');
function_exists('ob_start') && @ob_start();

// ===== MAIN REQUEST HANDLER =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = $raw && trim($raw) ? (json_decode($raw, true) ?: $_POST) : $_POST;
    
    if (empty($data['action'])) {
        mm_json_response(error_response('No action specified'));
    }
    
    $action = $data['action'];
    $response = ['success' => false, 'error' => 'Invalid action'];
    
    switch ($action) {
        /* ===== CHAT ACTIONS ===== */
        case 'get_chats':
                if (!is_dir($chatsDir)) mkdir($chatsDir, 0755, true);
                
                $chats = [];
                foreach (glob("$chatsDir/*.json") as $file) {
                    $chatData = json_decode(file_get_contents($file), true);
                    if ($chatData) {
                        $chats[] = [
                            'id' => pathinfo($file, PATHINFO_FILENAME),
                            'title' => $chatData['title'] ?? 'Untitled Chat',
                            'timestamp' => $chatData['timestamp'] ?? date('c'),
                            'lastMessage' => $chatData['lastMessage'] ?? '',
                            'messageCount' => count($chatData['messages'] ?? [])
                        ];
                    }
                }
                usort($chats, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));
                $response = ['success' => true, 'chats' => $chats];
                break;
                
            case 'save_chat':
                $chatId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['chatId'] ?? '') ?: uniqid();
                $messages = $data['messages'] ?? [];
                
                if (empty($messages)) {
                    $response = error_response('No messages to save');
                    break;
                }
                
                if (!is_dir($chatsDir)) mkdir($chatsDir, 0755, true);
                
                $chatData = [
                    'id' => $chatId,
                    'title' => $data['title'] ?? 'Untitled Chat',
                    'messages' => $messages,
                    'timestamp' => date('c'),
                    'lastMessage' => end($messages)['content'] ?? '',
                    'tokenData' => $data['tokenData'] ?? []
                ];
                
                $success = file_put_contents("$chatsDir/$chatId.json", json_encode($chatData, JSON_PRETTY_PRINT)) !== false;
                $response = $success
                    ? ['success' => true, 'chatId' => $chatId]
                    : error_response('Failed to save chat');
                break;
                
            case 'load_chat':
                $chatId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['chatId'] ?? '');
                
                if (!$chatId) {
                    $response = error_response('No chat ID specified');
                    break;
                }
                
                $chatFile = "$chatsDir/$chatId.json";
                if (!file_exists($chatFile)) {
                    $response = error_response('Chat not found');
                    break;
                }
                
                $chatData = json_decode(file_get_contents($chatFile), true);
                $response = $chatData
                    ? ['success' => true, 'chat' => $chatData]
                    : error_response('Invalid chat data');
                break;
                
            case 'delete_chat':
                $chatId = preg_replace('/[^a-zA-Z0-9_-]/', '', $data['chatId'] ?? '');
                
                if (!$chatId) {
                    $response = error_response('No chat ID specified');
                    break;
                }
                
                $chatFile = "$chatsDir/$chatId.json";
                $success = !file_exists($chatFile) || unlink($chatFile);
                $response = $success
                    ? ['success' => true, 'message' => 'Chat deleted']
                    : error_response('Failed to delete chat');
                break;
                
            /* ===== AUX INTELLIGENCE ACTIONS ===== */
            case 'prune_message':
                $message = $data['message'] ?? '';
                $prompt = $data['prompt'] ?? '';
                
                // LOG: Prune request received
                write_debug_log('PRUNE_ENDPOINT', 'Prune message request received', [
                    'message_length' => strlen($message),
                    'message_preview' => substr($message, 0, 200) . (strlen($message) > 200 ? '...' : ''),
                    'prompt_provided' => !empty($prompt),
                    'prompt_length' => strlen($prompt)
                ]);
                
                if (!is_string($message) || !trim($message)) {
                    write_debug_log('PRUNE_ERROR', 'No message provided', [
                        'message_type' => gettype($message),
                        'message_value' => $message
                    ]);
                    $response = error_response('No message provided');
                    break;
                }
                
                $message = trim($message);
                $prompt = trim($prompt) ?: DEFAULT_CONFIG['prune_prompt'];
                
                write_debug_log('PRUNE_ENDPOINT', 'Processing prune request', [
                    'trimmed_message_length' => strlen($message),
                    'using_prompt' => $prompt,
                    'prompt_is_default' => $prompt === DEFAULT_CONFIG['prune_prompt']
                ]);
                
                $govConfig = load_governor_config();
                $auxPort = $govConfig['aux_port'] ?? 8081;
                
                write_debug_log('PRUNE_ENDPOINT', 'Checking aux model availability', [
                    'aux_port' => $auxPort,
                    'checking_port' => true
                ]);
                
                if (!is_port_open($auxPort)) {
                    write_debug_log('PRUNE_ERROR', 'Auxiliary model not running', [
                        'aux_port' => $auxPort,
                        'port_open' => false
                    ]);
                    $response = error_response('Auxiliary model not running');
                    break;
                }
                
                write_debug_log('PRUNE_ENDPOINT', 'Sending to aux_chat_request', [
                    'aux_port' => $auxPort,
                    'combined_prompt_length' => strlen("$prompt\n\n$message")
                ]);
                
                $result = aux_chat_request("$prompt\n\n$message", ['temperature' => 0.3]);
                
                write_debug_log('PRUNE_ENDPOINT', 'Received response from aux_chat_request', [
                    'success' => $result['success'],
                    'has_output' => isset($result['output']),
                    'output_length' => isset($result['output']) ? strlen($result['output']) : 0,
                    'error' => $result['error'] ?? 'none'
                ]);
                
                if ($result['success']) {
                    write_debug_log('PRUNE_SUCCESS', 'Prune completed successfully', [
                        'original_length' => strlen($message),
                        'pruned_length' => strlen($result['output']),
                        'compression_ratio' => round((1 - strlen($result['output']) / strlen($message)) * 100, 2) . '%'
                    ]);
                    $response = ['success' => true, 'pruned' => $result['output']];
                } else {
                    write_debug_log('PRUNE_ERROR', 'Prune failed', [
                        'error' => $result['error'] ?? 'Unknown',
                        'full_result' => $result
                    ]);
                    $response = error_response('Prune failed: ' . ($result['error'] ?? 'Unknown'));
                }
                break;
                
            case 'route_query':
                $message = trim($data['message'] ?? '');
                if (!$message) {
                    $response = error_response('No message provided');
                    break;
                }
                
                $message = substr($message, 0, 500);
                $govConfig = load_governor_config();
                
                if (!is_port_open($govConfig['aux_port'] ?? 8081)) {
                    $response = error_response('Auxiliary model not running');
                    break;
                }
                
                // Get expert names from client request or build from config
                $expertNames = $data['expert_names'] ?? [];
                
                // If no expert names passed, build from config
                if (empty($expertNames)) {
                    for ($i = 1; $i <= 10; $i++) {
                        $enabled = $govConfig["expert_{$i}_enabled"] ?? ($i <= 5);
                        $model = $govConfig["expert_{$i}_model"] ?? '';
                        $name = $govConfig["expert_{$i}_name"] ?? "Expert $i";
                        
                        if ($enabled && !empty($model)) {
                            $expertNames[] = strtoupper($name);
                        }
                    }
                }
                
                // If only one expert or no experts, return immediately
                if (count($expertNames) <= 1) {
                    $response = [
                        'success' => true,
                        'route' => $expertNames[0] ?? 'EXPERT_1',
                        'single_expert' => true,
                        'message_preview' => substr($message, 0, 100)
                    ];
                    break;
                }
                
                // Build routing prompt with expert names
                $categoriesList = implode(', ', $expertNames);
                
                $routingPrompt = "Task: Classify the following message as one of these categories: $categoriesList.

Analyze the message content and determine which category best matches. Consider the topic, keywords, and intent of the message.

Message to classify:
---
$message
---

Respond with exactly one word - one of: $categoriesList:";
                
                $result = aux_chat_request($routingPrompt, ['max_tokens' => 20, 'temperature' => 0.1]);
                
                if ($result['success']) {
                    $rawResponse = trim($result['output']);
                    $upperResponse = strtoupper($rawResponse);
                    $classification = null;
                    
                    // Try to match response to one of the expert names
                    foreach ($expertNames as $expertName) {
                        if (strpos($upperResponse, $expertName) !== false) {
                            $classification = $expertName;
                            break;
                        }
                    }
                    
                    // If no match, try first word
                    if ($classification === null) {
                        $firstWord = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', explode(' ', trim($rawResponse))[0] ?? ''));
                        if (in_array($firstWord, $expertNames)) {
                            $classification = $firstWord;
                        }
                    }
                    
                    if ($classification !== null) {
                        $response = ['success' => true, 'route' => $classification];
                        break;
                    }
                }
                
                // Default to first expert
                $defaultRoute = $expertNames[0] ?? 'EXPERT_1';
                $response = ['success' => true, 'route' => $defaultRoute, 'fallback' => true];
                break;
                
            case 'velocity_create_title':
                $message = trim($data['message'] ?? '');
                if (!$message) {
                    $response = error_response('No message provided');
                    break;
                }
                
                $config = load_governor_config();
                if (!is_port_open($config['aux_port'] ?? 8081)) {
                    $response = error_response('Auxiliary model not running');
                    break;
                }
                
                $basePrompt = $config['velocity_index_prompt'] ?? DEFAULT_CONFIG['velocity_index_prompt'];
                $titlePrompt = "$basePrompt

Message:
---
$message
---

Title:";
                
                $result = aux_chat_request($titlePrompt, ['max_tokens' => 30, 'temperature' => 0.3]);
                
                if ($result['success']) {
                    $cleanTitle = trim($result['output'], " \t\n\r\0\x0B\"'");
                    if (!empty($cleanTitle)) {
                        $response = ['success' => true, 'title' => $cleanTitle];
                    } else {
                        $response = error_response('Empty title generated');
                    }
                } else {
                    $response = error_response('Failed to generate title: ' . ($result['error'] ?? 'Unknown'));
                }
                break;
                
            case 'velocity_find_relevant':
                $message = trim($data['message'] ?? '');
                $titles = $data['titles'] ?? [];
                
                if (!$message) {
                    $response = error_response('No message provided');
                    break;
                }
                
                if (empty($titles)) {
                    $response = ['success' => true, 'relevant_index' => null, 'reason' => 'No titles to search'];
                    break;
                }
                
                $config = load_governor_config();
                if (!is_port_open($config['aux_port'] ?? 8081)) {
                    $response = error_response('Auxiliary model not running');
                    break;
                }
                
                $titlesList = "";
                foreach ($titles as $item) {
                    $titlesList .= "[" . $item['index'] . "] " . $item['title'] . "\n";
                }
                
                $basePrompt = $config['velocity_recall_prompt'] ?? DEFAULT_CONFIG['velocity_recall_prompt'];
                $relevancePrompt = "$basePrompt

Archived topics:
$titlesList

User's new message:
---
$message
---

Your response (number or NULL):";
                
                $result = aux_chat_request($relevancePrompt, ['max_tokens' => 10, 'temperature' => 0.1]);
                
                if ($result['success']) {
                    $rawResponse = trim($result['output']);
                    $upperResponse = strtoupper($rawResponse);
                    
                    if (strpos($upperResponse, 'NULL') !== false || strpos($upperResponse, 'NONE') !== false) {
                        $relevantIndex = null;
                    } elseif (preg_match('/\d+/', $rawResponse, $matches)) {
                        $idx = (int)$matches[0];
                        $validIndices = array_column($titles, 'index');
                        $relevantIndex = in_array($idx, $validIndices) ? $idx : null;
                    } else {
                        $relevantIndex = null;
                    }
                    
                    $response = ['success' => true, 'relevant_index' => $relevantIndex];
                } else {
                    $response = error_response('Failed to check relevance: ' . ($result['error'] ?? 'Unknown'));
                }
                break;
                
            /* ===== GOVERNOR ACTIONS ===== */
            case 'scan_models':
                $models = [];
                if (is_dir($modelsDir)) {
                    foreach (glob("$modelsDir/*.gguf") as $file) {
                        $size = filesize($file);
                        $models[] = [
                            'filename' => basename($file),
                            'path' => $file,
                            'size' => $size,
                            'sizeFormatted' => round($size / (1024 * 1024 * 1024), 2) . ' GB'
                        ];
                    }
                }
                usort($models, fn($a, $b) => strcasecmp($a['filename'], $b['filename']));
                $response = ['success' => true, 'models' => $models];
                break;
                
            case 'detect_vram':
                $vram = null;
                $gpu = null;
                $method = null;
                
                $nvsmiPaths = [
                    'nvidia-smi',
                    'C:\\Windows\\System32\\nvidia-smi.exe',
                    'C:\\Program Files\\NVIDIA Corporation\\NVSMI\\nvidia-smi.exe'
                ];
                
                foreach ($nvsmiPaths as $nvsmi) {
                    $output = [];
                    $returnCode = -1;
                    @exec("\"$nvsmi\" --query-gpu=name,memory.total,memory.free,memory.used --format=csv,noheader,nounits 2>&1", $output, $returnCode);
                    
                    if ($returnCode === 0 && !empty($output[0])) {
                        $parts = array_map('trim', explode(',', $output[0]));
                        if (count($parts) >= 4) {
                            $gpu = $parts[0];
                            $vram = [
                                'total' => (int)$parts[1],
                                'free' => (int)$parts[2],
                                'used' => (int)$parts[3]
                            ];
                            $method = 'nvidia-smi';
                            break;
                        }
                    }
                }
                
                if ($vram === null) {
                    $output = [];
                    $returnCode = -1;
                    @exec('rocm-smi --showmeminfo vram --csv 2>&1', $output, $returnCode);
                    
                    if ($returnCode === 0 && count($output) > 1) {
                        foreach ($output as $line) {
                            if (preg_match('/(\d+),\s*(\d+)/', $line, $matches)) {
                                $vram = [
                                    'total' => (int)($matches[1] / (1024 * 1024)),
                                    'used' => (int)($matches[2] / (1024 * 1024)),
                                    'free' => (int)(($matches[1] - $matches[2]) / (1024 * 1024))
                                ];
                                $gpu = 'AMD GPU';
                                $method = 'rocm-smi';
                                break;
                            }
                        }
                    }
                }
                
                if ($vram === null) {
                    $output = [];
                    $returnCode = -1;
                    @exec('xpu-smi discovery 2>&1', $output, $returnCode);
                    
                    if ($returnCode === 0) {
                        $fullOutput = implode("\n", $output);
                        if (preg_match('/Memory Size:\s*(\d+(?:\.\d+)?)\s*(GB|MB)/i', $fullOutput, $matches)) {
                            $size = (float)$matches[1];
                            if (strtoupper($matches[2]) === 'GB') $size *= 1024;
                            $vram = [
                                'total' => (int)$size,
                                'free' => (int)$size,
                                'used' => 0
                            ];
                            $gpu = 'Intel Arc';
                            $method = 'xpu-smi';
                        }
                    }
                }
                
                if ($vram !== null) {
                    $response = [
                        'success' => true,
                        'gpu' => $gpu,
                        'vram' => $vram,
                        'method' => $method
                    ];
                } else {
                    $response = error_response('Could not detect GPU/VRAM. Ensure GPU drivers are installed.');
                }
                break;
                
            case 'load_governor_config':
                $config = load_governor_config();
                $response = ['success' => true, 'config' => $config];
                break;
                
            case 'save_governor_config':
                $configData = [
                    'aux_model' => trim($data['aux_model'] ?? ''),
                    'aux_cpu_only' => filter_var($data['aux_cpu_only'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'aux_context_length' => max(512, min(32768, (int)($data['aux_context_length'] ?? 2048))),
                    'aux_port' => max(1, min(65535, (int)($data['aux_port'] ?? 8081))),
                    'expert_port' => max(1, min(65535, (int)($data['expert_port'] ?? 8080))),
                    'velocity_enabled' => filter_var($data['velocity_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'velocity_threshold' => max(10, min(90, (int)($data['velocity_threshold'] ?? 40))),
                    'velocity_char_threshold' => max(100, min(10000, (int)($data['velocity_char_threshold'] ?? 1500))),
                    'velocity_index_prompt' => trim($data['velocity_index_prompt'] ?? '') ?: DEFAULT_CONFIG['velocity_index_prompt'],
                    'velocity_recall_prompt' => trim($data['velocity_recall_prompt'] ?? '') ?: DEFAULT_CONFIG['velocity_recall_prompt'],
                    'enable_pruning' => filter_var($data['enable_pruning'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'prune_threshold' => max(100, min(10000, (int)($data['prune_threshold'] ?? 1500))),
                    'prune_prompt' => trim($data['prune_prompt'] ?? DEFAULT_CONFIG['prune_prompt']) ?: DEFAULT_CONFIG['prune_prompt']
                ];
                
                // Save 10 expert slots
                $defaultNames = ['Text', 'Code', 'Medical', 'Electrical', 'Vehicle', 'Expert 6', 'Expert 7', 'Expert 8', 'Expert 9', 'Expert 10'];
                $defaultEnabled = [true, true, true, true, true, false, false, false, false, false];
                
                for ($i = 1; $i <= 10; $i++) {
                    $configData["expert_{$i}_model"] = trim($data["expert_{$i}_model"] ?? '');
                    $configData["expert_{$i}_name"] = trim($data["expert_{$i}_name"] ?? '') ?: $defaultNames[$i-1];
                    $configData["expert_{$i}_enabled"] = filter_var($data["expert_{$i}_enabled"] ?? $defaultEnabled[$i-1], FILTER_VALIDATE_BOOLEAN);
                    $configData["expert_{$i}_system_prompt"] = trim($data["expert_{$i}_system_prompt"] ?? 'You are a helpful assistant. Provide detailed, accurate responses.');
                }
                
                $configFile = "$governorDir/config.json";
                $success = file_put_contents($configFile, json_encode($configData, JSON_PRETTY_PRINT)) !== false;
                $response = $success
                    ? ['success' => true, 'config' => $configData]
                    : error_response('Failed to save governor config');
                break;
                
            case 'start_server':
                $serverType = $data['type'] ?? 'aux';
                $model = $data['model'] ?? '';
                $port = (int)($data['port'] ?? 8080);
                $cpuOnly = filter_var($data['cpu_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $contextSize = (int)($data['context_size'] ?? 0);
                
                if (!$model) {
                    $response = error_response('No model specified');
                    break;
                }
                
                $result = start_llama_server($serverType, $model, $port, $cpuOnly, $contextSize);
                $response = $result;
                break;
                
            case 'stop_server':
                $serverType = $data['type'] ?? '';
                $result = stop_llama_server($serverType);
                $response = $result;
                break;
                
            case 'server_status':
                $status = detect_running_servers();
                $response = ['success' => true, 'servers' => $status];
                break;
                
            case 'calibrate_context':
                $model = $data['model'] ?? '';
                if (!$model) {
                    $response = error_response('No model specified');
                    break;
                }
                
                $vramData = $data['vram'] ?? null;
                
                if (!$vramData) {
                    $output = [];
                    @exec('nvidia-smi --query-gpu=memory.total --format=csv,noheader,nounits 2>&1', $output);
                    if (!empty($output[0])) {
                        $vramData = ['total' => (int)trim($output[0])];
                    }
                }
                
                if (!$vramData || !isset($vramData['total']) || $vramData['total'] <= 0) {
                    $response = error_response('VRAM detection failed');
                    break;
                }
                
                try {
                    $result = calibrate_model_context($model, $vramData);
                    
                    if ($result['success']) {
                        $cache = load_context_cache();
                        $cache[$model] = [
                            'max_context' => $result['max_context'],
                            'architecture' => $result['architecture'] ?? null,
                            'theoretical_max' => $result['theoretical_max'] ?? null,
                            'tested_on_vram' => $vramData['total'],
                            'calibrated_at' => date('c'),
                            'method' => $result['method'] ?? 'unknown'
                        ];
                        save_context_cache($cache);
                    }
                    
                    $response = $result;
                } catch (Throwable $e) {
                    $response = error_response('Calibration failed: ' . $e->getMessage());
                }
                break;
                
            case 'get_cached_context':
                $model = $data['model'] ?? '';
                if (!$model) {
                    $response = ['success' => false];
                    break;
                }
                
                $cache = load_context_cache();
                if (isset($cache[$model])) {
                    $cached = $cache[$model];
                    $response = [
                        'success' => true,
                        'cached_context' => $cached['max_context'],
                        'calibration_info' => $cached
                    ];
                } else {
                    $response = ['success' => true, 'cached_context' => null];
                }
                break;
                
            case 'get_context_cache':
                $cache = load_context_cache();
                $response = ['success' => true, 'cache' => $cache];
                break;
                
            /* ===== SERVER LIFECYCLE ACTIONS ===== */
            case 'count_tokens':
                $text = $data['text'] ?? '';
                if (!$text) {
                    $response = ['success' => true, 'tokens' => 0];
                    break;
                }
                
                if (!is_port_open(8080)) {
                    $response = error_response('Server not running');
                    break;
                }
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "http://localhost:8080/tokenize",
                    CURLOPT_POST => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_POSTFIELDS => json_encode(['content' => $text]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
                ]);
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode === 200) {
                    $response = ['success' => true, 'tokens' => count(json_decode($result, true)['tokens'] ?? [])];
                } else {
                    $response = error_response('Tokenization failed');
                }
                break;
                
            case 'get_model_info':
                if (!is_port_open(8080)) {
                    $response = error_response('Server not running');
                    break;
                }
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "http://localhost:8080/props",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5
                ]);
                $propsResult = curl_exec($ch);
                $propsCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $contextLength = null;
                if ($propsCode === 200) {
                    $props = json_decode($propsResult, true);
                    $contextLength = $props['n_ctx'] ?? $props['default_generation_settings']['n_ctx'] ?? null;
                }
                
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => "http://localhost:8080/v1/models",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5
                ]);
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $model = $httpCode === 200 ? (json_decode($result, true)['data'][0] ?? []) : [];
                
                $response = ['success' => true, 'model' => $model['id'] ?? 'llama.cpp', 'context_length' => $contextLength, 'model_info' => $model];
                break;
                
            default:
                $response = error_response('Invalid action');
                break;
        }
    
    mm_json_response($response);
}
?>
<!DOCTYPE html>
<html lang="en" class="dark" dir="ltr">
    <style>
        body { opacity: 0; }
        body.loaded { opacity: 1; transition: opacity 0.3s ease-in; }
    </style>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>openOrchestrate - Llama.cpp Chat</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🦙</text></svg>">
    <meta name="theme-color" content="#171717">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script>
        function copyCodeToClipboard(button) {
            const codeBlock = button.closest('.code-block');
            const code = codeBlock.querySelector('code').textContent;
            
            navigator.clipboard.writeText(code).then(() => {
                button.classList.add('copied');
                setTimeout(() => {
                    button.classList.remove('copied');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy code:', err);
            });
        }
        
        class LlamaChat {
            constructor() {
                this.messages = [];
                this.currentChatId = this.generateId();
                this.isLoading = false;
                this.isStreaming = false;
                this.streamReader = null;
                this.sidebarCollapsed = false;
                this.chatToDelete = null;
                this.messageToDelete = null;
                this.currentStatus = '';
                this.inputTokenTimer = null;
                
                this.pruningConfig = {
                    enabled: true,
                    threshold: 500,
                    prunePrompt: 'Condense this message to only the essential information in 2-3 sentences:'
                };
                this.tokenTracker = {
                    currentTokens: 0,
                    maxContextLength: 0,
                    model: 'unknown',
                    savedTokens: 0,
                    prunedMessages: new Set(),
                    lastPrunedIndex: -1,
                    unableToPrune: false,
                    available: true
                };
                this.settings = {};
                this.attachments = [];
                this.serverAvailable = false;
                
                this.governorConfig = {
                    experts: [
                        { model: '', name: 'Text', enabled: true, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Code', enabled: true, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Medical', enabled: true, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Electrical', enabled: true, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Vehicle', enabled: true, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Expert 6', enabled: false, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Expert 7', enabled: false, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Expert 8', enabled: false, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Expert 9', enabled: false, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' },
                        { model: '', name: 'Expert 10', enabled: false, systemPrompt: 'You are a helpful assistant. Provide detailed, accurate responses.' }
                    ],
                    auxModel: '',
                    auxContextLength: 2048,
                    auxCpuOnly: true,
                    auxPort: 8081,
                    expertPort: 8080,
                    velocityEnabled: true,
                    velocityThreshold: 40,
                    velocityCharThreshold: 1500,
                    velocityIndexPrompt: 'Create a brief, descriptive title (max 10 words) that captures the key topic or intent of this message. Return ONLY the title, nothing else.',
                    velocityRecallPrompt: 'Given the user\'s new message, determine which archived conversation topic (if any) is most relevant and should be recalled to provide better context. If one topic is clearly relevant, respond with ONLY the number in brackets (e.g., 0 or 3). If no topic is relevant, respond with: NULL'
                };
                this.availableModels = [];
                this.vramInfo = null;
                this.currentExpert = null;
                this.isRoutingEnabled = true;
                
                this.velocityIndex = [];
                this.velocityRecallCount = 0;
                this.recalledMessage = null;
                
                this.auxQueue = [];
                this.auxBusy = false;
                
                this.BACKEND_URL = window.location.href;
                this.SERVER_URL = 'http://localhost:8080';
                
                this.initElements();
                this.initAudioChimes();
                this.init();
            }
            
            // ===== AUDIO CHIMES =====
            
            initAudioChimes() {
                this.audioContext = null;
                this.audioChimeEnabled = true;
                const audioToggle = document.getElementById('enableAudioChime');
                if (audioToggle) {
                    audioToggle.addEventListener('change', () => {
                        this.audioChimeEnabled = audioToggle.checked;
                    });
                }
            }
            
            playCompletionDing() {
                if (!this.audioChimeEnabled) return;
                if (!this.audioContext) {
                    this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                }
                const now = this.audioContext.currentTime;
                const oscillator = this.audioContext.createOscillator();
                const gainNode = this.audioContext.createGain();
                oscillator.connect(gainNode);
                gainNode.connect(this.audioContext.destination);
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                gainNode.gain.setValueAtTime(0.15, now);
                gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.1);
                oscillator.start(now);
                oscillator.stop(now + 0.1);
            }
            
            // ===== PIPELINE ENGINE =====
            
            async runPipeline(stages, context) {
                for (const stage of stages) {
                    if (context.aborted) return context;
                    try {
                        await stage.call(this, context);
                    } catch (err) {
                        context.error = err;
                        context.aborted = true;
                    }
                }
                return context;
            }
            
            // ===== PIPELINE STAGES =====
            
            async stageBuildMessage(ctx) {
                const attachmentsText = this.getAttachmentsText();
                ctx.fullMessage = attachmentsText
                    ? `${attachmentsText}\n\n${ctx.userMessage}`
                    : ctx.userMessage;

                const tokens = await this.countTokens(ctx.fullMessage);
                if (tokens !== null && this.tokenTracker.available) {
                    const reserve = Math.max(256, Math.floor(this.tokenTracker.maxContextLength * 0.1));
                    const available = this.tokenTracker.maxContextLength - this.tokenTracker.currentTokens - reserve;
                    if (tokens > available) {
                        this.showContextOverflowDialog(tokens, available);
                        ctx.aborted = true;
                    }
                }
            }
            
            stageCommitUserMessage(ctx) {
                this.chatInput.value = '';
                this.adjustTextareaHeight();
                this.updateSendButton();
                this.clearInputTokenDisplay();
                this.clearAttachments();

                this.addMessage('user', ctx.userMessage, {
                    attachments: ctx.attachments,
                    fullContent: ctx.fullMessage
                });

                ctx.userIndex = this.messages.length - 1;
            }
            
            async stageAutoPruneUser(ctx) {
                if (!this.pruningConfig.enabled || !this.governorConfig.auxModel) {
                    console.log('[PRUNE] Auto-prune skipped for user message', {
                        enabled: this.pruningConfig.enabled,
                        hasAuxModel: !!this.governorConfig.auxModel,
                        reason: !this.pruningConfig.enabled ? 'disabled' : 'no aux model'
                    });
                    return;
                }

                const msg = this.messages[ctx.userIndex];
                const text = msg.fullContent || msg.content;
                
                console.log('[PRUNE] stageAutoPruneUser - checking message', {
                    messageIndex: ctx.userIndex,
                    textLength: text.length,
                    threshold: this.pruningConfig.threshold,
                    willPrune: text.length >= this.pruningConfig.threshold,
                    hasFullContent: !!msg.fullContent
                });

                if (text.length < this.pruningConfig.threshold) {
                    console.log('[PRUNE] User message below threshold, skipping', {
                        textLength: text.length,
                        threshold: this.pruningConfig.threshold
                    });
                    return;
                }

                this.updateStatus('Pruning message...', 'pruning');
                
                console.log('[PRUNE] Starting prune operation for user message', {
                    textLength: text.length,
                    textPreview: text.substring(0, 200) + (text.length > 200 ? '...' : '')
                });
                
                const pruned = await this.pruneMessage(text);

                if (pruned) {
                    console.log('[PRUNE] User message prune successful', {
                        originalLength: text.length,
                        prunedLength: pruned.length,
                        savedChars: text.length - pruned.length
                    });
                    
                    msg.prunedContent = pruned;
                    const [o, p] = await Promise.all([
                        this.countTokens(text),
                        this.countTokens(pruned)
                    ]);
                    
                    console.log('[PRUNE] User message token counts', {
                        originalTokens: o,
                        prunedTokens: p,
                        savedTokens: o && p ? Math.max(0, o - p) : 0
                    });
                    
                    if (o && p) this.tokenTracker.savedTokens += Math.max(0, o - p);
                    this.rerenderMessages();
                } else {
                    console.error('[PRUNE] User message prune returned null', {
                        prunedValue: pruned,
                        messageLength: text.length
                    });
                }
                this.updateStatus('');
            }
            
            async stageVelocity(ctx) {
                await this.velocityIndexCheck();
                this.velocityClearRecall();

                if (this.velocityIndex.length) {
                    const hit = await this.velocityFindRelevant(ctx.userMessage);
                    if (hit) await this.velocityRecall(hit);
                }
            }
            
            async stageRouting(ctx) {
                if (!this.governorConfig.auxModel) return;
                
                const target = await this.routeQuery(ctx.userMessage);
                if (target !== this.currentExpert) {
                    await this.switchExpert(target);
                }
            }
            
            async stageGenerate(ctx) {
                this.showLoading();
                this.isLoading = true;
                this.isStreaming = true;
                this.updateStatus('Generating Response', 'generating');

                const assistantDiv = this.createStreamingMessage();
                const contentDiv = assistantDiv.querySelector('.message-content');
                this.hideLoading();
                this.addStopButton(assistantDiv);

                const messages = await this.prepareMessagesForApi();
                const maxTokens = Math.floor(this.tokenTracker.maxContextLength * 0.4);

                const res = await fetch(`${this.SERVER_URL}/v1/chat/completions`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        messages,
                        stream: true,
                        temperature: 0.7,
                        max_tokens: maxTokens
                    })
                });

                if (!res.ok) throw new Error(`Server error ${res.status}`);

                const reader = res.body.getReader();
                this.streamReader = reader;
                const decoder = new TextDecoder();
                let content = '';

                while (this.isStreaming) {
                    const { done, value } = await reader.read();
                    if (done) break;

                    const chunk = decoder.decode(value);
                    
                    for (const line of chunk.split('\n')) {
                        if (!line.startsWith('data: ')) continue;
                        const data = line.slice(6);
                        if (data === '[DONE]') {
                            this.isStreaming = false;
                            continue;
                        }
                        try {
                            const parsed = JSON.parse(data);
                            const token = parsed?.choices?.[0]?.delta?.content;
                            if (token) {
                                content += token;
                                contentDiv.innerHTML =
                                    this.formatContent(content) + '<span class="typing-cursor"></span>';
                                this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
                            }
                        } catch (parseErr) {
                            // Ignore parse errors on partial chunks
                        }
                    }
                }

                contentDiv.innerHTML = this.formatContent(content);
                this.highlightCodeBlocks(assistantDiv);
                
                this.messages.push({ role: 'assistant', content, timestamp: new Date().toISOString() });
                this.removeStopButton(assistantDiv);
                this.addMessageActions(assistantDiv, this.messages.length - 1);
                assistantDiv.id = '';
                
                // Set status to Ready immediately so UI feels snappy
                this.updateStatus('');
                
                // Play completion ding immediately
                this.playCompletionDing();
            }
            
            async stageFinalize(ctx) {
                const msg = this.messages.at(-1);
                
                console.log('[PRUNE] stageFinalize - checking assistant response', {
                    pruningEnabled: this.pruningConfig.enabled,
                    hasAuxModel: !!this.governorConfig.auxModel,
                    messageLength: msg.content.length,
                    threshold: this.pruningConfig.threshold,
                    willPrune: this.pruningConfig.enabled && this.governorConfig.auxModel && msg.content.length >= this.pruningConfig.threshold
                });
                
                if (this.pruningConfig.enabled && this.governorConfig.auxModel &&
                    msg.content.length >= this.pruningConfig.threshold) {

                    this.updateStatus('Pruning response...', 'pruning');
                    
                    console.log('[PRUNE] Starting prune operation for assistant response', {
                        contentLength: msg.content.length,
                        contentPreview: msg.content.substring(0, 200) + (msg.content.length > 200 ? '...' : '')
                    });
                    
                    const pruned = await this.pruneMessage(msg.content);
                    
                    if (pruned) {
                        console.log('[PRUNE] Assistant response prune successful', {
                            originalLength: msg.content.length,
                            prunedLength: pruned.length,
                            savedChars: msg.content.length - pruned.length
                        });
                        
                        msg.prunedContent = pruned;
                        this.rerenderMessages();
                    } else {
                        console.error('[PRUNE] Assistant response prune returned null', {
                            prunedValue: pruned,
                            messageLength: msg.content.length
                        });
                    }
                    this.updateStatus('');
                } else {
                    console.log('[PRUNE] Assistant response pruning skipped', {
                        reason: !this.pruningConfig.enabled ? 'pruning disabled' :
                                !this.governorConfig.auxModel ? 'no aux model' :
                                'below threshold'
                    });
                }

                await this.saveChatToHistory();
                await this.updateTokenUsage();

                this.isLoading = false;
                this.isStreaming = false;
                this.updateSendButton();
            }
            
            // ===== SEND MESSAGE =====
            
            async sendMessage() {
                if (this.isLoading || !this.serverAvailable) return;

                const userMessage = this.chatInput.value.trim();
                if (!userMessage) return;

                const ctx = {
                    userMessage,
                    attachments: this.getAttachmentNames(this.getAttachmentsText()),
                    aborted: false
                };

                await this.runPipeline([
                    this.stageBuildMessage,
                    this.stageCommitUserMessage,
                    this.stageAutoPruneUser,
                    this.stageVelocity,
                    this.stageRouting,
                    this.stageGenerate,
                    this.stageFinalize
                ], ctx);
            }
            
            // ===== HELPER METHODS =====
            
            generateId() {
                return Date.now().toString(36) + Math.random().toString(36).substr(2);
            }
            
            initElements() {
                this.$ = id => document.getElementById(id);
                this.chatInput = this.$('chatInput');
                this.sendBtn = this.$('sendBtn');
                this.chatForm = this.$('chatForm');
                this.messagesContainer = this.$('messagesContainer');
                this.welcomeScreen = this.$('welcomeScreen');
                this.statsContainer = this.$('statsContainer');
                this.chatsList = this.$('chatsList');
                this.searchInput = this.$('searchInput');
                this.sidebar = this.$('sidebar');
                this.app = document.querySelector('.app');
                this.settingsPanel = this.$('settingsPanel');
                this.enablePruning = this.$('enablePruning');
                this.pruneThreshold = this.$('pruneThreshold');
                this.prunePrompt = this.$('prunePrompt');
                this.confirmDialog = this.$('confirmDialog');
                this.confirmMessage = this.$('confirmMessage');
                this.contextOverflowDialog = this.$('contextOverflowDialog');
                this.contextOverflowMessage = this.$('contextOverflowMessage');
                this.fileInput = this.$('fileInput');
                this.attachBtn = this.$('attachBtn');
                this.attachmentsPreview = this.$('attachmentsPreview');
                
                // Dynamic expert slot container
                this.expertSlotsContainer = this.$('expertSlotsContainer');
                
                // Auxiliary model elements
                this.governorAuxModel = this.$('governorAuxModel');
                this.governorAuxCpuOnly = this.$('governorAuxCpuOnly');
                this.auxContextLength = this.$('auxContextLength');
                this.vramValue = this.$('vramValue');
                this.vramGpu = this.$('vramGpu');
                this.auxModelSize = this.$('auxModelSize');
                this.expertServerStatus = this.$('expertServerStatus');
                this.auxServerStatus = this.$('auxServerStatus');
                
                this.startupOverlay = this.$('startupOverlay');
                this.startupTitle = this.$('startupTitle');
                this.startupMessage = this.$('startupMessage');
                this.startupError = this.$('startupError');
                
                this.velocityEnabled = this.$('velocityEnabled');
                this.velocityThreshold = this.$('velocityThreshold');
                this.velocityCharThreshold = this.$('velocityCharThreshold');
                this.velocityIndexPrompt = this.$('velocityIndexPrompt');
                this.velocityRecallPrompt = this.$('velocityRecallPrompt');
                this.velocityStats = this.$('velocityStats');
                this.velocityIndexedCount = this.$('velocityIndexedCount');
                this.velocityRecallCount = this.$('velocityRecallCount');
                
                // Generate expert slot UI elements
                this.generateExpertSlotUI();
            }
            
            generateExpertSlotUI() {
                const defaultNames = ['Text', 'Code', 'Medical', 'Electrical', 'Vehicle', 'Expert 6', 'Expert 7', 'Expert 8', 'Expert 9', 'Expert 10'];
                const defaultEnabled = [true, true, true, true, true, false, false, false, false, false];
                
                // Generate expert slots HTML with inline system prompts
                let slotsHtml = '';
                for (let i = 1; i <= 10; i++) {
                    slotsHtml += `
                        <div class="model-select-wrapper expert-slot" data-slot="${i}">
                            <div class="model-select-label">
                                <div class="settings-checkbox" style="margin:0">
                                    <input type="checkbox" id="expert${i}Enabled" ${defaultEnabled[i-1] ? 'checked' : ''}>
                                    <label for="expert${i}Enabled">${defaultNames[i-1]}</label>
                                </div>
                                <span class="model-size" id="expert${i}ModelSize"></span>
                            </div>
                            <input type="text" class="settings-input expert-name-input" id="expert${i}Name" value="${defaultNames[i-1]}" placeholder="Expert name...">
                            <select class="model-select" id="expert${i}Model" style="margin-top:0.5rem"><option value="">Select a model...</option></select>
                            <label class="settings-label" id="expert${i}PromptLabel" style="margin-top:0.75rem;font-size:0.8rem">${defaultNames[i-1]} System Prompt</label>
                            <textarea class="settings-textarea" id="expert${i}SystemPrompt" rows="2" style="margin-top:0.25rem">You are a helpful assistant. Provide detailed, accurate responses.</textarea>
                        </div>
                    `;
                }
                this.expertSlotsContainer.innerHTML = slotsHtml;
                
                // Add event listeners for name changes to update prompt labels and checkbox labels
                for (let i = 1; i <= 10; i++) {
                    const nameInput = this.$(`expert${i}Name`);
                    const promptLabel = this.$(`expert${i}PromptLabel`);
                    const enabledCheckbox = this.$(`expert${i}Enabled`);
                    const enabledLabel = enabledCheckbox?.nextElementSibling;
                    
                    nameInput?.addEventListener('input', () => {
                        const name = nameInput.value.trim() || `Expert ${i}`;
                        if (promptLabel) promptLabel.textContent = `${name} System Prompt`;
                        if (enabledLabel) enabledLabel.textContent = name;
                    });
                    
                    // Add change listener for model size display
                    const modelSelect = this.$(`expert${i}Model`);
                    modelSelect?.addEventListener('change', () => this.updateModelSizeDisplay());
                }
            }
            
            async init() {
                this.setupEventListeners();
                this.createStatsBoxes();
                await this.loadGovernorConfigOnStartup();
                await this.loadChatHistory();
                await this.initModelInfo();
                this.adjustTextareaHeight();
                this.updateUI();
                this.chatInput.focus();
            }
            
            async loadGovernorConfigOnStartup() {
                try {
                    const configData = await this.apiCall('load_governor_config');
                    if (configData.success) {
                        // Build experts array from config
                        const defaultNames = ['Text', 'Code', 'Medical', 'Electrical', 'Vehicle', 'Expert 6', 'Expert 7', 'Expert 8', 'Expert 9', 'Expert 10'];
                        const defaultEnabled = [true, true, true, true, true, false, false, false, false, false];
                        
                        this.governorConfig = {
                            experts: [],
                            auxModel: configData.config.aux_model || '',
                            auxContextLength: configData.config.aux_context_length || 2048,
                            auxCpuOnly: configData.config.aux_cpu_only !== false,
                            auxPort: configData.config.aux_port || 8081,
                            expertPort: configData.config.expert_port || 8080,
                            velocityEnabled: configData.config.velocity_enabled !== false,
                            velocityThreshold: configData.config.velocity_threshold || 40,
                            velocityCharThreshold: configData.config.velocity_char_threshold || 1500,
                            velocityIndexPrompt: configData.config.velocity_index_prompt || '',
                            velocityRecallPrompt: configData.config.velocity_recall_prompt || ''
                        };
                        
                        // Load 10 expert slots
                        for (let i = 1; i <= 10; i++) {
                            this.governorConfig.experts.push({
                                model: configData.config[`expert_${i}_model`] || '',
                                name: configData.config[`expert_${i}_name`] || defaultNames[i-1],
                                enabled: configData.config[`expert_${i}_enabled`] !== undefined ? configData.config[`expert_${i}_enabled`] : defaultEnabled[i-1],
                                systemPrompt: configData.config[`expert_${i}_system_prompt`] || 'You are a helpful assistant. Provide detailed, accurate responses.'
                            });
                        }
                        
                        this.pruningConfig.enabled = configData.config.enable_pruning !== false;
                        this.pruningConfig.threshold = configData.config.prune_threshold || 1500;
                        this.pruningConfig.prunePrompt = configData.config.prune_prompt || this.pruningConfig.prunePrompt;
                        await this.initializeGovernor();
                    }
                } catch (e) {}
            }
            
            setupEventListeners() {
                this.chatForm.addEventListener('submit', e => {
                    e.preventDefault();
                    this.sendMessage();
                });
                
                this.chatInput.addEventListener('input', () => {
                    this.adjustTextareaHeight();
                    this.updateSendButton();
                    this.scheduleTokenCount();
                });
                
                this.chatInput.addEventListener('keydown', e => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (this.chatInput.value.trim() && !this.isLoading) {
                            this.sendMessage();
                        }
                    }
                });
                
                this.$('suggestionsGrid').addEventListener('click', e => {
                    const btn = e.target.closest('.suggestion-btn');
                    if (btn) {
                        this.chatInput.value = btn.dataset.prompt;
                        this.adjustTextareaHeight();
                        this.updateSendButton();
                        this.chatInput.focus();
                        this.scheduleTokenCount();
                    }
                });
                
                this.attachBtn.addEventListener('click', () => this.fileInput.click());
                this.fileInput.addEventListener('change', e => this.handleFileSelect(e));
                this.attachmentsPreview.addEventListener('click', e => {
                    const removeBtn = e.target.closest('.remove-attachment');
                    if (removeBtn) {
                        const index = parseInt(removeBtn.dataset.index);
                        this.removeAttachment(index);
                    }
                });
                
                this.$('newChatBtn').addEventListener('click', () => this.createNewChat());
                this.searchInput.addEventListener('input', e => this.filterChats(e.target.value));
                
                ['sidebarToggle', 'headerSidebarToggle', 'mobileSidebarToggle'].forEach(id => {
                    this.$(id)?.addEventListener('click', () => this.toggleSidebar());
                });
                
                this.$('confirmCancel').addEventListener('click', () => this.hideDialog(this.confirmDialog));
                this.$('confirmDelete').addEventListener('click', () => this.deleteChatConfirmed());
                this.confirmDialog.addEventListener('click', e => {
                    if (e.target === this.confirmDialog) this.hideDialog(this.confirmDialog);
                });
                
                this.$('contextOverflowOk').addEventListener('click', () => this.hideDialog(this.contextOverflowDialog));
                this.contextOverflowDialog.addEventListener('click', e => {
                    if (e.target === this.contextOverflowDialog) this.hideDialog(this.contextOverflowDialog);
                });
                
                this.$('settingsBtn').addEventListener('click', () => this.openSettings());
                this.$('settingsClose').addEventListener('click', () => this.closeSettings());
                this.$('closeSettingsBtn').addEventListener('click', () => this.closeSettings());
                this.$('saveSettingsBtn').addEventListener('click', () => this.saveSettings());
                this.enablePruning.addEventListener('change', () => this.updatePruningUI());
                
                document.querySelectorAll('.accordion-header').forEach(header => {
                    header.addEventListener('click', () => {
                        const accordion = header.closest('.settings-accordion');
                        accordion.classList.toggle('open');
                    });
                });
                
                // Aux model change listener
                this.governorAuxModel?.addEventListener('change', () => this.updateModelSizeDisplay());
                
                document.addEventListener('keydown', e => {
                    if (e.key === 'Escape') {
                        this.hideDialog(this.confirmDialog);
                        this.hideDialog(this.contextOverflowDialog);
                    }
                });
                
                window.addEventListener('resize', () => {
                    if (window.innerWidth >= 1024) {
                        this.sidebar.classList.remove('active');
                        this.app.classList.remove('sidebar-collapsed');
                        this.sidebarCollapsed = false;
                    }
                });
                
                setInterval(() => this.checkServerStatus(), 5000);
            }
            
            scheduleTokenCount() {
                clearTimeout(this.inputTokenTimer);
                const text = this.chatInput.value.trim();
                const attachmentsText = this.getAttachmentsText();
                
                if (!text && !attachmentsText) {
                    this.clearInputTokenDisplay();
                    return;
                }
                
                this.inputTokenTimer = setTimeout(async () => {
                    const currentText = this.chatInput.value.trim();
                    const currentAttachments = this.getAttachmentsText();
                    if (!currentText && !currentAttachments) {
                        this.clearInputTokenDisplay();
                        return;
                    }
                    const fullText = currentAttachments ? `${currentAttachments}\n\n${currentText}` : currentText;
                    const tokens = await this.countTokens(fullText);
                    if (tokens !== null && (this.chatInput.value.trim() || this.attachments.length) && !this.isLoading) {
                        this.showInputTokenCount(tokens);
                    } else {
                        this.clearInputTokenDisplay();
                    }
                }, 300);
            }
            
            showInputTokenCount(tokens) {
                if (!this.inputTokenBox) {
                    this.inputTokenBox = document.createElement('div');
                    this.inputTokenBox.className = 'stat-box input-tokens';
                    this.inputTokenBox.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 0 0-1.883 2.542l.857 6a2.25 2.25 0 0 0 2.227 1.932H19.05a2.25 2.25 0 0 0 2.227-1.932l.857-6a2.25 2.25 0 0 0-1.883-2.542m-16.5 0V6A2.25 2.25 0 0 1 6 3.75h3.879a1.5 1.5 0 0 1 1.06.44l2.122 2.12a1.5 1.5 0 0 0 1.06.44H18A2.25 2.25 0 0 1 20.25 9v.776"/></svg><span class="stat-text"></span>`;
                    this.statsContainer.appendChild(this.inputTokenBox);
                }
                this.inputTokenBox.querySelector('.stat-text').textContent = `Input: ${tokens} tokens`;
                this.inputTokenBox.style.display = 'flex';
            }
            
            clearInputTokenDisplay() {
                clearTimeout(this.inputTokenTimer);
                if (this.inputTokenBox) {
                    this.inputTokenBox.style.display = 'none';
                }
            }
            
            updateSendButton() {
                this.sendBtn.disabled = !this.chatInput.value.trim() || this.isLoading || !this.serverAvailable;
            }
            
            createStatsBoxes() {
                this.statsContainer.innerHTML = '';
                const icons = {
                    status: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>',
                    context: '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0-1 3m8.5-3 1 3m0 0 .5 1.5m-.5-1.5h-9.5m0 0-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6"/>'
                };

                ['status', 'context'].forEach(type => {
                    const box = document.createElement('div');
                    box.className = `stat-box ${type}`;
                    box.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">${icons[type]}</svg><span class="stat-text">${type === 'status' ? 'Ready' : ''}</span>`;
                    box.style.display = type === 'status' ? 'flex' : 'none';
                    this.statsContainer.appendChild(box);
                    this[`${type}Box`] = box;
                });
            }
            
            async apiCall(action, params = {}) {
                try {
                    const response = await fetch(this.BACKEND_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action, ...params })
                    });
                    if (!response.ok) {
                        return { success: false, error: `HTTP ${response.status}` };
                    }
                    return response.json();
                } catch (e) {
                    return { success: false, error: e.message };
                }
            }
            
            // Queued API call for aux server - ensures sequential processing
            async auxApiCall(action, params = {}) {
                return new Promise((resolve) => {
                    this.auxQueue.push({ action, params, resolve });
                    this.processAuxQueue();
                });
            }
            
            async processAuxQueue() {
                if (this.auxBusy || this.auxQueue.length === 0) return;
                
                this.auxBusy = true;
                const { action, params, resolve } = this.auxQueue.shift();
                
                try {
                    const result = await this.apiCall(action, params);
                    resolve(result);
                } catch (e) {
                    resolve({ success: false, error: e.message });
                } finally {
                    this.auxBusy = false;
                    // Process next item in queue
                    if (this.auxQueue.length > 0) {
                        this.processAuxQueue();
                    }
                }
            }
            
            async countTokens(text) {
                if (!text?.trim()) return 0;
                const data = await this.apiCall('count_tokens', { text });
                return data.success ? data.tokens : null;
            }
            
            async initModelInfo() {
                const data = await this.apiCall('get_model_info');
                if (data.success) {
                    this.serverAvailable = true;
                    if (data.context_length) {
                        this.tokenTracker.maxContextLength = data.context_length;
                    }
                    this.tokenTracker.model = data.model || 'llama.cpp';
                    this.tokenTracker.available = true;
                    this.chatInput.placeholder = 'How can I help you today?';
                    this.chatInput.disabled = false;
                    this.sendBtn.disabled = false;
                } else {
                    this.setServerUnavailable(data.error || 'Server not running');
                }
            }
            
            setServerUnavailable(reason) {
                this.serverAvailable = false;
                this.tokenTracker.available = false;
                this.chatInput.placeholder = `No server running at ${this.SERVER_URL}`;
                this.chatInput.disabled = true;
                this.sendBtn.disabled = true;
                this.updateTokenMeter();
                this.updateStatus('Server Offline', 'offline');
            }
            
            async retryServerConnection() {
                this.chatInput.placeholder = 'Connecting...';
                await this.initModelInfo();
            }
            
            createStreamingMessage() {
                const div = document.createElement('div');
                div.className = 'message assistant-message';
                div.id = 'streamingMessage';
                div.innerHTML = '<div class="message-content"></div>';
                this.messagesContainer.appendChild(div);
                this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
                return div;
            }
            
            stopStreaming() {
                if (!this.isStreaming || !this.streamReader) return;
                
                const wasFirst = this.messages.length === 1;
                this.isStreaming = false;
                this.streamReader.cancel();
                this.updateStatus('Stopped', 'stopped');
                
                const streamingMsg = document.getElementById('streamingMessage');
                if (streamingMsg) {
                    this.removeStopButton(streamingMsg);
                    const content = streamingMsg.querySelector('.message-content')?.textContent.replace('▌', '').trim() || '';
                    
                    if (content) {
                        this.messages.push({ role: 'assistant', content, timestamp: new Date().toISOString() });
                        this.addMessageActions(streamingMsg, this.messages.length - 1);
                        if (!wasFirst) this.saveChatToHistory();
                    } else {
                        streamingMsg.remove();
                        if (wasFirst) {
                            this.messages = [];
                            this.rerenderMessages();
                            this.updateStatus('');
                            return;
                        }
                    }
                    streamingMsg.id = '';
                }
                
                this.updateTokenUsage();
                setTimeout(() => {
                    if (this.currentStatus === 'Stopped') this.updateStatus('');
                }, 2000);
            }
            
            addStopButton(msgDiv) {
                const actions = document.createElement('div');
                actions.className = 'message-actions';
                actions.innerHTML = `<button class="action-btn stop"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z"/></svg>Stop</button>`;
                actions.querySelector('button').addEventListener('click', () => this.stopStreaming());
                msgDiv.appendChild(actions);
            }
            
            removeStopButton(msgDiv) {
                msgDiv.querySelector('.message-actions')?.remove();
            }
            
            async prepareMessagesForApi() {
                const result = [];
                
                // Add system prompt - use the working default
                const systemPrompt = 'You are a helpful assistant. Provide detailed, accurate responses.';
                
                result.push({
                    role: 'system',
                    content: systemPrompt
                });
                
                for (const msg of this.messages) {
                    // Skip indexed messages UNLESS they've been recalled
                    if (msg.velocityIndexed && !msg.velocityRecalled) {
                        continue;
                    }
                    
                    // Always use pruned content for API when available
                    const contentToSend = msg.prunedContent || msg.fullContent || msg.content;
                    
                    // If this is a recalled message, add a context note
                    if (msg.velocityRecalled && msg.velocityTitle) {
                        result.push({
                            role: msg.role,
                            content: `[Recalled context: ${msg.velocityTitle}]\n\n${contentToSend}`
                        });
                    } else {
                        result.push({
                            role: msg.role,
                            content: contentToSend
                        });
                    }
                }
                
                return result;
            }
            
            async pruneMessage(message) {
                console.log('[PRUNE] Client-side pruneMessage called', {
                    messageLength: message.length,
                    messagePreview: message.substring(0, 200) + (message.length > 200 ? '...' : ''),
                    prunePrompt: this.pruningConfig.prunePrompt,
                    timestamp: new Date().toISOString()
                });
                
                try {
                    const data = await this.auxApiCall('prune_message', {
                        message,
                        prompt: this.pruningConfig.prunePrompt
                    });
                    
                    console.log('[PRUNE] Received response from server', {
                        success: data.success,
                        hasPruned: !!data.pruned,
                        prunedLength: data.pruned ? data.pruned.length : 0,
                        error: data.error || 'none',
                        timestamp: new Date().toISOString()
                    });
                    
                    if (data.success && data.pruned) {
                        console.log('[PRUNE] Prune successful', {
                            originalLength: message.length,
                            prunedLength: data.pruned.length,
                            compressionRatio: Math.round((1 - data.pruned.length / message.length) * 100) + '%',
                            prunedPreview: data.pruned.substring(0, 200) + (data.pruned.length > 200 ? '...' : '')
                        });
                    } else {
                        console.error('[PRUNE] Prune failed or returned null', {
                            success: data.success,
                            error: data.error,
                            fullResponse: data
                        });
                    }
                    
                    return data.success ? data.pruned : null;
                } catch (error) {
                    console.error('[PRUNE] Exception in pruneMessage', {
                        error: error.message,
                        stack: error.stack,
                        timestamp: new Date().toISOString()
                    });
                    return null;
                }
            }
            
            async updateTokenUsage() {
                if (!this.messages.length) {
                    this.tokenTracker.currentTokens = 0;
                    this.tokenTracker.savedTokens = 0;
                    this.tokenTracker.available = true;
                    this.updateTokenMeter();
                    return;
                }
                
                let total = 0;
                
                for (const msg of this.messages) {
                    // Skip indexed messages (unless recalled) - they're not in the context
                    if (msg.velocityIndexed && !msg.velocityRecalled) {
                        continue;
                    }
                    
                    // Count tokens based on what will be sent to API (pruned content if available)
                    const content = msg.prunedContent || msg.fullContent || msg.content;
                    const tokens = await this.countTokens(content);
                    if (tokens === null) {
                        // Server not available - hide token display
                        this.tokenTracker.available = false;
                        this.updateTokenMeter();
                        return;
                    }
                    total += tokens + 4;
                }
                
                this.tokenTracker.available = true;
                this.tokenTracker.currentTokens = total + 50;
                this.updateTokenMeter();
                this.checkContextLimit();
            }
            
            checkContextLimit() {
                const pct = (this.tokenTracker.currentTokens / this.tokenTracker.maxContextLength) * 100;
                if (pct > 90) {
                    this.showNotification(`Context window nearly full (${Math.round(pct)}%)`, 'context-error', 5000);
                } else if (pct > 70) {
                    this.showNotification(`Context usage: ${Math.round(pct)}%`, 'context-hint', 3000);
                }
            }
            
            showNotification(message, type, duration) {
                const boxName = `${type}NotificationBox`;
                if (!this[boxName]) {
                    this[boxName] = document.createElement('div');
                    this[boxName].className = `stat-box ${type}`;
                    this.statsContainer.appendChild(this[boxName]);
                }
                
                const icons = {
                    'context-warning': '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>',
                    'context-hint': '<path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z"/>',
                    'context-error': '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>'
                };
                
                this[boxName].innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">${icons[type] || icons['context-warning']}</svg><span class="stat-text">${message}</span>`;
                this[boxName].style.display = 'flex';
                
                setTimeout(() => {
                    if (this[boxName]) this[boxName].style.display = 'none';
                }, duration);
            }
            
            updateTokenMeter() {
                const { currentTokens: used, maxContextLength: max, available } = this.tokenTracker;
                const pct = Math.min(100, Math.round((used / max) * 100));
                
                if (this.contextBox) {
                    // Hide context display if token counting is unavailable
                    if (!available) {
                        this.contextBox.style.display = 'none';
                    } else {
                        const text = this.contextBox.querySelector('.stat-text');
                        if (text) text.textContent = `Context: ${used}/${max} (${pct}%)`;
                        this.contextBox.style.display = used > 0 ? 'flex' : 'none';
                    }
                }
            }
            
            updateStatus(status, state = '') {
                this.currentStatus = status;
                if (!this.statusBox) return;
                
                const text = this.statusBox.querySelector('.stat-text');
                const icon = this.statusBox.querySelector('svg');
                if (!text || !icon) return;
                
                // Remove all state classes
                this.statusBox.classList.remove(
                    'pruning', 'generating', 'error', 'routing', 'indexing', 
                    'searching', 'switching', 'learning', 'ready', 'stopped', 'offline', 'warning'
                );
                icon.classList.remove('spinner');
                
                // Define status configurations
                const statusConfig = {
                    '': { 
                        text: 'Ready', 
                        state: 'ready',
                        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'
                    },
                    'Generating Response': { 
                        text: status, 
                        state: 'generating',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Pruning message...': { 
                        text: status, 
                        state: 'pruning',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Pruning response...': { 
                        text: status, 
                        state: 'pruning',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Finding expert...': { 
                        text: status, 
                        state: 'routing',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Switching models...': { 
                        text: status, 
                        state: 'switching',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Learning GPU...': { 
                        text: status, 
                        state: 'learning',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Indexing...': { 
                        text: status, 
                        state: 'indexing',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Searching index...': { 
                        text: status, 
                        state: 'searching',
                        icon: '<path d="M12 3a9 9 0 1 0 9 9" stroke-width="2" stroke-linecap="round"/>',
                        spin: true
                    },
                    'Stopped': { 
                        text: status, 
                        state: 'stopped',
                        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z"/>'
                    },
                    'Server Offline': { 
                        text: status, 
                        state: 'offline',
                        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>'
                    },
                    'Unable to Prune': { 
                        text: status, 
                        state: 'warning',
                        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>'
                    }
                };
                
                // Get config for this status, or use the state parameter
                const config = statusConfig[status] || { 
                    text: status || 'Ready', 
                    state: state || 'ready',
                    icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'
                };
                
                text.textContent = config.text;
                icon.innerHTML = config.icon;
                if (config.spin) icon.classList.add('spinner');
                if (config.state) this.statusBox.classList.add(config.state);
                this.statusBox.style.display = 'flex';
            }
            
            showTemporaryStatus(status, state, duration) {
                this.updateStatus(status, state);
                setTimeout(() => {
                    if (this.currentStatus === status) this.updateStatus('');
                }, duration);
            }

            addMessage(role, content, options = {}) {
                const messageData = { 
                    role, 
                    content, 
                    timestamp: new Date().toISOString()
                };
                // Store full content (with attachments) for API
                if (options.fullContent) {
                    messageData.fullContent = options.fullContent;
                }
                if (options.attachments) {
                    messageData.attachments = options.attachments;
                }
                this.messages.push(messageData);
                const el = this.createMessageElement(role, content, this.messages.length - 1, options.attachments);
                this.messagesContainer.appendChild(el);
                this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
                if (this.messages.length === 1) this.welcomeScreen.classList.add('hidden');
                this.updateUI();
            }
            
            createMessageElement(role, content, index, attachments = null) {
                const msg = this.messages[index];
                const div = document.createElement('div');
                
                // Build class list
                let classes = `message ${role}-message`;
                if (msg?.prunedContent) classes += ' pruned-message';
                if (msg?.velocityIndexed && !msg?.velocityRecalled) classes += ' indexed-message';
                if (msg?.velocityRecalled) classes += ' recalled-message';
                div.className = classes;
                
                let attachmentsHtml = '';
                if (attachments && attachments.length > 0) {
                    attachmentsHtml = `<div class="message-attachments">${attachments.map(name => `
                        <span class="message-attachment-chip">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            ${this.escapeHtml(name)}
                        </span>
                    `).join('')}</div>`;
                }
                
                // Add velocity index badge if message is indexed
                let velocityBadgeHtml = '';
                if (msg?.velocityIndexed && !msg?.velocityRecalled) {
                    velocityBadgeHtml = `<div class="indexed-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="10" height="10">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
                        </svg>
                        Indexed: ${this.escapeHtml(msg.velocityTitle || 'Untitled')}
                    </div>`;
                } else if (msg?.velocityRecalled) {
                    velocityBadgeHtml = `<div class="recalled-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="10" height="10">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
                        </svg>
                        Recalled: ${this.escapeHtml(msg.velocityTitle || 'Untitled')}
                    </div>`;
                }
                
                // Main message content - always show original content
                const mainContent = `<div class="message-content">${velocityBadgeHtml}${this.formatContent(content)}</div>`;
                
                // Add pruning UI if message has been pruned
                let pruningHtml = '';
                if (msg?.prunedContent) {
                    const isExpanded = false;
                    const toggleIcon = isExpanded ? 
                        '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>' :
                        '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>';
                    
                    pruningHtml = `
                        <div class="pruned-container" data-index="${index}">
                            <div class="pruned-toggle ${isExpanded ? 'expanded' : 'collapsed'}" data-index="${index}">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    ${toggleIcon}
                                </svg>
                                <span>${isExpanded ? 'Hide' : 'Show'} pruned version (${msg.prunedContent.length} chars)</span>
                            </div>
                            ${isExpanded ? `
                                <div class="pruned-content">
                                    <div class="pruned-content-header">
                                        <span class="pruned-label">Pruned Version</span>
                                        <button class="edit-pruned-btn" data-index="${index}">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="12" height="12">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>
                                            </svg>
                                            Edit
                                        </button>
                                    </div>
                                    <div class="pruned-text">${this.formatContent(msg.prunedContent)}</div>
                                </div>
                            ` : ''}
                        </div>
                    `;
                }
                
                div.innerHTML = `${attachmentsHtml}${mainContent}${pruningHtml}`;
                this.addMessageActions(div, index);
                
                // Add event listener for pruning toggle
                if (msg?.prunedContent) {
                    const toggle = div.querySelector('.pruned-toggle');
                    toggle.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.togglePrunedVersion(index);
                    });
                    
                    // Add event listener for edit pruned button
                    const editBtn = div.querySelector('.edit-pruned-btn');
                    if (editBtn) {
                        editBtn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            this.editPrunedContent(index);
                        });
                    }
                }
                
                return div;
            }
            
            togglePrunedVersion(index) {
                const msg = this.messages[index];
                if (msg?.prunedContent) {
                    const toggle = document.querySelector(`.pruned-toggle[data-index="${index}"]`);
                    const prunedContainer = document.querySelector(`.pruned-container[data-index="${index}"]`);
                    
                    if (!toggle || !prunedContainer) return;
                    
                    if (toggle.classList.contains('collapsed')) {
                        // Expand
                        toggle.classList.remove('collapsed');
                        toggle.classList.add('expanded');
                        toggle.querySelector('svg').innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>';
                        toggle.querySelector('span').textContent = `Hide pruned version (${msg.prunedContent.length} chars)`;
                        
                        // Create and insert pruned content
                        const prunedContentHTML = `
                            <div class="pruned-content">
                                <div class="pruned-content-header">
                                    <span class="pruned-label">Pruned Version</span>
                                    <button class="edit-pruned-btn" data-index="${index}">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="12" height="12">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>
                                        </svg>
                                        Edit
                                    </button>
                                </div>
                                <div class="pruned-text">${this.formatContent(msg.prunedContent)}</div>
                            </div>
                        `;
                        
                        toggle.insertAdjacentHTML('afterend', prunedContentHTML);
                        
                        // Add event listener to new edit button
                        const newEditBtn = prunedContainer.querySelector('.edit-pruned-btn');
                        if (newEditBtn) {
                            newEditBtn.addEventListener('click', (e) => {
                                e.stopPropagation();
                                this.editPrunedContent(index);
                            });
                        }
                    } else {
                        // Collapse
                        toggle.classList.remove('expanded');
                        toggle.classList.add('collapsed');
                        toggle.querySelector('svg').innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>';
                        toggle.querySelector('span').textContent = `Show pruned version (${msg.prunedContent.length} chars)`;
                        
                        // Remove pruned content
                        prunedContainer.querySelector('.pruned-content')?.remove();
                    }
                }
            }
            
            editPrunedContent(index) {
                const msg = this.messages[index];
                if (!msg?.prunedContent) return;
                
                const prunedContentDiv = document.querySelector(`.pruned-container[data-index="${index}"] .pruned-text`);
                if (!prunedContentDiv) return;
                
                const originalHTML = prunedContentDiv.innerHTML;
                const originalText = msg.prunedContent;
                
                prunedContentDiv.innerHTML = `
                    <textarea class="edit-textarea" style="margin:0;width:100%">${this.escapeHtml(originalText)}</textarea>
                    <div class="edit-actions" style="margin-top:8px">
                        <button class="save-btn">Save</button>
                        <button class="cancel-btn">Cancel</button>
                    </div>`;
                
                const textarea = prunedContentDiv.querySelector('textarea');
                textarea.focus();
                textarea.select();
                
                const save = async () => {
                    const newContent = textarea.value.trim();
                    if (newContent && newContent !== originalText) {
                        msg.prunedContent = newContent;
                        this.rerenderMessages();
                        await this.saveChatToHistory();
                this.playCompletionDing();
                        await this.updateTokenUsage();
                    } else {
                        cancel();
                    }
                };
                
                const cancel = () => {
                    prunedContentDiv.innerHTML = originalHTML;
                };
                
                prunedContentDiv.querySelector('.save-btn').addEventListener('click', save);
                prunedContentDiv.querySelector('.cancel-btn').addEventListener('click', cancel);
                
                textarea.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && e.ctrlKey) {
                        e.preventDefault();
                        save();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        cancel();
                    }
                });
            }
            
            addMessageActions(msgDiv, index) {
                msgDiv.querySelector('.message-actions')?.remove();
                const msg = this.messages[index];
                if (!msg) return;
                
                const isAssistant = msg.role === 'assistant';
                const actions = document.createElement('div');
                actions.className = 'message-actions';
                
                const buttons = [
                    {
                        cls: isAssistant ? 'regenerate' : '',
                        icon: isAssistant
                            ? '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/>'
                            : '<path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10"/>',
                        text: isAssistant ? 'Regenerate' : 'Edit',
                        action: () => this.editMessage(index)
                    },
                    {
                        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 0 1 1.927-.184"/>',
                        text: 'Copy',
                        action: () => this.copyMessage(index)
                    },
                    {
                        icon: '<path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>',
                        text: 'Delete',
                        action: () => this.deleteMessage(index)
                    }
                ];
                
                buttons.forEach(b => {
                    const btn = document.createElement('button');
                    btn.className = `action-btn ${b.cls || ''}`;
                    btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">${b.icon}</svg>${b.text}`;
                    btn.addEventListener('click', e => {
                        e.stopPropagation();
                        b.action();
                    });
                    actions.appendChild(btn);
                });
                
                msgDiv.appendChild(actions);
            }
            
            editMessage(index) {
                const msg = this.messages[index];
                if (!msg) return;
                
                if (msg.role === 'assistant') {
                    this.regenerateMessage(index);
                    return;
                }
                
                // Find the actual DOM element for this message
                // Count which user message this is (0-indexed among user messages only)
                let userMessageIndex = 0;
                for (let i = 0; i < index; i++) {
                    if (this.messages[i].role === 'user') {
                        userMessageIndex++;
                    }
                }
                
                const userMessages = document.querySelectorAll('.message.user-message');
                const msgEl = userMessages[userMessageIndex];
                if (!msgEl) return;
                
                const contentDiv = msgEl.querySelector('.message-content');
                const actionsDiv = msgEl.querySelector('.message-actions');
                if (!contentDiv) return;
                
                const original = msg.content;
                const originalHTML = contentDiv.innerHTML;
                if (actionsDiv) actionsDiv.style.display = 'none';
                
                contentDiv.innerHTML = `
                    <textarea class="edit-textarea">${this.escapeHtml(original)}</textarea>
                    <div class="edit-actions">
                        <button class="save-btn">Save</button>
                        <button class="cancel-btn">Cancel</button>
                    </div>`;
                
                const textarea = contentDiv.querySelector('textarea');
                textarea.focus();
                textarea.select();
                
                const save = async () => {
                    const newContent = textarea.value.trim();
                    if (newContent && newContent !== original) {
                        msg.content = newContent;
                        delete msg.prunedContent;
                        
                        const hasAssistantAfter = this.messages.slice(index + 1).some(m => m.role === 'assistant');
                        if (hasAssistantAfter) {
                            // Delete from this user message onwards (like regenerateMessage does)
                            this.messages.splice(index);
                            this.rerenderMessages();
                            
                            // Regenerate response using the pipeline (same as regenerateMessage)
                            const ctx = {
                                userMessage: newContent,
                                attachments: msg.attachments || [],
                                aborted: false
                            };
                            
                            await this.runPipeline([
                                this.stageBuildMessage,
                                this.stageCommitUserMessage,
                                this.stageAutoPruneUser,
                                this.stageVelocity,
                                this.stageRouting,
                                this.stageGenerate,
                                this.stageFinalize
                            ], ctx);
                        } else {
                            this.rerenderMessages();
                        }
                        await this.saveChatToHistory();
                this.playCompletionDing();
                        this.updateTokenUsage();
                    } else {
                        cancel();
                    }
                };
                
                const cancel = () => {
                    contentDiv.innerHTML = originalHTML;
                    if (actionsDiv) actionsDiv.style.display = '';
                    else this.addMessageActions(msgEl, index);
                };
                
                contentDiv.querySelector('.save-btn').addEventListener('click', save);
                contentDiv.querySelector('.cancel-btn').addEventListener('click', cancel);
                textarea.addEventListener('keydown', e => {
                    if (e.key === 'Enter' && e.ctrlKey) {
                        e.preventDefault();
                        save();
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        cancel();
                    }
                });
            }
            
            copyMessage(index) {
                const msg = this.messages[index];
                if (msg) {
                    navigator.clipboard.writeText(msg.content);
                }
            }
            
            async deleteMessage(index) {
                this.messageToDelete = index;
                this.confirmMessage.textContent = 'Are you sure you want to delete this message?';
                this.showDialog(this.confirmDialog);
            }
            
            async regenerateMessage(index) {
                if (this.isStreaming) this.stopStreaming();
                this.hideLoading();
                
                // Find the user message before this assistant message
                let userIndex = -1;
                for (let i = index - 1; i >= 0; i--) {
                    if (this.messages[i].role === 'user') {
                        userIndex = i;
                        break;
                    }
                }
                if (userIndex === -1) return;
                
                // Get the user message content
                const userContent = this.messages[userIndex].content;
                const userAttachments = this.messages[userIndex].attachments || [];
                
                // Delete from the USER message onwards
                this.messages.splice(userIndex);
                this.rerenderMessages();
                
                // Call pipeline directly (don't use sendMessage - it reads from chatInput)
                const ctx = {
                    userMessage: userContent,
                    attachments: userAttachments,
                    aborted: false
                };

                await this.runPipeline([
                    this.stageBuildMessage,
                    this.stageCommitUserMessage,
                    this.stageAutoPruneUser,
                    this.stageVelocity,
                    this.stageRouting,
                    this.stageGenerate,
                    this.stageFinalize
                ], ctx);
            }
            
            
            rerenderMessages() {
                this.messagesContainer.querySelectorAll('.message').forEach(el => el.remove());
                const leftoverStreaming = document.getElementById('streamingMessage');
                if (leftoverStreaming) leftoverStreaming.remove();
                
                this.messages.forEach((msg, i) => {
                    const el = this.createMessageElement(msg.role, msg.content, i, msg.attachments);
                    this.messagesContainer.appendChild(el);
                    this.highlightCodeBlocks(el);
                });
                this.welcomeScreen.classList.toggle('hidden', this.messages.length > 0);
                this.updateTokenUsage();
            }
            
            formatContent(content) {
                content = content.trim();
                
                let html = content
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');
                
                html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, (_, lang, code) => {
                    const language = lang || 'text';
                    const displayLang = language.charAt(0).toUpperCase() + language.slice(1);
                    
                    const areBracketsBalanced = (text) => {
                        const brackets = { '{': '}', '[': ']', '(': ')', '<': '>' };
                        const opening = Object.keys(brackets);
                        const closing = Object.values(brackets);
                        const stack = [];
                        
                        const hasPhpOpen = /&lt;\?php/i.test(text);
                        const hasPhpClose = /\?&gt;/.test(text);
                        if (hasPhpOpen && !hasPhpClose) return false;
                        
                        for (let char of text) {
                            if (opening.includes(char)) stack.push(char);
                            else if (closing.includes(char)) {
                                const last = stack.pop();
                                if (!last || brackets[last] !== char) return false;
                            }
                        }
                        return stack.length === 0;
                    };
                    
                    const lines = code.split('\n');
                    let codeLines = [];
                    let exampleLines = [];
                    let foundSeparator = false;
                    
                    for (const line of lines) {
                        const trimmed = line.trim();
                        
                        const isExampleSeparator = 
                            /^#\s*(Example|Usage|Output)/i.test(trimmed) ||
                            /^\/\/\s*(Example|Usage|Output)/i.test(trimmed) ||
                            /^\/\*\s*(Example|Usage|Output)/i.test(trimmed) ||
                            /^--\s*(Example|Usage|Output)/i.test(trimmed);
                        
                        if (isExampleSeparator && codeLines.length > 0) {
                            const codeBeforeSeparator = codeLines.join('\n');
                            if (areBracketsBalanced(codeBeforeSeparator)) foundSeparator = true;
                        }
                        
                        if (foundSeparator) exampleLines.push(line);
                        else codeLines.push(line);
                    }
                    
                    let result = `<div class="code-block"><div class="code-header"><span class="code-lang">${displayLang}</span><button class="copy-btn" onclick="copyCodeToClipboard(this)" title="Copy code"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button></div><pre><code class="language-${language}">${codeLines.join('\n').trim()}</code></pre></div>`;
                    
                    if (exampleLines.length > 0) {
                        result += `\n\n<div class="code-block"><div class="code-header"><span class="code-lang">${displayLang}</span><button class="copy-btn" onclick="copyCodeToClipboard(this)" title="Copy code"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button></div><pre><code class="language-${language}">${exampleLines.join('\n').trim()}</code></pre></div>`;
                    }
                    
                    return result;
                });
                
                html = html.replace(/(?:^|\n)(\|.+\|)\n(\|[-:| ]+\|)\n((?:\|.+\|\n?)+)/gm, (match, header, separator, body) => {
                    const headers = header.split('|').filter(c => c.trim()).map(c => `<th>${c.trim()}</th>`).join('');
                    const rows = body.trim().split('\n').map(row => {
                        const cells = row.split('|').filter(c => c.trim()).map(c => `<td>${c.trim()}</td>`).join('');
                        return `<tr>${cells}</tr>`;
                    }).join('');
                    return `<table><thead><tr>${headers}</tr></thead><tbody>${rows}</tbody></table>`;
                });
                
                const lines = html.split('\n');
                let result = [], inUl = false, inOl = false;
                
                for (let i = 0; i < lines.length; i++) {
                    const line = lines[i], bulletMatch = line.match(/^[*\-] (.+)$/), numberMatch = line.match(/^\d+\. (.+)$/);
                    
                    if (bulletMatch) {
                        if (!inUl) { if (inOl) { result.push('</ol>'); inOl = false; } result.push('<ul>'); inUl = true; }
                        result.push(`<li>${bulletMatch[1]}</li>`);
                    } else if (numberMatch) {
                        if (!inOl) { if (inUl) { result.push('</ul>'); inUl = false; } result.push('<ol>'); inOl = true; }
                        result.push(`<li>${numberMatch[1]}</li>`);
                    } else if (line.trim() === '' && (inUl || inOl) && lines.slice(i + 1).find(l => l.trim())?.match(/^([*\-]|\d+\.) /)) {
                        continue;
                    } else {
                        if (inUl) { result.push('</ul>'); inUl = false; }
                        if (inOl) { result.push('</ol>'); inOl = false; }
                        result.push(line);
                    }
                }
                if (inUl) result.push('</ul>');
                if (inOl) result.push('</ol>');
                
                html = result.join('\n');
                
                html = html.replace(/^### (.+)$/gm, '<h4>$1</h4>');
                html = html.replace(/^## (.+)$/gm, '<h3>$1</h3>');
                html = html.replace(/^# (.+)$/gm, '<h2>$1</h2>');
                html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
                html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
                html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                html = html.replace(/\*([^*]+?)\*/g, '<em>$1</em>');
                
                const codeBlocks = [];
                html = html.replace(/<pre><code[^>]*>[\s\S]*?<\/code><\/pre>/g, (match) => {
                    codeBlocks.push(match);
                    return `__CODE_BLOCK_${codeBlocks.length - 1}__`;
                });
                html = html.replace(/\n/g, '<br>');
                codeBlocks.forEach((block, i) => {
                    html = html.replace(`__CODE_BLOCK_${i}__`, block);
                });
                html = html.replace(/<\/(table|ul|ol|pre|h[2-4])><br>/g, '</$1>');
                html = html.replace(/<br><(table|ul|ol|pre|h[2-4])/g, '<$1');
                
                return html;
            }
            
            highlightCodeBlocks(element) {
                if (typeof hljs !== 'undefined') {
                    element.querySelectorAll('pre code').forEach(block => hljs.highlightElement(block));
                }
            }
            
            showLoading() {
                const div = document.createElement('div');
                div.className = 'message assistant-message';
                div.id = 'loadingIndicator';
                div.innerHTML = `
                    <div class="message-content">
                        <div style="display:flex;align-items:center;gap:.75rem">
                            <svg class="spinner" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,19a8,8,0,1,1,8-8A8,8,0,0,1,12,20Z" opacity=".25"/>
                                <path d="M10.14,1.16a11,11,0,0,0-9,8.92A1.59,1.59,0,0,0,2.46,12,1.52,1.52,0,0,0,4.11,10.7a8,8,0,0,1,6.66-6.61A1.42,1.42,0,0,0,12,2.69h0A1.57,1.57,0,0,0,10.14,1.16Z"/>
                            </svg>
                            <span style="color:var(--gray-400)">Thinking...</span>
                        </div>
                    </div>`;
                this.messagesContainer.appendChild(div);
                this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
            }
            
            hideLoading() {
                document.getElementById('loadingIndicator')?.remove();
            }
            
            async createNewChat() {
                if (this.messages.length > 0) await this.saveChatToHistory();
                this.playCompletionDing();
                
                this.currentChatId = this.generateId();
                this.messages = [];
                this.tokenTracker.savedTokens = 0;
                this.tokenTracker.prunedMessages = new Set();
                this.tokenTracker.lastPrunedIndex = -1;
                this.tokenTracker.unableToPrune = false;
                
                // Clear velocity index
                this.velocityIndex = [];
                this.recalledMessage = null;
                this.velocityRecallCount = 0;
                this.updateVelocityStatsUI();
                
                this.messagesContainer.querySelectorAll('.message').forEach(el => el.remove());
                this.clearInputTokenDisplay();
                this.welcomeScreen.classList.remove('hidden');
                this.updateUI();
                this.updateTokenUsage();
                this.updateStatus('');
                this.chatInput.value = '';
                this.adjustTextareaHeight();
                this.updateSendButton();
                
                if (window.innerWidth < 1024) this.sidebar.classList.remove('active');
            }
            
            async saveChatToHistory() {
                if (!this.messages.length) return;
                
                const firstMsg = this.messages[0].content;
                const title = firstMsg.substring(0, 50) + (firstMsg.length > 50 ? '...' : '');
                
                const data = await this.apiCall('save_chat', {
                    chatId: this.currentChatId,
                    title,
                    messages: JSON.parse(JSON.stringify(this.messages)),
                    tokenData: {
                        savedTokens: this.tokenTracker.savedTokens,
                        prunedMessages: [...this.tokenTracker.prunedMessages],
                        lastPrunedIndex: this.tokenTracker.lastPrunedIndex
                    }
                });
                
                if (data.success) {
                    this.currentChatId = data.chatId;
                    await this.loadChatHistory();
                }
            }
            
            async loadChatHistory() {
                const data = await this.apiCall('get_chats', {});
                if (data.success) {
                    this.renderChatList(data.chats);
                }
            }
            
            renderChatList(chats) {
                this.chatsList.innerHTML = '';
                
                if (chats.length === 0) {
                    const empty = document.createElement('div');
                    empty.style.cssText = 'padding:2rem 1rem;text-align:center;color:var(--gray-500);font-size:.875rem';
                    empty.textContent = 'No chats yet';
                    this.chatsList.appendChild(empty);
                    return;
                }
                
                const today = new Date().toDateString();
                const yesterday = new Date(Date.now() - 86400000).toDateString();
                
                const groups = { 'Today': [], 'Yesterday': [], 'Previous': [] };
                
                chats.forEach(chat => {
                    const chatDate = new Date(chat.timestamp).toDateString();
                    if (chatDate === today) groups['Today'].push(chat);
                    else if (chatDate === yesterday) groups['Yesterday'].push(chat);
                    else groups['Previous'].push(chat);
                });
                
                Object.entries(groups).forEach(([title, groupChats]) => {
                    if (groupChats.length === 0) return;
                    
                    const group = document.createElement('div');
                    group.innerHTML = `<div class="chat-date">${title}</div>`;
                    groupChats.forEach(chat => group.appendChild(this.createChatItem(chat)));
                    this.chatsList.appendChild(group);
                });
                
                this.markActiveChat();
            }
            
            createChatItem(chat) {
                const item = document.createElement('div');
                item.className = 'chat-item';
                item.dataset.chatId = chat.id;
                item.innerHTML = `
                    <div class="chat-item-content">
                        <div class="chat-title">${this.escapeHtml(chat.title)}</div>
                        <div class="chat-preview">${this.escapeHtml(chat.lastMessage || '')}</div>
                    </div>
                    <div class="chat-actions">
                        <button class="delete-btn" title="Delete">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/>
                            </svg>
                        </button>
                    </div>`;
                
                item.addEventListener('click', e => {
                    if (!e.target.closest('.delete-btn')) this.loadChat(chat.id);
                });
                item.querySelector('.delete-btn').addEventListener('click', e => {
                    e.stopPropagation();
                    this.showChatDeleteDialog(chat);
                });
                
                return item;
            }
            
            markActiveChat() {
                document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
                document.querySelector(`.chat-item[data-chat-id="${this.currentChatId}"]`)?.classList.add('active');
            }
            
            async loadChat(chatId) {
                // Show overlay IMMEDIATELY before API call
                document.getElementById('tokenizationOverlay').classList.add('active');
                
                const data = await this.apiCall('load_chat', { chatId });
                
                if (data.success) {
                    const chat = data.chat;
                    this.currentChatId = chat.id;
                    this.messages = JSON.parse(JSON.stringify(chat.messages));
                    this.tokenTracker.savedTokens = chat.tokenData?.savedTokens || 0;
                    this.tokenTracker.prunedMessages = new Set(chat.tokenData?.prunedMessages || []);
                    this.tokenTracker.lastPrunedIndex = chat.tokenData?.lastPrunedIndex ?? -1;
                    this.tokenTracker.unableToPrune = false;
                    
                    this.clearInputTokenDisplay();
                    this.messagesContainer.querySelectorAll('.message').forEach(el => el.remove());
                    
                    this.messages.forEach((msg, i) => {
                        const el = this.createMessageElement(msg.role, msg.content, i, msg.attachments);
                        this.messagesContainer.appendChild(el);
                        this.highlightCodeBlocks(el);
                    });
                    
                    this.welcomeScreen.classList.toggle('hidden', this.messages.length > 0);
                    this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
                    
                    if (window.innerWidth < 1024) this.sidebar.classList.remove('active');
                    
                    this.markActiveChat();
                    this.updateUI();
                    
                    await this.updateTokenUsage();
                    
                    // Hide tokenization overlay
                    document.getElementById('tokenizationOverlay').classList.remove('active');
                    
                    this.updateStatus('');
                    this.chatInput.value = '';
                    this.adjustTextareaHeight();
                    this.updateSendButton();
                }
            }
            
            filterChats(query) {
                const items = this.chatsList.querySelectorAll('.chat-item');
                const dates = this.chatsList.querySelectorAll('.chat-date');
                
                if (!query.trim()) {
                    items.forEach(el => el.style.display = '');
                    dates.forEach(el => el.style.display = '');
                    this.chatsList.querySelector('.no-results')?.remove();
                    return;
                }
                
                const term = query.toLowerCase();
                dates.forEach(el => el.style.display = 'none');
                let hasVisible = false;
                
                items.forEach(item => {
                    const title = item.querySelector('.chat-title').textContent.toLowerCase();
                    const preview = item.querySelector('.chat-preview').textContent.toLowerCase();
                    const visible = title.includes(term) || preview.includes(term);
                    item.style.display = visible ? '' : 'none';
                    
                    if (visible) {
                        hasVisible = true;
                        const dateHeader = item.previousElementSibling;
                        if (dateHeader?.classList.contains('chat-date')) {
                            dateHeader.style.display = '';
                        }
                    }
                });
                
                const noResults = this.chatsList.querySelector('.no-results');
                if (!hasVisible && !noResults) {
                    const div = document.createElement('div');
                    div.className = 'no-results';
                    div.style.cssText = 'padding:2rem 1rem;text-align:center;color:var(--gray-500)';
                    div.textContent = 'No chats found';
                    this.chatsList.appendChild(div);
                } else if (hasVisible && noResults) {
                    noResults.remove();
                }
            }
            
            showChatDeleteDialog(chat) {
                this.chatToDelete = chat;
                this.confirmMessage.textContent = `Are you sure you want to delete "${chat.title}"?`;
                this.showDialog(this.confirmDialog);
            }
            
            async deleteChatConfirmed() {
                if (this.messageToDelete !== null) {
                    this.messages.splice(this.messageToDelete, 1);
                    this.rerenderMessages();
                    await this.saveChatToHistory();
                this.playCompletionDing();
                    await this.updateTokenUsage();
                    this.hideDialog(this.confirmDialog);
                    this.messageToDelete = null;
                    return;
                }
                if (!this.chatToDelete) return;
                
                const data = await this.apiCall('delete_chat', { chatId: this.chatToDelete.id });
                
                if (data.success) {
                    if (this.currentChatId === this.chatToDelete.id) {
                        this.messages = [];
                        await this.createNewChat();
                    }
                    await this.loadChatHistory();
                    this.hideDialog(this.confirmDialog);
                    this.chatToDelete = null;
                }
            }
            
            toggleSidebar() {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                this.app.classList.toggle('sidebar-collapsed', this.sidebarCollapsed);
                if (window.innerWidth < 1024) {
                    this.sidebar.classList.toggle('active', !this.sidebarCollapsed);
                }
            }
            
            openSettings() {
                this.settingsPanel.classList.add('open');
                this.updateSettingsUI();
                this.loadGovernorSettings();
                if (window.innerWidth <= 1024) {
                    this.sidebarCollapsed = true;
                    this.app.classList.add('sidebar-collapsed');
                    this.sidebar.classList.remove('active');
                }
            }
            
            closeSettings() {
                this.settingsPanel.classList.remove('open');
            }
            
            async loadGovernorSettings() {
                // Load VRAM info
                this.vramValue.textContent = 'Detecting...';
                this.vramValue.classList.add('vram-loading');
                this.vramGpu.textContent = '';
                
                const vramData = await this.apiCall('detect_vram');
                if (vramData.success) {
                    this.vramInfo = vramData.vram;
                    this.vramValue.textContent = `${vramData.vram.free} MB free / ${vramData.vram.total} MB total`;
                    this.vramValue.classList.remove('vram-loading', 'vram-error');
                    this.vramGpu.textContent = vramData.gpu;
                } else {
                    this.vramValue.textContent = vramData.error || 'Detection failed';
                    this.vramValue.classList.remove('vram-loading');
                    this.vramValue.classList.add('vram-error');
                }
                
                // Load available models
                const modelsData = await this.apiCall('scan_models');
                if (modelsData.success) {
                    this.availableModels = modelsData.models;
                    this.populateModelDropdowns();
                }
                
                // Load governor config
                const configData = await this.apiCall('load_governor_config');
                if (configData.success) {
                    // Build experts array from config
                    this.governorConfig = {
                        experts: [],
                        auxModel: configData.config.aux_model || '',
                        auxContextLength: configData.config.aux_context_length || 2048,
                        auxCpuOnly: configData.config.aux_cpu_only !== false,
                        auxPort: configData.config.aux_port || 8081,
                        expertPort: configData.config.expert_port || 8080,
                        velocityEnabled: configData.config.velocity_enabled !== false,
                        velocityThreshold: configData.config.velocity_threshold || 40,
                        velocityCharThreshold: configData.config.velocity_char_threshold || 1500,
                        velocityIndexPrompt: configData.config.velocity_index_prompt || this.governorConfig?.velocityIndexPrompt || '',
                        velocityRecallPrompt: configData.config.velocity_recall_prompt || this.governorConfig?.velocityRecallPrompt || ''
                    };
                    
                    // Load 10 expert slots
                    const defaultNames = ['Text', 'Code', 'Medical', 'Electrical', 'Vehicle', 'Expert 6', 'Expert 7', 'Expert 8', 'Expert 9', 'Expert 10'];
                    const defaultEnabled = [true, true, true, true, true, false, false, false, false, false];
                    
                    for (let i = 1; i <= 10; i++) {
                        this.governorConfig.experts.push({
                            model: configData.config[`expert_${i}_model`] || '',
                            name: configData.config[`expert_${i}_name`] || defaultNames[i-1],
                            enabled: configData.config[`expert_${i}_enabled`] !== undefined ? configData.config[`expert_${i}_enabled`] : defaultEnabled[i-1],
                            systemPrompt: configData.config[`expert_${i}_system_prompt`] || 'You are a helpful assistant. Provide detailed, accurate responses.'
                        });
                    }
                    
                    this.pruningConfig.enabled = configData.config.enable_pruning !== false;
                    this.pruningConfig.threshold = configData.config.prune_threshold || 1500;
                    this.pruningConfig.prunePrompt = configData.config.prune_prompt || this.pruningConfig.prunePrompt;
                    
                    this.updateGovernorUI();
                    this.updateSettingsUI();
                }
                
                await this.checkServerStatus();
            }
            
            populateModelDropdowns() {
                // Populate aux model dropdown
                const auxDropdown = this.governorAuxModel;
                if (auxDropdown) {
                    const currentValue = auxDropdown.value;
                    auxDropdown.innerHTML = '<option value="">Select a model...</option>';
                    
                    this.availableModels.forEach(model => {
                        const option = document.createElement('option');
                        option.value = model.filename;
                        option.textContent = model.filename;
                        option.dataset.size = model.sizeFormatted;
                        auxDropdown.appendChild(option);
                    });
                    
                    if (currentValue) auxDropdown.value = currentValue;
                }
                
                // Populate expert model dropdowns
                for (let i = 1; i <= 10; i++) {
                    const dropdown = this.$(`expert${i}Model`);
                    if (dropdown) {
                        const currentValue = dropdown.value;
                        dropdown.innerHTML = '<option value="">Select a model...</option>';
                        
                        this.availableModels.forEach(model => {
                            const option = document.createElement('option');
                            option.value = model.filename;
                            option.textContent = model.filename;
                            option.dataset.size = model.sizeFormatted;
                            dropdown.appendChild(option);
                        });
                        
                        if (currentValue) dropdown.value = currentValue;
                    }
                }
            }
            
            updateGovernorUI() {
                // Update aux model UI
                if (this.governorAuxModel) this.governorAuxModel.value = this.governorConfig.auxModel;
                if (this.governorAuxCpuOnly) this.governorAuxCpuOnly.checked = this.governorConfig.auxCpuOnly;
                if (this.auxContextLength) this.auxContextLength.value = this.governorConfig.auxContextLength || 2048;
                
                // Update 10 expert slot UIs
                if (this.governorConfig.experts) {
                    for (let i = 1; i <= 10; i++) {
                        const expert = this.governorConfig.experts[i-1];
                        if (!expert) continue;
                        
                        const modelSelect = this.$(`expert${i}Model`);
                        const nameInput = this.$(`expert${i}Name`);
                        const enabledCheckbox = this.$(`expert${i}Enabled`);
                        const systemPrompt = this.$(`expert${i}SystemPrompt`);
                        const promptLabel = this.$(`expert${i}PromptLabel`);
                        const enabledLabel = enabledCheckbox?.nextElementSibling;
                        
                        if (modelSelect) modelSelect.value = expert.model;
                        if (nameInput) nameInput.value = expert.name;
                        if (enabledCheckbox) enabledCheckbox.checked = expert.enabled;
                        if (systemPrompt) systemPrompt.value = expert.systemPrompt;
                        if (promptLabel) promptLabel.textContent = `${expert.name} Expert Prompt`;
                        if (enabledLabel) enabledLabel.textContent = expert.name;
                    }
                }
                
                // Velocity Index settings
                if (this.velocityEnabled) {
                    this.velocityEnabled.checked = this.governorConfig.velocityEnabled !== false;
                }
                if (this.velocityThreshold) {
                    this.velocityThreshold.value = this.governorConfig.velocityThreshold || 40;
                }
                if (this.velocityCharThreshold) {
                    this.velocityCharThreshold.value = this.governorConfig.velocityCharThreshold || 1500;
                }
                if (this.velocityIndexPrompt) {
                    this.velocityIndexPrompt.value = this.governorConfig.velocityIndexPrompt || '';
                }
                if (this.velocityRecallPrompt) {
                    this.velocityRecallPrompt.value = this.governorConfig.velocityRecallPrompt || '';
                }
                
                this.updateModelSizeDisplay();
                this.updateVelocityStatsUI();
            }
            
            updateModelSizeDisplay() {
                const getSize = (filename) => {
                    const model = this.availableModels.find(m => m.filename === filename);
                    return model ? model.sizeFormatted : '';
                };
                
                // Update aux model size
                const auxSizeEl = this.$('auxModelSize');
                if (auxSizeEl && this.governorAuxModel) {
                    auxSizeEl.textContent = getSize(this.governorAuxModel.value);
                }
                
                // Update 10 expert model sizes
                for (let i = 1; i <= 10; i++) {
                    const sizeEl = this.$(`expert${i}ModelSize`);
                    const modelSelect = this.$(`expert${i}Model`);
                    if (sizeEl && modelSelect) {
                        sizeEl.textContent = getSize(modelSelect.value);
                    }
                }
            }
            
            // ===== SERVER MANAGEMENT =====
            
            showStartupOverlay(title, message) {
                this.startupTitle.textContent = title;
                this.startupMessage.textContent = message;
                this.startupError.style.display = 'none';
                this.startupError.textContent = '';
                this.startupOverlay.classList.add('active');
            }
            
            hideStartupOverlay() {
                this.startupOverlay.classList.remove('active');
            }
            
            showStartupError(error) {
                this.startupError.textContent = error;
                this.startupError.style.display = 'block';
            }
            
            updateServerStatusUI(type, status) {
                const element = type === 'aux' ? this.auxServerStatus : this.expertServerStatus;
                if (!element) return;
                
                element.classList.remove('online', 'offline', 'starting', 'error');
                
                if (status.running && status.healthy) {
                    element.classList.add('online');
                    if (type === 'expert' && this.currentExpert) {
                        element.innerHTML = `<span class="server-status-dot"></span><span>${this.currentExpert}</span>`;
                    } else {
                        element.innerHTML = `<span class="server-status-dot"></span><span>${type === 'aux' ? 'Aux' : 'Expert'}</span>`;
                    }
                } else if (status.running && !status.healthy) {
                    element.classList.add('starting');
                    element.innerHTML = `<span class="server-status-dot"></span><span>${type === 'aux' ? 'Aux' : 'Expert'}...</span>`;
                } else if (status.starting) {
                    element.classList.add('starting');
                    element.innerHTML = `<span class="server-status-dot"></span><span>${type === 'aux' ? 'Aux' : 'Expert'}...</span>`;
                } else {
                    element.classList.add('offline');
                    element.innerHTML = `<span class="server-status-dot"></span><span>${type === 'aux' ? 'Aux' : 'Expert'}</span>`;
                }
            }
            
            async checkServerStatus() {
                const data = await this.apiCall('server_status');
                if (data.success) {
                    data.servers.forEach(server => {
                        this.updateServerStatusUI(server.type, server);
                    });
                    return data.servers;
                }
                return [
                    { type: 'aux', running: false, healthy: false },
                    { type: 'expert', running: false, healthy: false }
                ];
            }
            
            async startAuxServer() {
                const contextSize = this.governorConfig.auxContextLength || 2048;
                
                const result = await this.apiCall('start_server', {
                    type: 'aux',
                    model: this.governorConfig.auxModel,
                    port: this.governorConfig.auxPort,
                    cpu_only: this.governorConfig.auxCpuOnly,
                    context_size: contextSize
                });
                
                if (result.success) {
                    return result;
                } else {
                    throw new Error(result.error || 'Failed to start auxiliary server');
                }
            }
            
            async startExpertServer(expertType) {
                // expertType is now the name of the expert (e.g., 'CODE', 'TEXT', 'ELECTRICAL', etc.)
                // Find the expert by name (case-insensitive)
                const expertName = expertType.toUpperCase();
                const expert = this.governorConfig.experts?.find(e => 
                    e.name.toUpperCase() === expertName && e.enabled && e.model
                );
                
                // If not found by name, try to find by index (e.g., 'EXPERT_1')
                let model = expert?.model;
                if (!model && expertName.startsWith('EXPERT_')) {
                    const index = parseInt(expertName.split('_')[1]) - 1;
                    if (index >= 0 && index < this.governorConfig.experts?.length) {
                        const indexedExpert = this.governorConfig.experts[index];
                        if (indexedExpert.enabled && indexedExpert.model) {
                            model = indexedExpert.model;
                        }
                    }
                }
                
                if (!model) {
                    // Fallback: find any enabled expert with a model
                    const fallbackExpert = this.governorConfig.experts?.find(e => e.enabled && e.model);
                    model = fallbackExpert?.model;
                }
                
                if (!model) {
                    throw new Error(`No ${expertType.toLowerCase()} model configured`);
                }
                
                // Check if we need to calibrate this model
                const contextSize = await this.getOptimalContext(model);
                
                const result = await this.apiCall('start_server', {
                    type: 'expert',
                    model: model,
                    port: this.governorConfig.expertPort,
                    cpu_only: false,
                    context_size: contextSize
                });
                
                if (result.success) {
                    this.currentExpert = expertType;
                    return result;
                } else {
                    throw new Error(result.error || `Failed to start ${expertType} expert server`);
                }
            }
            
            async getOptimalContext(modelFilename) {
                const cacheData = await this.apiCall('get_context_cache');
                const cache = cacheData.success ? (cacheData.cache || {}) : {};
                
                if (cache[modelFilename]) {
                    return cache[modelFilename].max_context;
                }
                
                this.updateStatus('Learning GPU...', 'learning');
                
                try {
                    const calibrationData = { model: modelFilename };
                    
                    if (this.vramInfo && this.vramInfo.total) {
                        calibrationData.vram = { total: this.vramInfo.total };
                    }
                    
                    const result = await this.apiCall('calibrate_context', calibrationData);
                    
                    if (result.success) {
                        return result.max_context;
                    } else {
                        return this.calculateSafeContext(modelFilename);
                    }
                } catch (error) {
                    return this.calculateSafeContext(modelFilename);
                }
            }
            
            async calculateSafeContext(modelFilename) {
                // Step 1: Check if we have a calibrated value for this model
                const cacheResult = await this.apiCall('get_cached_context', { model: modelFilename });
                if (cacheResult.success && cacheResult.cached_context) {
                    console.log('[Governor] Using cached context for', modelFilename, ':', cacheResult.cached_context);
                    return cacheResult.cached_context;
                }
                
                // Step 2: Fall back to conservative calculation
                const modelInfo = this.availableModels.find(m => m.filename === modelFilename);
                if (!modelInfo) {
                    console.error('[Governor] Model not found:', modelFilename);
                    return 2048;
                }
                
                const modelSizeBytes = modelInfo.size;
                const modelSizeMB = modelSizeBytes / (1024 * 1024);
                
                if (!this.vramInfo?.total) {
                    console.error('[Governor] VRAM not detected, using conservative context');
                    return 2048;
                }
                
                // Conservative calculation (15% VRAM headroom)
                const vramTotalMB = this.vramInfo.total;
                const vramUsableMB = vramTotalMB * 0.85;
                
                // Step 2: Account for model weight memory
                const vramAfterModelMB = vramUsableMB - modelSizeMB;
                
                if (vramAfterModelMB < 100) {
                    console.warn('[Governor] Insufficient VRAM for this model');
                    return 512; // Minimum viable context
                }
                
                // Step 3: Estimate embedding dimensions from model size
                // Rough heuristics based on common architectures:
                // ~1B params  ≈ 2048 dim  (e.g., DeepSeek-Coder 1.3B, Gemma-3 1B)
                // ~2B params  ≈ 2304 dim  (e.g., Gemma-2 2B)
                // ~3B params  ≈ 2560 dim  (e.g., Phi-3)
                // ~7B params  ≈ 4096 dim  (e.g., Llama-2/3 7B, Mistral 7B)
                // ~13B params ≈ 5120 dim  (e.g., Llama-2 13B)
                // ~70B params ≈ 8192 dim  (e.g., Llama-2/3 70B)
                
                let estimatedDim, estimatedLayers;
                const modelSizeGB = modelSizeMB / 1024;
                
                if (modelSizeGB < 0.8) {
                    // < 1B params
                    estimatedDim = 1536;
                    estimatedLayers = 18;
                } else if (modelSizeGB < 1.8) {
                    // ~1B params
                    estimatedDim = 2048;
                    estimatedLayers = 24;
                } else if (modelSizeGB < 2.5) {
                    // ~2B params
                    estimatedDim = 2304;
                    estimatedLayers = 26;
                } else if (modelSizeGB < 3.5) {
                    // ~3B params
                    estimatedDim = 2560;
                    estimatedLayers = 28;
                } else if (modelSizeGB < 5.5) {
                    // ~7B params
                    estimatedDim = 4096;
                    estimatedLayers = 32;
                } else if (modelSizeGB < 10) {
                    // ~13B params
                    estimatedDim = 5120;
                    estimatedLayers = 40;
                } else if (modelSizeGB < 25) {
                    // ~30B params
                    estimatedDim = 6656;
                    estimatedLayers = 48;
                } else {
                    // 70B+ params
                    estimatedDim = 8192;
                    estimatedLayers = 80;
                }
                
                // Step 4: Calculate KV cache memory requirements
                // KV cache formula (for FP16):
                // bytes_per_token = 2 * layers * (k_dim + v_dim) * 2 bytes
                // For most models: k_dim = v_dim = n_embd / n_heads * n_heads_kv
                // Simplified: bytes_per_token ≈ 2 * layers * n_embd * 2
                //
                // Additional factors:
                // - Modern models use GQA (grouped query attention), reducing KV cache
                // - n_parallel=4 (default) multiplies cache by 4 for batch processing
                // - llama.cpp uses FP16 for KV cache (2 bytes per value)
                
                const n_parallel = 4; // llama-server default
                
                // Base calculation: 2 layers (K and V) * 2 bytes (FP16)
                const bytesPerTokenBase = estimatedLayers * estimatedDim * 2 * 2;
                
                // Account for GQA reduction (modern models use ~25-50% less KV cache)
                const gqaFactor = modelSizeGB < 3 ? 0.6 : 0.75; // Smaller models often have more aggressive GQA
                const bytesPerToken = bytesPerTokenBase * gqaFactor;
                
                // Total KV cache size for batch processing
                const bytesPerContextToken = bytesPerToken * n_parallel;
                
                // Step 5: Calculate maximum context from available VRAM
                const vramForKvCacheMB = vramAfterModelMB * 0.9; // Reserve 10% for overhead
                const vramForKvCacheBytes = vramForKvCacheMB * 1024 * 1024;
                
                let maxContext = Math.floor(vramForKvCacheBytes / bytesPerContextToken);
                
                // Step 6: Apply practical constraints
                // Clamp to reasonable minimums and maximums
                maxContext = Math.max(512, maxContext);
                maxContext = Math.min(131072, maxContext); // Max 128k context
                
                // Also respect common model training context limits to avoid warnings
                // Most models are trained on specific context lengths
                const commonContextLimits = [2048, 4096, 8192, 16384, 32768, 65536, 131072];
                if (modelSizeGB < 2) {
                    // Small models usually trained on 8k or less
                    maxContext = Math.min(maxContext, 8192);
                } else if (modelSizeGB < 5) {
                    // Medium models often 16k
                    maxContext = Math.min(maxContext, 16384);
                }
                
                // Round down to nearest 256 for alignment
                maxContext = Math.floor(maxContext / 256) * 256;
                
                return maxContext;
            }
            
            async switchExpert(newExpertType) {
                if (this.currentExpert === newExpertType) return true;
                
                this.updateStatus('Switching models...', 'switching');
                
                await this.apiCall('stop_server', { type: 'expert' });
                await new Promise(r => setTimeout(r, 1500));
                
                await this.startExpertServer(newExpertType);
                
                // Clear Learning GPU status immediately after server starts
                this.updateStatus('');
                
                for (let i = 0; i < 60; i++) {
                    const status = await this.apiCall('server_status');
                    const expertStatus = status.servers?.find(s => s.type === 'expert');
                    if (expertStatus?.running && expertStatus?.healthy) {
                        this.updateServerStatusUI('expert', expertStatus);
                        await this.initModelInfo();
                        return true;
                    }
                    await new Promise(r => setTimeout(r, 1000));
                }
                console.error(`[Governor] Expert server failed to become healthy after 60s`);
                return false;
            }
            
            async routeQuery(message) {
                // Get enabled experts with models
                const enabledExperts = this.governorConfig.experts?.filter(e => e.enabled && e.model) || [];
                
                if (!this.governorConfig.auxModel || enabledExperts.length === 0) {
                    // Return first enabled expert with a model, or default to slot 1
                    const firstEnabled = enabledExperts[0];
                    return firstEnabled ? firstEnabled.name.toUpperCase() : 'EXPERT_1';
                }
                
                if (enabledExperts.length === 1) {
                    return enabledExperts[0].name.toUpperCase();
                }
                
                this.updateStatus('Finding expert...', 'routing');
                
                // Pass expert names for dynamic routing
                const expertNames = enabledExperts.map(e => e.name.toUpperCase());
                const result = await this.auxApiCall('route_query', { 
                    message,
                    expert_names: expertNames
                });
                
                if (result.success && result.route) {
                    return result.route;
                }
                
                // Fallback to first enabled expert
                return enabledExperts[0]?.name.toUpperCase() || 'EXPERT_1';
            }
            
            // ===== VELOCITY INDEX =====
            
            async velocityIndexCheck() {
                // Check if velocity indexing is enabled and aux model is available
                if (!this.governorConfig.velocityEnabled || !this.governorConfig.auxModel) {
                    return;
                }
                
                // Calculate current context usage
                const contextPct = (this.tokenTracker.currentTokens / this.tokenTracker.maxContextLength) * 100;
                
                if (contextPct <= this.governorConfig.velocityThreshold) return;
                
                while (true) {
                    const charThreshold = this.governorConfig.velocityCharThreshold || 1500;
                    const firstActiveIndex = this.messages.findIndex(m => 
                        !m.velocityIndexed && m.content.length >= charThreshold
                    );
                    
                    if (firstActiveIndex === -1) break;
                    
                    const messageToIndex = this.messages[firstActiveIndex];
                    this.updateStatus('Indexing...', 'indexing');
                    
                    const contentToTitle = messageToIndex.fullContent || messageToIndex.content;
                    const titleResult = await this.auxApiCall('velocity_create_title', { message: contentToTitle });
                    
                    if (titleResult.success && titleResult.title) {
                        messageToIndex.velocityIndexed = true;
                        messageToIndex.velocityTitle = titleResult.title;
                        
                        this.velocityIndex.push({
                            index: firstActiveIndex,
                            title: titleResult.title,
                            message: messageToIndex,
                            originalIndex: this.velocityIndex.length
                        });
                        
                        await this.saveChatToHistory();
                this.playCompletionDing();
                        await this.updateTokenUsage();
                        this.rerenderMessages();
                        this.updateVelocityStatsUI();
                        
                        const newContextPct = (this.tokenTracker.currentTokens / this.tokenTracker.maxContextLength) * 100;
                        if (newContextPct <= this.governorConfig.velocityThreshold) break;
                    } else {
                        break;
                    }
                }
                
                this.updateStatus('');
            }
            
            async velocityFindRelevant(userMessage) {
                if (!this.governorConfig.velocityEnabled || this.velocityIndex.length === 0) return null;
                
                const titles = this.velocityIndex.map((item, idx) => ({
                    index: idx,
                    title: item.title
                }));
                
                this.updateStatus('Searching index...', 'searching');
                
                const result = await this.auxApiCall('velocity_find_relevant', {
                    message: userMessage,
                    titles: titles
                });
                
                this.updateStatus('');
                
                if (result.success && result.relevant_index !== null) {
                    return this.velocityIndex[result.relevant_index];
                }
                return null;
            }
            
            async velocityRecall(indexedItem) {
                if (!indexedItem || !indexedItem.message) return;
                
                indexedItem.message.velocityRecalled = true;
                this.recalledMessage = indexedItem;
                this.velocityRecallCount++;
                
                this.updateVelocityStatsUI();
                this.rerenderMessages();
            }
            
            velocityClearRecall() {
                // Clear any previously recalled message
                if (this.recalledMessage && this.recalledMessage.message) {
                    this.recalledMessage.message.velocityRecalled = false;
                    this.recalledMessage = null;
                    this.rerenderMessages();
                }
            }
            
            updateVelocityStatsUI() {
                if (this.velocityStats) {
                    this.velocityStats.style.display = this.velocityIndex.length > 0 ? 'flex' : 'none';
                }
                if (this.velocityIndexedCount) {
                    this.velocityIndexedCount.textContent = this.velocityIndex.length;
                }
                if (this.velocityRecallCount) {
                    this.velocityRecallCount.textContent = this.velocityRecallCount;
                }
            }
            
            async stopServer(type) {
                const result = await this.apiCall('stop_server', { type });
                if (result.success) {
                    this.updateServerStatusUI(type, { running: false });
                    if (type === 'expert') {
                        this.currentExpert = null;
                    }
                }
                return result;
            }
            
            async calibrateModel(modelFilename) {
                console.log('[Governor] Starting calibration for', modelFilename);
                this.updateStatus('Calibrating ' + modelFilename + '...', 'calibrating');
                
                const result = await this.apiCall('calibrate_context', { model: modelFilename });
                
                if (result.success) {
                    console.log('[Governor] Calibration complete:', result);
                    this.updateStatus('');
                    alert(`Calibration complete!\n\nMax context: ${result.max_context} tokens\nMethod: ${result.method}\nAttempts: ${result.attempts || 'N/A'}`);
                } else {
                    console.error('[Governor] Calibration failed:', result);
                    this.updateStatus('');
                    alert('Calibration failed: ' + (result.error || 'Unknown error'));
                }
                
                return result;
            }
            
            async initializeGovernor() {
                if (!this.governorConfig.auxModel) return;
                
                // Detect VRAM first - required for context size calculations
                if (!this.vramInfo) {
                    const vramData = await this.apiCall('detect_vram');
                    if (vramData.success) {
                        this.vramInfo = vramData.vram;
                    }
                }
                
                // Ensure models are scanned so we know their sizes
                if (this.availableModels.length === 0) {
                    const modelsData = await this.apiCall('scan_models');
                    if (modelsData.success) {
                        this.availableModels = modelsData.models;
                    }
                }
                
                this.showStartupOverlay('Starting Servers...', 'Initializing the auxiliary model');
                
                await this.apiCall('start_server', {
                    type: 'aux',
                    model: this.governorConfig.auxModel,
                    port: this.governorConfig.auxPort,
                    cpu_only: this.governorConfig.auxCpuOnly,
                    context_size: this.governorConfig.auxContextLength || 2048
                });
                
                await new Promise(r => setTimeout(r, 4000));
                
                let auxHealthy = false;
                for (let i = 0; i < 60; i++) {
                    const result = await this.apiCall('server_status');
                    const auxStatus = result.servers?.find(s => s.type === 'aux');
                    if (auxStatus?.running && auxStatus?.healthy) {
                        this.updateServerStatusUI('aux', auxStatus);
                        auxHealthy = true;
                        break;
                    }
                    await new Promise(r => setTimeout(r, 1000));
                }
                
                if (auxHealthy) {
                    // Find the first enabled expert with a model
                    const firstExpert = this.governorConfig.experts?.find(e => e.enabled && e.model);
                    
                    if (firstExpert) {
                        this.showStartupOverlay('Starting Servers...', `Initializing the ${firstExpert.name} expert model`);
                        
                        await this.startExpertServer(firstExpert.name.toUpperCase());
                        
                        // Clear Learning GPU status immediately after server starts
                        this.updateStatus('');
                        
                        await new Promise(r => setTimeout(r, 4000));
                        
                        for (let i = 0; i < 60; i++) {
                            const result = await this.apiCall('server_status');
                            const expertStatus = result.servers?.find(s => s.type === 'expert');
                            if (expertStatus?.running && expertStatus?.healthy) {
                                this.updateServerStatusUI('expert', expertStatus);
                                // Update context length from expert
                                await this.initModelInfo();
                                break;
                            }
                            await new Promise(r => setTimeout(r, 1000));
                        }
                    }
                }
                
                this.hideStartupOverlay();
            }
            
            updateSettingsUI() {
                this.enablePruning.checked = this.pruningConfig.enabled;
                this.pruneThreshold.value = this.pruningConfig.threshold;
                this.prunePrompt.value = this.pruningConfig.prunePrompt;
                this.updatePruningUI();
            }
            
            updatePruningUI() {
                const enabled = this.enablePruning.checked;
                this.pruneThreshold.disabled = !enabled;
                this.prunePrompt.disabled = !enabled;
            }
            
            async saveSettings() {
                // Gather all settings
                const configData = {
                    aux_model: this.governorAuxModel?.value || '',
                    aux_cpu_only: this.governorAuxCpuOnly?.checked ?? true,
                    aux_context_length: parseInt(this.auxContextLength?.value) || 2048,
                    aux_port: this.governorConfig.auxPort || 8081,
                    expert_port: this.governorConfig.expertPort || 8080,
                    velocity_enabled: this.velocityEnabled?.checked ?? true,
                    velocity_threshold: Math.max(10, Math.min(90, parseInt(this.velocityThreshold?.value) || 40)),
                    velocity_char_threshold: Math.max(100, Math.min(10000, parseInt(this.velocityCharThreshold?.value) || 1500)),
                    velocity_index_prompt: this.velocityIndexPrompt?.value?.trim() || this.governorConfig.velocityIndexPrompt || '',
                    velocity_recall_prompt: this.velocityRecallPrompt?.value?.trim() || this.governorConfig.velocityRecallPrompt || '',
                    enable_pruning: this.enablePruning?.checked ?? true,
                    prune_threshold: Math.max(100, Math.min(10000, parseInt(this.pruneThreshold?.value) || 1500)),
                    prune_prompt: this.prunePrompt?.value?.trim() || this.pruningConfig.prunePrompt
                };
                
                // Gather 10 expert slots
                for (let i = 1; i <= 10; i++) {
                    const modelSelect = this.$(`expert${i}Model`);
                    const nameInput = this.$(`expert${i}Name`);
                    const enabledCheckbox = this.$(`expert${i}Enabled`);
                    const systemPrompt = this.$(`expert${i}SystemPrompt`);
                    
                    configData[`expert_${i}_model`] = modelSelect?.value || '';
                    configData[`expert_${i}_name`] = nameInput?.value?.trim() || `Expert ${i}`;
                    configData[`expert_${i}_enabled`] = enabledCheckbox?.checked ?? false;
                    configData[`expert_${i}_system_prompt`] = systemPrompt?.value?.trim() || 'You are a helpful assistant. Provide detailed, accurate responses.';
                }
                
                this.showStartupOverlay('Saving Settings...', 'Stopping servers and applying changes');
                await this.apiCall('stop_server', { type: 'all' });
                
                const result = await this.apiCall('save_governor_config', configData);
                
                if (result.success) {
                    window.location.reload();
                } else {
                    this.hideStartupOverlay();
                }
            }
            
            async handleFileSelect(e) {
                const files = Array.from(e.target.files);
                for (const file of files) {
                    try {
                        const text = await this.readFileAsText(file);
                        this.attachments.push({
                            name: file.name,
                            content: text,
                            size: file.size
                        });
                    } catch (err) {
                        
                    }
                }
                this.fileInput.value = '';
                this.updateAttachmentsPreview();
                this.updateAttachButton();
                this.scheduleTokenCount();
            }
            
            readFileAsText(file) {
                return new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = e => resolve(e.target.result);
                    reader.onerror = () => reject(new Error('Failed to read file'));
                    reader.readAsText(file);
                });
            }
            
            removeAttachment(index) {
                this.attachments.splice(index, 1);
                this.updateAttachmentsPreview();
                this.updateAttachButton();
                this.scheduleTokenCount();
            }
            
            updateAttachmentsPreview() {
                this.attachmentsPreview.innerHTML = this.attachments.map((att, i) => `
                    <div class="attachment-chip">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>${this.escapeHtml(att.name)}</span>
                        <button type="button" class="remove-attachment" data-index="${i}" title="Remove">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                `).join('');
            }
            
            updateAttachButton() {
                this.attachBtn.classList.toggle('has-files', this.attachments.length > 0);
            }
            
            getAttachmentsText() {
                if (this.attachments.length === 0) return '';
                return this.attachments.map(att => 
                    `<file name="${att.name}">\n\n${att.content}\n\n</file>`
                ).join('\n\n');
            }
            
            clearAttachments() {
                this.attachments = [];
                this.updateAttachmentsPreview();
                this.updateAttachButton();
            }
            
            getAttachmentNames(attachmentsText) {
                const matches = attachmentsText.match(/<file name="([^"]+)">/g) || [];
                return matches.map(m => m.match(/<file name="([^"]+)">/)[1]);
            }
            
            showDialog(dialog) {
                dialog.classList.add('active');
            }
            
            hideDialog(dialog) {
                dialog.classList.remove('active');
            }
            
            showContextOverflowDialog(messageTokens, availableTokens) {
                const overflow = messageTokens - availableTokens;
                this.contextOverflowMessage.textContent = `Your message (${messageTokens} tokens) exceeds the available context space (${Math.max(0, availableTokens)} tokens available). Please shorten your message by approximately ${overflow} tokens and try again.`;
                this.showDialog(this.contextOverflowDialog);
            }
            
            updateUI() {
                this.updateSendButton();
                this.welcomeScreen.classList.toggle('hidden', this.messages.length > 0);
            }
            
            adjustTextareaHeight() {
                this.chatInput.style.height = 'auto';
                this.chatInput.style.height = Math.min(this.chatInput.scrollHeight, 144) + 'px';
            }
            
            escapeHtml(text) {
                const d = document.createElement('div');
                d.textContent = text;
                return d.innerHTML;
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            window.chatApp = new LlamaChat();
            
            // Snow globe sparkles
            const sparklesContainer = document.getElementById('sparkles');
            const sparkles = [];
            const numSparkles = 40;
            
            for (let i = 0; i < numSparkles; i++) {
                const sparkle = document.createElement('div');
                sparkle.className = 'sparkle';
                sparklesContainer.appendChild(sparkle);
                
                sparkles.push({
                    el: sparkle,
                    x: Math.random() * window.innerWidth,
                    y: Math.random() * window.innerHeight,
                    vx: (Math.random() - 0.5) * 2,
                    vy: (Math.random() - 0.5) * 2,
                    opacity: Math.random(),
                    fadeDir: Math.random() > 0.5 ? 1 : -1,
                    size: 1 + Math.random() * 4
                });
            }
            
            function animateSparkles() {
                sparkles.forEach(s => {
                    // Move
                    s.x += s.vx;
                    s.y += s.vy;
                    
                    // Bounce off edges with some randomness
                    if (s.x < 0 || s.x > window.innerWidth) {
                        s.vx *= -1;
                        s.vx += (Math.random() - 0.5) * 0.5;
                    }
                    if (s.y < 0 || s.y > window.innerHeight) {
                        s.vy *= -1;
                        s.vy += (Math.random() - 0.5) * 0.5;
                    }
                    
                    // Gentle drift changes
                    if (Math.random() < 0.02) {
                        s.vx += (Math.random() - 0.5) * 0.8;
                        s.vy += (Math.random() - 0.5) * 0.8;
                        s.vx = Math.max(-2.5, Math.min(2.5, s.vx));
                        s.vy = Math.max(-2.5, Math.min(2.5, s.vy));
                    }
                    
                    // Twinkle
                    s.opacity += s.fadeDir * 0.02;
                    if (s.opacity >= 0.9) {
                        s.opacity = 0.9;
                        s.fadeDir = -1;
                    } else if (s.opacity <= 0) {
                        s.opacity = 0;
                        s.fadeDir = 1;
                    }
                    
                    // Apply
                    s.el.style.transform = `translate(${s.x}px, ${s.y}px)`;
                    s.el.style.opacity = s.opacity;
                    s.el.style.width = s.size + 'px';
                    s.el.style.height = s.size + 'px';
                });
                
                requestAnimationFrame(animateSparkles);
            }
            
            animateSparkles();
        });
    </script>

    <head>
        <div class="sparkles" id="sparkles"></div>

        <button class="mobile-sidebar-toggle icon-btn" id="mobileSidebarToggle">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
        </button>

        <div class="settings-panel" id="settingsPanel">
            <div class="settings-header">
                <div class="settings-title">Settings</div>
                <button class="icon-btn" id="settingsClose">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="settings-content">
                <div class="settings-group governor-section">
                    <div class="governor-title">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 0 0 2.25-2.25V6.75a2.25 2.25 0 0 0-2.25-2.25H6.75A2.25 2.25 0 0 0 4.5 6.75v10.5a2.25 2.25 0 0 0 2.25 2.25Zm.75-12h9v9h-9v-9Z"/></svg>
                        Llama Governor
                    </div>
                    
                    <div class="vram-display" id="vramDisplay">
                        <svg class="vram-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 1-6.23.693L5 15.3m14.8 0 .002.003a2.25 2.25 0 0 1-.994 3.168 23.933 23.933 0 0 1-10.603 1.584 2.25 2.25 0 0 1-2.205-2.25V15.3"/></svg>
                        <div class="vram-info">
                            <div class="vram-label">GPU VRAM</div>
                            <div class="vram-value vram-loading" id="vramValue">Detecting...</div>
                            <div class="vram-gpu" id="vramGpu"></div>
                        </div>
                    </div>
                    
                    <!-- ACCORDION: Expert Models -->
                    <div class="settings-accordion open" data-accordion="models">
                        <div class="accordion-header">
                            <svg class="accordion-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            <span>Expert Models (GPU)</span>
                        </div>
                        <div class="accordion-content" id="expertSlotsContainer">
                            <!-- Expert slots will be generated dynamically by JavaScript -->
                        </div>
                    </div>
                    
                    <!-- ACCORDION: Control Plane -->
                    <div class="settings-accordion" data-accordion="control">
                        <div class="accordion-header">
                            <svg class="accordion-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            <span>Control Plane</span>
                        </div>
                        <div class="accordion-content">
                            <div class="model-select-wrapper">
                                <div class="model-select-label">
                                    <span>Auxiliary Model</span>
                                    <span class="model-size" id="auxModelSize"></span>
                                </div>
                                <select class="model-select" id="governorAuxModel"><option value="">Select a model...</option></select>
                                <div class="settings-hint">Small model for routing, pruning, and indexing. Runs separately from experts.</div>
                            </div>
                            
                            <div class="settings-row">
                                <div class="settings-item" style="flex:1">
                                    <label class="settings-label">Context Length</label>
                                    <input type="number" class="settings-input" id="auxContextLength" value="2048" min="256" max="8192" step="256">
                                </div>
                                <div class="settings-item" style="flex:1">
                                    <div class="settings-checkbox" style="margin-top:1.75rem">
                                        <input type="checkbox" id="governorAuxCpuOnly" checked>
                                        <label for="governorAuxCpuOnly">CPU-only mode</label>
                                    </div>
                                </div>
                            </div>
                            <div class="settings-hint">CPU-only keeps VRAM free for expert models</div>
                        </div>
                    </div>
                    
                    <!-- ACCORDION: Context Management -->
                    <div class="settings-accordion" data-accordion="context">
                        <div class="accordion-header">
                            <svg class="accordion-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            <span>Context Management</span>
                        </div>
                        <div class="accordion-content">
                            <!-- Velocity Index Section -->
                            <div class="settings-hint" style="margin-bottom:0.5rem;font-weight:600;color:var(--text-secondary)">Velocity Index</div>
                            <div class="settings-hint" style="margin-bottom:1rem">Archives older messages when context fills up, then intelligently recalls relevant context when needed.</div>
                            
                            <div class="settings-item">
                                <div class="settings-checkbox">
                                    <input type="checkbox" id="velocityEnabled" checked>
                                    <label for="velocityEnabled">Enable Velocity Index</label>
                                </div>
                            </div>
                            
                            <div class="settings-row">
                                <div class="settings-item" style="flex:1">
                                    <label class="settings-label">Context Threshold (%)</label>
                                    <input type="number" class="settings-input" id="velocityThreshold" value="40" min="10" max="90">
                                </div>
                                <div class="settings-item" style="flex:1">
                                    <label class="settings-label">Character Threshold</label>
                                    <input type="number" class="settings-input" id="velocityCharThreshold" value="1500" min="100" max="10000">
                                </div>
                            </div>
                            <div class="settings-hint">Start indexing when context exceeds threshold %. Skip messages shorter than character threshold.</div>
                            
                            <div class="settings-item">
                                <label class="settings-label">Index Prompt</label>
                                <textarea class="settings-textarea" id="velocityIndexPrompt" rows="2">Create a brief, descriptive title (max 10 words) that captures the key topic or intent of this message. Return ONLY the title, nothing else.</textarea>
                            </div>
                            
                            <div class="settings-item">
                                <label class="settings-label">Recall Prompt</label>
                                <textarea class="settings-textarea" id="velocityRecallPrompt" rows="3">Given the user's new message, determine which archived conversation topic (if any) is most relevant and should be recalled to provide better context. If one topic is clearly relevant, respond with ONLY the number in brackets (e.g., 0 or 3). If no topic is relevant, respond with: NULL</textarea>
                            </div>
                            
                            <div class="velocity-stats" id="velocityStats" style="display:none">
                                <div class="velocity-stat">
                                    <span class="velocity-stat-label">Indexed</span>
                                    <span class="velocity-stat-value" id="velocityIndexedCount">0</span>
                                </div>
                                <div class="velocity-stat">
                                    <span class="velocity-stat-label">Recalls</span>
                                    <span class="velocity-stat-value" id="velocityRecallCount">0</span>
                                </div>
                            </div>
                            
                            <!-- Context Pruning Section -->
                            <div style="border-top:1px solid var(--glass-border);margin:1.5rem 0 1rem 0;padding-top:1rem">
                                <div class="settings-hint" style="margin-bottom:0.5rem;font-weight:600;color:var(--text-secondary)">Context Pruning</div>
                                <div class="settings-hint" style="margin-bottom:1rem">Automatically condenses older messages to preserve context space while retaining key information.</div>
                            </div>
                            
                            <div class="settings-item">
                                <div class="settings-checkbox">
                                    <input type="checkbox" id="enablePruning" checked>
                                    <label for="enablePruning">Enable Context Pruning</label>
                                </div>
                            </div>
                            
                            <div class="settings-item">
                                <label class="settings-label">Character Threshold</label>
                                <input type="number" class="settings-input" id="pruneThreshold" value="1500" min="100" max="10000">
                                <div class="settings-hint">Messages shorter than this will be skipped</div>
                            </div>
                            
                            <div class="settings-item">
                                <label class="settings-label">Pruning Prompt</label>
                                <textarea class="settings-textarea" id="prunePrompt" rows="2">Produce a slimmed down version of the following message, preserving all crucial details:</textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- ACCORDION: Audio -->
                    <div class="settings-accordion" data-accordion="audio">
                        <div class="accordion-header">
                            <svg class="accordion-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            <span>Audio</span>
                        </div>
                        <div class="accordion-content">
                            <div class="settings-hint" style="margin-bottom:1rem">Configure audio notifications for response completion.</div>
                            
                            <div class="settings-item">
                                <div class="settings-checkbox">
                                    <input type="checkbox" id="enableAudioChime" checked>
                                    <label for="enableAudioChime">Enable Completion Sound</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="settings-actions">
                <button class="settings-btn save" id="saveSettingsBtn"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>Save Settings</button>
                <button class="settings-btn cancel" id="closeSettingsBtn"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>Close</button>
            </div>
        </div>
        
        <div class="startup-overlay" id="startupOverlay">
            <div class="startup-content">
                <div class="startup-spinner"><div class="sparkle"></div></div>
                <div class="startup-title" id="startupTitle">Starting Server...</div>
                <div class="startup-message" id="startupMessage">Initializing the auxiliary model</div>
                <div class="startup-error" id="startupError" style="display:none"></div>
            </div>
        </div>

        <div class="startup-overlay" id="tokenizationOverlay">
            <div class="startup-content">
                <div class="startup-spinner"><div class="sparkle"></div></div>
                <div class="startup-title">Loading Chat...</div>
                <div class="startup-message">Calculating context usage</div>
            </div>
        </div>
        
        <div class="modal" id="confirmDialog">
            <div class="modal-content">
                <div class="modal-title">Delete Chat</div>
                <div class="modal-message" id="confirmMessage">Are you sure you want to delete this chat?</div>
                <div class="modal-actions">
                    <button class="modal-btn" id="confirmCancel">Cancel</button>
                    <button class="modal-btn danger" id="confirmDelete">Delete</button>
                </div>
            </div>
        </div>

        <div class="modal" id="contextOverflowDialog">
            <div class="modal-content">
                <div class="modal-title">Message Too Long</div>
                <div class="modal-message" id="contextOverflowMessage">Your message is too long and would exceed the available context space. Please shorten your message and try again.</div>
                <div class="modal-actions">
                    <button class="modal-btn confirm" id="contextOverflowOk">OK</button>
                </div>
            </div>
        </div>

        <div class="app">
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <button class="icon-btn" id="sidebarToggle">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
                    </button>
                    <div class="sidebar-logo">
                        <div class="sidebar-logo-title">openOrchestrate</div>
                        <div class="sidebar-logo-subtitle">by Technologyst Labs</div>
                    </div>
                </div>
                <div class="search-container">
                    <div class="chat-search">
                        <div class="search-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.328-3.329A7 7 0 012 9z" clip-rule="evenodd"/></svg></div>
                        <input type="text" class="search-input" placeholder="Search chats" id="searchInput">
                    </div>
                </div>
                <button class="new-chat-btn" id="newChatBtn"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg><span>New Chat</span></button>
                <div class="chats-list scrollbar-hidden" id="chatsList"></div>
                <div class="sidebar-footer">
                    <span class="sidebar-version">v0.9-R8 © 2026 Technologyst Labs</span>
                </div>
            </div>
            
            <div class="chat-container" id="chatContainer">
                <div class="chat-header">
                    <div class="header-content">
                        <button class="icon-btn header-sidebar-toggle" id="headerSidebarToggle">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
                        </button>
                        <div class="header-server-status">
                            <div class="server-status compact" id="auxServerStatus"><span class="server-status-dot"></span><span>Aux</span></div>
                            <div class="server-status compact" id="expertServerStatus"><span class="server-status-dot"></span><span>Expert</span></div>
                        </div>
                        <div style="flex: 1;"></div>
                        <div class="header-controls">
                            <button class="icon-btn" title="Settings" id="settingsBtn">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke="currentColor" fill="currentColor" width="20" height="20" stroke-width="0.5"><path d="M3.16667 16.5L3.16667 11.5M3.16667 11.5C4.08714 11.5 4.83333 10.7538 4.83333 9.83333C4.83333 8.91286 4.08714 8.16667 3.16667 8.16667C2.24619 8.16667 1.5 8.91286 1.5 9.83333C1.5 10.7538 2.24619 11.5 3.16667 11.5ZM3.16667 4.83333V1.5M9 16.5V11.5M9 4.83333V1.5M9 4.83333C8.07953 4.83333 7.33333 5.57953 7.33333 6.5C7.33333 7.42047 8.07953 8.16667 9 8.16667C9.92047 8.16667 10.6667 7.42047 10.6667 6.5C10.6667 5.57953 9.92047 4.83333 9 4.83333ZM14.8333 16.5V13.1667M14.8333 13.1667C15.7538 13.1667 16.5 12.4205 16.5 11.5C16.5 10.5795 15.7538 9.83333 14.8333 9.83333C13.9129 9.83333 13.1667 10.5795 13.1667 11.5C13.1667 12.4205 13.9129 13.1667 14.8333 13.1667ZM14.8333 6.5V1.5" stroke-width="1.66667" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="messages-container" id="messagesContainer">
                    <div class="welcome-screen" id="welcomeScreen">
                        <div class="welcome-content">
                            <div class="welcome-title">openOrchestrate</div>
                            <div class="welcome-subtitle">Local AI, treated with respect.</div>
                            <div class="suggestions">
                                <div class="suggestions-title">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="12" height="12">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>
                                    </svg>
                                    Try a prompt!
                                </div>
                                <div class="suggestions-grid" id="suggestionsGrid">
                                    <button class="suggestion-btn waterfall" style="animation-delay:0ms" data-prompt="Write a short article explaining quantum computing.">
                                        <div class="suggestion-title">Learn & Create</div>
                                        <div class="suggestion-desc">Write a short article explaining quantum computing.</div>
                                    </button>
                                    <button class="suggestion-btn waterfall" style="animation-delay:60ms" data-prompt="Write a PHP function to calculate fibonacci numbers">
                                        <div class="suggestion-title">Start your programming journey</div>
                                        <div class="suggestion-desc">Calculate fibonacci numbers in PHP!</div>
                                    </button>
                                    <button class="suggestion-btn waterfall" style="animation-delay:120ms" data-prompt="What can I take for a headache?">
                                        <div class="suggestion-title">Seek health advice</div>
                                        <div class="suggestion-desc">What can I take for a headache?</div>
                                    </button>
                                    <button class="suggestion-btn waterfall" style="animation-delay:180ms" data-prompt="How do you make a good carrot cake?">
                                        <div class="suggestion-title">Bake with brand new recipes!</div>
                                        <div class="suggestion-desc">Carrot Cake</div>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="input-container">
                    <div class="attachments-preview" id="attachmentsPreview"></div>
                    <form class="input-form" id="chatForm">
                        <div class="input-wrapper">
                            <div class="textarea-row">
                                <textarea class="text-input scrollbar-hidden" id="chatInput" placeholder="How can I help you today?" rows="1"></textarea>
                                <input type="file" id="fileInput" accept=".txt,.md,.json,.csv,.xml,.html,.css,.js,.py,.c,.cpp,.h,.java,.rb,.php,.sh,.yml,.yaml,.log,.ini,.cfg" multiple hidden>
                                <button type="button" class="attach-btn" id="attachBtn" title="Attach text files">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                </button>
                                <button type="submit" class="send-btn" id="sendBtn" disabled>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" width="20" height="20"><path fill-rule="evenodd" d="M8 14a.75.75 0 0 1-.75-.75V4.56L4.03 7.78a.75.75 0 0 1-1.06-1.06l4.5-4.5a.75.75 0 0 1 1.06 0l4.5 4.5a.75.75 0 0 1-1.06 1.06L8.75 4.56v8.69A.75.75 0 0 1 8 14Z" clip-rule="evenodd"/></svg>
                                </button>
                            </div>
                            <div class="input-controls">
                                <div class="stats-container" id="statsContainer"></div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </body>        
        <style>
            :root { --glass-bg: rgba(255, 255, 255, 0.03); --glass-bg-hover: rgba(255, 255, 255, 0.09); --glass-border: rgba(255, 255, 255, 0.08); --glass-border-bright: rgba(255, 255, 255, 0.15); --text-primary: rgba(255, 255, 255, 0.95); --text-secondary: rgba(255, 255, 255, 0.7); --text-tertiary: rgba(255, 255, 255, 0.5); --text-quaternary: rgba(255, 255, 255, 0.35); --accent-primary: #5BC4E8; --accent-primary-soft: rgba(91, 196, 232, 0.15); --accent-secondary: #7DD87D; --accent-secondary-soft: rgba(125, 216, 125, 0.15); --accent-gradient: linear-gradient(135deg, #9DE89D 0%, #5DD8A6 30%, #4BBEE8 65%, #3A9ED4 100%); --success: #30D158; --success-soft: rgba(48, 209, 88, 0.15); --warning: #FFD60A; --warning-soft: rgba(255, 214, 10, 0.15); --error: #FF453A; --error-soft: rgba(255, 69, 58, 0.15); --bg-deep: #040d12; --bg-panel: rgba(15, 25, 35, 0.85); --bg-ambient: radial-gradient(ellipse 100% 60% at 20% -20%, rgba(130, 220, 130, 0.18), transparent 50%), radial-gradient(ellipse 90% 55% at 75% 10%, rgba(91, 196, 232, 0.22), transparent 55%), radial-gradient(ellipse 70% 50% at 50% 115%, rgba(58, 158, 212, 0.2), transparent); --liquid-glass: linear-gradient(160deg, rgba(65, 65, 78, 0.45) 0%, rgba(48, 48, 58, 0.5) 100%); --liquid-glass-hover: linear-gradient(160deg, rgba(75, 75, 90, 0.5) 0%, rgba(55, 55, 68, 0.55) 100%); --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.15); --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.25); --shadow-glow: 0 0 40px rgba(91, 196, 232, 0.15); --radius-xs: 8px; --radius-sm: 12px; --radius-md: 16px; --radius-lg: 20px; --radius-xl: 24px; --radius-2xl: 28px; --radius-pill: 9999px; --blur-sm: 8px; --blur-md: 16px; --blur-lg: 24px; --blur-xl: 40px; --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1); --transition-smooth: 250ms cubic-bezier(0.4, 0, 0.2, 1); --transition-spring: 400ms cubic-bezier(0.34, 1.56, 0.64, 1) }
            .dark { color-scheme: dark }
            * { margin: 0; padding: 0; box-sizing: border-box }
            body { font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Arial, sans-serif; background: var(--bg-deep); color: var(--text-primary); height: 100vh; overflow: hidden; position: relative }
            body::before { content: ''; position: fixed; inset: 0; background: var(--bg-ambient); pointer-events: none; z-index: 0 }
            .sparkles { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden }
            .sparkle { position: absolute; width: 2px; height: 2px; background: white; border-radius: 50%; opacity: 0; box-shadow: 0 0 2px rgba(255, 255, 255, 0.9), 0 0 4px rgba(180, 240, 255, 0.5) }
            .app { display: flex; height: 100vh; max-height: 100dvh; position: relative; z-index: 1 }
            .sidebar { width: 270px; background: var(--bg-panel); backdrop-filter: blur(var(--blur-xl)) saturate(180%); -webkit-backdrop-filter: blur(var(--blur-xl)) saturate(180%); border-right: 1px solid var(--glass-border); display: flex; flex-direction: column; height: 100vh; transition: transform var(--transition-smooth); flex-shrink: 0; position: relative }
            .sidebar::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.1) 20%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.1) 80%, transparent 100%) }
            .sidebar-header { padding: 1.5rem 1.25rem 1rem; display: flex; align-items: center; gap: .75rem; border-bottom: 1px solid var(--glass-border); position: relative }
            .sidebar-header .icon-btn { width: 36px; height: 36px; min-width: 36px; min-height: 36px; flex-shrink: 0 }
            .logo-container { display: flex; align-items: center; gap: .75rem; flex: 1 }
            .sidebar-logo { display: flex; flex-direction: column; align-items: flex-start; justify-content: center; flex: 1; margin-left: 0.5rem }
            .sidebar-logo-title { font-family: 'Quicksand', sans-serif; font-size: 1.25rem; font-weight: 700; background: var(--accent-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; position: relative; display: inline-block; letter-spacing: -0.01em; filter: drop-shadow(0 0 20px rgba(91, 196, 232, 0.3)) }
            .sidebar-logo-subtitle { font-family: 'Quicksand', sans-serif; font-size: 0.6rem; color: var(--text-quaternary); letter-spacing: 0.12em; text-transform: uppercase; font-weight: 600; padding-top: 2px }
            .sidebar-footer { padding: 0.75rem 1.25rem; border-top: 1px solid var(--glass-border); margin-top: auto; background: rgba(255, 255, 255, 0.02) }
            .sidebar-version { font-size: 0.6rem; color: var(--text-quaternary); opacity: 0.7; letter-spacing: 0.02em; text-align: center; display: block; width: 100% }
            .new-chat-btn { display: flex; align-items: center; gap: .75rem; padding: .55rem 1.1rem; margin: 1rem 1rem .75rem; border-radius: var(--radius-xl); background: linear-gradient(160deg, rgba(70, 70, 85, 0.5) 0%, rgba(50, 50, 62, 0.55) 50%, rgba(45, 45, 58, 0.6) 100%); border: 2px solid rgba(255, 255, 255, 0.1); color: var(--text-secondary); cursor: pointer; font-weight: 500; font-size: 0.9rem; transition: all var(--transition-smooth); position: relative; overflow: hidden; box-shadow: 0 0 6px rgba(255, 255, 255, 0.02), 0 2px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.06) }
            .new-chat-btn::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%); pointer-events: none }
            .new-chat-btn:hover { background: linear-gradient(160deg, rgba(80, 80, 98, 0.55) 0%, rgba(58, 58, 72, 0.6) 50%, rgba(52, 52, 66, 0.65) 100%); border-color: rgba(255, 255, 255, 0.18); color: var(--text-primary); transform: translateY(-2px); box-shadow: 0 0 10px rgba(255, 255, 255, 0.04), 0 6px 20px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .new-chat-btn:active { transform: translateY(0); box-shadow: 0 0 4px rgba(255, 255, 255, 0.02), 0 1px 4px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.04) }
            .search-container { padding: .5rem 1rem; position: relative; padding-top: 22px; }
            .chat-search { display: flex; width: 100%; border-radius: var(--radius-xl); background: var(--glass-bg); border: 1px solid var(--glass-border); overflow: hidden; transition: all var(--transition-smooth) }
            .chat-search:focus-within { border-color: var(--accent-primary); box-shadow: 0 0 0 3px var(--accent-primary-soft), var(--shadow-glow) }
            .search-icon { padding: .5rem .75rem; color: var(--text-quaternary); display: flex; align-items: center }
            .search-input { width: 100%; padding: .5rem .5rem .5rem 0; background: transparent; border: none; color: var(--text-primary); outline: none; font-size: .875rem }
            .search-input::placeholder { color: var(--text-quaternary) }
            .chats-list { flex: 1; overflow-y: auto; padding: .5rem .75rem }
            .chats-list::-webkit-scrollbar { width: 6px }
            .chats-list::-webkit-scrollbar-track { background: transparent }
            .chats-list::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: var(--radius-pill) }
            .chats-list::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.15) }
            .chat-date { padding: .75rem .5rem .5rem; font-size: .7rem; color: var(--text-quaternary); font-weight: 600; text-transform: uppercase; letter-spacing: .08em }
            .chat-item { padding: .625rem .75rem; margin: .125rem 0; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: space-between; cursor: pointer; transition: all var(--transition-smooth); border: 2px solid transparent; position: relative }
            .chat-item:hover { background: var(--liquid-glass); border-color: rgba(255, 255, 255, 0.08); box-shadow: 0 0 6px rgba(255, 255, 255, 0.02), 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.04) }
            .chat-item.active { background: linear-gradient(160deg, rgba(70, 70, 85, 0.5) 0%, rgba(55, 55, 68, 0.55) 100%); border-color: rgba(255, 255, 255, 0.12); box-shadow: 0 0 8px rgba(255, 255, 255, 0.03), 0 3px 10px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.06) }
            .chat-item-content { flex: 1; min-width: 0; display: flex; flex-direction: column }
            .chat-title { font-size: .875rem; font-weight: 500; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
            .chat-item:hover .chat-title, .chat-item.active .chat-title { color: var(--text-primary) }
            .chat-preview { font-size: .75rem; color: var(--text-quaternary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: .125rem }
            .chat-actions { display: flex; align-items: center; gap: .25rem; opacity: 0; transition: opacity var(--transition-fast) }
            .chat-item:hover .chat-actions { opacity: 1 }
            .icon-btn { width: 36px; height: 36px; border-radius: 50%; background: var(--liquid-glass); border: 2px solid rgba(255, 255, 255, 0.08); color: var(--text-tertiary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all var(--transition-smooth); line-height: 0; position: relative; overflow: hidden; box-shadow: 0 0 5px rgba(255, 255, 255, 0.02), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .icon-btn::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%); pointer-events: none }
            .icon-btn svg { display: block; flex-shrink: 0; position: relative; z-index: 1 }
            #settingsBtn svg { transform: translate(2px, 2px) }
            .icon-btn:hover { background: var(--liquid-glass-hover); border-color: rgba(255, 255, 255, 0.15); color: var(--text-primary); transform: scale(1.05); box-shadow: 0 0 8px rgba(255, 255, 255, 0.04), 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.07) }
            .icon-btn:active { transform: scale(0.95); box-shadow: 0 0 3px rgba(255, 255, 255, 0.02), 0 1px 3px rgba(0, 0, 0, 0.1) }
            .delete-btn { padding: .375rem; border-radius: var(--radius-sm); background: linear-gradient(160deg, rgba(60, 60, 72, 0.35) 0%, rgba(45, 45, 55, 0.4) 100%); border: 2px solid rgba(255, 255, 255, 0.05); color: var(--text-quaternary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all var(--transition-smooth); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04) }
            .delete-btn:hover { background: linear-gradient(160deg, rgba(255, 85, 75, 0.25) 0%, rgba(220, 60, 55, 0.3) 100%); border-color: rgba(255, 69, 58, 0.25); color: var(--error); box-shadow: 0 0 8px rgba(255, 69, 58, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.06) }
            .delete-btn:active { transform: scale(0.92) }
            .chat-container { flex: 1; display: flex; flex-direction: column; overflow: hidden; position: relative; transition: margin-left var(--transition-smooth) }
            .sidebar-collapsed .chat-container { margin-left: -280px; width: calc(100% + 280px) }
            .chat-header { padding: 1.25rem 2rem; border-bottom: 1px solid var(--glass-border); position: sticky; top: 0; z-index: 30; background: var(--bg-panel); backdrop-filter: blur(var(--blur-lg)) saturate(180%); -webkit-backdrop-filter: blur(var(--blur-lg)) saturate(180%); display: flex; align-items: center; justify-content: space-between }
            .header-content { display: flex; align-items: center; justify-content: space-between; flex: 1 }
            .header-controls { display: flex; align-items: center; gap: .5rem }
            .header-sidebar-toggle { display: none }
            .messages-container { flex: 1; overflow-y: auto; padding: 1.5rem 2rem; position: relative }
            .messages-container::-webkit-scrollbar { width: 8px }
            .messages-container::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.02); border-radius: var(--radius-pill) }
            .messages-container::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.1); border-radius: var(--radius-pill) }
            .messages-container::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.18) }
            .message { margin-bottom: 1.5rem; max-width: 42rem; animation: messageSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) }
            @keyframes messageSlideIn { from { opacity: 0; transform: translateY(12px) } to { opacity: 1; transform: translateY(0) } }
            .user-message { background: var(--glass-bg-hover); backdrop-filter: blur(var(--blur-md)) saturate(150%); -webkit-backdrop-filter: blur(var(--blur-md)) saturate(150%); padding: 1rem 1.5rem; border-radius: var(--radius-2xl); border: 1px solid var(--glass-border); position: relative; box-shadow: var(--shadow-sm); margin-left: auto; margin-right: 0 }
            .user-message::before { content: ''; position: absolute; top: 0; left: 20px; right: 20px; height: 1px; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.15) 30%, rgba(255, 255, 255, 0.25) 50%, rgba(255, 255, 255, 0.15) 70%, transparent 100%); border-radius: var(--radius-pill) }
            .assistant-message { padding: 1rem 1.5rem; position: relative; background: rgba(0, 0, 0, 0.25); border-radius: var(--radius-lg); box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); margin-left: 0; margin-right: auto }
            .message-attachments { display: flex; flex-wrap: wrap; gap: .375rem; margin-bottom: .625rem }
            .message-attachment-chip { display: inline-flex; align-items: center; gap: .25rem; padding: .1875rem .5rem; background: var(--accent-primary-soft); border: 1px solid rgba(91, 196, 232, 0.15); border-radius: var(--radius-pill); font-size: .6875rem; color: var(--accent-primary) }
            .message-attachment-chip svg { width: 10px; height: 10px; opacity: 0.7 }
            .message-content { line-height: 1.7; color: var(--text-secondary) }
            .user-message .message-content { color: var(--text-primary) }
            .code-lang,.copy-btn{color:var(--text-tertiary)}
            .code-block{background:var(--glass-bg);backdrop-filter:blur(var(--blur-md)) saturate(150%);-webkit-backdrop-filter:blur(var(--blur-md)) saturate(150%);border:1px solid var(--glass-border);border-radius:10px;margin:.75rem 0;overflow:hidden;box-shadow:var(--shadow-sm);position:relative}
            .code-header{display:flex;justify-content:space-between;align-items:center;padding:5px .75rem .25rem;background:rgba(0,0,0,.3);border-bottom:1px solid var(--glass-border);height:33px}
            .code-lang{font-size:.65rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
            .copy-btn{display:flex;align-items:center;justify-content:center;width:20px;height:20px;padding:0;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius-xs);cursor:pointer;transition:all var(--transition-fast)}
            .copy-btn:hover{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:var(--text-primary)}
            .copy-btn svg{width:12px;height:12px;display:block}
            .copy-btn.copied{color:var(--accent-primary);background:var(--accent-primary-soft);border-color:var(--accent-primary)}
            .code-block pre{margin:0;padding:1rem;overflow-x:auto;background:0 0;border:none}
            .code-block pre code{display:block;font-family:'SF Mono','Fira Code',Monaco,Consolas,monospace;font-size:.875em;line-height:1.6;background:0 0;border:none;padding:0;color:#abb2bf}
            .hljs-keyword, .hljs-selector-tag, .hljs-built_in, .hljs-name { color: #c678dd; }
            .hljs-string, .hljs-attr { color: #98c379; }
            .hljs-number, .hljs-literal { color: #d19a66; }
            .hljs-comment, .hljs-quote { color: #5c6370; font-style: italic; }
            .hljs-function .hljs-title, .hljs-title.function_ { color: #61afef; }
            .hljs-variable, .hljs-template-variable { color: #e06c75; }
            .hljs-type, .hljs-class .hljs-title { color: #e5c07b; }
            .hljs-tag { color: #e06c75; }
            .hljs-attribute { color: #d19a66; }
            .hljs-symbol, .hljs-bullet { color: #56b6c2; }
            .hljs-addition { color: #98c379; background: rgba(152, 195, 121, 0.1); }
            .hljs-deletion { color: #e06c75; background: rgba(224, 108, 117, 0.1); }
            .hljs-meta { color: #56b6c2; }
            .hljs-params { color: #abb2bf; }
            .hljs-property { color: #e06c75; }
            .hljs-selector-class { color: #e5c07b; }
            .hljs-selector-id { color: #61afef; }
            .hljs-regexp { color: #56b6c2; }
            .hljs-link { color: #61afef; text-decoration: underline; }
            .hljs-doctag { color: #c678dd; }
            .hljs-section { color: #e06c75; }
            .message-content code:not(pre code){background:var(--glass-bg);border-radius:var(--radius-xs);padding:.125rem .5rem;border:1px solid var(--glass-border);color:var(--accent-primary);font-family:'SF Mono','Fira Code',Monaco,Consolas,monospace;font-size:.875em}
            .message-content strong { font-weight: 600; color: var(--text-primary) }
            .message-content table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.9em; background: rgba(0, 0, 0, 0.2); border-radius: var(--radius-sm); overflow: hidden }
            .message-content th, .message-content td { padding: 0.6rem 0.8rem; text-align: left; border-bottom: 1px solid var(--glass-border) }
            .message-content th { background: rgba(0, 0, 0, 0.3); color: var(--text-primary); font-weight: 600 }
            .message-content tr:last-child td { border-bottom: none }
            .message-content tr:hover td { background: rgba(255, 255, 255, 0.02) }
            .message-content ul, .message-content ol { margin: 0.75rem 0; padding-left: 1.5rem }
            .message-content li { margin: 0.4rem 0; line-height: 1.6 }
            .message-content h2, .message-content h3, .message-content h4 { color: var(--text-primary); margin: 1.25rem 0 0.5rem; font-weight: 600 }
            .message-content h2 { font-size: 1.3em }
            .message-content h3 { font-size: 1.15em }
            .message-content h4 { font-size: 1.05em }
            .message-actions { display: flex; gap: .5rem; margin-top: .75rem; flex-wrap: wrap }
            .action-btn { padding: .375rem .75rem; background: var(--liquid-glass); backdrop-filter: blur(var(--blur-sm)); border: 2px solid rgba(255, 255, 255, 0.1); border-radius: var(--radius-lg); color: var(--text-tertiary); cursor: pointer; font-size: .75rem; font-weight: 500; display: flex; align-items: center; gap: .375rem; transition: all var(--transition-smooth); position: relative; overflow: hidden; box-shadow: 0 0 5px rgba(255, 255, 255, 0.02), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .action-btn::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%); pointer-events: none }
            .action-btn:hover { background: linear-gradient(160deg, rgba(75, 75, 90, 0.55) 0%, rgba(58, 58, 70, 0.6) 100%); border-color: rgba(255, 255, 255, 0.18); color: var(--text-primary); transform: translateY(-2px); box-shadow: 0 0 8px rgba(255, 255, 255, 0.03), 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.07) }
            .action-btn:active { transform: translateY(0); box-shadow: 0 0 3px rgba(255, 255, 255, 0.02), 0 1px 3px rgba(0, 0, 0, 0.1) }
            .action-btn.regenerate { background: linear-gradient(160deg, rgba(91, 196, 232, 0.2) 0%, rgba(70, 185, 215, 0.25) 100%); color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.25); box-shadow: 0 0 8px rgba(91, 196, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.1) }
            .action-btn.regenerate:hover { background: linear-gradient(160deg, rgba(91, 196, 232, 0.28) 0%, rgba(70, 185, 215, 0.33) 100%); border-color: rgba(91, 196, 232, 0.35); box-shadow: 0 0 14px rgba(91, 196, 232, 0.18), 0 4px 12px rgba(0, 0, 0, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.12) }
            .action-btn.stop { background: linear-gradient(160deg, rgba(255, 69, 58, 0.2) 0%, rgba(230, 55, 48, 0.25) 100%); color: var(--error); border-color: rgba(255, 69, 58, 0.28); box-shadow: 0 0 8px rgba(255, 69, 58, 0.1), 0 2px 6px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .action-btn.stop:hover { background: linear-gradient(160deg, rgba(255, 69, 58, 0.28) 0%, rgba(230, 55, 48, 0.33) 100%); border-color: rgba(255, 69, 58, 0.38); box-shadow: 0 0 14px rgba(255, 69, 58, 0.18), 0 4px 12px rgba(0, 0, 0, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.1) }
            .action-btn svg { width: 12px; height: 12px }
            .edit-textarea { width: 100%; min-height: 100px; padding: .75rem 1rem; background: var(--glass-bg); border: 2px solid var(--accent-primary); border-radius: var(--radius-md); color: var(--text-primary); font-family: inherit; font-size: .9375rem; line-height: 1.5; resize: vertical; margin-bottom: .5rem; outline: none; box-shadow: 0 0 0 4px var(--accent-primary-soft), var(--shadow-glow) }
            .edit-actions { display: flex; gap: .5rem; margin-top: .5rem }
            .edit-actions button { padding: .5rem 1rem; border-radius: var(--radius-md); cursor: pointer; font-weight: 500; border: none; font-size: .875rem; transition: all var(--transition-smooth) }
            .edit-actions .save-btn { background: var(--success); color: #000 }
            .edit-actions .save-btn:hover { filter: brightness(1.1); transform: translateY(-1px) }
            .edit-actions .cancel-btn { background: var(--glass-bg-elevated); color: var(--text-secondary); border: 1px solid var(--glass-border) }
            .edit-actions .cancel-btn:hover { background: var(--glass-bg-hover); color: var(--text-primary) }
            .input-container { padding: 1.25rem 2rem; border-top: 1px solid var(--glass-border); background: rgba(10, 10, 15, 0.8); backdrop-filter: blur(var(--blur-lg)) saturate(180%); -webkit-backdrop-filter: blur(var(--blur-lg)) saturate(180%) }
            .input-form { max-width: 48rem; margin: 0 auto; display: flex; gap: .75rem }
            .input-wrapper { flex: 1; display: flex; flex-direction: column; border-radius: var(--radius-2xl); border: 1px solid var(--glass-border); background: rgba(15, 25, 35, 0.6); backdrop-filter: blur(var(--blur-md)); transition: all var(--transition-smooth); overflow: hidden; position: relative }
            .input-wrapper::before { content: ''; position: absolute; inset: -2px; border-radius: calc(var(--radius-2xl) + 2px); background: var(--accent-gradient); opacity: 0; transition: opacity var(--transition-smooth); z-index: -1; filter: blur(8px) }
            .input-wrapper:focus-within { border-color: rgba(255, 255, 255, 0.2); background: rgba(20, 32, 45, 0.9); box-shadow: 0 0 20px rgba(0, 0, 0, 0.3) }
            .input-wrapper:focus-within::before { opacity: 0.3 }
            .input-wrapper:focus-within .input-controls { background: rgba(0, 0, 0, 0.5); border-top-color: rgba(255, 255, 255, 0.08) }
            .input-wrapper:focus-within .stat-box { background: rgba(0, 0, 0, 0.5) !important; border-color: rgba(255, 255, 255, 0.15) !important }
            .input-wrapper:focus-within .stat-box.status { background: rgba(0, 0, 0, 0.5) !important; color: var(--accent-primary) !important; border-color: rgba(91, 196, 232, 0.4) !important }
            .input-wrapper:focus-within .stat-box.status.pruning { background: rgba(0, 0, 0, 0.5) !important; color: var(--warning) !important; border-color: rgba(255, 214, 10, 0.3) !important }
            .input-wrapper:focus-within .stat-box.status.generating { background: rgba(0, 0, 0, 0.5) !important; color: var(--success) !important; border-color: rgba(48, 209, 88, 0.3) !important }
            .input-wrapper:focus-within .stat-box.status.error { background: rgba(0, 0, 0, 0.5) !important; color: var(--error) !important; border-color: rgba(255, 69, 58, 0.3) !important }
            .input-wrapper:focus-within .stat-box.status.learning { background: rgba(0, 0, 0, 0.5) !important; color: #FF69B4 !important; border-color: rgba(255, 105, 180, 0.3) !important }
            .input-wrapper:focus-within .stat-box.context { background: rgba(0, 0, 0, 0.5) !important; border-color: rgba(255, 255, 255, 0.2) !important }
            .input-wrapper:focus-within .stat-box.input-tokens { background: rgba(0, 0, 0, 0.5) !important; border-color: rgba(255, 255, 255, 0.2) !important }
            .input-wrapper:focus-within .stat-box.warning { background: rgba(0, 0, 0, 0.5) !important; border-color: rgba(255, 69, 58, 0.3) !important }
            .input-wrapper:focus-within .stat-box.hint { background: rgba(0, 0, 0, 0.5) !important; border-color: rgba(255, 214, 10, 0.3) !important }
            .input-wrapper:focus-within .send-btn { background: rgba(0, 0, 0, 0.5); border-color: rgba(255, 255, 255, 0.4); color: rgba(255, 255, 255, 0.7) }
            .input-wrapper:focus-within .send-btn:enabled { background: rgba(0, 0, 0, 0.4); color: rgba(255, 255, 255, 0.8) }
            .input-wrapper:focus-within .send-btn:enabled:hover { background: rgba(255, 255, 255, 0.1); color: rgba(255, 255, 255, 0.9) }
            .text-input { background: transparent; border: none; color: var(--text-primary); outline: none; width: 100%; padding: .875rem 1rem; resize: none; font-family: inherit; font-size: .9375rem; line-height: 1.5; min-height: 2.5rem; max-height: 12rem }
            .text-input::placeholder { color: var(--text-quaternary) }
            .input-wrapper:focus-within .text-input::placeholder { color: rgba(255, 255, 255, 0.5) }
            .textarea-row { display: flex; align-items: flex-end; padding-right: .75rem; gap: .25rem }
            .textarea-row .send-btn, .textarea-row .attach-btn { flex-shrink: 0; margin-bottom: .625rem; width: 2rem; height: 2rem; padding: .375rem }
            .textarea-row .send-btn svg, .textarea-row .attach-btn svg { width: 16px; height: 16px }
            .attach-btn { border-radius: var(--radius-pill); background: linear-gradient(160deg, rgba(60, 60, 72, 0.4) 0%, rgba(45, 45, 55, 0.45) 100%); border: 2px solid rgba(255, 255, 255, 0.06); color: var(--text-quaternary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all var(--transition-smooth); box-shadow: 0 0 4px rgba(255, 255, 255, 0.02), inset 0 1px 0 rgba(255, 255, 255, 0.04) }
            .attach-btn:hover { color: var(--text-secondary); background: linear-gradient(160deg, rgba(70, 70, 85, 0.45) 0%, rgba(52, 52, 62, 0.5) 100%); border-color: rgba(255, 255, 255, 0.1); transform: scale(1.05); box-shadow: 0 0 8px rgba(255, 255, 255, 0.03), 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.06) }
            .attach-btn:active { transform: scale(0.95) }
            .attach-btn.has-files { color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.2); box-shadow: 0 0 10px rgba(91, 196, 232, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.06) }
            .attachments-preview { display: flex; flex-wrap: wrap; gap: .5rem; padding: 0 2rem; max-width: 48rem; margin: 0 auto; padding-bottom: 20px }
            .attachments-preview:empty { display: none }
            .attachment-chip { display: flex; align-items: center; gap: .375rem; padding: .25rem .5rem .25rem .625rem; background: var(--accent-primary-soft); border: 1px solid rgba(91, 196, 232, 0.2); border-radius: var(--radius-pill); font-size: .75rem; color: var(--accent-primary); animation: fadeIn var(--transition-smooth) }
            .attachment-chip svg { width: 12px; height: 12px; opacity: 0.8 }
            .attachment-chip .remove-attachment { display: flex; align-items: center; justify-content: center; width: 16px; height: 16px; border: none; background: transparent; color: var(--accent-primary); cursor: pointer; border-radius: 50%; padding: 0; opacity: 0.6; transition: all var(--transition-fast) }
            .attachment-chip .remove-attachment:hover { opacity: 1; background: rgba(91, 196, 232, 0.2) }
            .attachment-chip .remove-attachment svg { width: 10px; height: 10px }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(4px) } to { opacity: 1; transform: translateY(0) } }
            .input-controls { display: flex; justify-content: space-between; align-items: center; padding: .5rem .75rem; border-top: 1px solid var(--glass-border); gap: .75rem; background: rgba(255, 255, 255, 0.02) }
            .stats-container { display: flex; gap: .5rem; flex-wrap: wrap; flex: 1; min-width: 0 }
            .stat-box { padding: .375rem .75rem; border-radius: var(--radius-pill); background: var(--liquid-glass); border: 2px solid rgba(255, 255, 255, 0.08); color: var(--text-tertiary); font-size: .7rem; font-weight: 500; display: flex; align-items: center; gap: .375rem; white-space: nowrap; max-width: 200px; flex-shrink: 0; letter-spacing: 0.02em; position: relative; overflow: hidden; box-shadow: 0 0 5px rgba(255, 255, 255, 0.02), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .stat-box::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%); pointer-events: none }
            .stat-box svg { width: 12px; height: 12px; flex-shrink: 0; opacity: 0.8; position: relative; z-index: 1 }
            .stat-box.status { background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%); color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.25); box-shadow: 0 0 8px rgba(91, 196, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.pruning { background: linear-gradient(160deg, rgba(255, 214, 10, 0.15) 0%, rgba(220, 180, 10, 0.2) 100%); color: var(--warning); border-color: rgba(255, 214, 10, 0.25); box-shadow: 0 0 8px rgba(255, 214, 10, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.generating { background: linear-gradient(160deg, rgba(48, 209, 88, 0.15) 0%, rgba(40, 175, 70, 0.2) 100%); color: var(--success); border-color: rgba(48, 209, 88, 0.25); box-shadow: 0 0 8px rgba(48, 209, 88, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.error { background: linear-gradient(160deg, rgba(255, 69, 58, 0.15) 0%, rgba(220, 55, 48, 0.2) 100%); color: var(--error); border-color: rgba(255, 69, 58, 0.25); box-shadow: 0 0 8px rgba(255, 69, 58, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.routing { background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%); color: var(--accent-secondary); border-color: rgba(75, 190, 232, 0.25); box-shadow: 0 0 8px rgba(75, 190, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.indexing { background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%); color: var(--accent-secondary); border-color: rgba(75, 190, 232, 0.25); box-shadow: 0 0 8px rgba(75, 190, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.searching { background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%); color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.25); box-shadow: 0 0 8px rgba(91, 196, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.switching { background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%); color: var(--accent-secondary); border-color: rgba(75, 190, 232, 0.25); box-shadow: 0 0 8px rgba(75, 190, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.ready { background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%); color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.25); box-shadow: 0 0 8px rgba(91, 196, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.stopped { background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%); color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.25); box-shadow: 0 0 8px rgba(91, 196, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.offline { background: linear-gradient(160deg, rgba(255, 69, 58, 0.15) 0%, rgba(220, 55, 48, 0.2) 100%); color: var(--error); border-color: rgba(255, 69, 58, 0.25); box-shadow: 0 0 8px rgba(255, 69, 58, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.learning { background: linear-gradient(160deg, rgba(255, 105, 180, 0.15) 0%, rgba(255, 20, 147, 0.2) 100%); color: #FF69B4; border-color: rgba(255, 105, 180, 0.25); box-shadow: 0 0 8px rgba(255, 105, 180, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.status.warning { background: linear-gradient(160deg, rgba(255, 214, 10, 0.15) 0%, rgba(220, 180, 10, 0.2) 100%); color: var(--warning); border-color: rgba(255, 214, 10, 0.25); box-shadow: 0 0 8px rgba(255, 214, 10, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.context { background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%); color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.25); box-shadow: 0 0 8px rgba(91, 196, 232, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .stat-box.pruning { background: var(--success-soft); color: var(--success); border-color: rgba(48, 209, 88, 0.2) }
            .stat-box.input-tokens { background: var(--accent-secondary-soft); color: var(--accent-secondary); border-color: rgba(75, 190, 232, 0.2); margin-left: auto }
            .stat-box.context-warning { background: var(--warning-soft); color: var(--warning); border-color: rgba(255, 214, 10, 0.2); animation: pulse-glow 2s infinite }
            .stat-box.context-hint { background: var(--accent-primary-soft); color: var(--accent-primary); border-color: rgba(91, 196, 232, 0.2); animation: pulse-glow 2s infinite }
            .stat-box.context-error { background: var(--error-soft); color: var(--error); border-color: rgba(255, 69, 58, 0.2); animation: pulse-glow 2s infinite }
            @keyframes pulse-glow { 0%, 100% { opacity: 1; box-shadow: 0 0 0 0 transparent } 50% { opacity: 0.8; box-shadow: 0 0 12px rgba(255, 69, 58, 0.3) } }
            .input-actions { display: flex; gap: .5rem; align-items: center }
            .send-btn { padding: .5rem; border-radius: var(--radius-pill); background: var(--liquid-glass); border: 2px solid rgba(255, 255, 255, 0.08); color: var(--text-quaternary); cursor: pointer; display: flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; transition: all var(--transition-spring); position: relative; overflow: hidden; box-shadow: 0 0 5px rgba(255, 255, 255, 0.02), 0 2px 6px rgba(0, 0, 0, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .send-btn::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%); pointer-events: none }
            .send-btn::after { content: ''; position: absolute; top: -50%; left: -100%; width: 60%; height: 200%; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.15) 50%, transparent 100%); transform: skewX(-20deg); pointer-events: none; opacity: 0; transition: opacity 0.2s ease }
            .send-btn:enabled { color: var(--text-primary); background: linear-gradient(160deg, rgba(91, 196, 232, 0.2) 0%, rgba(75, 190, 220, 0.25) 100%); border-color: rgba(91, 196, 232, 0.3); box-shadow: 0 0 12px rgba(91, 196, 232, 0.15), 0 2px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.1) }
            .send-btn:enabled::after { animation: send-btn-shine 2.5s ease-in-out infinite }
            @keyframes send-btn-shine { 0% { left: -100%; opacity: 0 } 10% { opacity: 1 } 40% { left: 150%; opacity: 1 } 50%, 100% { left: 150%; opacity: 0 } }
            .send-btn:enabled:hover { background: linear-gradient(160deg, rgba(91, 196, 232, 0.3) 0%, rgba(75, 190, 220, 0.35) 100%); border-color: rgba(91, 196, 232, 0.45); transform: translateY(-2px) scale(1.08); box-shadow: 0 0 20px rgba(91, 196, 232, 0.25), 0 8px 20px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.15) }
            .send-btn:enabled:hover::after { animation: send-btn-shine-fast 1s ease-in-out infinite }
            @keyframes send-btn-shine-fast { 0% { left: -100%; opacity: 0 } 15% { opacity: 1 } 60% { left: 150%; opacity: 1 } 70%, 100% { left: 150%; opacity: 0 } }
            .send-btn:enabled:active { transform: translateY(0) scale(0.95); box-shadow: 0 0 8px rgba(91, 196, 232, 0.1), 0 1px 3px rgba(0, 0, 0, 0.15) }
            .send-btn:enabled:active::after { animation: none; opacity: 0 }
            .send-btn svg { position: relative; z-index: 1 }
            .welcome-screen { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; z-index: 10; pointer-events: none }
            .welcome-content { text-align: center; padding: 4rem 1rem; max-width: 48rem; margin: 0 auto }
            .welcome-title { font-family: 'Quicksand', sans-serif; font-size: 2.5rem; font-weight: 700; margin-bottom: .5rem; background: var(--accent-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; position: relative; display: inline-block; letter-spacing: -0.02em; filter: drop-shadow(0 0 30px rgba(91, 196, 232, 0.4)) }
            .welcome-subtitle { font-family: 'Quicksand', sans-serif; font-size: 0.8rem; color: var(--text-quaternary); letter-spacing: 0.15em; text-transform: uppercase; font-weight: 600; margin-bottom: 3rem }
            .welcome-subtitle::before { content: "✦"; color: var(--accent-primary); padding-right: 8px; opacity: 0.7 }
            .suggestions { max-width: 36rem; margin: 0 auto }
            .suggestions-title { display: flex; align-items: center; gap: .5rem; justify-content: center; font-size: .7rem; color: var(--text-quaternary); margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600 }
            .suggestions-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: .75rem }
            .suggestion-btn { background: linear-gradient(160deg, rgba(70, 70, 85, 0.55) 0%, rgba(50, 50, 62, 0.6) 50%, rgba(45, 45, 58, 0.65) 100%); backdrop-filter: blur(var(--blur-md)); -webkit-backdrop-filter: blur(var(--blur-md)); border: 2px solid rgba(255, 255, 255, 0.12); padding: 1rem 1.25rem; border-radius: var(--radius-xl); text-align: left; cursor: pointer; transition: all var(--transition-smooth); pointer-events: auto; position: relative; overflow: hidden; box-shadow: 0 0 8px rgba(255, 255, 255, 0.03), 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .suggestion-btn::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%); pointer-events: none }
            .suggestion-btn:hover { background: linear-gradient(160deg, rgba(80, 80, 98, 0.6) 0%, rgba(58, 58, 72, 0.65) 50%, rgba(52, 52, 66, 0.7) 100%); border-color: rgba(255, 255, 255, 0.2); transform: translateY(-3px); box-shadow: 0 0 12px rgba(255, 255, 255, 0.06), 0 8px 24px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.1) }
            .suggestion-btn:active { transform: translateY(-1px); box-shadow: 0 0 6px rgba(255, 255, 255, 0.04), 0 2px 8px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.06) }
            .suggestion-title { font-weight: 500; color: var(--text-secondary); margin-bottom: .25rem; font-size: .9rem }
            .suggestion-btn:hover .suggestion-title { color: var(--text-primary) }
            .suggestion-desc { font-size: .75rem; color: var(--text-quaternary); line-height: 1.4 }
            .settings-panel { position: fixed; top: 0; right: 0; bottom: 0; width: 470px; background: var(--bg-panel); backdrop-filter: blur(var(--blur-xl)) saturate(180%); -webkit-backdrop-filter: blur(var(--blur-xl)) saturate(180%); border-left: 1px solid var(--glass-border); z-index: 1000; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; overflow: hidden }
            .settings-panel::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.1) 20%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.1) 80%, transparent 100%); z-index: 1 }
            .settings-panel.open { transform: translateX(0) }
            .settings-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--glass-border); display: flex; align-items: center; justify-content: space-between; background: rgba(255, 255, 255, 0.02) }
            .settings-title { font-size: 1.25rem; font-weight: 600; color: var(--text-primary); letter-spacing: -0.02em }
            .settings-content { flex: 1; overflow-y: auto; padding: 2rem }
            .settings-group { background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-xl); padding: 1.5rem; margin-bottom: 1.5rem; position: relative }
            .settings-group::before { content: ''; position: absolute; top: 0; left: 30px; right: 30px; height: 1px; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.1) 50%, transparent 100%) }
            .settings-item { margin-bottom: 1.25rem }
            .settings-item:last-child { margin-bottom: 0 }
            .settings-label { display: block; font-size: .875rem; font-weight: 500; color: var(--text-secondary); margin-bottom: .5rem }
            .settings-input, .settings-select, .settings-textarea { width: 100%; padding: .75rem 1rem; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-md); color: var(--text-primary); font-size: .875rem; transition: all var(--transition-smooth) }
            .settings-textarea { min-height: 100px; resize: vertical; font-family: inherit }
            .settings-input:focus, .settings-select:focus, .settings-textarea:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px var(--accent-primary-soft), var(--shadow-glow) }
            .settings-hint { font-size: .75rem; color: var(--text-quaternary); margin-top: .375rem; line-height: 1.4 }
            .settings-checkbox { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem }
            .settings-checkbox input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; border-radius: 5px; accent-color: var(--accent-primary) }
            .settings-checkbox label { font-size: .875rem; font-weight: 500; color: var(--text-secondary); cursor: pointer }
            .settings-accordion { border: 1px solid var(--glass-border); border-radius: var(--radius-md); margin-bottom: 0.75rem; overflow: hidden; background: rgba(255, 255, 255, 0.02) }
            .accordion-header { display: flex; align-items: center; gap: 0.5rem; padding: 0.875rem 1rem; cursor: pointer; user-select: none; transition: background var(--transition-smooth); font-size: 0.875rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.03em }
            .accordion-header:hover { background: rgba(255, 255, 255, 0.04) }
            .accordion-icon { width: 16px; height: 16px; transition: transform 0.3s ease; color: var(--text-quaternary) }
            .settings-accordion.open .accordion-icon { transform: rotate(180deg) }
            .accordion-header .pruning-status { margin-left: auto }
            .accordion-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease, padding 0.3s ease; padding: 0 1rem }
            .settings-accordion.open .accordion-content { max-height: 2000px; padding: 0.5rem 1rem 1rem 1rem }
            .settings-actions { padding: 1.5rem 2rem; border-top: 1px solid var(--glass-border); background: rgba(255, 255, 255, 0.02); display: flex; flex-direction: column; gap: .75rem }
            .settings-btn { width: 100%; padding: .875rem; border-radius: var(--radius-lg); font-weight: 500; font-size: .875rem; cursor: pointer; transition: all var(--transition-smooth); border: none; display: flex; align-items: center; justify-content: center; gap: .5rem; position: relative; overflow: hidden }
            .settings-btn::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.12) 0%, transparent 100%); pointer-events: none }
            .settings-btn.save { background: linear-gradient(160deg, rgba(55, 210, 95, 0.95) 0%, rgba(40, 185, 75, 0.95) 100%); color: #000; border: 2px solid rgba(255, 255, 255, 0.2); box-shadow: 0 0 12px rgba(48, 209, 88, 0.2), 0 4px 12px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2) }
            .settings-btn.save:hover:not(:disabled) { background: linear-gradient(160deg, rgba(65, 225, 105, 0.95) 0%, rgba(50, 200, 85, 0.95) 100%); border-color: rgba(255, 255, 255, 0.3); transform: translateY(-2px); box-shadow: 0 0 18px rgba(48, 209, 88, 0.3), 0 6px 20px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.25) }
            .settings-btn.save:active:not(:disabled) { transform: translateY(0); box-shadow: 0 0 8px rgba(48, 209, 88, 0.15), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.15) }
            .settings-btn.cancel { background: linear-gradient(160deg, rgba(70, 70, 85, 0.55) 0%, rgba(50, 50, 62, 0.6) 100%); color: var(--text-secondary); border: 2px solid rgba(255, 255, 255, 0.1); box-shadow: 0 0 6px rgba(255, 255, 255, 0.02), 0 2px 8px rgba(0, 0, 0, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .settings-btn.cancel:hover { background: linear-gradient(160deg, rgba(80, 80, 98, 0.6) 0%, rgba(58, 58, 72, 0.65) 100%); color: var(--text-primary); border-color: rgba(255, 255, 255, 0.15); transform: translateY(-1px); box-shadow: 0 0 10px rgba(255, 255, 255, 0.03), 0 4px 12px rgba(0, 0, 0, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.07) }
            .settings-btn.cancel:active { transform: translateY(0); box-shadow: 0 0 4px rgba(255, 255, 255, 0.02), 0 1px 4px rgba(0, 0, 0, 0.12) }
            .settings-btn:disabled { opacity: 0.5; cursor: not-allowed }
            .governor-section { margin-top: 1rem }
            .governor-title { font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 1rem; display: flex; align-items: center; gap: .5rem }
            .governor-title svg { width: 20px; height: 20px; color: var(--accent-primary) }
            .vram-display { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-md); margin-bottom: 1rem }
            .vram-icon { width: 24px; height: 24px; color: var(--accent-primary) }
            .vram-info { flex: 1 }
            .vram-label { font-size: .75rem; color: var(--text-quaternary); text-transform: uppercase; letter-spacing: 0.05em }
            .vram-value { font-size: .875rem; font-weight: 600; color: var(--text-primary) }
            .vram-gpu { font-size: .75rem; color: var(--text-tertiary) }
            .vram-error { color: var(--error); font-size: .8rem }
            .vram-loading { color: var(--text-tertiary); font-size: .8rem; font-style: italic }
            .model-select-wrapper { margin-bottom: 1rem }
            .model-select-label { display: flex; align-items: center; justify-content: space-between; margin-bottom: .5rem }
            .model-select-label span { font-size: .875rem; font-weight: 500; color: var(--text-secondary) }
            .model-size { font-size: .75rem; color: var(--text-quaternary) }
            .model-select { width: 100%; padding: .75rem 1rem; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-md); color: var(--text-primary); font-size: .875rem; transition: all var(--transition-smooth); cursor: pointer }
            .model-select:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px var(--accent-primary-soft) }
            .model-select option { background: #1a1a24; color: #e5e5e5; padding: 0.5rem }
            .expert-name-input { width: 100%; padding: .625rem 1rem; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-md); color: var(--text-primary); font-size: .875rem; transition: all var(--transition-smooth) }
            .expert-name-input:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px var(--accent-primary-soft) }
            .expert-name-input::placeholder { color: var(--text-quaternary) }
            .expert-slot { padding-bottom: 1rem; border-bottom: 1px solid var(--glass-border); margin-bottom: 1rem }
            .expert-slot:last-child { border-bottom: none; margin-bottom: 0 }
            .expert-prompt-item[data-slot] { margin-bottom: 1rem }
            @keyframes pulse { 0%, 100% { opacity: 1 } 50% { opacity: 0.4 } }
            .startup-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.85); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; z-index: 2000; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s }
            .startup-overlay.active { opacity: 1; visibility: visible }
            .startup-content { text-align: center; padding: 2rem }
            .startup-spinner { width: 220px; height: 18px; border-radius: 6px; background: linear-gradient(160deg, rgba(50, 50, 60, 0.5) 0%, rgba(35, 35, 45, 0.6) 100%); border: 2px solid rgba(255, 255, 255, 0.15); overflow: hidden; position: relative; margin: 0 auto 1.5rem; box-shadow: inset 0 2px 6px rgba(0, 0, 0, 0.4), 0 0 16px rgba(91, 196, 232, 0.15), 0 4px 12px rgba(0, 0, 0, 0.3) }
            .startup-spinner::before { content: ''; position: absolute; top: 0; left: 0; height: 100%; width: 100%; background: linear-gradient(90deg, #5BC4E8 0%, #7DD87D 25%, #5DD8A6 50%, #4BBEE8 75%, #5BC4E8 100%); background-size: 200% 100%; animation: aero-shine 1.5s ease-in-out infinite; border-radius: 4px; box-shadow: 0 0 24px rgba(91, 196, 232, 0.8), 0 0 12px rgba(125, 216, 125, 0.5), inset 0 1px 2px rgba(255, 255, 255, 0.5), inset 0 -1px 1px rgba(0, 0, 0, 0.2) }
            .startup-spinner::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.5) 0%, rgba(255, 255, 255, 0.1) 50%, transparent 100%); border-radius: 4px 4px 0 0; pointer-events: none }
            @keyframes aero-shine { 0% { background-position: 0% 50% } 50% { background-position: 100% 50% } 100% { background-position: 0% 50% } }
            .startup-spinner .sparkle { position: absolute; top: 0; left: -100px; width: 80px; height: 100%; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.8) 50%, transparent 100%); animation: sparkle-pulse 2s ease-in-out infinite; pointer-events: none; transform: skewX(-20deg) }
            @keyframes sparkle-pulse { 0% { left: -100px; opacity: 0 } 20% { opacity: 1 } 80% { opacity: 1 } 100% { left: 280px; opacity: 0 } }
            .startup-title { font-size: 1.25rem; font-weight: 600; color: var(--text-primary); margin-bottom: .5rem }
            .startup-message { color: var(--text-tertiary); font-size: .875rem; max-width: 300px }
            .startup-error { color: var(--error); font-size: .875rem; margin-top: 1rem; max-width: 400px; text-align: left; background: var(--error-soft); padding: 1rem; border-radius: var(--radius-md); white-space: pre-wrap; font-family: monospace; font-size: .75rem; max-height: 200px; overflow-y: auto }
            .modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; z-index: 1100; opacity: 0; visibility: hidden; transition: opacity 0.3s, visibility 0.3s }
            .modal.active { opacity: 1; visibility: visible }
            .modal-content { background: rgba(20, 20, 28, 0.95); backdrop-filter: blur(var(--blur-xl)) saturate(200%); -webkit-backdrop-filter: blur(var(--blur-xl)) saturate(200%); border: 1px solid var(--glass-border); border-radius: var(--radius-2xl); padding: 1.5rem; max-width: 400px; width: 90%; margin: 1rem; box-shadow: var(--shadow-lg); position: relative; animation: modalSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) }
            @keyframes modalSlideIn { from { opacity: 0; transform: scale(0.95) translateY(10px) } to { opacity: 1; transform: scale(1) translateY(0) } }
            .modal-content::before { content: ''; position: absolute; top: 0; left: 30px; right: 30px; height: 1px; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.15) 50%, transparent 100%) }
            .modal-title { font-size: 1.125rem; font-weight: 600; color: var(--text-primary); margin-bottom: .75rem }
            .modal-message { color: var(--text-tertiary); margin-bottom: 1.5rem; line-height: 1.5 }
            .modal-actions { display: flex; gap: .75rem; justify-content: flex-end }
            .modal-btn { padding: .625rem 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--glass-border); background: var(--glass-bg-elevated); color: var(--text-secondary); cursor: pointer; font-weight: 500; transition: all var(--transition-smooth) }
            .modal-btn:hover { background: var(--glass-bg-hover); color: var(--text-primary) }
            .modal-btn.danger { background: var(--error-soft); border-color: rgba(255, 69, 58, 0.3); color: var(--error) }
            .modal-btn.danger:hover { background: rgba(255, 69, 58, 0.25) }
            .modal-btn.confirm { background: var(--success); color: #000; border: none }
            .modal-btn.confirm:hover { filter: brightness(1.1) }
            .modal-input { width: 100%; padding: .875rem 1rem; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-md); color: var(--text-primary); font-size: .875rem; margin-bottom: 1.5rem; transition: all var(--transition-smooth) }
            .modal-input:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px var(--accent-primary-soft) }
            @keyframes spin { from { transform: rotate(0deg) } to { transform: rotate(360deg) } }
            @keyframes blink { 0%, 50% { opacity: 1 } 51%, 100% { opacity: 0 } }
            @keyframes waterfall { from { opacity: 0; transform: translateY(15px) } to { opacity: 1; transform: translateY(0) } }
            .spinner { animation: spin 1s linear infinite }
            .typing-cursor { display: inline-block; width: 2px; height: 18px; background: var(--accent-primary); margin-left: 3px; vertical-align: middle; animation: blink 1s infinite; border-radius: 1px; box-shadow: 0 0 8px var(--accent-primary) }
            .streaming-text { animation: streamPulse 0.15s ease-out }
            @keyframes streamPulse { from { opacity: 0.85 } to { opacity: 1 } }
            .waterfall { animation: waterfall 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards }
            .hidden { display: none !important }
            .scrollbar-hidden { scrollbar-width: none; -ms-overflow-style: none }
            .scrollbar-hidden::-webkit-scrollbar { display: none }
            .sidebar-collapsed .sidebar { transform: translateX(-100%) }
            .sidebar-collapsed .header-sidebar-toggle { display: flex }
            .mobile-sidebar-toggle { position: fixed; top: 1rem; left: 1rem; z-index: 40; padding: .5rem; border-radius: var(--radius-md); background: var(--glass-bg-elevated); backdrop-filter: blur(var(--blur-md)); border: 1px solid var(--glass-border); color: var(--text-tertiary); cursor: pointer; display: none; align-items: center; justify-content: center; transition: all var(--transition-smooth) }
            .mobile-sidebar-toggle:hover { background: var(--glass-bg-hover); color: var(--text-primary) }
            @media (max-width:768px) { .sidebar-logo-title { font-size: 1.1rem } .sidebar-logo-subtitle { font-size: 0.6rem } .welcome-title { font-size: 2rem } .suggestions-grid { grid-template-columns: 1fr } .settings-panel { width: 100% } }
            @media (max-width:480px) { .sidebar-logo-title { font-size: 1rem } .sidebar-logo-subtitle { font-size: 0.55rem } .welcome-title { font-size: 1.75rem } .chat-header, .input-container, .messages-container { padding-left: 1rem; padding-right: 1rem } }
            .pruned-container { margin-top: 10px; border-left: 3px solid var(--warning); padding-left: 10px; }
            .pruned-toggle { display: flex; align-items: center; gap: 6px; font-size: 0.8rem; color: var(--warning); cursor: pointer; margin-bottom: 8px; padding: 6px 12px; border-radius: var(--radius-md); background: linear-gradient(160deg, rgba(255, 214, 10, 0.15) 0%, rgba(220, 180, 10, 0.2) 100%); border: 2px solid rgba(255, 214, 10, 0.25); transition: all var(--transition-smooth); user-select: none; position: relative; overflow: hidden; box-shadow: 0 0 8px rgba(255, 214, 10, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.1) }
            .pruned-toggle::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%); pointer-events: none }
            .pruned-toggle:hover { background: linear-gradient(160deg, rgba(255, 214, 10, 0.22) 0%, rgba(220, 180, 10, 0.28) 100%); border-color: rgba(255, 214, 10, 0.35); box-shadow: 0 0 12px rgba(255, 214, 10, 0.15), 0 4px 10px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.12) }
            .pruned-toggle svg { width: 14px; height: 14px; transition: transform var(--transition-smooth); position: relative; z-index: 1 }
            .pruned-toggle.collapsed svg { transform: rotate(-90deg); }
            .pruned-toggle.expanded svg { transform: rotate(0deg); }
            .pruned-content { padding: 12px; background: linear-gradient(160deg, rgba(255, 214, 10, 0.08) 0%, rgba(220, 180, 10, 0.1) 100%); border: 2px solid rgba(255, 214, 10, 0.15); border-radius: var(--radius-md); font-size: 0.9rem; color: var(--text-tertiary); position: relative; margin-bottom: 10px; overflow: hidden; box-shadow: 0 0 6px rgba(255, 214, 10, 0.05), 0 2px 8px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .pruned-content::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 40%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.04) 0%, transparent 100%); pointer-events: none }
            .pruned-content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; padding-bottom: 4px; border-bottom: 1px solid rgba(255, 214, 10, 0.15); position: relative; z-index: 1 }
            .pruned-label { font-size: 0.75rem; font-weight: 600; color: var(--warning); text-transform: uppercase; letter-spacing: 0.05em; }
            .edit-pruned-btn { padding: 4px 10px; background: var(--liquid-glass); border: 2px solid rgba(255, 255, 255, 0.08); border-radius: var(--radius-sm); color: var(--text-tertiary); cursor: pointer; font-size: 0.7rem; display: flex; align-items: center; gap: 4px; transition: all var(--transition-smooth); position: relative; overflow: hidden; box-shadow: 0 0 4px rgba(255, 255, 255, 0.02), 0 2px 4px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .edit-pruned-btn::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%); pointer-events: none }
            .edit-pruned-btn:hover { background: var(--liquid-glass-hover); border-color: rgba(255, 255, 255, 0.15); color: var(--text-primary); box-shadow: 0 0 6px rgba(255, 255, 255, 0.03), 0 3px 8px rgba(0, 0, 0, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.08) }
            .pruned-stats { display: flex; gap: 12px; font-size: 0.7rem; color: var(--text-quaternary); margin-top: 8px; position: relative; z-index: 1 }
            .pruned-stats span { display: flex; align-items: center; gap: 4px; }
            .pruned-stats .saved { color: var(--success); }
            .message.pruned-message { border-left: 3px solid var(--success); background: linear-gradient(90deg, rgba(48, 209, 88, 0.08) 0%, rgba(0, 0, 0, 0.25) 30px); }
            .message.indexed-message { border-left: 3px solid var(--accent-secondary); background: linear-gradient(90deg, rgba(75, 190, 232, 0.08) 0%, rgba(0, 0, 0, 0.25) 30px); opacity: 0.7; }
            .message.indexed-message .indexed-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 0.65rem; color: var(--accent-secondary); background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%); border: 1px solid rgba(75, 190, 232, 0.25); padding: 3px 10px; border-radius: var(--radius-sm); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em; box-shadow: 0 0 6px rgba(75, 190, 232, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.08) font-weight: 600; }
            .message.recalled-message { border-left: 3px solid var(--accent-primary); background: linear-gradient(90deg, rgba(91, 196, 232, 0.05) 0%, transparent 10px); }
            .message.recalled-message .recalled-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 0.65rem; color: var(--accent-primary); background: rgba(91, 196, 232, 0.1); padding: 2px 8px; border-radius: var(--radius-sm); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
            .velocity-stats { display: flex; gap: 16px; padding: 12px; background: var(--glass-bg); border-radius: var(--radius-sm); border: 1px solid var(--glass-border); margin-top: 12px; }
            .velocity-stat { display: flex; flex-direction: column; align-items: center; gap: 2px; }
            .velocity-stat-label { font-size: 0.65rem; color: var(--text-quaternary); text-transform: uppercase; letter-spacing: 0.05em; }
            .velocity-stat-value { font-size: 1.25rem; font-weight: 600; color: var(--accent-secondary); }
            .settings-row { display: flex; gap: 1rem; margin-bottom: 0.5rem; }
            .server-status-row { display: flex; gap: 1rem; margin-top: 1rem; }
            .header-server-status { display: flex; gap: 0.5rem; margin-left: 1rem; }
            .server-status { display: flex; align-items: center; gap: 0.5rem; padding: 0.625rem 0.875rem; background: var(--liquid-glass); border: 2px solid rgba(255, 255, 255, 0.08); border-radius: var(--radius-md); font-size: 0.75rem; color: var(--text-tertiary); position: relative; overflow: hidden; box-shadow: 0 0 5px rgba(255, 255, 255, 0.02), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .server-status::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%); pointer-events: none }
            .server-status.compact { padding: 0.375rem 0.625rem; font-size: 0.7rem; border-radius: var(--radius-sm) }
            .server-status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-quaternary); flex-shrink: 0; position: relative; z-index: 1 }
            .server-status.online .server-status-dot { background: var(--success); box-shadow: 0 0 8px var(--success) }
            .server-status.online { color: var(--success); border-color: rgba(48, 209, 88, 0.3); box-shadow: 0 0 8px rgba(48, 209, 88, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .server-status.offline .server-status-dot { background: var(--text-quaternary) }
            .server-status.starting .server-status-dot { background: var(--warning); animation: pulse-glow 1.5s infinite }
            .server-status.starting { border-color: rgba(255, 214, 10, 0.25); box-shadow: 0 0 8px rgba(255, 214, 10, 0.08), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .server-status.error .server-status-dot { background: var(--error); box-shadow: 0 0 8px var(--error) }
            .server-status.error { border-color: rgba(255, 69, 58, 0.25); box-shadow: 0 0 8px rgba(255, 69, 58, 0.1), 0 2px 6px rgba(0, 0, 0, 0.15), inset 0 1px 0 rgba(255, 255, 255, 0.05) }
            .context-meter { height: 6px; border-radius: var(--radius-pill); background: linear-gradient(160deg, rgba(50, 50, 60, 0.5) 0%, rgba(35, 35, 45, 0.6) 100%); border: 1px solid rgba(255, 255, 255, 0.06); overflow: hidden; margin-top: 4px; box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.2), 0 0 4px rgba(255, 255, 255, 0.02) }
            .context-meter-fill { height: 100%; transition: width var(--transition-smooth); border-radius: var(--radius-pill); position: relative }
            .context-meter-fill::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.3) 0%, transparent 100%); border-radius: var(--radius-pill) var(--radius-pill) 0 0 }
            .context-meter-fill.safe { background: linear-gradient(90deg, rgba(91, 196, 232, 0.8) 0%, rgba(91, 196, 232, 1) 100%); box-shadow: 0 0 8px rgba(91, 196, 232, 0.4) }
            .context-meter-fill.warning { background: linear-gradient(90deg, rgba(255, 214, 10, 0.8) 0%, rgba(255, 214, 10, 1) 100%); box-shadow: 0 0 8px rgba(255, 214, 10, 0.4) }
            .context-meter-fill.critical { background: linear-gradient(90deg, rgba(255, 69, 58, 0.8) 0%, rgba(255, 69, 58, 1) 100%); box-shadow: 0 0 8px rgba(255, 69, 58, 0.4) }
            .wmc-wizard { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(135deg, #1a2332 0%, #0d1117 50%, #1a2838 100%); z-index: 99999; display: flex; align-items: center; justify-content: center; opacity: 0; animation: wmcFadeIn 0.8s ease-out forwards }
            @keyframes wmcFadeIn { to { opacity: 1 } }
            .wmc-container { width: 100%; height: 100%; display: flex; flex-direction: column; position: relative }
            .wmc-header { padding: 1.25rem 3rem; background: linear-gradient(180deg, rgba(255, 255, 255, 0.12) 0%, rgba(255, 255, 255, 0.08) 50%, rgba(255, 255, 255, 0.04) 100%); border-bottom: 1px solid rgba(91, 196, 232, 0.3); box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.15), 0 1px 3px rgba(0, 0, 0, 0.3); backdrop-filter: blur(10px); position: relative; overflow: hidden }
            .wmc-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.3) 50%, transparent 100%) }
            .wmc-header-title { font-size: 1.75rem; font-weight: 300; color: #ffffff; letter-spacing: 0.02em; margin-bottom: 0; text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5) }
            .wmc-header-subtitle { font-size: 0.875rem; color: rgba(255, 255, 255, 0.6); font-weight: 300 }
            .wmc-content { flex: 1; display: flex; align-items: center; justify-content: center; padding: 3rem; overflow: hidden }
            .wmc-page-container { width: 100%; max-width: 1400px; height: 100%; display: flex; align-items: center; gap: 4rem }
            .wmc-left { flex: 1; display: flex; flex-direction: column; justify-content: center; max-width: 700px }
            .wmc-right { width: 400px; height: 100%; display: flex; align-items: center; justify-content: center; position: relative }
            .wmc-icon-container { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; position: relative }
            .wmc-icon-container::before { content: ''; position: absolute; inset: -40px; background: radial-gradient(circle, rgba(91, 196, 232, 0.2) 0%, transparent 70%); animation: iconPulse 3s ease-in-out infinite }
            @keyframes iconPulse { 0%, 100% { opacity: 0.5; transform: scale(0.95) } 50% { opacity: 1; transform: scale(1.05) } }
            .wmc-icon { font-size: 18rem; filter: drop-shadow(0 20px 60px rgba(91, 196, 232, 0.5)); animation: floatIcon 4s ease-in-out infinite }
            @keyframes floatIcon { 0%, 100% { transform: translateY(0) rotate(0deg) } 50% { transform: translateY(-20px) rotate(3deg) } }
            .wmc-page-title { font-size: 2.5rem; font-weight: 300; color: #ffffff; margin-bottom: 1.5rem; text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3) }
            .wmc-description { font-size: 1.125rem; color: rgba(255, 255, 255, 0.75); line-height: 1.8; margin-bottom: 2.5rem; font-weight: 300 }
            .wmc-section { margin-bottom: 2rem }
            .wmc-section-label { font-size: 0.875rem; color: rgba(255, 255, 255, 0.5); margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600 }
            .wmc-select-wrapper { position: relative }
            .wmc-select { width: 100%; padding: 1rem 1.25rem; background: rgba(0, 0, 0, 0.3); border: 2px solid rgba(91, 196, 232, 0.3); border-radius: 4px; color: #ffffff; font-size: 1.125rem; font-weight: 400; appearance: none; cursor: pointer; transition: all 0.3s; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='rgba(91,196,232,1)'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; background-size: 20px; padding-right: 3rem }
            .wmc-select:hover { border-color: rgba(91, 196, 232, 0.5); background-color: rgba(0, 0, 0, 0.4) }
            .wmc-select:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px rgba(91, 196, 232, 0.2); background-color: rgba(0, 0, 0, 0.5) }
            .wmc-select option { background: #0d1117; color: #ffffff; padding: 0.75rem }
            .wmc-toggle-group { display: flex; align-items: center; gap: 1rem; margin-bottom: 2rem }
            .wmc-toggle { width: 60px; height: 30px; background: rgba(255, 255, 255, 0.1); border-radius: 15px; position: relative; cursor: pointer; transition: all 0.3s; border: 2px solid rgba(255, 255, 255, 0.2) }
            .wmc-toggle.active { background: var(--accent-primary); border-color: var(--accent-primary); box-shadow: 0 0 20px rgba(91, 196, 232, 0.5) }
            .wmc-toggle-knob { width: 22px; height: 22px; background: white; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: all 0.3s; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.3) }
            .wmc-toggle.active .wmc-toggle-knob { transform: translateX(30px) }
            .wmc-toggle-label { font-size: 1.125rem; color: rgba(255, 255, 255, 0.75); font-weight: 300 }
            .wmc-input { width: 100%; padding: 1rem 1.25rem; background: rgba(0, 0, 0, 0.3); border: 2px solid rgba(91, 196, 232, 0.3); border-radius: 4px; color: #ffffff; font-size: 1.125rem; transition: all 0.3s }
            .wmc-input:hover { border-color: rgba(91, 196, 232, 0.5); background-color: rgba(0, 0, 0, 0.4) }
            .wmc-input:focus { outline: none; border-color: var(--accent-primary); box-shadow: 0 0 0 3px rgba(91, 196, 232, 0.2); background-color: rgba(0, 0, 0, 0.5) }
            .wmc-recommendation { background: rgba(91, 196, 232, 0.08); border-left: 3px solid var(--accent-primary); padding: 1.25rem 1.5rem; margin-top: 2rem; border-radius: 0 4px 4px 0 }
            .wmc-recommendation-title { font-size: 0.875rem; font-weight: 600; color: var(--accent-primary); margin-bottom: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em }
            .wmc-recommendation-text { font-size: 1rem; color: rgba(255, 255, 255, 0.7); line-height: 1.7 }
            .wmc-footer { padding: 1.25rem 3rem; background: linear-gradient(180deg, rgba(255, 255, 255, 0.04) 0%, rgba(255, 255, 255, 0.08) 50%, rgba(255, 255, 255, 0.12) 100%); border-top: 1px solid rgba(91, 196, 232, 0.3); display: flex; justify-content: space-between; align-items: center; box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.15), 0 -1px 3px rgba(0, 0, 0, 0.3); backdrop-filter: blur(10px); position: relative; overflow: hidden }
            .wmc-footer::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.3) 50%, transparent 100%) }
            .wmc-btn { padding: 0.875rem 2.5rem; border-radius: 4px; font-size: 1rem; font-weight: 400; cursor: pointer; transition: all 0.3s; border: none; text-transform: capitalize; letter-spacing: 0.02em; min-width: 120px }
            .wmc-btn-back { background: transparent; border: 2px solid rgba(255, 255, 255, 0.3); color: rgba(255, 255, 255, 0.7) }
            .wmc-btn-back:hover { border-color: rgba(255, 255, 255, 0.5); color: #ffffff; background: rgba(255, 255, 255, 0.05) }
            .wmc-btn-next { background: var(--accent-primary); color: #000000; font-weight: 500; box-shadow: 0 4px 12px rgba(91, 196, 232, 0.3) }
            .wmc-btn-next:hover { background: #6bd0f0; box-shadow: 0 6px 16px rgba(91, 196, 232, 0.4); transform: translateY(-1px) }
            .wmc-btn-cancel { background: linear-gradient(180deg, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0.12) 50%, rgba(255, 255, 255, 0.08) 100%); color: rgba(255, 255, 255, 0.85); border: 2px solid rgba(255, 255, 255, 0.3); padding: 0.875rem 2rem; cursor: pointer; transition: all 0.3s; border-radius: 4px; box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.4), inset 0 -1px 0 rgba(0, 0, 0, 0.15), 0 3px 6px rgba(0, 0, 0, 0.3); min-width: 120px; position: relative; overflow: hidden }
            .wmc-btn-cancel::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255, 255, 255, 0.25) 0%, transparent 100%); pointer-events: none }
            .wmc-btn-cancel:hover { color: #ffffff; background: linear-gradient(180deg, rgba(255, 255, 255, 0.28) 0%, rgba(255, 255, 255, 0.18) 50%, rgba(255, 255, 255, 0.12) 100%); border-color: rgba(255, 255, 255, 0.45); box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.5), inset 0 -1px 0 rgba(0, 0, 0, 0.15), 0 4px 10px rgba(0, 0, 0, 0.4) }
            .wmc-page { display: none }
            .wmc-page.active { display: flex }
            .wmc-settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem }
            .wmc-final-icon { font-size: 10rem }
            .wmc-final-message { font-size: 1.25rem; color: rgba(255, 255, 255, 0.7); line-height: 1.8; margin-top: 2rem }
            @media (max-width: 1024px) { .wmc-container { width: 95%; height: 90vh } .wmc-content { flex-direction: column; gap: 2rem } .wmc-right { width: 100%; height: 200px } .wmc-icon { font-size: 8rem } }
        </style>

    </head>
        <!-- FIRST SETUP WIZARD -->
        <div id="wmcWizard" class="wmc-wizard hidden">
            <div class="wmc-container">
                <div class="wmc-header">
                    <div class="wmc-header-title">openOrchestrate</div>
                    <div class="wmc-header-subtitle">Setup Wizard</div>
                </div>

                <div class="wmc-content">
                    <!-- Page 0: Welcome -->
                    <div class="wmc-page active" data-page="0">
                        <div class="wmc-page-container">
                        <div class="wmc-left">
                            <div class="wmc-page-title">Welcome to openOrchestrate</div>
                            <div class="wmc-description">
                                openOrchestrate is an intelligent AI orchestration system that routes your queries to specialized expert models. 
                                This wizard will help you configure your expert models and advanced features.
                            </div>
                            
                            <div class="wmc-warning" style="background: rgba(255, 180, 0, 0.15); border: 1px solid rgba(255, 180, 0, 0.4); border-radius: 8px; padding: 1rem; margin: 1.5rem 0;">
                                <div style="font-weight: 600; color: #ffb400; margin-bottom: 0.5rem;">⚠️ Important</div>
                                <div style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                                    It is advised to <strong>close all GPU related applications</strong> before proceeding to ensure maximum VRAM availability for your models.
                                </div>
                            </div>
                            
                            <div class="wmc-info" style="background: rgba(91, 196, 232, 0.15); border: 1px solid rgba(91, 196, 232, 0.4); border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                                <div style="font-weight: 600; color: #5bc4e8; margin-bottom: 0.5rem;">ℹ️ First Run Notice</div>
                                <div style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                                    The first time you run a language model, openOrchestrate will calculate the best possible context length for your system. 
                                    <strong>This could take several minutes.</strong> Please be patient — this calibration will only take place once.
                                </div>
                            </div>
                        </div>
                        <div class="wmc-right">
                            <div class="wmc-icon-container">
                                <div class="wmc-icon">🚀</div>
                            </div>
                        </div></div>
                    </div>

                    <!-- Page 1: Expert Models -->
                    <div class="wmc-page" data-page="1">
                        <div class="wmc-page-container">
                        <div class="wmc-left">
                            <div class="wmc-page-title">Configure Expert Models</div>
                            <div class="wmc-description">
                                Configure your specialized AI experts. Enable the ones you need and assign a model to each. 
                                You can customize names and add more experts later in Settings.
                            </div>
                            
                            <div class="wmc-expert-slots" id="wmcExpertSlots" style="max-height: 320px; overflow-y: auto; margin: 1rem 0; padding-right: 0.5rem;">
                                <!-- Expert slots will be generated by JavaScript -->
                            </div>
                            
                            <div class="wmc-recommendation">
                                <div class="wmc-recommendation-title">💡 Tip</div>
                                <div class="wmc-recommendation-text">
                                    Start with just one or two experts. You can always add more later. 
                                    Look for models like <strong>Llama</strong>, <strong>Mistral</strong>, <strong>Qwen</strong>, or <strong>DeepSeek</strong>.
                                </div>
                            </div>
                        </div>
                        <div class="wmc-right">
                            <div class="wmc-icon-container">
                                <div class="wmc-icon">🧠</div>
                            </div>
                        </div></div>
                    </div>

                    <!-- Page 2: Auxiliary Model -->
                    <div class="wmc-page" data-page="2">
                        <div class="wmc-page-container">
                        <div class="wmc-left">
                            <div class="wmc-page-title">Configure Auxiliary Model</div>
                            <div class="wmc-description">
                                The Auxiliary model handles query routing, context management, and background tasks. 
                                Choose a small, fast model (1-3B parameters) that runs efficiently on CPU.
                            </div>
                            
                            <div class="wmc-section">
                                <div class="wmc-section-label">Select Model</div>
                                <select class="wmc-select" id="wmcAuxModel">
                                    <option value="">Select a model...</option>
                                </select>
                            </div>
                            
                            <div class="wmc-section">
                                <div class="wmc-section-label">Configuration</div>
                                <div class="wmc-settings-grid">
                                    <div>
                                        <div class="wmc-section-label" style="margin-bottom: 0.5rem">Context Length</div>
                                        <input type="number" class="wmc-input" id="wmcAuxContext" value="2048" min="512" max="8192" step="512">
                                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); margin-top: 0.5rem;">
                                            Maximum context window
                                        </div>
                                    </div>
                                    <div>
                                        <div class="wmc-section-label" style="margin-bottom: 0.5rem">Port</div>
                                        <input type="number" class="wmc-input" id="wmcAuxPort" value="8081" min="1024" max="65535">
                                        <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); margin-top: 0.5rem;">
                                            Network port for server
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="wmc-recommendation">
                                <div class="wmc-recommendation-title">💡 Recommended</div>
                                <div class="wmc-recommendation-text">
                                    Look for <strong>Phi</strong>, <strong>TinyLlama</strong>, <strong>Qwen2-1.5B</strong>, or <strong>SmolLM</strong>. 
                                    Small models work best here and run on CPU to preserve GPU resources.
                                </div>
                            </div>
                        </div>
                        <div class="wmc-right">
                            <div class="wmc-icon-container">
                                <div class="wmc-icon">⚙️</div>
                            </div>
                        </div></div>
                    </div>

                    <!-- Page 3: Context Management -->
                    <div class="wmc-page" data-page="3">
                        <div class="wmc-page-container">
                        <div class="wmc-left">
                            <div class="wmc-page-title">Context Management</div>
                            <div class="wmc-description">
                                Configure intelligent memory and context management for optimal long-form conversations.
                            </div>
                            
                            <div class="wmc-section">
                                <div class="wmc-toggle-group">
                                    <div class="wmc-toggle active" id="wmcVelocityToggle">
                                        <div class="wmc-toggle-knob"></div>
                                    </div>
                                    <div class="wmc-toggle-label">Enable Velocity Index</div>
                                </div>
                                <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); margin-left: 71px; margin-top: -0.5rem">
                                    Automatically archives and recalls conversation context
                                </div>
                            </div>
                            
                            <div class="wmc-section">
                                <div class="wmc-toggle-group">
                                    <div class="wmc-toggle active" id="wmcPruningToggle">
                                        <div class="wmc-toggle-knob"></div>
                                    </div>
                                    <div class="wmc-toggle-label">Enable Context Pruning</div>
                                </div>
                                <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); margin-left: 71px; margin-top: -0.5rem">
                                    Automatically condenses messages to preserve context space
                                </div>
                            </div>
                            
                            <div class="wmc-settings-grid" style="margin-top: 1rem;">
                                <div>
                                    <div class="wmc-section-label">Velocity Threshold (%)</div>
                                    <input type="number" class="wmc-input" id="wmcVelocityThreshold" value="40" min="10" max="90" step="5">
                                    <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); margin-top: 0.5rem;">
                                        Archive when context exceeds this %
                                    </div>
                                </div>
                                <div>
                                    <div class="wmc-section-label">Pruning Threshold (chars)</div>
                                    <input type="number" class="wmc-input" id="wmcPruneThreshold" value="1500" min="500" max="5000" step="100">
                                    <div style="font-size: 0.8rem; color: rgba(255, 255, 255, 0.5); margin-top: 0.5rem;">
                                        Condense messages longer than this
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="wmc-right">
                            <div class="wmc-icon-container">
                                <div class="wmc-icon">⚡</div>
                            </div>
                        </div></div>
                    </div>

                    <!-- Page 4: Complete -->
                    <div class="wmc-page" data-page="4">
                        <div class="wmc-page-container">
                        <div class="wmc-left">
                            <div class="wmc-page-title">Setup Complete</div>
                            <div class="wmc-description">
                                Your configuration has been saved. openOrchestrate is ready to intelligently route your queries 
                                to the appropriate expert models.
                            </div>
                            
                            <div class="wmc-info" style="background: rgba(91, 196, 232, 0.15); border: 1px solid rgba(91, 196, 232, 0.4); border-radius: 8px; padding: 1rem; margin: 1.5rem 0;">
                                <div style="font-weight: 600; color: #5bc4e8; margin-bottom: 0.5rem;">ℹ️ Reminder</div>
                                <div style="color: rgba(255, 255, 255, 0.8); line-height: 1.6;">
                                    Remember, the first model launch will include context calibration which may take several minutes. 
                                    Subsequent launches will be much faster.
                                </div>
                            </div>
                            
                            <div class="wmc-final-message">
                                Click <strong>Finish</strong> to start using openOrchestrate.
                            </div>
                        </div>
                        <div class="wmc-right">
                            <div class="wmc-icon-container">
                                <div class="wmc-icon wmc-final-icon">✅</div>
                            </div></div>
                        </div>
                    </div>
                </div>

                <div class="wmc-footer">
                    <button class="wmc-btn-cancel" id="wmcCancel">Cancel</button>
                    <div style="display: flex; gap: 1rem;">
                        <button class="wmc-btn wmc-btn-back hidden" id="wmcBack">Back</button>
                        <button class="wmc-btn wmc-btn-next" id="wmcNext">Next</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // WIZARD
        const WMCWizard = {
            currentPage: 0,
            totalPages: 5,
            availableModels: [],
            soundtrackStarted: false,
            soundtrack: null,
            expertNames: ['Text', 'Code', 'Medical', 'Electrical', 'Vehicle', 'Expert 6', 'Expert 7', 'Expert 8', 'Expert 9', 'Expert 10'],
            expertEnabled: [true, true, true, true, true, false, false, false, false, false],

            init() {
                this.bindEvents();
                this.loadAvailableModels();
                this.generateExpertSlots();
                this.checkAndShow();
            },

            bindEvents() {
                document.getElementById('wmcNext').addEventListener('click', () => this.nextPage());
                document.getElementById('wmcBack').addEventListener('click', () => this.prevPage());
                document.getElementById('wmcCancel').addEventListener('click', () => this.cancel());
                
                ['wmcVelocityToggle', 'wmcPruningToggle'].forEach(id => {
                    const toggle = document.getElementById(id);
                    if (toggle) {
                        toggle.addEventListener('click', () => toggle.classList.toggle('active'));
                    }
                });
            },
            
            generateExpertSlots() {
                const container = document.getElementById('wmcExpertSlots');
                if (!container) return;
                
                let html = '';
                for (let i = 1; i <= 10; i++) {
                    const name = this.expertNames[i-1];
                    const enabled = this.expertEnabled[i-1];
                    html += `
                        <div class="wmc-expert-slot" style="padding: 0.75rem 0; border-bottom: 1px solid rgba(255,255,255,0.1);">
                            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                                <div class="wmc-toggle ${enabled ? 'active' : ''}" id="wmcExpert${i}Toggle" style="flex-shrink: 0;">
                                    <div class="wmc-toggle-knob"></div>
                                </div>
                                <input type="text" class="wmc-input" id="wmcExpert${i}Name" value="${name}" 
                                    style="flex: 1; padding: 0.5rem; font-size: 0.9rem;" placeholder="Expert name...">
                            </div>
                            <select class="wmc-select" id="wmcExpert${i}Model" style="width: 100%; font-size: 0.85rem;">
                                <option value="">Select a model...</option>
                            </select>
                        </div>
                    `;
                }
                container.innerHTML = html;
                
                // Bind toggle events for expert slots
                for (let i = 1; i <= 10; i++) {
                    const toggle = document.getElementById(`wmcExpert${i}Toggle`);
                    if (toggle) {
                        toggle.addEventListener('click', () => toggle.classList.toggle('active'));
                    }
                }
            },

            async loadAvailableModels() {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'scan_models' })
                    });
                    const data = await response.json();
                    
                    if (data.success && data.models) {
                        this.availableModels = data.models;
                        this.populateDropdowns();
                    }
                } catch (err) {
                    console.error('Failed to load models:', err);
                }
            },

            populateDropdowns() {
                // Populate expert model dropdowns
                for (let i = 1; i <= 10; i++) {
                    const select = document.getElementById(`wmcExpert${i}Model`);
                    if (select) {
                        select.innerHTML = '<option value="">Select a model...</option>';
                        this.availableModels.forEach(model => {
                            const option = document.createElement('option');
                            option.value = model.filename;
                            option.textContent = model.filename;
                            select.appendChild(option);
                        });
                    }
                }
                
                // Populate aux model dropdown
                const auxSelect = document.getElementById('wmcAuxModel');
                if (auxSelect) {
                    auxSelect.innerHTML = '<option value="">Select a model...</option>';
                    this.availableModels.forEach(model => {
                        const option = document.createElement('option');
                        option.value = model.filename;
                        option.textContent = model.filename;
                        auxSelect.appendChild(option);
                    });
                }
            },

            async checkAndShow() {
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'load_governor_config' })
                    });
                    const data = await response.json();
                    
                    if (data.success && data.config) {
                        const config = data.config;
                        
                        // Check if any expert has a model configured
                        let hasModels = false;
                        for (let i = 1; i <= 10; i++) {
                            if (config[`expert_${i}_model`]) {
                                hasModels = true;
                                break;
                            }
                        }
                        
                        if (!hasModels) {
                            setTimeout(() => {
                                document.getElementById('wmcWizard').classList.remove('hidden');
                            }, 300);
                        }
                        
                        // Load existing config into wizard fields
                        for (let i = 1; i <= 10; i++) {
                            const nameInput = document.getElementById(`wmcExpert${i}Name`);
                            const modelSelect = document.getElementById(`wmcExpert${i}Model`);
                            const toggle = document.getElementById(`wmcExpert${i}Toggle`);
                            
                            if (nameInput) nameInput.value = config[`expert_${i}_name`] || this.expertNames[i-1];
                            if (modelSelect) modelSelect.value = config[`expert_${i}_model`] || '';
                            if (toggle) {
                                if (config[`expert_${i}_enabled`] === false) {
                                    toggle.classList.remove('active');
                                } else if (config[`expert_${i}_enabled`] === true) {
                                    toggle.classList.add('active');
                                }
                            }
                        }
                        
                        document.getElementById('wmcAuxModel').value = config.aux_model || '';
                        document.getElementById('wmcAuxContext').value = config.aux_context_length || 2048;
                        document.getElementById('wmcAuxPort').value = config.aux_port || 8081;
                        document.getElementById('wmcVelocityThreshold').value = config.velocity_threshold || 40;
                        document.getElementById('wmcPruneThreshold').value = config.prune_threshold || 1500;
                        
                        if (config.velocity_enabled === false) document.getElementById('wmcVelocityToggle').classList.remove('active');
                        if (config.enable_pruning === false) document.getElementById('wmcPruningToggle').classList.remove('active');
                    } else {
                        setTimeout(() => {
                            document.getElementById('wmcWizard').classList.remove('hidden');
                        }, 300);
                    }
                } catch (err) {
                    console.error('Failed to check config:', err);
                }
            },

            updatePage() {
                document.querySelectorAll('.wmc-page').forEach((page, idx) => {
                    page.classList.toggle('active', idx === this.currentPage);
                });

                const backBtn = document.getElementById('wmcBack');
                const nextBtn = document.getElementById('wmcNext');
                
                backBtn.classList.toggle('hidden', this.currentPage === 0);
                
                if (this.currentPage === this.totalPages - 1) {
                    nextBtn.textContent = 'Finish';
                } else {
                    nextBtn.textContent = 'Next';
                }
            },

            nextPage() {
                if (this.currentPage === 0 && !this.soundtrackStarted) {
                    this.startSoundtrack();
                }
                
                if (this.currentPage === this.totalPages - 1) {
                    this.saveAndClose();
                } else if (this.currentPage === this.totalPages - 2) {
                    this.saveSettings();
                } else {
                    this.currentPage++;
                    this.updatePage();
                }
            },

            prevPage() {
                if (this.currentPage > 0) {
                    this.currentPage--;
                    this.updatePage();
                }
            },

            cancel() {
                this.stopSoundtrack();
                this.close();
            },
            
            startSoundtrack() {
                this.soundtrackStarted = true;
                
                const loadTone = () => {
                    return new Promise((resolve, reject) => {
                        if (window.Tone) { resolve(); return; }
                        const script = document.createElement('script');
                        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js';
                        script.onload = resolve;
                        script.onerror = reject;
                        document.head.appendChild(script);
                    });
                };
                
                loadTone().then(() => {
                    const chordSynth = new Tone.PolySynth(Tone.Synth, {
                        oscillator: { type: 'sine' },
                        envelope: { attack: 2, decay: 1, sustain: 0.5, release: 3 }
                    }).toDestination();
                    chordSynth.volume.value = -28;
                    
                    const bassSynth = new Tone.MonoSynth({
                        oscillator: { type: 'sine' },
                        envelope: { attack: 1.5, decay: 0.8, sustain: 0.3, release: 2 }
                    }).toDestination();
                    bassSynth.volume.value = -32;
                    
                    const hihatSynth = new Tone.NoiseSynth({
                        noise: { type: 'white' },
                        envelope: { attack: 0.001, decay: 0.05, sustain: 0 }
                    }).toDestination();
                    hihatSynth.volume.value = -30;
                    
                    const kickSynth = new Tone.MembraneSynth({
                        pitchDecay: 0.05,
                        octaves: 8,
                        oscillator: { type: 'sine' },
                        envelope: { attack: 0.001, decay: 0.4, sustain: 0.01, release: 1.4 }
                    }).toDestination();
                    kickSynth.volume.value = -22;
                    
                    const snareSynth = new Tone.NoiseSynth({
                        noise: { type: 'white' },
                        envelope: { attack: 0.005, decay: 0.2, sustain: 0.05, release: 0.3 }
                    }).toDestination();
                    snareSynth.volume.value = -24;
                    
                    const chords = [['C4', 'E4', 'G4'], ['A3', 'C4', 'E4'], ['F3', 'A3', 'C4'], ['G3', 'B3', 'D4']];
                    const bassNotes = ['C2', 'A1', 'F1', 'G1'];
                    
                    Tone.start();
                    
                    let chordIndex = 0;
                    let hihatBeat = 0;
                    
                    const chordLoop = new Tone.Loop((time) => {
                        chordSynth.triggerAttackRelease(chords[chordIndex], '1n', time);
                        bassSynth.triggerAttackRelease(bassNotes[chordIndex], '1n', time);
                        chordIndex = (chordIndex + 1) % 4;
                    }, '1n').start(0);
                    
                    const hihatLoop = new Tone.Loop((time) => {
                        hihatSynth.triggerAttackRelease('16n', time);
                        
                        if (hihatBeat % 4 === 0) {
                            kickSynth.triggerAttackRelease('C1', '8n', time);
                        }
                        
                        if (hihatBeat % 4 === 2) {
                            snareSynth.triggerAttackRelease('16n', time);
                        }
                        
                        hihatBeat++;
                    }, '8n').start(0);
                    
                    Tone.Transport.bpm.value = 70;
                    Tone.Transport.start();
                    
                    this.soundtrack = { chordLoop, hihatLoop };
                }).catch(err => {
                    console.warn('Could not load Tone.js:', err);
                });
            },
            
            stopSoundtrack() {
                if (this.soundtrack) {
                    Tone.Transport.stop();
                    this.soundtrack.chordLoop.dispose();
                    this.soundtrack.hihatLoop.dispose();
                    this.soundtrack = null;
                }
            },

            close() {
                document.getElementById('wmcWizard').classList.add('hidden');
            },

            collectSettings() {
                const settings = {
                    aux_model: document.getElementById('wmcAuxModel').value.trim(),
                    aux_cpu_only: true,
                    aux_context_length: parseInt(document.getElementById('wmcAuxContext').value) || 2048,
                    aux_port: parseInt(document.getElementById('wmcAuxPort').value) || 8081,
                    expert_port: 8080,
                    velocity_enabled: document.getElementById('wmcVelocityToggle').classList.contains('active'),
                    velocity_threshold: parseInt(document.getElementById('wmcVelocityThreshold').value) || 40,
                    velocity_char_threshold: 1500,
                    enable_pruning: document.getElementById('wmcPruningToggle').classList.contains('active'),
                    prune_threshold: parseInt(document.getElementById('wmcPruneThreshold').value) || 1500,
                    velocity_index_prompt: 'Create a brief, descriptive title (max 10 words) that captures the key topic or intent of this message. Return ONLY the title, nothing else.',
                    velocity_recall_prompt: 'Given the user\'s new message, determine which archived conversation topic (if any) is most relevant and should be recalled to provide better context. If one topic is clearly relevant, respond with ONLY the number in brackets (e.g., 0 or 3). If no topic is relevant, respond with: NULL',
                    prune_prompt: 'Condense this message to only the essential information in 2-3 sentences:'
                };
                
                // Collect 10 expert slots
                for (let i = 1; i <= 10; i++) {
                    const nameInput = document.getElementById(`wmcExpert${i}Name`);
                    const modelSelect = document.getElementById(`wmcExpert${i}Model`);
                    const toggle = document.getElementById(`wmcExpert${i}Toggle`);
                    
                    settings[`expert_${i}_name`] = nameInput?.value?.trim() || this.expertNames[i-1];
                    settings[`expert_${i}_model`] = modelSelect?.value?.trim() || '';
                    settings[`expert_${i}_enabled`] = toggle?.classList.contains('active') ?? this.expertEnabled[i-1];
                    settings[`expert_${i}_system_prompt`] = 'You are a helpful assistant. Provide detailed, accurate responses.';
                }
                
                return settings;
            },

            async saveSettings() {
                const settings = this.collectSettings();

                // Check if at least one expert has a model
                let hasModel = false;
                for (let i = 1; i <= 10; i++) {
                    if (settings[`expert_${i}_enabled`] && settings[`expert_${i}_model`]) {
                        hasModel = true;
                        break;
                    }
                }
                
                if (!hasModel) {
                    alert('Please configure at least one expert model before continuing.');
                    return false;
                }
                
                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'save_governor_config', ...settings })
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.currentPage++;
                        this.updatePage();
                        return true;
                    } else {
                        alert('Failed to save settings: ' + (data.message || 'Unknown error'));
                        return false;
                    }
                } catch (err) {
                    console.error('Save error:', err);
                    alert('An error occurred while saving settings');
                    return false;
                }
            },

            saveAndClose() {
                this.stopSoundtrack();
                this.close();
                window.location.reload();
            }
        };
        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            WMCWizard.init();
        });
        setTimeout(() => {
            document.body.classList.add('loaded');
        }, 1500);
        </script>
    </body>
</html>