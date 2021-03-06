<?php
/**
 * @version 1.4.0
 * @package RSform!Pro 1.4.0
 * @copyright (C) 2007-2012 www.rsjoomla.com
 * @license GPL, http://www.gnu.org/copyleft/gpl.html
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

class plgSystemRSFPIrandargahGatewayInstallerScript
{
    public function preflight($type, $parent)
    {
        if ($type != 'uninstall') {
            $app = JFactory::getApplication();

            if (!file_exists(JPATH_ADMINISTRATOR . '/components/com_rsform/helpers/rsform.php')) {
                $app->enqueueMessage('Please install the RSForm! Pro component before continuing.', 'error');
                return false;
            }

            if (!file_exists(JPATH_ADMINISTRATOR . '/components/com_rsform/helpers/assets.php')) {
                $app->enqueueMessage('Please upgrade RSForm! Pro to at least version 1.51.0 before continuing!', 'error');
                return false;
            }

            if (!file_exists(JPATH_PLUGINS . '/system/rsfppayment/rsfppayment.php')) {
                $app->enqueueMessage('Please install the RSForm! Pro Payment Plugin first!', 'error');
                return false;
            }

            $jversion = new JVersion();
            if (!$jversion->isCompatible('2.5.28')) {
                $app->enqueueMessage('Please upgrade to at least Joomla! 2.5.28 before continuing!', 'error');
                return false;
            }
        }

        return true;
    }

    public function postflight($type, $parent)
    {
        if ($type == 'update') {
            $this->runSQL($parent->getParent()->getPath('source'), 'install.sql');
        }
    }

    protected function runSQL($source, $file)
    {
        $db = JFactory::getDbo();
        $driver = 'mysql';

        $sqlfile = $source . '/sql/' . $driver . '/' . $file;

        if (file_exists($sqlfile)) {
            $buffer = file_get_contents($sqlfile);
            if ($buffer !== false) {
                $queries = JInstallerHelper::splitSql($buffer);
                foreach ($queries as $query) {
                    $query = trim($query);
                    if ($query != '' && $query{0} != '#') {
                        $db->setQuery($query);
                        if (!$db->execute()) {
                            JError::raiseWarning(1, JText::sprintf('JLIB_INSTALLER_ERROR_SQL_ERROR', $db->stderr(true)));
                        }
                    }
                }
            }
        }
    }
}
