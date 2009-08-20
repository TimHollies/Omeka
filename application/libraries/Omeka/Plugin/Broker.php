<?php
/**
 * @version $Id$
 * @copyright Center for History and New Media, 2007-2008
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 * @package Omeka
 * @author CHNM
 **/

/**
 * Plugin Broker for Omeka.
 *
 * This handles installation, loading and calling hooks for plugins.  The
 * syntax for activating plugin hooks on the Broker is as simple as calling
 * it as a method on the broker.
 *
 * For example, $broker->add_action_contexts($controller) would call the 
 * 'add_action_contexts' on all plugins, and it would provide the controller
 * object as the first argument to all implementations of that hook. 
 **/
class Omeka_Plugin_Broker 
{
    protected $_pluginPaths = array();
    
    protected $_basePath = array();
    
    /**
     * Database connection to use when interacting with the database
     *
     * @var Omeka_Db 
     **/
    protected $_db;
    
    /**
     * Array of hooks that have been implemented for plugins.
     *
     * @var array
     **/
    protected $_callbacks = array();

    /**
     * An array of plugin directory names for plugins that have been installed (but not necessarily
     * activated and not necessarily have the plugin files).
     *
     * @var array
     **/
    protected $_installed = array();
    
    /**
     * An array of plugin directory names for currently activated plugins
     *
     * @var array
     **/
    protected $_active = array();
    
    /**
     * An array of plugin directory names for upgradable plugins
     *
     * @var array
     **/
    protected $_upgradeable = array();
    
    /**
     * An array of all plugin directory names for plugins (installed or not) that are currently located
     * in the plugins/ directory
     *
     * @var array
     **/
    protected $_all = array();
    
    /**
     * An associative array of all plugin directory names for plugins that are used optionally by another plugin.
     * The key is a pluginDirName and the value is an array of the plugin directory names of its optional plugins
     *
     * @var array
     **/
    protected $_optional = array();
    
    /**
      * An associative array of all plugin directory names for plugins that are required by another plugin.
      * The key is a pluginDirName and the value is an array of the plugin directory names of its required plugins
      *
      * @var array
      **/
    protected $_required = array();
    
    /**
     * The directory name of the current plugin (used for calling hooks)
     *
     * @var string
     **/
    protected $_current;
        
    /**
     * @see Omeka_Plugin_Broker::addMediaAdapter()
     *
     * @var array
     **/
    protected $_media = array('callbacks'=>array(), 'options'=>array());    
        
    //View script directories that have been added by plugins
    protected $_pluginViewDirs = array();
            
    public function __construct($db, $pathToPlugins) 
    {        
        // Should be able to delegate to the plugin filters
        $this->_delegates = array();
        $this->_delegates[] = new Omeka_Plugin_Filters($this);
        
        $this->_basePath = $pathToPlugins;
        $this->_db = $db;
        
        // Construct the current list of potential, installed & active plugins
        require_once 'VersionedDirectoryIterator.php';
        
        // Loop through all the plugins in the plugin directory, 
        // and add each plugin directory name to the list of all plugin directory names
        $dir = new VersionedDirectoryIterator($this->_basePath);
        $this->_all = $dir->getValid();
        
        // Get the list of currently installed plugin directory names and list of the active plugin directory names
        $this->_active = array();
        $this->_installed = array();
        
        // Loop through the installed plugins and add the plugin directory names to a list. 
        // Deactive upgradable plugins, and add their names to a list.        
        // Finally, add the active plugin directory names to a list.
        $plugins = $this->_db->getTable('Plugin')->findAll();
        foreach ($plugins as $plugin) {
            
            // Get the plugin directory name
            $pluginDirName = $plugin->name;
            
            // Add the plugin directory name to the list of installed plugin directory names
            $this->_installed[$pluginDirName] = $pluginDirName;
            
            // If the plugin is upgradable, then deactive it and store its directory name in a list
            if (version_compare($this->getPluginIniValue($pluginDirName, 'version'), $plugin->version, '>')) {
                if ($plugin->active) {
                    $plugin->active = 0;
                    $plugin->forceSave();
                }
                $this->_upgradeable[$pluginDirName] = $pluginDirName;
            }
            
            // If a plugin is active, store its plugin directory name in a list
            if ($plugin->active) {
                // Add the plugin directory name to the list of active plugin directory names
                $this->_active[$pluginDirName] = $pluginDirName;
            }
        }    
    }
    
    /**
     * Load all active plugins (and make sure the plugin API is available)
     * 
     * @return void
     **/
    public function loadActive()
    {   
        Zend_Registry::set('pluginbroker', $this);     
        foreach ($this->_active as $pluginDirName) {
            $this->load($pluginDirName);
        } 
    }
    
    /**
     * Loads a plugin (and make sure the plugin API is available)
     * 
     * Note: Loading a plugin, activates it for the remainder of the request, 
     * but it does not change its active status in the database
     * @return void
     **/
    public function load($pluginDirName)
    {
        $pluginPath = $this->getPluginFilePath($pluginDirName);
        if (file_exists($pluginPath)) {
            
            //activate the plugin for the remainder of the request
            $this->_active[$pluginDirName] = $pluginDirName;
                
            $this->_pluginPaths[$pluginDirName] = $pluginPath;
            
            // set the current plugin
            $this->setCurrentPluginDirName($pluginDirName);
            
            // add the plugin dir paths and require the plugin files
            $this->addApplicationDirs($pluginDirName);
            require_once $pluginPath;
            
            // set the current plugin back to null
            $this->setCurrentPluginDirName(null);
        }
    }
    
    /**
     * Returns the path to the plugin.php file
     * 
     * @param string $pluginDirName
     * @return string
     **/
    public function getPluginFilePath($pluginDirName)
    {
        return $this->_basePath . DIRECTORY_SEPARATOR . $pluginDirName . DIRECTORY_SEPARATOR . 'plugin.php';
    }
    
    /**
     * Returns the path to the plugin.ini file
     * 
     * @param string $pluginDirName
     * @return string
     **/
    public function getPluginIniFilePath($pluginDirName)
    {
        return $this->_basePath . DIRECTORY_SEPARATOR . $pluginDirName . DIRECTORY_SEPARATOR . 'plugin.ini';
    }
        
    /**
     * Check if the plugin is active, then enable the hook for it
     *
     * @param string $hook
     * @param string $callback
     * @return void
     **/
    public function addHook($hook, $callback)
    {    
        $currentPluginDirName = $this->getCurrentPluginDirName();          
        $this->_callbacks[$hook][$currentPluginDirName] = $callback;
    }
    
    /**
     * Gets the hook for a plugin
     *
     * @param string $pluginDirName
     * @param string $callback
     * @return void
     **/
    public function getHook($pluginDirName, $hook)
    {        
        if (is_array($this->_callbacks[$hook])) {
            return $this->_callbacks[$hook][$pluginDirName];
        }
    }
    
    /**
     * The plugin helper functions do not have any way of determining what
     *  plugin to is currently in focus.  These get/setCurrentPluginDirName methods
     *  allow the broker to know how to delegate to specific plugins if necessary.
     * 
     * @param string $pluginDirName
     * @return void
     **/
    protected function setCurrentPluginDirName($pluginDirName)
    {
        $this->_current = $pluginDirName;
    }
    
    public function getCurrentPluginDirName()
    {
        return $this->_current;
    }
    
    /**
     * Returns whether a plugin is active or not
     * 
     * @param string $pluginDirName
     * @return boolean
     **/
    public function isActive($pluginDirName)
    {
        return in_array($pluginDirName, $this->_active);
    }
    
    /**
     * Returns whether a plugin is installed or not
     * 
     * @param string $pluginDirName
     * @return boolean
     **/
    public function isInstalled($pluginDirName)
    {
        return in_array($pluginDirName, $this->_installed);
    }
    
    /**
     * Returns whether the plugin has both a plugin.php file and a plugin.ini file
     * 
     * @param string $pluginDirName
     * @return boolean
     **/    
    public function hasPluginFiles($pluginDirName)
    {
        return (file_exists($this->getPluginFilePath($pluginDirName)) && file_exists($this->getPluginIniFilePath($pluginDirName)));
    }
    
    /**
     * Returns an array of all of the plugin directory names in the plugin directory
     *
     * @return array
     **/
    public function getAll()
    {
        return $this->_all;
    }
    
    /**
     * Return an array of plugin directory names for the plugins that have not been installed yet
     *
     * @return array
     **/
    public function getNew()
    {
       return array_diff($this->_all, array_keys($this->_installed));
    }
    
    /**
     * Return whether a plugin can be upgraded.  A plugin can be upgrade only if it is installed 
     * and the plugin version in the plugin.ini file is newer than the version in the database
     *
     * @param string $pluginDirName
     * @return boolean
     **/
    public function canUpgrade($pluginDirName)
    {
        return in_array($pluginDirName, $this->_upgradeable);
    }
    
    /**
     * Upgrades the plugin
     *
     * @param string $pluginDirName
     * @return void
     **/
    public function upgrade($pluginDirName)
    {
        //Make sure the plugin helpers that need to be aware of scope can operate on this plugin
        $this->setCurrentPluginDirName($pluginDirName);
        
        if ($this->canUpgrade($pluginDirName)) {
            
            // load the plugin files
            $plugin = $this->_db->getTable('Plugin')->findByDirectoryName($pluginDirName);            
            $this->load($pluginDirName);
            
            // let the plugin do the upgrade
            $oldPluginVersion = $plugin->version;
            $newPluginVersion = $this->getPluginIniValue($pluginDirName, 'version');            
            
            // run the upgrade function in the plugin
            $upgrade_hook = $this->getHook($pluginDirName, 'upgrade');
            call_user_func_array($upgrade_hook, array($oldPluginVersion, $newPluginVersion));            
            
            // update version of the plugin and activate it
            $plugin->version = $newPluginVersion;
            $plugin->forceSave();
            
            // remove the plugin from the list of upgradable plugins
            unset($_upgradeable[$pluginDirName]);
            
            // activate the plugin
            $this->activate($pluginDirName);
            
        } else {
            throw new Exception("Plugin named '$pluginDirName' must be installed and have newer files to upgrade it.");
        }
    }
    
    /**
     * Activates the plugin
     *
     * @param string $pluginDirName
     * @return void
     **/
    public function activate($pluginDirName)
    {
        if ($plugin = $this->_db->getTable('Plugin')->findByDirectoryName($pluginDirName)) {
            $plugin->active = 1;
            $plugin->forceSave();
            $_active[$pluginDirName] = $pluginDirName;
        } else {
            throw new Exception("The plugin in the directory '" . $pluginDirName . "' must be installed to activate.");
        }
    }
    
    /**
     * Deactivates the plugin
     *
     * @param string $pluginDirName
     * @return void
     **/
    public function deactivate($pluginDirName)
    {
        if ($plugin = $this->_db->getTable('Plugin')->findByDirectoryName($pluginDirName)) {
            $plugin->active = 0;
            $plugin->forceSave();
            unset($_active[$pluginDirName]);
        } else {
            throw new Exception("The plugin in the directory '" . $pluginDirName . "' must be installed to deactivate.");
        }
    }
    
    /**
     * Configures the plugin
     *
     * @param string $pluginDirName
     * @return void
     **/
    public function config($pluginDirName)
    {
        //Check if the POST is empty, then check for a configuration form    
        if (empty($_POST)) {

            $config_form_hook = $this->getHook($pluginDirName, 'config_form');
    
            //If there is a configuration form available, load that and return the output for rendering later
            if ($config_form_hook) {
                
                require_once HELPERS;
                
                ob_start();
                call_user_func_array($config_form_hook, array($_POST)); 
                $config = ob_get_clean();    
                
                return $config;
            }
        
        //Data has been POSTed to the configuration mechanism
        } else {
            
            //Run the 'config' hook, then run the rest of the installer
            $config_hook = $this->getHook($pluginDirName, 'config');
            
            if ($config_hook) {
                call_user_func_array($config_hook, array($_POST));
            }
        }
    }
    
    /**
     * Installs the plugin
     *
     * @param string $pluginDirName
     * @return void
     **/
    public function install($pluginDirName) 
    {
        //Make sure the plugin helpers that need to be aware of scope can operate on this plugin
        $this->setCurrentPluginDirName($pluginDirName);
        
        //Include the plugin file manually because it was not included via the constructor
        $file = $this->getPluginFilePath($pluginDirName);
        if (file_exists($file)) {
            $this->addApplicationDirs($pluginDirName);
            require_once $file;
        } else {
            throw new Exception("Plugin named '$pluginDirName' requires at minimum a file named 'plugin.php' to exist.  Please add this file or remove the '$pluginDirName' directory.");
        }

        try {            
            $plugin = new Plugin;
            $plugin->active = 1;
            $plugin->name = $pluginDirName;
            $plugin->version = $this->getPluginIniValue($pluginDirName, 'version');
            $plugin->forceSave();
            
            //Now run the installer for the plugin
            $install_hook = $this->getHook($pluginDirName, 'install');
            call_user_func_array($install_hook, array($plugin->id));
                       
        } catch (Exception $e) {
            //If there was an error, remove the plugin from the DB so that we can retry the install
            if ($plugin->exists()) {
                $plugin->delete();
            }
            throw new Exception($e->getMessage());
        }
    }
        
    /**
     * used by the add_theme_pages() helper to create a list of directories that can store static pages that integrate into the themes
     *
     * @param string $pluginDirName
     * @param string $path
     * @param string $themeType
     * @param string $moduleName
     * @return void
     **/
    protected function addThemeDir($pluginDirName, $path, $themeType, $moduleName)
    {
        if (!in_array($themeType, array('public','admin','shared'))) {
            return false;
        }
        
        //Path must begin from within the plugin's directory
        
        $path = $pluginDirName . DIRECTORY_SEPARATOR . $path;
                
        switch ($themeType) {
            case 'public':
                $this->_pluginViewDirs[$moduleName]['public'][] = $path;
                break;
            case 'admin':
                $this->_pluginViewDirs[$moduleName]['admin'][] = $path;
                break;
            case 'shared':
                $this->_pluginViewDirs[$moduleName]['public'][] = $path;
                $this->_pluginViewDirs[$moduleName]['admin'][] = $path;
                break;
            default:
                break;
        }
    }
    
    public function getModuleViewScriptDirs($moduleName=null)
    {
        if ($moduleName) {
            return $this->_pluginViewDirs[$moduleName];
        }
        return $this->_pluginViewDirs;
    }
    
    /**
     * This will make an entire directory of controllers available to the front controller.
     * 
     * This has to use addControllerDirectory() instead of addModuleDirectory() because module names
     * are case-sensitive and module directories need to be lowercased to conform to Zend's weird naming conventions.
     *
     * @param string $pluginDirName
     * @param string $moduleName 
     * @return void
     **/
    public function addControllerDir($pluginDirName, $moduleName)
    {                
        $contrDir = PLUGIN_DIR . DIRECTORY_SEPARATOR . $pluginDirName . DIRECTORY_SEPARATOR . 'controllers';
        Zend_Controller_Front::getInstance()->addControllerDirectory($contrDir, $moduleName);
    }
    
    /**
     * Set up the following directory structure for plugins:
     * 
     *      controllers/
     *      models/
     *      libraries/
     *      views/
     *          admin/
     *          public/
     *          shared/
     * 
     *  This also adds these folders to the correct include paths.
     *  
     * @param string $pluginDirName
     * @return void
     **/
    public function addApplicationDirs($pluginDirName)
    {        
        $baseDir = $this->_basePath . DIRECTORY_SEPARATOR . $pluginDirName;
        
        $modelDir      = $baseDir . DIRECTORY_SEPARATOR  . 'models';
        $controllerDir = $baseDir . DIRECTORY_SEPARATOR  . 'controllers';
        $librariesDir  = $baseDir . DIRECTORY_SEPARATOR  . 'libraries';
        $viewsDir      = $baseDir . DIRECTORY_SEPARATOR  . 'views';
        $adminDir      = $viewsDir . DIRECTORY_SEPARATOR . 'admin';
        $publicDir     = $viewsDir . DIRECTORY_SEPARATOR . 'public';
        $sharedDir     = $viewsDir . DIRECTORY_SEPARATOR . 'shared';
        
        //Add 'models' and 'libraries' directories to the include path
        if (file_exists($modelDir)) {
            set_include_path(get_include_path() . PATH_SEPARATOR . $modelDir );
        }
        
        if (file_exists($librariesDir)) {
            set_include_path(get_include_path() . PATH_SEPARATOR . $librariesDir);
        }
        
        $moduleName = $this->_getModuleName($pluginDirName);

        //If the controller directory exists, add that 
        if (file_exists($controllerDir)) {
            $this->addControllerDir($pluginDirName, $moduleName);   
        }
        
        if (file_exists($sharedDir)) {
            $this->addThemeDir($pluginDirName, 'views' . DIRECTORY_SEPARATOR . 'shared', 'shared', $moduleName);
        }
        
        if (file_exists($adminDir)) {
            $this->addThemeDir($pluginDirName, 'views' . DIRECTORY_SEPARATOR . 'admin', 'admin', $moduleName);
        }

        if (file_exists($publicDir)) {
            $this->addThemeDir($pluginDirName, 'views' . DIRECTORY_SEPARATOR . 'public', 'public', $moduleName);
        }

    }
    
    /**
     * Retrieve the module name for the plugin (based on the directory name
     * of the plugin).
     * 
     * @param string $pluginDirName
     * @return string
     **/
    protected function _getModuleName($pluginDirName)
    {
        // Module name needs to be lowercased (plugin directories are not, 
        // typically).  Module name needs to go from camelCased to dashed 
        // (ElementSets --> element-sets).
        $inflector = new Zend_Filter_Word_CamelCaseToDash();
        $moduleName = strtolower($inflector->filter($pluginDirName));
        return $moduleName;
    }
    
    /**
     * Adds a plugin hook to display files of a specific MIME type in a certain way.
     * 
     * This allows plugins to hook directly into the Omeka_View_Helper_Media
     * class, so that plugins can override/define ways of displaying specific
     * files.  The most obvious example of where this would come in handy is
     * to define ways of displaying uncommon files, such as QTVR, or novel ways
     * of displaying more common files, such as using iPaper to display PDFs.
     *
     * The advantage is seemless integration with the themes, rather than
     * forcing theme designers to use plugin-specific API calls in their themes.
     *
     * @internal This operates on two keyed lists: a list of callbacks, which is
     * keyed to the MIME type, i.e. array('video/wmv'=>'foobar_movie_display').
     * The second list is the set of default options for the callback, which
     * can be overridden during the actual display_files() call within the theme.
     * 
     * @param array|string $mimeTypes Set of MIME types that this specific
     * callback will respond to.
     * @param callback Any valid callback.  This function should return a
     * string containing valid XHTML, which will be used to display the file.
     * @return void
     **/
    public function addMediaAdapter($mimeTypes, $callback, array $defaultOptions = array())
    {
        //Create the keyed list of mimeType=>callback format, and merge it
        //with the current list.
        $mimeTypes = (array) $mimeTypes;
        $fillArray = array_fill(0, count($mimeTypes), $callback);    
        $callbackList = array_combine($mimeTypes, $fillArray);
        
        $this->_media['callbacks'] = array_merge($callbackList, $this->_media['callbacks']);
        
        //Create the keyed list of callback=>options format, and add it 
        //to the current list
        
        //The key for the array might be the serialized callback (if necessary)
        $callbackKey = !is_string($callback) ? serialize($callback) : $callback;
        $this->_media['options'][$callbackKey] = $defaultOptions;        
    }
    
    /**
     * Retrieve a list of all media display callbacks that are defined by
     * plugins.  Currently called only within Omeka_View_Helper_Media
     *
     * @see Omeka_View_Helper_Media::__construct()
     * @return array
     **/
    public function getMediaAdapters()
    {        
        return $this->_media;
    }
    
    /**
     * Uninstall hook for plugins.  
     *
     * This will run the 'uninstall' hook for the given plugin, and then it
     * will remove the entry in the DB corresponding to the plugin.
     * 
     * @param string $pluginDirName Name of the plugin directory to uninstall
     * @return void
     **/
    public function uninstall($pluginDirName)
    {
        try {
            $uninstallHook = $this->getHook($pluginDirName, 'uninstall');
            if ($uninstallHook) {
                call_user_func($uninstallHook);
            }
            //Remove the entry from the database
            $this->_db->query("DELETE FROM {$this->_db->Plugin} WHERE name = ? LIMIT 1", array($pluginDirName));
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * Returns a value in plugin.ini for a key
     *
     * Will return an empty string if no value can be found in the ini file for the key.
     * 
     * @param string $pluginDirName
     * @param string $iniKeyName
     * @return string
     **/
    public function getPluginIniValue($pluginDirName, $iniKeyName)
    {
        $pluginIniPath = $this->getPluginIniFilePath($pluginDirName);
        if (file_exists($pluginIniPath)) {
            try {
                $config = new Zend_Config_Ini($pluginIniPath, 'info');
            } catch(Exception $e) {
    			throw $e;
    		}   
        } else {
    		throw new Exception("Path to plugin.ini for '$pluginDirName' is not correct.");
    	}
        return $config->$iniKeyName;
    } 
    
    /**
     * Returns an array of the plugin directory names for the plugins that the plugin requires
     * 
     * @param string $pluginDirName
     * @return array
     **/
    public function getRequiredPluginDirNames($pluginDirName)
    {
        if ($this->_required[$pluginDirName] == null) {            
            $this->_required[$pluginDirName] = array();
            $rPluginDirNames = explode(',', trim(get_plugin_ini($pluginDirName, 'required_plugins')));
            if(count($rPluginDirNames) == 1 && trim($rPluginDirNames[0]) == '') {
                $rPluginDirNames = array();
            }
            $this->_required[$pluginDirName] = $rPluginDirNames;
        }

        return $this->_required[$pluginDirName];
    }
    
    /**
     * Returns an array of the plugin directory names for the plugins that the plugin optionally uses
     * 
     * @param string $pluginDirName
     * @return array
     **/
    public function getOptionalPluginDirNames($pluginDirName)
    {
        if ($this->_optional[$pluginDirName] == null) {
            $this->_optional[$pluginDirName] = array();
            $oPluginDirNames = explode(',', trim(get_plugin_ini($pluginDirName, 'optional_plugins')));
            if(count($oPluginDirNames) == 1 && trim($oPluginDirNames[0]) == '') {
                $oPluginDirNames = array();
            }
            $this->_optional[$pluginDirName] = $oPluginDirNames;
        }

        return $this->_optional[$pluginDirName];
    }
    
    /**
     * @see Omeka_Plugin_Broker::__call()
     * @param string Name of the hook.
     * @param array Arguments that are passed to each hook implementation.
     * @return array Keyed to the names of plugins, this will contain an array of 
     * all the return values of the hook implementations.
     **/
    public function callHook($hook, $args)
    {
        if (empty($this->_callbacks[$hook])) {
            return;
        }
        
        $return_values = array();        
        foreach ($this->_callbacks[$hook] as $pluginDirName => $callback) {
            if ($this->isActive($pluginDirName)) {
                //Make sure the callback executes within the scope of the current plugin
                $this->setCurrentPluginDirName($pluginDirName);
                $return_values[$pluginDirName] = call_user_func_array($callback, $args);
            }
        }
        
        // Reset the value for current plugin after this loop finishes
        $this->setCurrentPluginDirName(null);
        
        return $return_values;        
    }
    
    /**
     * This handles dispatching all plugin hooks.
     *
     * Check for delegating to other classes that handle plugin API stuff first,
     * i.e. Omeka_Plugin_Filters etc.
     *
     * @see Omeka_Plugin_Broker::__construct()
     * @return mixed
     **/
    public function __call($hook, $args) 
    {
        // Delegation
        foreach ($this->_delegates as $delegator) {
            if(method_exists($delegator, $hook)) {
                return call_user_func_array(array($delegator, $hook), $args);
            }
        }
        
        // Call the hook for the plugins
        return $this->callHook($hook, $args);
    }
}