<?php
define('PG_DSN', 'pgsql:host=aws-1-ap-northeast-1.pooler.supabase.com;port=5432;dbname=postgres;sslmode=require');
define('PG_DB_USER', 'postgres.fnpxtquhvlflyjibuwlx');
define('PG_DB_PASSWORD', '0ltvCJjD0CkZoBpX');

try {
    $pdo = new PDO(PG_DSN, PG_DB_USER, PG_DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("ALTER TABLE public.users ADD COLUMN IF NOT EXISTS failed_attempts INT DEFAULT 0");
    echo "Column added.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}