<?php
/**
 * CRM demo seed — gives /admin/crm.php realistic data across every pipeline stage,
 * lead status and task state so the page demonstrates itself instead of reading as
 * blank on a fresh install. Idempotent: clears the CRM tables first.
 *
 * Usage: php tests/seed_crm_demo.php
 */
require_once __DIR__ . '/../config/config.php';

foreach (['crm_activities', 'crm_tasks', 'crm_notes', 'crm_opportunities', 'crm_companies', 'contact_leads'] as $t) {
    $pdo->exec("DELETE FROM `$t`");
}

$pdo->exec("INSERT INTO contact_leads (lead_type, full_name, email, phone, message, status, utm_source, utm_medium, utm_campaign, source_page, created_at) VALUES
('contact','عبدالله الحربي','abdullah.h@example.com','+966551000001','أحتاج استشارة في نزاع تجاري مع مورد.','new','google','cpc','brand','/contact.php',NOW() - INTERVAL 2 DAY),
('corporate','شركة الأفق للمقاولات','info@alofoq.example.com','+966551000002','نطلب عرض سعر لعقد سنوي لخدمات قانونية.','contacted','linkedin','social','b2b','/services.php',NOW() - INTERVAL 6 DAY),
('contact','نورة العتيبي','noura.o@example.com','+966551000003','استفسار عن توثيق عقد إيجار تجاري.','new','direct','none','none','/contact.php',NOW() - INTERVAL 1 DAY),
('join_lawyer','مكتب الوفاء للمحاماة','legal@alwafa.example.com','+966551000004','رغبة في شراكة إحالة قضايا.','closed','email','email','partners','/partners.php',NOW() - INTERVAL 20 DAY)");

$pdo->exec("INSERT INTO crm_companies (name, industry, website, phone, email, address, notes, created_by_admin_id, created_at) VALUES
('شركة الأفق للمقاولات','المقاولات والإنشاءات','https://alofoq.example.com','+966112223344','info@alofoq.example.com','الرياض - حي العليا','عميل محتمل كبير لخدمات العقود.',1,NOW() - INTERVAL 15 DAY),
('مجموعة النخبة التجارية','التجزئة','https://alnokhba.example.com','+966113334455','contact@alnokhba.example.com','جدة - حي الروضة','يحتاج متابعة دورية للفواتير.',1,NOW() - INTERVAL 9 DAY),
('تقنية المستقبل','تقنية المعلومات','https://futuretech.example.com','+966114445566','hello@futuretech.example.com','الدمام - حي الفيصلية','مهتم بحماية الملكية الفكرية.',1,NOW() - INTERVAL 4 DAY)");

$companies = [];
foreach ($pdo->query('SELECT id, name FROM crm_companies')->fetchAll() as $c) { $companies[$c['name']] = (int)$c['id']; }
$leads = [];
foreach ($pdo->query('SELECT id, email FROM contact_leads')->fetchAll() as $l) { $leads[$l['email']] = (int)$l['id']; }

$opps = [
    ['عقد خدمات قانونية سنوي — الأفق',      'new',         45000, 10, 30, 1, $companies['شركة الأفق للمقاولات'], $leads['info@alofoq.example.com']],
    ['مراجعة عقود التوريد — النخبة',        'qualified',   28000, 30, 21, 1, $companies['مجموعة النخبة التجارية'], null],
    ['تسجيل علامة تجارية — تقنية المستقبل', 'proposal',    16500, 55, 14, 2, $companies['تقنية المستقبل'], null],
    ['نزاع تجاري — مطالبة بمبلغ توريد',     'negotiation', 92000, 75, 10, 1, null, $leads['abdullah.h@example.com']],
    ['استشارات عمالية شهرية — النخبة',      'won',         36000, 100, -5, 1, $companies['مجموعة النخبة التجارية'], null],
    ['تمثيل قانوني — عرض مرفوض',            'lost',        54000, 0, -15, 2, null, null],
];
$ins = $pdo->prepare('INSERT INTO crm_opportunities (title, stage, value, probability, expected_close, user_id, company_id, lead_id, owner_admin_id, created_at) VALUES (?,?,?,?,CURDATE() + INTERVAL ? DAY,?,?,?,1,NOW() - INTERVAL ? DAY)');
foreach ($opps as $i => $o) {
    $ins->execute([$o[0], $o[1], $o[2], $o[3], $o[4], $o[5], $o[6], $o[7], 3 + $i * 5]);
}

$oppIds = array_column($pdo->query('SELECT id FROM crm_opportunities ORDER BY id')->fetchAll(), 'id');
$pdo->exec("INSERT INTO crm_tasks (title, task_type, due_at, status, entity_type, entity_id, assigned_admin_id, created_at) VALUES
('متابعة عرض الأفق للمقاولات','follow_up',NOW() + INTERVAL 1 DAY,'open','opportunity',{$oppIds[0]},1,NOW() - INTERVAL 1 DAY),
('إرسال مسودة العقد للنخبة','task',NOW() + INTERVAL 3 DAY,'open','opportunity',{$oppIds[1]},1,NOW() - INTERVAL 2 DAY),
('تجهيز ملف العلامة التجارية','task',NOW() + INTERVAL 5 DAY,'open','opportunity',{$oppIds[2]},1,NOW() - INTERVAL 1 DAY),
('جدولة اجتماع التفاوض','follow_up',NOW() - INTERVAL 1 DAY,'open','opportunity',{$oppIds[3]},1,NOW() - INTERVAL 6 DAY),
('إغلاق فرصة الاستشارات الشهرية','task',NOW() - INTERVAL 4 DAY,'done','opportunity',{$oppIds[4]},1,NOW() - INTERVAL 20 DAY)");

$pdo->exec("INSERT INTO crm_activities (entity_type, entity_id, activity_type, summary, admin_id, created_at) VALUES
('opportunity',{$oppIds[0]},'note','تم إنشاء الفرصة من طلب وارد.',1,NOW() - INTERVAL 3 DAY),
('opportunity',{$oppIds[1]},'stage_change','new → qualified',1,NOW() - INTERVAL 6 DAY),
('opportunity',{$oppIds[3]},'stage_change','proposal → negotiation',1,NOW() - INTERVAL 2 DAY),
('lead',{$leads['abdullah.h@example.com']},'note','تم التواصل هاتفياً، بانتظار تأكيد الموعد.',1,NOW() - INTERVAL 1 DAY),
('opportunity',{$oppIds[4]},'stage_change','negotiation → won',1,NOW() - INTERVAL 5 DAY)");

foreach (['crm_opportunities', 'crm_companies', 'contact_leads', 'crm_tasks', 'crm_activities'] as $t) {
    printf("%-20s %d\n", $t, (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn());
}
echo "CRM demo data seeded.\n";
