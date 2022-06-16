<?php

/**
 * @file PlagiarismPlugin.inc.php
 *
 * Copyright (c) 2003-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class PlagiarismPlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		$this->addLocaleData();

		if ($success && Config::getVar('ithenticate', 'ithenticate') && $this->getEnabled()) {
			HookRegistry::register('submissionsubmitstep4form::execute', array($this, 'callback'));
		}
		return $success;
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.plagiarism.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return Config::getVar('ithenticate', 'ithenticate')?__('plugins.generic.plagiarism.description'):__('plugins.generic.plagiarism.description.seeReadme');
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	function getCanEnable() {
		if (!parent::getCanEnable()) return false;
		return Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * @copydoc LazyLoadPlugin::getEnabled()
	 */
	function getEnabled($contextId = null) {
		if (!parent::getEnabled($contextId)) return false;
		return Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * Fetch credentials from config.inc.php, if available
	 * @return array username and password, or null(s)
	**/
	function getForcedCredentials() {
		$request = Application::getRequest();
		$context = $request->getContext();
		$contextPath = $context->getPath();
		$username = Config::getVar('ithenticate', 'username[' . $contextPath . ']',
				Config::getVar('ithenticate', 'username'));
		$password = Config::getVar('ithenticate', 'password[' . $contextPath . ']',
				Config::getVar('ithenticate', 'password'));
		return array($username, $password);
	}
	
	/**
	 * Send submission files to iThenticate.
	 * @param $hookName string
	 * @param $args array
	 */
	public function callback($hookName, $args) {
		$request = Application::getRequest();
		$context = $request->getContext();
		$contextPath = $context->getPath();
		$submissionDao = Application::getSubmissionDAO();
		$submission = $submissionDao->getById($request->getUserVar('submissionId'));
		$publication = $submission->getCurrentPublication();

		require_once(dirname(__FILE__) . '/vendor/autoload.php');

		// try to get credentials for current context otherwise use default config
        	$contextId = $context->getId();
		$credentials = $this->getForcedCredentials(); 
		$username = $credentials[0];
		$password = $credentials[1];
		if (Empty($username) || Empty($password)) {
			$username = $this->getSetting($contextId, 'ithenticate_user');
			$password = $this->getSetting($contextId, 'ithenticate_pass');
		}

		$ithenticate = null;
		try {
			$ithenticate = new \bsobbe\ithenticate\Ithenticate($username, $password);
		} catch (Exception $e) {
			error_log('Could not login to iThenticate: '.$e->getMessage());
			return false;
		}
		// Make sure there's a group list for this context, creating if necessary.
		$groupList = $ithenticate->fetchGroupList();
		$contextName = $context->getLocalizedName($context->getPrimaryLocale());
		if (!($groupId = array_search($contextName, $groupList))) {
			// No folder group found for the context; create one.
			$groupId = $ithenticate->createGroup($contextName);
			if (!$groupId) {
				error_log('Could not create folder group for context ' . $contextName . ' on iThenticate.');
				return false;
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
			error_log('Could not create folder for submission ID ' . $submission->getId() . ' on iThenticate.');
			return false;
		}

		$submissionFiles = Services::get('submissionFile')->getMany([
			'submissionIds' => [$submission->getId()],
		]);

		$authors = $publication->getData('authors');
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
				error_log('Could not submit "' . $submissionFile->getData('path') . '" to iThenticate.');
			}
		}

		return false;
	}
	
	/**
     * @copydoc Plugin::getActions()
     */
    function getActions($request, $verb) {
        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        return array_merge(
                $this->getEnabled() ? array(
            new LinkAction(
                    'settings',
                    new AjaxModal(
                            $router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
                            $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
            ),
                ) : array(),
                parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();

                AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->registerPlugin('function', 'plugin_url', array($this, 'smartyPluginUrl'));

                $this->import('PlagiarismSettingsForm');
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
class TestIthenticate {
	public function __construct($username, $password) {
		error_log("Constructing iThenticate: $username $password");
	}

	public function fetchGroupList() {
		error_log('Fetching iThenticate group list');
		return array();
	}

	public function createGroup($group_name) {
		error_log("Creating group named \"$group_name\"");
		return 1;
	}

	public function createFolder($folder_name, $folder_description, $group_id, $exclude_quotes) {
		error_log("Creating folder:\n\t$folder_name\n\t$folder_description\n\t$group_id\n\t$exclude_quotes");
		return true;
	}

	public function submitDocument($essay_title, $author_firstname, $author_lastname, $filename, $document_content, $folder_number) {
		error_log("Submitting document:\n\t$essay_title\n\t$author_firstname\n\t$author_lastname\n\t$filename\n\t" . strlen($document_content) . " bytes of content\n\t$folder_number");
		return true;
	}
}
