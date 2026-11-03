<?php
/**
 * Role preview bar. Include near the top of <body> on any page an admin
 * might want to preview.
 *
 * Styled inline rather than with either app.php's CSS or the EAER theme
 * tokens, because it has to look the same on both and belongs to neither.
 * Renders nothing at all for non-admins.
 */
if (!function_exists('can_preview_roles') || !can_preview_roles()) {
    return;
}

$previewReturn = $_SERVER['REQUEST_URI'] ?? '/app.php';
$effective     = user_role();
$previewing    = is_previewing();

$pvBtn = 'display:inline-block;padding:2px 10px;border-radius:999px;font-size:11px;'
       . 'font-weight:600;text-decoration:none;border:1px solid rgba(0,0,0,.18);line-height:1.6;';
?>
<div role="status" style="
    background:<?= $previewing ? '#4C1D95' : '#1F2937' ?>;
    color:#fff;font-size:12px;padding:6px 16px;display:flex;align-items:center;
    gap:10px;flex-wrap:wrap;justify-content:center;">

    <?php if ($previewing): ?>
        <strong>Previewing as <?= htmlspecialchars(ucfirst($effective), ENT_QUOTES) ?></strong>
        <span style="opacity:.75">Your real role is <?= htmlspecialchars(ucfirst(actual_role()), ENT_QUOTES) ?>. Permissions are genuinely restricted while previewing.</span>
    <?php else: ?>
        <span style="opacity:.75">Preview the interface as another role:</span>
    <?php endif; ?>

    <span style="display:inline-flex;gap:6px;">
        <?php foreach (['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer'] as $r => $label):
            $isCurrent = ($effective === $r);
            // 'admin' is the way back to the real role, so it clears rather
            // than sets — preview_role() would refuse it anyway.
            $href = 'preview_role.php?role=' . ($r === actual_role() ? 'clear' : $r)
                  . '&return=' . rawurlencode($previewReturn);
            ?>
            <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"
               style="<?= $pvBtn ?><?= $isCurrent
                   ? 'background:#fff;color:#111;'
                   : 'background:rgba(255,255,255,.14);color:#fff;' ?>"
               <?= $isCurrent ? 'aria-current="true"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </span>

    <?php if ($previewing): ?>
        <a href="preview_role.php?role=clear&return=<?= rawurlencode($previewReturn) ?>"
           style="<?= $pvBtn ?>background:#F59E0B;color:#111;">Exit preview</a>
    <?php endif; ?>
</div>
