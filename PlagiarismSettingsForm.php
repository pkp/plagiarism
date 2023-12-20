<?php

/**
 * @file plugins/generic/plagiarism/PlagiarismSettingsForm.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2013-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PlagiarismSettingsForm
 *
 * @brief  plagiarism plugin settings form class
 */

namespace APP\plugins\generic\plagiarism;

use PKP\form\Form;
use APP\template\TemplateManager;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use APP\plugins\generic\plagiarism\PlagiarismPlugin;

class PlagiarismSettingsForm extends Form 
{
	protected int $_contextId;

	protected PlagiarismPlugin $_plugin;

	/**
	 * Constructor
	 */
	function __construct(PlagiarismPlugin $plugin, int $contextId)
	{
		$this->_contextId = $contextId;
		$this->_plugin = $plugin;

		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

		$this->addCheck(new FormValidator($this, 'ithenticateUser', 'required', 'plugins.generic.plagiarism.manager.settings.usernameRequired'));
		$this->addCheck(new FormValidator($this, 'ithenticatePass', 'required', 'plugins.generic.plagiarism.manager.settings.passwordRequired'));

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * @copydoc \PKP\form\Form::initData()
	 */
	function initData()
	{
		list($username, $password) = $this->_plugin->getForcedCredentials();

		$this->_data = [
			'ithenticateUser' 	=> $this->_plugin->getSetting($this->_contextId, 'ithenticateUser'),
			'ithenticatePass' 	=> $this->_plugin->getSetting($this->_contextId, 'ithenticatePass'),
			'ithenticateForced' => !empty($username) && !empty($password)
		];
	}

	/**
	 * @copydoc \PKP\form\Form::readInputData()
	 */
	function readInputData()
	{
		$this->readUserVars(['ithenticateUser', 'ithenticatePass']);
	}

	/**
	 * @copydoc \PKP\form\Form::fetch()
	 */
	function fetch($request, $template = null, $display = false)
	{
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());

		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc \PKP\form\Form::execute()
	 */
	function execute(...$functionArgs)
	{
		$this->_plugin->updateSetting($this->_contextId, 'ithenticateUser', trim($this->getData('ithenticateUser'), "\"\';"), 'string');
		$this->_plugin->updateSetting($this->_contextId, 'ithenticatePass', trim($this->getData('ithenticatePass'), "\"\';"), 'string');

		parent::execute(...$functionArgs);
	}	
}
