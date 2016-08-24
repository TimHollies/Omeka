<?php
/**
 * Omeka
 * 
 * @copyright Copyright 2007-2012 Roy Rosenzweig Center for History and New Media
 * @license http://www.gnu.org/licenses/gpl-3.0.txt GNU GPLv3
 */

/**
 * @package Omeka\Controller
 */
class StaticFileController extends Omeka_Controller_AbstractActionController
{
    public function indexAction()
    {
          $test =  $this->_request->getRequestUri();
        $test = preg_replace('/\?.*/', '', $test);
        $passed = preg_match('/\.(?:png|jpg|jpeg|gif|css|ico|js|woff|ttf)$/', $test);

        $path_parts = pathinfo($test);
        switch($path_parts["extension"]) {
            case "js":
                $this->_response->setHeader("CONTENT-TYPE", "text/javascript");
                break;
            case "css":
                $this->_response->setHeader("CONTENT-TYPE", "text/css");
                break;
        }

        $this->_helper->viewRenderer->renderScript($test);
        // $test =  $this->_request->getRequestUri();
        // $test = preg_replace('/\?.*/', '', $test);
        // $passed = preg_match('/\.(?:png|jpg|jpeg|gif|css|ico|js|woff|ttf)$/', $test);
        // if (preg_match('/\.(?:png|jpg|jpeg|gif|css|ico|js|woff|ttf)$/', $test)) {

        //     $path_parts = pathinfo($test);
        //     switch($path_parts["extension"]) {
        //         case "js":
        //             $this->_response->setHeader("CONTENT-TYPE", "text/javascript");
        //             break;
        //         case "css":
        //             $this->_response->setHeader("CONTENT-TYPE", "text/css");
        //             break;
        //     }

            
        //     if (0 === strpos($test, '/admin/application')) {
        //         $test = substr($test, strlen('/admin'));
        //     }

        //     if (0 === strpos($test, '/admin/themes')) {
        //         $test2 = substr($test, strlen('/admin'));
        //         if(file_exists($test2)) {
        //             $test = $test2;
        //         }
        //     }
            
        //     $filepath = BASE_DIR . $test;
            
        //     $this->_response->setBody(file_get_contents($filepath));

        //     $this->_response->sendResponse();
        // }
    }
}
