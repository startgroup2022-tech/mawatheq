<?php
/**
 * جاهزية لوحة المحامي — مؤشّر واحد يقرأه الموقع ولوحة التحكم.
 *
 * A lawyer account can exist, log in, and still be **unbookable**: no price, no
 * published working hours, or no declared area of law. The customer then sees a card
 * that answers nothing, and the operator cannot tell a half-set-up account from a
 * ready one at a glance. This module answers that with one definition, so the lawyer's
 * own panel, the admin list and the customer-facing directory cannot disagree about
 * whether a lawyer is ready.
 *
 * ## Two kinds of signal
 *
 * - **Required** — a lawyer who fails any of these cannot be booked at all:
 *   a declared specialization, a price (`hourly_rate` > 0), and published working
 *   hours (`lawyer_availability`). These decide `ready` / `blocking`.
 * - **Recommended** — they make the profile convincing but do not stop a booking:
 *   photo, bio, years of experience, qualifications, linked specialties and social
 *   links. They contribute to `percent` only.
 *
 * The account's own `status` is reported separately as `account_approved`, because an
 * unapproved account is a moderation decision, not something the lawyer completes — it
 * is a gate on being *visible*, not a profile field.
 */
require_once __DIR__ . '/specialization_field.php';

/** The label + requirement of each signal, in the order the UI shows them. */
function lawyer_readiness_definitions(bool $rtl): array {
    return [
        'specialization'  => ['label' => $rtl ? 'التخصص القانوني' : 'Legal specialization', 'required' => true,
                              'hint' => $rtl ? 'أضف تخصصاً واحداً على الأقل ليصلك العملاء المطابقون.' : 'Add at least one area so matching clients can reach you.'],
        'price'           => ['label' => $rtl ? 'سعر الاستشارة' : 'Consultation price', 'required' => true,
                              'hint' => $rtl ? 'حدّد أجرك حتى يتمكن العميل من الدفع.' : 'Set your rate so a client can pay.'],
        'working_hours'   => ['label' => $rtl ? 'أوقات العمل' : 'Working hours', 'required' => true,
                              'hint' => $rtl ? 'انشر دوامك الأسبوعي لتظهر المواعيد القابلة للحجز.' : 'Publish your weekly hours so bookable slots appear.'],
        'photo'           => ['label' => $rtl ? 'الصورة الشخصية' : 'Profile photo', 'required' => false,
                              'hint' => $rtl ? 'صورة واضحة تزيد ثقة العميل.' : 'A clear photo builds client trust.'],
        'bio'             => ['label' => $rtl ? 'النبذة التعريفية' : 'Bio', 'required' => false,
                              'hint' => $rtl ? 'عرّف بخبرتك في بضعة أسطر.' : 'Describe your experience in a few lines.'],
        'experience'      => ['label' => $rtl ? 'سنوات الخبرة' : 'Years of experience', 'required' => false,
                              'hint' => $rtl ? 'أضف سنوات خبرتك.' : 'Add your years of experience.'],
        'qualifications'  => ['label' => $rtl ? 'المؤهلات' : 'Qualifications', 'required' => false,
                              'hint' => $rtl ? 'اذكر مؤهلاتك العلمية.' : 'List your academic qualifications.'],
        'specialty_links' => ['label' => $rtl ? 'ربط التخصصات بالتصنيف' : 'Specialties linked to the taxonomy', 'required' => false,
                              'hint' => $rtl ? 'اربط تخصصاتك بالتصنيف القضائي من صفحة «تخصصاتي».' : 'Link your areas to the taxonomy from your specialties page.'],
        'social'          => ['label' => $rtl ? 'وسائل التواصل' : 'Social links', 'required' => false,
                              'hint' => $rtl ? 'أضف رابطاً واحداً على الأقل.' : 'Add at least one link.'],
    ];
}

/**
 * The per-lawyer counts the signals need, for a whole page of lawyers in two queries.
 *
 * @param array<int,int> $lawyerIds
 * @return array<int,array{availability:int,specialties:int}>
 */
function lawyer_readiness_counts(PDO $pdo, array $lawyerIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $lawyerIds), fn($i) => $i > 0)));
    $out = [];
    foreach ($ids as $id) { $out[$id] = ['availability' => 0, 'specialties' => 0]; }
    if (!$ids) return $out;

    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (['availability' => 'lawyer_availability', 'specialties' => 'lawyer_specialties'] as $key => $table) {
        try {
            $stmt = $pdo->prepare("SELECT lawyer_id, COUNT(*) AS c FROM $table WHERE lawyer_id IN ($ph) GROUP BY lawyer_id");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['lawyer_id']][$key] = (int)$row['c'];
            }
        } catch (Throwable $e) {
            // A deployment that has not run the migration yet must degrade to "0 hours /
            // 0 links", never to a fatal that blanks the whole admin list.
        }
    }
    return $out;
}

/**
 * Evaluate one lawyer's readiness.
 *
 * @param array $counts optional pre-fetched ['availability'=>n,'specialties'=>n] for this
 *                      lawyer, so a list does not run two queries per row.
 * @return array{ready:bool,account_approved:bool,percent:int,items:array<int,array>,blocking:array<int,string>}
 */
function lawyer_panel_readiness(PDO $pdo, array $lawyer, ?array $counts = null): array {
    $rtl = is_rtl();
    $defs = lawyer_readiness_definitions($rtl);

    if ($counts === null) {
        $id = (int)($lawyer['id'] ?? 0);
        $counts = lawyer_readiness_counts($pdo, [$id])[$id] ?? ['availability' => 0, 'specialties' => 0];
    }

    $hasSocial = trim((string)($lawyer['social_linkedin'] ?? '')) !== ''
        || trim((string)($lawyer['social_twitter'] ?? '')) !== ''
        || trim((string)($lawyer['social_website'] ?? '')) !== '';

    $done = [
        'specialization'  => lawyer_specialization_list($lawyer) !== [],
        'price'           => is_numeric($lawyer['hourly_rate'] ?? null) && (float)$lawyer['hourly_rate'] > 0,
        'working_hours'   => (int)($counts['availability'] ?? 0) > 0,
        'photo'           => trim((string)($lawyer['photo_path'] ?? '')) !== '',
        'bio'             => trim((string)($lawyer['bio'] ?? '')) !== '',
        'experience'      => is_numeric($lawyer['years_experience'] ?? null) && (float)$lawyer['years_experience'] > 0,
        'qualifications'  => trim((string)($lawyer['qualifications'] ?? '')) !== '',
        'specialty_links' => (int)($counts['specialties'] ?? 0) > 0,
        'social'          => $hasSocial,
    ];

    $items = [];
    $blocking = [];
    $total = 0;
    $score = 0;
    foreach ($defs as $key => $def) {
        $isDone = $done[$key] ?? false;
        $total++;
        if ($isDone) $score++;
        if ($def['required'] && !$isDone) $blocking[] = $key;
        $items[] = [
            'key'      => $key,
            'label'    => $def['label'],
            'hint'     => $def['hint'],
            'required' => $def['required'],
            'done'     => $isDone,
        ];
    }

    return [
        'ready'            => $blocking === [],
        'account_approved' => ($lawyer['status'] ?? '') === 'approved',
        'percent'          => $total > 0 ? (int)round(($score / $total) * 100) : 0,
        'items'            => $items,
        'blocking'         => $blocking,
    ];
}

/** A short Arabic label for a blocked readiness state, for a badge tooltip. */
function lawyer_readiness_blocking_label(array $blocking): string {
    $rtl = is_rtl();
    $defs = lawyer_readiness_definitions($rtl);
    $labels = [];
    foreach ($blocking as $key) {
        if (isset($defs[$key])) $labels[] = $defs[$key]['label'];
    }
    if (!$labels) return $rtl ? 'جاهز' : 'Ready';
    return $rtl ? 'ينقص: ' . implode('، ', $labels) : 'Missing: ' . implode(', ', $labels);
}

/**
 * Print the readiness checklist card — required items first (with a clear "blocks
 * bookings" marker), then the recommended ones. Mirrors `render_completion_card()`'s
 * markup so the two cards sit together without a second visual language.
 */
function render_lawyer_readiness_card(array $readiness): void {
    $rtl = is_rtl();
    $items = $readiness['items'] ?? [];
    $required = array_values(array_filter($items, fn($i) => !empty($i['required'])));
    $recommended = array_values(array_filter($items, fn($i) => empty($i['required'])));
    $render = function (array $list) {
        foreach ($list as $it): ?>
          <li class="<?= $it['done'] ? 'is-done' : 'is-pending' ?>">
            <span class="dot"><?= $it['done'] ? '✓' : '' ?></span>
            <span><?= htmlspecialchars($it['label']) ?></span>
          </li>
        <?php endforeach;
    };
    ?>
    <div class="readiness-card">
      <div class="completion-head">
        <div>
          <h3><?= $rtl ? 'جاهزية لوحتك للحجز' : 'Panel readiness for bookings' ?></h3>
          <p><?= $rtl
              ? 'هذه العناصر تحدّد إن كان بإمكان العميل حجز استشارة معك. البنود المعلّمة بـ«مطلوب» تمنع الحجز حتى تكتمل.'
              : 'These decide whether a client can book a consultation with you. Items marked "required" block booking until complete.' ?></p>
        </div>
        <div class="completion-ring" style="--p:<?= (int)$readiness['percent'] ?>"><span><?= (int)$readiness['percent'] ?>%</span></div>
      </div>

      <h4 class="readiness-subhead"><?= $rtl ? 'مطلوب' : 'Required' ?></h4>
      <ul class="completion-list readiness-required"><?php $render($required); ?></ul>

      <?php if ($recommended): ?>
        <h4 class="readiness-subhead"><?= $rtl ? 'مستحسن' : 'Recommended' ?></h4>
        <ul class="completion-list"><?php $render($recommended); ?></ul>
      <?php endif; ?>

      <a href="/lawyer/profile.php" class="btn btn-outline btn-sm"><?= $rtl ? 'استكمل البيانات' : 'Complete the details' ?></a>
    </div>
    <?php
}

