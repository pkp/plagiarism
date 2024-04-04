<?php

/**
 * @file PlagiarismSettingsForm.inc.php
 *
 * Copyright (c) 2003-2024 Simon Fraser University
 * Copyright (c) 2003-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism settings form
 */

import('lib.pkp.classes.form.Form');
import('plugins.generic.plagiarism.PlagiarismPlugin');
import('plugins.generic.plagiarism.classes.form.validation.FormValidatorIthenticateAccess');

class PlagiarismSettingsForm extends Form {

	/**
	 * The context
	 * 
	 * @var Context
	 */
	protected $_context;

	/**
	 * The PlagiarismPlugin instance
	 * 
	 * @var PlagiarismPlugin
	 */
	protected $_plugin;

	/**
	 * Constructor
	 * 
	 * @param PlagiarismPlugin 	$plugin
	 * @param Context 			$context
	 */
	public function __construct($plugin, $context) {
		$this->_plugin = $plugin;
		$this->_context = $context;

		$request = Application::get()->getRequest();

		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

		$this->addCheck(new FormValidator($this, 'ithenticateApiUrl', 'required', 'plugins.generic.plagiarism.manager.settings.apiUrlRequired'));
		$this->addCheck(new FormValidator($this, 'ithenticateApiKey', 'required', 'plugins.generic.plagiarism.manager.settings.apiKeyRequired'));
		$this->addCheck(new FormValidatorUrl($this, 'ithenticateApiUrl', 'required', 'plugins.generic.plagiarism.manager.settings.apiUrlInvalid'));
		$this->addCheck(
			new FormValidatorIthenticateAccess(
				$this,
				'',
				'required',
				'plugins.generic.plagiarism.manager.settings.serviceAccessInvalid',
				$this->_plugin->initIthenticate(
					$request->getUserVar('ithenticateApiUrl'),
					$request->getUserVar('ithenticateApiKey')
				)
			)
		);

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * Initialize form data.
	 */
	public function initData() {
		$this->_data = [
			'ithenticateApiUrl' => $this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiUrl'),
			'ithenticateApiKey' => $this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiKey'),
			'ithenticateForced' => $this->_plugin->hasForcedCredentials(),
		];
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	public function readInputData() {
		$this->readUserVars(['ithenticateApiUrl', 'ithenticateApiKey']);
	}

	/**
	 * @copydoc Form::fetch()
	 */
	public function fetch($request, $template = null, $display = false) {
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('pluginName', $this->_plugin->getName());
		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc Form::execute()
	 */
	public function execute(...$functionArgs) {
		$ithenticateApiUrl = trim($this->getData('ithenticateApiUrl'), "\"\';");
		$ithenticateApiKey = trim($this->getData('ithenticateApiKey'), "\"\';");

		if ($this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiUrl') !== $ithenticateApiUrl ||
			$this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiKey') !== $ithenticateApiKey) {
			
			// access updated or new access entered, need to update webhook registration	
			$this->_plugin->registerIthenticateWebhook(
				$this->_plugin->initIthenticate(
					$this->getData('ithenticateApiUrl'),
					$this->getData('ithenticateApiKey')
				)
			);
		}

		$this->_plugin->updateSetting($this->_context->getId(), 'ithenticateApiUrl', $ithenticateApiUrl, 'string');
		$this->_plugin->updateSetting($this->_context->getId(), 'ithenticateApiKey', $ithenticateApiKey, 'string');

		parent::execute(...$functionArgs);
	}
}
