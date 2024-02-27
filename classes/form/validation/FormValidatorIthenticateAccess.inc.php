<?php

/**
 * @file plugins/generic/plagiarism/classes/form/validation/FormValidatorIthenticateAccess.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FormValidatorIthenticateAccess
 * @see FormValidator
 *
 * @brief Form validation check for iThenticate service access.
 */

import('lib.pkp.classes.form.validation.FormValidator');
import('plugins.generic.plagiarism.classes.validation.ValidatorIthenticateAccess');
import("plugins.generic.plagiarism.IThenticate");
import("plugins.generic.plagiarism.TestIThenticate");

class FormValidatorIthenticateAccess extends FormValidator {
    
	/**
	 * Constructor.
	 * @param Form                          $form           the associated form
	 * @param string                        $field          the name of the associated field
	 * @param string                        $type           the type of check, either "required" or "optional"
	 * @param string                        $message        the error message for validation failures (i18n key)
     * @param \IThenticate|\TestIThenticate $ithenticate    iThenticate API communicating service class instance
	 */
	public function __construct(&$form, $field, $type, $message, $ithenticate) {
		$validator = new ValidatorIthenticateAccess($ithenticate);
		parent::__construct($form, $field, $type, $message, $validator);
	}
}


