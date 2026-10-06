<?php
if (getenv('INTERCOM_CI') !== '1') { throw new RuntimeException('Options rejection fixtures require disposable CI'); }
require __DIR__ . '/bootstrap.php';
$app->loadLanguage();
$app->getLanguage()->load('joomla', JPATH_ADMINISTRATOR);
$app->getLanguage()->load('com_intercom', JPATH_ADMINISTRATOR . '/components/com_intercom');
$userId = (int)$db->setQuery("SELECT id FROM #__users WHERE username='intercom'")->loadResult();
$app->loadIdentity($container->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($userId));
$runtime = $app->bootComponent('com_intercom')->runtime;
$store = $runtime->store;
$original = $store->row("SELECT extension_id AS id,params FROM #__extensions WHERE element='com_intercom' AND type='component'");
$config = json_decode($original['params'], true);
$config = array_merge($config, ['mode'=>'live','group_id'=>899970,'sender_email'=>'sender@example.invalid','unsubscribe_form_id'=>'432342','acceptance_recipient'=>'approved@example.invalid']);
$draftId = 0;
try {
    $store->execute('UPDATE #__extensions SET params='.$store->q(json_encode($config)).' WHERE extension_id='.(int)$original['id']);
    $store->execute("INSERT INTO #__intercom_drafts(owner_id,delivery_mode,content,state,mailing_attempted,filter_id,mailing_id,created_at,updated_at) VALUES ($userId,'live','{}','tested',1,899971,899972,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $draftId = (int)$db->insertid();
    $store->execute("INSERT INTO #__intercom_filters(filter_id,draft_id,group_id,managed) VALUES (899971,$draftId,899970,1)");
    $approval = new \FKT\Component\Intercom\Administrator\Service\ReleaseApproval($store);
    $fingerprint = $approval->fingerprint();
    $store->execute("INSERT INTO #__intercom_acceptance(draft_id,actor_id,fingerprint,recipient_hash,state,created_at,verified_at) VALUES ($draftId,$userId,".$store->q($fingerprint).",".$store->q(hash('sha256', 'approved@example.invalid')).",'verified',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    check($approval->valid(), 'Synthetic approval matches the original list and configuration');
    $model = $app->bootComponent('com_config')->getMVCFactory()->createModel('Component', 'Administrator', ['ignore_request'=>true]);
    $changed = array_merge($config, ['group_id'=>899973]);
    try {
        $model->save(['id'=>(int)$original['id'],'option'=>'com_intercom','params'=>$changed]);
        throw new LogicException('Expected native reservation guard');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'Active CleverReach reservations'), 'Native Options rejects changing list with a live reservation');
        check(str_contains($error->getMessage(), 'InterCOM → Filters'), 'Reservation error identifies the actual reconciliation action');
        $fallback = \Joomla\CMS\Language\Text::_('JERROR_SAVE_FAILED', $error->getMessage());
        check($fallback === 'The InterCOM settings could not be saved.' && !str_contains($fallback, '%s'), 'Joomla controller fallback contains no unformatted placeholder');
    }
    check(json_decode($store->row('SELECT params FROM #__extensions WHERE extension_id='.(int)$original['id'])['params'], true)===$config, 'Rejected Options preserves all stored delivery settings, sender and approved recipient');
    check((int)json_decode($store->row('SELECT params FROM #__extensions WHERE extension_id='.(int)$original['id'])['params'], true)['group_id']===899970, 'Rejected Options leaves the original list unchanged');
    check((int)$store->row('SELECT draft_id FROM #__intercom_filters WHERE filter_id=899971')['draft_id']===$draftId, 'Rejected Options preserves the live reservation');
    check($approval->fingerprint()===$fingerprint && $approval->valid(), 'Rejected list switch preserves the fingerprint and verified acceptance');
    $context = 'com_config.edit.component.com_intercom.data';
    $app->setUserState($context, ['params'=>$changed,'id'=>(int)$original['id'],'option'=>'com_intercom']);
    $app->setUserState('com_config.edit.component.com_content.data', ['other'=>'preserved']);
    $app->getDispatcher()->dispatch('application.before_respond', new \Joomla\Event\Event('application.before_respond'));
    check($app->getUserState($context)===null, 'Redirect response discards the malformed rejected Options form data');
    check($app->getUserState('com_config.edit.component.com_content.data')['other']==='preserved', 'Rejected InterCOM save leaves other component form sessions unchanged');
    foreach (['en-GB','da-DK'] as $language) {
        $strings = parse_ini_file(JPATH_ADMINISTRATOR.'/components/com_intercom/language/'.$language.'/com_intercom.configfailure.ini');
        check(!empty($strings['JERROR_SAVE_FAILED']) && !str_contains($strings['JERROR_SAVE_FAILED'], '%s'), "Safe Options fallback installed for $language");
    }
} finally {
    $store->rollback();
    $store->execute('UPDATE #__extensions SET params='.$store->q($original['params']).' WHERE extension_id='.(int)$original['id']);
    $store->execute('DELETE FROM #__intercom_filters WHERE filter_id=899971');
    $store->execute('DELETE FROM #__intercom_acceptance WHERE draft_id='.$draftId);
    if ($draftId) { $store->execute('DELETE FROM #__intercom_drafts WHERE id='.$draftId); }
}
echo "NATIVE OPTIONS REJECTION OK\n";
