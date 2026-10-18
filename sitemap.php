<?php
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
$base=rtrim(SITE_URL,'/');
$urls=['/','/pricing.php','/laws.php','/knowledge.php','/join-lawyer.php','/contact.php','/privacy.php','/terms.php'];
try { foreach($pdo->query("SELECT slug FROM articles WHERE status='published'")->fetchAll() as $article) $urls[]='/article.php?slug='.rawurlencode($article['slug']); }
catch(PDOException $e) { error_log('Sitemap articles unavailable: '.$e->getMessage()); }
try { foreach($pdo->query('SELECT id FROM law_articles')->fetchAll() as $law) $urls[]='/laws.php?article='.(int)$law['id']; }
catch(PDOException $e) { error_log('Sitemap laws unavailable: '.$e->getMessage()); }
// Published law-firm public sites (v50). Only firms that opted in are listed.
try { foreach($pdo->query("SELECT public_slug FROM law_firms WHERE is_public=1 AND public_slug IS NOT NULL AND public_slug<>''")->fetchAll() as $firm) $urls[]='/firm.php?firm='.rawurlencode($firm['public_slug']); }
catch(PDOException $e) { error_log('Sitemap firms unavailable: '.$e->getMessage()); }
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach(array_unique($urls) as $path) echo '<url><loc>'.htmlspecialchars($base.$path,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>' . "\n";
echo '</urlset>';
