<?php
/**
 * Plan (package) input normalisation and validation — shared by the admin console.
 *
 * The plan form used to accept any combination of classification and price, so an
 * operator could save a "free" package with a 149 SAR monthly price, or a "paid"
 * package at zero. Every surface that reads `plans.classification` (packages.php,
 * checkout.php, the mobile plans endpoint) then mislabels the package. The rules live
 * here, not inline in the page, so they are one source and can be tested directly.
 */

/**
 * Normalise a posted plan payload into exactly the columns the plans table stores.
 *
 * Values are clamped to the enum/typed domain the schema accepts, so a tampered POST
 * degrades to a safe default instead of failing the INSERT.
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function plan_normalize_input(array $post): array {
    return [
        'name_ar' => trim((string)($post['name_ar'] ?? '')),
        'name_en' => trim((string)($post['name_en'] ?? '')),
        'description_ar' => trim((string)($post['description_ar'] ?? '')),
        'description_en' => trim((string)($post['description_en'] ?? '')),
        'price_monthly' => max(0.0, (float)($post['price_monthly'] ?? 0)),
        'price_yearly' => ($post['price_yearly'] ?? '') !== '' ? max(0.0, (float)$post['price_yearly']) : null,
        'ai_queries_limit' => ($post['ai_queries_limit'] ?? '') !== '' ? max(0, (int)$post['ai_queries_limit']) : null,
        'document_analysis_limit' => ($post['document_analysis_limit'] ?? '') !== '' ? max(0, (int)$post['document_analysis_limit']) : null,
        'consultations_included' => max(0, (int)($post['consultations_included'] ?? 0)),
        'classification' => in_array($post['classification'] ?? '', ['free', 'paid', 'corporate'], true) ? $post['classification'] : 'paid',
        'billing_cycle' => in_array($post['billing_cycle'] ?? '', ['monthly', 'yearly', 'both'], true) ? $post['billing_cycle'] : 'both',
        'consultation_price' => ($post['consultation_price'] ?? '') !== '' ? max(0.0, (float)$post['consultation_price']) : null,
        'is_corporate' => isset($post['is_corporate']) ? 1 : 0,
        'is_featured' => isset($post['is_featured']) ? 1 : 0,
        'status' => in_array($post['status'] ?? '', ['active', 'hidden'], true) ? $post['status'] : 'active',
        'sort_order' => (int)($post['sort_order'] ?? 0),
    ];
}

/**
 * The first reason this normalised plan cannot be saved, or null when it is valid.
 *
 * @param array<string,mixed> $data a plan_normalize_input() result
 */
function plan_validation_error(array $data): ?string {
    if ($data['name_ar'] === '' || $data['name_en'] === '') {
        return 'اسم الباقة (عربي وإنجليزي) مطلوب.';
    }
    $monthly = (float)$data['price_monthly'];
    $yearly = $data['price_yearly'] !== null ? (float)$data['price_yearly'] : 0.0;
    $isFree = $data['classification'] === 'free';

    if ($isFree && $monthly > 0) {
        return 'الباقة المجانية يجب أن يكون سعرها الشهري صفراً، أو غيّر التصنيف إلى «مدفوعة».';
    }
    if (!$isFree && $monthly <= 0 && $yearly <= 0) {
        return 'الباقة المدفوعة تحتاج سعراً شهرياً أو سنوياً أكبر من صفر، أو غيّر التصنيف إلى «مجانية».';
    }
    if (!$isFree && $data['billing_cycle'] !== 'yearly' && $monthly <= 0) {
        return 'دورة الفوترة تتضمن الشهري، لذا يجب إدخال سعر شهري أكبر من صفر.';
    }
    if (!$isFree && $data['billing_cycle'] === 'yearly' && $yearly <= 0) {
        return 'دورة الفوترة سنوية فقط، لذا يجب إدخال سعر سنوي أكبر من صفر.';
    }
    return null;
}
