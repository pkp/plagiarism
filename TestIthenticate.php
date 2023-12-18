<?php

/**
 * @file plugins/generic/plagiarism/TestIthenticate.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2013-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class TestIthenticate
 *
 * @brief   Low-budget mock class for \bsobbe\ithenticate\Ithenticate -- Replace the
 *          constructor above with this class name to log API usage instead of
 *          interacting with the iThenticate service.
 */

namespace APP\plugins\generic\plagiarism;

class TestIthenticate 
{
	public function __construct(string $username, string $password) 
    {
		error_log("Constructing iThenticate: $username $password");
	}

	public function fetchGroupList(): array
    {
		error_log('Fetching iThenticate group list');
		return [];
	}

	public function createGroup(string $group_name): int
    {
		error_log("Creating group named \"$group_name\"");
		return 1;
	}

	public function createFolder(string $folder_name, string $folder_description, string|int $group_id, string $exclude_quotes): bool
    {
		error_log("Creating folder:\n\t$folder_name\n\t$folder_description\n\t$group_id\n\t$exclude_quotes");
		return true;
	}

	public function submitDocument(string $essay_title, string $author_firstname, string $author_lastname, string $filename, string $document_content, string|int $folder_number): bool
    {
		error_log("Submitting document:\n\t$essay_title\n\t$author_firstname\n\t$author_lastname\n\t$filename\n\t" . strlen($document_content) . " bytes of content\n\t$folder_number");
		return true;
	}
}