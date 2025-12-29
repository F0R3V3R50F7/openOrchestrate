<?php
/**
 * openOrchestrate - Intelligent, self-governing Llama.cpp Frontend
 * MPL-2.0 https://mozilla.org/MPL/2.0/
 * @version 0.9 (Pre-Release)
 * © TechnologystLabs 2026 */

// ===== BACKEND =====

/* ===== CORE UTILITIES ===== */
define('DEFAULT_CONFIG', [
    'text_model' => '', 'code_model' => '', 'medical_model' => '', 'aux_model' => '',
    'text_enabled' => true, 'code_enabled' => true, 'medical_enabled' => true,
    'aux_cpu_only' => true, 'aux_context_length' => 2048, 'aux_port' => 8081, 'expert_port' => 8080,
    'velocity_enabled' => true, 'velocity_threshold' => 40, 'velocity_char_threshold' => 1500,
    'velocity_index_prompt' => 'Create a brief, descriptive title (max 10 words) that captures the key topic or intent of this message. Return ONLY the title, nothing else.',
    'velocity_recall_prompt' => 'Given the user\'s new message, determine which archived conversation topic (if any) is most relevant and should be recalled to provide better context. If one topic is clearly relevant, respond with ONLY the number in brackets (e.g., 0 or 3). If no topic is relevant, respond with: NULL',
    'enable_pruning' => true, 'prune_threshold' => 1500,
    'prune_prompt' => 'Condense this message to only the essential information in 2-3 sentences:'
]);

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

/* ===== CONFIGURATION LOADERS (Phase 2) ===== */
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

// load_governor_config is now an alias for load_config
function load_governor_config() {
    return load_config();
}



/* ===== AUX MODEL CLIENT (Phase 3) ===== */
function aux_chat_request($prompt, $options = []) {
    $timeout = $options['timeout'] ?? 60;
    $maxTokens = $options['max_tokens'] ?? 512;
    $temperature = $options['temperature'] ?? 0.3;
    
    $govConfig = load_governor_config();
    $auxPort = $govConfig['aux_port'] ?? 8081;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => "http://127.0.0.1:$auxPort/v1/chat/completions",
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS => json_encode([
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'stream' => false
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && $result !== false) {
        $response = json_decode($result, true);
        $content = trim($response['choices'][0]['message']['content'] ?? '');
        
        if (!empty($content)) {
            return ['success' => true, 'output' => $content];
        }
        return ['success' => false, 'error' => 'Empty response from model'];
    }
    
    return ['success' => false, 'error' => $error ?: "HTTP $httpCode"];
}

/* ===== PORT & HEALTH CHECKS (Phase 5) ===== */
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

/* ===== SERVER LIFECYCLE HELPERS (Phase 7) ===== */
function start_llama_server($type, $model, $port, $cpuOnly, $contextSize = 0) {
    global $modelsDir, $governorDir;
    
    $modelPath = realpath("$modelsDir/$model");
    if (!$modelPath) {
        return ['success' => false, 'error' => "Model not found: $model"];
    }
    
    $llamaServer = __DIR__ . '/llama-server.exe';
    if (!file_exists($llamaServer)) {
        return ['success' => false, 'error' => 'llama-server.exe not found'];
    }
    
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
    
    if ($type === 'aux' && !$cpuOnly) {
        $args['--split'] = "30:70";
    }
    
    $logFile = __DIR__ . "/governor/{$type}_server.log";
    
    // Build command string
    $command = "\"$llamaServer\"";
    foreach ($args as $k => $v) {
        $command .= " $k $v";
    }
    
    // Create batch file that monitors phpdesktop-chrome.exe and kills llama-server when it exits
    $batchFile = __DIR__ . "/governor/start_{$type}.bat";
    $batchFileWin = str_replace('/', '\\', $batchFile);
    $watchdogBat = "@echo off\r\nsetlocal\r\n\r\n";
    $watchdogBat .= ":: Start llama-server in background\r\n";
    $watchdogBat .= "start \"llama-{$type}\" /B $command\r\n\r\n";
    $watchdogBat .= ":: Monitor phpdesktop-chrome.exe - when it exits, kill llama-server\r\n";
    $watchdogBat .= ":watchloop\r\n";
    $watchdogBat .= "tasklist /fi \"imagename eq phpdesktop-chrome.exe\" 2>nul | find /i \"phpdesktop-chrome.exe\" >nul\r\n";
    $watchdogBat .= "if errorlevel 1 (\r\n";
    $watchdogBat .= "    taskkill /f /im llama-server.exe >nul 2>&1\r\n";
    $watchdogBat .= "    exit /b\r\n";
    $watchdogBat .= ")\r\n";
    $watchdogBat .= "timeout /t 2 /nobreak >nul\r\n";
    $watchdogBat .= "goto watchloop\r\n";
    file_put_contents($batchFile, $watchdogBat);
    
    // Create VBS launcher (runs batch hidden) - use doubled quotes for VBScript escaping
    $vbsFile = __DIR__ . "/governor/launch_{$type}.vbs";
    $wshScript = 'CreateObject("WScript.Shell").Run "' . $batchFileWin . '", 0, False';
    file_put_contents($vbsFile, $wshScript);
    
    exec("wscript \"$vbsFile\"");
    
    // Try to get PID
    sleep(2);
    $pid = 0;
    
    $output = [];
    exec('tasklist /FI "IMAGENAME eq llama-server.exe" /FO CSV 2>&1', $output);
    
    foreach ($output as $line) {
        if (preg_match('/"llama-server\.exe","(\d+)"/', $line, $matches)) {
            $foundPid = (int)$matches[1];
            $cmdOutput = [];
            exec("wmic process where \"ProcessId=$foundPid\" get CommandLine 2>&1", $cmdOutput);
            $cmdLine = implode(' ', $cmdOutput);
            
            if (strpos($cmdLine, "--port $port") !== false) {
                $pid = $foundPid;
                file_put_contents("$governorDir/{$type}.pid", $pid);
                break;
            }
        }
    }
    
    return [
        'success' => true,
        'port' => $port,
        'type' => $type,
        'model' => $model,
        'pid' => $pid
    ];
}

function stop_llama_server($type) {
    global $governorDir;
    
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
$configDir = 'configs';
$chatsDir = 'chats';
$governorDir = 'governor';
$modelsDir = 'models';

foreach ([$configDir, $chatsDir, $governorDir] as $dir) {
    !is_dir($dir) && mkdir($dir, 0755, true);
}

// Runtime hardening
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
    
    try {
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
                
                if (!is_string($message) || !trim($message)) {
                    $response = error_response('No message provided');
                    break;
                }
                
                $message = trim($message);
                $prompt = trim($prompt) ?: DEFAULT_CONFIG['prune_prompt'];
                
                $govConfig = load_governor_config();
                $auxPort = $govConfig['aux_port'] ?? 8081;
                
                if (!is_port_open($auxPort)) {
                    $response = error_response('Auxiliary model not running');
                    break;
                }
                
                $result = aux_chat_request("$prompt\n\n$message", ['temperature' => 0.3]);
                
                if ($result['success']) {
                    $response = ['success' => true, 'pruned' => $result['output']];
                } else {
                    $response = error_response('Prune failed: ' . ($result['error'] ?? 'Unknown'));
                }
                break;
                
            case 'route_query':
                $message = trim($data['message'] ?? '');
                if (!$message) {
                    $response = error_response('No message provided');
                    break;
                }
                
                $govConfig = load_governor_config();
                
                if (!is_port_open($govConfig['aux_port'] ?? 8081)) {
                    $response = error_response('Auxiliary model not running');
                    break;
                }
                
                $textEnabled = ($govConfig['text_enabled'] ?? true) && !empty($govConfig['text_model']);
                $codeEnabled = ($govConfig['code_enabled'] ?? true) && !empty($govConfig['code_model']);
                $medicalEnabled = ($govConfig['medical_enabled'] ?? true) && !empty($govConfig['medical_model']);
                
                $enabledCategories = [];
                $categoryDescriptions = [];
                
                if ($codeEnabled) {
                    $enabledCategories[] = 'CODE';
                    $categoryDescriptions[] = "CODE means: programming code, scripts, functions, debugging, error messages with stack traces, technical implementation, code review, SQL queries, HTML/CSS/JavaScript, API requests, command line instructions, or requests to write/fix/explain code.";
                }
                
                if ($medicalEnabled) {
                    $enabledCategories[] = 'MEDICAL';
                    $categoryDescriptions[] = "MEDICAL means: health questions, medical symptoms, diagnoses, treatments, medications, medical advice, anatomy, physiology, disease information, healthcare, medical terminology, or requests for medical information.";
                }
                
                if ($textEnabled) {
                    $enabledCategories[] = 'TEXT';
                    $textDesc = "TEXT means: general conversation, questions about concepts, creative writing, explanations, advice";
                    if ($codeEnabled || $medicalEnabled) {
                        $excluded = [];
                        if ($codeEnabled) $excluded[] = "programming code";
                        if ($medicalEnabled) $excluded[] = "medical topics";
                        $textDesc .= ", or anything not directly involving " . implode(' or ', $excluded);
                    }
                    $textDesc .= ".";
                    $categoryDescriptions[] = $textDesc;
                }
                
                if (count($enabledCategories) <= 1) {
                    $response = [
                        'success' => true,
                        'route' => $enabledCategories[0] ?? 'TEXT',
                        'single_expert' => true,
                        'message_preview' => substr($message, 0, 100)
                    ];
                    break;
                }
                
                $categoriesList = implode(', ', $enabledCategories);
                $descriptionsText = implode("\n\n", $categoryDescriptions);
                
                $routingPrompt = "Task: Classify the following message as one of: $categoriesList.

$descriptionsText

Message to classify:
---
$message
---

Respond with exactly one word - one of: $categoriesList:";
                
                $result = aux_chat_request($routingPrompt, ['max_tokens' => 10, 'temperature' => 0.1]);
                
                if ($result['success']) {
                    $rawResponse = trim($result['output']);
                    $upperResponse = strtoupper($rawResponse);
                    $classification = null;
                    
                    if ($codeEnabled && strpos($upperResponse, 'CODE') !== false) {
                        $classification = 'CODE';
                    } elseif ($medicalEnabled && strpos($upperResponse, 'MEDICAL') !== false) {
                        $classification = 'MEDICAL';
                    } elseif ($textEnabled && strpos($upperResponse, 'TEXT') !== false) {
                        $classification = 'TEXT';
                    } else {
                        $firstWord = strtoupper(preg_replace('/[^A-Za-z]/', '', explode(' ', trim($rawResponse))[0] ?? ''));
                        if (in_array($firstWord, $enabledCategories)) {
                            $classification = $firstWord;
                        }
                    }
                    
                    if ($classification !== null) {
                        $response = ['success' => true, 'route' => $classification];
                        break;
                    }
                }
                
                $defaultRoute = $textEnabled ? 'TEXT' : ($enabledCategories[0] ?? 'TEXT');
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
                    'text_model' => trim($data['text_model'] ?? ''),
                    'code_model' => trim($data['code_model'] ?? ''),
                    'medical_model' => trim($data['medical_model'] ?? ''),
                    'aux_model' => trim($data['aux_model'] ?? ''),
                    'text_enabled' => filter_var($data['text_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'code_enabled' => filter_var($data['code_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'medical_enabled' => filter_var($data['medical_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'aux_cpu_only' => filter_var($data['aux_cpu_only'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'aux_context_length' => max(512, min(32768, (int)($data['aux_context_length'] ?? 2048))),
                    'aux_port' => max(1, min(65535, (int)($data['aux_port'] ?? 8081))),
                    'expert_port' => max(1, min(65535, (int)($data['expert_port'] ?? 8080))),
                    'velocity_enabled' => filter_var($data['velocity_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'velocity_threshold' => max(10, min(90, (int)($data['velocity_threshold'] ?? 40))),
                    'velocity_char_threshold' => max(100, min(10000, (int)($data['velocity_char_threshold'] ?? 500))),
                    'velocity_index_prompt' => trim($data['velocity_index_prompt'] ?? '') ?: DEFAULT_CONFIG['velocity_index_prompt'],
                    'velocity_recall_prompt' => trim($data['velocity_recall_prompt'] ?? '') ?: DEFAULT_CONFIG['velocity_recall_prompt'],
                    'enable_pruning' => filter_var($data['enable_pruning'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    'prune_threshold' => max(100, min(10000, (int)($data['prune_threshold'] ?? 1500))),
                    'prune_prompt' => trim($data['prune_prompt'] ?? DEFAULT_CONFIG['prune_prompt']) ?: DEFAULT_CONFIG['prune_prompt']
                ];
                
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
    } catch (Throwable $e) {
        $response = error_response('Internal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
    
    mm_json_response($response);
}
?>
<!DOCTYPE html>
<html lang="en" class="dark" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>openOrchestrate - Llama.cpp Chat</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🦙</text></svg>">
    <meta name="theme-color" content="#171717">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-bg-hover: rgba(255, 255, 255, 0.09);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-border-bright: rgba(255, 255, 255, 0.15);
            --text-primary: rgba(255, 255, 255, 0.95);
            --text-secondary: rgba(255, 255, 255, 0.7);
            --text-tertiary: rgba(255, 255, 255, 0.5);
            --text-quaternary: rgba(255, 255, 255, 0.35);
            --accent-primary: #5BC4E8;
            --accent-primary-soft: rgba(91, 196, 232, 0.15);
            --accent-secondary: #7DD87D;
            --accent-secondary-soft: rgba(125, 216, 125, 0.15);
            --accent-gradient: linear-gradient(135deg, #9DE89D 0%, #5DD8A6 30%, #4BBEE8 65%, #3A9ED4 100%);
            --success: #30D158;
            --success-soft: rgba(48, 209, 88, 0.15);
            --warning: #FFD60A;
            --warning-soft: rgba(255, 214, 10, 0.15);
            --error: #FF453A;
            --error-soft: rgba(255, 69, 58, 0.15);
            --bg-deep: #040d12;
            --bg-panel: rgba(15, 25, 35, 0.85);
            --bg-ambient: radial-gradient(ellipse 100% 60% at 20% -20%, rgba(130, 220, 130, 0.18), transparent 50%), radial-gradient(ellipse 90% 55% at 75% 10%, rgba(91, 196, 232, 0.22), transparent 55%), radial-gradient(ellipse 70% 50% at 50% 115%, rgba(58, 158, 212, 0.2), transparent);
            --liquid-glass: linear-gradient(160deg, rgba(65, 65, 78, 0.45) 0%, rgba(48, 48, 58, 0.5) 100%);
            --liquid-glass-hover: linear-gradient(160deg, rgba(75, 75, 90, 0.5) 0%, rgba(55, 55, 68, 0.55) 100%);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.15);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.25);
            --shadow-glow: 0 0 40px rgba(91, 196, 232, 0.15);
            --radius-xs: 8px;
            --radius-sm: 12px;
            --radius-md: 16px;
            --radius-lg: 20px;
            --radius-xl: 24px;
            --radius-2xl: 28px;
            --radius-pill: 9999px;
            --blur-sm: 8px;
            --blur-md: 16px;
            --blur-lg: 24px;
            --blur-xl: 40px;
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-smooth: 250ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-spring: 400ms cubic-bezier(0.34, 1.56, 0.64, 1)
        }

        .dark {
            color-scheme: dark
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Helvetica Neue', Arial, sans-serif;
            background: var(--bg-deep);
            color: var(--text-primary);
            height: 100vh;
            overflow: hidden;
            position: relative
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: var(--bg-ambient);
            pointer-events: none;
            z-index: 0
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background: 
                /* Beam 1 - Main green-to-blue sweep from top-left */
                linear-gradient(
                    130deg,
                    rgba(140, 225, 140, 0.5) 0%,
                    rgba(100, 215, 170, 0.35) 5%,
                    rgba(75, 200, 220, 0.25) 12%,
                    rgba(58, 170, 230, 0.15) 22%,
                    rgba(45, 140, 210, 0.06) 35%,
                    transparent 50%
                ),
                /* Beam 2 - Blue beam crossing from top-right */
                linear-gradient(
                    220deg,
                    rgba(100, 200, 240, 0.4) 0%,
                    rgba(80, 185, 235, 0.3) 6%,
                    rgba(60, 165, 225, 0.2) 14%,
                    rgba(50, 145, 210, 0.1) 25%,
                    transparent 42%
                ),
                /* Beam 3 - Teal strand from left */
                linear-gradient(
                    118deg,
                    transparent 20%,
                    rgba(80, 210, 190, 0.2) 28%,
                    rgba(70, 195, 215, 0.28) 33%,
                    rgba(60, 180, 225, 0.18) 40%,
                    rgba(50, 160, 220, 0.08) 50%,
                    transparent 62%
                ),
                /* Beam 4 - Crossing accent strand */
                linear-gradient(
                    235deg,
                    transparent 30%,
                    rgba(90, 195, 230, 0.15) 40%,
                    rgba(75, 190, 225, 0.22) 46%,
                    rgba(60, 175, 220, 0.12) 54%,
                    transparent 68%
                ),
                /* Beam 5 - Bottom horizon glow */
                linear-gradient(
                    180deg,
                    transparent 60%,
                    rgba(50, 150, 210, 0.1) 75%,
                    rgba(70, 175, 230, 0.2) 88%,
                    rgba(90, 195, 240, 0.35) 100%
                );
            pointer-events: none;
            z-index: 0
        }

        /* Aurora strand lines */
        .aurora-strands {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden
        }

        .aurora-strand {
            position: absolute;
            width: 1px;
            border-radius: 1px;
            transform-origin: top center;
            box-shadow: 
                0 0 2px currentColor,
                0 0 4px currentColor,
                0 0 6px currentColor;
            opacity: 0.7
        }

        .aurora-strand::after {
            content: '';
            position: absolute;
            width: 6px;
            height: 40px;
            background: radial-gradient(ellipse, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.5) 40%, transparent 70%);
            border-radius: 50%;
            left: -2.5px;
            opacity: 0;
            animation: strand-shimmer 10s ease-in-out infinite
        }

        .strand-1::after { animation-delay: 0s; }
        .strand-2::after { animation-delay: 4s; }
        .strand-3::after { animation-delay: 7s; }

        @keyframes strand-shimmer {
            0%, 100% { 
                opacity: 0; 
                top: 5%;
            }
            15% {
                opacity: 0.9;
            }
            50% { 
                opacity: 0.7;
                top: 75%;
            }
            85% {
                opacity: 0;
            }
        }

        .strand-1 {
            height: 110%;
            top: -5%;
            left: 30%;
            background: linear-gradient(180deg, transparent, rgba(180, 255, 180, 0.9) 15%, rgba(150, 245, 210, 0.95) 40%, rgba(120, 225, 245, 0.85) 70%, rgba(90, 200, 240, 0.4) 90%, transparent);
            color: rgba(150, 240, 210, 0.8);
            animation: strand-sway-1 25s ease-in-out infinite
        }

        .strand-2 {
            height: 115%;
            top: -8%;
            left: 45%;
            background: linear-gradient(180deg, transparent, rgba(140, 225, 255, 0.85) 10%, rgba(120, 215, 250, 0.9) 35%, rgba(100, 205, 245, 0.8) 65%, rgba(80, 190, 235, 0.3) 88%, transparent);
            color: rgba(120, 215, 250, 0.8);
            animation: strand-sway-2 18s ease-in-out infinite
        }

        .strand-3 {
            height: 105%;
            top: -3%;
            left: 58%;
            background: linear-gradient(180deg, transparent, rgba(160, 250, 200, 0.8) 12%, rgba(130, 240, 220, 0.9) 38%, rgba(100, 220, 245, 0.85) 68%, rgba(70, 195, 240, 0.35) 92%, transparent);
            color: rgba(130, 235, 220, 0.8);
            animation: strand-sway-3 22s ease-in-out infinite
        }

        @keyframes strand-sway-1 {
            0%, 100% {
                transform: rotate(-6deg) translateX(0)
            }
            20% {
                transform: rotate(-3deg) translateX(80px)
            }
            35% {
                transform: rotate(-8deg) translateX(180px)
            }
            50% {
                transform: rotate(-4deg) translateX(280px)
            }
            65% {
                transform: rotate(-7deg) translateX(180px)
            }
            80% {
                transform: rotate(-2deg) translateX(60px)
            }
        }

        @keyframes strand-sway-2 {
            0%, 100% {
                transform: rotate(5deg) translateX(0)
            }
            25% {
                transform: rotate(8deg) translateX(-60px)
            }
            50% {
                transform: rotate(3deg) translateX(40px)
            }
            75% {
                transform: rotate(7deg) translateX(-40px)
            }
        }

        @keyframes strand-sway-3 {
            0%, 100% {
                transform: rotate(-4deg) translateX(0)
            }
            15% {
                transform: rotate(-7deg) translateX(-50px)
            }
            30% {
                transform: rotate(-2deg) translateX(-120px)
            }
            50% {
                transform: rotate(-6deg) translateX(-80px)
            }
            70% {
                transform: rotate(-3deg) translateX(30px)
            }
            85% {
                transform: rotate(-5deg) translateX(-20px)
            }
        }

        /* Sparkle particles */
        .sparkles {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden
        }

        .sparkle {
            position: absolute;
            width: 2px;
            height: 2px;
            background: white;
            border-radius: 50%;
            opacity: 0;
            box-shadow: 0 0 2px rgba(255, 255, 255, 0.9), 0 0 4px rgba(180, 240, 255, 0.5)
        }

        .app {
            display: flex;
            height: 100vh;
            max-height: 100dvh;
            position: relative;
            z-index: 1
        }

        .sidebar {
            width: 270px;
            background: var(--bg-panel);
            backdrop-filter: blur(var(--blur-xl)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--blur-xl)) saturate(180%);
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            height: 100vh;
            transition: transform var(--transition-smooth);
            flex-shrink: 0;
            position: relative
        }

        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.1) 20%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.1) 80%, transparent 100%)
        }

        .sidebar-header {
            padding: 1.5rem 1.25rem 1rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            border-bottom: 1px solid var(--glass-border);
            position: relative
        }

        .sidebar-header .icon-btn {
            width: 36px;
            height: 36px;
            min-width: 36px;
            min-height: 36px;
            flex-shrink: 0
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: .75rem;
            flex: 1
        }

        .sidebar-logo {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            flex: 1;
            margin-left: 0.5rem
        }

        .sidebar-logo-title {
            font-family: 'Quicksand', sans-serif;
            font-size: 1.25rem;
            font-weight: 700;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
            display: inline-block;
            letter-spacing: -0.01em;
            filter: drop-shadow(0 0 20px rgba(91, 196, 232, 0.3))
        }

        .sidebar-logo-subtitle {
            font-family: 'Quicksand', sans-serif;
            font-size: 0.6rem;
            color: var(--text-quaternary);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            font-weight: 600;
            padding-top: 2px
        }

        .sidebar-footer {
            padding: 0.75rem 1.25rem;
            border-top: 1px solid var(--glass-border);
            margin-top: auto;
            background: rgba(255, 255, 255, 0.02)
        }

        .sidebar-version {
            font-size: 0.6rem;
            color: var(--text-quaternary);
            opacity: 0.7;
            letter-spacing: 0.02em;
            text-align: center;
            display: block;
            width: 100%
        }

        .new-chat-btn {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .55rem 1.1rem;
            margin: 1rem 1rem .75rem;
            border-radius: var(--radius-xl);
            background: linear-gradient(160deg, rgba(70, 70, 85, 0.5) 0%, rgba(50, 50, 62, 0.55) 50%, rgba(45, 45, 58, 0.6) 100%);
            border: 2px solid rgba(255, 255, 255, 0.1);
            color: var(--text-secondary);
            cursor: pointer;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all var(--transition-smooth);
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 6px rgba(255, 255, 255, 0.02),
                0 2px 8px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.06)
        }

        .new-chat-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%);
            pointer-events: none
        }

        .new-chat-btn:hover {
            background: linear-gradient(160deg, rgba(80, 80, 98, 0.55) 0%, rgba(58, 58, 72, 0.6) 50%, rgba(52, 52, 66, 0.65) 100%);
            border-color: rgba(255, 255, 255, 0.18);
            color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: 
                0 0 10px rgba(255, 255, 255, 0.04),
                0 6px 20px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .new-chat-btn:active {
            transform: translateY(0);
            box-shadow: 
                0 0 4px rgba(255, 255, 255, 0.02),
                0 1px 4px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.04)
        }

        .search-container {
            padding: .5rem 1rem;
            position: relative;
            padding-top: 22px;
        }

        .chat-search {
            display: flex;
            width: 100%;
            border-radius: var(--radius-xl);
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            overflow: hidden;
            transition: all var(--transition-smooth)
        }

        .chat-search:focus-within {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px var(--accent-primary-soft), var(--shadow-glow)
        }

        .search-icon {
            padding: .5rem .75rem;
            color: var(--text-quaternary);
            display: flex;
            align-items: center
        }

        .search-input {
            width: 100%;
            padding: .5rem .5rem .5rem 0;
            background: transparent;
            border: none;
            color: var(--text-primary);
            outline: none;
            font-size: .875rem
        }

        .search-input::placeholder {
            color: var(--text-quaternary)
        }

        .chats-list {
            flex: 1;
            overflow-y: auto;
            padding: .5rem .75rem
        }

        .chats-list::-webkit-scrollbar {
            width: 6px
        }

        .chats-list::-webkit-scrollbar-track {
            background: transparent
        }

        .chats-list::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-pill)
        }

        .chats-list::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.15)
        }

        .chat-date {
            padding: .75rem .5rem .5rem;
            font-size: .7rem;
            color: var(--text-quaternary);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .08em
        }

        .chat-item {
            padding: .625rem .75rem;
            margin: .125rem 0;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: all var(--transition-smooth);
            border: 2px solid transparent;
            position: relative
        }

        .chat-item:hover {
            background: var(--liquid-glass);
            border-color: rgba(255, 255, 255, 0.08);
            box-shadow: 
                0 0 6px rgba(255, 255, 255, 0.02),
                0 2px 8px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.04)
        }

        .chat-item.active {
            background: linear-gradient(160deg, rgba(70, 70, 85, 0.5) 0%, rgba(55, 55, 68, 0.55) 100%);
            border-color: rgba(255, 255, 255, 0.12);
            box-shadow: 
                0 0 8px rgba(255, 255, 255, 0.03),
                0 3px 10px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.06)
        }

        .chat-item-content {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column
        }

        .chat-title {
            font-size: .875rem;
            font-weight: 500;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis
        }

        .chat-item:hover .chat-title,
        .chat-item.active .chat-title {
            color: var(--text-primary)
        }

        .chat-preview {
            font-size: .75rem;
            color: var(--text-quaternary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: .125rem
        }

        .chat-actions {
            display: flex;
            align-items: center;
            gap: .25rem;
            opacity: 0;
            transition: opacity var(--transition-fast)
        }

        .chat-item:hover .chat-actions {
            opacity: 1
        }

        .icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--liquid-glass);
            border: 2px solid rgba(255, 255, 255, 0.08);
            color: var(--text-tertiary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-smooth);
            line-height: 0;
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 5px rgba(255, 255, 255, 0.02),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .icon-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
            pointer-events: none
        }

        .icon-btn svg {
            display: block;
            flex-shrink: 0;
            position: relative;
            z-index: 1
        }

        #settingsBtn svg {
            transform: translate(2px, 2px)
        }

        .icon-btn:hover {
            background: var(--liquid-glass-hover);
            border-color: rgba(255, 255, 255, 0.15);
            color: var(--text-primary);
            transform: scale(1.05);
            box-shadow: 
                0 0 8px rgba(255, 255, 255, 0.04),
                0 4px 12px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.07)
        }

        .icon-btn:active {
            transform: scale(0.95);
            box-shadow: 
                0 0 3px rgba(255, 255, 255, 0.02),
                0 1px 3px rgba(0, 0, 0, 0.1)
        }

        .delete-btn {
            padding: .375rem;
            border-radius: var(--radius-sm);
            background: linear-gradient(160deg, rgba(60, 60, 72, 0.35) 0%, rgba(45, 45, 55, 0.4) 100%);
            border: 2px solid rgba(255, 255, 255, 0.05);
            color: var(--text-quaternary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-smooth);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04)
        }

        .delete-btn:hover {
            background: linear-gradient(160deg, rgba(255, 85, 75, 0.25) 0%, rgba(220, 60, 55, 0.3) 100%);
            border-color: rgba(255, 69, 58, 0.25);
            color: var(--error);
            box-shadow: 
                0 0 8px rgba(255, 69, 58, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.06)
        }

        .delete-btn:active {
            transform: scale(0.92)
        }

        .chat-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            transition: margin-left var(--transition-smooth)
        }

        .sidebar-collapsed .chat-container {
            margin-left: -280px;
            width: calc(100% + 280px)
        }

        .chat-header {
            padding: 1.25rem 2rem;
            border-bottom: 1px solid var(--glass-border);
            position: sticky;
            top: 0;
            z-index: 30;
            background: var(--bg-panel);
            backdrop-filter: blur(var(--blur-lg)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--blur-lg)) saturate(180%);
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex: 1
        }

        .header-controls {
            display: flex;
            align-items: center;
            gap: .5rem
        }

        .header-sidebar-toggle {
            display: none
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem 2rem;
            position: relative
        }

        .messages-container::-webkit-scrollbar {
            width: 8px
        }

        .messages-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
            border-radius: var(--radius-pill)
        }

        .messages-container::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-pill)
        }

        .messages-container::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.18)
        }

        .message {
            margin-bottom: 1.5rem;
            max-width: 42rem;
            animation: messageSlideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1)
        }

        @keyframes messageSlideIn {
            from {
                opacity: 0;
                transform: translateY(12px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .user-message {
            background: var(--glass-bg-hover);
            backdrop-filter: blur(var(--blur-md)) saturate(150%);
            -webkit-backdrop-filter: blur(var(--blur-md)) saturate(150%);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-2xl);
            border: 1px solid var(--glass-border);
            position: relative;
            box-shadow: var(--shadow-sm);
            margin-left: auto;
            margin-right: 0
        }

        .user-message::before {
            content: '';
            position: absolute;
            top: 0;
            left: 20px;
            right: 20px;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.15) 30%, rgba(255, 255, 255, 0.25) 50%, rgba(255, 255, 255, 0.15) 70%, transparent 100%);
            border-radius: var(--radius-pill)
        }

        .assistant-message {
            padding: 1rem 1.5rem;
            position: relative;
            background: rgba(0, 0, 0, 0.25);
            border-radius: var(--radius-lg);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            margin-left: 0;
            margin-right: auto
        }

        .message-attachments {
            display: flex;
            flex-wrap: wrap;
            gap: .375rem;
            margin-bottom: .625rem
        }

        .message-attachment-chip {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            padding: .1875rem .5rem;
            background: var(--accent-primary-soft);
            border: 1px solid rgba(91, 196, 232, 0.15);
            border-radius: var(--radius-pill);
            font-size: .6875rem;
            color: var(--accent-primary)
        }

        .message-attachment-chip svg {
            width: 10px;
            height: 10px;
            opacity: 0.7
        }

        .message-content {
            line-height: 1.7;
            color: var(--text-secondary)
        }

        .user-message .message-content {
            color: var(--text-primary)
        }

        .message-content pre {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            padding: 1rem;
            overflow-x: auto;
            margin: .5rem 0
        }

        .message-content code {
            background: var(--glass-bg);
            border-radius: var(--radius-xs);
            padding: .125rem .5rem;
            font-family: 'SF Mono', 'Fira Code', 'Monaco', 'Consolas', monospace;
            font-size: .85em;
            border: 1px solid var(--glass-border)
        }

        .message-content strong {
            font-weight: 600;
            color: var(--text-primary)
        }

        .message-actions {
            display: flex;
            gap: .5rem;
            margin-top: .75rem;
            flex-wrap: wrap
        }

        .action-btn {
            padding: .375rem .75rem;
            background: var(--liquid-glass);
            backdrop-filter: blur(var(--blur-sm));
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-lg);
            color: var(--text-tertiary);
            cursor: pointer;
            font-size: .75rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: .375rem;
            transition: all var(--transition-smooth);
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 5px rgba(255, 255, 255, 0.02),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
            pointer-events: none
        }

        .action-btn:hover {
            background: linear-gradient(160deg, rgba(75, 75, 90, 0.55) 0%, rgba(58, 58, 70, 0.6) 100%);
            border-color: rgba(255, 255, 255, 0.18);
            color: var(--text-primary);
            transform: translateY(-2px);
            box-shadow: 
                0 0 8px rgba(255, 255, 255, 0.03),
                0 4px 12px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.07)
        }

        .action-btn:active {
            transform: translateY(0);
            box-shadow: 
                0 0 3px rgba(255, 255, 255, 0.02),
                0 1px 3px rgba(0, 0, 0, 0.1)
        }

        .action-btn.regenerate {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.2) 0%, rgba(70, 185, 215, 0.25) 100%);
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(91, 196, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.1)
        }

        .action-btn.regenerate:hover {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.28) 0%, rgba(70, 185, 215, 0.33) 100%);
            border-color: rgba(91, 196, 232, 0.35);
            box-shadow: 
                0 0 14px rgba(91, 196, 232, 0.18),
                0 4px 12px rgba(0, 0, 0, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.12)
        }

        .action-btn.stop {
            background: linear-gradient(160deg, rgba(255, 69, 58, 0.2) 0%, rgba(230, 55, 48, 0.25) 100%);
            color: var(--error);
            border-color: rgba(255, 69, 58, 0.28);
            box-shadow: 
                0 0 8px rgba(255, 69, 58, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .action-btn.stop:hover {
            background: linear-gradient(160deg, rgba(255, 69, 58, 0.28) 0%, rgba(230, 55, 48, 0.33) 100%);
            border-color: rgba(255, 69, 58, 0.38);
            box-shadow: 
                0 0 14px rgba(255, 69, 58, 0.18),
                0 4px 12px rgba(0, 0, 0, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.1)
        }

        .action-btn svg {
            width: 12px;
            height: 12px
        }

        .edit-textarea {
            width: 100%;
            min-height: 100px;
            padding: .75rem 1rem;
            background: var(--glass-bg);
            border: 2px solid var(--accent-primary);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: inherit;
            font-size: .9375rem;
            line-height: 1.5;
            resize: vertical;
            margin-bottom: .5rem;
            outline: none;
            box-shadow: 0 0 0 4px var(--accent-primary-soft), var(--shadow-glow)
        }

        .edit-actions {
            display: flex;
            gap: .5rem;
            margin-top: .5rem
        }

        .edit-actions button {
            padding: .5rem 1rem;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-weight: 500;
            border: none;
            font-size: .875rem;
            transition: all var(--transition-smooth)
        }

        .edit-actions .save-btn {
            background: var(--success);
            color: #000
        }

        .edit-actions .save-btn:hover {
            filter: brightness(1.1);
            transform: translateY(-1px)
        }

        .edit-actions .cancel-btn {
            background: var(--glass-bg-elevated);
            color: var(--text-secondary);
            border: 1px solid var(--glass-border)
        }

        .edit-actions .cancel-btn:hover {
            background: var(--glass-bg-hover);
            color: var(--text-primary)
        }

        .input-container {
            padding: 1.25rem 2rem;
            border-top: 1px solid var(--glass-border);
            background: rgba(10, 10, 15, 0.8);
            backdrop-filter: blur(var(--blur-lg)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--blur-lg)) saturate(180%)
        }

        .input-form {
            max-width: 48rem;
            margin: 0 auto;
            display: flex;
            gap: .75rem
        }

        .input-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            border-radius: var(--radius-2xl);
            border: 1px solid var(--glass-border);
            background: rgba(15, 25, 35, 0.6);
            backdrop-filter: blur(var(--blur-md));
            transition: all var(--transition-smooth);
            overflow: hidden;
            position: relative
        }

        .input-wrapper::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: calc(var(--radius-2xl) + 2px);
            background: var(--accent-gradient);
            opacity: 0;
            transition: opacity var(--transition-smooth);
            z-index: -1;
            filter: blur(8px)
        }

        .input-wrapper:focus-within {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(20, 32, 45, 0.9);
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3)
        }

        .input-wrapper:focus-within::before {
            opacity: 0.3
        }

        .input-wrapper:focus-within .input-controls {
            background: rgba(0, 0, 0, 0.5);
            border-top-color: rgba(255, 255, 255, 0.08)
        }

        .input-wrapper:focus-within .stat-box {
            background: rgba(0, 0, 0, 0.5) !important;
            border-color: rgba(255, 255, 255, 0.15) !important
        }

        .input-wrapper:focus-within .stat-box.status {
            background: rgba(0, 0, 0, 0.5) !important;
            color: var(--accent-primary) !important;
            border-color: rgba(91, 196, 232, 0.4) !important
        }

        .input-wrapper:focus-within .stat-box.status.pruning {
            background: rgba(0, 0, 0, 0.5) !important;
            color: var(--warning) !important;
            border-color: rgba(255, 214, 10, 0.3) !important
        }

        .input-wrapper:focus-within .stat-box.status.generating {
            background: rgba(0, 0, 0, 0.5) !important;
            color: var(--success) !important;
            border-color: rgba(48, 209, 88, 0.3) !important
        }

        .input-wrapper:focus-within .stat-box.status.error {
            background: rgba(0, 0, 0, 0.5) !important;
            color: var(--error) !important;
            border-color: rgba(255, 69, 58, 0.3) !important
        }

        .input-wrapper:focus-within .stat-box.context {
            background: rgba(0, 0, 0, 0.5) !important;
            border-color: rgba(255, 255, 255, 0.2) !important
        }

        .input-wrapper:focus-within .stat-box.input-tokens {
            background: rgba(0, 0, 0, 0.5) !important;
            border-color: rgba(255, 255, 255, 0.2) !important
        }

        .input-wrapper:focus-within .stat-box.warning {
            background: rgba(0, 0, 0, 0.5) !important;
            border-color: rgba(255, 69, 58, 0.3) !important
        }

        .input-wrapper:focus-within .stat-box.hint {
            background: rgba(0, 0, 0, 0.5) !important;
            border-color: rgba(255, 214, 10, 0.3) !important
        }

        .input-wrapper:focus-within .send-btn {
            background: rgba(0, 0, 0, 0.5);
            border-color: rgba(255, 255, 255, 0.4);
            color: rgba(255, 255, 255, 0.7)
        }

        .input-wrapper:focus-within .send-btn:enabled {
            background: rgba(0, 0, 0, 0.4);
            color: rgba(255, 255, 255, 0.8)
        }

        .input-wrapper:focus-within .send-btn:enabled:hover {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.9)
        }

        .text-input {
            background: transparent;
            border: none;
            color: var(--text-primary);
            outline: none;
            width: 100%;
            padding: .875rem 1rem;
            resize: none;
            font-family: inherit;
            font-size: .9375rem;
            line-height: 1.5;
            min-height: 2.5rem;
            max-height: 12rem
        }

        .text-input::placeholder {
            color: var(--text-quaternary)
        }

        .input-wrapper:focus-within .text-input::placeholder {
            color: rgba(255, 255, 255, 0.5)
        }

        .textarea-row {
            display: flex;
            align-items: flex-end;
            padding-right: .75rem;
            gap: .25rem
        }

        .textarea-row .send-btn,
        .textarea-row .attach-btn {
            flex-shrink: 0;
            margin-bottom: .625rem;
            width: 2rem;
            height: 2rem;
            padding: .375rem
        }

        .textarea-row .send-btn svg,
        .textarea-row .attach-btn svg {
            width: 16px;
            height: 16px
        }

        .attach-btn {
            border-radius: var(--radius-pill);
            background: linear-gradient(160deg, rgba(60, 60, 72, 0.4) 0%, rgba(45, 45, 55, 0.45) 100%);
            border: 2px solid rgba(255, 255, 255, 0.06);
            color: var(--text-quaternary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-smooth);
            box-shadow: 
                0 0 4px rgba(255, 255, 255, 0.02),
                inset 0 1px 0 rgba(255, 255, 255, 0.04)
        }

        .attach-btn:hover {
            color: var(--text-secondary);
            background: linear-gradient(160deg, rgba(70, 70, 85, 0.45) 0%, rgba(52, 52, 62, 0.5) 100%);
            border-color: rgba(255, 255, 255, 0.1);
            transform: scale(1.05);
            box-shadow: 
                0 0 8px rgba(255, 255, 255, 0.03),
                0 2px 8px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.06)
        }

        .attach-btn:active {
            transform: scale(0.95)
        }

        .attach-btn.has-files {
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.2);
            box-shadow: 
                0 0 10px rgba(91, 196, 232, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.06)
        }

        .attachments-preview {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            padding: 0 2rem;
            max-width: 48rem;
            margin: 0 auto;
            padding-bottom: 20px
        }

        .attachments-preview:empty {
            display: none
        }

        .attachment-chip {
            display: flex;
            align-items: center;
            gap: .375rem;
            padding: .25rem .5rem .25rem .625rem;
            background: var(--accent-primary-soft);
            border: 1px solid rgba(91, 196, 232, 0.2);
            border-radius: var(--radius-pill);
            font-size: .75rem;
            color: var(--accent-primary);
            animation: fadeIn var(--transition-smooth)
        }

        .attachment-chip svg {
            width: 12px;
            height: 12px;
            opacity: 0.8
        }

        .attachment-chip .remove-attachment {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 16px;
            height: 16px;
            border: none;
            background: transparent;
            color: var(--accent-primary);
            cursor: pointer;
            border-radius: 50%;
            padding: 0;
            opacity: 0.6;
            transition: all var(--transition-fast)
        }

        .attachment-chip .remove-attachment:hover {
            opacity: 1;
            background: rgba(91, 196, 232, 0.2)
        }

        .attachment-chip .remove-attachment svg {
            width: 10px;
            height: 10px
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(4px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .input-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .5rem .75rem;
            border-top: 1px solid var(--glass-border);
            gap: .75rem;
            background: rgba(255, 255, 255, 0.02)
        }

        .stats-container {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            flex: 1;
            min-width: 0
        }

        .stat-box {
            padding: .375rem .75rem;
            border-radius: var(--radius-pill);
            background: var(--liquid-glass);
            border: 2px solid rgba(255, 255, 255, 0.08);
            color: var(--text-tertiary);
            font-size: .7rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: .375rem;
            white-space: nowrap;
            max-width: 200px;
            flex-shrink: 0;
            letter-spacing: 0.02em;
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 5px rgba(255, 255, 255, 0.02),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%);
            pointer-events: none
        }

        .stat-box svg {
            width: 12px;
            height: 12px;
            flex-shrink: 0;
            opacity: 0.8;
            position: relative;
            z-index: 1
        }

        .stat-box.status {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%);
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(91, 196, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.pruning {
            background: linear-gradient(160deg, rgba(255, 214, 10, 0.15) 0%, rgba(220, 180, 10, 0.2) 100%);
            color: var(--warning);
            border-color: rgba(255, 214, 10, 0.25);
            box-shadow: 
                0 0 8px rgba(255, 214, 10, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.generating {
            background: linear-gradient(160deg, rgba(48, 209, 88, 0.15) 0%, rgba(40, 175, 70, 0.2) 100%);
            color: var(--success);
            border-color: rgba(48, 209, 88, 0.25);
            box-shadow: 
                0 0 8px rgba(48, 209, 88, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.error {
            background: linear-gradient(160deg, rgba(255, 69, 58, 0.15) 0%, rgba(220, 55, 48, 0.2) 100%);
            color: var(--error);
            border-color: rgba(255, 69, 58, 0.25);
            box-shadow: 
                0 0 8px rgba(255, 69, 58, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.routing {
            background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%);
            color: var(--accent-secondary);
            border-color: rgba(75, 190, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(75, 190, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.indexing {
            background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%);
            color: var(--accent-secondary);
            border-color: rgba(75, 190, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(75, 190, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.searching {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%);
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(91, 196, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.switching {
            background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%);
            color: var(--accent-secondary);
            border-color: rgba(75, 190, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(75, 190, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.ready {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%);
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(91, 196, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.stopped {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%);
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(91, 196, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.offline {
            background: linear-gradient(160deg, rgba(255, 69, 58, 0.15) 0%, rgba(220, 55, 48, 0.2) 100%);
            color: var(--error);
            border-color: rgba(255, 69, 58, 0.25);
            box-shadow: 
                0 0 8px rgba(255, 69, 58, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.status.warning {
            background: linear-gradient(160deg, rgba(255, 214, 10, 0.15) 0%, rgba(220, 180, 10, 0.2) 100%);
            color: var(--warning);
            border-color: rgba(255, 214, 10, 0.25);
            box-shadow: 
                0 0 8px rgba(255, 214, 10, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.context {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.15) 0%, rgba(75, 190, 220, 0.2) 100%);
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.25);
            box-shadow: 
                0 0 8px rgba(91, 196, 232, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .stat-box.pruning {
            background: var(--success-soft);
            color: var(--success);
            border-color: rgba(48, 209, 88, 0.2)
        }

        .stat-box.input-tokens {
            background: var(--accent-secondary-soft);
            color: var(--accent-secondary);
            border-color: rgba(75, 190, 232, 0.2);
            margin-left: auto
        }

        .stat-box.context-warning {
            background: var(--warning-soft);
            color: var(--warning);
            border-color: rgba(255, 214, 10, 0.2);
            animation: pulse-glow 2s infinite
        }

        .stat-box.context-hint {
            background: var(--accent-primary-soft);
            color: var(--accent-primary);
            border-color: rgba(91, 196, 232, 0.2);
            animation: pulse-glow 2s infinite
        }

        .stat-box.context-error {
            background: var(--error-soft);
            color: var(--error);
            border-color: rgba(255, 69, 58, 0.2);
            animation: pulse-glow 2s infinite
        }

        @keyframes pulse-glow {

            0%,
            100% {
                opacity: 1;
                box-shadow: 0 0 0 0 transparent
            }

            50% {
                opacity: 0.8;
                box-shadow: 0 0 12px rgba(255, 69, 58, 0.3)
            }
        }

        .input-actions {
            display: flex;
            gap: .5rem;
            align-items: center
        }

        .send-btn {
            padding: .5rem;
            border-radius: var(--radius-pill);
            background: var(--liquid-glass);
            border: 2px solid rgba(255, 255, 255, 0.08);
            color: var(--text-quaternary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            transition: all var(--transition-spring);
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 5px rgba(255, 255, 255, 0.02),
                0 2px 6px rgba(0, 0, 0, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .send-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
            pointer-events: none
        }

        .send-btn::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -100%;
            width: 60%;
            height: 200%;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.15) 50%, transparent 100%);
            transform: skewX(-20deg);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease
        }

        .send-btn:enabled {
            color: var(--text-primary);
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.2) 0%, rgba(75, 190, 220, 0.25) 100%);
            border-color: rgba(91, 196, 232, 0.3);
            box-shadow: 
                0 0 12px rgba(91, 196, 232, 0.15),
                0 2px 8px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1)
        }

        .send-btn:enabled::after {
            animation: send-btn-shine 2.5s ease-in-out infinite
        }

        @keyframes send-btn-shine {
            0% {
                left: -100%;
                opacity: 0
            }
            10% {
                opacity: 1
            }
            40% {
                left: 150%;
                opacity: 1
            }
            50%, 100% {
                left: 150%;
                opacity: 0
            }
        }

        .send-btn:enabled:hover {
            background: linear-gradient(160deg, rgba(91, 196, 232, 0.3) 0%, rgba(75, 190, 220, 0.35) 100%);
            border-color: rgba(91, 196, 232, 0.45);
            transform: translateY(-2px) scale(1.08);
            box-shadow: 
                0 0 20px rgba(91, 196, 232, 0.25),
                0 8px 20px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.15)
        }

        .send-btn:enabled:hover::after {
            animation: send-btn-shine-fast 1s ease-in-out infinite
        }

        @keyframes send-btn-shine-fast {
            0% {
                left: -100%;
                opacity: 0
            }
            15% {
                opacity: 1
            }
            60% {
                left: 150%;
                opacity: 1
            }
            70%, 100% {
                left: 150%;
                opacity: 0
            }
        }

        .send-btn:enabled:active {
            transform: translateY(0) scale(0.95);
            box-shadow: 
                0 0 8px rgba(91, 196, 232, 0.1),
                0 1px 3px rgba(0, 0, 0, 0.15)
        }

        .send-btn:enabled:active::after {
            animation: none;
            opacity: 0
        }

        .send-btn svg {
            position: relative;
            z-index: 1
        }

        .welcome-screen {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            pointer-events: none
        }

        .welcome-content {
            text-align: center;
            padding: 4rem 1rem;
            max-width: 48rem;
            margin: 0 auto
        }

        .welcome-title {
            font-family: 'Quicksand', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: .5rem;
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
            display: inline-block;
            letter-spacing: -0.02em;
            filter: drop-shadow(0 0 30px rgba(91, 196, 232, 0.4))
        }

        .welcome-subtitle {
            font-family: 'Quicksand', sans-serif;
            font-size: 0.8rem;
            color: var(--text-quaternary);
            letter-spacing: 0.15em;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 3rem
        }

        .welcome-subtitle::before {
            content: "✦";
            color: var(--accent-primary);
            padding-right: 8px;
            opacity: 0.7
        }

        .suggestions {
            max-width: 36rem;
            margin: 0 auto
        }

        .suggestions-title {
            display: flex;
            align-items: center;
            gap: .5rem;
            justify-content: center;
            font-size: .7rem;
            color: var(--text-quaternary);
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 600
        }

        .suggestions-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: .75rem
        }

        .suggestion-btn {
            background: linear-gradient(160deg, rgba(70, 70, 85, 0.55) 0%, rgba(50, 50, 62, 0.6) 50%, rgba(45, 45, 58, 0.65) 100%);
            backdrop-filter: blur(var(--blur-md));
            -webkit-backdrop-filter: blur(var(--blur-md));
            border: 2px solid rgba(255, 255, 255, 0.12);
            padding: 1rem 1.25rem;
            border-radius: var(--radius-xl);
            text-align: left;
            cursor: pointer;
            transition: all var(--transition-smooth);
            pointer-events: auto;
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 8px rgba(255, 255, 255, 0.03),
                0 4px 16px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .suggestion-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
            pointer-events: none
        }

        .suggestion-btn:hover {
            background: linear-gradient(160deg, rgba(80, 80, 98, 0.6) 0%, rgba(58, 58, 72, 0.65) 50%, rgba(52, 52, 66, 0.7) 100%);
            border-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-3px);
            box-shadow: 
                0 0 12px rgba(255, 255, 255, 0.06),
                0 8px 24px rgba(0, 0, 0, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.1)
        }

        .suggestion-btn:active {
            transform: translateY(-1px);
            box-shadow: 
                0 0 6px rgba(255, 255, 255, 0.04),
                0 2px 8px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.06)
        }

        .suggestion-title {
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: .25rem;
            font-size: .9rem
        }

        .suggestion-btn:hover .suggestion-title {
            color: var(--text-primary)
        }

        .suggestion-desc {
            font-size: .75rem;
            color: var(--text-quaternary);
            line-height: 1.4
        }

        .settings-panel {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            width: 450px;
            background: var(--bg-panel);
            backdrop-filter: blur(var(--blur-xl)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--blur-xl)) saturate(180%);
            border-left: 1px solid var(--glass-border);
            z-index: 1000;
            transform: translateX(100%);
            transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            overflow: hidden
        }

        .settings-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.1) 20%, rgba(255, 255, 255, 0.2) 50%, rgba(255, 255, 255, 0.1) 80%, transparent 100%);
            z-index: 1
        }

        .settings-panel.open {
            transform: translateX(0)
        }

        .settings-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--glass-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.02)
        }

        .settings-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: -0.02em
        }

        .settings-content {
            flex: 1;
            overflow-y: auto;
            padding: 2rem
        }

        .settings-group {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            position: relative
        }

        .settings-group::before {
            content: '';
            position: absolute;
            top: 0;
            left: 30px;
            right: 30px;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.1) 50%, transparent 100%)
        }

        .settings-item {
            margin-bottom: 1.25rem
        }

        .settings-item:last-child {
            margin-bottom: 0
        }

        .settings-label {
            display: block;
            font-size: .875rem;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: .5rem
        }

        .settings-input,
        .settings-select,
        .settings-textarea {
            width: 100%;
            padding: .75rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: .875rem;
            transition: all var(--transition-smooth)
        }

        .settings-textarea {
            min-height: 100px;
            resize: vertical;
            font-family: inherit
        }

        .settings-input:focus,
        .settings-select:focus,
        .settings-textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px var(--accent-primary-soft), var(--shadow-glow)
        }

        .settings-hint {
            font-size: .75rem;
            color: var(--text-quaternary);
            margin-top: .375rem;
            line-height: 1.4
        }

        .settings-checkbox {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 1rem
        }

        .settings-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            border-radius: 5px;
            accent-color: var(--accent-primary)
        }

        .settings-checkbox label {
            font-size: .875rem;
            font-weight: 500;
            color: var(--text-secondary);
            cursor: pointer
        }

        .settings-separator {
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, var(--glass-border-bright) 50%, transparent 100%);
            margin: 1.5rem 0
        }

        .settings-subtitle {
            font-size: .8rem;
            font-weight: 600;
            color: var(--text-tertiary);
            margin-bottom: .75rem;
            display: block;
            text-transform: uppercase;
            letter-spacing: 0.05em
        }

        .settings-actions {
            padding: 1.5rem 2rem;
            border-top: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.02);
            display: flex;
            flex-direction: column;
            gap: .75rem
        }

        .settings-btn {
            width: 100%;
            padding: .875rem;
            border-radius: var(--radius-lg);
            font-weight: 500;
            font-size: .875rem;
            cursor: pointer;
            transition: all var(--transition-smooth);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            position: relative;
            overflow: hidden
        }

        .settings-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.12) 0%, transparent 100%);
            pointer-events: none
        }

        .settings-btn.save {
            background: linear-gradient(160deg, rgba(55, 210, 95, 0.95) 0%, rgba(40, 185, 75, 0.95) 100%);
            color: #000;
            border: 2px solid rgba(255, 255, 255, 0.2);
            box-shadow: 
                0 0 12px rgba(48, 209, 88, 0.2),
                0 4px 12px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.2)
        }

        .settings-btn.save:hover:not(:disabled) {
            background: linear-gradient(160deg, rgba(65, 225, 105, 0.95) 0%, rgba(50, 200, 85, 0.95) 100%);
            border-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 
                0 0 18px rgba(48, 209, 88, 0.3),
                0 6px 20px rgba(0, 0, 0, 0.25),
                inset 0 1px 0 rgba(255, 255, 255, 0.25)
        }

        .settings-btn.save:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 
                0 0 8px rgba(48, 209, 88, 0.15),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.15)
        }

        .settings-btn.cancel {
            background: linear-gradient(160deg, rgba(70, 70, 85, 0.55) 0%, rgba(50, 50, 62, 0.6) 100%);
            color: var(--text-secondary);
            border: 2px solid rgba(255, 255, 255, 0.1);
            box-shadow: 
                0 0 6px rgba(255, 255, 255, 0.02),
                0 2px 8px rgba(0, 0, 0, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .settings-btn.cancel:hover {
            background: linear-gradient(160deg, rgba(80, 80, 98, 0.6) 0%, rgba(58, 58, 72, 0.65) 100%);
            color: var(--text-primary);
            border-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-1px);
            box-shadow: 
                0 0 10px rgba(255, 255, 255, 0.03),
                0 4px 12px rgba(0, 0, 0, 0.22),
                inset 0 1px 0 rgba(255, 255, 255, 0.07)
        }

        .settings-btn.cancel:active {
            transform: translateY(0);
            box-shadow: 
                0 0 4px rgba(255, 255, 255, 0.02),
                0 1px 4px rgba(0, 0, 0, 0.12)
        }

        .settings-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed
        }

        .pruning-status {
            display: inline-flex;
            align-items: center;
            gap: .375rem;
            padding: .25rem .75rem;
            border-radius: var(--radius-pill);
            font-size: .7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: linear-gradient(160deg, rgba(48, 209, 88, 0.15) 0%, rgba(40, 175, 70, 0.2) 100%);
            color: var(--success);
            border: 2px solid rgba(48, 209, 88, 0.25);
            margin-left: .5rem;
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 8px rgba(48, 209, 88, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .pruning-status::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
            pointer-events: none
        }

        .pruning-status.inactive {
            background: linear-gradient(160deg, rgba(255, 69, 58, 0.15) 0%, rgba(220, 55, 48, 0.2) 100%);
            color: var(--error);
            border-color: rgba(255, 69, 58, 0.25);
            box-shadow: 
                0 0 8px rgba(255, 69, 58, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .governor-section {
            margin-top: 1rem
        }

        .governor-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: .5rem
        }

        .governor-title svg {
            width: 20px;
            height: 20px;
            color: var(--accent-primary)
        }

        .vram-display {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .75rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            margin-bottom: 1rem
        }

        .vram-icon {
            width: 24px;
            height: 24px;
            color: var(--accent-primary)
        }

        .vram-info {
            flex: 1
        }

        .vram-label {
            font-size: .75rem;
            color: var(--text-quaternary);
            text-transform: uppercase;
            letter-spacing: 0.05em
        }

        .vram-value {
            font-size: .875rem;
            font-weight: 600;
            color: var(--text-primary)
        }

        .vram-gpu {
            font-size: .75rem;
            color: var(--text-tertiary)
        }

        .vram-error {
            color: var(--error);
            font-size: .8rem
        }

        .vram-loading {
            color: var(--text-tertiary);
            font-size: .8rem;
            font-style: italic
        }

        .model-select-wrapper {
            margin-bottom: 1rem
        }

        .model-select-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: .5rem
        }

        .model-select-label span {
            font-size: .875rem;
            font-weight: 500;
            color: var(--text-secondary)
        }

        .model-size {
            font-size: .75rem;
            color: var(--text-quaternary)
        }

        .model-select {
            width: 100%;
            padding: .75rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: .875rem;
            transition: all var(--transition-smooth);
            cursor: pointer
        }

        .model-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px var(--accent-primary-soft)
        }

        .model-select option {
            background: #1a1a24;
            color: #e5e5e5;
            padding: 0.5rem
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: 0.4
            }
        }

        .startup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.85);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s
        }

        .startup-overlay.active {
            opacity: 1;
            visibility: visible
        }

        .startup-content {
            text-align: center;
            padding: 2rem
        }

        .startup-spinner {
            width: 220px;
            height: 18px;
            border-radius: var(--radius-pill);
            background: linear-gradient(160deg, rgba(50, 50, 60, 0.5) 0%, rgba(35, 35, 45, 0.6) 100%);
            border: 2px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
            position: relative;
            margin: 0 auto 1.5rem;
            box-shadow: 
                inset 0 2px 4px rgba(0, 0, 0, 0.3),
                0 0 12px rgba(91, 196, 232, 0.1)
        }

        .startup-spinner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 100%;
            background: repeating-linear-gradient(
                -45deg,
                rgba(255, 255, 255, 0.95) 0px,
                rgba(255, 255, 255, 0.95) 12px,
                #5BC4E8 12px,
                #5BC4E8 24px
            );
            background-size: 33.94px 100%;
            animation: candy-cane 0.6s linear infinite;
            border-radius: var(--radius-pill)
        }

        .startup-spinner::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.4) 0%, transparent 100%);
            border-radius: var(--radius-pill) var(--radius-pill) 0 0;
            pointer-events: none
        }

        @keyframes candy-cane {
            0% {
                background-position: 0 0
            }
            100% {
                background-position: 33.94px 0
            }
        }

        .startup-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: .5rem
        }

        .startup-message {
            color: var(--text-tertiary);
            font-size: .875rem;
            max-width: 300px
        }

        .startup-error {
            color: var(--error);
            font-size: .875rem;
            margin-top: 1rem;
            max-width: 400px;
            text-align: left;
            background: var(--error-soft);
            padding: 1rem;
            border-radius: var(--radius-md);
            white-space: pre-wrap;
            font-family: monospace;
            font-size: .75rem;
            max-height: 200px;
            overflow-y: auto
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1100;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s
        }

        .modal.active {
            opacity: 1;
            visibility: visible
        }

        .modal-content {
            background: rgba(20, 20, 28, 0.95);
            backdrop-filter: blur(var(--blur-xl)) saturate(200%);
            -webkit-backdrop-filter: blur(var(--blur-xl)) saturate(200%);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-2xl);
            padding: 1.5rem;
            max-width: 400px;
            width: 90%;
            margin: 1rem;
            box-shadow: var(--shadow-lg);
            position: relative;
            animation: modalSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.95) translateY(10px)
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0)
            }
        }

        .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 30px;
            right: 30px;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255, 255, 255, 0.15) 50%, transparent 100%)
        }

        .modal-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: .75rem
        }

        .modal-message {
            color: var(--text-tertiary);
            margin-bottom: 1.5rem;
            line-height: 1.5
        }

        .modal-actions {
            display: flex;
            gap: .75rem;
            justify-content: flex-end
        }

        .modal-btn {
            padding: .625rem 1.25rem;
            border-radius: var(--radius-md);
            border: 1px solid var(--glass-border);
            background: var(--glass-bg-elevated);
            color: var(--text-secondary);
            cursor: pointer;
            font-weight: 500;
            transition: all var(--transition-smooth)
        }

        .modal-btn:hover {
            background: var(--glass-bg-hover);
            color: var(--text-primary)
        }

        .modal-btn.danger {
            background: var(--error-soft);
            border-color: rgba(255, 69, 58, 0.3);
            color: var(--error)
        }

        .modal-btn.danger:hover {
            background: rgba(255, 69, 58, 0.25)
        }

        .modal-btn.confirm {
            background: var(--success);
            color: #000;
            border: none
        }

        .modal-btn.confirm:hover {
            filter: brightness(1.1)
        }

        .modal-input {
            width: 100%;
            padding: .875rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: .875rem;
            margin-bottom: 1.5rem;
            transition: all var(--transition-smooth)
        }

        .modal-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px var(--accent-primary-soft)
        }

        @keyframes spin {
            from {
                transform: rotate(0deg)
            }

            to {
                transform: rotate(360deg)
            }
        }

        @keyframes blink {

            0%,
            50% {
                opacity: 1
            }

            51%,
            100% {
                opacity: 0
            }
        }

        @keyframes waterfall {
            from {
                opacity: 0;
                transform: translateY(15px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .spinner {
            animation: spin 1s linear infinite
        }

        .typing-cursor {
            display: inline-block;
            width: 2px;
            height: 18px;
            background: var(--accent-primary);
            margin-left: 3px;
            vertical-align: middle;
            animation: blink 1s infinite;
            border-radius: 1px;
            box-shadow: 0 0 8px var(--accent-primary)
        }

        .waterfall {
            animation: waterfall 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) forwards
        }

        .hidden {
            display: none !important
        }

        .scrollbar-hidden {
            scrollbar-width: none;
            -ms-overflow-style: none
        }

        .scrollbar-hidden::-webkit-scrollbar {
            display: none
        }

        .sidebar-collapsed .sidebar {
            transform: translateX(-100%)
        }

        .sidebar-collapsed .header-sidebar-toggle {
            display: flex
        }

        .mobile-sidebar-toggle {
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 40;
            padding: .5rem;
            border-radius: var(--radius-md);
            background: var(--glass-bg-elevated);
            backdrop-filter: blur(var(--blur-md));
            border: 1px solid var(--glass-border);
            color: var(--text-tertiary);
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            transition: all var(--transition-smooth)
        }

        .mobile-sidebar-toggle:hover {
            background: var(--glass-bg-hover);
            color: var(--text-primary)
        }

        @media (max-width:768px) {
            .sidebar-logo-title {
                font-size: 1.1rem
            }

            .sidebar-logo-subtitle {
                font-size: 0.6rem
            }

            .welcome-title {
                font-size: 2rem
            }

            .suggestions-grid {
                grid-template-columns: 1fr
            }

            .settings-panel {
                width: 100%
            }
        }

        @media (max-width:480px) {
            .sidebar-logo-title {
                font-size: 1rem
            }

            .sidebar-logo-subtitle {
                font-size: 0.55rem
            }

            .welcome-title {
                font-size: 1.75rem
            }

            .chat-header,
            .input-container,
            .messages-container {
                padding-left: 1rem;
                padding-right: 1rem
            }
        }

        /* NEW STYLES FOR PRUNING UI */
        .pruned-container {
            margin-top: 10px;
            border-left: 3px solid var(--warning);
            padding-left: 10px;
        }

        .pruned-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: var(--warning);
            cursor: pointer;
            margin-bottom: 8px;
            padding: 6px 12px;
            border-radius: var(--radius-md);
            background: linear-gradient(160deg, rgba(255, 214, 10, 0.15) 0%, rgba(220, 180, 10, 0.2) 100%);
            border: 2px solid rgba(255, 214, 10, 0.25);
            transition: all var(--transition-smooth);
            user-select: none;
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 8px rgba(255, 214, 10, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.1)
        }

        .pruned-toggle::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.1) 0%, transparent 100%);
            pointer-events: none
        }

        .pruned-toggle:hover {
            background: linear-gradient(160deg, rgba(255, 214, 10, 0.22) 0%, rgba(220, 180, 10, 0.28) 100%);
            border-color: rgba(255, 214, 10, 0.35);
            box-shadow: 
                0 0 12px rgba(255, 214, 10, 0.15),
                0 4px 10px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.12)
        }

        .pruned-toggle svg {
            width: 14px;
            height: 14px;
            transition: transform var(--transition-smooth);
            position: relative;
            z-index: 1
        }

        .pruned-toggle.collapsed svg {
            transform: rotate(-90deg);
        }

        .pruned-toggle.expanded svg {
            transform: rotate(0deg);
        }

        .pruned-content {
            padding: 12px;
            background: linear-gradient(160deg, rgba(255, 214, 10, 0.08) 0%, rgba(220, 180, 10, 0.1) 100%);
            border: 2px solid rgba(255, 214, 10, 0.15);
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            color: var(--text-tertiary);
            position: relative;
            margin-bottom: 10px;
            overflow: hidden;
            box-shadow: 
                0 0 6px rgba(255, 214, 10, 0.05),
                0 2px 8px rgba(0, 0, 0, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .pruned-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 40%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.04) 0%, transparent 100%);
            pointer-events: none
        }

        .pruned-content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid rgba(255, 214, 10, 0.15);
            position: relative;
            z-index: 1
        }

        .pruned-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--warning);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .edit-pruned-btn {
            padding: 4px 10px;
            background: var(--liquid-glass);
            border: 2px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--radius-sm);
            color: var(--text-tertiary);
            cursor: pointer;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all var(--transition-smooth);
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 4px rgba(255, 255, 255, 0.02),
                0 2px 4px rgba(0, 0, 0, 0.12),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .edit-pruned-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%);
            pointer-events: none
        }

        .edit-pruned-btn:hover {
            background: var(--liquid-glass-hover);
            border-color: rgba(255, 255, 255, 0.15);
            color: var(--text-primary);
            box-shadow: 
                0 0 6px rgba(255, 255, 255, 0.03),
                0 3px 8px rgba(0, 0, 0, 0.18),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
        }

        .pruned-stats {
            display: flex;
            gap: 12px;
            font-size: 0.7rem;
            color: var(--text-quaternary);
            margin-top: 8px;
            position: relative;
            z-index: 1
        }

        .pruned-stats span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pruned-stats .saved {
            color: var(--success);
        }

        .message.pruned-message {
            border-left: 3px solid var(--success);
            background: linear-gradient(90deg, rgba(48, 209, 88, 0.08) 0%, rgba(0, 0, 0, 0.25) 30px);
        }

        .message.indexed-message {
            border-left: 3px solid var(--accent-secondary);
            background: linear-gradient(90deg, rgba(75, 190, 232, 0.08) 0%, rgba(0, 0, 0, 0.25) 30px);
            opacity: 0.7;
        }

        .message.indexed-message .indexed-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.65rem;
            color: var(--accent-secondary);
            background: linear-gradient(160deg, rgba(75, 190, 232, 0.15) 0%, rgba(50, 170, 210, 0.2) 100%);
            border: 1px solid rgba(75, 190, 232, 0.25);
            padding: 3px 10px;
            border-radius: var(--radius-sm);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: 
                0 0 6px rgba(75, 190, 232, 0.1),
                inset 0 1px 0 rgba(255, 255, 255, 0.08)
            font-weight: 600;
        }

        .message.recalled-message {
            border-left: 3px solid var(--accent-primary);
            background: linear-gradient(90deg, rgba(91, 196, 232, 0.05) 0%, transparent 10px);
        }

        .message.recalled-message .recalled-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.65rem;
            color: var(--accent-primary);
            background: rgba(91, 196, 232, 0.1);
            padding: 2px 8px;
            border-radius: var(--radius-sm);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .velocity-stats {
            display: flex;
            gap: 16px;
            padding: 12px;
            background: var(--glass-bg);
            border-radius: var(--radius-sm);
            border: 1px solid var(--glass-border);
            margin-top: 12px;
        }

        .velocity-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }

        .velocity-stat-label {
            font-size: 0.65rem;
            color: var(--text-quaternary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .velocity-stat-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--accent-secondary);
        }

        .settings-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .server-status-row {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .header-server-status {
            display: flex;
            gap: 0.5rem;
            margin-left: 1rem;
        }

        .server-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 0.875rem;
            background: var(--liquid-glass);
            border: 2px solid rgba(255, 255, 255, 0.08);
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            color: var(--text-tertiary);
            position: relative;
            overflow: hidden;
            box-shadow: 
                0 0 5px rgba(255, 255, 255, 0.02),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .server-status::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, transparent 100%);
            pointer-events: none
        }

        .server-status.compact {
            padding: 0.375rem 0.625rem;
            font-size: 0.7rem;
            border-radius: var(--radius-sm)
        }

        .server-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text-quaternary);
            flex-shrink: 0;
            position: relative;
            z-index: 1
        }

        .server-status.online .server-status-dot {
            background: var(--success);
            box-shadow: 0 0 8px var(--success)
        }

        .server-status.online {
            color: var(--success);
            border-color: rgba(48, 209, 88, 0.3);
            box-shadow: 
                0 0 8px rgba(48, 209, 88, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .server-status.offline .server-status-dot {
            background: var(--text-quaternary)
        }

        .server-status.starting .server-status-dot {
            background: var(--warning);
            animation: pulse-glow 1.5s infinite
        }

        .server-status.starting {
            border-color: rgba(255, 214, 10, 0.25);
            box-shadow: 
                0 0 8px rgba(255, 214, 10, 0.08),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .server-status.error .server-status-dot {
            background: var(--error);
            box-shadow: 0 0 8px var(--error)
        }

        .server-status.error {
            border-color: rgba(255, 69, 58, 0.25);
            box-shadow: 
                0 0 8px rgba(255, 69, 58, 0.1),
                0 2px 6px rgba(0, 0, 0, 0.15),
                inset 0 1px 0 rgba(255, 255, 255, 0.05)
        }

        .settings-subtitle {
            display: flex;
            align-items: center;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
            margin-top: 0.5rem;
        }

        .settings-subtitle .pruning-status {
            margin-left: auto;
        }

        /* Context meter styling */
        .context-meter {
            height: 6px;
            border-radius: var(--radius-pill);
            background: linear-gradient(160deg, rgba(50, 50, 60, 0.5) 0%, rgba(35, 35, 45, 0.6) 100%);
            border: 1px solid rgba(255, 255, 255, 0.06);
            overflow: hidden;
            margin-top: 4px;
            box-shadow: 
                inset 0 1px 2px rgba(0, 0, 0, 0.2),
                0 0 4px rgba(255, 255, 255, 0.02)
        }

        .context-meter-fill {
            height: 100%;
            transition: width var(--transition-smooth);
            border-radius: var(--radius-pill);
            position: relative
        }

        .context-meter-fill::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 50%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.3) 0%, transparent 100%);
            border-radius: var(--radius-pill) var(--radius-pill) 0 0
        }

        .context-meter-fill.safe {
            background: linear-gradient(90deg, rgba(91, 196, 232, 0.8) 0%, rgba(91, 196, 232, 1) 100%);
            box-shadow: 0 0 8px rgba(91, 196, 232, 0.4)
        }

        .context-meter-fill.warning {
            background: linear-gradient(90deg, rgba(255, 214, 10, 0.8) 0%, rgba(255, 214, 10, 1) 100%);
            box-shadow: 0 0 8px rgba(255, 214, 10, 0.4)
        }

        .context-meter-fill.critical {
            background: linear-gradient(90deg, rgba(255, 69, 58, 0.8) 0%, rgba(255, 69, 58, 1) 100%);
            box-shadow: 0 0 8px rgba(255, 69, 58, 0.4)
        }
    </style>
</head>
    <body>
        <div class="aurora-strands">
            <div class="aurora-strand strand-1"></div>
            <div class="aurora-strand strand-2"></div>
            <div class="aurora-strand strand-3"></div>
        </div>

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
                    
                    <!-- Expert Models Section -->
                    <div class="settings-subtitle">Expert Models (GPU)</div>
                    
                    <div class="model-select-wrapper">
                        <div class="model-select-label">
                            <div class="settings-checkbox" style="margin:0">
                                <input type="checkbox" id="governorTextEnabled" checked>
                                <label for="governorTextEnabled">Text Expert</label>
                            </div>
                            <span class="model-size" id="textModelSize"></span>
                        </div>
                        <select class="model-select" id="governorTextModel"><option value="">Select a model...</option></select>
                        <div class="settings-hint">General conversation and text tasks</div>
                    </div>
                    
                    <div class="model-select-wrapper">
                        <div class="model-select-label">
                            <div class="settings-checkbox" style="margin:0">
                                <input type="checkbox" id="governorCodeEnabled" checked>
                                <label for="governorCodeEnabled">Code Expert</label>
                            </div>
                            <span class="model-size" id="codeModelSize"></span>
                        </div>
                        <select class="model-select" id="governorCodeModel"><option value="">Select a model...</option></select>
                        <div class="settings-hint">Programming and code-related tasks</div>
                    </div>
                    
                    <div class="model-select-wrapper">
                        <div class="model-select-label">
                            <div class="settings-checkbox" style="margin:0">
                                <input type="checkbox" id="governorMedicalEnabled" checked>
                                <label for="governorMedicalEnabled">Medical Expert</label>
                            </div>
                            <span class="model-size" id="medicalModelSize"></span>
                        </div>
                        <select class="model-select" id="governorMedicalModel"><option value="">Select a model...</option></select>
                        <div class="settings-hint">Medical and health-related questions</div>
                    </div>
                    
                    <!-- Control Plane Section -->
                    <div class="settings-separator"></div>
                    <div class="settings-subtitle">Control Plane</div>
                    
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
                    
                    <!-- Context Pruning Section -->
                    <div class="settings-separator"></div>
                    <div class="settings-subtitle">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;margin-right:6px"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                        Context Pruning
                        <span class="pruning-status" id="pruningStatusIndicator">Active</span>
                    </div>
                    <div class="settings-hint" style="margin-bottom:1rem">Automatically condenses older messages to preserve context space while retaining key information.</div>
                    
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
                        <textarea class="settings-textarea" id="prunePrompt" rows="2">Condense this message to only the essential information in 2-3 sentences:</textarea>
                    </div>
                    
                    <!-- Velocity Index Section -->
                    <div class="settings-separator"></div>
                    <div class="settings-subtitle">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:16px;height:16px;margin-right:6px"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                        Velocity Index
                    </div>
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
                </div>
            </div>
            <div class="settings-actions">
                <button class="settings-btn save" id="saveSettingsBtn"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>Save Settings</button>
                <button class="settings-btn cancel" id="closeSettingsBtn"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>Close</button>
            </div>
        </div>
        
        <div class="startup-overlay" id="startupOverlay">
            <div class="startup-content">
                <div class="startup-spinner"></div>
                <div class="startup-title" id="startupTitle">Starting Server...</div>
                <div class="startup-message" id="startupMessage">Initializing the auxiliary model</div>
                <div class="startup-error" id="startupError" style="display:none"></div>
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
                    <span class="sidebar-version">v0.9 Pre-Release © 2026 Technologyst Labs</span>
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
                            <div class="welcome-subtitle">by Technologyst Labs</div>
                            <div class="suggestions">
                                <div class="suggestions-title">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="12" height="12">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>
                                    </svg>
                                    Suggested Prompts
                                </div>
                                <div class="suggestions-grid" id="suggestionsGrid">
                                    <button class="suggestion-btn waterfall" style="animation-delay:0ms" data-prompt="Explain quantum computing in simple terms">
                                        <div class="suggestion-title">Explain quantum computing</div>
                                        <div class="suggestion-desc">in simple terms</div>
                                    </button>
                                    <button class="suggestion-btn waterfall" style="animation-delay:60ms" data-prompt="Write a Python function to calculate fibonacci numbers">
                                        <div class="suggestion-title">Write a Python function</div>
                                        <div class="suggestion-desc">to calculate fibonacci numbers</div>
                                    </button>
                                    <button class="suggestion-btn waterfall" style="animation-delay:120ms" data-prompt="What are the benefits of meditation?">
                                        <div class="suggestion-title">Benefits of meditation</div>
                                        <div class="suggestion-desc">for mental health</div>
                                    </button>
                                    <button class="suggestion-btn waterfall" style="animation-delay:180ms" data-prompt="Create a recipe for chocolate chip cookies">
                                        <div class="suggestion-title">Chocolate chip cookies</div>
                                        <div class="suggestion-desc">recipe</div>
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

        <script>
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
                        textModel: '',
                        codeModel: '',
                        medicalModel: '',
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
                    
                    // Velocity Index state
                    this.velocityIndex = [];
                    this.velocityRecallCount = 0;
                    this.recalledMessage = null;
                    
                    // Aux server request queue (llama.cpp can only handle one request at a time)
                    this.auxQueue = [];
                    this.auxBusy = false;
                    
                    this.BACKEND_URL = window.location.href;
                    this.SERVER_URL = 'http://localhost:8080';
                    
                    this.initElements();
                    this.init();
                }
                
                // ==================== PIPELINE ENGINE ====================
                
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
                
                // ==================== PIPELINE STAGES ====================
                
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
                    if (!this.pruningConfig.enabled || !this.governorConfig.auxModel) return;

                    const msg = this.messages[ctx.userIndex];
                    const text = msg.fullContent || msg.content;

                    if (text.length < this.pruningConfig.threshold) return;

                    this.updateStatus('Pruning message...', 'pruning');
                    const pruned = await this.pruneMessage(text);

                    if (pruned) {
                        msg.prunedContent = pruned;
                        const [o, p] = await Promise.all([
                            this.countTokens(text),
                            this.countTokens(pruned)
                        ]);
                        if (o && p) this.tokenTracker.savedTokens += Math.max(0, o - p);
                        this.rerenderMessages();
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

                        for (const line of decoder.decode(value).split('\n')) {
                            if (!line.startsWith('data: ')) continue;
                            const data = line.slice(6);
                            if (data === '[DONE]') {
                                this.isStreaming = false;
                                continue;
                            }
                            const token = JSON.parse(data)?.choices?.[0]?.delta?.content;
                            if (token) {
                                content += token;
                                contentDiv.innerHTML =
                                    this.formatContent(content) + '<span class="typing-cursor"></span>';
                                this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
                            }
                        }
                    }

                    contentDiv.innerHTML = this.formatContent(content);
                    this.messages.push({ role: 'assistant', content, timestamp: new Date().toISOString() });
                    this.removeStopButton(assistantDiv);
                    this.addMessageActions(assistantDiv, this.messages.length - 1);
                    assistantDiv.id = '';
                }
                
                async stageFinalize(ctx) {
                    const msg = this.messages.at(-1);
                    if (this.pruningConfig.enabled && this.governorConfig.auxModel &&
                        msg.content.length >= this.pruningConfig.threshold) {

                        this.updateStatus('Pruning response...', 'pruning');
                        const pruned = await this.pruneMessage(msg.content);
                        if (pruned) {
                            msg.prunedContent = pruned;
                            this.rerenderMessages();
                        }
                        this.updateStatus('');
                    }

                    await this.saveChatToHistory();
                    await this.updateTokenUsage();

                    this.isLoading = false;
                    this.isStreaming = false;
                    this.updateSendButton();
                    this.updateStatus('');
                }
                
                // ==================== REFACTORED sendMessage ====================
                
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
                
                // ==================== HELPER METHODS ====================
                
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
                    this.pruningStatusIndicator = this.$('pruningStatusIndicator');
                    this.confirmDialog = this.$('confirmDialog');
                    this.confirmMessage = this.$('confirmMessage');
                    this.contextOverflowDialog = this.$('contextOverflowDialog');
                    this.contextOverflowMessage = this.$('contextOverflowMessage');
                    this.fileInput = this.$('fileInput');
                    this.attachBtn = this.$('attachBtn');
                    this.attachmentsPreview = this.$('attachmentsPreview');
                    
                    // Governor elements
                    this.governorTextModel = this.$('governorTextModel');
                    this.governorCodeModel = this.$('governorCodeModel');
                    this.governorMedicalModel = this.$('governorMedicalModel');
                    this.governorAuxModel = this.$('governorAuxModel');
                    this.governorAuxCpuOnly = this.$('governorAuxCpuOnly');
                    this.governorTextEnabled = this.$('governorTextEnabled');
                    this.governorCodeEnabled = this.$('governorCodeEnabled');
                    this.governorMedicalEnabled = this.$('governorMedicalEnabled');
                    this.auxContextLength = this.$('auxContextLength');
                    this.vramValue = this.$('vramValue');
                    this.vramGpu = this.$('vramGpu');
                    this.textModelSize = this.$('textModelSize');
                    this.codeModelSize = this.$('codeModelSize');
                    this.medicalModelSize = this.$('medicalModelSize');
                    this.auxModelSize = this.$('auxModelSize');
                    this.expertServerStatus = this.$('expertServerStatus');
                    this.auxServerStatus = this.$('auxServerStatus');
                    
                    // Startup overlay
                    this.startupOverlay = this.$('startupOverlay');
                    this.startupTitle = this.$('startupTitle');
                    this.startupMessage = this.$('startupMessage');
                    this.startupError = this.$('startupError');
                    
                    // Velocity Index elements
                    this.velocityEnabled = this.$('velocityEnabled');
                    this.velocityThreshold = this.$('velocityThreshold');
                    this.velocityCharThreshold = this.$('velocityCharThreshold');
                    this.velocityIndexPrompt = this.$('velocityIndexPrompt');
                    this.velocityRecallPrompt = this.$('velocityRecallPrompt');
                    this.velocityStats = this.$('velocityStats');
                    this.velocityIndexedCount = this.$('velocityIndexedCount');
                    this.velocityRecallCount = this.$('velocityRecallCount');
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
                            this.governorConfig = {
                                textModel: configData.config.text_model || '',
                                codeModel: configData.config.code_model || '',
                                medicalModel: configData.config.medical_model || '',
                                auxModel: configData.config.aux_model || '',
                                auxContextLength: configData.config.aux_context_length || 2048,
                                textEnabled: configData.config.text_enabled !== false,
                                codeEnabled: configData.config.code_enabled !== false,
                                medicalEnabled: configData.config.medical_enabled !== false,
                                auxCpuOnly: configData.config.aux_cpu_only !== false,
                                auxPort: configData.config.aux_port || 8081,
                                expertPort: configData.config.expert_port || 8080,
                                velocityEnabled: configData.config.velocity_enabled !== false,
                                velocityThreshold: configData.config.velocity_threshold || 40,
                                velocityCharThreshold: configData.config.velocity_char_threshold || 1500,
                                velocityIndexPrompt: configData.config.velocity_index_prompt || this.governorConfig.velocityIndexPrompt,
                                velocityRecallPrompt: configData.config.velocity_recall_prompt || this.governorConfig.velocityRecallPrompt
                            };
                            
                            // Also load pruning settings from unified config
                            this.pruningConfig.enabled = configData.config.enable_pruning !== false;
                            this.pruningConfig.threshold = configData.config.prune_threshold || 1500;
                            this.pruningConfig.prunePrompt = configData.config.prune_prompt || this.pruningConfig.prunePrompt;
                            
                            // Initialize governor (start aux server if configured)
                            await this.initializeGovernor();
                        }
                    } catch (e) {}
                }
                
                setupEventListeners() {
                    // Form submission
                    this.chatForm.addEventListener('submit', e => {
                        e.preventDefault();
                        this.sendMessage();
                    });
                    
                    // Input handling with debounced token counting
                    this.chatInput.addEventListener('input', () => {
                        this.adjustTextareaHeight();
                        this.updateSendButton();
                        this.scheduleTokenCount();
                    });
                    
                    // Enter to send (shift+enter for newline)
                    this.chatInput.addEventListener('keydown', e => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            if (this.chatInput.value.trim() && !this.isLoading) {
                                this.sendMessage();
                            }
                        }
                    });
                    
                    // Suggestions
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
                    
                    // File attachments
                    this.attachBtn.addEventListener('click', () => this.fileInput.click());
                    this.fileInput.addEventListener('change', e => this.handleFileSelect(e));
                    this.attachmentsPreview.addEventListener('click', e => {
                        const removeBtn = e.target.closest('.remove-attachment');
                        if (removeBtn) {
                            const index = parseInt(removeBtn.dataset.index);
                            this.removeAttachment(index);
                        }
                    });
                    
                    // Chat management
                    this.$('newChatBtn').addEventListener('click', () => this.createNewChat());
                    this.searchInput.addEventListener('input', e => this.filterChats(e.target.value));
                    
                    // Sidebar toggles
                    ['sidebarToggle', 'headerSidebarToggle', 'mobileSidebarToggle'].forEach(id => {
                        this.$(id)?.addEventListener('click', () => this.toggleSidebar());
                    });
                    
                    // Delete confirmation dialog
                    this.$('confirmCancel').addEventListener('click', () => this.hideDialog(this.confirmDialog));
                    this.$('confirmDelete').addEventListener('click', () => this.deleteChatConfirmed());
                    this.confirmDialog.addEventListener('click', e => {
                        if (e.target === this.confirmDialog) this.hideDialog(this.confirmDialog);
                    });
                    
                    // Context overflow dialog
                    this.$('contextOverflowOk').addEventListener('click', () => this.hideDialog(this.contextOverflowDialog));
                    this.contextOverflowDialog.addEventListener('click', e => {
                        if (e.target === this.contextOverflowDialog) this.hideDialog(this.contextOverflowDialog);
                    });
                    
                    // Settings panel
                    this.$('settingsBtn').addEventListener('click', () => this.openSettings());
                    this.$('settingsClose').addEventListener('click', () => this.closeSettings());
                    this.$('closeSettingsBtn').addEventListener('click', () => this.closeSettings());
                    this.$('saveSettingsBtn').addEventListener('click', () => this.saveSettings());
                    this.enablePruning.addEventListener('change', () => this.updatePruningUI());
                    
                    // Governor model selectors
                    this.governorTextModel.addEventListener('change', () => this.updateModelSizeDisplay());
                    this.governorCodeModel.addEventListener('change', () => this.updateModelSizeDisplay());
                    this.governorAuxModel.addEventListener('change', () => this.updateModelSizeDisplay());
                    
                    // Global keyboard shortcuts
                    document.addEventListener('keydown', e => {
                        if (e.key === 'Escape') {
                            this.hideDialog(this.confirmDialog);
                            this.hideDialog(this.contextOverflowDialog);
                        }
                    });
                    
                    // Responsive handling
                    window.addEventListener('resize', () => {
                        if (window.innerWidth >= 1024) {
                            this.sidebar.classList.remove('active');
                            this.app.classList.remove('sidebar-collapsed');
                            this.sidebarCollapsed = false;
                        }
                    });
                    
                    // Poll server status every 5 seconds for header indicators
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
                        // Double-check text hasn't been cleared
                        const currentText = this.chatInput.value.trim();
                        const currentAttachments = this.getAttachmentsText();
                        if (!currentText && !currentAttachments) {
                            this.clearInputTokenDisplay();
                            return;
                        }
                        const fullText = currentAttachments ? `${currentAttachments}\n\n${currentText}` : currentText;
                        const tokens = await this.countTokens(fullText);
                        // Triple-check before displaying (user may have sent message)
                        // Also hide if server unavailable (tokens is null)
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
                    const data = await this.auxApiCall('prune_message', {
                        message,
                        prompt: this.pruningConfig.prunePrompt
                    });
                    return data.success ? data.pruned : null;
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
                        total += tokens + 4; // overhead per message
                    }
                    
                    this.tokenTracker.available = true;
                    this.tokenTracker.currentTokens = total + 50; // system overhead
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
                        'searching', 'switching', 'ready', 'stopped', 'offline', 'warning'
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
                        const isExpanded = false; // Default collapsed
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
                    
                    const userMessages = document.querySelectorAll('.message.user-message');
                    const msgEl = userMessages[index];
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
                                this.messages = this.messages.slice(0, index + 1);
                                this.rerenderMessages();
                                this.regenerateFromUser(newContent);
                            } else {
                                this.rerenderMessages();
                            }
                            await this.saveChatToHistory();
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
                
                regenerateMessage(index) {
                    if (this.isStreaming) this.stopStreaming();
                    this.hideLoading();
                    
                    // Find the user message before this assistant message
                    let userIndex = -1;
                    for (let i = index; i >= 0; i--) {
                        if (this.messages[i].role === 'user') {
                            userIndex = i;
                            break;
                        }
                    }
                    if (userIndex === -1) return;
                    
                    const content = this.messages[userIndex].content;
                    this.messages = this.messages.slice(0, userIndex + 1);
                    this.rerenderMessages();
                    this.chatInput.value = '';
                    this.adjustTextareaHeight();
                    this.regenerateFromUser(content);
                }
                
                async regenerateFromUser(content) {
                    if (!this.serverAvailable) return;
                    
                    this.showLoading();
                    this.isLoading = true;
                    this.isStreaming = true;
                    this.updateSendButton();
                    
                    try {
                        const messagesForApi = await this.prepareMessagesForApi();
                        this.updateStatus('Generating Response', 'generating');
                        
                        const maxResponseTokens = Math.floor(this.tokenTracker.maxContextLength * 0.4);
                        const response = await fetch(`${this.SERVER_URL}/v1/chat/completions`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                messages: messagesForApi,
                                stream: true,
                                temperature: 0.7,
                                max_tokens: maxResponseTokens
                            })
                        });
                        
                        if (!response.ok) throw new Error(`Server error: ${response.status}`);
                        
                        const assistantDiv = this.createStreamingMessage();
                        const contentDiv = assistantDiv.querySelector('.message-content');
                        this.hideLoading();
                        
                        this.streamReader = response.body.getReader();
                        const decoder = new TextDecoder();
                        let accumulated = '';
                        this.addStopButton(assistantDiv);
                        
                        while (this.isStreaming) {
                            const { done, value } = await this.streamReader.read();
                            if (done) break;
                            
                            for (const line of decoder.decode(value).split('\n')) {
                                if (!line.startsWith('data: ')) continue;
                                const data = line.slice(6);
                                if (data === '[DONE]') {
                                    this.isStreaming = false;
                                    continue;
                                }
                                try {
                                    const token = JSON.parse(data).choices[0]?.delta?.content || '';
                                    if (token) {
                                        accumulated += token;
                                        contentDiv.innerHTML = this.formatContent(accumulated) + '<span class="typing-cursor"></span>';
                                        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
                                    }
                                } catch {}
                            }
                        }
                        
                        contentDiv.innerHTML = this.formatContent(accumulated);
                        this.messages.push({ role: 'assistant', content: accumulated, timestamp: new Date().toISOString() });
                        this.removeStopButton(assistantDiv);
                        this.addMessageActions(assistantDiv, this.messages.length - 1);
                        assistantDiv.id = '';
                        
                        // AUTOMATIC PRUNING: Prune the assistant response if it exceeds character threshold
                        if (this.pruningConfig.enabled && this.governorConfig.auxModel) {
                            const lastMessage = this.messages[this.messages.length - 1];
                            const contentLength = lastMessage.content.length;
                            
                            if (contentLength >= this.pruningConfig.threshold) {
                                this.updateStatus('Pruning response...', 'pruning');
                                const pruned = await this.pruneMessage(lastMessage.content);
                                
                                if (pruned) {
                                    const origTokens = await this.countTokens(lastMessage.content);
                                    const sumTokens = await this.countTokens(pruned);
                                    
                                    lastMessage.prunedContent = pruned;
                                    this.tokenTracker.prunedMessages.add(lastMessage.timestamp);
                                    if (origTokens !== null && sumTokens !== null) {
                                        this.tokenTracker.savedTokens += Math.max(0, origTokens - sumTokens);
                                    }
                                    
                                    this.rerenderMessages();
                                }
                                this.updateStatus('');
                            }
                        }
                        
                        await this.saveChatToHistory();
                        await this.updateTokenUsage();
                    } catch (error) {
                        document.getElementById('streamingMessage')?.remove();
                        if (error.message.includes('fetch') || error.message.includes('network') || error.name === 'TypeError') {
                            this.setServerUnavailable(error.message);
                            this.addMessage('assistant', `Connection error: Could not reach the server at ${this.SERVER_URL}`);
                        } else {
                            this.addMessage('assistant', `Error: ${error.message}`);
                        }
                        await this.updateTokenUsage();
                    } finally {
                        this.updateStatus('');
                        this.isLoading = false;
                        this.isStreaming = false;
                        this.updateSendButton();
                    }
                }
                
                rerenderMessages() {
                    this.messagesContainer.querySelectorAll('.message').forEach(el => el.remove());
                    this.messages.forEach((msg, i) => {
                        const el = this.createMessageElement(msg.role, msg.content, i, msg.attachments);
                        this.messagesContainer.appendChild(el);
                    });
                    this.welcomeScreen.classList.toggle('hidden', this.messages.length > 0);
                    this.updateTokenUsage();
                }
                
                formatContent(content) {
                    return content
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/```([\s\S]*?)```/g, (_, code) => `<pre><code>${code.trim()}</code></pre>`)
                        .replace(/`([^`]+)`/g, '<code>$1</code>')
                        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                        .replace(/\*(.*?)\*/g, '<em>$1</em>')
                        .replace(/\n/g, '<br>');
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
                        });
                        
                        this.welcomeScreen.classList.toggle('hidden', this.messages.length > 0);
                        this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
                        
                        if (window.innerWidth < 1024) this.sidebar.classList.remove('active');
                        
                        this.markActiveChat();
                        this.updateUI();
                        await this.updateTokenUsage();
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
                        this.governorConfig = {
                            textModel: configData.config.text_model || '',
                            codeModel: configData.config.code_model || '',
                            medicalModel: configData.config.medical_model || '',
                            auxModel: configData.config.aux_model || '',
                            auxContextLength: configData.config.aux_context_length || 2048,
                            textEnabled: configData.config.text_enabled !== false,
                            codeEnabled: configData.config.code_enabled !== false,
                            medicalEnabled: configData.config.medical_enabled !== false,
                            auxCpuOnly: configData.config.aux_cpu_only !== false,
                            auxPort: configData.config.aux_port || 8081,
                            expertPort: configData.config.expert_port || 8080,
                            velocityEnabled: configData.config.velocity_enabled !== false,
                            velocityThreshold: configData.config.velocity_threshold || 40,
                            velocityCharThreshold: configData.config.velocity_char_threshold || 1500,
                            velocityIndexPrompt: configData.config.velocity_index_prompt || this.governorConfig.velocityIndexPrompt,
                            velocityRecallPrompt: configData.config.velocity_recall_prompt || this.governorConfig.velocityRecallPrompt
                        };
                        
                        this.pruningConfig.enabled = configData.config.enable_pruning !== false;
                        this.pruningConfig.threshold = configData.config.prune_threshold || 1500;
                        this.pruningConfig.prunePrompt = configData.config.prune_prompt || this.pruningConfig.prunePrompt;
                        
                        this.updateGovernorUI();
                        this.updateSettingsUI();
                    }
                    
                    await this.checkServerStatus();
                }
                
                populateModelDropdowns() {
                    const dropdowns = [
                        this.governorTextModel,
                        this.governorCodeModel,
                        this.governorMedicalModel,
                        this.governorAuxModel
                    ];
                    
                    dropdowns.forEach(dropdown => {
                        const currentValue = dropdown.value;
                        dropdown.innerHTML = '<option value="">Select a model...</option>';
                        
                        this.availableModels.forEach(model => {
                            const option = document.createElement('option');
                            option.value = model.filename;
                            option.textContent = model.filename;
                            option.dataset.size = model.sizeFormatted;
                            dropdown.appendChild(option);
                        });
                        
                        // Restore selection if it exists
                        if (currentValue) {
                            dropdown.value = currentValue;
                        }
                    });
                }
                
                updateGovernorUI() {
                    this.governorTextModel.value = this.governorConfig.textModel;
                    this.governorCodeModel.value = this.governorConfig.codeModel;
                    this.governorMedicalModel.value = this.governorConfig.medicalModel || '';
                    this.governorAuxModel.value = this.governorConfig.auxModel;
                    this.governorAuxCpuOnly.checked = this.governorConfig.auxCpuOnly;
                    this.governorTextEnabled.checked = this.governorConfig.textEnabled !== false;
                    this.governorCodeEnabled.checked = this.governorConfig.codeEnabled !== false;
                    this.governorMedicalEnabled.checked = this.governorConfig.medicalEnabled !== false;
                    this.auxContextLength.value = this.governorConfig.auxContextLength || 2048;
                    
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
                    
                    this.textModelSize.textContent = getSize(this.governorTextModel.value);
                    this.codeModelSize.textContent = getSize(this.governorCodeModel.value);
                    this.medicalModelSize.textContent = getSize(this.governorMedicalModel.value);
                    this.auxModelSize.textContent = getSize(this.governorAuxModel.value);
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
                    // expertType is 'TEXT', 'CODE', or 'MEDICAL'
                    let model;
                    switch(expertType) {
                        case 'CODE':
                            model = this.governorConfig.codeModel;
                            break;
                        case 'MEDICAL':
                            model = this.governorConfig.medicalModel;
                            break;
                        default:
                            model = this.governorConfig.textModel;
                    }
                    
                    if (!model) {
                        throw new Error(`No ${expertType.toLowerCase()} model configured`);
                    }
                    
                    const result = await this.apiCall('start_server', {
                        type: 'expert',
                        model: model,
                        port: this.governorConfig.expertPort,
                        cpu_only: false,
                        context_size: 4096
                    });
                    
                    if (result.success) {
                        this.currentExpert = expertType;
                        return result;
                    } else {
                        throw new Error(result.error || `Failed to start ${expertType} expert server`);
                    }
                }
                
                async switchExpert(newExpertType) {
                    if (this.currentExpert === newExpertType) return true;
                    
                    console.log(`[Governor] Switching expert: ${this.currentExpert} → ${newExpertType}`);
                    this.updateStatus('Switching models...', 'switching');
                    
                    await this.apiCall('stop_server', { type: 'expert' });
                    await new Promise(r => setTimeout(r, 1500));
                    
                    await this.startExpertServer(newExpertType);
                    
                    for (let i = 0; i < 60; i++) {
                        const status = await this.apiCall('server_status');
                        const expertStatus = status.servers?.find(s => s.type === 'expert');
                        if (expertStatus?.running && expertStatus?.healthy) {
                            this.updateServerStatusUI('expert', expertStatus);
                            return true;
                        }
                        await new Promise(r => setTimeout(r, 1000));
                    }
                    return false;
                }
                
                async routeQuery(message) {
                    const hasEnabledExperts = 
                        (this.governorConfig.textEnabled && this.governorConfig.textModel) ||
                        (this.governorConfig.codeEnabled && this.governorConfig.codeModel) ||
                        (this.governorConfig.medicalEnabled && this.governorConfig.medicalModel);
                    
                    if (!this.governorConfig.auxModel || !hasEnabledExperts) {
                        if (this.governorConfig.textEnabled && this.governorConfig.textModel) return 'TEXT';
                        if (this.governorConfig.codeEnabled && this.governorConfig.codeModel) return 'CODE';
                        if (this.governorConfig.medicalEnabled && this.governorConfig.medicalModel) return 'MEDICAL';
                        return 'TEXT';
                    }
                    
                    this.updateStatus('Finding expert...', 'routing');
                    
                    const result = await this.auxApiCall('route_query', { message });
                    if (result.success) {
                        console.log(`[Governor] Routing decision: ${result.route}`);
                        return result.route;
                    }
                    
                    if (this.governorConfig.textEnabled && this.governorConfig.textModel) return 'TEXT';
                    if (this.governorConfig.codeEnabled && this.governorConfig.codeModel) return 'CODE';
                    if (this.governorConfig.medicalEnabled && this.governorConfig.medicalModel) return 'MEDICAL';
                    return 'TEXT';
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
                
                async initializeGovernor() {
                    if (!this.governorConfig.auxModel) return;
                    
                    this.showStartupOverlay('Starting Servers...', 'Initializing the auxiliary model');
                    console.log('[Governor] Starting auxiliary server...');
                    
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
                            console.log('[Governor] Auxiliary server healthy');
                            this.updateServerStatusUI('aux', auxStatus);
                            auxHealthy = true;
                            break;
                        }
                        await new Promise(r => setTimeout(r, 1000));
                    }
                    
                    if (auxHealthy && this.governorConfig.textModel) {
                        this.showStartupOverlay('Starting Servers...', 'Initializing the text expert model');
                        console.log('[Governor] Starting expert server...');
                        
                        await this.startExpertServer('TEXT');
                        await new Promise(r => setTimeout(r, 4000));
                        
                        for (let i = 0; i < 60; i++) {
                            const result = await this.apiCall('server_status');
                            const expertStatus = result.servers?.find(s => s.type === 'expert');
                            if (expertStatus?.running && expertStatus?.healthy) {
                                console.log('[Governor] Expert server healthy');
                                this.updateServerStatusUI('expert', expertStatus);
                                break;
                            }
                            await new Promise(r => setTimeout(r, 1000));
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
                    
                    this.pruningStatusIndicator.textContent = enabled ? 'Active' : 'Inactive';
                    this.pruningStatusIndicator.classList.toggle('inactive', !enabled);
                }
                
                async saveSettings() {
                    // Gather all settings
                    const configData = {
                        text_model: this.governorTextModel.value,
                        code_model: this.governorCodeModel.value,
                        medical_model: this.governorMedicalModel.value,
                        aux_model: this.governorAuxModel.value,
                        text_enabled: this.governorTextEnabled.checked,
                        code_enabled: this.governorCodeEnabled.checked,
                        medical_enabled: this.governorMedicalEnabled.checked,
                        aux_cpu_only: this.governorAuxCpuOnly.checked,
                        aux_context_length: parseInt(this.auxContextLength.value) || 2048,
                        aux_port: this.governorConfig.auxPort,
                        expert_port: this.governorConfig.expertPort,
                        velocity_enabled: this.velocityEnabled?.checked ?? true,
                        velocity_threshold: Math.max(10, Math.min(90, parseInt(this.velocityThreshold?.value) || 40)),
                        velocity_char_threshold: Math.max(100, Math.min(10000, parseInt(this.velocityCharThreshold?.value) || 1500)),
                        velocity_index_prompt: this.velocityIndexPrompt?.value?.trim() || this.governorConfig.velocityIndexPrompt,
                        velocity_recall_prompt: this.velocityRecallPrompt?.value?.trim() || this.governorConfig.velocityRecallPrompt,
                        enable_pruning: this.enablePruning.checked,
                        prune_threshold: Math.max(100, Math.min(10000, parseInt(this.pruneThreshold.value) || 1500)),
                        prune_prompt: this.prunePrompt.value.trim() || this.pruningConfig.prunePrompt
                    };
                    
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
    </body>
</html>