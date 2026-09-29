<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/lang/strings.php';
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/contract_comparison.php';
$userId=(int)$_SESSION['user_id'];
$error=null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!csrf_is_valid($_POST['csrf_token']??null)) { http_response_code(403); exit('Invalid request'); }
    if (($_POST['action']??'')==='compare') {
        $result=compare_user_contracts($pdo,$userId,(int)($_POST['first_document_id']??0),(int)($_POST['second_document_id']??0),!empty($_POST['ai_consent']));
        if ($result['ok']) { header('Location: /contract-comparison.php?id='.(int)$result['comparison_id']); exit; }
        $error=$result['error'];
    }
}
$docsQuery=$pdo->prepare("SELECT id,original_name,created_at FROM documents WHERE user_id=? AND document_type='contract' AND status='completed' AND contract_analysis_json IS NOT NULL ORDER BY created_at DESC");
$docsQuery->execute([$userId]); $contracts=$docsQuery->fetchAll();
$historyQuery=$pdo->prepare('SELECT cc.id,cc.first_document_id,cc.second_document_id,cc.comparison_result,cc.comparison_status,cc.model,cc.created_at,d1.original_name AS first_name,d2.original_name AS second_name FROM contract_comparisons cc JOIN documents d1 ON d1.id=cc.first_document_id JOIN documents d2 ON d2.id=cc.second_document_id WHERE cc.user_id=? ORDER BY cc.created_at DESC LIMIT 20');
$historyQuery->execute([$userId]); $comparisons=$historyQuery->fetchAll();
$selectedComparison=null;
if (!empty($_GET['id'])) { $current=$pdo->prepare('SELECT cc.*,d1.original_name AS first_name,d2.original_name AS second_name FROM contract_comparisons cc JOIN documents d1 ON d1.id=cc.first_document_id JOIN documents d2 ON d2.id=cc.second_document_id WHERE cc.id=? AND cc.user_id=?'); $current->execute([(int)$_GET['id'],$userId]); $selectedComparison=$current->fetch()?:null; }
$activePage='contract-comparison';
require_once __DIR__.'/includes/app_header.php';
?>
<div class="app-content">
  <div class="toolbar"><h2>⚖️ <?= is_rtl()?'مقارنة العقود':'Contract comparison' ?></h2></div>
  <div class="form-alert" style="background:#FFF8E6;color:#795b12"><?= is_rtl()?'تقارن هذه الأداة نتائج التحليل المستخرجة من العقدين، ولا تقارن النصين الأصليين حرفياً ولا تنشئ redline. راجع المستندين والمحامي قبل اتخاذ قرار.':'This compares extracted AI analyses, not the full original texts, and does not create a redline. Review both documents and consult counsel before deciding.' ?></div>
  <?php if($error): ?><div class="form-alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post" class="dash-card"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="compare"><h3><?= is_rtl()?'إنشاء مقارنة جديدة':'Create a comparison' ?></h3>
    <?php if(count($contracts)<2): ?><p><?= is_rtl()?'تحتاج عقدين على الأقل من نوع «تحليل عقد تفصيلي» بحالة تحليل مكتمل.':'You need two completed documents analyzed as contracts.' ?></p><?php else: ?><div class="grid-2"><div class="field"><label><?= is_rtl()?'العقد الأول':'First contract' ?></label><select class="pro-select" name="first_document_id" required><?php foreach($contracts as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['original_name']) ?> · <?= date('Y-m-d',strtotime($c['created_at'])) ?></option><?php endforeach; ?></select></div><div class="field"><label><?= is_rtl()?'العقد الثاني':'Second contract' ?></label><select class="pro-select" name="second_document_id" required><?php foreach($contracts as $i=>$c): ?><option value="<?= (int)$c['id'] ?>" <?= $i===1?'selected':'' ?>><?= htmlspecialchars($c['original_name']) ?> · <?= date('Y-m-d',strtotime($c['created_at'])) ?></option><?php endforeach; ?></select></div></div>
    <label style="display:block;margin:12px 0"><input type="checkbox" name="ai_consent" value="1" required> <?= is_rtl()?'أوافق على إرسال نتائج التحليل المستخرجة للعقدين إلى مزوّد الذكاء الاصطناعي المهيأ للمقارنة.':'I consent to sending the extracted analyses of both contracts to the configured AI provider for comparison.' ?></label><button class="btn btn-primary" type="submit"><?= is_rtl()?'قارن العقدين':'Compare contracts' ?></button><?php endif; ?>
  </form>
  <?php if($selectedComparison): ?><div class="dash-card"><h3><?= htmlspecialchars($selectedComparison['first_name']) ?> ↔ <?= htmlspecialchars($selectedComparison['second_name']) ?></h3><small><?= date('Y-m-d H:i',strtotime($selectedComparison['created_at'])) ?> · <?= htmlspecialchars($selectedComparison['model']??'') ?> · <?= htmlspecialchars($selectedComparison['comparison_status']) ?></small><?php if(!empty($selectedComparison['comparison_result'])): ?><pre style="white-space:pre-wrap;font-family:inherit;line-height:1.9;margin-top:14px"><?= htmlspecialchars($selectedComparison['comparison_result']) ?></pre><?php else: ?><p><?= is_rtl()?'لم تُنتج مقارنة مكتملة لهذه المحاولة.':'This attempt did not produce a completed comparison.' ?></p><?php endif; ?></div><?php endif; ?>
  <div class="dash-card"><h3><?= is_rtl()?'المقارنات السابقة':'Previous comparisons' ?></h3><?php foreach($comparisons as $comparison): ?><div class="rec-item"><a href="/contract-comparison.php?id=<?= (int)$comparison['id'] ?>"><?= htmlspecialchars($comparison['first_name']) ?> ↔ <?= htmlspecialchars($comparison['second_name']) ?></a><small style="display:block"><?= date('Y-m-d H:i',strtotime($comparison['created_at'])) ?> · <?= htmlspecialchars($comparison['comparison_status']) ?></small></div><?php endforeach; ?><?php if(!$comparisons): ?><p style="color:var(--text-muted)"><?= is_rtl()?'لا توجد مقارنات محفوظة بعد.':'No saved comparisons yet.' ?></p><?php endif; ?></div>
</div>
<?php require_once __DIR__.'/includes/app_footer.php'; ?>
