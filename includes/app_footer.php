  </main>
</div>
<script src="/assets/js/main.js"></script>
<script>
function openModal(id){ var m=document.getElementById(id); if(m){ m.classList.add('is-open'); document.body.style.overflow='hidden'; } }
function closeModal(id){ var m=document.getElementById(id); if(m){ m.classList.remove('is-open'); document.body.style.overflow=''; } }
document.querySelectorAll('.modal-backdrop').forEach(function(b){ b.addEventListener('mousedown', function(e){ if(e.target===b) closeModal(b.id); }); });
document.addEventListener('keydown', function(e){ if(e.key==='Escape') document.querySelectorAll('.modal-backdrop.is-open').forEach(function(m){ closeModal(m.id); }); });
</script>
</body>
</html>
