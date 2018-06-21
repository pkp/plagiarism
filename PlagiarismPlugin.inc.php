<?php

/**
 * @file plugins/generic/plagiarism/PlagiarismPlugin.inc.php
 *
 * Copyright (c) 2003-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @brief Plagiarism plugin
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class PlagiarismPlugin extends GenericPlugin {
	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();

		if ($success && Config::getVar('ithenticate', 'ithenticate') && $this->getEnabled()) {
			HookRegistry::register('submissionsubmitstep4form::display', array($this, 'callback'));
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
	 * @copydoc Plugin::getEnabled()
	 */
	function getEnabled() {
		if (!parent::getEnabled()) return false;
		return Config::getVar('ithenticate', 'ithenticate');
	}

	/**
	 * Send submission files to iThenticate.
	 * @param $hookName string
	 * @param $args array
	 */
	public function callback($hookName, $args) {
		$request = Application::getRequest();
		$context = $request->getContext();
		$submissionDao = Application::getSubmissionDAO();
		$submission = $submissionDao->getById($request->getUserVar('submissionId'));

		require_once(dirname(__FILE__) . '/vendor/autoload.php');

		$ithenticate = new \bsobbe\ithenticate\Ithenticate(
			Config::getVar('ithenticate', 'username'),
			Config::getVar('ithenticate', 'password')
		);

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
			'Submission_' . $submission->getId() . ': ' . $submission->getLocalizedTitle($submission->getLocale()),
			$groupId,
			1
		))) {
			error_log('Could not create folder for submission ID ' . $submission->getId() . ' on iThenticate.');
			return false;
		}

		$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
		$submissionFiles = $submissionFileDao->getBySubmissionId($submission->getId());
		$authors = $submission->getAuthors();
		$author = array_shift($authors);
		foreach ($submissionFiles as $submissionFile) {
			if (!$ithenticate->submitDocument(
				$submissionFile->getLocalizedName(),
				$author->getLocalizedGivenName(),
				$author->getLocalizedFamilyName(),
				$submissionFile->getOriginalFileName(),
				file_get_contents($submissionFile->getFilePath()),
				$folderId
			)) {
				error_log('Could not submit ' . $submissionFile->getFilePath() . ' to iThenticate.');
			}
		}

		return false;
	}
}
