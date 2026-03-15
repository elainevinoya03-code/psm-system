<?php

// ── WEB BASE PATH (for CSS, JS, assets) ──────────────────────────────
// Set to '' if your doc root is Log1 (e.g. php -S localhost:8000 from Log1 folder).
// Set to '/Log1' if your doc root is htdocs (e.g. XAMPP Apache, URL: localhost/Log1/...).
if (!defined('LOG1_WEB_BASE')) {
    define('LOG1_WEB_BASE', '');
}

// ── SUPABASE (HTTP) CONFIG ─────────────────────────────────────────

define('SUPABASE_URL', 'https://fnpxtquhvlflyjibuwlx.supabase.co');
define('SUPABASE_ANON_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZucHh0cXVodmxmbHlqaWJ1d2x4Iiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzM0NTE1OTMsImV4cCI6MjA4OTAyNzU5M30.KZaOgxA4hPEYpfunOg1HGyjKSb5lXNlUHWNXdYJqHdE');
define('SUPABASE_SERVICE_ROLE_KEY', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImZucHh0cXVodmxmbHlqaWJ1d2x4Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc3MzQ1MTU5MywiZXhwIjoyMDg5MDI3NTkzfQ.oEVViZBSr-WFCLmBwazQLGvPNg8M0IByN4Iz5vCIym0');

// Helper function to make Supabase API requests
function supabase_request($endpoint, $method = 'GET', $data = null, $useServiceRole = false) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $key = $useServiceRole ? SUPABASE_SERVICE_ROLE_KEY : SUPABASE_ANON_KEY;

    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// ── POSTGRESQL + PDO CONFIG (used by modules like PSM) ─────────────
// Project  : micro_finance_logistic
// Region   : Northeast Asia (Tokyo) -> ap-northeast-1
// Pooler   : aws-1-ap-northeast-1.pooler.supabase.com (NOT aws-0)
// Mode     : Session pooler, port 5432, IPv4 compatible
// User     : postgres.fnpxtquhvlflyjibuwlx (required for pooler)

if (!defined('PG_DSN')) {
    define('PG_DSN', 'pgsql:host=aws-1-ap-northeast-1.pooler.supabase.com;port=5432;dbname=postgres;sslmode=require');
}

if (!defined('PG_DB_USER')) {
    define('PG_DB_USER', 'postgres.fnpxtquhvlflyjibuwlx');
}

if (!defined('PG_DB_PASSWORD')) {
    define('PG_DB_PASSWORD', '0ltvCJjD0CkZoBpX');
}

// ── SMTP / EMAIL CONFIG (used by PSM send PO email) ───────────────
// Supports PHPMailer (if installed via composer) or PHP mail() fallback.
// To install PHPMailer: composer require phpmailer/phpmailer
//
// Gmail example:
//   SMTP_HOST     = 'smtp.gmail.com'
//   SMTP_USER     = 'your@gmail.com'
//   SMTP_PASS     = 'your-app-password'   ← use App Password, not account password
//   SMTP_PORT     = 587
//   SMTP_SECURE   = 'tls'
//
// Outlook / Office 365 example:
//   SMTP_HOST     = 'smtp.office365.com'
//   SMTP_PORT     = 587
//   SMTP_SECURE   = 'tls'

if (!defined('SMTP_HOST'))      define('SMTP_HOST',      'smtp.gmail.com');
if (!defined('SMTP_USER'))      define('SMTP_USER',      'noreply.microfinancial@gmail.com');
if (!defined('SMTP_PASS'))      define('SMTP_PASS',      'dpjdwwlopkzdyfnk');
if (!defined('SMTP_PORT'))      define('SMTP_PORT',      587);
if (!defined('SMTP_SECURE'))    define('SMTP_SECURE',    'tls');
if (!defined('MAIL_FROM'))      define('MAIL_FROM',      'noreply.microfinancial@gmail.com');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'MicroFinancial');

// ── GROQ AI CONFIG (used by DTRS document metadata extraction) ────────────────
// Free tier: 14,400 requests/day, no billing required.
// Get your API key from: https://console.groq.com/keys

define('GROQ_API_KEY',       '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');                    // ← paste your gsk_... key here
define('GROQ_EXTRACT_MODEL', 'llama-3.1-8b-instant'); // free, very fast


?>