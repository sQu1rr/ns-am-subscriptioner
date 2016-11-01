<?php
/**
 * Boostraps the subscriptioner
 * Used template from official tutorial
 *
 * @copyright 2016 Linguistica 360
 */

class Bootstrap_Subscriptioner extends Am_Module {
	function onUserTabs(Am_Event_UserTabs $event)
    {
        if ($event->getUserId() > 0) { // when user is selected
            $event->getTabs()->addPage([
                'id' => 'subscriptioner',
                'module' => 'subscriptioner',
                'controller' => 'admin',
                'action' => 'subscriptions-tab',
                'params' => [ 'user_id' => $event->getUserId() ],
                'label' => 'Subscription Editor',
                'order' => 1000, // let it be last tab
                'resource' => 'subscriptioner'
            ]);
        }
    }

    function onBeforeRender($event)
    {
        $name = $event->getTemplateName();
        if (preg_match('/admin\\/user-invoices\\.phtml$/', $name)) {
            $text = 'Adding subscription should be done from Subscriptioner';
            $event->getView()->accessForm->save = "<span>$text</span>";
        }
    }
}
