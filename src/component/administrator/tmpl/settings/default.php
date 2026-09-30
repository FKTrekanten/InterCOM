<?php

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;
use FKT\Component\Intercom\Administrator\Domain\Message;

$r = $this->runtime;
$esc = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$t = static fn ($key) => Text::_('COM_INTERCOM_' . $key);
$locale = Factory::getApplication()->getLanguage()->getTag();
ToolbarHelper::title($t('COMPONENT_SETTINGS'), 'envelope');
ToolbarHelper::preferences('com_intercom');
$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
$wa->usePreset('choicesjs')->useScript('webcomponent.field-fancy-select')->useScript('core');
?>
<nav class="mb-4 d-flex gap-3 flex-wrap" aria-label="<?= $t('COMPONENT_SETTINGS') ?>">
<?php foreach (['types' => 'COMMUNICATION_GROUPS', 'tags' => 'RECIPIENT_TAGS', 'access' => 'AUDIENCE_ACCESS'] as $section => $label) : ?>
<a href="index.php?option=com_intercom&amp;view=settings&amp;section=<?= $section ?>" <?= $this->section === $section ? 'aria-current="page"' : '' ?>><?= $t($label) ?></a>
<?php endforeach; ?><a href="index.php?option=com_intercom"><?= $t('AUDIT') ?></a></nav>
<?php if ($this->section === 'types' && $this->record) :
    $row = $this->record;
    $definition = (int) $row['id'] ? $r->catalog->definition($row) : ['translations' => []]; ?>
<form action="index.php?option=com_intercom&amp;task=management.savetype" method="post" id="adminForm" name="adminForm">
    <?= HTMLHelper::_('form.token') ?>
<input type="hidden" name="jform[id]" value="<?= (int) $row['id'] ?>"><input type="hidden" name="jform[revision]" value="<?= (int) $row['revision'] ?>">
<div class="row g-3 mb-4">
<div class="col-md-4"><label for="type-key" class="form-label"><?= $t('TYPE_KEY') ?></label><input class="form-control" id="type-key" name="jform[type_key]" value="<?= $esc($row['type_key']) ?>" required pattern="[a-z][a-z0-9_-]{0,63}" <?= $row['id'] ? 'readonly' : '' ?>></div>
<div class="col-md-4"><label for="suppression" class="form-label"><?= $t('SUPPRESSION_WORD') ?></label><input class="form-control" id="suppression" name="jform[suppression]" value="<?= $esc($row['suppression']) ?>" required maxlength="128"></div>
<div class="col-md-4"><label for="category-id" class="form-label"><?= $t('CATEGORY_ID') ?></label><input class="form-control" id="category-id" name="jform[category_id]" type="number" min="0" value="<?= (int) $row['category_id'] ?>"></div>
<div class="col-md-4"><label for="type-state" class="form-label"><?= $t('PUBLICATION') ?></label><select class="form-select" id="type-state" name="jform[state]">
    <?php foreach ([1 => 'PUBLISHED', 0 => 'UNPUBLISHED', -2 => 'ARCHIVED'] as $value => $label) :
        ?><option value="<?= $value ?>" <?= (int) $row['state'] === $value ? 'selected' : '' ?>><?= $t($label) ?></option><?php
    endforeach; ?></select></div>
<div class="col-md-4"><label for="type-ordering" class="form-label"><?= $t('ORDERING') ?></label><input class="form-control" id="type-ordering" name="jform[ordering]" type="number" value="<?= (int) $row['ordering'] ?>"></div>
<div class="col-md-4"><label for="require-group" class="form-label"><?= $t('REQUIRE_GROUP') ?></label><input id="require-group" type="checkbox" name="jform[require_group]" value="1" <?= $row['require_group'] ? 'checked' : '' ?>></div>
</div><p class="alert alert-warning"><?= $t('SUPPRESSION_HELP') ?></p>
    <?= HTMLHelper::_('uitab.startTabSet', 'translations', ['active' => $r->catalog->defaultLanguage()]) ?>
    <?php foreach ($r->catalog->languages() as $language => $name) :
        $translation = $definition['translations'][$language] ?? [];
        echo HTMLHelper::_('uitab.addTab', 'translations', $language, $name);
        foreach (['name' => 'TRANSLATED_NAME', 'description' => 'TRANSLATED_DESCRIPTION', 'subject_prefix' => 'SUBJECT_PREFIX', 'heading' => 'EMAIL_HEADING'] as $field => $label) : ?>
<div class="mb-3"><label class="form-label" for="<?= $esc($language . '-' . $field) ?>"><?= $t($label) ?></label>
            <?php if ($field === 'description') :
                ?><textarea class="form-control" id="<?= $esc($language . '-' . $field) ?>" name="jform[translations][<?= $esc($language) ?>][<?= $field ?>]" rows="3" maxlength="2000"><?= $esc($translation[$field] ?? '') ?></textarea>
            <?php else :
                ?><input class="form-control" id="<?= $esc($language . '-' . $field) ?>" name="jform[translations][<?= $esc($language) ?>][<?= $field ?>]" value="<?= $esc($translation[$field] ?? '') ?>" maxlength="<?= $field === 'subject_prefix' ? 80 : 255 ?>"><?php
            endif; ?></div>
        <?php endforeach;
        echo HTMLHelper::_('uitab.endTab');
    endforeach;
    echo HTMLHelper::_('uitab.endTabSet'); ?>
<p><?= $t('TRANSLATION_HELP') ?></p><h2><?= $t('GROUP_PERMISSIONS') ?></h2><?= $this->form->getInput('asset_id') ?><?= $this->form->getInput('rules') ?>
<button type="submit" class="btn btn-primary"><?= $t('SAVE_CHANGES') ?></button> <a class="btn btn-secondary" href="index.php?option=com_intercom&amp;view=settings"><?= $t('BACK') ?></a>
    <?php if ($row['id']) :
        ?><button type="submit" class="btn btn-danger" formaction="index.php?option=com_intercom&amp;task=management.deletetype" formnovalidate><?= $t('DELETE_UNUSED') ?></button><?php
    endif; ?>
</form>
<?php elseif ($this->section === 'types') : ?>
<h2><?= $t('COMMUNICATION_GROUPS') ?></h2><p><?= $t('GROUPS_HELP') ?></p><a class="btn btn-primary mb-3" href="index.php?option=com_intercom&amp;view=settings&amp;section=types&amp;edit=1"><?= $t('NEW_GROUP') ?></a>
<div class="table-responsive"><table class="table"><thead><tr><th><?= $t('TRANSLATED_NAME') ?></th><th><?= $t('SUPPRESSION_WORD') ?></th><th><?= $t('PUBLICATION') ?></th><th><?= $t('ORDERING') ?></th></tr></thead><tbody>
    <?php foreach ($this->definitions as $row) :
        $copy = Message::translation($r->catalog->definition($row), $locale); ?>
<tr><td><a href="index.php?option=com_intercom&amp;view=settings&amp;section=types&amp;edit=1&amp;id=<?= (int) $row['id'] ?>"><?= $esc($copy['name'] ?: $row['type_key']) ?></a></td><td><?= $esc($row['suppression']) ?></td><td><?= $t([1 => 'PUBLISHED', 0 => 'UNPUBLISHED', -2 => 'ARCHIVED'][(int) $row['state']]) ?></td><td><?= (int) $row['ordering'] ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?= $this->pagination->getPagesLinks() ?><?= $this->pagination->getResultsCounter() ?>
<?php elseif ($this->section === 'tags') :
    $meta = $r->store->row('SELECT * FROM #__intercom_catalogues WHERE list_id=' . $r->catalog->context()); ?>
<h2><?= $t('RECIPIENT_TAGS') ?></h2><p><?= $t('TAG_CATALOGUE_HELP') ?></p><p><?= $t('LAST_REFRESH') ?>: <?= $esc($meta['refreshed_at'] ?? '-') ?> UTC</p>
<form action="index.php?option=com_intercom&amp;task=management.refreshtags" method="post" class="mb-3"><?= HTMLHelper::_('form.token') ?><button class="btn btn-secondary"><?= $t('REFRESH_TAGS') ?></button></form>
<form action="index.php?option=com_intercom&amp;task=management.savetags" method="post"><?= HTMLHelper::_('form.token') ?><input type="hidden" name="jform[revision]" value="<?= (int) ($meta['revision'] ?? 0) ?>">
    <?php foreach (['group' => 'GROUP_HELP', 'membership' => 'MEMBERSHIPS'] as $prefix => $label) : ?>
<h3><?= $t($label) ?> (<?= $prefix ?>.*)</h3><div class="table-responsive"><table class="table"><thead><tr><th><?= $t('SHOW_TAG') ?></th><th><?= $t('TAG') ?></th><th><?= $t('AVAILABILITY') ?></th><th><?= $t('ORDERING') ?></th></tr></thead><tbody>
        <?php foreach ($r->catalog->tags(false) as $row) :
            if (!str_starts_with($row['tag'], $prefix . '.')) {
                continue;
            } ?>
<tr><td><input type="checkbox" name="jform[enabled][]" value="<?= $esc($row['tag']) ?>" aria-label="<?= $esc($row['tag']) ?>" <?= $row['enabled'] && $row['available'] ? 'checked' : '' ?> <?= !$row['available'] ? 'disabled' : '' ?>></td><td><?= $esc($row['tag']) ?></td><td><?= $t($row['available'] ? 'AVAILABLE' : 'UNAVAILABLE') ?></td><td><input class="form-control" type="number" name="jform[ordering][<?= $esc($row['tag']) ?>]" value="<?= (int) $row['ordering'] ?>" aria-label="<?= $t('ORDERING') ?> <?= $esc($row['tag']) ?>"></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endforeach; ?><button class="btn btn-primary" <?= !$meta ? 'disabled' : '' ?>><?= $t('SAVE_CHANGES') ?></button></form>
<?php elseif ($this->section === 'access') :
    $rules = json_decode($r->config['audience_rules'] ?? '[]', true) ?: [];
    $scope = array_column($rules, null, 'group'); ?>
<h2><?= $t('AUDIENCE_ACCESS') ?></h2><p><?= $t('AUDIENCE_ACCESS_HELP') ?></p>
<form action="index.php?option=com_intercom&amp;task=management.savescopes" method="post"><?= HTMLHelper::_('form.token') ?><input type="hidden" name="jform[revision]" value="<?= $esc(hash('sha256', json_encode($rules))) ?>">
<div class="table-responsive"><table class="table"><thead><tr><th><?= $t('JOOMLA_GROUP') ?></th><th><?= $t('ALL_AUDIENCE') ?></th><th><?= $t('ALLOWED_TAGS') ?></th></tr></thead><tbody>
    <?php foreach ($r->store->rows('SELECT id,title FROM #__usergroups ORDER BY lft') as $group) :
        $id = (int) $group['id'];
        $selected = $scope[$id]['tags'] ?? [];
        $tags = array_values(array_unique(array_merge(array_column($r->catalog->tags(), 'tag'), $selected))); ?>
<tr><td><?= $esc($group['title']) ?></td><td><input type="checkbox" name="jform[scopes][<?= $id ?>][all]" value="1" aria-label="<?= $esc($group['title']) ?> <?= $t('ALL_AUDIENCE') ?>" <?= !empty($scope[$id]['all']) ? 'checked' : '' ?>></td><td><joomla-field-fancy-select><select multiple name="jform[scopes][<?= $id ?>][tags][]" aria-label="<?= $esc($group['title']) ?> <?= $t('ALLOWED_TAGS') ?>">
        <?php foreach ($tags as $tag) :
            if (!str_starts_with($tag, 'group.')) {
                continue;
            } ?><option value="<?= $esc($tag) ?>" <?= in_array($tag, $selected, true) ? 'selected' : '' ?>><?= $esc($tag) ?></option><?php
        endforeach; ?>
</select></joomla-field-fancy-select></td></tr>
    <?php endforeach; ?></tbody></table></div><button class="btn btn-primary"><?= $t('SAVE_CHANGES') ?></button></form>
<?php endif; ?>
