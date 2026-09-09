<?php
/** @var int $page @var int $pages @var array $filters */
$pages   = max(1, (int) ($pages ?? 1));
$page    = max(1, (int) ($page ?? 1));
$filters = is_array($filters ?? null) ? $filters : [];
$base    = $basePath ?? '';
if ($pages <= 1) { return; }
$start = max(1, $page - 2);
$end   = min($pages, $start + 4);
$start = max(1, $end - 4);
?>
<nav class="pagination" aria-label="Paginacion">
  <?php if ($page > 1): ?>
    <a href="<?= e($base . queryString(['page' => $page - 1], $filters)) ?>">Anterior</a>
  <?php else: ?><span class="is-disabled">Anterior</span><?php endif; ?>

  <?php if ($start > 1): ?>
    <a href="<?= e($base . queryString(['page' => 1], $filters)) ?>">1</a>
    <?php if ($start > 2): ?><span class="is-disabled">…</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <?php if ($i === $page): ?>
      <span class="is-active"><?= $i ?></span>
    <?php else: ?>
      <a href="<?= e($base . queryString(['page' => $i], $filters)) ?>"><?= $i ?></a>
    <?php endif; ?>
  <?php endfor; ?>

  <?php if ($end < $pages): ?>
    <?php if ($end < $pages - 1): ?><span class="is-disabled">…</span><?php endif; ?>
    <a href="<?= e($base . queryString(['page' => $pages], $filters)) ?>"><?= $pages ?></a>
  <?php endif; ?>

  <?php if ($page < $pages): ?>
    <a href="<?= e($base . queryString(['page' => $page + 1], $filters)) ?>">Siguiente</a>
  <?php else: ?><span class="is-disabled">Siguiente</span><?php endif; ?>
</nav>
