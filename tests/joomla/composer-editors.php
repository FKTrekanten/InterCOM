<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Editor fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';

use FKT\Component\Intercom\Administrator\Domain\Message;
use Joomla\CMS\Editor\EditorsRegistry;
use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserFactoryInterface;

$app->loadDocument()->loadLanguage();
$r = $app->bootComponent('com_intercom')->runtime;
$user = $container->get(UserFactoryInterface::class)->loadUserByUsername('intercom');
$app->loadIdentity($user);
$app->getDocument()->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_intercom');
$view = new class ($r) {
    public function __construct(public $runtime) {}
    public function render(): string
    {
        ob_start();
        try {
            include JPATH_SITE . '/components/com_intercom/tmpl/composer/default.php';
            return ob_get_contents();
        } finally { ob_end_clean(); }
    }
};

// Exercise Joomla's real legacy plugin path without installing JCE or changing site files.
$dispatcher = $container->get('dispatcher');
$plugin = new class ($dispatcher, ['name'=>'intercomfixture', 'type'=>'editors', 'params'=>'{}']) extends CMSPlugin {
    public function onDisplay($name, $content, $width, $height, $col, $row, $buttons = true, $id = null, $asset = null, $author = null, $params = [])
    {
        if ($buttons !== false || $id !== $name) { throw new RuntimeException('Incorrect legacy editor options'); }
        return '<textarea data-legacy-editor="1" name="'.$name.'" id="'.$id.'" style="width:'.$width.';height:'.$height.'px">'.$content.'</textarea>';
    }
};
ExtensionHelper::$extensions[PluginInterface::class]['intercomfixture:editors'] = $plugin;
$r->store->begin();
try {
    $record = $db->setQuery("SELECT * FROM #__extensions WHERE type='plugin' AND element='none' AND folder='editors'")->loadObject();
    unset($record->extension_id);
    $record->name = 'CI legacy editor';
    $record->element = 'intercomfixture';
    $record->namespace = '';
    $record->enabled = 1;
    $record->access = 1;
    $record->params = '{}';
    $db->insertObject('#__extensions', $record);
    $draft = $r->workflow($user)->save(['type'=>'club', 'format'=>'html', 'sender'=>'Club', 'tags'=>[], 'subject_da'=>'DA', 'subject_en'=>'EN',
        'body_da'=>'<p>Æbler &amp; pærer &lt;/textarea&gt;</p>', 'body_en'=>'<p>English <strong>formatting</strong> &amp; entities</p>']);
    $content = json_decode($draft['content'], true);
    $app->input->set('id', (int)$draft['id']);
    $user->setParam('editor', 'intercomfixture');
    $page = $view->render();
    check(!$container->get(EditorsRegistry::class)->has('intercomfixture'), 'Legacy editor remains outside the modern registry');
    check(substr_count($page, 'data-legacy-editor="1"') === 2, 'Enabled legacy editor renders both composer languages');
    foreach (['da','en'] as $lang) {
        preg_match('/<textarea[^>]*id="body_'.$lang.'"[^>]*>(.*?)<\/textarea>/s', $page, $match);
        check(isset($match[1]) && html_entity_decode($match[1], ENT_QUOTES, 'UTF-8') === Message::bodyHtml($content, $lang), 'Saved '.$lang.' HTML round-trips through the legacy textarea');
    }
    $app->set('editor', 'intercomfixture');
    $user->setParam('editor', '');
    check(substr_count($view->render(), 'data-legacy-editor="1"') === 2, 'Empty user preference uses the configured global editor');
    $user->setParam('editor', 'unavailable-editor');
    check(substr_count($view->render(), '<joomla-editor-none>') === 2, 'Unavailable editor falls back to editable plain text');
    $user->setParam('editor', 'tinymce');
    $page = $view->render();
    check(substr_count($page, 'class="js-editor-tinymce"') === 2, 'Modern TinyMCE still renders both composer languages');
    check($app->getDocument()->getScriptOptions('plg_editor_tinymce')['tinyMCE']['body_en']['menubar'] === false, 'TinyMCE retains email-safe formatting options');
} finally {
    $r->store->rollback();
    unset(ExtensionHelper::$extensions[PluginInterface::class]['intercomfixture:editors']);
}
echo "COMPOSER EDITORS OK\n";
