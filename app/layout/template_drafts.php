<section class="card draft-list">
    <details><summary>Borradores compartidos de esta sucursal</summary>
    <ul><?php foreach (DraftRepository::all($draftKind) as $sharedDraft): ?>
        <li><a href="<?= e($draftEditor) ?>?draft=<?= (int) $sharedDraft['id'] ?>"><?= e($sharedDraft['name']) ?></a> · <?= e($sharedDraft['editor']) ?> · <?= e($sharedDraft['updated_at']) ?></li>
    <?php endforeach; ?></ul>
    <p class="hint">Guardar un borrador conserva el trabajo sin modificar la plantilla publicada. Cualquier usuario de esta sucursal puede continuarlo.</p>
    </details>
</section>
