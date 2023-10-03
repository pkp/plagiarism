<?php

namespace APP\plugins\generic\plagiarism;

/**
 * @file PlagiarismPlugin.php
 *
 * Copyright (c) 2003-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism plugin
 */

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use Exception;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\security\Role;
use Illuminate\Support\Facades\Event;

class PlagiarismPlugin extends GenericPlugin
{
	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null)
	{
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();

		if ($success && $this->getEnabled()) {
			Event::subscribe(new PlagiarismSubmissionSubmitListener($this));
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName()
	{
		return __('plugins.generic.plagiarism.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription()
	{
		return __('plugins.generic.plagiarism.description');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	function getCanEnable($contextId = null)
	{
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	function getCanDisable($contextId = null)
	{
		return !Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getEnabled()
	 */
	function getEnabled($contextId = null)
	{
		return parent::getEnabled($contextId) || Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * Fetch credentials from config.inc.php, if available
	 * @return array username and password, or null(s)
	 **/
	function getForcedCredentials()
	{
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$contextPath = $context->getPath();
		$username = Config::getVar(
			'ithenticate',
			'username[' . $contextPath . ']',
			Config::getVar('ithenticate', 'username')
		);
		$password = Config::getVar(
			'ithenticate',
			'password[' . $contextPath . ']',
			Config::getVar('ithenticate', 'password')
		);
		return [$username, $password];
	}

	/**
	 * Send the editor an error message
	 * @param $submissionid int
	 * @param $message string
	 * @return void
	 **/
	public function sendErrorMessage($submissionid, $message)
	{
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		import('classes.notification.NotificationManager');
		$notificationManager = new NotificationManager();

		/** @var RoleDAO $roleDao */
		$roleDao = DAORegistry::getDAO('RoleDAO');
		// Get the managers.
		$managers = $roleDao->getUsersByRoleId(Role::ROLE_ID_MANAGER, $context->getId());
		while ($manager = $managers->next()) {
			$notificationManager->createTrivialNotification($manager->getId(), Notification::NOTIFICATION_TYPE_ERROR, ['contents' => __('plugins.generic.plagiarism.errorMessage', ['submissionId' => $submissionid, 'errorMessage' => $message])]);
		}
		error_log('iThenticate submission ' . $submissionid . ' failed: ' . $message);
	}

	/**
	 * Send submission files to iThenticate.
	 * @param $context Context
	 * @param $submission Submission
	 */
	public function sendSubmissionFiles($context, $submission)
	{
		$publication = $submission->getCurrentPublication();

		require_once(dirname(__FILE__) . '/vendor/autoload.php');

		// try to get credentials for current context otherwise use default config
		$contextId = $context->getId();
		list($username, $password) = $this->getForcedCredentials();
		if (empty($username) || empty($password)) {
			$username = $this->getSetting($contextId, 'ithenticateUser');
			$password = $this->getSetting($contextId, 'ithenticatePass');
		}

		$ithenticate = null;
		try {
			$ithenticate = new \bsobbe\ithenticate\Ithenticate($username, $password);
		} catch (Exception $e) {
			$this->sendErrorMessage($submission->getId(), $e->getMessage());
			return;
		}
		// Make sure there's a group list for this context, creating if necessary.
		$groupList = $ithenticate->fetchGroupList();
		$contextName = $context->getLocalizedName($context->getPrimaryLocale());
		if (!($groupId = array_search($contextName, $groupList))) {
			// No folder group found for the context; create one.
			$groupId = $ithenticate->createGroup($contextName);
			if (!$groupId) {
				$this->sendErrorMessage($submission->getId(), 'Could not create folder group for context ' . $contextName . ' on iThenticate.');
				return;
			}
		}

		// Create a folder for this submission.
		if (!($folderId = $ithenticate->createFolder(
			'Submission_' . $submission->getId(),
			'Submission_' . $submission->getId() . ': ' . $publication->getLocalizedTitle($publication->getData('locale')),
			$groupId,
			true,
			true
		))) {
			$this->sendErrorMessage($submission->getId(), 'Could not create folder for submission ID ' . $submission->getId() . ' on iThenticate.');
			return;
		}

		$submissionFiles = Repo::submissionFile()
			->getCollector()
			->filterBySubmissionIds([$submission->getId()])
			->getMany();

		$authors = $publication->getData('authors')->toArray();
		$author = array_shift($authors);
		foreach ($submissionFiles as $submissionFile) {
			$file = Services::get('file')->get($submissionFile->getData('fileId'));
			if (!$ithenticate->submitDocument(
				$submissionFile->getLocalizedData('name'),
				$author->getLocalizedGivenName(),
				$author->getLocalizedFamilyName(),
				$submissionFile->getLocalizedData('name'),
				Services::get('file')->fs->read($file->path),
				$folderId
			)) {
				$this->sendErrorMessage($submission->getId(), 'Could not submit "' . $submissionFile->getData('path') . '" to iThenticate.');
			}
		}
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb)
	{
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			$this->getEnabled() ? [
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			] : [],
			parent::getActions($request, $verb)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request)
	{
		switch ($request->getUserVar('verb')) {
			case 'settings':
				$context = $request->getContext();

				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->registerPlugin('function', 'plugin_url', [$this, 'smartyPluginUrl']);

				$form = new PlagiarismSettingsForm($this, $context->getId());

				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}
}

/**
 * Low-budget mock class for \bsobbe\ithenticate\Ithenticate -- Replace the
 * constructor above with this class name to log API usage instead of
 * interacting with the iThenticate service.
 */
class TestIthenticate
{
	public function __construct($username, $password)
	{
		error_log("Constructing iThenticate: $username $password");
	}

	public function fetchGroupList()
	{
		error_log('Fetching iThenticate group list');
		return array();
	}

	public function createGroup($group_name)
	{
		error_log("Creating group named \"$group_name\"");
		return 1;
	}

	public function createFolder($folder_name, $folder_description, $group_id, $exclude_quotes)
	{
		error_log("Creating folder:\n\t$folder_name\n\t$folder_description\n\t$group_id\n\t$exclude_quotes");
		return true;
	}

	public function submitDocument($essay_title, $author_firstname, $author_lastname, $filename, $document_content, $folder_number)
	{
		error_log("Submitting document:\n\t$essay_title\n\t$author_firstname\n\t$author_lastname\n\t$filename\n\t" . strlen($document_content) . " bytes of content\n\t$folder_number");
		return true;
	}
}
