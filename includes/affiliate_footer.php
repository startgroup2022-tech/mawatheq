  </main>
</div>
<script src="/assets/js/enterprise.js" defer></script>
<script src="/assets/js/affiliate.js" defer></script>
<?php if (!empty($affiliatePageAssets)): foreach ((array)$affiliatePageAssets as $asset): ?><?= $asset ?>
<?php endforeach; endif; ?>
</body>
</html>
