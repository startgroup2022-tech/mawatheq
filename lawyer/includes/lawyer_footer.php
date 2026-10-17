  </main>
</div>
<?= nav_render_bottom($nav ?? ['groups' => [], 'active' => '']) ?>
<script src="/assets/js/enterprise.js" defer></script>
<?php if (!empty($extraPageAssets)): foreach ((array)$extraPageAssets as $asset): ?><?= $asset ?>
<?php endforeach; endif; ?>
</body>
</html>
