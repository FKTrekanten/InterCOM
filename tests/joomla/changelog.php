<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Changelog fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
use Joomla\CMS\Changelog\Changelog;

$app->loadDocument()->loadLanguage();
$version = trim(file_get_contents('/workspace/VERSION'));
$model = $app->bootComponent('com_installer')->getMVCFactory()->createModel('Manage', 'Administrator', ['ignore_request'=>true]);
$extensions = $db->setQuery("SELECT extension_id,element,type,folder,changelogurl FROM #__extensions WHERE element IN ('pkg_intercom','com_intercom') OR (element='intercom' AND folder IN ('task','extension'))")->loadObjectList();
check(count($extensions) === 4, 'All package extensions expose their native changelogs');
$fixtures = [];
$db->transactionStart();
try {
    foreach ($extensions as $extension) {
        $file = basename($extension->changelogurl);
        $fixture = JPATH_SITE . '/intercom-ci-' . $file;
        if (file_exists($fixture)) { throw new RuntimeException('Unexpected existing changelog fixture'); }
        $fixtures[] = $fixture;
        copy('/workspace/updates/' . $file, $fixture);
        $url = 'http://127.0.0.1/intercom-ci-' . $file;
        $changelog = new Changelog();
        $changelog->setVersion($version);
        check($changelog->loadFromXml($url), 'Native Joomla parser loads ' . $file);
        check($changelog->get('version')->data === $version && $changelog->get('element')->data === $extension->element && $changelog->get('type')->data === $extension->type, 'Changelog matches installed version and extension identity');
        if ($extension->type === 'plugin') {
            check($changelog->get('folder')->data === $extension->folder, 'Plugin changelog identifies its group');
        }
        $items = $changelog->get('fix')->data;
        check(count($items) > 0 && count($changelog->get('note')->data) > 0, 'Native parser exposes fixes and upgrade notes');
        // Joomla's translated manifest metadata also supplies the changelog URL.
        $db->setQuery('UPDATE #__extensions SET changelogurl=' . $db->quote($url)
            . ',manifest_cache=JSON_SET(manifest_cache,' . $db->quote('$.changelogurl') . ',' . $db->quote($url) . ') WHERE extension_id=' . (int)$extension->extension_id)->execute();
        $output = $model->loadChangelog((int)$extension->extension_id, 'manage');
        check(str_contains($output, $items[0]), 'Joomla extension manager renders the installed release notes');
        $missing = new Changelog();
        $missing->setVersion('99.0.0');
        check($missing->loadFromXml($url) && $missing->get('fix') === [], 'Unknown versions do not display another release changelog');
    }
} finally {
    $db->transactionRollback();
    foreach ($fixtures as $fixture) { unlink($fixture); }
}
echo "JOOMLA CHANGELOG OK\n";
