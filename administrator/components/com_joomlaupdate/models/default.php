<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_joomlaupdate
 *
 * @copyright   Copyright (C) 2005 - 2012 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Joomla! update overview Model
 *
 * @package     Joomla.Administrator
 * @subpackage  com_joomlaupdate
 * @author      nikosdion <nicholas@dionysopoulos.me>
 * @since       2.5.4
 */
class JoomlaupdateModelDefault extends JModelLegacy
{
	/**
	 * Detects if the Joomla! update site currently in use matches the one
	 * configured in this component. If they don't match, it changes it.
	 *
	 * @return	void
	 *
	 * @since	2.5.4
	 */
	public function applyUpdateSite()
	{
		// Determine the intended update URL
		$params = JComponentHelper::getParams('com_joomlaupdate');
		switch ($params->get('updatesource', 'nochange'))
		{
			// "Long Term Support (LTS) branch - Recommended"
			case 'lts':
				$updateURL = 'http://update.joomla.org/core/list.xml';
				break;

			// "Short term support (STS) branch"
			case 'sts':
				$updateURL = 'http://update.joomla.org/core/sts/list_sts.xml';
				break;

			// "Testing"
			case 'testing':
				$updateURL = 'http://update.joomla.org/core/test/list_test.xml';
				break;

			// "Custom"
			case 'custom':
				$updateURL = $params->get('customurl', '');
				break;

			// "Do not change"
			case 'nochange':
			default:
				return;
				break;
		}

		$db = $this->getDbo();
		$query = $db->getQuery(true)
			->select($db->qn('us') . '.*')
			->from(
				$db->qn('#__update_sites_extensions') . ' AS ' . $db->qn('map')
			)
			->innerJoin(
				$db->qn('#__update_sites') . ' AS ' . $db->qn('us') . ' ON (' .
				$db->qn('us') . '.' . $db->qn('update_site_id') . ' = ' .
					$db->qn('map') . '.' . $db->qn('update_site_id') . ')'
			)
			->where(
				$db->qn('map') . '.' . $db->qn('extension_id') . ' = ' . $db->q(700)
			);
		$db->setQuery($query);
		$update_site = $db->loadObject();

		if ($update_site->location != $updateURL)
		{
			// Modify the database record
			$update_site->last_check_timestamp = 0;
			$update_site->location = $updateURL;
			$db->updateObject('#__update_sites', $update_site, 'update_site_id');

			// Remove cached updates
			$query = $db->getQuery(true)
				->delete($db->qn('#__updates'))
				->where($db->qn('extension_id').' = '.$db->q('700'));
			$db->setQuery($query);
			$db->execute();
		}
	}

	/**
	 * Makes sure that the Joomla! update cache is up-to-date
	 *
	 * @param   bool  $force  Force reload, ignoring the cache timeout
	 *
	 * @return	void
	 *
	 * @since	2.5.4
	 */
	public function refreshUpdates($force = false)
	{
		if ($force)
		{
			$cache_timeout = 0;
		}
		else
		{
			$update_params = JComponentHelper::getParams('com_installer');
			$cache_timeout = $update_params->get('cachetimeout', 6, 'int');
			$cache_timeout = 3600 * $cache_timeout;
		}
		$updater = JUpdater::getInstance();
		$results = $updater->findUpdates(700, $cache_timeout);
	}

	/**
	 * Returns an array with the Joomla! update information
	 *
	 * @return array
	 *
	 * @since 2.5.4
	 */
	public function getUpdateInformation()
	{
		// Initialise the return array
		$ret = array(
			'installed'		=> JVERSION,
			'latest'		=> null,
			'object'		=> null
		);

		// Fetch the update information from the database
		$db = $this->getDbo();
		$query = $db->getQuery(true)
			->select('*')
			->from($db->qn('#__updates'))
			->where($db->qn('extension_id') . ' = ' . $db->q(700));
		$db->setQuery($query);
		$updateObject = $db->loadObject();

		if (is_null($updateObject))
		{
			$ret['latest'] = JVERSION;
			return $ret;
		}
		else
		{
			$ret['latest'] = $updateObject->version;
		}

		// Fetch the full udpate details from the update details URL
		jimport('joomla.updater.update');
		$update = new JUpdate;
		$update->loadFromXML($updateObject->detailsurl);

		// Pass the update object
		if($ret['latest'] == JVERSION) {
			$ret['object'] = null;
		} else {
			$ret['object'] = $update;
		}

		return $ret;
	}

	/**
	 * Returns an array with the configured FTP options
	 *
	 * @return array
	 *
	 * @since  2.5.4
	 */
	public function getFTPOptions()
	{
		$config = JFactory::getConfig();
		return array(
			'host'		=> $config->get('ftp_host'),
			'port'		=> $config->get('ftp_port'),
			'username'	=> $config->get('ftp_user'),
			'password'	=> $config->get('ftp_pass'),
			'directory'	=> $config->get('ftp_root'),
			'enabled'	=> $config->get('ftp_enable'),
		);
	}

	/**
	 * Downloads the update package to the site
	 *
	 * @return bool|string False on failure, basename of the file in any other case
	 *
	 * @since 2.5.4
	 */
	public function download()
	{
		$updateInfo = $this->getUpdateInformation();
		$packageURL = $updateInfo['object']->downloadurl->_data;
		$basename = basename($packageURL);

		// Find the path to the temp directory and the local package
		$jreg = JFactory::getConfig();
		$tempdir = $jreg->getValue('config.tmp_path');
		$target = $tempdir . '/' . $basename;

		// Do we have a cached file?
		jimport('joomla.filesystem.file');
		$exists = JFile::exists($target);

		if (!$exists)
		{

			// Not there, let's fetch it
			return $this->downloadPackage($packageURL, $target);
		}
		else
		{
			// Is it a 0-byte file? If so, re-download please.
			$filesize = @filesize($target);
			if(empty($filesize)) return $this->downloadPackage($packageURL, $target);

			// Yes, it's there, skip downloading
			return $basename;
		}
	}

	/**
	 * Downloads a package file to a specific directory
	 *
	 * @param   string  $url     The URL to download from
	 * @param   string  $target  The directory to store the file
	 *
	 * @return boolean True on success
	 *
	 * @since  2.5.4
	 */
	protected function downloadPackage($url, $target)
	{
		JLoader::import('helpers.download', JPATH_COMPONENT_ADMINISTRATOR);
		$result = AdmintoolsHelperDownload::download($url, $target);
		if(!$result)
		{
			return false;
		}
		else
		{
			return basename($target);
		}
	}

	/**
	 * @since  2.5.4
	 */
	public function createRestorationFile($basename = null)
	{
		// Get a password
		$password = JUserHelper::genRandomPassword(32);
		JFactory::getApplication()->setUserState('com_joomlaupdate.password', $password);

		// Do we have to use FTP?
		$method = JRequest::getCmd('method', 'direct');

		// Get the absolute path to site's root
		$siteroot = JPATH_SITE;

		// If the package name is not specified, get it from the update info
		if (empty($basename))
		{
			$updateInfo = $this->getUpdateInformation();
			$packageURL = $updateInfo['object']->downloadurl->_data;
			$basename = basename($packageURL);
		}

		// Get the package name
		$tempdir = JFactory::getConfig()->getValue('config.tmp_path');
		$file  = $tempdir . '/' . $basename;

		$filesize = @filesize($file);
		JFactory::getApplication()->setUserState('com_joomlaupdate.password', $password);
		JFactory::getApplication()->setUserState('com_joomlaupdate.filesize', $filesize);

		$data = "<?php\ndefined('_AKEEBA_RESTORATION') or die('Restricted access');\n";
		$data .= '$restoration_setup = array('."\n";
		$data .= <<<ENDDATA
	'kickstart.security.password' => '$password',
	'kickstart.tuning.max_exec_time' => '5',
	'kickstart.tuning.run_time_bias' => '75',
	'kickstart.tuning.min_exec_time' => '0',
	'kickstart.procengine' => '$method',
	'kickstart.setup.sourcefile' => '$file',
	'kickstart.setup.destdir' => '$siteroot',
	'kickstart.setup.restoreperms' => '0',
	'kickstart.setup.filetype' => 'zip',
	'kickstart.setup.dryrun' => '0'
ENDDATA;

		if ($method == 'ftp')
		{
			// Fetch the FTP parameters from the request. Note: The password should be
			// allowed as raw mode, otherwise something like !@<sdf34>43H% would be
			// sanitised to !@43H% which is just plain wrong.
			$ftp_host = JRequest::getVar('ftp_host','');
			$ftp_port = JRequest::getVar('ftp_port', '21');
			$ftp_user = JRequest::getVar('ftp_user', '');
			$ftp_pass = JRequest::getVar('ftp_pass', '', 'default', 'none', 2);
			$ftp_root = JRequest::getVar('ftp_root', '');

			// Is the tempdir really writable?
			$writable = @is_writeable($tempdir);
			if($writable) {
				// Let's be REALLY sure
				$fp = @fopen($tempdir.'/test.txt','w');
				if($fp === false) {
					$writable = false;
				} else {
					fclose($fp);
					unlink($tempdir.'/test.txt');
				}
			}

			// If the tempdir is not writable, create a new writable subdirectory
			if(!$writable) {
				jimport('joomla.filesystem.folder');

				$FTPOptions = JClientHelper::getCredentials('ftp');
				$ftp = JClientFtp::getInstance($FTPOptions['host'], $FTPOptions['port'], null, $FTPOptions['user'], $FTPOptions['pass']);
				$dest = JPath::clean(str_replace(JPATH_ROOT, $FTPOptions['root'], $tempdir.'/admintools'), '/');
				if(!@mkdir($tempdir.'/admintools')) $ftp->mkdir($dest);
				if(!@chmod($tempdir.'/admintools', 511)) $ftp->chmod($dest, 511);

				$tempdir .= '/admintools';
			}

			// Just in case the temp-directory was off-root, try using the default tmp directory
			$writable = @is_writeable($tempdir);
			if(!$writable) {
				$tempdir = JPATH_ROOT.'/tmp';

				// Does the JPATH_ROOT/tmp directory exist?
				if(!is_dir($tempdir)) {
					jimport('joomla.filesystem.folder');
					jimport('joomla.filesystem.file');
					JFolder::create($tempdir, 511);
					JFile::write($tempdir.'/.htaccess',"order deny, allow\ndeny from all\nallow from none\n");
				}

				// If it exists and it is unwritable, try creating a writable admintools subdirectory
				if(!is_writable($tempdir)) {
					jimport('joomla.filesystem.folder');

					$FTPOptions = JClientHelper::getCredentials('ftp');
					$ftp = JClientFtp::getInstance($FTPOptions['host'], $FTPOptions['port'], null, $FTPOptions['user'], $FTPOptions['pass']);
					$dest = JPath::clean(str_replace(JPATH_ROOT, $FTPOptions['root'], $tempdir.'/admintools'), '/');
					if(!@mkdir($tempdir.'/admintools')) $ftp->mkdir($dest);
					if(!@chmod($tempdir.'/admintools', 511)) $ftp->chmod($dest, 511);

					$tempdir .= '/admintools';
				}
			}

			// If we still have no writable directory, we'll try /tmp and the system's temp-directory
			$writable = @is_writeable($tempdir);
			if(!$writable) {
				if(@is_dir('/tmp') && @is_writable('/tmp')) {
					$tempdir = '/tmp';
				} else {
					// Try to find the system temp path
					$tmpfile = @tempnam("dummy","");
					$systemp = @dirname($tmpfile);
					@unlink($tmpfile);
					if(!empty($systemp)) {
						if(@is_dir($systemp) && @is_writable($systemp)) {
							$tempdir = $systemp;
						}
					}
				}
			}

			$data.=<<<ENDDATA
	,
	'kickstart.ftp.ssl' => '0',
	'kickstart.ftp.passive' => '1',
	'kickstart.ftp.host' => '$ftp_host',
	'kickstart.ftp.port' => '$ftp_port',
	'kickstart.ftp.user' => '$ftp_user',
	'kickstart.ftp.pass' => '$ftp_pass',
	'kickstart.ftp.dir' => '$ftp_root',
	'kickstart.ftp.tempdir' => '$tempdir'
ENDDATA;
		}

		$data .= ');';

		// Remove the old file, if it's there...
		jimport('joomla.filesystem.file');
		$configpath = JPATH_COMPONENT_ADMINISTRATOR . '/restoration.php';
		if( JFile::exists($configpath) )
		{
			JFile::delete($configpath);
		}

		// Write new file. First try with JFile.
		$result = JFile::write( $configpath, $data );
		// In case JFile used FTP but direct access could help
		if(!$result) {
			if(function_exists('file_put_contents')) {
				$result = @file_put_contents($configpath, $data);
				if($result !== false) $result = true;
			} else {
				$fp = @fopen($configpath, 'wt');
				if($fp !== false) {
					$result = @fwrite($fp, $data);
					if($result !== false) $result = true;
					@fclose($fp);
				}
			}
		}
		return $result;
	}

	/**
	 * Runs the schema update SQL files, the PHP update script and updates the
	 * manifest cache and #__extensions entry. Essentially, it is identical to
	 * JInstallerFile::install() without the file copy.
	 *
	 * @return boolean True on success
	 *
	 * @since  2.5.4
	 */
	public function finaliseUpgrade()
	{
		jimport('joomla.installer.install');
		$installer = JInstaller::getInstance();

		$installer->setPath('source', JPATH_ROOT);
		$installer->setPath('extension_root', JPATH_ROOT);

		if (!$installer->setupInstall())
		{
			$installer->abort(JText::_('JLIB_INSTALLER_ABORT_DETECTMANIFEST'));

			return false;
		}

		$installer->extension = JTable::getInstance('extension');
		$installer->extension->load(700);
		$installer->setAdapter($installer->extension->type);

		$manifest = $installer->getManifest();

		$manifestPath = JPath::clean($installer->getPath('manifest'));
		$element = preg_replace('/\.xml/', '', basename($manifestPath));

		// Run the script file
		$scriptElement = $manifest->scriptfile;
		$manifestScript = (string) $manifest->scriptfile;

		if ($manifestScript)
		{
			$manifestScriptFile = JPATH_ROOT . '/' . $manifestScript;

			if (is_file($manifestScriptFile))
			{
				// load the file
				include_once $manifestScriptFile;
			}

			$classname = 'JoomlaInstallerScript';

			if (class_exists($classname))
			{
				$manifestClass = new $classname($this);
			}
		}

		ob_start();
		ob_implicit_flush(false);
		if ($manifestClass && method_exists($manifestClass, 'preflight'))
		{
			if ($manifestClass->preflight('update', $this) === false)
			{
				$installer->abort(JText::_('JLIB_INSTALLER_ABORT_FILE_INSTALL_CUSTOM_INSTALL_FAILURE'));

				return false;
			}
		}

		$msg = ob_get_contents(); // create msg object; first use here
		ob_end_clean();

		// Get a database connector object
		$db = JFactory::getDbo();

		// Check to see if a file extension by the same name is already installed
		// If it is, then update the table because if the files aren't there
		// we can assume that it was (badly) uninstalled
		// If it isn't, add an entry to extensions
		$query = $db->getQuery(true);
		$query->select($query->qn('extension_id'))
			->from($query->qn('#__extensions'));
		$query->where($query->qn('type') . ' = ' . $query->q('file'))
			->where($query->qn('element') . ' = ' . $query->q('joomla'));
		$db->setQuery($query);
		try
		{
			$db->execute();
		}
		catch (RuntimeException $e)
		{
			// Install failed, roll back changes
			$installer->abort(
				JText::sprintf('JLIB_INSTALLER_ABORT_FILE_ROLLBACK', JText::_('JLIB_INSTALLER_UPDATE'), $db->stderr(true))
			);
			return false;
		}
		$id = $db->loadResult();
		$row = JTable::getInstance('extension');

		if ($id)
		{
			// Load the entry and update the manifest_cache
			$row->load($id);
			// Update name
			$row->set('name', 'files_joomla');
			// Update manifest
			$row->manifest_cache = $installer->generateManifestCache();
			if (!$row->store())
			{
				// Install failed, roll back changes
				$installer->abort(
					JText::sprintf('JLIB_INSTALLER_ABORT_FILE_ROLLBACK', JText::_('JLIB_INSTALLER_UPDATE'), $db->stderr(true))
				);
				return false;
			}
		}
		else
		{
			// Add an entry to the extension table with a whole heap of defaults
			$row->set('name', 'files_joomla');
			$row->set('type', 'file');
			$row->set('element', 'joomla');
			// There is no folder for files so leave it blank
			$row->set('folder', '');
			$row->set('enabled', 1);
			$row->set('protected', 0);
			$row->set('access', 0);
			$row->set('client_id', 0);
			$row->set('params', '');
			$row->set('system_data', '');
			$row->set('manifest_cache', $installer->generateManifestCache());

			if (!$row->store())
			{
				// Install failed, roll back changes
				$installer->abort(JText::sprintf('JLIB_INSTALLER_ABORT_FILE_INSTALL_ROLLBACK', $db->stderr(true)));
				return false;
			}

			// Set the insert id
			$row->set('extension_id', $db->insertid());

			// Since we have created a module item, we add it to the installation step stack
			// so that if we have to rollback the changes we can undo it.
			$installer->pushStep(array('type' => 'extension', 'extension_id' => $row->extension_id));
		}

		/*
		 * Let's run the queries for the file
		 */
		if ($manifest->update)
		{
			$result = $installer->parseSchemaUpdates($manifest->update->schemas, $row->extension_id);
			if ($result === false)
			{
				// Install failed, rollback changes
				$installer->abort(JText::sprintf('JLIB_INSTALLER_ABORT_FILE_UPDATE_SQL_ERROR', $db->stderr(true)));
				return false;
			}
		}

		// Start Joomla! 1.6
		ob_start();
		ob_implicit_flush(false);

		if ($manifestClass && method_exists($manifestClass, 'update'))
		{
			if ($manifestClass->update($installer) === false)
			{
				// Install failed, rollback changes
				$installer->abort(JText::_('JLIB_INSTALLER_ABORT_FILE_INSTALL_CUSTOM_INSTALL_FAILURE'));

				return false;
			}
		}

		$msg .= ob_get_contents(); // append messages
		ob_end_clean();

		// Lastly, we will copy the manifest file to its appropriate place.
		$manifest = array();
		$manifest['src'] = $installer->getPath('manifest');
		$manifest['dest'] = JPATH_MANIFESTS . '/files/' . basename($installer->getPath('manifest'));
		if (!$installer->copyFiles(array($manifest), true))
		{
			// Install failed, rollback changes
			$installer->abort(JText::_('JLIB_INSTALLER_ABORT_FILE_INSTALL_COPY_SETUP'));
			return false;
		}

		// Clobber any possible pending updates
		$update = JTable::getInstance('update');
		$uid = $update->find(
			array('element' => $element, 'type' => 'file', 'client_id' => '', 'folder' => '')
		);

		if ($uid)
		{
			$update->delete($uid);
		}

		// And now we run the postflight
		ob_start();
		ob_implicit_flush(false);

		if ($manifestClass && method_exists($manifestClass, 'postflight'))
		{
			$manifestClass->postflight('update', $this);
		}

		$msg .= ob_get_contents(); // append messages
		ob_end_clean();

		if ($msg != '')
		{
			$installer->set('extension_message', $msg);
		}

		return true;
	}

	/**
	 * Removes the extracted package file
	 *
	 * @return void
	 *
	 * @since  2.5.4
	 */
	public function cleanUp()
	{
		jimport('joomla.filesystem.file');

		// Remove the update package
		$jreg = JFactory::getConfig();
		$tempdir = $jreg->getValue('config.tmp_path');
		$file = JFactory::getApplication()->getUserState('com_joomlaupdate.file', null);
		$target = $tempdir.'/'.$file;
		if (!@unlink($target))
		{
			jimport('joomla.filesystem.file');
			JFile::delete($target);
		}

		// Remove the restoration.php file
		$target = JPATH_COMPONENT_ADMINISTRATOR . '/restoration.php';
		if (!@unlink($target))
		{
			JFile::delete($target);
		}

		// Remove joomla.xml from the site's root
		$target = JPATH_ROOT . '/joomla.xml';
		if (!@unlink($target))
		{
			JFile::delete($target);
		}

		// Unset the update filename from the session
		JFactory::getApplication()->setUserState('com_joomlaupdate.file', null);
	}
}
