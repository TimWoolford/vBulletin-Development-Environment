<?php
/**
 * Ports existing products to be VDE-compatible and livign in the filesystem.
 *
 * @package     VDE
 * @author      AdrianSchneider / ForumOps
 */
class VDE_Porter {
    /**
     * Database object.
     *
     * @var    vB_Database
     */
    public $_db;

    /**
     * Brings vBulletin into scope
     * @param   vB_Registry
     */
    public function __construct(vB_Registry $registry) {
        $this->_db = $registry->db;
    }

    /**
     * Ports a vBulletin product from the database into something that is
     * VDE compatible.
     *
     * @param   string $productId
     * @param   $out
     * @throws  Exception
     */
    public function port($productId, $out) {
        if (!is_dir($out)) {
            mkdir($out, 0777, true);
        }

        if (!$product = $this->_getProduct($productId)) {
            throw new Exception("$productId not found in database");
        }

        $data = $this->_fetchAllProductInformation($product);

        // Create config.php
        $this->_createArrayFile("$out/config.php", $data['product']);

        // Create /updown
        if ($data['updown']) {
            if (!is_dir("$out/updown")) {
                mkdir("$out/updown");
            }

            foreach ($data['updown'] as $version => $codes) {
                foreach ($codes as $type => $code) {
                    file_put_contents(
                        "$out/updown/$type-" . str_replace('*', 'all', $version) . ".php",
                        "<?php\n\n" . $code
                    );
                }
            }
        }

        // Create /plugins
        if ($data['plugins']) {
            if (!is_dir("$out/plugins")) {
                mkdir("$out/plugins");
            }

            foreach ($data['plugins'] as $hook => $plugins) {
                file_put_contents(
                    "$out/plugins/$hook.php",
                    "<?php\n\n" . implode("\n\n", $plugins)
                );
            }
        }

        // Create /templates
        if ($data['templates']) {
            if (!is_dir("$out/templates")) {
                mkdir("$out/templates");
            }

            foreach ($data['templates'] as $title => $info) {
                if ($info['templatetype'] == 'template') {
                    $filename = "$title.html";
                } elseif ($info['templatetype'] == 'css') {
                    $filename = "$title";
                } else {
                    continue;
                }

                file_put_contents(
                    "$out/templates/$filename",
                    $info['content']
                );
            }
        }

        // Create /phrases
        if ($data['phrases']) {
            if (!is_dir("$out/phrases")) {
                mkdir("$out/phrases");
            }

            foreach ($data['phrases'] as $group => $groupInfo) {
                if (!is_dir("$out/phrases/$group")) {
                    mkdir("$out/phrases/$group");
                }

                if ($groupInfo['new']) {
                    file_put_contents(
                        "$out/phrases/$group/$group.txt",
                        $groupInfo['title']
                    );
                }

                foreach ($groupInfo['phrases'] as $phrase => $text) {
                    file_put_contents(
                        "$out/phrases/$group/$phrase.txt",
                        $text
                    );
                }
            }
        }

        // Create /options
        if ($data['options']) {
            if (!is_dir("$out/options")) {
                mkdir("$out/options");
            }

            foreach ($data['options'] as $groupVarname => $group) {
                if (!is_dir("$out/options/$groupVarname")) {
                    mkdir("$out/options/$groupVarname");
                }

                if ($group['new']) {
                    $this->_createArrayFile(
                        "$out/options/$groupVarname/$groupVarname.php",
                        array(
                            'title'        => $group['title'],
                            'displayorder' => $group['displayorder']
                        )
                    );
                }

                foreach ($group['options'] as $varname => $option) {
                    $this->_createArrayFile(
                        "$out/options/$groupVarname/$varname.php",
                        $option
                    );
                }
            }
        }

        if ($data['navigation']) {
            if (!is_dir($navigationDir = "$out/navigation")) {
                mkdir($navigationDir);
            }

            foreach ($data['navigation'] as $tabName => $tabData) {
                if (!is_dir("$navigationDir/$tabName")) {
                    mkdir("$navigationDir/$tabName");
                }

                foreach ($tabData['links'] as $name => $link) {
                    $this->_createArrayFile(
                        "$navigationDir/$tabName/" . $tabName . "_" . $name . ".php",
                        $link
                    );
                }

                unset($tabData['links']);
                unset($tabData['parent']);

                $this->_createArrayFile(
                    "$navigationDir/$tabName/$tabName.php",
                    $tabData
                );
            }
        }
    }

    /**
     * Creates a file containing an exported array
     * @param   $filename   string      filename to create at
     * @param   $contents   array       variable contents
     */
    protected function _createArrayFile($filename, $contents) {
        file_put_contents(
            $filename,
            "<?php \n\nreturn " . var_export($contents, true) . ';'
        );
    }

    /**
     * Takes a product from the database, and fetches all the associated information
     * in the VDE format.
     *
     * @param   $product    array       Product row from database
     */
    protected function _fetchAllProductInformation(array $product) {
        $info['product'] = array(
            'id'           => $product['productid'],
            'buildPath'    => '',
            'title'        => $product['title'],
            'description'  => $product['description'],
            'url'          => $product['url'],
            'version'      => $product['version'],
            'dependencies' => $this->_getDependencies($product['productid']),
            'files'        => array()
        );

        $info['updown']     = $this->_getUpDown($product['productid']);
        $info['plugins']    = $this->_getPlugins($product['productid']);
        $info['templates']  = $this->_getTemplates($product['productid']);
        $info['phrases']    = $this->_getPhrases($product['productid']);
        $info['tasks']      = $this->_getTasks($product['productid']);
        $info['options']    = $this->_getOptions($product['productid']);
        $info['navigation'] = $this->_getNavigation($product['productid']);

        return $info;
    }

    /**
     * Fetches product information from the database
     * @param   $id     string      Product ID
     * @return          array       Product informtation, or FALSE on failure
     */
    protected function _getProduct($id) {
        return $this->_db->query_first("
            SELECT *
              FROM " . TABLE_PREFIX . "product
             WHERE productid = " . $this->_db->sql_prepare($id) . "
        ");
    }

    /**
     * Fetches an array of dependencies for a given product, from the db
     * @param   $id     string      Product ID
     * @return          array       Dependencies
     */
    protected function _getDependencies($id) {
        $dependencies = array();

        $result = $this->_db->query_read("
            SELECT *
              FROM " . TABLE_PREFIX . "productdependency
             WHERE productid = " . $this->_db->sql_prepare($id) . "
        ");

        while ($dependency = $this->_db->fetch_array($result)) {
            $dependencies[$dependency['dependencytype']] = array(
                $dependency['minversion'],
                $dependency['maxversion']
            );
        }

        $this->_db->free_result($result);
        return $dependencies;
    }

    /**
     * Fetches an array of plugins for a given product
     * @param   $id     string      Product ID
     * @return          array       Plugins (hookname => plugins[])
     */
    protected function _getPlugins($id) {
        $plugins = array();

        $result = $this->_db->query_read("
            SELECT *
              FROM " . TABLE_PREFIX . "plugin
             WHERE product = " . $this->_db->sql_prepare($id) . "
               AND active = 1
            ORDER
                BY executionorder
        ");

        while ($plugin = $this->_db->fetch_array($result)) {
            $plugins[$plugin['hookname']][] = $plugin['phpcode'];
        }

        $this->_db->free_result($result);
        return $plugins;
    }

    /**
     * Fetches an array of templates for a given product
     * @param   $id     string      Product ID
     * @return          array       Templates (templatename => body)
     */
    protected function _getTemplates($id) {
        $templates = array();

        $result = $this->_db->query_read("
            SELECT *
              FROM " . TABLE_PREFIX . "template
             WHERE product = " . $this->_db->sql_prepare($id) . "
               AND templatetype in ('template', 'css')
        ");

        while ($template = $this->_db->fetch_array($result)) {
            $templates[$template['title']] =
                array(
                    'templatetype' => $template['templatetype'],
                    'content'      => $template['template_un']);
        }

        $this->_db->free_result($result);
        return $templates;
    }

    /**
     * Fetches an array of all updown (install code) for a given product
     * @param   $id     string      Product ID
     * @return          array       Install code ( array('version' => array('up' => 'xx', 'down' => 'yy'))
     */
    protected function _getUpDown($id) {
        $upDown = array();

        $result = $this->_db->query_read("
            SELECT *
              FROM " . TABLE_PREFIX . "productcode
             WHERE productid = " . $this->_db->sql_prepare($id) . "
        ");

        while ($code = $this->_db->fetch_array($result)) {
            $upDown[$code['version']] = array(
                'up'   => $code['installcode'],
                'down' => $code['uninstallcode']
            );
        }

        $this->_db->free_result($result);
        return $upDown;
    }

    /**
     * Gets all options for a given product
     * @param   $id     string      Product iD
     * @return          array       Options
     */
    protected function _getOptions($id) {
        $options = array();

        $result = $this->_db->query_read("
            SELECT setting.*
                 , settinggroup.displayorder   AS group_displayorder
                 , settinggroup.product        AS group_product
              FROM " . TABLE_PREFIX . "setting AS setting
            INNER
              JOIN " . TABLE_PREFIX . "settinggroup AS settinggroup
                ON settinggroup.grouptitle = setting.grouptitle
             WHERE setting.product = " . $this->_db->sql_prepare($id) . "
        ");

        while ($option = $this->_db->fetch_array($result)) {
            if (!$options[$option['grouptitle']]) {
                $options[$option['grouptitle']] = array(
                    'title'        => $this->_getSpecialPhrase('settinggroup_' . $option['grouptitle'], $id),
                    'displayorder' => $option['group_displayorder'],
                    'new'          => $option['group_product'] == $id,
                    'options'      => array()
                );
            }

            $options[$option['grouptitle']]['options'][$option['varname']] = array(
                'title'          => $this->_getSpecialPhrase('setting_' . $option['varname'] . '_title', $id),
                'description'    => $this->_getSpecialPhrase('setting_' . $option['varname'] . '_desc', $id),
                'optioncode'     => $option['optioncode'],
                'datatype'       => $option['datatype'],
                'displayorder'   => $option['displayorder'],
                'defaultvalue'   => $option['defaultvalue'],
                'value'          => $option['value'],
                'volatile'       => $option['volatile'],
                'validationcode' => $option['validationcode']
            );
        }

        return $options;
    }

    /**
     * Fetches an array of tasks for a given product
     * @param   $id     string      Product ID
     * @return          array       Scheduled task information
     */
    protected function _getTasks($id) {
        $tasks = array();

        $result = $this->_db->query_read("
            SELECT *
              FROM " . TABLE_PREFIX . "cron
             WHERE product = " . $this->_db->sql_prepare($id) . "
        ");

        while ($task = $this->_db->fetch_array($result)) {
            $tasks[$task['varname']] = array(
                'title'       => $this->_getSpecialPhrase('task_' . $task['varname'] . '_title', $id),
                'description' => $this->_getSpecialPhrase('task_' . $task['varname'] . '_desc', $id),
                'weekday'     => $task['weekday'],
                'day'         => $task['day'],
                'hour'        => $task['hour'],
                'minute'      => $task['minute'],
                'filename'    => $task['filename'],
                'loglevel'    => $task['loglevel'],
                'active'      => $task['active'],
                'volatile'    => $task['volatile']
            );
        }

        return $tasks;
    }

    /**
     * Fetches a special phrase
     * @param   $varname    string      Phrase varname
     * @param   $productId  string      Product ID
     * @return              string      Phrase text
     */
    protected function _getSpecialPhrase($varname, $productId) {
        $result = $this->_db->query_first("
            SELECT text
              FROM " . TABLE_PREFIX . "phrase
             WHERE varname = " . $this->_db->sql_prepare($varname) . "
               AND languageid = -1
               AND product = " . $this->_db->sql_prepare($productId) . "
        ");

        return $result['text'];
    }

    /**
     * Fetches all non-special phrases for a given product
     * @param   $id     string      Product ID
     * @return          array       Phrases
     */
    protected function _getPhrases($id) {
        $phrases = array();

        $result = $this->_db->query_read("
            SELECT phrase.*
                 , phrasetype.title   AS group_title
                 , phrasetype.product AS group_product
              FROM " . TABLE_PREFIX . "phrase AS phrase
            LEFT OUTER 
              JOIN " . TABLE_PREFIX . "phrasetype AS phrasetype
                ON phrase.fieldname = phrasetype.fieldname
             WHERE phrase.product = " . $this->_db->sql_prepare($id) . "
               AND phrase.varname NOT LIKE 'task_%_title'
               AND phrase.varname NOT LIKE 'task_%_desc'
               AND phrase.varname NOT LIKE 'task_%_log'
               AND phrase.varname NOT LIKE 'setting_%_title'
               AND phrase.varname NOT LIKE 'setting_%_desc'
               AND phrase.varname NOT LIKE 'settinggroup_%'
               AND phrase.varname NOT LIKE 'vb_navigation_%'
        ");

        while ($phrase = $this->_db->fetch_array($result)) {
            if (!$phrases[$phrase['fieldname']]) {
                $phrases[$phrase['fieldname']] = array(
                    'title'   => $phrase['group_title'],
                    'new'     => $phrase['group_product'] == $id,
                    'phrases' => array()
                );
            }

            $phrases[$phrase['fieldname']]['phrases'][$phrase['varname']] = $phrase['text'];
        }

        $this->_db->free_result($phrase);
        return $phrases;
    }

    protected function _getNavigation($id) {
        $navigations = array();
        foreach ($this->_getNavTypeArray($id, 'tab') as $tab) {
            $tab['links']              = array();
            $tab['links']              = array_merge($tab['links'], $this->_getNavTypeArray($id, 'link', $tab['name']));
            $navigations[$tab['name']] = $tab;
        }
        return $navigations;
    }

    /**
     * @param string $id
     * @param string $navType
     * @param string $parent
     * @return array
     */
    private function _getNavTypeArray($id, $navType, $parent = '') {
        $navigations = array();

        $result = $this->_db->query_read(
            "SELECT n.name,
                    n.displayorder,
                    n.url,
                    n.showperm,
                    n.navtype,
                    n.scripts,
                    p.text
               FROM " . TABLE_PREFIX . "navigation n
             INNER JOIN " . TABLE_PREFIX . "phrase p
                 ON p.varname = concat('vb_navigation_', " . $this->_db->sql_prepare($navType) . ", '_', n . name, '_text')
                AND p . product = n . productid
              WHERE productid = " . $this->_db->sql_prepare($id) . "
                AND navtype = " . $this->_db->sql_prepare($navType)
        );

        while ($navigation = $this->_db->fetch_array($result)) {
            $navigation['parent']             = $parent;
            $navigations[$navigation['name']] = $navigation;
        }

        $this->_db->free_result($result);
        return $navigations;
    }
}