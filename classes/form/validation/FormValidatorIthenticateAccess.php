<?php

/**
 * @file plugins/generic/plagiarism/classes/form/validation/FormValidatorIthenticateAccess.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class FormValidatorIthenticateAccess
 * @see FormValidator
 *
 * @brief Form validation check for iThenticate service access.
 */

namespace APP\plugins\generic\plagiarism\classes\form\validation;

use PKP\form\validation\FormValidator;
use APP\plugins\generic\plagiarism\classes\validation\ValidatorIthenticateAccess;
use APP\plugins\generic\plagiarism\TestIThenticate;
use APP\plugins\generic\plagiarism\IThenticate;

class FormValidatorIthenticateAccess extends FormValidator
{
	/**
	 * Constructor.
	 * 
	 * @param Form                          $form           the associated form
	 * @param string                        $field          the name of the associated field
	 * @param string                        $type           the type of check, either "required" or "optional"
	 * @param string                        $message        the error message for validation failures (i18n key)
     * @param IThenticate|TestIThenticate 	$ithenticate    iThenticate API communicating service class instance
	 */
	public function __construct(&$form, $field, $type, $message, $ithenticate)
	{
		$validator = new ValidatorIthenticateAccess($ithenticate);
		parent::__construct($form, $field, $type, $message, $validator);
	}
}
