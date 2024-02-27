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
	 * The context id
	 * 
	 * @var int
	 */
	var $_contextId;

	/**
	 * The PlagiarismPlugin instance
	 * 
	 * @var PlagiarismPlugin
	 */
	var $_plugin;

	/**
	 * Constructor
	 * 
	 * @param PlagiarismPlugin 	$plugin
	 * @param int 				$contextId
	 */
	public function __construct($plugin, $contextId) {
		$this->_plugin = $plugin;
		$this->_contextId = $contextId;

		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

		$this->addCheck(new FormValidator($this, 'ithenticateApiUrl', 'required', 'plugins.generic.plagiarism.manager.settings.apiUrlRequired'));
		$this->addCheck(new FormValidator($this, 'ithenticateApiKey', 'required', 'plugins.generic.plagiarism.manager.settings.apiKeyRequired'));
		$this->addCheck(new FormValidatorUrl($this, 'ithenticateApiUrl', 'required', 'plugins.generic.plagiarism.manager.settings.apiUrlInvalid'));

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * Initialize form data.
	 */
	public function initData() {
		$this->_data = [
			'ithenticateApiUrl' => $this->_plugin->getSetting($this->_contextId, 'ithenticateApiUrl'),
			'ithenticateApiKey' => $this->_plugin->getSetting($this->_contextId, 'ithenticateApiKey'),
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
	 * @copydoc Form::validate()
	 */
	public function validate($callHooks = true) {
		$this->addCheck(
			new FormValidatorIthenticateAccess(
				$this,
				'',
				'required',
				'plugins.generic.plagiarism.manager.settings.serviceAccessInvalid',
				$this->_plugin->initIthenticate(
					$this->getData('ithenticateApiUrl'),
					$this->getData('ithenticateApiKey')
				)
			)
		);

		return parent::validate($callHooks);
	}

	/**
	 * @copydoc Form::execute()
	 */
	public function execute(...$functionArgs) {
        $this->_plugin->updateSetting($this->_contextId, 'ithenticateApiUrl', trim($this->getData('ithenticateApiUrl'), "\"\';"), 'string');
		$this->_plugin->updateSetting($this->_contextId, 'ithenticateApiKey', trim($this->getData('ithenticateApiKey'), "\"\';"), 'string');
		parent::execute(...$functionArgs);
	}
}
