<?php

/**
 * @file plugins/generic/plagiarism/PlagiarismPlugin.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2013-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismPlugin
 *
 * @brief  Coar plugin class of plagiarism plugin 
 */

namespace APP\plugins\generic\plagiarism;

use APP\core\Services;
use APP\facades\Repo;
use APP\plugins\generic\plagiarism\PlagiarismSubmissionSubmitListener;
use APP\template\TemplateManager;
use APP\notification\Notification;
use APP\notification\NotificationManager;
use APP\plugins\generic\plagiarism\PlagiarismSettingsForm;
use APP\submission\Submission;
use PKP\context\Context;
use PKP\config\Config;
use PKP\security\Role;
use APP\core\Application;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\plugins\GenericPlugin;
use PKP\linkAction\request\AjaxModal;
use Illuminate\Support\Facades\Event;
use Throwable;

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
     */
    function getForcedCredentials(): array
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
     */
    public function sendErrorMessage(int $submissionId, string $message): void
    {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$notificationManager = new NotificationManager();

		// Get the managers
		$managers = Repo::userGroup()
			->getCollector()
			->filterByContextIds([$context->getId()])
			->filterByRoleIds([Role::ROLE_ID_MANAGER])
			->getMany();

		foreach($managers as $manager) {
			$notificationManager->createTrivialNotification(
				$manager->getId(), 
				Notification::NOTIFICATION_TYPE_ERROR, 
				[
					'contents' => __(
						'plugins.generic.plagiarism.errorMessage', 
						['submissionId' => $submissionId, 'errorMessage' => $message]
					)
				]
			);
		}

		error_log('iThenticate submission '.$submissionId.' failed: '.$message);
    }

    /**
     * Send submission files to iThenticate.
     */
    public function sendSubmissionFiles(Context $context, Submission $submission): void
    {
		$publication = $submission->getCurrentPublication();

		require_once(dirname(__FILE__) . '/vendor/autoload.php');

		// try to get credentials for current context otherwise use default config
		list($username, $password) = $this->getForcedCredentials();

		if (empty($username) || empty($password)) {
			$username = $this->getSetting($context->getId(), 'ithenticateUser');
			$password = $this->getSetting($context->getId(), 'ithenticatePass');
		}

		$ithenticate = null;
		try {
			$ithenticate = new \bsobbe\ithenticate\Ithenticate($username, $password);
		} catch (Throwable $e) {
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
				$this->sendErrorMessage(
					$submission->getId(), 
					'Could not create folder group for context ' . $contextName . ' on iThenticate.'
				);

				return;
			}
		}

		// Create a folder for this submission.
		$folderId = $ithenticate->createFolder(
			'Submission_' . $submission->getId(),
			'Submission_' . $submission->getId() . ': ' . $publication->getLocalizedTitle($publication->getData('locale')),
			$groupId,
			true,
			true
		);

		if (!$folderId) {
			$this->sendErrorMessage(
				$submission->getId(), 
				'Could not create folder for submission ID ' . $submission->getId() . ' on iThenticate.'
            );
			return;
		}

		$submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
		]);
		$authors = $publication->getData('authors');
		$author = array_shift($authors);

		foreach ($submissionFiles as $submissionFile) {
			$file = Services::get('file')->get($submissionFile->getData('fileId'));

			$submittedDocumentIdentifier = $ithenticate->submitDocument(
				$submissionFile->getLocalizedData('name'),
				$author->getLocalizedGivenName(),
				$author->getLocalizedFamilyName(),
				$submissionFile->getLocalizedData('name'),
				Services::get('file')->fs->read($file->path),
				$folderId
			);

			if (!$submittedDocumentIdentifier) {
				$this->sendErrorMessage(
					$submission->getId(), 
					'Could not submit "' . $submissionFile->getData('path') . '" to iThenticate.'
				);
			}
		}

		return;
    }

    /**
     * @copydoc Plugin::getActions()
     */
    function getActions($request, $verb)
    {
        $router = $request->getRouter();

        return array_merge(
            $this->getEnabled() 
                ? [
                    new LinkAction(
                        'settings',
                        new AjaxModal(
                            $router->url(
                                $request,
                                null,
                                null,
                                'manage',
                                null,
                                [
                                    'verb'      => 'settings',
                                    'plugin'    => $this->getName(),
                                    'category'  => 'generic'
                                ]
                            ),
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

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\plagiarism\PlagiarismPlugin', '\PlagiarismPlugin');
}
