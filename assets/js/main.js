// تفاعلات بسيطة للموقع العام
document.addEventListener('DOMContentLoaded', () => {
  // نموذج المساعد الذكي التجريبي في الصفحة الرئيسية (Hero)
  const heroForm = document.getElementById('hero-ai-form');
  if (heroForm) {
    heroForm.addEventListener('submit', (e) => {
      e.preventDefault();
      window.location.href = '/register.php?next=ai-assistant';
    });
  }
});
