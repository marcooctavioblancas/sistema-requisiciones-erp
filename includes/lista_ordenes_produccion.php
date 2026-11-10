<?php
// Include file — NOT standalone. Requires $db to be defined.
// Shows active ordenes_produccion in a clean table.

$op_lista = [];
try {
    $op_lista = $db->query(
        "SELECT id, numero_proyecto, cliente, fecha_compromiso, fecha, estado
         FROM ordenes_produccion
         WHERE estado = 'activa'
         ORDER BY fecha_creacion DESC"
    )->fetchAll();
} catch (Exception $e) {
    $op_lista = [];
}
?>

<?php if (empty($op_lista)): ?>
<div style="text-align:center;padding:48px 20px;color:#888;">
    <i class="fas fa-industry" style="font-size:2.5rem;opacity:.3;margin-bottom:12px;display:block;"></i>
    <p style="font-size:.95rem;font-weight:600;margin-bottom:6px;">Sin órdenes de producción activas</p>
    <p style="font-size:.82rem;">Cuando Proyectos difunda una Orden de Producción, aparecerá aquí.</p>
</div>
<?php else: ?>
<div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-family:'Montserrat',Arial,sans-serif;font-size:.82rem;">
        <thead>
            <tr style="background:#003d5c;color:#fff;">
                <th style="padding:10px 14px;text-align:left;font-weight:700;letter-spacing:.4px;">N° Proyecto</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;letter-spacing:.4px;">Cliente</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;letter-spacing:.4px;">Fecha</th>
                <th style="padding:10px 14px;text-align:left;font-weight:700;letter-spacing:.4px;">Fecha Compromiso</th>
                <th style="padding:10px 14px;text-align:center;font-weight:700;letter-spacing:.4px;">Acción</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($op_lista as $op): ?>
            <tr style="border-bottom:1px solid #e8edf2;transition:background .15s;" onmouseover="this.style.background='#f0f6fb'" onmouseout="this.style.background=''">
                <td style="padding:9px 14px;font-weight:600;color:#003d5c;"><?= htmlspecialchars($op['numero_proyecto'] ?? '—') ?></td>
                <td style="padding:9px 14px;"><?= htmlspecialchars($op['cliente'] ?? '—') ?></td>
                <td style="padding:9px 14px;"><?= $op['fecha'] ? date('d/m/Y', strtotime($op['fecha'])) : '—' ?></td>
                <td style="padding:9px 14px;"><?= htmlspecialchars($op['fecha_compromiso'] ?? '—') ?></td>
                <td style="padding:9px 14px;text-align:center;">
                    <a href="ver_orden_produccion.php?id=<?= $op['id'] ?>"
                       style="display:inline-flex;align-items:center;gap:6px;padding:5px 14px;background:#003d5c;color:#fff;border-radius:7px;font-size:.76rem;font-weight:600;text-decoration:none !important;transition:background .2s;"
                       onmouseover="this.style.background='#005686'" onmouseout="this.style.background='#003d5c'"
                       target="_blank">
                        <i class="fas fa-eye"></i> Ver
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
