<style>
/* =========================================================
   نظام تصميم موحّد للوحة التحكم — نفس هوية صفحة "المحامون"
   مضمّن مباشرة بكل صفحة (وليس ملف CSS خارجي منفصل) حتى يظهر
   دائماً بغض النظر عن ترتيب رفع الملفات أو الكاش.
   ========================================================= */
:root{
  --ap-primary:#2563eb; --ap-primary-dark:#1d4ed8; --ap-success:#16a34a;
  --ap-danger:#dc2626; --ap-warning:#d97706; --ap-text:#172033;
  --ap-muted:#667085; --ap-border:#e7eaf0; --ap-bg:#f6f8fb; --ap-card:#ffffff;
}
.app-content{ direction:rtl; color:var(--ap-text); }
.app-content *{ box-sizing:border-box; }

.app-content .toolbar{
  display:flex; align-items:flex-start; justify-content:space-between; gap:20px;
  margin-bottom:22px; flex-wrap:wrap;
}
.app-content .toolbar h2{
  margin:0; font-size:26px; line-height:1.3; font-weight:800; letter-spacing:-.3px;
}
.count-badge{
  display:inline-flex; min-width:32px; height:27px; padding:0 9px; align-items:center;
  justify-content:center; margin-inline-start:7px; border-radius:999px;
  background:#eff6ff; color:var(--ap-primary); font-size:13px; font-weight:800; vertical-align:middle;
}

/* أزرار */
.app-content .btn-primary, .app-content .primary-action{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  min-height:44px; padding:0 18px; border:0; border-radius:12px;
  background:linear-gradient(135deg, var(--ap-primary), #3b82f6); color:#fff;
  font-size:14px; font-weight:800; cursor:pointer;
  box-shadow:0 8px 20px rgba(37,99,235,.18); transition:.18s ease; text-decoration:none;
}
.app-content .btn-primary:hover{ transform:translateY(-1px); box-shadow:0 11px 24px rgba(37,99,235,.24); background:var(--ap-primary-dark); }
.app-content .btn-outline{
  display:inline-flex; align-items:center; justify-content:center; gap:6px;
  min-height:40px; padding:0 16px; border:1px solid var(--ap-border); border-radius:10px;
  background:#fff; color:var(--ap-muted); font-size:13px; font-weight:800; cursor:pointer; text-decoration:none;
}
.app-content .btn-outline:hover{ background:#f8fafc; border-color:#cbd5e1; color:var(--ap-primary); }
.app-content .btn-sm{ min-height:34px; padding:0 12px; font-size:12px; }
.app-content .btn-lg{ min-height:50px; font-size:15px; }
.app-content .btn-block{ width:100%; }
.icon-btn{
  display:inline-flex; align-items:center; justify-content:center; min-height:32px; padding:0 10px;
  border:1px solid var(--ap-border); border-radius:8px; background:#fff; color:var(--ap-muted);
  text-decoration:none; font-size:11px; font-weight:800; transition:.15s ease; cursor:pointer;
}
.icon-btn:hover{ background:#f8fafc; border-color:#cbd5e1; color:var(--ap-primary); }
.icon-btn.danger{ color:var(--ap-danger); }
.icon-btn.danger:hover{ background:#fff5f5; border-color:#fecaca; }

/* بطاقات إحصائية */
.stat-grid{ display:grid; grid-template-columns:repeat(auto-fit, minmax(200px,1fr)); gap:14px; margin-bottom:18px; }
.stat-card{
  position:relative; overflow:hidden; display:flex; align-items:center; gap:14px; min-height:92px;
  padding:17px; border:1px solid var(--ap-border); border-radius:16px; background:var(--ap-card);
  box-shadow:0 5px 20px rgba(16,24,40,.035);
}
.stat-card .num{ font-size:23px; line-height:1.1; font-weight:800; color:var(--ap-text); }
.stat-card .label{ color:var(--ap-muted); font-size:12px; margin-top:3px; }

/* بطاقة/حاوية عامة */
.dash-card, .table-wrap, .modal-form{
  border:1px solid var(--ap-border); border-radius:17px; background:var(--ap-card);
  box-shadow:0 7px 25px rgba(16,24,40,.045);
}
.dash-card{ padding:22px; margin-bottom:18px; }
.table-wrap{ overflow:hidden; }
.table-wrap > .admin-table{ border:0; border-radius:0; box-shadow:none; }

/* جداول */
.admin-table{ width:100%; border-collapse:separate; border-spacing:0; }
.admin-table th{
  padding:13px 15px; background:#fafbfc; color:#667085; font-size:11px; font-weight:800;
  text-align:start; white-space:nowrap; border-bottom:1px solid var(--ap-border); text-transform:none;
}
.admin-table td{ padding:14px 15px; color:#344054; font-size:12.5px; vertical-align:middle; border-bottom:1px solid #f0f2f5; }
.admin-table tr:hover td{ background:#fbfdff; }
.admin-table tr:last-child td{ border-bottom:0; }

/* شرائط الحالة */
.status-pill{
  display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:999px;
  font-size:11px; font-weight:800; white-space:nowrap;
}
.status-pill::before{ content:""; width:6px; height:6px; border-radius:50%; background:currentColor; }
.status-confirmed, .status-approved, .status-active{ background:#ecfdf3; color:#15803d; }
.status-pending{ background:#fff7ed; color:#b45309; }
.status-cancelled, .status-suspended{ background:#fef2f2; color:#b91c1c; }

/* نوافذ منبثقة (Modal) */
.modal-backdrop{
  position:fixed; inset:0; z-index:9999; display:none; align-items:center; justify-content:center;
  padding:24px; background:rgba(15,23,42,.58); backdrop-filter:blur(5px);
}
.modal-backdrop.is-open{ display:flex; animation:apFadeIn .18s ease; }
.modal-backdrop .modal-form{
  width:min(720px,100%); max-height:calc(100vh - 48px); overflow-y:auto; padding:26px;
  box-shadow:0 30px 80px rgba(15,23,42,.24); animation:apModalIn .2s ease; position:relative;
}
.modal-close{
  position:absolute; top:16px; inset-inline-end:16px; width:36px; height:36px; border:0; border-radius:10px;
  background:#f2f4f7; color:#667085; cursor:pointer; font-size:16px; transition:.15s ease;
}
.modal-close:hover{ background:#e4e7ec; color:#344054; }
@keyframes apFadeIn{ from{opacity:0} to{opacity:1} }
@keyframes apModalIn{ from{opacity:0; transform:translateY(12px) scale(.985);} to{opacity:1; transform:none;} }

/* فورمات */
.field{ margin-bottom:16px; }
.field > label{ display:block; margin:0 0 6px; color:#344054; font-size:12px; font-weight:800; }
.field input, .field select, .field textarea, .pro-input, .pro-select{
  width:100%; min-height:42px; border:1px solid #dfe3ea; border-radius:10px; background:#fff;
  color:#172033; padding:9px 12px; outline:none; font-family:inherit; font-size:13px; transition:.15s ease;
}
.field input:focus, .field select:focus, .field textarea:focus, .pro-input:focus, .pro-select:focus{
  border-color:#93c5fd; box-shadow:0 0 0 3px rgba(37,99,235,.09);
}
.form-section-title{ display:flex; align-items:center; gap:8px; margin:22px 0 12px; color:#344054; font-size:12px; font-weight:900; }
.form-section-title::before{ content:""; width:3px; height:15px; border-radius:99px; background:var(--ap-primary); }
.form-alert{ display:flex; align-items:flex-start; gap:8px; margin-bottom:16px; padding:11px 14px; border-radius:10px; font-size:12px; line-height:1.7; font-weight:600; }
.form-alert.error{ border:1px solid #fecaca; background:#fff5f5; color:#b42318; }
.form-alert.success{ border:1px solid #bbf7d0; background:#ecfdf3; color:#15803d; }
.field-hint{ margin:5px 0 0; color:#98a2b3; font-size:11px; }
.grid-2{ display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px 16px; }

@media (max-width:850px){
  .app-content .toolbar{ align-items:stretch; flex-direction:column; }
  .app-content .btn-primary{ width:100%; }
  .modal-backdrop{ padding:12px; }
}
@media (max-width:600px){
  .grid-2{ grid-template-columns:1fr; }
  .app-content .toolbar h2{ font-size:22px; }
}
</style>
