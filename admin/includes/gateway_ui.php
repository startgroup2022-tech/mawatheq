<?php
/**
 * Resolve the tenant scope for the gateway settings UI from a request value.
 *
 * Format: "platform" | "firm:<id>" | "lawyer:<id>". Defaults to platform. The admin console may
 * configure any tenant, but a payment for a given invoice always resolves its OWN tenant's
 * credentials (see gateway_for_invoice), so a firm's key can never launch another firm's payment.
 *
 * @return array{0:string,1:int} [tenantType, tenantId]
 */
function gateway_ui_tenant_scope(?string $scope, PDO $pdo): array {
    $scope = trim((string)$scope);
    if ($scope === '' || $scope === 'platform') return ['platform', 0];
    if (preg_match('/^(firm|lawyer):(\d+)$/', $scope, $m)) {
        $type = $m[1]; $id = (int)$m[2];
        $table = $type === 'firm' ? 'law_firms' : 'lawyers';
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id=?");
            $stmt->execute([$id]);
            if ((int)$stmt->fetchColumn() > 0) return [$type, $id];
        } catch (PDOException $e) { /* fall through */ }
    }
    return ['platform', 0];
}

/** Human label for a tenant scope. */
function gateway_ui_tenant_label(PDO $pdo, string $type, int $id): string {
    if ($type === 'platform') return is_rtl() ? 'المنصة (افتراضي)' : 'Platform (default)';
    try {
        if ($type === 'firm') {
            $stmt = $pdo->prepare('SELECT name FROM law_firms WHERE id=?');
            $stmt->execute([$id]);
            $name = (string)$stmt->fetchColumn();
            return (is_rtl() ? 'مكتب: ' : 'Firm: ') . ($name !== '' ? $name : '#' . $id);
        }
        $stmt = $pdo->prepare('SELECT full_name FROM lawyers WHERE id=?');
        $stmt->execute([$id]);
        $name = (string)$stmt->fetchColumn();
        return (is_rtl() ? 'محامٍ: ' : 'Lawyer: ') . ($name !== '' ? $name : '#' . $id);
    } catch (PDOException $e) {
        return $type . ' #' . $id;
    }
}

/** Options list of firms + lawyers for the tenant scope selector. */
function gateway_ui_tenant_options(PDO $pdo): array {
    $out = [];
    try {
        foreach ($pdo->query('SELECT id, name FROM law_firms ORDER BY name LIMIT 500')->fetchAll() as $r) {
            $out['firm:' . (int)$r['id']] = (is_rtl() ? 'مكتب: ' : 'Firm: ') . $r['name'];
        }
    } catch (PDOException $e) {}
    try {
        foreach ($pdo->query('SELECT id, full_name FROM lawyers ORDER BY full_name LIMIT 500')->fetchAll() as $r) {
            $out['lawyer:' . (int)$r['id']] = (is_rtl() ? 'محامٍ: ' : 'Lawyer: ') . $r['full_name'];
        }
    } catch (PDOException $e) {}
    return $out;
}
