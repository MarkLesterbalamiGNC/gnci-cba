<?php
/**
 * BioTrack AI Assistant settings (admin only).
 *
 * Pick ONE provider below.
 *   'gemini' = Google Gemini  -> has a FREE tier (no credit card)   <- default
 *   'claude' = Anthropic Claude -> paid, pay-per-use
 *
 * Keep this file private - never share it or post it in screenshots.
 * The assistant needs internet on the XAMPP PC. It only READS data: it cannot
 * add, edit or delete anything.
 */
// Private values (your real key) live in config/ai.local.php - it is not uploaded to GitHub.
if (is_file(__DIR__ . '/ai.local.php')) require_once __DIR__ . '/ai.local.php';

defined('AI_PROVIDER') || define('AI_PROVIDER', 'gemini');

// ---- Google Gemini (free) ----------------------------------------------
// Get a free key: https://aistudio.google.com  ->  "Get API key"  ->  Create API key
defined('AI_GEMINI_KEY') || define('AI_GEMINI_KEY', '');                    // <-- paste your Google key here
defined('AI_GEMINI_MODEL') || define('AI_GEMINI_MODEL', 'gemini-3.1-flash-lite');   // if Google retires it, the site finds a current Flash model by itself

// ---- Anthropic Claude (paid) -------------------------------------------
// Get a key: https://console.anthropic.com  ->  API Keys
defined('AI_API_KEY') || define('AI_API_KEY', '');                       // 'sk-ant-...'
defined('AI_MODEL') || define('AI_MODEL', 'claude-sonnet-5-5');        // cheaper option: 'claude-haiku-5-5'

// ---- Both ----------------------------------------------------------------
defined('AI_MAX_TOKENS') || define('AI_MAX_TOKENS', 1024);                  // longest reply the assistant may write
defined('AI_TIMEOUT') || define('AI_TIMEOUT', 60);                       // seconds to wait for a reply
