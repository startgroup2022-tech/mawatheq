<?php
/**
 * المكتبة القانونية والقضائية — الملفات القابلة للتنزيل (Execution 68).
 * Legal & judicial library — downloadable files.
 *
 * Public. Lists the items that actually carry a file (a hosted copy, or a file URL on
 * the source), grouped by type. For a `link_only` source the download goes to the
 * source's own file, never to a copy inside Mawatheq — the redistribution rule is
 * applied upstream in legal_library_public_item().
 */
$pageStyles = ['/assets/css/legal-library.css'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/legal_library.php';
require_once __DIR__ . '/includes/legal_library_ui.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$rtl = is_rtl();
$page = max(1, (int)($_GET['page'] ?? 1));
$fileType = trim((string)($_GET['file_type'] ?? ''));
$perPage = 24;

// Items that carry a file: a hosted path or a source file URL.
$filters = ['has_file' => 1];
if ($fileType !== '') $filters['file_type'] = $fileType;
$res = legal_library_search($filters, $page, $perPage);
$rows = $res['items'];
$facets = legal_library_facets();
?>
<div class="ll-page">
<section class="section">
  <div class="container">
    <nav class="muted-sm" style="margin-bottom:12px">
      <a href="/legal-library.php" style="color:var(--ll-teal);text-decoration:none"><?= $rtl ? 'المكتبة القانونية' : 'Legal library' ?></a>
      <span> / <?= $rtl ? 'الملفات' : 'Files' ?></span>
    </nav>

    <div class="ll-hero" style="padding:clamp(18px,3vw,30px)">
      <div class="ll-hero-inner">
        <h1 style="font-size:clamp(1.3rem,2.6vw,1.8rem)"><?= ui_icon('download', 'll-ico') ?> <?= $rtl ? 'الملفات القانونية القابلة للتنزيل' : 'Downloadable legal files' ?></h1>
        <p class="ll-sub" style="margin-bottom:0"><?= $rtl
          ? 'المجموعات والأدلة والأنظمة المتاحة بصيغة ملف. عند كان الملف محفوظاً لدى الجهة المصدِرة فإن التنزيل يتم من مصدره الرسمي مباشرة.'
          : 'Collections, guides and regulations available as files. Where the file lives at the issuing authority, the download goes to the official source directly.' ?></p>
      </div>
    </div>

    <?php if ($facets['file_types'] ?? []): ?>
    <div class="ll-browse-row" style="margin:16px 0">
      <a class="ll-browse" href="/legal-library-files.php" style="font-weight:700"><?= $rtl ? 'الكل' : 'All' ?></a>
      <?php foreach ($facets['file_types'] as $ft): ?>
        <a class="ll-browse" href="/legal-library-files.php?file_type=<?= urlencode((string)$ft) ?>" style="<?= $fileType === $ft ? 'border-color:var(--ll-teal)' : '' ?>"><?= ui_e(strtoupper((string)$ft)) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
      <div class="ll-empty">
        <div class="ll-empty-icon"><?= ui_icon('download', 'll-ico-lg') ?></div>
        <h3><?= $rtl ? 'لا توجد ملفات بعد' : 'No files yet' ?></h3>
        <p><?= $rtl ? 'لم يُستورد أي عنصر يحمل ملفاً. شغّل المزامنة من لوحة التحكم.' : 'No item carrying a file has been imported. Run a sync from the console.' ?></p>
      </div>
    <?php else: ?>
      <div class="ll-files">
        <?php foreach ($rows as $row): $it = legal_library_public_item($row, $userId); ?>
          <div class="ll-file" style="flex-direction:column">
            <div style="display:flex;gap:13px">
              <span class="ll-file-ico"><?= ui_e(strtoupper((string)($it['file_type'] ?: 'FILE'))) ?></span>
              <span>
                <a class="ll-file-name" href="/legal-library-item.php?id=<?= (int)$it['id'] ?>" style="text-decoration:none;color:inherit"><?= ui_e(mb_substr((string)$it['title'], 0, 90)) ?></a>
                <span class="ll-file-meta">
                  <?= ui_e((string)$it['source_name']) ?>
                  <?= $it['file_size_kb'] ? ' · ' . number_format((int)$it['file_size_kb']) . ' KB' : '' ?>
                  <?= $it['decision_date'] ? ' · ' . ui_e((string)$it['decision_date']) : '' ?>
                </span>
              </span>
            </div>
            <?php if (!empty($it['download_url'])): ?>
              <a class="btn btn-outline btn-sm" style="align-self:flex-start;margin-top:10px" href="<?= ui_e((string)$it['download_url']) ?>" <?= $it['download_is_external'] ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                <?= ui_icon($it['download_is_external'] ? 'external' : 'download', 'll-ico ll-ico-sm') ?>
                <?= $it['download_is_external'] ? ($rtl ? 'تنزيل من المصدر' : 'Download from source') : ($rtl ? 'تنزيل' : 'Download') ?>
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?= ll_pager((int)$res['page'], (int)$res['pages'], fn($p) => '/legal-library-files.php?' . http_build_query(array_filter(['file_type' => $fileType, 'page' => $p > 1 ? $p : '']))) ?>
    <?php endif; ?>
  </div>
</section>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
