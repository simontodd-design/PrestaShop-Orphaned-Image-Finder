<?php
/**
 * ============================================================================
 * PrestaShop Image Cleanup Tool v1.0.0
 * ============================================================================
 * 
 * A free, open-source utility for identifying and removing orphaned images
 * from PrestaShop installations to reclaim disk space.
 * 
 * @package     PrestaShop Image Cleanup
 * @version     1.0.0
 * @author      Simon Todd
 * @copyright   2024 Simon Todd
 * @license     MIT License
 * @link        https://github.com/simontodd
 * 
 * ============================================================================
 * DISCLAIMER
 * ============================================================================
 * This tool is provided "AS IS" without warranty of any kind, express or
 * implied. The author accepts no responsibility for any data loss, damage,
 * or other issues caused by using this tool. 
 * 
 * ALWAYS BACKUP YOUR FILES AND DATABASE BEFORE RUNNING ANY CLEANUP OPERATIONS.
 * 
 * By using this tool, you acknowledge that you understand the risks and
 * accept full responsibility for any consequences.
 * 
 * ============================================================================
 * USAGE
 * ============================================================================
 * 1. Upload this file to your PrestaShop root directory
 * 2. Access via browser: https://yoursite.com/ps_image_cleanup.php
 * 3. Login with your configured password (change TOOL_PASSWORD below!)
 * 4. Run scans and CAREFULLY review results before deleting anything
 * 5. DELETE THIS FILE when finished (security best practice)
 * 
 * ============================================================================
 * SUPPORT & CONTACT
 * ============================================================================
 * For professional PrestaShop development, optimization, and support:
 * 
 * Website: https://simontodd.dev
 * Email:   hello@simontodd.dev
 * GitHub:  https://github.com/simontodd
 * 
 * ============================================================================
 */

session_start();
set_time_limit(300); // 5 minutes per request
ini_set('memory_limit', '512M');
ini_set('display_errors', 0);
error_reporting(0);

// Custom error handler for AJAX requests - output JSON instead of HTML
function jsonErrorHandler($errno, $errstr, $errfile, $errline) {
    if (isset($_POST['action'])) {
        // Clean any previous output
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'PHP Error: ' . $errstr,
            'file' => basename($errfile),
            'line' => $errline,
            'recoverable' => true
        ]);
        exit;
    }
    return false;
}

function jsonExceptionHandler($e) {
    if (isset($_POST['action'])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Exception: ' . $e->getMessage(),
            'recoverable' => true
        ]);
        exit;
    }
}

function jsonShutdownHandler() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (isset($_POST['action'])) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Fatal: ' . $error['message'],
                'hint' => 'Try reducing chunk size or increasing memory limit',
                'recoverable' => false
            ]);
            exit;
        }
    }
}

set_error_handler('jsonErrorHandler');
set_exception_handler('jsonExceptionHandler');
register_shutdown_function('jsonShutdownHandler');

// ============================================================================
// CONFIGURATION - CHANGE PASSWORD BEFORE USE!
// ============================================================================
define('TOOL_PASSWORD', 'simontodd');  // <-- CHANGE THIS TO A SECURE PASSWORD!
define('SESSION_KEY', 'ps_cleanup_auth_st');
define('CHUNK_SIZE', 500);
define('TOOL_VERSION', '1.0.0');

// Logo as base64 (embedded)
define('LOGO_SVG', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 595.28 841.89"><defs><linearGradient id="lg1" x1="-225.39" y1="933.09" x2="717.42" y2="-9.72" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#df1b67"/><stop offset=".54" stop-color="#ee3e79"/><stop offset="1" stop-color="#ea507f"/></linearGradient></defs><path fill="url(#lg1)" fill-rule="evenodd" d="M-6.73,319.37c4.77-38.72,33.31-73.18,75.35-98.27,38.66-23.07,84.3-40.79,132.59-50.88,112.54-23.44,247.73-24.86,329.76-45.85,114.13-29.15,124.39-110.08,124.39-110.08C751.43,329.77,105.26,193.16,134.5,364.49c7.78,45.6,77.5,93.64,258.5,98.97h0c-107.55,9.93-276.01,28.36-354.93-41-29.33-25.76-49.88-61.84-44.8-103.08ZM556.74,398.14c-78.91-69.36-247.38-50.93-354.93-41h0c180.99,5.33,250.72,53.37,258.5,98.97,29.24,171.32-616.93,34.71-520.87,350.19,0,0,10.26-80.93,124.39-110.08,82.03-20.99,217.22-22.41,329.76-45.85,48.29-10.09,93.94-27.82,132.59-50.88,42.05-25.09,70.58-59.55,75.35-98.27,5.08-41.24-15.47-77.32-44.8-103.08Z"/></svg>');

// Authentication check
$authenticated = isset($_SESSION[SESSION_KEY]) && $_SESSION[SESSION_KEY] === true;
$disclaimerAccepted = isset($_SESSION['disclaimer_accepted']) && $_SESSION['disclaimer_accepted'] === true;

// Utility functions
function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes) / log(1024));
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function tableExists($pdo, $table) {
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($pdo, $table, $column) {
    try {
        $pdo->query("SELECT `$column` FROM `$table` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function getPrestaShopConfig() {
    $paramsFile = __DIR__ . '/app/config/parameters.php';
    $configFile = __DIR__ . '/config/settings.inc.php';
    
    if (file_exists($paramsFile)) {
        $parameters = require $paramsFile;
        if (isset($parameters['parameters'])) {
            $p = $parameters['parameters'];
            return [
                'server' => $p['database_host'],
                'name' => $p['database_name'],
                'user' => $p['database_user'],
                'pass' => $p['database_password'],
                'prefix' => $p['database_prefix'],
                'version' => '1.7+'
            ];
        }
    }
    
    if (file_exists($configFile)) {
        require_once($configFile);
        if (defined('_DB_SERVER_')) {
            return [
                'server' => _DB_SERVER_,
                'name' => _DB_NAME_,
                'user' => _DB_USER_,
                'pass' => _DB_PASSWD_,
                'prefix' => _DB_PREFIX_,
                'version' => '1.6'
            ];
        }
    }
    
    return null;
}

function getDbConnection($config) {
    try {
        return new PDO(
            'mysql:host=' . $config['server'] . ';dbname=' . $config['name'] . ';charset=utf8mb4',
            $config['user'],
            $config['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        return null;
    }
}

// API Request Handler
if (isset($_POST['action']) || isset($_GET['action'])) {
    // Start output buffering to catch any stray output
    ob_start();
    
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'];
    
    // Handle login/logout without auth
    if ($action === 'login') {
        if (($_POST['password'] ?? '') === TOOL_PASSWORD) {
            $_SESSION[SESSION_KEY] = true;
            $_SESSION['scan_data'] = null;
            $_SESSION['disclaimer_accepted'] = false;
            echo json_encode(['success' => true]);
        } else {
            sleep(1); // Slow down brute force
            echo json_encode(['success' => false, 'error' => 'Incorrect password']);
        }
        exit;
    }
    
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'acceptDisclaimer') {
        $_SESSION['disclaimer_accepted'] = true;
        echo json_encode(['success' => true]);
        exit;
    }
    
    // All other actions require authentication
    if (!$authenticated) {
        echo json_encode(['error' => 'Not authenticated', 'code' => 401]);
        exit;
    }
    
    // Load PrestaShop config
    $config = getPrestaShopConfig();
    $pdo = $config ? getDbConnection($config) : null;
    $prefix = $config['prefix'] ?? 'ps_';
    
    // Action handlers
    switch ($action) {
        
        // =========================================
        // SYSTEM INFORMATION
        // =========================================
        case 'getSystemInfo':
            $info = [
                'success' => true,
                'config' => $config !== null,
                'database' => $pdo !== null,
                'dbName' => $config['name'] ?? null,
                'prefix' => $prefix,
                'rootPath' => __DIR__,
                'phpVersion' => PHP_VERSION,
                'toolVersion' => TOOL_VERSION,
                'disclaimerAccepted' => $_SESSION['disclaimer_accepted'] ?? false,
            ];
            
            if ($pdo) {
                try {
                    $stmt = $pdo->query("SELECT value FROM {$prefix}configuration WHERE name = 'PS_VERSION_DB'");
                    $info['psVersion'] = $stmt->fetchColumn() ?: 'Unknown';
                } catch (Exception $e) {
                    $info['psVersion'] = 'Unknown';
                }
                
                try {
                    $stmt = $pdo->query("SELECT value FROM {$prefix}configuration WHERE name = 'PS_SHOP_NAME'");
                    $info['shopName'] = $stmt->fetchColumn() ?: 'PrestaShop Store';
                } catch (Exception $e) {
                    $info['shopName'] = 'PrestaShop Store';
                }
                
                try {
                    $info['productCount'] = $pdo->query("SELECT COUNT(*) FROM {$prefix}product")->fetchColumn();
                    $info['imageCount'] = $pdo->query("SELECT COUNT(*) FROM {$prefix}image")->fetchColumn();
                } catch (Exception $e) {
                    $info['productCount'] = 0;
                    $info['imageCount'] = 0;
                }
            }
            
            echo json_encode($info);
            break;
            
        // =========================================
        // MODULE DETECTION
        // =========================================
        case 'detectModules':
            $modules = [];
            $modulesDir = __DIR__ . '/modules';
            
            if (is_dir($modulesDir)) {
                $installedModules = glob($modulesDir . '/*/');
                
                foreach ($installedModules as $moduleDir) {
                    $moduleName = basename($moduleDir);
                    
                    // Skip hidden/system folders
                    if (strpos($moduleName, '.') === 0) continue;
                    
                    // Check for module main file to confirm it's a valid module
                    $mainFile = $moduleDir . $moduleName . '.php';
                    $isValid = file_exists($mainFile);
                    
                    // Check if module has database tables
                    $tableCount = 0;
                    $hasImageColumns = false;
                    
                    try {
                        // Look for tables with this module's prefix
                        $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}{$moduleName}%'");
                        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        $tableCount = count($tables);
                        
                        // Check if any tables have potential image columns
                        foreach ($tables as $table) {
                            $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($cols as $col) {
                                $colName = strtolower($col['Field']);
                                $colType = strtolower($col['Type']);
                                if (preg_match('/(image|img|icon|photo|picture|thumb|banner|logo|background|media|file|content|html|description)/i', $colName) ||
                                    preg_match('/(text|blob|longtext|mediumtext)/i', $colType)) {
                                    $hasImageColumns = true;
                                    break 2;
                                }
                            }
                        }
                    } catch (Exception $e) {
                        // Silently continue
                    }
                    
                    // Check for views/templates folder (likely has image references)
                    $hasTemplates = is_dir($moduleDir . 'views');
                    
                    // Determine icon based on module type
                    $icon = '🧩';
                    $lowerName = strtolower($moduleName);
                    if (strpos($lowerName, 'slider') !== false || strpos($lowerName, 'carousel') !== false) $icon = '🎠';
                    else if (strpos($lowerName, 'blog') !== false || strpos($lowerName, 'news') !== false) $icon = '📝';
                    else if (strpos($lowerName, 'menu') !== false) $icon = '📋';
                    else if (strpos($lowerName, 'banner') !== false || strpos($lowerName, 'html') !== false) $icon = '🏷️';
                    else if (strpos($lowerName, 'gallery') !== false || strpos($lowerName, 'image') !== false) $icon = '🖼️';
                    else if (strpos($lowerName, 'creator') !== false || strpos($lowerName, 'builder') !== false || strpos($lowerName, 'elementor') !== false) $icon = '🎨';
                    else if (strpos($lowerName, 'social') !== false || strpos($lowerName, 'instagram') !== false || strpos($lowerName, 'facebook') !== false) $icon = '📱';
                    else if (strpos($lowerName, 'testimonial') !== false || strpos($lowerName, 'review') !== false) $icon = '💬';
                    
                    $modules[] = [
                        'name' => $moduleName,
                        'icon' => $icon,
                        'installed' => $isValid,
                        'tables' => $tableCount,
                        'hasImageColumns' => $hasImageColumns,
                        'hasTemplates' => $hasTemplates,
                        'scannable' => ($tableCount > 0 && $hasImageColumns) || $hasTemplates,
                    ];
                }
                
                // Sort: scannable modules first, then alphabetically
                usort($modules, function($a, $b) {
                    if ($a['scannable'] !== $b['scannable']) {
                        return $b['scannable'] - $a['scannable'];
                    }
                    return strcasecmp($a['name'], $b['name']);
                });
            }
            
            echo json_encode([
                'success' => true, 
                'modules' => $modules,
                'totalModules' => count($modules),
                'scannableModules' => count(array_filter($modules, fn($m) => $m['scannable'])),
            ]);
            break;
            
        // =========================================
        // DISK STATISTICS
        // =========================================
        case 'getDiskStats':
            $stats = [];
            
            $folders = [
                ['path' => 'img/p', 'label' => 'Product Images', 'icon' => '📦', 'cleanable' => false],
                ['path' => 'img/c', 'label' => 'Category Images', 'icon' => '📁', 'cleanable' => false],
                ['path' => 'img/m', 'label' => 'Manufacturer Images', 'icon' => '🏭', 'cleanable' => false],
                ['path' => 'img/su', 'label' => 'Supplier Images', 'icon' => '🚚', 'cleanable' => false],
                ['path' => 'img/st', 'label' => 'Store Images', 'icon' => '🏪', 'cleanable' => false],
                ['path' => 'img/cms', 'label' => 'CMS Images', 'icon' => '📄', 'cleanable' => true],
                ['path' => 'img/tmp', 'label' => 'Temporary Files', 'icon' => '🗑️', 'cleanable' => true],
                ['path' => 'upload', 'label' => 'Upload Folder', 'icon' => '📤', 'cleanable' => true],
                ['path' => 'modules', 'label' => 'Module Assets', 'icon' => '🧩', 'cleanable' => false],
            ];
            
            $totalFiles = 0;
            $totalSize = 0;
            
            foreach ($folders as $folder) {
                $fullPath = __DIR__ . '/' . $folder['path'];
                $files = 0;
                $size = 0;
                $images = 0;
                
                if (is_dir($fullPath)) {
                    try {
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        
                        foreach ($iterator as $file) {
                            if ($file->isFile()) {
                                $files++;
                                $size += $file->getSize();
                                $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'])) {
                                    $images++;
                                }
                            }
                        }
                    } catch (Exception $e) {}
                }
                
                $totalFiles += $files;
                $totalSize += $size;
                
                $stats[] = [
                    'path' => $folder['path'],
                    'label' => $folder['label'],
                    'icon' => $folder['icon'],
                    'cleanable' => $folder['cleanable'],
                    'exists' => is_dir($fullPath),
                    'files' => $files,
                    'images' => $images,
                    'size' => $size,
                    'sizeFormatted' => formatBytes($size),
                ];
            }
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'totals' => [
                    'files' => $totalFiles,
                    'size' => $totalSize,
                    'sizeFormatted' => formatBytes($totalSize),
                ]
            ]);
            break;
            
        // =========================================
        // INITIALIZE SCAN
        // =========================================
        case 'initScan':
            $_SESSION['scan_data'] = [
                'references' => [],
                'orphans' => [],
                'stats' => [
                    'tablesScanned' => 0,
                    'referencesFound' => 0,
                    'filesScanned' => 0,
                    'orphansFound' => 0,
                    'orphanSize' => 0,
                ],
                'log' => [],
                'startTime' => microtime(true),
            ];
            
            echo json_encode(['success' => true, 'message' => 'Scan initialized']);
            break;
            
        // =========================================
        // SCAN DATABASE TABLES (Dynamic Discovery)
        // =========================================
        case 'scanDatabaseChunk':
            $chunkIndex = intval($_POST['chunk'] ?? 0);
            
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'Scan not initialized']);
                break;
            }
            
            $refs = &$_SESSION['scan_data']['references'];
            $stats = &$_SESSION['scan_data']['stats'];
            $log = &$_SESSION['scan_data']['log'];
            
            $addRef = function($path, $source) use (&$refs) {
                $path = trim($path);
                if (empty($path)) return;
                
                if (preg_match('#^https?://#i', $path)) {
                    $parsed = parse_url($path);
                    $path = $parsed['path'] ?? '';
                }
                
                $path = ltrim($path, '/');
                
                if (!preg_match('/\.(jpg|jpeg|png|gif|webp|svg|avif|ico|bmp)$/i', $path)) return;
                
                if (!isset($refs[$path])) {
                    $refs[$path] = [];
                }
                if (!in_array($source, $refs[$path])) {
                    $refs[$path][] = $source;
                }
            };
            
            $scanContent = function($content, $source) use ($addRef) {
                if (empty($content) || !is_string($content)) return 0;
                $count = 0;
                
                $patterns = [
                    // JSON properties commonly used for images
                    '#"(?:image|background|src|url|thumb|thumbnail|icon|photo|cover|banner|logo|picture|img|media|file|asset|poster|preview|featured|hero|avatar|sprite|bg|bgImage|backgroundImage|imageSrc|imageUrl|imgSrc|imgUrl|imageFile|pictureSrc|photoUrl)"\s*:\s*"([^"]+)"#i',
                    
                    // Paths containing common image directories
                    '#["\']([^"\']*?/img/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                    '#["\']([^"\']*?/upload/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                    '#["\']([^"\']*?/modules/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                    '#["\']([^"\']*?/themes/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                    '#["\']([^"\']*?/media/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                    '#["\']([^"\']*?/content/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                    '#["\']([^"\']*?/assets/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                    
                    // HTML img tags
                    '#<img[^>]+src=["\']([^"\']+)["\']#i',
                    '#<img[^>]+data-src=["\']([^"\']+)["\']#i',
                    '#<img[^>]+data-lazy-src=["\']([^"\']+)["\']#i',
                    
                    // CSS url() functions
                    '#url\(["\']?([^"\')\s]+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']?\)#i',
                    
                    // Data attributes commonly used for images
                    '#data-(?:src|image|bg|background|poster|thumb|original|full|large|medium|small)=["\']([^"\']+)["\']#i',
                    
                    // Background style attributes
                    '#background(?:-image)?:\s*url\(["\']?([^"\')\s]+)["\']?\)#i',
                    '#style=["\'][^"\']*background[^"\']*url\(["\']?([^"\')\s]+)["\']?\)[^"\']*["\']#i',
                    
                    // srcset, poster, object/embed
                    '#srcset=["\']([^"\']+)["\']#i',
                    '#poster=["\']([^"\']+)["\']#i',
                    '#<(?:object|embed)[^>]+(?:data|src)=["\']([^"\']+\.(jpg|jpeg|png|gif|webp|svg))["\']#i',
                    '#<link[^>]+href=["\']([^"\']+\.(jpg|jpeg|png|gif|webp|svg|ico))["\']#i',
                    
                    // Plain image paths
                    '#(?:^|[\s"\'=,;])([a-zA-Z0-9_/-]+\.(jpg|jpeg|png|gif|webp|svg|avif))(?:[\s"\'&?,;]|$)#i',
                ];
                
                foreach ($patterns as $pattern) {
                    if (preg_match_all($pattern, $content, $matches)) {
                        foreach ($matches[1] as $match) {
                            $addRef($match, $source);
                            $count++;
                        }
                    }
                }
                
                // Check for serialized PHP data
                if (strpos($content, 's:') !== false || strpos($content, 'a:') !== false) {
                    $unserialized = @unserialize($content);
                    if ($unserialized !== false && is_array($unserialized)) {
                        array_walk_recursive($unserialized, function($value) use ($addRef, $source, &$count) {
                            if (is_string($value) && preg_match('/\.(jpg|jpeg|png|gif|webp|svg|avif|ico)$/i', $value)) {
                                $addRef($value, $source . ' (serialized)');
                                $count++;
                            }
                        });
                    }
                }
                
                // Check for JSON data
                if (strpos($content, '{') !== false || strpos($content, '[') !== false) {
                    $decoded = @json_decode($content, true);
                    if (is_array($decoded)) {
                        array_walk_recursive($decoded, function($value) use ($addRef, $source, &$count) {
                            if (is_string($value) && preg_match('/\.(jpg|jpeg|png|gif|webp|svg|avif|ico)$/i', $value)) {
                                $addRef($value, $source . ' (JSON)');
                                $count++;
                            }
                        });
                    }
                }
                
                return $count;
            };
            
            // Dynamic table discovery on first chunk
            if ($chunkIndex === 0 && !isset($_SESSION['db_scan_tables'])) {
                $scannableTables = [];
                
                // Column names that likely contain image references
                $imageColumnPatterns = [
                    'image', 'img', 'icon', 'photo', 'picture', 'thumb', 'thumbnail', 'banner', 'logo',
                    'background', 'media', 'file', 'asset', 'cover', 'poster', 'avatar', 'sprite',
                    'content', 'description', 'html', 'text', 'body', 'data', 'params', 'settings',
                    'value', 'layers', 'slides', 'items', 'config', 'json', 'custom', 'url', 'src'
                ];
                
                try {
                    // Get all tables with this prefix
                    $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}%'");
                    $allTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    foreach ($allTables as $table) {
                        // Skip certain system/log tables that won't have useful image refs
                        $tableName = str_replace($prefix, '', $table);
                        if (preg_match('/^(log|cart|order_detail|order_history|connections|guest|pagenotfound|statssearch|sekeyword|compare|mail|message_readed|customer_session|stock_mvt|specific_price|search_index|search_word|layered_|smarty_cache|smarty_lazy)/', $tableName)) {
                            continue;
                        }
                        
                        // Skip revision/history tables (huge data, minimal image value)
                        if (preg_match('/(revision|history|backup|cache|queue|cron|log_)/', $tableName)) {
                            continue;
                        }
                        
                        // Get columns for this table
                        $colStmt = $pdo->query("SHOW COLUMNS FROM `$table`");
                        $columns = $colStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $scannableColumns = [];
                        foreach ($columns as $col) {
                            $colName = strtolower($col['Field']);
                            $colType = strtolower($col['Type']);
                            
                            // Check if column type can hold content (TEXT, BLOB, VARCHAR, LONGTEXT, etc.)
                            $isTextType = preg_match('/(text|blob|varchar|char|longtext|mediumtext|json)/i', $colType);
                            
                            // Check if column name suggests image content
                            $isImageColumn = false;
                            foreach ($imageColumnPatterns as $pattern) {
                                if (strpos($colName, $pattern) !== false) {
                                    $isImageColumn = true;
                                    break;
                                }
                            }
                            
                            // For TEXT/BLOB types, scan even if name doesn't match pattern
                            // For VARCHAR, only scan if name matches pattern
                            if (preg_match('/(text|blob|longtext|mediumtext|json)/i', $colType) || 
                                ($isTextType && $isImageColumn)) {
                                $scannableColumns[] = $col['Field'];
                            }
                        }
                        
                        if (!empty($scannableColumns)) {
                            $scannableTables[] = [
                                'table' => $table,
                                'columns' => $scannableColumns,
                                'source' => str_replace($prefix, '', $table),
                            ];
                        }
                    }
                } catch (Exception $e) {
                    // Fall back to core tables if discovery fails
                    $scannableTables = [
                        ['table' => "{$prefix}cms_lang", 'columns' => ['content'], 'source' => 'CMS Pages'],
                        ['table' => "{$prefix}product_lang", 'columns' => ['description', 'description_short'], 'source' => 'Products'],
                        ['table' => "{$prefix}category_lang", 'columns' => ['description'], 'source' => 'Categories'],
                        ['table' => "{$prefix}configuration", 'columns' => ['value'], 'source' => 'Configuration'],
                    ];
                }
                
                // Split into chunks of 5 tables each
                $_SESSION['db_scan_tables'] = array_chunk($scannableTables, 3);
                $_SESSION['db_scan_total_tables'] = count($scannableTables);
            }
            
            $tableChunks = $_SESSION['db_scan_tables'] ?? [];
            $totalChunks = count($tableChunks);
            
            if ($chunkIndex >= $totalChunks) {
                echo json_encode([
                    'success' => true,
                    'complete' => true,
                    'totalChunks' => $totalChunks,
                    'totalTables' => $_SESSION['db_scan_total_tables'] ?? 0,
                    'referencesFound' => count($refs),
                ]);
                break;
            }
            
            $targets = $tableChunks[$chunkIndex];
            $chunkLog = [];
            $chunkRefs = 0;
            
            foreach ($targets as $target) {
                if (!tableExists($pdo, $target['table'])) {
                    continue; // Silently skip - table may have been removed
                }
                
                $validCols = [];
                foreach ($target['columns'] as $col) {
                    if (columnExists($pdo, $target['table'], $col)) {
                        $validCols[] = $col;
                    }
                }
                
                if (empty($validCols)) {
                    continue;
                }
                
                try {
                    $colList = implode(', ', array_map(function($c) { return "`$c`"; }, $validCols));
                    
                    // Get row count first to handle large tables
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$target['table']}`");
                    $totalRows = $countStmt->fetchColumn();
                    
                    // For very large tables, process in batches
                    $maxRowsPerTable = 5000; // Limit per table to prevent memory issues
                    $batchSize = 500;
                    $rowCount = 0;
                    $refCount = 0;
                    
                    if ($totalRows > $maxRowsPerTable) {
                        // Sample the table instead of reading everything
                        $sql = "SELECT $colList FROM `{$target['table']}` LIMIT $maxRowsPerTable";
                        $chunkLog[] = [
                            'type' => 'warning',
                            'source' => $target['source'],
                            'message' => "Large table ({$totalRows} rows) - sampling first {$maxRowsPerTable}",
                        ];
                    } else {
                        $sql = "SELECT $colList FROM `{$target['table']}`";
                    }
                    
                    $stmt = $pdo->query($sql);
                    $stmt->setFetchMode(PDO::FETCH_ASSOC);
                    
                    while ($row = $stmt->fetch()) {
                        $rowCount++;
                        foreach ($row as $value) {
                            if (!empty($value) && is_string($value) && strlen($value) > 3) {
                                // Limit content length to prevent memory issues
                                if (strlen($value) > 500000) {
                                    $value = substr($value, 0, 500000);
                                }
                                $refCount += $scanContent($value, $target['source']);
                            }
                        }
                        
                        // Free memory periodically
                        if ($rowCount % $batchSize === 0) {
                            gc_collect_cycles();
                        }
                    }
                    
                    unset($stmt); // Free statement memory
                    gc_collect_cycles();
                    
                    $stats['tablesScanned']++;
                    $chunkRefs += $refCount;
                    
                    if ($refCount > 0) {
                        $chunkLog[] = [
                            'type' => 'success',
                            'source' => $target['source'],
                            'table' => $target['table'],
                            'rows' => $rowCount,
                            'refs' => $refCount,
                        ];
                    }
                    
                } catch (Exception $e) {
                    $chunkLog[] = [
                        'type' => 'error',
                        'source' => $target['source'],
                        'table' => $target['table'],
                        'error' => $e->getMessage(),
                    ];
                }
            }
            
            $stats['referencesFound'] = count($refs);
            $_SESSION['scan_data']['log'] = array_merge($log, $chunkLog);
            
            // Clear any stray output and send JSON
            ob_clean();
            echo json_encode([
                'success' => true,
                'complete' => false,
                'chunk' => $chunkIndex,
                'totalChunks' => $totalChunks,
                'progress' => round((($chunkIndex + 1) / $totalChunks) * 100, 1),
                'chunkRefs' => $chunkRefs,
                'totalRefs' => count($refs),
                'log' => $chunkLog,
            ]);
            break;
            
        // =========================================
        // SCAN THEME FILES
        // =========================================
        case 'scanThemes':
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'Scan not initialized']);
                break;
            }
            
            $refs = &$_SESSION['scan_data']['references'];
            
            $addRef = function($path, $source) use (&$refs) {
                $path = ltrim(trim($path), '/');
                if (!preg_match('/\.(jpg|jpeg|png|gif|webp|svg|avif|ico)$/i', $path)) return;
                if (!isset($refs[$path])) $refs[$path] = [];
                if (!in_array($source, $refs[$path])) $refs[$path][] = $source;
            };
            
            $themesDir = __DIR__ . '/themes';
            $modulesDir = __DIR__ . '/modules';
            $themeResults = [];
            
            // Scan themes directory
            if (is_dir($themesDir)) {
                foreach (glob($themesDir . '/*/') as $themeDir) {
                    $themeName = basename($themeDir);
                    $themeRefs = 0;
                    $filesScanned = 0;
                    
                    try {
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($themeDir, RecursiveDirectoryIterator::SKIP_DOTS)
                        );
                        
                        foreach ($iterator as $file) {
                            if ($file->isDir()) continue;
                            $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                            
                            if (in_array($ext, ['tpl', 'html', 'htm', 'css', 'scss', 'less', 'js', 'json', 'xml', 'php', 'yml', 'yaml'])) {
                                $content = @file_get_contents($file->getPathname());
                                $filesScanned++;
                                
                                if ($content) {
                                    $patterns = [
                                        '#["\']([^"\']*?/img/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                                        '#["\']([^"\']*?/upload/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                                        '#["\']([^"\']*?/modules/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                                        '#url\(["\']?([^"\')\s]+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']?\)#i',
                                        '#<img[^>]+src=["\']([^"\']+)["\']#i',
                                        '#data-(?:src|image|bg|background)=["\']([^"\']+\.(jpg|jpeg|png|gif|webp|svg))["\']#i',
                                        '#background(?:-image)?:\s*url\(["\']?([^"\')\s]+)["\']?\)#i',
                                    ];
                                    
                                    foreach ($patterns as $pattern) {
                                        if (preg_match_all($pattern, $content, $matches)) {
                                            foreach ($matches[1] as $match) {
                                                $addRef($match, "Theme: $themeName");
                                                $themeRefs++;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        $themeResults[] = [
                            'theme' => $themeName,
                            'files' => $filesScanned,
                            'refs' => $themeRefs,
                            'status' => 'success',
                        ];
                        
                    } catch (Exception $e) {
                        $themeResults[] = [
                            'theme' => $themeName,
                            'error' => $e->getMessage(),
                            'status' => 'error',
                        ];
                    }
                }
            }
            
            // Also scan module views/templates for image references
            if (is_dir($modulesDir)) {
                $moduleRefs = 0;
                $moduleFilesScanned = 0;
                
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($modulesDir, RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    
                    foreach ($iterator as $file) {
                        if ($file->isDir()) continue;
                        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                        
                        // Only scan template and config files, not all module files
                        if (in_array($ext, ['tpl', 'html', 'htm', 'json', 'xml', 'yml', 'yaml']) ||
                            (in_array($ext, ['php']) && strpos($file->getPathname(), '/views/') !== false)) {
                            $content = @file_get_contents($file->getPathname());
                            $moduleFilesScanned++;
                            
                            if ($content) {
                                $patterns = [
                                    '#["\']([^"\']*?/img/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                                    '#["\']([^"\']*?/upload/[^"\']+\.(jpg|jpeg|png|gif|webp|svg|avif|ico))["\']#i',
                                ];
                                
                                foreach ($patterns as $pattern) {
                                    if (preg_match_all($pattern, $content, $matches)) {
                                        foreach ($matches[1] as $match) {
                                            $moduleName = '';
                                            if (preg_match('#/modules/([^/]+)/#', $file->getPathname(), $m)) {
                                                $moduleName = $m[1];
                                            }
                                            $addRef($match, "Module: $moduleName");
                                            $moduleRefs++;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    if ($moduleFilesScanned > 0) {
                        $themeResults[] = [
                            'theme' => 'Modules (templates)',
                            'files' => $moduleFilesScanned,
                            'refs' => $moduleRefs,
                            'status' => 'success',
                        ];
                    }
                    
                } catch (Exception $e) {
                    // Silently handle module scan errors
                }
            }
            
            $_SESSION['scan_data']['stats']['referencesFound'] = count($refs);
            
            echo json_encode([
                'success' => true,
                'themes' => $themeResults,
                'totalRefs' => count($refs),
            ]);
            break;
            
        // =========================================
        // SCAN FOLDER FOR ORPHANS
        // =========================================
        case 'scanFolderChunk':
            $folder = $_POST['folder'] ?? '';
            $offset = intval($_POST['offset'] ?? 0);
            $chunkSize = CHUNK_SIZE;
            
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'Scan not initialized']);
                break;
            }
            
            $refs = $_SESSION['scan_data']['references'];
            $fullPath = __DIR__ . '/' . $folder;
            
            if (!is_dir($fullPath)) {
                echo json_encode(['error' => 'Folder not found: ' . $folder]);
                break;
            }
            
            $cacheKey = 'folder_files_' . md5($folder);
            if ($offset === 0 || !isset($_SESSION[$cacheKey])) {
                $allFiles = [];
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    
                    foreach ($iterator as $file) {
                        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'bmp', 'ico'])) {
                            $allFiles[] = [
                                'path' => str_replace(['\\', __DIR__ . '/'], ['/', ''], $file->getPathname()),
                                'name' => $file->getFilename(),
                                'size' => $file->getSize(),
                            ];
                        }
                    }
                } catch (Exception $e) {
                    echo json_encode(['error' => $e->getMessage()]);
                    break;
                }
                
                $_SESSION[$cacheKey] = $allFiles;
                $_SESSION[$cacheKey . '_orphans'] = [];
                $_SESSION[$cacheKey . '_stats'] = ['total' => 0, 'size' => 0];
            }
            
            $allFiles = $_SESSION[$cacheKey];
            $orphans = $_SESSION[$cacheKey . '_orphans'];
            $folderStats = $_SESSION[$cacheKey . '_stats'];
            $total = count($allFiles);
            
            $chunk = array_slice($allFiles, $offset, $chunkSize);
            $newOrphans = [];
            
            foreach ($chunk as $file) {
                $relativePath = $file['path'];
                $fileName = $file['name'];
                
                $isReferenced = isset($refs[$relativePath]);
                
                if (!$isReferenced) {
                    foreach ($refs as $refPath => $sources) {
                        if (strpos($refPath, $fileName) !== false || strpos($refPath, basename($relativePath)) !== false) {
                            $isReferenced = true;
                            break;
                        }
                    }
                }
                
                if (!$isReferenced) {
                    $newOrphans[] = [
                        'path' => $relativePath,
                        'name' => $fileName,
                        'size' => $file['size'],
                        'sizeFormatted' => formatBytes($file['size']),
                    ];
                    $folderStats['total']++;
                    $folderStats['size'] += $file['size'];
                }
            }
            
            $orphans = array_merge($orphans, $newOrphans);
            $_SESSION[$cacheKey . '_orphans'] = $orphans;
            $_SESSION[$cacheKey . '_stats'] = $folderStats;
            
            $processed = min($offset + $chunkSize, $total);
            $complete = $processed >= $total;
            
            if ($complete && !empty($orphans)) {
                $_SESSION['scan_data']['orphans'][$folder] = [
                    'files' => $orphans,
                    'count' => count($orphans),
                    'size' => $folderStats['size'],
                ];
                $_SESSION['scan_data']['stats']['orphansFound'] += count($orphans);
                $_SESSION['scan_data']['stats']['orphanSize'] += $folderStats['size'];
            }
            
            echo json_encode([
                'success' => true,
                'folder' => $folder,
                'processed' => $processed,
                'total' => $total,
                'progress' => $total > 0 ? round(($processed / $total) * 100, 1) : 100,
                'orphansInChunk' => count($newOrphans),
                'totalOrphans' => count($orphans),
                'orphanSize' => $folderStats['size'],
                'orphanSizeFormatted' => formatBytes($folderStats['size']),
                'complete' => $complete,
            ]);
            break;
            
        // =========================================
        // SCAN PRODUCT IMAGES
        // =========================================
        case 'scanProductImages':
            $offset = intval($_POST['offset'] ?? 0);
            $chunkSize = 1000;
            
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'Scan not initialized']);
                break;
            }
            
            $imgDir = __DIR__ . '/img/p';
            if (!is_dir($imgDir)) {
                echo json_encode(['error' => 'Product image folder not found', 'complete' => true]);
                break;
            }
            
            if ($offset === 0) {
                $stmt = $pdo->query("SELECT id_image FROM {$prefix}image");
                $_SESSION['product_valid_ids'] = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
                
                $allFiles = [];
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($imgDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($iterator as $file) {
                    $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
                        $allFiles[] = [
                            'path' => str_replace(['\\', __DIR__ . '/'], ['/', ''], $file->getPathname()),
                            'name' => $file->getFilename(),
                            'size' => $file->getSize(),
                        ];
                    }
                }
                
                $_SESSION['product_files'] = $allFiles;
                $_SESSION['product_total'] = count($allFiles);
                $_SESSION['product_orphans'] = [];
                $_SESSION['product_orphan_size'] = 0;
            }
            
            $validIds = $_SESSION['product_valid_ids'];
            $allFiles = $_SESSION['product_files'];
            $total = $_SESSION['product_total'];
            $orphans = $_SESSION['product_orphans'];
            $orphanSize = $_SESSION['product_orphan_size'];
            
            $chunk = array_slice($allFiles, $offset, $chunkSize);
            $newOrphans = [];
            
            foreach ($chunk as $file) {
                if (preg_match('/^(\d+)(?:-[a-z0-9_]+)?\.(jpg|jpeg|png|gif|webp|avif)$/i', $file['name'], $matches)) {
                    $imageId = $matches[1];
                    
                    if (!isset($validIds[$imageId])) {
                        $newOrphans[] = [
                            'path' => $file['path'],
                            'name' => $file['name'],
                            'size' => $file['size'],
                            'sizeFormatted' => formatBytes($file['size']),
                            'imageId' => $imageId,
                        ];
                        $orphanSize += $file['size'];
                    }
                }
            }
            
            $orphans = array_merge($orphans, $newOrphans);
            $_SESSION['product_orphans'] = $orphans;
            $_SESSION['product_orphan_size'] = $orphanSize;
            
            $processed = min($offset + $chunkSize, $total);
            $complete = $processed >= $total;
            
            if ($complete && !empty($orphans)) {
                $_SESSION['scan_data']['orphans']['img/p'] = [
                    'files' => $orphans,
                    'count' => count($orphans),
                    'size' => $orphanSize,
                ];
                $_SESSION['scan_data']['stats']['orphansFound'] += count($orphans);
                $_SESSION['scan_data']['stats']['orphanSize'] += $orphanSize;
            }
            
            echo json_encode([
                'success' => true,
                'processed' => $processed,
                'total' => $total,
                'progress' => $total > 0 ? round(($processed / $total) * 100, 1) : 100,
                'validImages' => count($validIds),
                'orphansInChunk' => count($newOrphans),
                'totalOrphans' => count($orphans),
                'orphanSize' => $orphanSize,
                'orphanSizeFormatted' => formatBytes($orphanSize),
                'complete' => $complete,
            ]);
            break;
            
        // =========================================
        // SCAN ENTITY IMAGES (Category, Manufacturer, Supplier, Store)
        // =========================================
        case 'scanEntityImages':
            $entityType = $_POST['entity'] ?? '';
            $offset = intval($_POST['offset'] ?? 0);
            $chunkSize = CHUNK_SIZE;
            
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'Scan not initialized']);
                break;
            }
            
            // Define entity configurations
            $entityConfigs = [
                'category' => [
                    'table' => "{$prefix}category",
                    'idColumn' => 'id_category',
                    'imgDir' => 'img/c',
                ],
                'manufacturer' => [
                    'table' => "{$prefix}manufacturer",
                    'idColumn' => 'id_manufacturer', 
                    'imgDir' => 'img/m',
                ],
                'supplier' => [
                    'table' => "{$prefix}supplier",
                    'idColumn' => 'id_supplier',
                    'imgDir' => 'img/su',
                ],
                'store' => [
                    'table' => "{$prefix}store",
                    'idColumn' => 'id_store',
                    'imgDir' => 'img/st',
                ],
            ];
            
            if (!isset($entityConfigs[$entityType])) {
                echo json_encode(['error' => 'Invalid entity type']);
                break;
            }
            
            $config = $entityConfigs[$entityType];
            $imgDir = __DIR__ . '/' . $config['imgDir'];
            
            if (!is_dir($imgDir)) {
                echo json_encode(['success' => true, 'complete' => true, 'totalOrphans' => 0, 'skipped' => true]);
                break;
            }
            
            $cacheKey = 'entity_' . $entityType;
            
            if ($offset === 0) {
                // Get valid IDs from database
                try {
                    $stmt = $pdo->query("SELECT {$config['idColumn']} FROM {$config['table']}");
                    $_SESSION[$cacheKey . '_valid_ids'] = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
                } catch (Exception $e) {
                    $_SESSION[$cacheKey . '_valid_ids'] = [];
                }
                
                // Collect all files
                $allFiles = [];
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($imgDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    
                    foreach ($iterator as $file) {
                        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'])) {
                            $allFiles[] = [
                                'path' => str_replace(['\\', __DIR__ . '/'], ['/', ''], $file->getPathname()),
                                'name' => $file->getFilename(),
                                'size' => $file->getSize(),
                            ];
                        }
                    }
                } catch (Exception $e) {
                    echo json_encode(['error' => $e->getMessage()]);
                    break;
                }
                
                $_SESSION[$cacheKey . '_files'] = $allFiles;
                $_SESSION[$cacheKey . '_total'] = count($allFiles);
                $_SESSION[$cacheKey . '_orphans'] = [];
                $_SESSION[$cacheKey . '_orphan_size'] = 0;
            }
            
            $validIds = $_SESSION[$cacheKey . '_valid_ids'];
            $allFiles = $_SESSION[$cacheKey . '_files'];
            $total = $_SESSION[$cacheKey . '_total'];
            $orphans = $_SESSION[$cacheKey . '_orphans'];
            $orphanSize = $_SESSION[$cacheKey . '_orphan_size'];
            
            $chunk = array_slice($allFiles, $offset, $chunkSize);
            $newOrphans = [];
            
            foreach ($chunk as $file) {
                // Entity images typically named: {id}.jpg, {id}-{type}.jpg, {id}_thumb.jpg, etc.
                if (preg_match('/^(\d+)(?:[-_][a-z0-9_]+)?\.(jpg|jpeg|png|gif|webp|avif)$/i', $file['name'], $matches)) {
                    $entityId = $matches[1];
                    
                    if (!isset($validIds[$entityId])) {
                        $newOrphans[] = [
                            'path' => $file['path'],
                            'name' => $file['name'],
                            'size' => $file['size'],
                            'sizeFormatted' => formatBytes($file['size']),
                            'entityId' => $entityId,
                        ];
                        $orphanSize += $file['size'];
                    }
                }
            }
            
            $orphans = array_merge($orphans, $newOrphans);
            $_SESSION[$cacheKey . '_orphans'] = $orphans;
            $_SESSION[$cacheKey . '_orphan_size'] = $orphanSize;
            
            $processed = min($offset + $chunkSize, $total);
            $complete = $processed >= $total;
            
            if ($complete && !empty($orphans)) {
                $_SESSION['scan_data']['orphans'][$config['imgDir']] = [
                    'files' => $orphans,
                    'count' => count($orphans),
                    'size' => $orphanSize,
                ];
                $_SESSION['scan_data']['stats']['orphansFound'] += count($orphans);
                $_SESSION['scan_data']['stats']['orphanSize'] += $orphanSize;
            }
            
            echo json_encode([
                'success' => true,
                'entity' => $entityType,
                'processed' => $processed,
                'total' => $total,
                'progress' => $total > 0 ? round(($processed / $total) * 100, 1) : 100,
                'validEntities' => count($validIds),
                'orphansInChunk' => count($newOrphans),
                'totalOrphans' => count($orphans),
                'orphanSize' => $orphanSize,
                'orphanSizeFormatted' => formatBytes($orphanSize),
                'complete' => $complete,
            ]);
            break;
            
        // =========================================
        // GET SCAN RESULTS
        // =========================================
        case 'getScanResults':
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'No scan data available']);
                break;
            }
            
            $data = $_SESSION['scan_data'];
            $duration = microtime(true) - ($data['startTime'] ?? microtime(true));
            
            echo json_encode([
                'success' => true,
                'stats' => $data['stats'],
                'referencesCount' => count($data['references']),
                'orphans' => $data['orphans'],
                'duration' => round($duration, 2),
            ]);
            break;
            
        // =========================================
        // GET ORPHANS
        // =========================================
        case 'getOrphans':
            $folder = $_POST['folder'] ?? '';
            $page = intval($_POST['page'] ?? 1);
            $perPage = intval($_POST['perPage'] ?? 50);
            
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'No scan data']);
                break;
            }
            
            if (empty($folder)) {
                $orphans = $_SESSION['scan_data']['orphans'];
                $summary = [];
                $totalCount = 0;
                $totalSize = 0;
                
                foreach ($orphans as $f => $data) {
                    $summary[] = [
                        'folder' => $f,
                        'count' => $data['count'],
                        'size' => $data['size'],
                        'sizeFormatted' => formatBytes($data['size']),
                    ];
                    $totalCount += $data['count'];
                    $totalSize += $data['size'];
                }
                
                echo json_encode([
                    'success' => true,
                    'summary' => $summary,
                    'totalCount' => $totalCount,
                    'totalSize' => $totalSize,
                    'totalSizeFormatted' => formatBytes($totalSize),
                ]);
            } else {
                $orphans = $_SESSION['scan_data']['orphans'][$folder]['files'] ?? [];
                $total = count($orphans);
                $totalPages = ceil($total / $perPage);
                $offset = ($page - 1) * $perPage;
                
                echo json_encode([
                    'success' => true,
                    'folder' => $folder,
                    'files' => array_slice($orphans, $offset, $perPage),
                    'page' => $page,
                    'total' => $total,
                    'totalPages' => $totalPages,
                    'totalSize' => $_SESSION['scan_data']['orphans'][$folder]['size'] ?? 0,
                    'totalSizeFormatted' => formatBytes($_SESSION['scan_data']['orphans'][$folder]['size'] ?? 0),
                ]);
            }
            break;
            
        // =========================================
        // DELETE ORPHANS
        // =========================================
        case 'deleteOrphans':
            $folder = $_POST['folder'] ?? '';
            $mode = $_POST['mode'] ?? 'backup';
            
            if (!isset($_SESSION['scan_data']['orphans'][$folder])) {
                echo json_encode(['error' => 'No orphans for this folder']);
                break;
            }
            
            $orphans = $_SESSION['scan_data']['orphans'][$folder]['files'];
            $backupDir = __DIR__ . '/_orphan_backup/' . date('Y-m-d_His') . '/' . str_replace('/', '_', $folder);
            
            $deleted = 0;
            $failed = 0;
            $freedSize = 0;
            
            if ($mode === 'backup' && !is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            foreach ($orphans as $orphan) {
                $fullPath = __DIR__ . '/' . $orphan['path'];
                
                if (!file_exists($fullPath)) {
                    $failed++;
                    continue;
                }
                
                try {
                    $fileSize = filesize($fullPath);
                    
                    if ($mode === 'backup') {
                        $destPath = $backupDir . '/' . $orphan['name'];
                        $counter = 1;
                        while (file_exists($destPath)) {
                            $info = pathinfo($orphan['name']);
                            $destPath = $backupDir . '/' . $info['filename'] . '_' . $counter . '.' . $info['extension'];
                            $counter++;
                        }
                        
                        if (rename($fullPath, $destPath)) {
                            $deleted++;
                            $freedSize += $fileSize;
                        } else {
                            $failed++;
                        }
                    } else {
                        if (unlink($fullPath)) {
                            $deleted++;
                            $freedSize += $fileSize;
                        } else {
                            $failed++;
                        }
                    }
                } catch (Exception $e) {
                    $failed++;
                }
            }
            
            unset($_SESSION['scan_data']['orphans'][$folder]);
            $_SESSION['scan_data']['stats']['orphansFound'] -= ($deleted + $failed);
            $_SESSION['scan_data']['stats']['orphanSize'] -= $freedSize;
            
            echo json_encode([
                'success' => true,
                'mode' => $mode,
                'deleted' => $deleted,
                'failed' => $failed,
                'freedSize' => $freedSize,
                'freedSizeFormatted' => formatBytes($freedSize),
                'backupPath' => $mode === 'backup' ? str_replace(__DIR__ . '/', '', $backupDir) : null,
            ]);
            break;
            
        // =========================================
        // CLEAN TEMP FOLDER
        // =========================================
        case 'cleanTemp':
            $tmpDir = __DIR__ . '/img/tmp';
            $deleted = 0;
            $size = 0;
            
            if (is_dir($tmpDir)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                
                foreach ($iterator as $file) {
                    try {
                        if ($file->isFile()) {
                            $fileSize = $file->getSize();
                            if (unlink($file->getPathname())) {
                                $deleted++;
                                $size += $fileSize;
                            }
                        } elseif ($file->isDir()) {
                            @rmdir($file->getPathname());
                        }
                    } catch (Exception $e) {}
                }
            }
            
            echo json_encode([
                'success' => true,
                'deleted' => $deleted,
                'freedSize' => $size,
                'freedSizeFormatted' => formatBytes($size),
            ]);
            break;
            
        // =========================================
        // EXPORT REPORT
        // =========================================
        case 'exportReport':
            $format = $_POST['format'] ?? 'json';
            
            if (!isset($_SESSION['scan_data'])) {
                echo json_encode(['error' => 'No scan data']);
                break;
            }
            
            $data = $_SESSION['scan_data'];
            
            $report = [
                'generated' => date('Y-m-d H:i:s'),
                'tool' => 'PrestaShop Image Cleanup Tool v' . TOOL_VERSION,
                'author' => 'Simon Todd (https://simontodd.dev)',
                'stats' => $data['stats'],
                'references' => count($data['references']),
                'orphansByFolder' => [],
            ];
            
            foreach ($data['orphans'] as $folder => $info) {
                $report['orphansByFolder'][$folder] = [
                    'count' => $info['count'],
                    'size' => $info['size'],
                    'sizeFormatted' => formatBytes($info['size']),
                    'files' => array_column($info['files'], 'path'),
                ];
            }
            
            if ($format === 'csv') {
                $csv = "Folder,File,Size\n";
                foreach ($data['orphans'] as $folder => $info) {
                    foreach ($info['files'] as $file) {
                        $csv .= "\"{$folder}\",\"{$file['path']}\",{$file['size']}\n";
                    }
                }
                echo json_encode(['success' => true, 'format' => 'csv', 'data' => $csv]);
            } else {
                echo json_encode(['success' => true, 'format' => 'json', 'data' => $report]);
            }
            break;
            
        default:
            echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PrestaShop Image Cleanup Tool | Simon Todd</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #e01966;
            --primary-hover: #c01555;
            --primary-light: #fce7ef;
            --dark: #383e46;
            --dark-light: #4a525c;
            --white: #ffffff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #3b82f6;
            --info-light: #dbeafe;
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-100);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Login Screen */
        .login-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--dark) 0%, #2d3748 100%);
            padding: 20px;
        }
        
        .login-box {
            background: var(--white);
            padding: 48px;
            border-radius: 24px;
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 440px;
            text-align: center;
        }
        
        .login-logo { width: 80px; height: 80px; margin: 0 auto 24px; }
        .login-logo svg { width: 100%; height: 100%; }
        
        .login-title { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .login-subtitle { color: var(--gray-500); margin-bottom: 8px; font-size: 14px; }
        .login-author { margin-bottom: 28px; }
        .login-author a { color: var(--primary); text-decoration: none; font-weight: 500; font-size: 14px; }
        .login-author a:hover { text-decoration: underline; }
        
        .form-group { margin-bottom: 20px; text-align: left; }
        .form-label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; color: var(--gray-700); }
        
        .form-input {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-light);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 28px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: var(--white);
            box-shadow: 0 4px 14px 0 rgba(224, 25, 102, 0.4);
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px 0 rgba(224, 25, 102, 0.5);
        }
        
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-secondary { background: var(--gray-200); color: var(--dark); }
        .btn-secondary:hover { background: var(--gray-300); }
        .btn-danger { background: var(--danger); color: var(--white); }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 10px 18px; font-size: 14px; border-radius: 10px; }
        .btn-block { width: 100%; }
        
        .error-msg {
            background: var(--danger-light);
            border: 1px solid #fca5a5;
            color: #b91c1c;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .security-notice {
            margin-top: 24px;
            padding: 16px;
            background: var(--warning-light);
            border-radius: 12px;
            font-size: 13px;
            color: #92400e;
        }
        
        /* App Layout */
        .app { display: flex; min-height: 100vh; }
        
        .sidebar {
            width: 280px;
            background: var(--dark);
            color: var(--white);
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--dark-light);
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .sidebar-logo-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .sidebar-logo-icon svg { width: 100%; height: 100%; }
        
        .sidebar-logo-text { font-size: 15px; font-weight: 700; }
        .sidebar-logo-version { font-size: 11px; color: var(--gray-400); }
        
        .sidebar-nav { flex: 1; padding: 16px 12px; overflow-y: auto; }
        
        .nav-section { margin-bottom: 24px; }
        .nav-section-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-500); padding: 0 12px; margin-bottom: 8px; }
        
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            color: var(--gray-400);
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 4px;
            font-weight: 500;
            font-size: 14px;
        }
        
        .nav-item:hover { background: var(--dark-light); color: var(--white); }
        .nav-item.active { background: var(--primary); color: var(--white); }
        .nav-item-icon { font-size: 18px; width: 24px; text-align: center; }
        .nav-item-badge { margin-left: auto; background: var(--primary); padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        
        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid var(--dark-light);
            text-align: center;
        }
        
        .sidebar-footer-brand {
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--dark-light);
        }
        
        .sidebar-footer-brand a {
            color: var(--gray-400);
            text-decoration: none;
            font-size: 12px;
        }
        
        .sidebar-footer-brand a:hover { color: var(--primary); }
        
        .main { flex: 1; margin-left: 280px; }
        
        .topbar {
            background: var(--white);
            padding: 16px 32px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        
        .topbar-title { font-size: 20px; font-weight: 700; }
        .topbar-subtitle { font-size: 13px; color: var(--gray-500); }
        
        .content { padding: 32px; }
        
        /* Cards */
        .card {
            background: var(--white);
            border-radius: 16px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .card-title {
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body { padding: 24px; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            flex-shrink: 0;
        }
        
        .stat-icon.primary { background: var(--primary-light); }
        .stat-icon.success { background: var(--success-light); }
        .stat-icon.warning { background: var(--warning-light); }
        .stat-icon.danger { background: var(--danger-light); }
        .stat-icon.info { background: var(--info-light); }
        
        .stat-value { font-size: 28px; font-weight: 700; color: var(--dark); line-height: 1.2; }
        .stat-label { font-size: 14px; color: var(--gray-500); margin-top: 4px; }
        
        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success { background: var(--success-light); color: #065f46; }
        .badge-warning { background: var(--warning-light); color: #92400e; }
        .badge-danger { background: var(--danger-light); color: #dc2626; }
        .badge-gray { background: var(--gray-200); color: var(--gray-600); }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        
        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }
        
        th {
            background: var(--gray-50);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--gray-500);
        }
        
        tr:hover td { background: var(--gray-50); }
        
        /* Progress */
        .progress-container { margin: 24px 0; }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }
        
        .progress-title { font-weight: 600; }
        .progress-value { color: var(--gray-500); }
        
        .progress-bar {
            background: var(--gray-200);
            border-radius: 10px;
            height: 10px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-hover) 100%);
            border-radius: 10px;
            transition: width 0.3s ease;
        }
        
        .progress-fill.animated {
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-hover) 50%, var(--primary) 100%);
            background-size: 200% 100%;
            animation: progress-shine 1.5s linear infinite;
        }
        
        @keyframes progress-shine {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        /* Log Console */
        .log-console {
            background: #1a1a2e;
            border-radius: 12px;
            padding: 20px;
            font-family: 'Monaco', 'Consolas', 'Courier New', monospace;
            font-size: 13px;
            max-height: 350px;
            overflow-y: auto;
        }
        
        .log-entry {
            padding: 6px 0;
            display: flex;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .log-time { color: #6b7280; min-width: 70px; }
        .log-icon { min-width: 20px; }
        .log-message { color: #e5e7eb; flex: 1; word-break: break-word; }
        .log-entry.success .log-message { color: var(--success); }
        .log-entry.error .log-message { color: var(--danger); }
        .log-entry.warning .log-message { color: var(--warning); }
        .log-entry.info .log-message { color: var(--info); }
        
        /* File List */
        .file-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--gray-100);
        }
        
        .file-item:last-child { border-bottom: none; }
        .file-item:hover { background: var(--gray-50); }
        
        .file-info { flex: 1; min-width: 0; }
        .file-path { font-family: monospace; font-size: 12px; color: var(--dark); word-break: break-all; }
        .file-meta { font-size: 11px; color: var(--gray-500); margin-top: 2px; }
        
        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        
        .alert-icon { font-size: 22px; flex-shrink: 0; }
        .alert-content { flex: 1; }
        .alert-title { font-weight: 600; margin-bottom: 4px; }
        .alert-text { font-size: 14px; opacity: 0.9; }
        
        .alert-info { background: var(--info-light); border: 1px solid #93c5fd; }
        .alert-success { background: var(--success-light); border: 1px solid #6ee7b7; }
        .alert-warning { background: var(--warning-light); border: 1px solid #fcd34d; }
        .alert-danger { background: var(--danger-light); border: 1px solid #fca5a5; }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            padding: 20px;
        }
        
        .modal-overlay.active { opacity: 1; visibility: visible; }
        
        .modal {
            background: var(--white);
            border-radius: 20px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s;
        }
        
        .modal-overlay.active .modal { transform: scale(1) translateY(0); }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-title { font-size: 18px; font-weight: 700; }
        
        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: var(--gray-100);
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover { background: var(--gray-200); }
        
        .modal-body { padding: 24px; max-height: 60vh; overflow-y: auto; }
        .modal-footer { padding: 20px 24px; border-top: 1px solid var(--gray-200); display: flex; gap: 12px; justify-content: flex-end; }
        
        /* Modules Grid */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }
        
        .module-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--gray-50);
            border-radius: 10px;
            border: 1px solid var(--gray-200);
        }
        
        .module-icon { font-size: 24px; }
        .module-name { font-weight: 500; font-size: 14px; }
        .module-tables { font-size: 11px; color: var(--gray-500); }
        .module-item.scannable { background: #f0fdf4; border-color: #86efac; }
        
        /* Spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--gray-300);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        /* Utilities */
        .hidden { display: none !important; }
        .text-center { text-align: center; }
        .text-muted { color: var(--gray-500); }
        .text-danger { color: var(--danger); }
        .text-sm { font-size: 13px; }
        .mt-4 { margin-top: 16px; }
        .mb-4 { margin-bottom: 16px; }
        .flex { display: flex; }
        .gap-2 { gap: 8px; }
        .gap-4 { gap: 16px; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { width: 100%; position: relative; }
            .main { margin-left: 0; }
            .content { padding: 20px; }
            .stats-grid { grid-template-columns: 1fr; }
            .login-box { padding: 32px 24px; }
        }
    </style>
</head>
<body>

<?php if (!$authenticated): ?>
<!-- Login Screen -->
<div class="login-wrapper">
    <div class="login-box">
        <div class="login-logo">
            <?php echo LOGO_SVG; ?>
        </div>
        <h1 class="login-title">Image Cleanup Tool</h1>
        <p class="login-subtitle">PrestaShop Image Management Utility</p>
        <p class="login-author">by <a href="https://simontodd.dev" target="_blank">Simon Todd</a></p>
        
        <div id="loginError" class="error-msg hidden"></div>
        
        <form id="loginForm">
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" id="loginPassword" class="form-input" placeholder="Enter password" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary btn-block" id="loginBtn">
                <span>Access Tool</span>
            </button>
        </form>
        
        <div class="security-notice">
            <strong>⚠️ Security Notice:</strong> Delete this file immediately after use. Never leave it on a production server.
        </div>
    </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('loginBtn');
    const err = document.getElementById('loginError');
    const pwd = document.getElementById('loginPassword').value;
    
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner"></div>';
    err.classList.add('hidden');
    
    try {
        const fd = new FormData();
        fd.append('action', 'login');
        fd.append('password', pwd);
        
        const r = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await r.json();
        
        if (data.success) {
            window.location.reload();
        } else {
            err.textContent = data.error || 'Login failed';
            err.classList.remove('hidden');
            btn.disabled = false;
            btn.innerHTML = '<span>Access Tool</span>';
        }
    } catch (error) {
        err.textContent = 'Connection error';
        err.classList.remove('hidden');
        btn.disabled = false;
        btn.innerHTML = '<span>Access Tool</span>';
    }
});
</script>

<?php else: ?>
<!-- Main Application -->
<div class="app">
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <div class="sidebar-logo-icon">
                    <?php echo LOGO_SVG; ?>
                </div>
                <div>
                    <div class="sidebar-logo-text">Image Cleanup</div>
                    <div class="sidebar-logo-version">v<?php echo TOOL_VERSION; ?></div>
                </div>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Overview</div>
                <div class="nav-item active" data-page="dashboard">
                    <span class="nav-item-icon">📊</span>
                    <span>Dashboard</span>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Analysis</div>
                <div class="nav-item" data-page="scan">
                    <span class="nav-item-icon">🔍</span>
                    <span>Scan Images</span>
                </div>
                <div class="nav-item" data-page="orphans">
                    <span class="nav-item-icon">🗑️</span>
                    <span>Orphans</span>
                    <span class="nav-item-badge" id="navOrphanCount">0</span>
                </div>
            </div>
            
            <div class="nav-section">
                <div class="nav-section-title">Actions</div>
                <div class="nav-item" data-page="cleanup">
                    <span class="nav-item-icon">✨</span>
                    <span>Cleanup</span>
                </div>
                <div class="nav-item" data-page="export">
                    <span class="nav-item-icon">📥</span>
                    <span>Export Report</span>
                </div>
            </div>
        </nav>
        
        <div class="sidebar-footer">
            <div class="sidebar-footer-brand">
                <a href="https://simontodd.dev" target="_blank">simontodd.dev</a> · 
                <a href="mailto:hello@simontodd.dev">hello@simontodd.dev</a>
            </div>
            <button class="btn btn-secondary btn-sm btn-block" onclick="logout()">
                Logout
            </button>
        </div>
    </aside>
    
    <!-- Main Content -->
    <main class="main">
        <div class="topbar">
            <div>
                <div class="topbar-title" id="pageTitle">Dashboard</div>
                <div class="topbar-subtitle" id="pageSubtitle">Overview of your PrestaShop installation</div>
            </div>
            <div id="shopInfo" class="text-sm text-muted"></div>
        </div>
        
        <div class="content">
            <!-- Dashboard Page -->
            <div id="page-dashboard" class="page-content">
                <div class="stats-grid" id="mainStats">
                    <div class="stat-card">
                        <div class="stat-icon info">📦</div>
                        <div class="stat-content">
                            <div class="stat-value" id="statProducts">-</div>
                            <div class="stat-label">Products</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon primary">🖼️</div>
                        <div class="stat-content">
                            <div class="stat-value" id="statImages">-</div>
                            <div class="stat-label">Product Images (DB)</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon success">📁</div>
                        <div class="stat-content">
                            <div class="stat-value" id="statTotalFiles">-</div>
                            <div class="stat-label">Total Files</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon warning">💾</div>
                        <div class="stat-content">
                            <div class="stat-value" id="statTotalSize">-</div>
                            <div class="stat-label">Total Size</div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">📁 Storage by Folder</h3>
                        <button class="btn btn-secondary btn-sm" onclick="loadDiskStats()">↻ Refresh</button>
                    </div>
                    <div class="card-body">
                        <table>
                            <thead>
                                <tr>
                                    <th>Folder</th>
                                    <th>Files</th>
                                    <th>Images</th>
                                    <th>Size</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="diskStatsTable">
                                <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🔌 Detected Modules</h3>
                    </div>
                    <div class="card-body">
                        <div id="modulesGrid" class="modules-grid">
                            <div class="text-muted">Loading...</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Scan Page -->
            <div id="page-scan" class="page-content hidden">
                <div class="alert alert-info">
                    <span class="alert-icon">ℹ️</span>
                    <div class="alert-content">
                        <div class="alert-title">How scanning works</div>
                        <div class="alert-text">The scanner checks database tables, theme files, and module content for image references, then compares against files on disk to identify orphans.</div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🔍 Scan Control</h3>
                    </div>
                    <div class="card-body">
                        <div id="scanControls">
                            <p class="mb-4">Click the button below to start scanning. The process runs in chunks so your browser won't freeze.</p>
                            <button class="btn btn-primary" onclick="startFullScan()" id="startScanBtn">
                                Start Full Scan
                            </button>
                        </div>
                        
                        <div id="scanRunning" class="hidden">
                            <div class="progress-container">
                                <div class="progress-header">
                                    <span class="progress-title" id="scanStage">Initializing...</span>
                                    <span class="progress-value" id="scanPercent">0%</span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill animated" id="scanProgressBar" style="width: 0%"></div>
                                </div>
                            </div>
                            
                            <div class="log-console mt-4" id="scanLog"></div>
                        </div>
                        
                        <div id="scanComplete" class="hidden mt-4">
                            <div class="alert alert-success">
                                <span class="alert-icon">✅</span>
                                <div class="alert-content">
                                    <div class="alert-title">Scan Complete!</div>
                                    <div class="alert-text">Found <strong id="scanRefCount">0</strong> references and <strong id="scanOrphanCount">0</strong> orphaned files (<span id="scanOrphanSize">0 B</span> reclaimable).</div>
                                </div>
                            </div>
                            
                            <div class="flex gap-2">
                                <button class="btn btn-secondary" onclick="navigateTo('orphans')">View Orphans</button>
                                <button class="btn btn-secondary" onclick="startFullScan()">Run Again</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Orphans Page -->
            <div id="page-orphans" class="page-content hidden">
                <div id="orphansContent">
                    <div class="alert alert-warning">
                        <span class="alert-icon">⚠️</span>
                        <div class="alert-content">
                            <div class="alert-title">No scan data</div>
                            <div class="alert-text">Run a scan first to identify orphaned files.</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Cleanup Page -->
            <div id="page-cleanup" class="page-content hidden">
                <div class="alert alert-danger mb-4">
                    <span class="alert-icon">⚠️</span>
                    <div class="alert-content">
                        <div class="alert-title">Caution: Destructive Operations</div>
                        <div class="alert-text">Deleting files is permanent. Always use "Move to Backup" first so you can restore files if needed.</div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">🗑️ Temporary Files</h3>
                        <span class="badge badge-warning" id="tempBadge">-</span>
                    </div>
                    <div class="card-body">
                        <p class="mb-4">Temporary files in <code>/img/tmp</code> are always safe to delete. These are generated during image processing.</p>
                        <button class="btn btn-danger btn-sm" onclick="confirmCleanTemp()" id="cleanTempBtn">
                            Clean Temporary Files
                        </button>
                        <div id="tempResult" class="mt-4"></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">📁 Orphan Cleanup</h3>
                    </div>
                    <div class="card-body" id="cleanupContent">
                        <p>Run a scan first to identify orphaned files that can be cleaned up.</p>
                    </div>
                </div>
            </div>
            
            <!-- Export Page -->
            <div id="page-export" class="page-content hidden">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">📥 Export Report</h3>
                    </div>
                    <div class="card-body">
                        <p class="mb-4">Export a detailed report of the scan results for documentation.</p>
                        
                        <div class="flex gap-2">
                            <button class="btn btn-secondary" onclick="exportReport('json')">Export as JSON</button>
                            <button class="btn btn-secondary" onclick="exportReport('csv')">Export as CSV</button>
                        </div>
                        
                        <div id="exportResult" class="mt-4"></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">💼 Need Professional Help?</h3>
                    </div>
                    <div class="card-body">
                        <p>For professional PrestaShop development, optimization, and support:</p>
                        <ul style="margin: 16px 0; padding-left: 20px;">
                            <li><strong>Website:</strong> <a href="https://simontodd.dev" target="_blank" style="color: var(--primary);">simontodd.dev</a></li>
                            <li><strong>Email:</strong> <a href="mailto:hello@simontodd.dev" style="color: var(--primary);">hello@simontodd.dev</a></li>
                            <li><strong>GitHub:</strong> <a href="https://github.com/simontodd" target="_blank" style="color: var(--primary);">github.com/simontodd</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Disclaimer Modal -->
<div class="modal-overlay" id="disclaimerModal">
    <div class="modal" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title">⚠️ Important: Read Before Use</h3>
        </div>
        <div class="modal-body">
            <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: 12px; padding: 20px; max-height: 300px; overflow-y: auto; font-size: 13px; line-height: 1.7; margin-bottom: 20px;">
                <h4 style="margin: 0 0 8px; color: var(--danger);">⚠️ DISCLAIMER</h4>
                <p>This tool is provided "AS IS" without warranty of any kind. The author accepts no responsibility for any data loss, damage, or other issues caused by using this tool.</p>
                
                <h4 style="margin: 16px 0 8px; color: var(--danger);">⚠️ BEFORE YOU BEGIN</h4>
                <ul style="margin-left: 20px;">
                    <li><strong>BACKUP YOUR FILES:</strong> Create a full backup of your /img and /upload folders</li>
                    <li><strong>BACKUP YOUR DATABASE:</strong> Export a full database backup</li>
                    <li><strong>TEST ENVIRONMENT:</strong> Consider testing on a staging site first</li>
                </ul>
                
                <h4 style="margin: 16px 0 8px; color: var(--danger);">⚠️ LIMITATIONS</h4>
                <ul style="margin-left: 20px;">
                    <li>Third-party modules may store image references in ways this tool cannot detect</li>
                    <li>Custom templates or hardcoded paths may not be identified</li>
                    <li>Always review orphan lists carefully before deleting</li>
                    <li>Use the "Backup" option before permanent deletion</li>
                </ul>
                
                <h4 style="margin: 16px 0 8px; color: var(--danger);">⚠️ SECURITY</h4>
                <ul style="margin-left: 20px;">
                    <li><strong>DELETE THIS FILE</strong> immediately after use</li>
                    <li>Never leave this tool accessible on a production server</li>
                    <li>Change the default password before uploading</li>
                </ul>
            </div>
            
            <div style="display: flex; align-items: flex-start; gap: 12px; margin-bottom: 20px;">
                <input type="checkbox" id="acceptDisclaimer" style="width: 20px; height: 20px; margin-top: 2px; accent-color: var(--primary);">
                <label for="acceptDisclaimer" style="font-size: 14px; cursor: pointer;">I understand the risks and have backed up my files and database. I accept full responsibility for any consequences of using this tool.</label>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="acceptDisclaimerBtn" disabled onclick="acceptDisclaimer()">I Understand, Continue</button>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Confirm</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button class="btn btn-danger" id="modalConfirm">Confirm</button>
        </div>
    </div>
</div>

<script>
// ========================================
// STATE
// ========================================
const state = {
    currentPage: 'dashboard',
    scanComplete: false,
    disclaimerAccepted: false,
};

// ========================================
// API
// ========================================
async function api(action, data = {}) {
    const fd = new FormData();
    fd.append('action', action);
    for (const [k, v] of Object.entries(data)) {
        fd.append(k, v);
    }
    
    try {
        const response = await fetch(window.location.href, { method: 'POST', body: fd });
        const text = await response.text();
        
        // Try to parse as JSON
        try {
            return JSON.parse(text);
        } catch (e) {
            // Not valid JSON - likely an HTML error page
            console.error('Non-JSON response:', text.substring(0, 500));
            
            // Try to extract error message from HTML
            let errorMsg = 'Server returned non-JSON response';
            if (text.includes('Fatal error')) {
                const match = text.match(/Fatal error[^<]*/i);
                if (match) errorMsg = match[0].substring(0, 200);
            } else if (text.includes('memory')) {
                errorMsg = 'Memory limit exceeded - try increasing PHP memory_limit';
            } else if (text.includes('Maximum execution')) {
                errorMsg = 'Execution timeout - try increasing max_execution_time';
            }
            
            return { error: errorMsg, recoverable: true };
        }
    } catch (e) {
        return { error: 'Network error: ' + e.message, recoverable: true };
    }
}

// ========================================
// NAVIGATION
// ========================================
const pageTitles = {
    dashboard: ['Dashboard', 'Overview of your PrestaShop installation'],
    scan: ['Scan Images', 'Find orphaned images in your store'],
    orphans: ['Orphaned Files', 'Files not referenced anywhere'],
    cleanup: ['Cleanup', 'Remove orphaned and temporary files'],
    export: ['Export Report', 'Download scan results'],
};

function navigateTo(page) {
    if (!state.disclaimerAccepted) return;
    
    state.currentPage = page;
    
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.toggle('active', item.dataset.page === page);
    });
    
    document.querySelectorAll('.page-content').forEach(p => {
        p.classList.add('hidden');
    });
    document.getElementById(`page-${page}`).classList.remove('hidden');
    
    const [title, subtitle] = pageTitles[page] || [page, ''];
    document.getElementById('pageTitle').textContent = title;
    document.getElementById('pageSubtitle').textContent = subtitle;
    
    if (page === 'orphans') loadOrphansPage();
    if (page === 'cleanup') loadCleanupPage();
}

document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', () => navigateTo(item.dataset.page));
});

// ========================================
// DASHBOARD
// ========================================
async function loadDashboard() {
    const info = await api('getSystemInfo');
    
    if (info.success) {
        document.getElementById('shopInfo').textContent = `${info.shopName} | PS ${info.psVersion}`;
        document.getElementById('statProducts').textContent = (info.productCount || 0).toLocaleString();
        document.getElementById('statImages').textContent = (info.imageCount || 0).toLocaleString();
    }
    
    await loadDiskStats();
    await loadModules();
}

async function loadDiskStats() {
    const result = await api('getDiskStats');
    
    if (result.success) {
        let html = '';
        for (const stat of result.stats) {
            const statusBadge = stat.cleanable 
                ? '<span class="badge badge-warning">Cleanable</span>'
                : '<span class="badge badge-success">Active</span>';
            
            html += `<tr>
                <td><span style="margin-right: 8px;">${stat.icon}</span> <code>${stat.path}</code></td>
                <td>${stat.files.toLocaleString()}</td>
                <td>${stat.images.toLocaleString()}</td>
                <td>${stat.sizeFormatted}</td>
                <td>${statusBadge}</td>
            </tr>`;
        }
        
        document.getElementById('diskStatsTable').innerHTML = html;
        document.getElementById('statTotalFiles').textContent = result.totals.files.toLocaleString();
        document.getElementById('statTotalSize').textContent = result.totals.sizeFormatted;
        
        const tmpStat = result.stats.find(s => s.path === 'img/tmp');
        if (tmpStat) {
            document.getElementById('tempBadge').textContent = `${tmpStat.files.toLocaleString()} files (${tmpStat.sizeFormatted})`;
        }
    }
}

async function loadModules() {
    const result = await api('detectModules');
    
    if (result.success) {
        let html = '';
        const scannableModules = result.modules.filter(m => m.scannable);
        const otherModules = result.modules.filter(m => !m.scannable);
        
        // Show header with counts
        html += `<div class="mb-4 text-sm text-muted">
            Found ${result.totalModules} modules, ${result.scannableModules} with scannable content
        </div>`;
        
        // Show scannable modules first
        for (const mod of scannableModules) {
            const details = [];
            if (mod.tables > 0) details.push(`${mod.tables} tables`);
            if (mod.hasTemplates) details.push('templates');
            
            html += `<div class="module-item scannable">
                <span class="module-icon">${mod.icon}</span>
                <div class="module-info">
                    <div class="module-name">${mod.name}</div>
                    <div class="module-tables">${details.join(', ')}</div>
                </div>
                <span class="badge badge-success">Scanned</span>
            </div>`;
        }
        
        // Show toggle for other modules
        if (otherModules.length > 0) {
            html += `<div class="mt-4 mb-2">
                <button class="btn btn-sm btn-secondary" onclick="document.getElementById('otherModules').classList.toggle('hidden')">
                    Show ${otherModules.length} other modules
                </button>
            </div>`;
            html += `<div id="otherModules" class="hidden">`;
            for (const mod of otherModules.slice(0, 20)) {
                html += `<div class="module-item">
                    <span class="module-icon">${mod.icon}</span>
                    <div class="module-info">
                        <div class="module-name">${mod.name}</div>
                        <div class="module-tables text-muted">No image content</div>
                    </div>
                    <span class="badge badge-gray">—</span>
                </div>`;
            }
            if (otherModules.length > 20) {
                html += `<div class="text-muted text-sm p-2">...and ${otherModules.length - 20} more</div>`;
            }
            html += `</div>`;
        }
        
        document.getElementById('modulesGrid').innerHTML = html;
    }
}

// ========================================
// SCANNING
// ========================================
let scanLogEntries = [];

function addScanLog(message, type = 'info') {
    const time = new Date().toLocaleTimeString();
    const icons = { success: '✓', error: '✗', warning: '⚠', info: '→' };
    
    scanLogEntries.push({ time, message, type, icon: icons[type] || '•' });
    
    const logHtml = scanLogEntries.slice(-100).map(entry => `
        <div class="log-entry ${entry.type}">
            <span class="log-time">${entry.time}</span>
            <span class="log-icon">${entry.icon}</span>
            <span class="log-message">${entry.message}</span>
        </div>
    `).join('');
    
    const logEl = document.getElementById('scanLog');
    logEl.innerHTML = logHtml;
    logEl.scrollTop = logEl.scrollHeight;
}

function updateScanProgress(percent, stage) {
    document.getElementById('scanProgressBar').style.width = `${percent}%`;
    document.getElementById('scanPercent').textContent = `${Math.round(percent)}%`;
    if (stage) document.getElementById('scanStage').textContent = stage;
}

async function startFullScan() {
    const btn = document.getElementById('startScanBtn');
    btn.disabled = true;
    
    document.getElementById('scanControls').classList.add('hidden');
    document.getElementById('scanRunning').classList.remove('hidden');
    document.getElementById('scanComplete').classList.add('hidden');
    
    scanLogEntries = [];
    addScanLog('Initializing scan...', 'info');
    
    let totalRefs = 0;
    let totalOrphans = 0;
    let totalOrphanSize = 0;
    
    try {
        await api('initScan');
        addScanLog('Scan initialized', 'success');
        
        // Scan database
        updateScanProgress(5, 'Scanning database tables...');
        let dbChunk = 0;
        let dbComplete = false;
        let dbErrorCount = 0;
        const maxDbErrors = 3;
        
        while (!dbComplete) {
            try {
                const result = await api('scanDatabaseChunk', { chunk: dbChunk });
                
                if (result.error) {
                    dbErrorCount++;
                    addScanLog(`Chunk ${dbChunk} error: ${result.error}`, 'error');
                    
                    // If recoverable, try next chunk
                    if (result.recoverable !== false && dbErrorCount < maxDbErrors) {
                        addScanLog(`Skipping to next chunk (${dbErrorCount}/${maxDbErrors} errors)...`, 'warning');
                        dbChunk++;
                        continue;
                    }
                    
                    // Too many errors, stop scanning
                    addScanLog('Too many errors, stopping database scan', 'error');
                    break;
                }
                
                dbErrorCount = 0; // Reset on success
                
                for (const log of result.log || []) {
                    if (log.type === 'success') {
                        addScanLog(`${log.source}: ${log.rows} rows, ${log.refs} refs`, 'success');
                    } else if (log.type === 'warning') {
                        addScanLog(`${log.source}: ${log.message}`, 'warning');
                    } else if (log.type === 'skip') {
                        addScanLog(`${log.source}: ${log.reason}`, 'info');
                    } else if (log.type === 'error') {
                        addScanLog(`${log.source}: ${log.error}`, 'error');
                    }
                }
                
                dbComplete = result.complete;
                if (!dbComplete) {
                    dbChunk++;
                    updateScanProgress(5 + (result.progress * 0.2), `Scanning database (${result.progress}%)...`);
                }
                
                totalRefs = result.totalRefs || totalRefs;
            } catch (e) {
                dbErrorCount++;
                addScanLog(`Network/parse error on chunk ${dbChunk}: ${e.message}`, 'error');
                
                if (dbErrorCount < maxDbErrors) {
                    addScanLog(`Retrying chunk ${dbChunk}...`, 'warning');
                    await new Promise(r => setTimeout(r, 1000)); // Wait 1 second
                    continue;
                }
                
                addScanLog('Too many errors, stopping database scan', 'error');
                break;
            }
        }
        
        addScanLog(`Database scan complete: ${totalRefs} references found`, 'success');
        
        // Scan themes
        updateScanProgress(25, 'Scanning theme files...');
        addScanLog('Scanning theme and module template files...', 'info');
        
        const themeResult = await api('scanThemes');
        for (const theme of themeResult.themes || []) {
            addScanLog(`Theme "${theme.theme}": ${theme.files} files, ${theme.refs} refs`, 'success');
        }
        totalRefs = themeResult.totalRefs || totalRefs;
        
        // Scan folders for orphaned images
        // Note: img/p (product images) is scanned separately with DB validation
        const foldersToScan = [
            'img/cms',      // CMS page images
            'upload',       // General uploads
            'img/co',       // Contact images (if exists)
            'img/l',        // Logo/Lang images (if exists)
        ];
        let folderProgress = 35;
        
        for (const folder of foldersToScan) {
            updateScanProgress(folderProgress, `Scanning ${folder}...`);
            addScanLog(`Scanning ${folder}...`, 'info');
            
            let offset = 0;
            let complete = false;
            let folderOrphans = 0;
            let folderSize = '';
            
            while (!complete) {
                const result = await api('scanFolderChunk', { folder, offset });
                
                if (result.error) {
                    addScanLog(`Error scanning ${folder}: ${result.error}`, 'error');
                    break;
                }
                
                offset = result.processed;
                complete = result.complete;
                folderOrphans = result.totalOrphans;
                folderSize = result.orphanSizeFormatted;
                
                updateScanProgress(folderProgress + (result.progress * 0.05), `Scanning ${folder} (${result.progress}%)...`);
            }
            
            if (folderOrphans > 0) {
                addScanLog(`${folder}: ${folderOrphans} orphans (${folderSize})`, 'warning');
                totalOrphans += folderOrphans;
            } else {
                addScanLog(`${folder}: No orphans found`, 'success');
            }
            
            folderProgress += 5;
        }
        
        // Scan product images
        updateScanProgress(55, 'Scanning product images...');
        addScanLog('Scanning product images (this may take a while)...', 'info');
        
        let productOffset = 0;
        let productComplete = false;
        let productOrphans = 0;
        let productSize = '';
        
        while (!productComplete) {
            const result = await api('scanProductImages', { offset: productOffset });
            
            if (result.error) {
                addScanLog(`Product images: ${result.error}`, 'warning');
                break;
            }
            
            productOffset = result.processed;
            productComplete = result.complete;
            productOrphans = result.totalOrphans;
            productSize = result.orphanSizeFormatted;
            
            updateScanProgress(55 + (result.progress * 0.35), `Scanning product images (${result.progress}%)...`);
        }
        
        if (productOrphans > 0) {
            addScanLog(`Product images: ${productOrphans} orphans (${productSize})`, 'warning');
            totalOrphans += productOrphans;
        } else {
            addScanLog(`Product images: No orphans found`, 'success');
        }
        
        // Scan entity images (category, manufacturer, supplier, store)
        const entitiesToScan = [
            { type: 'category', label: 'Category images', folder: 'img/c' },
            { type: 'manufacturer', label: 'Manufacturer images', folder: 'img/m' },
            { type: 'supplier', label: 'Supplier images', folder: 'img/su' },
            { type: 'store', label: 'Store images', folder: 'img/st' },
        ];
        
        let entityProgress = 90;
        for (const entity of entitiesToScan) {
            updateScanProgress(entityProgress, `Scanning ${entity.label}...`);
            
            let entityOffset = 0;
            let entityComplete = false;
            let entityOrphans = 0;
            let entitySize = '';
            
            while (!entityComplete) {
                const result = await api('scanEntityImages', { entity: entity.type, offset: entityOffset });
                
                if (result.error || result.skipped) {
                    if (!result.skipped) {
                        addScanLog(`${entity.label}: ${result.error || 'skipped'}`, 'info');
                    }
                    break;
                }
                
                entityOffset = result.processed;
                entityComplete = result.complete;
                entityOrphans = result.totalOrphans;
                entitySize = result.orphanSizeFormatted;
            }
            
            if (entityOrphans > 0) {
                addScanLog(`${entity.label}: ${entityOrphans} orphans (${entitySize})`, 'warning');
                totalOrphans += entityOrphans;
            } else if (entityComplete) {
                addScanLog(`${entity.label}: No orphans found`, 'success');
            }
            
            entityProgress += 2;
        }
        
        // Complete
        updateScanProgress(100, 'Scan complete!');
        addScanLog('Scan complete!', 'success');
        
        state.scanComplete = true;
        
        const finalResults = await api('getScanResults');
        
        document.getElementById('scanRefCount').textContent = finalResults.referencesCount?.toLocaleString() || totalRefs.toLocaleString();
        document.getElementById('scanOrphanCount').textContent = finalResults.stats?.orphansFound?.toLocaleString() || totalOrphans.toLocaleString();
        document.getElementById('scanOrphanSize').textContent = formatBytes(finalResults.stats?.orphanSize || totalOrphanSize);
        
        document.getElementById('navOrphanCount').textContent = finalResults.stats?.orphansFound || totalOrphans;
        
        document.getElementById('scanRunning').classList.add('hidden');
        document.getElementById('scanComplete').classList.remove('hidden');
        
    } catch (error) {
        addScanLog(`Fatal error: ${error.message}`, 'error');
    }
    
    btn.disabled = false;
    document.getElementById('scanControls').classList.remove('hidden');
}

// ========================================
// ORPHANS PAGE
// ========================================
async function loadOrphansPage() {
    if (!state.scanComplete) {
        document.getElementById('orphansContent').innerHTML = `
            <div class="alert alert-warning">
                <span class="alert-icon">⚠️</span>
                <div class="alert-content">
                    <div class="alert-title">No scan data</div>
                    <div class="alert-text">Run a scan first to identify orphaned files.</div>
                </div>
            </div>
        `;
        return;
    }
    
    const result = await api('getOrphans');
    
    if (!result.success || result.totalCount === 0) {
        document.getElementById('orphansContent').innerHTML = `
            <div class="alert alert-success">
                <span class="alert-icon">✅</span>
                <div class="alert-content">
                    <div class="alert-title">No orphans found!</div>
                    <div class="alert-text">All image files are properly referenced.</div>
                </div>
            </div>
        `;
        return;
    }
    
    let html = `
        <div class="stats-grid mb-4">
            <div class="stat-card">
                <div class="stat-icon danger">🗑️</div>
                <div class="stat-content">
                    <div class="stat-value">${result.totalCount.toLocaleString()}</div>
                    <div class="stat-label">Orphaned Files</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon primary">💾</div>
                <div class="stat-content">
                    <div class="stat-value">${result.totalSizeFormatted}</div>
                    <div class="stat-label">Reclaimable Space</div>
                </div>
            </div>
        </div>
    `;
    
    for (const item of result.summary) {
        const folderResult = await api('getOrphans', { folder: item.folder, page: 1, perPage: 20 });
        
        html += `
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">📁 ${item.folder}</h3>
                    <span class="badge badge-danger">${item.count} files (${item.sizeFormatted})</span>
                </div>
                <div class="card-body">
                    <div class="file-list">
        `;
        
        for (const file of (folderResult.files || []).slice(0, 20)) {
            html += `
                <div class="file-item">
                    <span>📄</span>
                    <div class="file-info">
                        <div class="file-path">${file.path}</div>
                        <div class="file-meta">${file.sizeFormatted}</div>
                    </div>
                </div>
            `;
        }
        
        if (item.count > 20) {
            html += `<div class="file-item text-center text-muted">... and ${item.count - 20} more files</div>`;
        }
        
        html += `</div></div></div>`;
    }
    
    document.getElementById('orphansContent').innerHTML = html;
}

// ========================================
// CLEANUP PAGE
// ========================================
async function loadCleanupPage() {
    if (!state.scanComplete) {
        document.getElementById('cleanupContent').innerHTML = '<p>Run a scan first to identify orphaned files.</p>';
        return;
    }
    
    const result = await api('getOrphans');
    
    if (!result.success || result.totalCount === 0) {
        document.getElementById('cleanupContent').innerHTML = `
            <div class="alert alert-success">
                <span class="alert-icon">✅</span>
                <p>No orphaned files to clean up!</p>
            </div>
        `;
        return;
    }
    
    let html = `
        <div class="alert alert-warning mb-4">
            <span class="alert-icon">⚠️</span>
            <div class="alert-content">
                <div class="alert-title">Review before cleaning</div>
                <div class="alert-text">Found ${result.totalCount.toLocaleString()} orphaned files (${result.totalSizeFormatted}). Use "Move to Backup" to safely remove files with the option to restore.</div>
            </div>
        </div>
    `;
    
    for (const item of result.summary) {
        html += `
            <div class="card mb-4">
                <div class="card-header">
                    <div>
                        <h3 class="card-title">📁 ${item.folder}</h3>
                        <div class="text-sm text-muted">${item.count} files (${item.sizeFormatted})</div>
                    </div>
                    <div class="flex gap-2">
                        <button class="btn btn-secondary btn-sm" onclick="cleanupFolder('${item.folder}', 'backup')">
                            Move to Backup
                        </button>
                        <button class="btn btn-danger btn-sm" onclick="confirmDelete('${item.folder}')">
                            Delete Permanently
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    document.getElementById('cleanupContent').innerHTML = html;
}

function confirmCleanTemp() {
    document.getElementById('modalTitle').textContent = 'Clean Temporary Files';
    document.getElementById('modalBody').innerHTML = `
        <p>Are you sure you want to delete all temporary files in <code>/img/tmp</code>?</p>
        <p class="text-muted mt-2">This is generally safe and frees up disk space used during image processing.</p>
    `;
    document.getElementById('modalConfirm').onclick = () => {
        closeModal();
        cleanTempFiles();
    };
    document.getElementById('confirmModal').classList.add('active');
}

async function cleanTempFiles() {
    const btn = document.getElementById('cleanTempBtn');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner"></div> Cleaning...';
    
    const result = await api('cleanTemp');
    
    document.getElementById('tempResult').innerHTML = result.success
        ? `<div class="alert alert-success"><span class="alert-icon">✅</span><div class="alert-content"><div class="alert-title">Cleaned!</div><div class="alert-text">Deleted ${result.deleted.toLocaleString()} files, freed ${result.freedSizeFormatted}</div></div></div>`
        : `<div class="alert alert-danger">Error cleaning temp files</div>`;
    
    btn.disabled = false;
    btn.innerHTML = 'Clean Temporary Files';
    
    await loadDiskStats();
}

async function cleanupFolder(folder, mode) {
    const btn = event.target;
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<div class="spinner"></div>';
    
    const result = await api('deleteOrphans', { folder, mode });
    
    if (result.success) {
        const msg = mode === 'backup' 
            ? `Moved ${result.deleted} files to ${result.backupPath}`
            : `Deleted ${result.deleted} files`;
        alert(`Success! ${msg}. Freed ${result.freedSizeFormatted}`);
        await loadCleanupPage();
        await loadDiskStats();
        
        const orphans = await api('getOrphans');
        document.getElementById('navOrphanCount').textContent = orphans.totalCount || 0;
    } else {
        alert('Error: ' + (result.error || 'Unknown error'));
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

function confirmDelete(folder) {
    document.getElementById('modalTitle').textContent = 'Confirm Permanent Delete';
    document.getElementById('modalBody').innerHTML = `
        <p>Are you sure you want to <strong>permanently delete</strong> all orphaned files in <code>${folder}</code>?</p>
        <p class="text-danger mt-4"><strong>⚠️ This action cannot be undone!</strong></p>
        <p class="mt-4">Consider using "Move to Backup" instead so you can restore files if needed.</p>
    `;
    document.getElementById('modalConfirm').onclick = () => {
        closeModal();
        cleanupFolder(folder, 'delete');
    };
    document.getElementById('confirmModal').classList.add('active');
}

function closeModal() {
    document.getElementById('confirmModal').classList.remove('active');
}

// ========================================
// EXPORT
// ========================================
async function exportReport(format) {
    const result = await api('exportReport', { format });
    
    if (!result.success) {
        document.getElementById('exportResult').innerHTML = `<div class="alert alert-danger">Error: ${result.error}</div>`;
        return;
    }
    
    const content = format === 'json' ? JSON.stringify(result.data, null, 2) : result.data;
    const blob = new Blob([content], { type: format === 'json' ? 'application/json' : 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `prestashop-cleanup-report.${format}`;
    a.click();
    URL.revokeObjectURL(url);
    
    document.getElementById('exportResult').innerHTML = `<div class="alert alert-success"><span class="alert-icon">✅</span><p>Report downloaded successfully!</p></div>`;
}

// ========================================
// UTILITIES
// ========================================
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

async function logout() {
    await api('logout');
    window.location.reload();
}

// ========================================
// INIT
// ========================================
async function init() {
    const info = await api('getSystemInfo');
    state.disclaimerAccepted = info.disclaimerAccepted || false;
    
    if (!state.disclaimerAccepted) {
        document.getElementById('disclaimerModal').classList.add('active');
    }
    loadDashboard();
}

document.getElementById('acceptDisclaimer').addEventListener('change', function() {
    document.getElementById('acceptDisclaimerBtn').disabled = !this.checked;
});

async function acceptDisclaimer() {
    await api('acceptDisclaimer');
    state.disclaimerAccepted = true;
    document.getElementById('disclaimerModal').classList.remove('active');
}

init();
</script>
<?php endif; ?>

</body>
</html>
