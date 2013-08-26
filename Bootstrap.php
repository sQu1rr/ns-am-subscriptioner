<?php

class Bootstrap_Subscriptioner extends Am_Module
{    
	function onUserTabs(Am_Event_UserTabs $event)
    {
        if ($event->getUserId() > 0) {
            $event->getTabs()->addPage(array(
                'id' => 'subscriptioner',
                'module' => 'subscriptioner',
                'controller' => 'admin',
                'action' => 'subscriptions-tab',
                'params' => array(
                    'user_id' => $event->getUserId(),
                ),
                'label' => ___('Subscription Editor'),
                'order' => 1000,
                'resource' => 'subscriptioner',
            ));
        }
    }
}