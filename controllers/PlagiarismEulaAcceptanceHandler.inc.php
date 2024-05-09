<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismEulaAcceptanceHandler.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismEulaAcceptanceHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief Handle the reconfirmation and acceptance of iThenticate EULA
 */

import('lib.pkp.classes.handler.PKPHandler');
import("plugins.generic.plagiarism.IThenticate");

class PlagiarismEulaAcceptanceHandler extends PKPHandler {

	/** 
	 * The Plagiarism Plugin itself
	 * 
	 * @var PlagiarismPlugin 
	 */
	protected static $_plugin;

	/**
	 * Get the plugin
	 * 
	 * @return PlagiarismPlugin
	 */
	public static function getPlugin() {
		return static::$_plugin;
	}

	/**
	 * Set the Plugin
	 * 
	 * @param PlagiarismPlugin $plugin
	 */
	public static function setPlugin($plugin) {
		static::$_plugin = $plugin;
	}

	/**
	 * Authorize this request.
	 *
	 * @return bool
	 */
	public function authorize($request, &$args, $roleAssignments) {
		return true;
	}

	/**
	 * Handle the request
	 *
	 * @return void
	 */
	public function handle($args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
        $user = $request->getUser();
        $submissionEulaVersion = $args['version'];

        /** @var \IThenticate $ithenticate */
        $ithenticate = static::$_plugin->initIthenticate(
            ...static::$_plugin->getServiceAccess($context)
        );
        
		$ithenticate->setApplicableEulaVersion($submissionEulaVersion);

        if ($ithenticate->verifyUserEulaAcceptance($user, $submissionEulaVersion) ||
			$ithenticate->confirmEula($user, $context)) {
                
            static::$_plugin->stampEulaVersionToUser($user, $submissionEulaVersion);
		}

		$request->redirectUrl(
            $request->getDispatcher()->url(
                $request,
                ROUTE_PAGE,
                $context->getData('urlPath'),
                'submission',
                'wizard',
                4,
                ['submissionId' => $args['submissionId']]
            )
        );
	}

}
