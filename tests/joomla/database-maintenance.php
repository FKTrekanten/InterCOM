<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Database repair checks require disposable CI'); }
require __DIR__ . '/bootstrap.php';
$extension = $db->setQuery("SELECT extension_id,manifest_cache FROM #__extensions WHERE type='component' AND element='com_intercom'")->loadAssoc();
$extensionId = (int)$extension['extension_id'];
$factory = $app->bootComponent('com_installer')->getMVCFactory();
$model = static function () use ($factory,$extensionId) {
    $model = $factory->createModel('Database', 'Administrator', ['ignore_request'=>true]);
    $model->setState('filter.extension_id',$extensionId);
    $model->setState('list.limit',20);
    return $model;
};
$inspect = static function () use ($model): array {
    $items = $model()->getItems();
    check(count($items)===1,'Native Database maintenance includes InterCOM');
    return $items[0];
};
$before = $inspect();
check($before['extension']->version_id===$before['schema'],'Database version matches the latest SQL migration');
check(json_decode($extension['manifest_cache'],true)['version']===trim(file_get_contents('/workspace/VERSION')),'Installed manifest tracks the extension release independently of its SQL migration');
$changes = new \Joomla\CMS\Schema\ChangeSet($db,JPATH_ADMINISTRATOR.'/components/com_intercom/sql/updates');
foreach ($changes->check() as $error) {
    echo 'DATABASE CHECK: '.basename($error->file).' '.$error->queryType.' '.implode(', ',$error->msgElements)."\n";
}
$snapshot = static function () use ($db,$extensionId): array {
    $data=[];
    foreach (['intercom_tags','intercom_types','intercom_type_translations','intercom_drafts','intercom_connections'] as $table) {
        $data[$table]=$db->setQuery('SELECT * FROM #__'.$table)->loadAssocList();
    }
    $data['params']=$db->setQuery('SELECT params FROM #__extensions WHERE extension_id='.$extensionId)->loadResult();
    $data['permissions']=$db->setQuery("SELECT name,rules FROM #__assets WHERE name='com_intercom' OR name LIKE 'com_intercom.communication.%' ORDER BY id")->loadAssocList();
    return $data;
};
$data = $snapshot();
$model()->fix([$extensionId]);
$after = $inspect();
check($after['errorsCount']===0,'Native Update Structure leaves no InterCOM problems'.($after['errorsCount'] ? ': '.implode(' | ',$after['errorsMessage']) : ''));
check($before['errorsCount']===0,'Native Database maintenance reports no problems after installation or upgrade');
check($snapshot()===$data,'Update Structure preserves tags, communications, drafts, credentials, settings and permissions');
echo "DATABASE MAINTENANCE OK\n";
