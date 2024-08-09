<?php

/**
 * @file PlagiarismSettingsForm.inc.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Plagiarism settings form
 */

import('lib.pkp.classes.form.Form');
import('plugins.generic.plagiarism.PlagiarismPlugin');
import('plugins.generic.plagiarism.classes.form.validation.FormValidatorIthenticateAccess');
import('plugins.generic.plagiarism.IThenticate');

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

		if (!empty(array_filter([$request->getUserVar('ithenticateApiUrl'), $request->getUserVar('ithenticateApiKey')]))) {
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
		}

		$this->addCheck(
			new FormValidatorCustom(
				$this,
				'excludeSmallMatches',
				'required', 
				'plugins.generic.plagiarism.similarityCheck.settings.field.excludeSmallMatches.validation.min',
				function($excludeSmallMatches) {
					return (int) $excludeSmallMatches >= IThenticate::EXCLUDE_SAMLL_MATCHES_MIN;
				}
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
			'ithenticateForced' 	=> $this->_plugin->hasForcedCredentials($this->_context),
			'ithenticateApiUrl' 	=> $this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiUrl'),
			'ithenticateApiKey' 	=> $this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiKey'),
			'disableAutoSubmission' => $this->_plugin->getSetting($this->_context->getId(), 'disableAutoSubmission'),
		];

		foreach(array_keys($this->_plugin->similaritySettings) as $settingOption) {
			$this->_data[$settingOption] = $this->_plugin->getSetting($this->_context->getId(), $settingOption);
		}
		
		// set the default value `8` for `excludeSmallMatches` as per iThenticate guide
		if ((int) $this->_data['excludeSmallMatches'] < IThenticate::EXCLUDE_SAMLL_MATCHES_MIN) {
			$this->_data['excludeSmallMatches'] = IThenticate::EXCLUDE_SAMLL_MATCHES_MIN;
		}
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	public function readInputData() {
		$this->readUserVars(
			array_merge([
			'ithenticateApiUrl',
			'ithenticateApiKey',
			'disableAutoSubmission',
			], array_keys($this->_plugin->similaritySettings))
		);
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

		// if proper api url and api key provided and if there is no forced credentails defined in 
		// `config.inc.php` at global or for this context
		if (!empty(array_filter([$ithenticateApiUrl, $ithenticateApiKey])) &&
			!$this->_plugin->hasForcedCredentials($this->_context)) {

			// access updated or new access entered, need to update webhook registration
			if ($this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiUrl') !== $ithenticateApiUrl ||
				$this->_plugin->getSetting($this->_context->getId(), 'ithenticateApiKey') !== $ithenticateApiKey) {

				$ithenticate = $this->_plugin->initIthenticate($ithenticateApiUrl, $ithenticateApiKey);

				// If there is a already registered webhook for this context, need to delete it first
				// before creating a new one as webhook URL remains same which will return 409(HTTP_CONFLICT)
				if ($this->_context->getData('ithenticateWebhookId')) {
					$ithenticate->deleteWebhook($this->_context->getData('ithenticateWebhookId'));
				}

				$this->_plugin->registerIthenticateWebhook($ithenticate);
			}

			$this->_plugin->updateSetting($this->_context->getId(), 'ithenticateApiUrl', $ithenticateApiUrl, 'string');
			$this->_plugin->updateSetting($this->_context->getId(), 'ithenticateApiKey', $ithenticateApiKey, 'string');
		}

		$this->_plugin->updateSetting($this->_context->getId(), 'disableAutoSubmission', $this->getData('disableAutoSubmission'), 'bool');

		foreach($this->_plugin->similaritySettings as $settingName => $settingValueType) {
			$this->_plugin->updateSetting(
				$this->_context->getId(),
				$settingName,
				$this->getData($settingName),
				$settingValueType
			);
		}

		parent::execute(...$functionArgs);
	}
}
