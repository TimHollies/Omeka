<?php
/**
 * Omeka
 * 
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 */

/**
 * This controller plugin allows for all functionality that is specific to the 
 * Admin theme.
 *
 * For now, all this includes is preventing unauthenticated access to all admin 
 * pages, with the exception of a few white-listed URLs, which are stored in 
 * this plugin.
 *
 * This controller plugin should be loaded only in the admin bootstrap.  
 * 
 * @package Omeka\Controller\Plugin
 */
class Omeka_Controller_Plugin_StaticFiles extends Zend_Controller_Plugin_Abstract
{ 
    /**
     * Require login when attempting to access the admin interface.
     * Whitelisted controller/action combinations are exempt from this
     * requirement.
     * Called before dispatching.
     *
     * @param Zend_Controller_Request_Abstract $request
     * @return void
     */
    public function preDispatch(Zend_Controller_Request_Abstract $request)
    {
       // $viewRenderer = Zend_Controller_Action_HelperBroker::getStaticHelper('viewRenderer');

        //$viewRenderer->setNoRender();

        $request->setControllerName("staticfile");
        $request->setActionName("index");
        $request->setDispatched();
    }
}

