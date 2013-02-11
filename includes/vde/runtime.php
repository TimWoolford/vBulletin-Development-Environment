<?php
/**
 * Handles automatically injecting product information into memory for
 * rapid plugin/product development.
 *
 * @package     VDE
 * @author      AdrianSchneider
 */
class VDE_Runtime {
    /**
     * Content type flags
     * @var     integer
     */
    const ENABLE_TEMPLATES = 1;
    const ENABLE_PLUGINS   = 2;
    const ENABLE_PHRASES   = 4;
    const ENABLE_OPTIONS   = 8;
    const ENABLE_STYLE     = 16;
    const ENABLE_NAVBAR    = 32;
    const ENABLE_ALL       = 63;

    /**
     * Type flag to type identifier lookup
     * @var     array
     */
    protected $_types = array(
        self::ENABLE_TEMPLATES => 'templates',
        self::ENABLE_PLUGINS   => 'plugins',
        self::ENABLE_PHRASES   => 'phrases',
        self::ENABLE_OPTIONS   => 'options',
        self::ENABLE_STYLE     => 'style',
        self::ENABLE_NAVBAR    => 'navigation',
    );

    /**
     * Delayed loading for projects per type
     * @var     array
     */
    protected $_delays = array(
        'templates' => array(),
        'phrases'   => array()
    );

    /**
     * Maps hooks to the data type that is ready to be loaded at that point
     * @var     array
     */
    protected $_delayHooks = array(
        'style_fetch'     => 'phrases',
        'parse_templates' => 'templates',
        'admin_global'    => 'phrases'
    );

    /**
     * Prevents hooks from having the listen code run multiple times.
     * @var     array
     */
    protected $_listeningOnHooks = array();

    /**
     * vBulletin Registry Object
     * @var     vB_Registry
     */
    protected $_registry;

    /**
     * Code to be evaluated at the init_startup hook
     * This is a special case because this library is loaded then, so we manually
     * have to call eval() again for any further dynamic code here.
     *
     * @var     string
     */
    protected $_initCode;

    /**
     * Legacy mode (for 3.5-3.9999)
     * @var     boolean     FALSE if 4+
     */
    protected $_legacy;

    /**
     * Runtime Style Importer
     * @var        VDE_Runtime_Style
     */
    protected $_runtimeStyle;

    /**
     * Gets things rolling
     * Merges $config['Settings'] into the master config for easy overriding.
     *
     * @param   vB_Registry
     */
    public function __construct(vB_Registry $registry) {
        $this->_registry              = $registry;
        $this->_registry->vdeProducts = array();

        $this->_initCode = '';
        $this->_legacy   = version_compare(FILE_VERSION, '4.0', '<');

        if (!empty($registry->config['Settings'])) {
            $registry->options = array_merge($registry->options, $registry->config['Settings']);
        }

        require_once(DIR . '/includes/vde/runtime_style.php');
        $this->_runtimeStyle = new VDE_Runtime_Style($this->_registry);
    }

    /**
     * Initiate loading a projects data at runtime.
     *
     * @param   VDE_Project
     * @param   integer         self::ENABLE_* flags combination
     */
    public function loadProject(VDE_Project $project, $flags = self::ENABLE_ALL) {
        $this->_registry->products[$project->id]    = 1;
        $this->_registry->vdeProducts[$project->id] = $project;

        devdebug('VDE: Loading project ' . $project->meta['title']);

        foreach ($this->_types as $flag => $type) {
            if ($flags & $flag) {
                if (isset($this->_delays[$type])) {
                    $this->_delay($type, $project);
                } else {
                    $type = ucfirst($type);
                    call_user_func(array($this, "_handle$type"), $project);
                }
            }
        }
    }

    /**
     * Loads all projects in a given directory
     * @param   string          Path containing multiple project directories
     * @throws  Exception
     */
    public function loadProjects($inDirectory) {
        $projects = array();

        if (!is_dir($inDirectory)) {
            devdebug("VDE Halted: $inDirectory does not exist");
        }

        foreach (scandir($inDirectory) as $directory) {
            if (is_dir("$inDirectory/$directory") and preg_match('/^([-_a-z0-9]+)$/i', $directory)) {
                try {
                    $projects[$directory] = new VDE_Project("$inDirectory/$directory");
                } catch (VDE_Project_Exception $e) {
                    devdebug("VDE_Project Could not load $directory - " . $e->getMessage());
                } catch (Exception $e) {
                    throw $e;
                }
            }
        }

        uasort($projects, function ($a, $b) {
            if ($a->meta['order'] == $b->meta['order']) {
                return 0;
            }
            return $a->meta['order'] < $b->meta['order'] ? -1 : 1;
        });

        foreach ($projects as $projectDir => $project) {
            if ($project->active) {
                $this->loadProject($project, isset($flags[$projectDir]) ? $flags[$projectDir] : self::ENABLE_ALL);
            }
        }
    }

    /**
     * Retrieves the code from init_startup hook to be explicitly evaluated
     * @return  string
     */
    public function getInitCode() {
        return $this->_initCode;
    }

    /**
     * Delays importing of project data until the appropriate hook has been called.
     * @param    string
     * @param    VDE_Project
     */
    protected function _delay($type, VDE_Project $project) {
        $this->_delays[$type][] = $project;

        $hook = array_search($type, $this->_delayHooks);


        if ($this->_legacy) {
            $hookObj = vBulletinHook::init();
        }

        foreach (array_keys($this->_delayHooks, $type, true) as $hook) {
            if (in_array($hook, $this->_listeningOnHooks)) {
                continue;
            }

            $this->_listeningOnHooks[] = $hook;
            if ($this->_legacy) {
                $hookObj->pluginlist[$hook] = '$vdeRuntime->listenAtHook("' . $hook . '");' .
                    "\n" . $hookObj->pluginlist[$hook];
            } else {
                vBulletinHook::$pluginlist[$hook] = 'global $vdeRuntime;' .
                    "\n" . '$vdeRuntime->listenAtHook("' . $hook . '");' .
                    "\n" . $hookObj->pluginlist[$hook];
            }
        }
    }

    /**
     * Called at certain hooks to trigger the  loading of delayed project data.
     * @param   string      Hook name
     */
    public function listenAtHook($hook) {
        if ($type = $this->_delayHooks[$hook] and !empty($this->_delays[$type])) {
            foreach ($this->_delays[$type] as $project) {
                $type = ucfirst($type);
                call_user_func(array($this, "_handle$type"), $project);
            }
        }
    }

    /**
     * Activates a style when a project requires one.
     * @param    VDE_Project
     */
    protected function _handleStyle(VDE_Project $project) {
        if (!$project->style_activate) {
            return;
        }

        $code = '$style = $vbulletin->db->query_first("
            SELECT *
              FROM " . TABLE_PREFIX . "style
             WHERE styleid = ' . $project->style_activate . '
        ");';

        if ($this->_legacy) {
            $hookObj = vBulletinHook::init();
            $hookObj->pluginlist['style_fetch'] .= "\n$code\n";
        } else {
            vBulletinHook::$pluginlist[$hook] .= "\n$code\n";
        }
    }

    /**
     * Imports Navbar Menu Items
     * @param    VDE_Project
     */
    protected function _handleNavigation(VDE_Project $project) {
        if ($this->_legacy) {
            $hookObj = vBulletinHook::init();
        }

        $resultSet = $this->_registry->db->query_read("SELECT max(navid) highestVal FROM " . TABLE_PREFIX . "navigation");
        $row       = $this->_registry->db->fetch_row($resultSet);
        $navId     = $row[0];

        $navBarData = $project->getNavigation();
        foreach ($navBarData as $tab) {
            $tab['navid']     = ++$navId;
            $tab['productid'] = $project->id;

            foreach ($tab['links'] as $link => $junk) {
                $tab['links'][$link]['navid']     = ++$navId;
                $tab['links'][$link]['productid'] = $project->id;
            }

            $code = '$result["vbtab_' . $tab['name'] . '"] = ' . var_export($tab, true) . ";";

            if ($this->_legacy) {
                $hookObj->pluginlist['build_navigation_array'] .= "\n  $code \n";
            } else {
                vBulletinHook::$pluginlist['build_navigation_array'] .= "\n  $code  \n";
            }
        }
    }

    /**
     * Handles importing of templates into memory for a given project.
     * Also imports templates into the style manager / memory for customizations.
     * @param   VDE_Project
     */
    protected function _handleTemplates(VDE_Project $project) {
        global $only;
        require_once(DIR . '/includes/adminfunctions_template.php');

        foreach ($project->getTemplates() as $template => $content) {
            $this->_registry->templatecache[$template] = compile_template($content);
        }

        if (is_dir($project->getPath() . '/templates/customized')) {
            $this->_runtimeStyle->loadTemplates($project);
        }
    }

    /**
     * Handles importing of plugins into memory for a given project.
     * @param   VDE_Project
     */
    protected function _handlePlugins(VDE_Project $project) {
        if ($this->_legacy) {
            $hookObj = vBulletinHook::init();
        }

        foreach ($project->getPlugins() as $hook => $code) {
            if ($hook == 'init_startup') {
                $this->_initCode .= "\n$code\n";
            } else {
                if ($this->_legacy) {
                    $hookObj->pluginlist[$hook] .= "\n" . $code . "\n";
                } else {
                    vBulletinHook::$pluginlist[$hook] .= "\n" . $code . "\n";
                }
            }
        }
    }

    /**
     * Handles importing of phrases into memory for a given project.
     * @param   VDE_Project
     */
    protected function _handlePhrases(VDE_Project $project) {
        global $vbphrase;

        foreach ($project->getPhrases() as $group => $phrases) {
            foreach ($phrases as $varname => $text) {
                $vbphrase[$varname] = $text;
            }
        }
    }

    /**
     * Handles importing of options into memory for a given project.
     * @var     VDE_Project
     */
    protected function _handleOptions(VDE_Project $project) {
        foreach ($project->getOptions() as $varname => $value) {
            $this->_registry->options[$varname] = $value;
        }
    }
}