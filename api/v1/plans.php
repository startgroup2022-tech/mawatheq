<?php
/**
 * GET /api/v1/plans.php  → الخطط المنشورة (نفس بيانات الموقع).
 * لا يحتاج مصادقة (تصفح عام، مثل pricing.php تماماً).
 * response: { ok, plans: [...] }
 *
 * يقرأ من جدول plans المُصدَّر في schema.sql — لا توجد أسعار مكتوبة في التطبيق.
 */
require_once __DIR__ . '/bootstrap.php';

$plans = $pdo->query(
    "SELECT id, name_ar, name_en, description_ar, description_en,
            price_monthly, price_yearly, ai_queries_limit,
            document_analysis_limit, consultations_included,
            is_corporate, is_featured, sort_order
     FROM plans WHERE status='active' ORDER BY sort_order, price_monthly"
)->fetchAll();

api_json(['ok' => true, 'plans' => $plans]);
